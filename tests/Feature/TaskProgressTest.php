<?php

use App\Livewire\Admin\Home;
use App\Models\AssignmentBatch;
use App\Models\DebtAccount;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Tasks\TaskProgress;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    $this->travelTo('2026-10-31 04:00:00');
});

function progressTask(AssignmentBatch $batch, ?Staff $rider = null, string $assignedAt = '2026-10-31 02:00:00', array $attributes = []): Task
{
    $task = Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id, ...$attributes]);
    if ($rider !== null) {
        TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->id, 'assigned_at' => $assignedAt]);
    }

    return $task;
}

test('progress counts verified completion overall, today and per current Rider allocation', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $busy = Staff::factory()->create(['first_name' => 'Nordin', 'last_name' => 'Rider']);
    $quiet = Staff::factory()->create(['first_name' => 'Alwi', 'last_name' => 'Rider']);
    $completed = ['work_status' => 'completed', 'review_status' => 'approved'];
    $yesterday = '2026-10-30 02:00:00';

    progressTask($batch, $busy, $yesterday, $completed);
    $reassigned = progressTask($batch, null);
    TaskAllocation::factory()->create(['task_id' => $reassigned->id, 'collector_id' => $quiet->id, 'assigned_at' => $yesterday, 'released_at' => '2026-10-31 01:00:00']);
    TaskAllocation::factory()->create(['task_id' => $reassigned->id, 'collector_id' => $busy->id, 'assigned_at' => '2026-10-31 01:00:00']);
    progressTask($batch, $busy, attributes: $completed);
    progressTask($batch, $quiet, $yesterday);
    progressTask($batch);
    progressTask($batch, $busy, attributes: ['work_status' => 'cancelled']);

    $progress = app(TaskProgress::class)->forUser($user);

    expect($progress)->toMatchArray([
        'total' => 5, 'complete' => 2, 'outstanding' => 3, 'completionRate' => 40.0,
        'today' => 2, 'completeToday' => 1, 'outstandingToday' => 1, 'todayCompletionRate' => 50.0, 'riderCount' => 2,
    ])->and($progress['riders']->map(fn (object $rider): array => (array) $rider)->all())->toBe([
        ['name' => 'Nordin Rider', 'staff_no' => $busy->staff_no, 'total' => 3, 'complete' => 2, 'outstanding' => 1, 'assigned_today' => 2],
        ['name' => 'Alwi Rider', 'staff_no' => $quiet->staff_no, 'total' => 1, 'complete' => 0, 'outstanding' => 1, 'assigned_today' => 0],
    ]);
    Livewire::actingAs($user)->test(Home::class)->assertSee('Assignment summary report')->assertSee('Nordin Rider')->assertSee('40.00%');
});

test('the assignment filter narrows the report to one assignment and lists every Rider on it', function (): void {
    $user = staffManager();
    $crowded = AssignmentBatch::factory()->create();
    $small = AssignmentBatch::factory()->create();
    foreach (range(1, 6) as $ignored) {
        progressTask($crowded, Staff::factory()->create());
    }
    progressTask($small, Staff::factory()->create());

    $all = app(TaskProgress::class)->forUser($user);
    $filtered = app(TaskProgress::class)->forUser($user, $crowded->id, null);

    expect($all['riders'])->toHaveCount(5)->and($all['riderCount'])->toBe(7)->and($all['total'])->toBe(7)
        ->and($filtered['riders'])->toHaveCount(6)->and($filtered['riderCount'])->toBe(6)->and($filtered['total'])->toBe(6);
    Livewire::actingAs($user)->test(Home::class)
        ->set('assignment', $small->public_id)
        ->assertViewHas('report', fn (array $report): bool => $report['total'] === 1)
        ->assertViewHas('progress', fn (array $progress): bool => $progress['total'] === 7)
        ->set('assignment', 'not-a-visible-assignment')
        ->assertViewHas('report', fn (array $report): bool => $report['total'] === 7);
});

test('a local midnight boundary decides which allocations count as today', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $rider = Staff::factory()->create();
    $localMidnight = now()->timezone(config('dcms.business_timezone'))->startOfDay()->utc();
    progressTask($batch, $rider, $localMidnight->copy()->subSecond()->format('Y-m-d H:i:s.uP'));
    progressTask($batch, $rider, $localMidnight->copy()->addSecond()->format('Y-m-d H:i:s.uP'));

    expect(app(TaskProgress::class)->forUser($user))->toMatchArray(['total' => 2, 'today' => 1, 'todayCompletionRate' => 0.0]);
});

test('assignment progress lists active assignments with unfinished work, soonest due first', function (): void {
    $user = staffManager();
    $complete = ['work_status' => 'completed', 'review_status' => 'approved'];
    $pastDue = AssignmentBatch::factory()->create(['name' => 'Past due batch', 'due_date' => '2026-10-29']);
    progressTask($pastDue, attributes: $complete);
    progressTask($pastDue);
    $dueSoon = AssignmentBatch::factory()->create(['name' => 'Due soon batch', 'due_date' => '2026-11-03']);
    progressTask($dueSoon);
    $later = AssignmentBatch::factory()->create(['name' => 'Later batch', 'due_date' => '2026-12-31']);
    progressTask($later);
    progressTask($later, attributes: ['work_status' => 'cancelled']);
    progressTask(AssignmentBatch::factory()->create(['name' => 'Finished batch']), attributes: $complete);
    progressTask(AssignmentBatch::factory()->create(['name' => 'Archived batch', 'status' => 'archived']));

    $progress = app(TaskProgress::class)->assignments($user);

    expect($progress['count'])->toBe(3)
        ->and($progress['items']->map(fn (object $item): array => [$item->name, $item->total, $item->complete, $item->rate, $item->status])->all())->toBe([
            ['Past due batch', 2, 1, 50.0, 'past_due'],
            ['Due soon batch', 1, 0, 0.0, 'due_soon'],
            ['Later batch', 1, 0, 0.0, null],
        ]);
    Livewire::actingAs($user)->test(Home::class)->assertSee('Assignment progress')->assertSee('Past due')->assertSeeHtml('- Past due batch</p>')->assertDontSeeHtml('- Finished batch</p>');
});

test('admin access without task permission does not show task progress', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');

    expect(app(TaskProgress::class)->forUser($user))->toBeNull()->and(app(TaskProgress::class)->assignments($user))->toBeNull();
    Livewire::actingAs($user)->test(Home::class)->assertDontSee('Assignment summary report')->assertSee('Your workspace is ready');
});
