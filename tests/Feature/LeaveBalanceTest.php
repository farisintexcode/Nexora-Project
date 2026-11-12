<?php

use App\Actions\Attendance\ConfigureAttendance;
use App\Actions\Attendance\ManageLeave;
use App\Livewire\Admin\Attendance\Index;
use App\Livewire\Admin\Attendance\LeaveManagement;
use App\Livewire\Admin\Attendance\LeaveTypes;
use App\Livewire\Rider\LeaveRequests;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\LeaveBalance;
use App\Services\Attendance\WorkCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', 'Asia/Kuala_Lumpur'));
});

function balanceFixture(int $days = 3): array
{
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $rider = User::factory()->for(Staff::factory()->create(['is_rider' => true]))->create();
    $rider->assignRole('Rider');
    $type = LeaveType::factory()->create(['configured_by' => $admin->id, 'tracks_balance' => true, 'max_calendar_days' => 93, 'allow_pending_cancel' => true]);
    $policy = app(ConfigureAttendance::class)->policy($admin, ['code' => 'balance-policy', 'rules' => array_replace(config('attendance.initial_rules'), ['workdays' => [1, 2, 3, 4, 5, 6]])]);
    app(WorkCalendar::class)->assign($admin, $rider->staff->public_id, $policy->public_id, '2026-11-09', '2027-12-31');
    app(LeaveBalance::class)->entitlement($admin, $type, 2026, $days);
    $data = ['type' => $type->public_id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'reason' => 'Synthetic leave request'];

    return [$admin, $rider, $type, $data, $policy];
}

function balanceSubmit(User $rider, array $data): LeaveRequest
{
    $action = app(ManageLeave::class);
    $preview = $action->preview($rider, $data);

    return $action->submit($rider, $data, $preview['token']);
}

test('pending reservation becomes approved usage exactly once with notifications and snapshot', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    $balance = app(LeaveBalance::class);
    $request = balanceSubmit($rider, $data);
    expect($balance->forStaff($rider->staff_id, $type, 2026))->toMatchArray(['entitlement' => 3, 'pending' => 1, 'used' => 0, 'available' => 2]);
    expect($request->type_snapshot['balance_snapshot'][2026]['entitlement'])->toBe(3);
    app(ManageLeave::class)->decide($admin, $request, 'approved', 'Approved', 0);
    expect($balance->forStaff($rider->staff_id, $type, 2026))->toMatchArray(['pending' => 0, 'used' => 1, 'available' => 2]);
    expect(fn () => app(ManageLeave::class)->decide($admin, $request, 'approved', 'Repeated', 0))->toThrow(ValidationException::class);
    expect($rider->notifications()->count())->toBe(1);
    $this->actingAs($rider)->get(route('rider.leave.show', $request))->assertOk()->assertSee('Approved');
});

test('rejection and cancellation release reservations', function (string $decision): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    $request = balanceSubmit($rider, $data);
    if ($decision === 'rejected') {
        app(ManageLeave::class)->decide($admin, $request, 'rejected', 'Cannot approve', 0);
    } else {
        app(ManageLeave::class)->cancel($rider, $request, 0);
    }
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, 2026))->toMatchArray(['pending' => 0, 'used' => 0, 'available' => 3]);
})->with(['rejected', 'cancelled']);

test('two preflight reviews cannot spend the same final balance', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture(1);
    $second = [...$data, 'start_date' => '2026-11-11', 'end_date' => '2026-11-11'];
    $action = app(ManageLeave::class);
    $review = $action->preview($rider, $second);
    balanceSubmit($rider, $data);
    expect(fn () => $action->submit($rider, $second, $review['token']))->toThrow(ValidationException::class);
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, 2026)['available'])->toBe(0);
});

test('cross-year leave reserves each year and requires explicit entitlement in both years', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    $data = [...$data, 'start_date' => '2026-12-31', 'end_date' => '2027-01-02'];
    expect(fn () => app(ManageLeave::class)->preview($rider, $data))->toThrow(ValidationException::class);
    app(LeaveBalance::class)->entitlement($admin, $type, 2027, 5);
    balanceSubmit($rider, $data);
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, 2026)['pending'])->toBe(1);
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, 2027))->toMatchArray(['pending' => 2, 'available' => 3]);
});

test('entitlement edits are year-specific audited and preserve submitted snapshots', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    $request = balanceSubmit($rider, $data);
    app(LeaveBalance::class)->entitlement($admin, $type, 2026, 5);
    expect($request->fresh()->type_snapshot['balance_snapshot'][2026]['entitlement'])->toBe(3);
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, 2026)['available'])->toBe(4);
    expect(fn () => app(LeaveBalance::class)->entitlement($admin, $type, 2025, 10))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'leave.entitlement_changed']);
});

test('authorized adjustments are immutable and cannot remove reserved days', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    balanceSubmit($rider, $data);
    $balances = app(LeaveBalance::class);
    $balances->adjust($admin, $rider->staff->public_id, $type, 2026, 2, 'Approved exception');
    expect($balances->forStaff($rider->staff_id, $type, 2026)['available'])->toBe(4);
    expect(fn () => $balances->adjust($admin, $rider->staff->public_id, $type, 2026, -5, 'Invalid reduction'))->toThrow(ValidationException::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'leave.balance_adjusted']);
});

test('recurring calendar respects Saturday work Sunday rest holidays and explicit overrides', function (): void {
    [$admin, $rider, $type, $data, $policy] = balanceFixture(10);
    app(WorkCalendar::class)->holiday($admin, $policy->public_id, '2026-11-13', 'Organization holiday');
    AttendanceSchedule::factory()->create(['rider_id' => $rider->staff_id, 'policy_id' => $policy->id, 'service_date' => '2026-11-12', 'day_type' => 'day_off', 'scheduled_start_at' => null, 'scheduled_end_at' => null]);
    $data = [...$data, 'start_date' => '2026-11-12', 'end_date' => '2026-11-16'];
    expect(app(ManageLeave::class)->duration($rider, $data))->toBe(2);
    expect(AttendanceSchedule::query()->where('rider_id', $rider->staff_id)->whereDate('service_date', '2026-11-14')->value('day_type'))->toBe('scheduled');
    balanceSubmit($rider, $data);
});

test('missing recurring assignment fails with actionable setup guidance and never guesses weekdays', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    $data = [...$data, 'start_date' => '2028-01-04', 'end_date' => '2028-01-04'];
    expect(fn () => app(ManageLeave::class)->duration($rider, $data))->toThrow(ValidationException::class, 'Schedules & policies');
});

test('non-deductible types need no entitlement and do not reserve tracked days', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture(0);
    $type->forceFill(['tracks_balance' => false])->save();
    $request = balanceSubmit($rider, $data);
    expect($request->type_snapshot['tracks_balance'])->toBeFalse();
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, 2026)['pending'])->toBe(0);
});

test('admin configures tracked type and Rider sees own annual balance before applying', function (): void {
    [$admin, $rider, $type] = balanceFixture();
    Livewire::actingAs($admin)->test(LeaveTypes::class)->call('editType', $type->public_id)->set('entitlementDays', 12)->set('description', 'Organization policy')->call('saveType')->assertHasNoErrors();
    Livewire::actingAs($rider)->test(LeaveRequests::class)->set('type', $type->public_id)->assertViewHas('balanceYears', fn ($years) => $years[2026]['entitlement'] === 12);
    Livewire::actingAs($rider)->test(LeaveTypes::class)->assertForbidden();
});

test('compact attendance filters share records totals and export session and can clear', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    app(ManageLeave::class)->duration($rider, $data);
    $component = Livewire::actingAs($admin)->test(Index::class)->set('from', $data['start_date'])->set('until', $data['end_date'])->set('rider', $rider->staff->public_id)->set('status', 'expected')->set('search', $rider->staff->staff_no)->call('$refresh');
    $component->assertViewHas('rows', fn ($rows) => $rows->total() === 1)->assertViewHas('totals', fn ($totals) => ($totals['expected'] ?? 0) == 1);
    expect(session('attendance.filters.'.$admin->id.'.search'))->toBe($rider->staff->staff_no);
    $csv = $this->actingAs($admin)->get(route('admin.attendance.export', ['format' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain($rider->staff->staff_no)->toContain($data['start_date'])->toContain('Expected');
    $this->get(route('admin.attendance.export', ['format' => 'print']))->assertOk()->assertSee($rider->staff->staff_no);
    $component->call('resetFilters')->assertSet('search', '')->assertSet('rider', '')->assertSet('status', '');
});

test('leave filters apply Rider status type and dates and clear independently of authorization', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture();
    balanceSubmit($rider, $data);
    Livewire::actingAs($admin)->test(LeaveManagement::class)->set('rider', $rider->staff->public_id)->set('status', 'pending')->set('filterType', $type->public_id)->set('from', $data['start_date'])->set('until', $data['end_date'])->call('filterRequests')->assertViewHas('requests', fn ($requests) => $requests->total() === 1)->set('status', 'rejected')->call('filterRequests')->assertViewHas('requests', fn ($requests) => $requests->isEmpty())->call('resetFilters')->assertSet('rider', '')->assertSet('status', '');
});

test('adjustment history cannot be rewritten and Riders cannot configure entitlements or adjustments', function (): void {
    [$admin, $rider, $type] = balanceFixture();
    $balances = app(LeaveBalance::class);
    expect(fn () => $balances->entitlement($rider, $type, 2026, 99))->toThrow(HttpException::class);
    expect(fn () => $balances->adjust($rider, $rider->staff->public_id, $type, 2026, 1, 'Unauthorized'))->toThrow(HttpException::class);
    $balances->adjust($admin, $rider->staff->public_id, $type, 2026, 1, 'Authorized exception');
    expect(fn () => DB::transaction(fn () => DB::table('leave_balance_adjustments')->update(['days' => 99])))->toThrow(QueryException::class);
    expect($balances->forStaff($rider->staff_id, $type, 2026)['adjustments'])->toBe(1);
});

test('all four leave status filters return only their matching records and totals', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture(5);
    foreach (['pending', 'approved', 'rejected', 'cancelled'] as $index => $state) {
        $date = '2026-11-'.(10 + $index);
        $request = balanceSubmit($rider, [...$data, 'start_date' => $date, 'end_date' => $date]);
        if ($state === 'approved' || $state === 'rejected') {
            app(ManageLeave::class)->decide($admin, $request, $state, 'Independent decision', 0);
        } elseif ($state === 'cancelled') {
            app(ManageLeave::class)->cancel($rider, $request, 0);
        }
    }
    $component = Livewire::actingAs($admin)->test(LeaveManagement::class);
    foreach (['pending', 'approved', 'rejected', 'cancelled'] as $state) {
        $component->set('status', $state)->call('filterRequests')->assertHasNoErrors()
            ->assertViewHas('requests', fn ($requests) => $requests->total() === 1 && $requests->first()->status === $state)
            ->assertViewHas('counts', fn ($counts) => $counts->sum() === 1);
    }
});

test('application limited leave counts each request once however many working days it covers', function (): void {
    [$admin, $rider] = balanceFixture();
    $medical = LeaveType::factory()->create(['configured_by' => $admin->id, 'tracks_balance' => true, 'limit_unit' => 'applications', 'max_calendar_days' => 93, 'allow_pending_cancel' => true]);
    app(LeaveBalance::class)->entitlement($admin, $medical, 2026, 2);
    $apply = fn (string $from, string $until): LeaveRequest => balanceSubmit($rider, ['type' => $medical->public_id, 'start_date' => $from, 'end_date' => $until, 'reason' => 'Synthetic medical leave']);

    $threeDays = $apply('2026-11-10', '2026-11-12');
    expect($threeDays->total_days)->toBe(3)->and($threeDays->type_snapshot['limit_unit'])->toBe('applications')
        ->and(app(LeaveBalance::class)->forStaff($rider->staff_id, $medical, 2026))->toMatchArray(['unit' => 'applications', 'pending' => 1, 'available' => 1]);

    $apply('2026-11-13', '2026-11-13');
    expect(fn () => $apply('2026-11-14', '2026-11-14'))->toThrow(ValidationException::class, 'already used all 2');
});

test('day limited leave explains how many working days are left', function (): void {
    [, $rider, $type] = balanceFixture(2);

    expect(fn () => balanceSubmit($rider, ['type' => $type->public_id, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'reason' => 'Synthetic leave request']))
        ->toThrow(ValidationException::class, 'this request needs 3 working days, but only 2 are left');
});

test('riders see their yearly balance for every leave type on the leave page', function (): void {
    [$admin, $rider, $type, $data] = balanceFixture(5);
    balanceSubmit($rider, $data);

    Livewire::actingAs($rider)->test(LeaveRequests::class)
        ->assertSee('My leave balance 2026')->assertSee('Limit 5 working days a year')->assertSee('Waiting approval');
});
