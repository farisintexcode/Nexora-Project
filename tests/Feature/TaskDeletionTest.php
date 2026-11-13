<?php

use App\Actions\Tasks\DeleteTaskFile;
use App\Actions\Tasks\DeleteTasks;
use App\Livewire\Admin\Tasks\Index;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\CollectionActivity;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
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
        ->call('showFile', Index::MANUAL_FILE)
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
    Livewire::actingAs($viewer)->test(Index::class)->call('showFile', Index::MANUAL_FILE)->assertDontSeeHtml('wire:click="deleteSelected"');
});

test('deleted tasks leave assignment counts', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $task = deletableTask('FG001', $assignment);
    deletableTask('FG002', $assignment);
    app(DeleteTasks::class)->handle(staffManager(), [$task->public_id]);

    expect(app(BatchWork::class)->summary($assignment)['tasks'])->toBe(1)
        ->and($assignment->tasks()->count())->toBe(1);
});

/** A completed task upload with a file name, as the Manage Task Excel file list expects. */
function deletableExcelFile(AssignmentBatch $assignment, string $name = 'march-visits.xlsx'): ImportBatch
{
    $file = ImportBatch::factory()->create(['kind' => 'task', 'template_id' => ImportTemplate::query()->where('kind', 'task')->value('id'), 'assignment_batch_id' => $assignment->id, 'input_count' => 1, 'accepted_count' => 1, 'processed_count' => 1]);
    $document = Document::factory()->create(['owner_type' => 'import', 'owner_id' => $file->id, 'original_filename' => $name]);
    $file->forceFill(['source_document_id' => $document->id])->save();

    return $file;
}

function taskInExcelFile(AssignmentBatch $assignment, ImportBatch $file, string $id): Task
{
    $row = ImportRow::factory()->create(['import_batch_id' => $file->id, 'row_number' => Task::numberFromId($id) + 1]);

    return Task::factory()->create(['assignment_batch_id' => $assignment->id, 'source_task_id' => $id, 'source_task_number' => Task::numberFromId($id), 'origin_code' => 'import', 'source_import_row_id' => $row->id]);
}

test('deleting an Excel file removes it and its tasks from Manage Task but keeps the records', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $file = deletableExcelFile($assignment);
    $tasks = collect(['FG001', 'FG002'])->map(fn (string $id): Task => taskInExcelFile($assignment, $file, $id));
    $kept = deletableTask('FG003', $assignment);
    $actor = staffManager();

    Livewire::actingAs($actor)->test(Index::class)
        ->assertSee('march-visits.xlsx')->assertSeeHtml(e("deleteFile('{$file->public_id}')"))
        ->call('deleteFile', $file->public_id)
        ->assertHasNoErrors()
        ->assertDontSee('march-visits.xlsx');

    expect(ImportBatch::query()->find($file->id))->toBeNull()
        ->and(ImportBatch::withTrashed()->find($file->id)->deleted_by)->toBe($actor->id)
        ->and(Task::query()->whereIn('id', $tasks->pluck('id'))->count())->toBe(0)
        ->and(Task::withTrashed()->whereIn('id', $tasks->pluck('id'))->count())->toBe(2)
        ->and(Task::query()->find($kept->id))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'task_file_deleted')->where('entity_id', $file->public_id)->exists())->toBeTrue();
});

test('an Excel file with a task already assigned to a Rider is not deleted', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $file = deletableExcelFile($assignment);
    $safe = taskInExcelFile($assignment, $file, 'FG001');
    $assigned = taskInExcelFile($assignment, $file, 'FG002');
    TaskAllocation::factory()->create(['task_id' => $assigned->id]);

    expect(fn () => app(DeleteTaskFile::class)->handle(staffManager(), $file->public_id))->toThrow(ValidationException::class, 'unassign it first');
    expect(ImportBatch::query()->find($file->id))->not->toBeNull()
        ->and(Task::query()->whereIn('id', [$safe->id, $assigned->id])->count())->toBe(2);
});

test('only admins with the delete permission can delete an Excel file', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $file = deletableExcelFile($assignment);
    taskInExcelFile($assignment, $file, 'FG001');
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor']);

    expect(fn () => app(DeleteTaskFile::class)->handle($viewer, $file->public_id))->toThrow(HttpException::class);
    Livewire::actingAs($viewer)->test(Index::class)->assertSee('march-visits.xlsx')->assertDontSeeHtml(e("deleteFile('{$file->public_id}')"));
    expect(ImportBatch::query()->find($file->id))->not->toBeNull();
});

test('select all ticks every task on the page and can select every matching task', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $tasks = collect(range(1, 12))->map(fn (int $number): Task => deletableTask(sprintf('FG%03d', $number), $assignment));
    $component = Livewire::actingAs(staffManager())->test(Index::class)->call('showFile', Index::MANUAL_FILE)
        ->assertSeeHtml('aria-label="Select all tasks on this page"')->assertSee('Select all 12 tasks');
    $pageIds = $tasks->sortByDesc('id')->take(10)->pluck('public_id')->values()->all();

    $component->call('togglePageSelection', $pageIds)->assertSet('selected', $pageIds)
        ->call('togglePageSelection', $pageIds)->assertSet('selected', [])
        ->call('selectAllMatching');
    expect($component->get('selected'))->toEqualCanonicalizing($tasks->pluck('public_id')->all());
    $component->call('clearSelection')->assertSet('selected', []);
});
