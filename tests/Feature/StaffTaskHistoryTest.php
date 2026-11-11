<?php

use App\Livewire\Admin\Staff\Show;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\TaskReview;
use App\Models\TaskSubmission;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function allocatedTask(Staff $staff, string $id, array $state = [], bool $released = false): Task
{
    $task = Task::factory()->create(['source_task_id' => $id, 'source_task_number' => Task::numberFromId($id), ...$state]);
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $staff->id, 'released_at' => $released ? now() : null]);

    return $task;
}

test('assigned tasks show the open work currently allocated to the rider and link to task management', function (): void {
    $staff = Staff::factory()->create(['is_rider' => true]);
    $open = allocatedTask($staff, 'FG101');
    allocatedTask($staff, 'FG102', ['work_status' => 'completed', 'review_status' => 'approved']);
    allocatedTask($staff, 'FG103', released: true);
    allocatedTask(Staff::factory()->create(['is_rider' => true]), 'FG104');

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $staff])->set('tab', 'assigned')
        ->assertSee('Task ID FG101')->assertDontSee('FG102')->assertDontSee('FG103')->assertDontSee('FG104')
        ->assertSee(route('admin.tasks.show', $open), false)
        ->assertSee(route('admin.tasks.index', ['rider' => $staff->public_id, 'status' => 'active']), false);
});

test('assigned task filters narrow to returned and pending verification work', function (): void {
    $staff = Staff::factory()->create(['is_rider' => true]);
    allocatedTask($staff, 'FG201', ['work_status' => 'in_progress', 'review_status' => 'returned']);
    allocatedTask($staff, 'FG202', ['work_status' => 'submitted', 'review_status' => 'pending']);

    $page = Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $staff])->set('tab', 'assigned');

    $page->set('taskFilter', 'returned')->assertSee('FG201')->assertDontSee('FG202');
    $page->set('taskFilter', 'submitted')->assertSee('FG202')->assertDontSee('FG201');
});

test('completed tasks show only verified work allocated to the rider', function (): void {
    $staff = Staff::factory()->create(['is_rider' => true]);
    allocatedTask($staff, 'FG301', ['work_status' => 'completed', 'review_status' => 'approved', 'completed_at' => now()]);
    allocatedTask($staff, 'FG302');

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $staff])->set('tab', 'completed')
        ->assertSee('Task ID FG301')->assertDontSee('FG302');
});

test('performance combines allocation status with the riders own submissions and review outcomes', function (): void {
    $staff = Staff::factory()->create(['is_rider' => true]);
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');
    allocatedTask($staff, 'FG401');
    allocatedTask($staff, 'FG402', ['work_status' => 'submitted', 'review_status' => 'pending']);
    $completed = allocatedTask($staff, 'FG403', ['work_status' => 'completed', 'review_status' => 'approved', 'completed_at' => now()]);
    $returned = allocatedTask($staff, 'FG404', ['work_status' => 'in_progress', 'review_status' => 'returned']);
    TaskReview::factory()->create(['submission_id' => TaskSubmission::factory()->create(['task_id' => $completed->id, 'submitted_by' => $rider->id])->id, 'decision' => 'approved']);
    TaskReview::factory()->create(['submission_id' => TaskSubmission::factory()->create(['task_id' => $returned->id, 'submitted_by' => $rider->id])->id, 'decision' => 'returned']);

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $staff])->set('tab', 'performance')
        ->assertViewHas('performance', fn (array $performance): bool => $performance['open'] === 3 && $performance['submitted'] === 1
            && $performance['returned'] === 1 && $performance['completed'] === 1 && $performance['completion_rate'] === 25
            && $performance['submissions'] === 2 && $performance['approval_rate'] === 50)
        ->call('showTasks', 'assigned', 'returned')->assertSet('tab', 'assigned')->assertSet('taskFilter', 'returned')->assertSee('FG404');
});

test('performance trend buckets the riders submissions and approvals by local day', function (): void {
    $staff = Staff::factory()->create(['is_rider' => true]);
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');
    $task = allocatedTask($staff, 'FG601', ['work_status' => 'completed', 'review_status' => 'approved', 'completed_at' => now()]);
    $submission = TaskSubmission::factory()->create(['task_id' => $task->id, 'submitted_by' => $rider->id, 'submitted_at' => now()->subDays(2)]);
    TaskReview::factory()->create(['submission_id' => $submission->id, 'decision' => 'approved', 'reviewed_at' => now()]);
    TaskSubmission::factory()->create(['submitted_by' => User::factory()->create()->id, 'submitted_at' => now()]);

    Livewire::actingAs(staffManager())->test(Show::class, ['staff' => $staff])->set('tab', 'performance')->set('period', 'daily')->set('range', '7')
        ->assertViewHas('trend', fn (array $trend): bool => $trend['buckets']->count() === 7 && $trend['submitted'] === 1 && $trend['approved'] === 1
            && $trend['buckets'][4]['submitted'] === 1 && $trend['buckets'][6]['approved'] === 1)
        ->assertSee('Submitted and approved')->assertSee('Work breakdown');
});

test('staff managers without task monitoring see no task data', function (): void {
    $staff = Staff::factory()->create(['is_rider' => true]);
    allocatedTask($staff, 'FG501');
    $manager = User::factory()->create();
    $manager->givePermissionTo(['admin.access', 'riders.manage']);

    Livewire::actingAs($manager)->test(Show::class, ['staff' => $staff])->set('tab', 'assigned')
        ->assertSee('Task access required')->assertDontSee('FG501')->assertViewHas('performance', null);
});
