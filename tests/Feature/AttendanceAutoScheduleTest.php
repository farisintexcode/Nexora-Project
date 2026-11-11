<?php

use App\Actions\Attendance\ConfigureAttendance;
use App\Actions\Attendance\ManageLeave;
use App\Livewire\Admin\Attendance\Configuration;
use App\Livewire\Admin\Attendance\LeaveManagement;
use App\Livewire\Admin\Attendance\Show;
use App\Livewire\Rider\Attendance;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\AttendanceRegister;
use App\Services\Attendance\LeaveBalance;
use App\Services\Attendance\PublicHolidays;
use App\Services\Attendance\WorkCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', config('dcms.business_timezone')));
});

function calendarAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $admin->givePermissionTo('attendance.configure');

    return $admin;
}

function calendarRider(?string $state = null): Staff
{
    return Staff::factory()->create(['is_rider' => true, 'is_active' => true, 'work_state_code' => $state, 'created_at' => now()->subYear()]);
}

function defaultPolicy(User $admin, string $from, array $rules = []): AttendancePolicy
{
    return app(ConfigureAttendance::class)->policy($admin, ['code' => 'standard', 'rules' => array_replace(config('attendance.initial_rules'), $rules), 'is_default' => true, 'effective_from' => $from]);
}

/** @return array<string, string> service date => day type */
function calendarDays(Staff $rider, string $from, string $until): array
{
    app(WorkCalendar::class)->ensure($rider->id, $from, $until);

    return AttendanceSchedule::query()->where('rider_id', $rider->id)->orderBy('service_date')->get()
        ->mapWithKeys(fn (AttendanceSchedule $day): array => [$day->service_date->toDateString() => $day->day_type])->all();
}

test('a default policy fills every riders calendar from its start date without any assignment', function (): void {
    $admin = calendarAdmin();
    $rider = calendarRider();
    $office = Staff::factory()->create(['is_rider' => false, 'is_active' => true, 'created_at' => now()->subYear()]);
    defaultPolicy($admin, '2026-11-17');

    expect(calendarDays($rider, '2026-11-16', '2026-11-22'))->toBe([
        '2026-11-17' => 'scheduled', '2026-11-18' => 'scheduled', '2026-11-19' => 'scheduled', '2026-11-20' => 'scheduled',
        '2026-11-21' => 'day_off', '2026-11-22' => 'day_off',
    ])->and(calendarDays($office, '2026-11-16', '2026-11-22'))->toBe([]);
});

test('a new version takes over from its start date while days already on the calendar keep theirs', function (): void {
    $admin = calendarAdmin();
    $rider = calendarRider();
    $first = defaultPolicy($admin, '2026-11-16');
    calendarDays($rider, '2026-11-16', '2026-11-16');
    $second = defaultPolicy($admin, '2026-11-16', ['shift_start' => '08:00', 'shift_end' => '17:00']);
    $third = defaultPolicy($admin, '2026-11-19', ['shift_start' => '10:00', 'shift_end' => '19:00']);
    calendarDays($rider, '2026-11-16', '2026-11-19');

    $versions = AttendanceSchedule::query()->where('rider_id', $rider->id)->orderBy('service_date')->pluck('policy_id', 'service_date')->all();
    expect(array_values($versions))->toBe([$first->id, $second->id, $second->id, $third->id]);
});

test('national holidays apply to everyone and state holidays only to riders in that state', function (): void {
    $admin = calendarAdmin();
    $selangor = calendarRider('selangor');
    $johor = calendarRider('johor');
    defaultPolicy($admin, '2026-11-16');
    app(PublicHolidays::class)->add($admin, '2026-11-17', 'National test day', null);
    app(PublicHolidays::class)->add($admin, '2026-11-18', 'Selangor test day', 'selangor');

    expect(calendarDays($selangor, '2026-11-17', '2026-11-18'))->toBe(['2026-11-17' => 'public_holiday', '2026-11-18' => 'public_holiday'])
        ->and(calendarDays($johor, '2026-11-17', '2026-11-18'))->toBe(['2026-11-17' => 'public_holiday', '2026-11-18' => 'scheduled']);
});

test('riders in friday weekend states work sunday to thursday', function (): void {
    defaultPolicy(calendarAdmin(), '2026-11-16');

    expect(calendarDays(calendarRider('kedah'), '2026-11-19', '2026-11-22'))->toBe([
        '2026-11-19' => 'scheduled', '2026-11-20' => 'day_off', '2026-11-21' => 'day_off', '2026-11-22' => 'scheduled',
    ]);
});

test('a per rider exception overrides the default policy for its dates only', function (): void {
    $admin = calendarAdmin();
    $rider = calendarRider();
    User::factory()->for($rider)->create();
    $default = defaultPolicy($admin, '2026-11-16');
    $night = app(ConfigureAttendance::class)->policy($admin, ['code' => 'night', 'rules' => config('attendance.initial_rules')]);
    app(WorkCalendar::class)->assign($admin, $rider->public_id, $night->public_id, '2026-11-16', '2026-11-17');
    calendarDays($rider, '2026-11-16', '2026-11-18');

    expect(AttendanceSchedule::query()->where('rider_id', $rider->id)->orderBy('service_date')->pluck('policy_id')->all())->toBe([$night->id, $night->id, $default->id])
        ->and($night->is_default)->toBeFalse();
});

/** @return array<string, mixed> Malaysia Calendar API /states payload for two weekend groups. */
function calendarApiStates(): array
{
    return ['data' => [
        ['code' => 'selangor', 'weekendHistory' => [['from' => '1900-01-01', 'to' => null, 'weekendDays' => [0, 6], 'replacedWeekday' => 0]]],
        ['code' => 'kuala-lumpur', 'weekendHistory' => [['from' => '1900-01-01', 'to' => null, 'weekendDays' => [0, 6], 'replacedWeekday' => 0]]],
        ['code' => 'wp-putrajaya', 'weekendHistory' => [['from' => '1900-01-01', 'to' => null, 'weekendDays' => [0, 6], 'replacedWeekday' => 0]]],
        ['code' => 'kedah', 'weekendHistory' => [['from' => '1900-01-01', 'to' => null, 'weekendDays' => [5, 6], 'replacedWeekday' => 5]]],
    ]];
}

/** @param  list<array<string, mixed>>  $holidays */
function fakeCalendarApi(array $holidays): void
{
    Http::fake([
        '*/v1/holidays*' => Http::response(['data' => $holidays, 'meta' => ['year' => 2026]]),
        '*/v1/states' => Http::response(calendarApiStates()),
    ]);
}

test('online sync stores national and state holidays and works out replacement days per state', function (): void {
    $admin = calendarAdmin();
    config(['services.malaysia_calendar.url' => 'https://calendar.test/v1']);
    fakeCalendarApi([
        ['id' => '2026-deepavali', 'date' => '2026-11-08', 'name' => ['ms' => 'Hari Deepavali', 'en' => 'Deepavali'], 'type' => 'state', 'status' => 'confirmed', 'states' => ['selangor', 'kuala-lumpur', 'kedah'], 'isPublicHoliday' => true],
        ['id' => '2026-krismas', 'date' => '2026-12-25', 'name' => ['ms' => 'Hari Krismas', 'en' => 'Christmas Day'], 'type' => 'federal', 'status' => 'confirmed', 'states' => ['*'], 'isPublicHoliday' => true],
        ['id' => '2026-cuti-tambahan', 'date' => '2026-03-20', 'name' => ['ms' => 'Cuti Tambahan'], 'type' => 'adhoc', 'status' => 'confirmed', 'states' => ['*'], 'isPublicHoliday' => true],
        ['id' => '2026-wp-day', 'date' => '2026-02-01', 'name' => ['en' => 'Federal Territory Day'], 'type' => 'state', 'status' => 'confirmed', 'states' => ['wp-putrajaya'], 'isPublicHoliday' => true],
    ]);

    expect(app(PublicHolidays::class)->sync($admin, 2026))->toMatchArray(['added' => 10, 'updated' => 0, 'removed' => 0]);

    $rows = PublicHoliday::query()->orderBy('holiday_date')->orderBy('state_code')->get()->map(fn (PublicHoliday $holiday): string => $holiday->holiday_date->toDateString().' '.($holiday->state_code ?? 'national').' '.$holiday->name)->all();
    expect($rows)->toBe([
        '2026-02-01 putrajaya Federal Territory Day',
        '2026-02-02 putrajaya Federal Territory Day (replacement holiday)',
        '2026-03-20 national Cuti Tambahan',
        '2026-11-08 kedah Deepavali',
        '2026-11-08 kuala_lumpur Deepavali',
        '2026-11-08 selangor Deepavali',
        '2026-11-09 kuala_lumpur Deepavali (replacement holiday)',
        '2026-11-09 selangor Deepavali (replacement holiday)',
        '2026-12-25 national Christmas Day',
        '2026-12-27 kedah Christmas Day (replacement holiday)',
    ]);
});

test('re-syncing moves tentative dates, removes dropped holidays and keeps holidays an admin switched off', function (): void {
    $admin = calendarAdmin();
    config(['services.malaysia_calendar.url' => 'https://calendar.test/v1']);
    $raya = ['id' => '2026-raya', 'date' => '2026-03-19', 'name' => ['en' => 'Hari Raya Aidilfitri'], 'type' => 'islamic', 'status' => 'tentative', 'states' => ['*'], 'isPublicHoliday' => true];
    $merdeka = ['id' => '2026-merdeka', 'date' => '2026-08-31', 'name' => ['en' => 'National Day'], 'type' => 'federal', 'status' => 'confirmed', 'states' => ['*'], 'isPublicHoliday' => true];
    $dropped = ['id' => '2026-dropped', 'date' => '2026-04-01', 'name' => ['en' => 'Withdrawn holiday'], 'type' => 'state', 'status' => 'confirmed', 'states' => ['selangor'], 'isPublicHoliday' => true];
    fakeCalendarApi([$raya, $merdeka, $dropped]);
    app(PublicHolidays::class)->sync($admin, 2026);
    PublicHoliday::query()->where('external_id', '2026-merdeka')->update(['is_active' => false]);

    fakeCalendarApi([[...$raya, 'date' => '2026-03-21', 'status' => 'confirmed'], $merdeka]);
    expect(app(PublicHolidays::class)->sync($admin, 2026))->toMatchArray(['added' => 0, 'updated' => 1, 'removed' => 1]);

    $moved = PublicHoliday::query()->where('external_id', '2026-raya')->sole();
    expect($moved->holiday_date->toDateString())->toBe('2026-03-21')->and($moved->is_tentative)->toBeFalse()
        ->and(PublicHoliday::query()->where('external_id', '2026-merdeka')->value('is_active'))->toBeFalse()
        ->and(PublicHoliday::query()->where('external_id', '2026-dropped')->exists())->toBeFalse();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/holidays') && (int) $request['year'] === 2026);
});

test('an unreachable holiday calendar shows a friendly error', function (): void {
    config(['services.malaysia_calendar.url' => 'https://calendar.test/v1']);
    Http::fake(['*' => Http::response('down', 503)]);

    expect(fn () => app(PublicHolidays::class)->sync(calendarAdmin(), 2026))->toThrow(ValidationException::class, 'could not be reached');
});

test('the schedules page publishes a default policy and shows it in effect', function (): void {
    $admin = calendarAdmin();

    Livewire::actingAs($admin)->test(Configuration::class)
        ->assertSee('No policy applies automatically yet')
        ->set('effectiveFrom', '2026-11-16')->call('publish')->assertHasNoErrors()
        ->assertSee('standard · version 1')->assertDontSee('No policy applies automatically yet');

    expect(AttendancePolicy::defaultOn('2026-11-16')?->effective_from->toDateString())->toBe('2026-11-16');
});

test('leave on the automatic calendar charges only working days and shows on attendance before and after approval', function (): void {
    $admin = calendarAdmin();
    $admin->givePermissionTo(['attendance.approve_correction', 'attendance.view_all']);
    $staff = calendarRider('selangor');
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');
    $type = LeaveType::factory()->create(['configured_by' => $admin->id, 'tracks_balance' => true, 'max_calendar_days' => 93]);
    app(LeaveBalance::class)->entitlement($admin, $type, 2026, 10);
    defaultPolicy($admin, '2026-11-16');
    app(PublicHolidays::class)->add($admin, '2026-12-11', 'Birthday of Sultan of Selangor', 'selangor');
    $leave = app(ManageLeave::class);
    $data = ['type' => $type->public_id, 'start_date' => '2026-12-10', 'end_date' => '2026-12-14', 'reason' => 'Family trip'];

    $request = $leave->submit($rider, $data, $leave->preview($rider, $data)['token']);
    $thursday = AttendanceSchedule::query()->where('rider_id', $staff->id)->where('service_date', '2026-12-10')->sole();
    $register = fn () => app(AttendanceRegister::class)->query($admin, ['rider' => $staff->public_id, 'from' => '2026-12-10', 'until' => '2026-12-14'])->orderBy('service_date')->get();

    expect($request->total_days)->toBe(2)->and((bool) $register()->first()->pending_leave)->toBeTrue();
    Livewire::actingAs($admin)->test(Show::class, ['schedule' => $thursday->public_id])
        ->assertSee('waiting for approval')->assertSee(route('admin.leave.show', $request->public_id), false);

    $request = $request->fresh();
    $leave->decide($admin, $request, 'approved', '', $request->lock_version);

    expect($register()->pluck('result_status')->all())->toBe(['leave', 'public_holiday', 'day_off', 'day_off', 'leave']);
    Livewire::actingAs($admin)->test(LeaveManagement::class, ['leave' => $request->public_id])
        ->assertSee('Public Holiday: Birthday of Sultan of Selangor — not counted')->assertSee(route('admin.attendance.show', $thursday->public_id), false);

    $this->travelTo(CarbonImmutable::parse('2026-12-10 08:30', config('dcms.business_timezone')));
    Livewire::actingAs($rider)->test(Attendance::class)
        ->assertSee('You are on approved leave today')->assertDontSee("wire:click=\"clock('in')\"", false);
});
