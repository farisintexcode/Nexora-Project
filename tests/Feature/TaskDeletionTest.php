<?php

use App\Actions\Tasks\DeleteTasks;
use App\Livewire\Admin\Tasks\Index;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\CollectionActivity;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Assignments\BatchWork;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function deletableTask(string $id = 'FG001', ?AssignmentBatch $assignment = null): Task
{
    return Task::factory()->create(['assignment_batch_id' => ($assignment ?? AssignmentBatch::factory()->create())->id, 'source_task_id' => $id, 'source_task_number' => Task::numberFromId($id)]);
}

test('deleting a task hides it everywhere but keeps its record and audit trail', function (): void {
    $actor = staffManager();
    $task = deletableTask();

    expect(app(DeleteTasks::class)->handle($actor, [$task->public_id]))->toBe(1);

    expect(Task::query()->find($task->id))->toBeNull()
        ->and(Task::withTrashed()->find($task->id)->deleted_at)->not->toBeNull()
        ->and(DB::table('tasks')->where('id', $task->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'task_deleted')->where('entity_id', $task->public_id)->exists())->toBeTrue();
    $this->actingAs($actor)->get(route('admin.tasks.show', $task))->assertNotFound();
});

test('a deleted task id can be used again in the same assignment', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $task = deletableTask('FG001', $assignment);
    app(DeleteTasks::class)->handle(staffManager(), [$task->public_id]);

    expect(deletableTask('FG001', $assignment)->exists)->toBeTrue();
});

test('manage task offers delete beside review and removes the ticked tasks', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $gone = deletableTask('FG001', $assignment);
    deletableTask('FG002', $assignment);

    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSeeHtml('wire:click="deleteSelected"')
        ->assertDontSeeHtml('btn-danger')
        ->set('selected', [$gone->public_id])
        ->assertSeeHtml('btn-danger')
        ->call('deleteSelected')
        ->assertHasNoErrors()
        ->assertSet('selected', [])
        ->assertDontSee('Task ID FG001')
        ->assertSee('Task ID FG002');
});

test('one blocked task stops the whole delete and says why', function (string $setup, string $reason): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    $safe = deletableTask('FG001', $assignment);
    $blocked = deletableTask('FG002', $assignment);
    match ($setup) {
        'assigned' => TaskAllocation::factory()->create(['task_id' => $blocked->id]),
        'started' => DB::table('tasks')->where('id', $blocked->id)->update(['work_status' => 'in_progress']),
        'activity' => CollectionActivity::factory()->create(['task_id' => $blocked->id, 'customer_id' => $blocked->customer_id]),
    };

    expect(fn () => app(DeleteTasks::class)->handle($actor, [$safe->public_id, $blocked->public_id]))->toThrow(ValidationException::class, $reason);
    expect(Task::query()->whereIn('id', [$safe->id, $blocked->id])->count())->toBe(2);
})->with([
    'assigned to a Rider' => ['assigned', 'unassign it first'],
    'already started' => ['started', 'use Cancel instead'],
    'recorded activity' => ['activity', 'use Cancel instead'],
]);

test('only admins with the delete permission can delete', function (): void {
    $task = deletableTask();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor']);

    expect(fn () => app(DeleteTasks::class)->handle($viewer, [$task->public_id]))->toThrow(HttpException::class);
    expect(Task::query()->find($task->id))->not->toBeNull();
    Livewire::actingAs($viewer)->test(Index::class)->assertDontSeeHtml('wire:click="deleteSelected"');
});

test('deleted tasks leave assignment counts', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $task = deletableTask('FG001', $assignment);
    deletableTask('FG002', $assignment);
    app(DeleteTasks::class)->handle(staffManager(), [$task->public_id]);

    expect(app(BatchWork::class)->summary($assignment)['tasks'])->toBe(1)
        ->and($assignment->tasks()->count())->toBe(1);
});
