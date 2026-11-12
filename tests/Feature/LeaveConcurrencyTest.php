<?php

use App\Actions\Attendance\ConfigureAttendance;
use App\Actions\Attendance\ManageLeave;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use App\Services\Attendance\LeaveBalance;
use App\Services\Attendance\WorkCalendar;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Validation\ValidationException;

uses(DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateDatabaseTables();
    RefreshDatabaseState::$migrated = false;
});

function concurrentLeaveSubmission(int $riderId, string $typeId, string $date): Closure
{
    return static function () use ($riderId, $typeId, $date): string {
        try {
            $actor = User::query()->findOrFail($riderId);
            $workflow = app(ManageLeave::class);
            $data = ['type' => $typeId, 'start_date' => $date, 'end_date' => $date, 'reason' => 'Concurrent synthetic request'];
            $review = $workflow->preview($actor, $data);
            usleep(100000);
            $workflow->submit($actor, $data, $review['token']);

            return 'reserved';
        } catch (ValidationException) {
            return 'insufficient';
        }
    };
}

test('simultaneous submissions for different dates cannot overdraw one available day', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('Administrative');
    $rider = User::factory()->for(Staff::factory()->create(['is_rider' => true]))->create();
    $rider->assignRole('Rider');
    $type = LeaveType::factory()->create(['configured_by' => $admin->id, 'tracks_balance' => true, 'max_calendar_days' => 7]);
    $policy = app(ConfigureAttendance::class)->policy($admin, ['code' => 'concurrent-work', 'rules' => array_replace(config('attendance.initial_rules'), ['workdays' => [1, 2, 3, 4, 5, 6, 7]])]);
    $start = now()->timezone(config('dcms.business_timezone'))->addDays(10);
    if ($start->month === 12 && $start->day === 31) {
        $start->subDay();
    }
    $end = $start->copy()->addDay();
    app(WorkCalendar::class)->assign($admin, $rider->staff->public_id, $policy->public_id, $start->toDateString(), $end->toDateString());
    foreach (array_unique([$start->year, $end->year]) as $year) {
        app(LeaveBalance::class)->entitlement($admin, $type, $year, 1);
    }
    $riderId = $rider->id;
    $typeId = $type->public_id;
    $firstDate = $start->toDateString();
    $secondDate = $end->toDateString();
    $results = Concurrency::driver('process')->run([
        concurrentLeaveSubmission($riderId, $typeId, $firstDate),
        concurrentLeaveSubmission($riderId, $typeId, $secondDate),
    ]);
    sort($results);
    expect($results)->toBe(['insufficient', 'reserved']);
    expect(LeaveRequest::query()->where('rider_id', $rider->staff_id)->count())->toBe(1);
    expect(app(LeaveBalance::class)->forStaff($rider->staff_id, $type, $start->year))->toMatchArray(['pending' => 1, 'available' => 0]);
});
