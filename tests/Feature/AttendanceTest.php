<?php

use App\Actions\Attendance\ClockAttendance;
use App\Actions\Attendance\ConfigureAttendance;
use App\Actions\Attendance\CorrectAttendance;
use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\AttendanceRegister;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function attendanceFixture(array $overrides = []): array
{
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $rider = User::factory()->for(Staff::factory())->create();
    $rider->assignRole('Rider');
    $policy = app(ConfigureAttendance::class)->policy($admin, ['code' => 'fixture-'.Str::lower(Str::random(8)), 'rules' => array_replace(config('attendance.initial_rules'), $overrides)]);
    [$start,$end] = app(ConfigureAttendance::class)->times(now()->timezone(config('dcms.business_timezone'))->toDateString(), $policy->rules);
    $schedule = AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'policy_id' => $policy->id, 'authorized_by' => $admin->id, 'scheduled_start_at' => $start, 'scheduled_end_at' => $end]);

    return [$admin, $rider, $schedule];
}

test('attendance routes enforce authentication and grants while own history stays personal', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $this->get(route('rider.attendance'))->assertRedirect(route('rider.login'));
    $this->actingAs($rider)->get(route('admin.attendance.index'))->assertForbidden();
    $this->get(route('rider.attendance'))->assertOk()->assertSee('Your attendance');
    $this->actingAs($admin)->get(route('admin.attendance.index'))->assertOk()->assertSee('Attendance');
    $this->get(route('admin.attendance.show', $schedule))->assertOk();
});

test('server punches are idempotent and one shift cannot be duplicated or manually overwritten', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:10:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $key = (string) Str::uuid7();
    $clock = app(ClockAttendance::class);
    $in = $clock->punch($rider, 'in', $key, remarks: 'Actual field shift');
    expect($in->raw_clock_in_at->format('H:i'))->toBe('01:10')->and($in->schedule_id)->toBe($schedule->id)->and($in->is_late)->toBeFalse();
    expect($clock->punch($rider, 'in', $key)->id)->toBe($in->id);
    expect(fn () => $clock->punch($rider, 'in', (string) Str::uuid7()))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 18:00:00', 'Asia/Kuala_Lumpur'));
    $outKey = (string) Str::uuid7();
    $out = $clock->punch($rider, 'out', $outKey, $in->public_id, 0);
    expect($out->worked_minutes)->toBe(470)->and($out->status)->toBe('present')->and($out->is_late)->toBeFalse();
    expect($clock->punch($rider, 'out', $outKey, $in->public_id, 0)->id)->toBe($in->id);
    expect(fn () => DB::transaction(fn () => DB::table('attendances')->where('id', $in->id)->update(['raw_clock_in_at' => now(), 'lock_version' => 2])))->toThrow(QueryException::class);
    expect(Attendance::query()->count())->toBe(1)->and(AuditLog::query()->whereIn('action', ['attendance.clock_in', 'attendance.clock_out'])->count())->toBe(2);
});

test('pinned duration and lateness rules retain actual times at approved boundaries', function (string $in, string $out, string $status, int $worked, bool $late, int $lateMinutes): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 '.$in, 'Asia/Kuala_Lumpur'));
    [, $rider] = attendanceFixture();
    $clock = app(ClockAttendance::class);
    $punch = $clock->punch($rider, 'in', (string) Str::uuid7());
    $this->travelTo(CarbonImmutable::parse('2026-10-19 '.$out, 'Asia/Kuala_Lumpur'));
    $punch = $clock->punch($rider, 'out', (string) Str::uuid7(), $punch->public_id, 0);
    expect($punch->status)->toBe($status)->and($punch->worked_minutes)->toBe($worked)->and($punch->is_late)->toBe($late)->and($punch->late_minutes)->toBe($lateMinutes);
})->with([
    'inclusive grace' => ['09:15:00', '18:00:00', 'present', 465, false, 0],
    'one minute late' => ['09:16:00', '18:00:00', 'present', 464, true, 16],
    'subsecond outside grace' => ['09:15:00.000001', '18:00:00', 'present', 464, true, 15],
    'present threshold' => ['09:00:00', '17:00:00', 'present', 420, false, 0],
    'below present' => ['09:00:00', '16:59:00', 'half_day', 419, false, 0],
    'half day threshold' => ['09:00:00', '14:00:00', 'half_day', 240, false, 0],
    'below half day' => ['09:00:00', '13:59:00', 'incomplete', 239, false, 0],
    'short interval cannot become absent' => ['09:00:00', '09:30:00', 'incomplete', 0, false, 0],
]);

test('overnight clock out retains the scheduled start service date and blocks another clock in', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 22:00:00', 'Asia/Kuala_Lumpur'));
    [, $rider,$schedule] = attendanceFixture(['shift_start' => '22:00', 'shift_end' => '07:00']);
    $clock = app(ClockAttendance::class);
    $in = $clock->punch($rider, 'in', (string) Str::uuid7());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 01:00:00', 'Asia/Kuala_Lumpur'));
    expect(fn () => $clock->punch($rider, 'in', (string) Str::uuid7()))->toThrow(ValidationException::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 07:00:00', 'Asia/Kuala_Lumpur'));
    $out = $clock->punch($rider, 'out', (string) Str::uuid7(), $in->public_id, 0);
    expect($out->service_date->toDateString())->toBe('2026-10-19')->and($out->schedule_id)->toBe($schedule->id)->and($out->worked_minutes)->toBe(480);
});

test('absence is derived only after an expected shift and day off leave holiday and incomplete stay distinct', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:59:59', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $register = app(AttendanceRegister::class);
    expect($register->query($admin, ['from' => '2026-10-19', 'until' => '2026-10-19'])->value('result_status'))->toBe('expected');
    $this->travelTo(CarbonImmutable::parse('2026-10-19 18:00:00', 'Asia/Kuala_Lumpur'));
    expect($register->query($admin, ['from' => '2026-10-19', 'until' => '2026-10-19'])->value('result_status'))->toBe('absent')->and(Attendance::query()->count())->toBe(0);
    $clock = app(ClockAttendance::class);
    $clock->punch($rider, 'in', (string) Str::uuid7());
    expect($register->query($admin, ['from' => '2026-10-19', 'until' => '2026-10-19'])->value('result_status'))->toBe('incomplete');
    foreach (['2026-10-20' => 'leave', '2026-10-21' => 'day_off', '2026-10-22' => 'public_holiday'] as $date => $kind) {
        AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'policy_id' => $schedule->policy_id, 'service_date' => $date, 'day_type' => $kind, 'scheduled_start_at' => null, 'scheduled_end_at' => null]);
    }
    expect($register->query($admin, ['from' => '2026-10-20', 'until' => '2026-10-22'])->orderBy('service_date')->pluck('result_status')->all())->toBe(['leave', 'day_off', 'public_holiday']);
});

test('independent correction closes a forgotten shift without fabricating a raw clock out and preserves decisions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $rider->givePermissionTo('attendance.correct');
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Kuala_Lumpur'));
    $correct = app(CorrectAttendance::class);
    $before = $correct->snapshot($schedule, $punch);
    $data = ['day_type' => 'scheduled', 'clock_in' => '2026-10-19T09:00', 'clock_out' => '2026-10-19T18:00', 'reason' => 'Forgot to clock out'];
    $request = $correct->request($rider, $schedule, $data, $before);
    expect($correct->request($rider, $schedule, $data, $before)->id)->toBe($request->id);
    expect($punch->refresh()->effective_clock_out_at)->toBeNull();
    $rider->assignRole('Admin');
    $rider->givePermissionTo('attendance.approve_correction');
    expect(fn () => $correct->review($rider, $request, 'approved', 'Reviewed'))->toThrow(HttpException::class);
    $admin->givePermissionTo('attendance.approve_correction');
    $correct->review($admin, $request, 'approved', 'Independent review');
    $punch->refresh();
    expect($punch->raw_clock_out_at)->toBeNull()->and($punch->clock_out_request_id)->toBeNull()->and($punch->effective_clock_out_at->format('H:i'))->toBe('10:00')->and($punch->worked_minutes)->toBe(480)->and($punch->status)->toBe('present');
    expect($correct->review($admin, $request, 'approved', 'Retry')->id)->toBe($request->id)->and($rider->notifications()->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'attendance.correction_approved')->count())->toBe(1)->and(AuditLog::query()->where('module', 'M10')->get()->toJson())->not->toContain('Forgot to clock out');
    expect(fn () => DB::transaction(fn () => DB::table('attendance_adjustments')->where('id', $request->id)->update(['reason' => 'Overwrite'])))->toThrow(QueryException::class);
    expect(app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7())->service_date->toDateString())->toBe('2026-10-20');
});

test('stale corrections cannot overwrite a later punch and rejected correction retains original values', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $admin->givePermissionTo('attendance.approve_correction');
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:00:00', 'Asia/Kuala_Lumpur'));
    $correct = app(CorrectAttendance::class);
    $request = $correct->request($admin, $schedule, ['day_type' => 'scheduled', 'clock_in' => '2026-10-19T09:00', 'clock_out' => '2026-10-19T17:00', 'reason' => 'Proposed correction'], $correct->snapshot($schedule, $punch));
    $checker = User::factory()->create();
    $checker->assignRole('Admin');
    $checker->givePermissionTo('attendance.approve_correction');
    app(ClockAttendance::class)->punch($rider, 'out', (string) Str::uuid7(), $punch->public_id, 0);
    expect(fn () => $correct->review($checker, $request, 'approved', 'Check'))->toThrow(ValidationException::class);
    $correct->review($checker, $request, 'rejected', 'Actual clock out already recorded.');
    expect($request->refresh()->status)->toBe('rejected')->and($punch->refresh()->lock_version)->toBe(1)->and($punch->raw_clock_out_at)->not->toBeNull();
});

test('calendar assignment respects approved workdays exceptions and immutable policy versions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $configure = app(ConfigureAttendance::class);
    $count = $configure->assign($admin, $rider->staff->public_id, $schedule->policy->public_id, '2026-10-20', '2026-10-25');
    expect($count)->toBe(6)->and(AttendanceSchedule::query()->where('rider_id', $rider->staff_id)->where('day_type', 'day_off')->count())->toBe(2);
    expect(fn () => $configure->assign($admin, $rider->staff->public_id, $schedule->policy->public_id, '2026-10-24', '2026-10-26'))->toThrow(ValidationException::class);
    expect(AttendanceSchedule::query()->where('service_date', '2026-10-26')->count())->toBe(0);
    $configure->assign($admin, $rider->staff->public_id, $schedule->policy->public_id, '2026-10-27', '2026-10-27', 'public_holiday');
    expect(AttendanceSchedule::query()->where('service_date', '2026-10-27')->value('day_type'))->toBe('public_holiday');
    $new = $configure->policy($admin, ['code' => $schedule->policy->code, 'rules' => array_replace($schedule->policy->rules, ['grace_minutes' => 0])]);
    expect($new->version)->toBe(2)->and($schedule->refresh()->policy_id)->not->toBe($new->id);
    expect(fn () => DB::transaction(fn () => DB::table('attendance_policies')->where('id', $new->id)->update(['is_active' => false])))->toThrow(QueryException::class);
    $this->actingAs($admin)->get(route('admin.attendance.configuration'))->assertOk()->assertSee('Publish a policy version')->assertDontSee('Assign calendar days');
});

test('invalid policy rules fail before activation and Riders cannot configure schedules', function (array $changes): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider] = attendanceFixture();
    $count = AttendancePolicy::query()->count();
    expect(fn () => app(ConfigureAttendance::class)->policy($admin, ['code' => 'invalid', 'rules' => array_replace(config('attendance.initial_rules'), $changes)]))->toThrow(ValidationException::class);
    expect(AttendancePolicy::query()->count())->toBe($count);
    expect(fn () => app(ConfigureAttendance::class)->policy($rider, ['code' => 'denied', 'rules' => config('attendance.initial_rules')]))->toThrow(AuthorizationException::class);
})->with([
    'invalid weekdays' => [['workdays' => [0, 8]]], 'missing workweek' => [['workdays' => []]],
    'negative break' => [['break_minutes' => -1]], 'half day at present' => [['half_day_minutes' => 420]],
    'present exceeds net shift' => [['present_minutes' => 481]], 'unapproved overnight' => [['shift_start' => '22:00', 'shift_end' => '07:00', 'overnight_allowed' => false]],
    'unexpected rule' => [['salary_deduction' => 100]],
]);

test('calendar-only leave correction needs independent authority and creates no fictitious punches', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 19:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $rider->givePermissionTo('attendance.correct');
    $admin->givePermissionTo('attendance.approve_correction');
    $correct = app(CorrectAttendance::class);
    $request = $correct->request($rider, $schedule, ['day_type' => 'leave', 'reason' => 'Approved leave correction'], $correct->snapshot($schedule, null));
    $register = app(AttendanceRegister::class);
    $criteria = ['from' => '2026-10-19', 'until' => '2026-10-19'];
    expect($register->query($admin, [...$criteria, 'status' => 'pending_correction'])->count())->toBe(1)->and($register->query($admin, $criteria)->value('result_status'))->toBe('absent');
    $correct->review($admin, $request, 'approved', 'Leave confirmed');
    expect($schedule->refresh()->day_type)->toBe('leave')->and(Attendance::query()->count())->toBe(0)->and($register->query($admin, $criteria)->value('result_status'))->toBe('leave');
    expect(fn () => DB::transaction(fn () => DB::table('attendance_schedules')->where('id', $schedule->id)->update(['day_type' => 'day_off'])))->toThrow(QueryException::class);
});

test('unscheduled punches stay needs review until an approved calendar link and preserve raw events', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $admin->givePermissionTo('attendance.approve_correction');
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00', 'Asia/Kuala_Lumpur'));
    $clock = app(ClockAttendance::class);
    $punch = $clock->punch($rider, 'in', (string) Str::uuid7());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 18:00', 'Asia/Kuala_Lumpur'));
    $punch = $clock->punch($rider, 'out', (string) Str::uuid7(), $punch->public_id, 0);
    expect($punch->status)->toBe('needs_review')->and($punch->schedule_id)->toBeNull();
    $raw = $punch->getRawOriginal('raw_clock_in_at');
    app(ConfigureAttendance::class)->assign($admin, $rider->staff->public_id, $schedule->policy->public_id, '2026-10-20', '2026-10-20');
    $target = AttendanceSchedule::query()->where('rider_id', $rider->staff_id)->where('service_date', '2026-10-20')->firstOrFail();
    $rider->givePermissionTo('attendance.correct');
    $correct = app(CorrectAttendance::class);
    $request = $correct->request($rider, $target, ['day_type' => 'scheduled', 'clock_in' => '2026-10-20T09:00', 'clock_out' => '2026-10-20T18:00', 'reason' => 'Link actual unscheduled shift'], $correct->snapshot($target, $punch));
    $correct->review($admin, $request, 'approved', 'Schedule link checked');
    expect($punch->refresh()->schedule_id)->toBe($target->id)->and($punch->status)->toBe('present')->and($punch->getRawOriginal('raw_clock_in_at'))->toBe($raw);
    expect(app(AttendanceRegister::class)->query($admin, ['from' => '2026-10-20', 'until' => '2026-10-20'])->count())->toBe(1);
});

test('exports reuse private filters and escape formulas', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $rider->staff->forceFill(['first_name' => '=Formula', 'last_name' => 'Test'])->save();
    $this->actingAs($admin)->withSession(['attendance.filters.'.$admin->id => ['from' => '2026-10-19', 'until' => '2026-10-19']]);
    $csv = $this->get(route('admin.attendance.export', ['format' => 'csv']))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->streamedContent();
    expect($csv)->toContain("'=Formula Test");
    $this->get(route('admin.attendance.export', ['format' => 'print']))->assertOk()->assertSee('Attendance report');
    $rider->givePermissionTo('attendance.export');
    $this->actingAs($rider)->get(route('admin.attendance.export', ['format' => 'csv']))->assertForbidden();
});

test('Livewire own punches and correction requests recheck current permissions', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $component = Livewire::actingAs($rider)->test(App\Livewire\Rider\Attendance::class)->call('clock', 'in')->assertHasNoErrors()->assertSee('Open shift');
    $rider->syncRoles([]);
    $component->call('clock', 'out')->assertForbidden();
    expect(Attendance::query()->firstOrFail()->raw_clock_out_at)->toBeNull();
    $rider->assignRole('Rider');
    $this->actingAs($rider)->get(route('rider.attendance'))->assertOk()->assertSee('Your attendance');
});

test('office staff with explicit own attendance grants use the shared personal route without Rider task access', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $rider->staff->forceFill(['is_rider' => false])->save();
    $rider->syncRoles(['Admin']);
    $rider->givePermissionTo(['attendance.view_own', 'attendance.clock']);
    $this->actingAs($rider)->get(route('attendance.own'))->assertOk();
    Livewire::actingAs($rider)->test(App\Livewire\Rider\Attendance::class)->call('clock', 'in')->assertHasNoErrors();
    expect(Attendance::query()->value('rider_id'))->toBe($rider->staff_id);
    $this->get(route('rider.tasks.index'))->assertForbidden();
});

test('late first punch after midnight binds to the configured previous-night shift', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 22:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture(['shift_start' => '22:00', 'shift_end' => '07:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 01:00', 'Asia/Kuala_Lumpur'));
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    expect($punch->service_date->toDateString())->toBe('2026-10-19')->and($punch->schedule_id)->toBe($schedule->id)->and($punch->is_late)->toBeTrue()->and($punch->late_minutes)->toBe(180);
});

test('database constraints refuse racing duplicate shifts and deletion of original punches', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [, $rider,$schedule] = attendanceFixture();
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    expect(fn () => DB::transaction(fn () => Attendance::factory()->create(['rider_id' => $rider->staff_id, 'schedule_id' => $schedule->id])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('attendances')->where('id', $punch->id)->delete()))->toThrow(QueryException::class);
    expect(Attendance::query()->count())->toBe(1);
    expect(fn () => DB::transaction(fn () => AttendanceSchedule::factory()->create(['policy_id' => $schedule->policy_id, 'scheduled_end_at' => null])))->toThrow(QueryException::class);
});

test('future times blank reasons and out-before-in fail without pending corrections', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    $correct = app(CorrectAttendance::class);
    foreach ([['reason' => '   ', 'clock_in' => '2026-10-19T09:00', 'clock_out' => '2026-10-19T10:00'], ['reason' => 'Future', 'clock_in' => '2026-10-19T09:00', 'clock_out' => '2026-10-20T10:00'], ['reason' => 'Reversed', 'clock_in' => '2026-10-19T09:00', 'clock_out' => '2026-10-19T08:00']] as $data) {
        expect(fn () => $correct->request($admin, $schedule, ['day_type' => 'scheduled', ...$data], $correct->snapshot($schedule, $punch)))->toThrow(ValidationException::class);
    }
    expect(AttendanceAdjustment::query()->count())->toBe(0)->and($punch->refresh()->effective_clock_out_at)->toBeNull();
});

test('an independently approved planned-shift correction preserves the previous calendar and reclassifies actual work', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $punch = app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7());
    $this->travelTo(CarbonImmutable::parse('2026-10-19 19:00', 'Asia/Kuala_Lumpur'));
    $punch = app(ClockAttendance::class)->punch($rider, 'out', (string) Str::uuid7(), $punch->public_id, 0);
    $correct = app(CorrectAttendance::class);
    $request = $correct->request($admin, $schedule, ['day_type' => 'scheduled', 'clock_in' => '2026-10-19T10:00', 'clock_out' => '2026-10-19T19:00', 'scheduled_start' => '2026-10-19T10:00', 'scheduled_end' => '2026-10-19T19:00', 'reason' => 'Authorized replacement shift'], $correct->snapshot($schedule, $punch));
    $checker = User::factory()->create();
    $checker->assignRole('Admin');
    $checker->givePermissionTo('attendance.approve_correction');
    $correct->review($checker, $request, 'approved', 'Changed shift checked');
    expect($punch->refresh()->is_late)->toBeFalse()->and($punch->worked_minutes)->toBe(480)->and($schedule->refresh()->scheduled_start_at->format('H:i'))->toBe('02:00')
        ->and($request->refresh()->before_values['scheduled_start_at'])->toBe('2026-10-19T01:00:00.000000Z');
});

test('malformed attendance filters return usable validation instead of database errors', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [$admin,$rider,$schedule] = attendanceFixture();
    $this->actingAs($admin)->get(route('admin.attendance.index', ['from' => 'not-a-date']))->assertOk()->assertSee('format Y-m-d');
    expect(AttendanceSchedule::query()->count())->toBe(1);
});

test('a stale staff identity cannot write a punch for the previously linked staff record', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Kuala_Lumpur'));
    [, $rider] = attendanceFixture();
    $replacement = Staff::factory()->create();
    $rider->fresh()->forceFill(['staff_id' => $replacement->id])->save();
    expect(fn () => app(ClockAttendance::class)->punch($rider, 'in', (string) Str::uuid7()))->toThrow(ValidationException::class, 'Your staff identity changed.');
    expect(Attendance::query()->count())->toBe(0);
});

test('Rider history is a protected separate screen while main attendance remains compact', function (): void {
    [$admin,$rider,$schedule] = attendanceFixture();
    $this->get(route('rider.attendance.history'))->assertRedirect(route('rider.login'));
    $this->actingAs($rider)->get(route('rider.attendance'))->assertOk()->assertSee(route('rider.attendance.history'))->assertDontSee('label="History from"', false)->assertDontSee('id="from"', false)->assertDontSee('id="attendance-history"', false);
    $this->get(route('rider.attendance.history'))->assertOk()->assertSee('Attendance History')->assertSee('History from')->assertSee('History through')->assertSee('View history')->assertSee('Back to Attendance')->assertDontSee('Shift remark (optional)');
    $rider->syncRoles([]);
    $this->get(route('rider.attendance.history'))->assertForbidden();
});
