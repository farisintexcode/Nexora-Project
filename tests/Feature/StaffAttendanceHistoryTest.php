<?php

use App\Livewire\Admin\Staff\Show;
use App\Models\Attendance;
use App\Models\AttendancePolicy;
use App\Models\AttendanceSchedule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo('2026-11-16 12:00:00');
});

function clockedShift(Staff $rider, string $date, string $in, ?string $out = null, int $lateMinutes = 0, ?string $status = null): Attendance
{
    $clockIn = CarbonImmutable::parse($date.' '.$in, config('dcms.business_timezone'))->utc();
    $clockOut = $out ? CarbonImmutable::parse($date.' '.$out, config('dcms.business_timezone'))->utc() : null;

    return Attendance::factory()->create([
        'rider_id' => $rider->id, 'service_date' => $date,
        'raw_clock_in_at' => $clockIn, 'effective_clock_in_at' => $clockIn,
        'raw_clock_out_at' => $clockOut, 'effective_clock_out_at' => $clockOut,
        'clock_out_request_id' => $clockOut ? (string) Str::uuid7() : null,
        'status' => $status ?? ($clockOut ? 'present' : 'incomplete'), 'is_late' => $lateMinutes > 0, 'late_minutes' => $lateMinutes,
    ]);
}

/** @param list<string> $dates */
function approvedLeave(Staff $rider, array $dates, string $type = 'Annual leave', string $status = 'approved'): LeaveRequest
{
    $policy = AttendancePolicy::factory()->create();
    $leaveType = LeaveType::factory()->create(['name' => $type]);
    $reviewer = User::factory()->create();
    $request = LeaveRequest::factory()->create([
        'rider_id' => $rider->id, 'requested_by' => (User::query()->firstWhere('staff_id', $rider->id) ?? User::factory()->create(['staff_id' => $rider->id]))->id, 'leave_type_id' => $leaveType->id,
        'start_date' => min($dates), 'end_date' => max($dates), 'total_days' => count($dates),
    ]);
    foreach ($dates as $date) {
        $schedule = AttendanceSchedule::factory()->create(['rider_id' => $rider->id, 'policy_id' => $policy->id, 'service_date' => $date, 'day_type' => 'leave', 'scheduled_start_at' => null, 'scheduled_end_at' => null]);
        $request->schedules()->attach($schedule->id, ['service_date' => $date, 'charged' => true]);
    }
    if ($status !== 'pending') {
        $request->forceFill(['status' => $status, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'lock_version' => 1])->save();
    }

    return $request;
}

test('status column shows present late incomplete and open days', function (): void {
    $rider = Staff::factory()->create(['is_rider' => true]);
    clockedShift($rider, '2026-11-09', '08:55', '18:05');
    clockedShift($rider, '2026-11-10', '09:42', '18:10', lateMinutes: 42);
    clockedShift($rider, '2026-11-11', '09:00', '14:30', status: 'half_day');
    clockedShift($rider, '2026-11-12', '09:10', '12:00', status: 'incomplete');
    clockedShift($rider, '2026-11-16', '08:58');

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $rider])->set('tab', 'attendance')
        ->assertSeeInOrder(['Clock out', 'Status'])
        ->assertSeeInOrder(['16 Nov 2026', '08:58 AM', 'Open', '12 Nov 2026', '12:00 PM', 'Incomplete', '11 Nov 2026', '02:30 PM', 'Half Day', '10 Nov 2026', '09:42 AM', 'Late', '42 min', '09 Nov 2026', 'Present']);
});

test('approved leave days appear with their leave type and replace a clock-in on that day', function (): void {
    $rider = Staff::factory()->create(['is_rider' => true]);
    clockedShift($rider, '2026-11-10', '08:55', '18:05');
    clockedShift($rider, '2026-11-12', '08:50');
    approvedLeave($rider, ['2026-11-12', '2026-11-13'], 'Medical leave');
    approvedLeave($rider, ['2026-11-05'], 'Annual leave', 'pending');
    approvedLeave(Staff::factory()->create(['is_rider' => true]), ['2026-11-11'], 'Emergency leave');

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $rider])->set('tab', 'attendance')
        ->assertSeeInOrder(['Friday', '13 Nov 2026', 'On leave', 'On leave', 'Medical leave', 'Thursday', '12 Nov 2026', 'On leave', 'Medical leave', '10 Nov 2026', 'Present'])
        ->assertDontSee('08:50 AM')->assertDontSee('05 Nov 2026')->assertDontSee('Emergency leave');
});

test('attendance tab lists day date clock in and clock out for the current month by default', function (): void {
    $rider = Staff::factory()->create(['is_rider' => true]);
    clockedShift($rider, '2026-11-10', '08:05', '17:30');
    clockedShift($rider, '2026-11-11', '08:45');
    clockedShift($rider, '2026-10-20', '09:15', '18:00');
    clockedShift(Staff::factory()->create(['is_rider' => true]), '2026-11-12', '07:10', '16:10');

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $rider])->set('tab', 'attendance')
        ->assertSet('from', '2026-11-01')->assertSet('until', '2026-11-16')
        ->assertSeeInOrder(['Day', 'Date', 'Clock in', 'Clock out'])
        ->assertSeeInOrder(['Wednesday', '11 Nov 2026', '08:45 AM', 'Tuesday', '10 Nov 2026', '08:05 AM', '05:30 PM'])
        ->assertDontSee('20 Oct 2026')->assertDontSee('07:10 AM')
        ->assertDontSee('View Rider attendance');
});

test('start and end date filters narrow the attendance history', function (): void {
    $rider = Staff::factory()->create(['is_rider' => true]);
    clockedShift($rider, '2026-10-20', '09:15', '18:00');
    clockedShift($rider, '2026-11-10', '08:05', '17:30');

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $rider])->set('tab', 'attendance')
        ->set('from', '2026-10-01')->set('until', '2026-10-31')
        ->assertSee('20 Oct 2026')->assertSee('09:15 AM')->assertSee('06:00 PM')->assertDontSee('10 Nov 2026')
        ->set('from', '2026-11-15')->set('until', '2026-11-16')
        ->assertSee('No attendance in this date range')
        ->assertSeeHtml('wire:click="resetAttendanceDates"')->assertSee('Reset')
        ->call('resetAttendanceDates')
        ->assertSet('from', '2026-11-01')->assertSee('10 Nov 2026')->assertDontSee('20 Oct 2026');
});

test('attendance tab is hidden from admins without attendance access', function (): void {
    $rider = Staff::factory()->create(['is_rider' => true]);
    clockedShift($rider, '2026-11-10', '08:05', '17:30');
    $viewer = staffManager();
    $viewer->revokePermissionTo('attendance.view_all');
    $viewer->roles->each(fn ($role) => $role->revokePermissionTo('attendance.view_all'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::actingAs($viewer->fresh())->test(Show::class, ['staff' => $rider])->set('tab', 'attendance')
        ->assertDontSee('Attendance history')->assertDontSee('08:05 AM');
});
