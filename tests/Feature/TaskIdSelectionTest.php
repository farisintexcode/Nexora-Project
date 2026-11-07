<?php

use App\Livewire\Admin;
use App\Livewire\Admin\Tasks\Allocation;
use App\Livewire\Admin\Tasks\Editor;
use App\Livewire\Admin\Tasks\Index;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\ImportTemplate;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Reports\TaskReports;
use App\Services\Tasks\TaskIdSelection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function numberedTask(AssignmentBatch $assignment, string $id): Task
{
    return Task::factory()->create(['assignment_batch_id' => $assignment->id, 'source_task_id' => $id, 'source_task_number' => Task::numberFromId($id)]);
}

test('task ids read their trailing digits as the number', function (string $id, ?int $number): void {
    expect(Task::numberFromId($id))->toBe($number);
})->with([
    ['FG001', 1], ['DT2304', 2304], ['12', 12], ['000', 0], ['FG001-A', 1], ['NODIGITS', null], [str_repeat('9', 19), null],
]);

test('each selection mode resolves exactly the tasks it describes', function (string $mode, string $input, array $expected): void {
    $assignment = AssignmentBatch::factory()->create();
    foreach ([10, 11, 12, 15, 20, 25] as $number) {
        numberedTask($assignment, sprintf('FG%03d', $number));
    }
    $ids = app(TaskIdSelection::class)->resolve($assignment->id, $mode, $input);

    expect(Task::query()->whereIn('id', $ids)->orderBy('source_task_number')->pluck('source_task_number')->all())->toBe($expected);
})->with([
    'single' => ['single', '12', [12]],
    'single with prefix' => ['single', 'FG012', [12]],
    'selected' => ['selected', '12, 15, 25', [12, 15, 25]],
    'range skips missing numbers' => ['range', '10-20', [10, 11, 12, 15, 20]],
    'range with prefixes' => ['range', 'FG010 - FG012', [10, 11, 12]],
    'range and selected' => ['range_selected', '10-12, 25', [10, 11, 12, 25]],
]);

test('input that does not fit the mode or the assignment is refused', function (string $mode, string $input): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG012');
    numberedTask(AssignmentBatch::factory()->create(), 'DT013');

    expect(fn () => app(TaskIdSelection::class)->resolve($assignment->id, $mode, $input))->toThrow(ValidationException::class);
})->with([
    'single with two' => ['single', '12, 13'],
    'single with range' => ['single', '10-20'],
    'selected with range' => ['selected', '10-20'],
    'range with extra' => ['range', '10-20, 25'],
    'two without range' => ['range_selected', '12, 13'],
    'range alone in combined' => ['range_selected', '10-20'],
    'empty' => ['selected', ' , '],
    'nonsense' => ['selected', 'abc'],
    'backwards' => ['range', '20-10'],
    'missing number' => ['selected', '12, 99'],
    'other assignment only' => ['single', '13'],
    'range with no tasks' => ['range', '500-600'],
    'unknown mode' => ['everything', '12'],
]);

test('a huge range is refused without loading more than the limit', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    foreach (range(1, 201) as $number) {
        numberedTask($assignment, 'T'.$number);
    }

    expect(fn () => app(TaskIdSelection::class)->resolve($assignment->id, 'range', '1-999999999'))->toThrow(ValidationException::class, 'up to 200');
});

test('the same number cannot repeat within an assignment but can across assignments', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    numberedTask(AssignmentBatch::factory()->create(), 'FG001');

    expect(fn () => DB::transaction(fn () => numberedTask($assignment, 'DT001')))->toThrow(QueryException::class);
});

test('manage task finds a task by its id and shows it', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    numberedTask($assignment, 'FG002');

    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSee('Task ID FG001')->assertSee('Task ID FG002')
        ->set('query', 'FG002')
        ->assertSee('Task ID FG002')->assertDontSee('Task ID FG001');
});

test('manage task finds a task by typing only its number', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    numberedTask($assignment, 'FG002');

    Livewire::actingAs(staffManager())->test(Index::class)
        ->set('query', '2')
        ->assertSee('Task ID FG002')->assertDontSee('Task ID FG001');
});

test('manage task shows three main filters and applies the rest only from the panel', function (): void {
    $assignment = AssignmentBatch::factory()->create(['name' => 'Filter assignment']);
    $task = numberedTask($assignment, 'FG001');
    numberedTask($assignment, 'FG002');
    DB::table('tasks')->where('id', $task->id)->update(['priority_code' => 'urgent']);

    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSee($assignment->refresh()->assignment_code.' - Filter assignment')
        ->assertSee('All assignments')
        ->assertDontSee('Assignment batch')
        ->assertDontSee('Exact task ID')
        ->assertDontSee('Current Rider<')
        ->assertDontSee('Allocation workspace')
        ->assertSee('Assign Task')
        ->set('draft.priority', 'urgent')
        ->assertSet('priority', '')
        ->assertSee('Task ID FG002')
        ->call('applyFilters')
        ->assertSet('priority', 'urgent')
        ->assertSee('Task ID FG001')
        ->assertDontSee('Task ID FG002')
        ->call('resetAdvancedFilters')
        ->assertSet('priority', '')
        ->assertSet('draft.priority', '')
        ->assertSee('Task ID FG002');
});

test('the task date in the panel filters by creation date and clear all resets everything', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');

    Livewire::actingAs(staffManager())->test(Index::class)
        ->set('status', 'active')
        ->set('draft.from', '2000-01-01')->set('draft.until', '2000-01-02')
        ->call('applyFilters')
        ->assertDontSee('Task ID FG001')
        ->call('resetFilters')
        ->assertSet('status', 'all')
        ->assertSet('from', '')
        ->assertSet('draft.until', '')
        ->assertSee('Task ID FG001');
});

test('manage task cards match the dashboard and each use their own colour', function (): void {
    $page = Livewire::actingAs(staffManager())->test(Index::class);
    foreach (['bg-blue-600' => 'Active work', 'bg-brand-accent' => 'Awaiting allocation', 'bg-green-600' => 'Assigned work', 'bg-brand-primary' => 'Pending verification'] as $tile => $label) {
        $page->assertSeeHtmlInOrder(['metric-card', $tile, $label]);
    }
});

test('manage task table wording and the review button colour follow the selection', function (): void {
    $task = numberedTask(AssignmentBatch::factory()->create(), 'FG001');

    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSee('Assignment / Task ID')
        ->assertDontSee('Batch / source')
        ->assertDontSee('Current Rider')
        ->assertSee('Review selected tasks')
        ->assertDontSee('Review selected operations')
        ->assertDontSeeHtml('btn-success')
        ->set('selected', [$task->public_id])
        ->assertSeeHtml('btn-success');
});

test('create task shows assignment type and date like upload task', function (): void {
    $assignment = AssignmentBatch::factory()->create(['name' => 'Typed assignment', 'start_date' => '2026-11-02', 'due_date' => '2026-11-03']);

    Livewire::actingAs(staffManager())->test(Editor::class)
        ->assertSee($assignment->refresh()->assignment_code.' - Typed assignment')
        ->assertDontSee('Assignment batch')
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->assertSee('02 Nov 2026 - 03 Nov 2026');
});

test('verification shows three main filters and applies the rest only from the panel', function (): void {
    $assignment = AssignmentBatch::factory()->create(['name' => 'Review assignment']);
    Livewire::actingAs(staffManager())->test(Admin\Verification\Index::class)
        ->assertSee($assignment->refresh()->assignment_code.' - Review assignment')
        ->assertDontSee('Assignment batch')
        ->assertDontSee('Submitted from')
        ->assertSee('Submitted Date')
        ->assertSee('Account or customer reference')
        ->set('draft.from', '2026-01-01')
        ->assertSet('from', '')
        ->call('applyFilters')
        ->assertSet('from', '2026-01-01')
        ->call('resetFilters')
        ->assertSet('from', '')
        ->assertSet('status', 'pending');
});

test('reports take task ids in any mix without a mode', function (string $input, array $expected): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    foreach (['FG010', 'FG011', 'FG012', 'FG025'] as $id) {
        numberedTask($assignment, $id);
    }
    $rows = app(TaskReports::class)->snapshot($actor, ['family' => 'task', 'batch' => $assignment->public_id, 'task_id' => $input, 'columns' => ['task_id']])['rows'];

    expect(collect($rows)->pluck('task_id')->sort()->values()->all())->toBe($expected);
})->with([
    'one' => ['12', ['FG012']],
    'several' => ['10, 25', ['FG010', 'FG025']],
    'range' => ['10-11', ['FG010', 'FG011']],
    'mix' => ['10-11, 25', ['FG010', 'FG011', 'FG025']],
    'with prefix' => ['FG012', ['FG012']],
]);

test('reports refuse task id text they cannot read', function (): void {
    expect(fn () => app(TaskReports::class)->criteria(['family' => 'task', 'task_id' => 'abc', 'columns' => ['task_id']]))->toThrow(ValidationException::class);
});

test('report filters show assignment and rider lists like manage task', function (): void {
    $assignment = AssignmentBatch::factory()->create(['name' => 'Report assignment']);
    $rider = Staff::factory()->create(['is_rider' => true]);
    Livewire::actingAs(staffManager())->test(Admin\Reports\Index::class)
        ->assertSee($assignment->refresh()->assignment_code.' - Report assignment')
        ->assertSee('All assignments')
        ->assertSee('Any Rider')
        ->assertSee($rider->full_name)
        ->assertDontSee('Rider staff number');
});

test('a task id cannot be changed once the task exists', function (): void {
    $task = numberedTask(AssignmentBatch::factory()->create(), 'FG001');

    expect(fn () => DB::transaction(fn () => DB::table('tasks')->where('id', $task->id)->update(['source_task_number' => 2])))->toThrow(QueryException::class);
});

test('assign task shows assignment type and date and previews typed ids without using manage task', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create(['name' => 'Typed assignment', 'start_date' => '2026-11-02', 'due_date' => '2026-11-03']);
    $first = numberedTask($assignment, 'FG001');
    $second = numberedTask($assignment, 'FG002');
    numberedTask($assignment, 'FG003');
    $rider = Staff::factory()->create(['is_rider' => true]);
    User::factory()->for($rider)->create()->assignRole('Rider');

    $page = Livewire::actingAs($actor)->test(Allocation::class)
        ->assertSee($assignment->refresh()->assignment_code.' - Typed assignment')
        ->assertDontSee('Assignment batch')
        ->assertDontSee('IWK')
        ->assertDontSee('Task IDs')
        ->set('batchId', (string) $assignment->id)
        ->assertSee('02 Nov 2026 - 03 Nov 2026')
        ->set('mode', 'range')
        ->assertSee('Task ID (From)')
        ->assertSee('Task ID (To)')
        ->assertDontSee('Other Task IDs')
        ->set('rangeFrom', '1')
        ->set('rangeTo', 'FG002')
        ->set('riderId', (string) $rider->id)
        ->set('reason', 'Synthetic typed allocation')
        ->call('assign')
        ->assertHasNoErrors();
    $operation = BulkOperation::query()->sole();
    $page->assertRedirect(route('admin.tasks.index'));
    expect($operation->selection_mode)->toBe('range')
        ->and($operation->items()->orderBy('task_id')->pluck('task_id')->all())->toBe([$first->id, $second->id]);
});

test('range and selected combines the from and to boxes with other ids', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    foreach (['FG001', 'FG002', 'FG003', 'FG010'] as $id) {
        numberedTask($assignment, $id);
    }
    $rider = Staff::factory()->create(['is_rider' => true]);
    User::factory()->for($rider)->create()->assignRole('Rider');

    Livewire::actingAs($actor)->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)
        ->set('mode', 'range_selected')
        ->assertSee('Other Task IDs')
        ->set('rangeFrom', '1')->set('rangeTo', '2')->set('taskIds', '10')
        ->set('riderId', (string) $rider->id)
        ->set('reason', 'Synthetic combined allocation')
        ->call('assign')
        ->assertHasNoErrors();
    $numbers = Task::query()->whereIn('id', BulkOperation::query()->sole()->items()->pluck('task_id'))->orderBy('source_task_number')->pluck('source_task_number')->all();
    expect($numbers)->toBe([1, 2, 10]);
});

test('the range boxes need one valid id each in the right order', function (array $values, array $errors): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    $page = Livewire::actingAs(staffManager())->test(Allocation::class)->set('batchId', (string) $assignment->id)->set('mode', 'range')->set('reason', 'Synthetic reason');
    foreach ($values as $property => $value) {
        $page->set($property, $value);
    }
    $page->call('assign')->assertHasErrors($errors);
    expect(BulkOperation::query()->count())->toBe(0);
})->with([
    'both blank' => [[], ['rangeFrom', 'rangeTo']],
    'to blank' => [['rangeFrom' => '1'], ['rangeTo']],
    'comma in from' => [['rangeFrom' => '1, 2', 'rangeTo' => '5'], ['rangeFrom']],
    'range in to' => [['rangeFrom' => '1', 'rangeTo' => '2-5'], ['rangeTo']],
    'backwards' => [['rangeFrom' => '20', 'rangeTo' => '10'], ['taskIds']],
]);

/** A completed task upload with its file name, as the assignment's Excel file list expects. */
function uploadedFile(AssignmentBatch $assignment, string $name): ImportBatch
{
    $file = ImportBatch::factory()->create(['kind' => 'task', 'template_id' => ImportTemplate::query()->where('kind', 'task')->value('id'), 'assignment_batch_id' => $assignment->id, 'input_count' => 1, 'accepted_count' => 1, 'processed_count' => 1]);
    $document = Document::factory()->create(['owner_type' => 'import', 'owner_id' => $file->id, 'original_filename' => $name]);
    $file->forceFill(['source_document_id' => $document->id])->save();

    return $file;
}

function taskFromFile(AssignmentBatch $assignment, ImportBatch $file, string $id): Task
{
    $row = ImportRow::factory()->create(['import_batch_id' => $file->id, 'row_number' => Task::numberFromId($id) + 1]);

    return Task::factory()->create(['assignment_batch_id' => $assignment->id, 'source_task_id' => $id, 'source_task_number' => Task::numberFromId($id), 'origin_code' => 'import', 'source_import_row_id' => $row->id]);
}

test('assign task lists only the excel files in the chosen assignment and clears the choice when it changes', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $other = AssignmentBatch::factory()->create();
    $january = uploadedFile($assignment, 'january.xlsx');
    uploadedFile($assignment, 'february.xlsx');
    uploadedFile($other, 'other-assignment.xlsx');

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->assertSee('Excel File Name')
        ->assertSee('Choose an assignment first')
        ->assertDontSee('january.xlsx')
        ->set('batchId', (string) $assignment->id)
        ->assertSee('All Excel files')
        ->assertSee('january.xlsx')
        ->assertSee('february.xlsx')
        ->assertDontSee('other-assignment.xlsx')
        ->set('importId', $january->public_id)
        ->set('batchId', (string) $other->id)
        ->assertSet('importId', '')
        ->assertSee('other-assignment.xlsx')
        ->assertDontSee('january.xlsx');
});

test('choosing an excel file limits the task list and typed ids to that file', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $january = uploadedFile($assignment, 'january.xlsx');
    $february = uploadedFile($assignment, 'february.xlsx');
    $first = taskFromFile($assignment, $january, 'JAN101');
    taskFromFile($assignment, $february, 'FEB102');

    $page = Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'selected')
        ->assertSee('JAN101')->assertSee('FEB102')
        ->set('importId', $february->public_id)
        ->assertSee('FEB102')->assertDontSee('JAN101')
        ->set('importId', $january->public_id)
        ->assertSee('JAN101')->assertDontSee('FEB102');

    expect(app(TaskIdSelection::class)->resolve($assignment->id, 'range', '101-102', $january->id))->toBe([$first->id])
        ->and(fn () => app(TaskIdSelection::class)->resolve($assignment->id, 'single', '102', $january->id))->toThrow(ValidationException::class, 'Not found in this Excel file: 102.');

    $page->set('operation', 'cancel')->set('reason', 'Synthetic reason')->set('taskIds', '102')->call('assign')->assertHasErrors(['taskIds']);
    expect(BulkOperation::query()->count())->toBe(0);
});

test('select all ticks only the chosen file and clears on the second press', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $january = uploadedFile($assignment, 'january.xlsx');
    $february = uploadedFile($assignment, 'february.xlsx');
    $first = taskFromFile($assignment, $january, 'JAN101');
    $second = taskFromFile($assignment, $january, 'JAN103');
    taskFromFile($assignment, $february, 'FEB102');

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'selected')->set('importId', $january->public_id)
        ->assertSee('Select all')
        ->call('toggleAllTasks')
        ->assertSet('taskIds', '101, 103')
        ->assertSee('Clear all')
        ->assertDontSee('Select all')
        ->call('toggleAllTasks')
        ->assertSet('taskIds', '')
        ->assertSee('Select all')
        ->call('toggleAllTasks')
        ->set('operation', 'cancel')->set('reason', 'Synthetic reason')
        ->call('assign')->assertHasNoErrors();

    expect(BulkOperation::query()->sole()->items()->pluck('task_id')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

test('select all is offered only when several tasks can be chosen and respects the operation limit', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'JAN101');

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'single')
        ->assertDontSee('Select all')
        ->call('toggleAllTasks')
        ->assertSet('taskIds', '')
        ->set('mode', 'range_selected')
        ->assertDontSee('Select all')
        ->call('toggleAllTasks')
        ->assertSet('taskIds', '');

    foreach (range(102, 301) as $number) {
        numberedTask($assignment, 'JAN'.$number);
    }

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'selected')
        ->assertSee('Select first 200')
        ->assertSee('One operation can include up to 200 tasks.')
        ->call('toggleAllTasks')
        ->assertSet('taskIds', implode(', ', range(101, 300)));
});

/** @return Testable the create task form filled in for one new task */
function createTaskForm(AssignmentBatch $assignment, string $id)
{
    return Livewire::actingAs(staffManager())->test(Editor::class)
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->set('data.task_id', $id)
        ->set('data.outstanding_balance', '100.00')
        ->set('data.customer_reference', 'REF-'.$id)
        ->set('data.customer_name', 'Synthetic Customer')
        ->set('data.account_number', 'ACC-'.$id);
}

test('create task can group a task with an excel file from its assignment or leave it ungrouped', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $file = uploadedFile($assignment, 'january.xlsx');
    uploadedFile(AssignmentBatch::factory()->create(), 'other-assignment.xlsx');

    createTaskForm($assignment, 'JAN101')
        ->assertSee('Excel File Name (optional)')
        ->assertSee('january.xlsx')
        ->assertDontSee('other-assignment.xlsx')
        ->set('data.import_id', $file->public_id)
        ->call('save')->assertHasNoErrors();
    createTaskForm($assignment, 'JAN102')->call('save')->assertHasNoErrors();

    $grouped = Task::query()->where('source_task_id', 'JAN101')->sole();
    expect($grouped->import_batch_id)->toBe($file->id)
        ->and($grouped->origin_code)->toBe('manual')
        ->and(Task::query()->where('source_task_id', 'JAN102')->sole()->import_batch_id)->toBeNull()
        ->and(Task::query()->fromImport($file->id)->pluck('id')->all())->toBe([$grouped->id]);

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'selected')->set('importId', $file->public_id)
        ->assertSee('JAN101')->assertDontSee('JAN102');
});

test('create task clears the excel file when the assignment changes and refuses a file from elsewhere', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    $other = AssignmentBatch::factory()->create();
    $file = uploadedFile($assignment, 'january.xlsx');
    $foreign = uploadedFile($other, 'other-assignment.xlsx');

    createTaskForm($assignment, 'JAN101')
        ->set('data.import_id', $file->public_id)
        ->set('data.assignment_batch_id', (string) $other->id)
        ->assertSet('data.import_id', '');

    createTaskForm($assignment, 'JAN101')
        ->set('data.import_id', $foreign->public_id)
        ->call('save')->assertHasErrors(['data.import_id']);
    expect(Task::query()->where('source_task_id', 'JAN101')->exists())->toBeFalse();
});

test('manage task shows ten tasks on each page', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    foreach (range(101, 111) as $number) {
        numberedTask($assignment, 'JAN'.$number);
    }

    $tasks = Livewire::actingAs(staffManager())->test(Index::class)->viewData('tasks');

    expect($tasks->perPage())->toBe(10)->and($tasks->count())->toBe(10)->and($tasks->total())->toBe(11)->and($tasks->lastPage())->toBe(2);
});

test('manage task shows bright work statuses, pastel priorities and an orange view button', function (): void {
    $task = numberedTask(AssignmentBatch::factory()->create(), 'JAN101');
    $task->forceFill(['priority_code' => 'urgent'])->save();

    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSeeHtml('bg-slate-500 text-white')
        ->assertSeeHtml('bg-rose-200')
        ->assertSeeHtml('class="btn btn-primary" href="'.route('admin.tasks.show', $task).'"');
});

test('admin hr communication and call centre pages use the shared calendars instead of browser date fields', function (): void {
    foreach (['attendance/configuration', 'attendance/index', 'attendance/leave-management', 'attendance/show', 'calls/show', 'communications/preferences', 'communications/recipients', 'staff/editor', 'staff/index'] as $page) {
        $view = file_get_contents(resource_path("views/livewire/admin/{$page}.blade.php"));

        expect($view)->toContain('<x-date-')->and($view)->not->toMatch('/type="(date|datetime-local)"/');
    }
});

test('the dashboard names assignments in the same id and name format', function (): void {
    $assignment = AssignmentBatch::factory()->create(['name' => 'Dashboard work', 'external_reference' => 'HIDDEN-REF']);
    numberedTask($assignment, 'JAN101');

    Livewire::actingAs(staffManager())->test(Admin\Home::class)
        ->assertSeeHtml('>'.$assignment->refresh()->assignment_code.' - Dashboard work</option>')
        ->assertSeeHtml('>'.$assignment->refresh()->assignment_code.' - Dashboard work</p>')
        ->assertDontSee('Assignment batch');
});

/** @return array{0: AssignmentBatch, 1: Staff} an assignment and an eligible Rider named Abdul */
function assignmentAndRider(): array
{
    $rider = Staff::factory()->create(['is_rider' => true, 'first_name' => 'Abdul', 'last_name' => '']);
    User::factory()->for($rider)->create()->assignRole('Rider');

    return [AssignmentBatch::factory()->create(), $rider];
}

test('assign to rider applies straight away, reports success and shows the rider on manage task', function (): void {
    [$assignment, $rider] = assignmentAndRider();
    $task = numberedTask($assignment, 'JAN101');
    numberedTask($assignment, 'JAN102');

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->assertSee('Assign to Rider')
        ->assertDontSee('Prepare operation preview')
        ->set('batchId', (string) $assignment->id)->set('mode', 'range')->set('rangeFrom', '101')->set('rangeTo', '102')
        ->set('riderId', (string) $rider->id)->set('reason', 'Synthetic allocation')
        ->call('assign')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.tasks.index'));

    expect(session('status'))->toBe('2 tasks assigned to Abdul.')
        ->and($task->refresh()->currentAllocation->collector_id)->toBe($rider->id)
        ->and(BulkOperation::query()->sole()->status)->toBe('completed');

    session()->flush();
    Livewire::actingAs(staffManager())->test(Index::class)->assertSeeHtml('<td>Abdul</td>')->assertDontSeeHtml('<td>Unassigned</td>');
});

test('assign to rider warns when some tasks could not be changed and says why', function (): void {
    [$assignment, $rider] = assignmentAndRider();
    $taken = numberedTask($assignment, 'JAN101');
    TaskAllocation::factory()->create(['task_id' => $taken->id, 'collector_id' => Staff::factory()->create()->id]);
    numberedTask($assignment, 'JAN102');

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'selected')->set('taskIds', '101, 102')
        ->set('riderId', (string) $rider->id)->set('reason', 'Synthetic allocation')
        ->call('assign')
        ->assertRedirect(route('admin.tasks.index'));

    expect(session('warning'))->toBe('1 task assigned to Abdul. Not changed: 1 already have a Rider.')
        ->and(session('status'))->toBeNull();
});

test('assign to rider stays on the page with an error when no task can be changed', function (): void {
    [$assignment, $rider] = assignmentAndRider();
    $taken = numberedTask($assignment, 'JAN101');
    TaskAllocation::factory()->create(['task_id' => $taken->id, 'collector_id' => Staff::factory()->create()->id]);

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'single')->set('taskIds', '101')
        ->set('riderId', (string) $rider->id)->set('reason', 'Synthetic allocation')
        ->call('assign')
        ->assertHasErrors(['operation'])
        ->assertNoRedirect()
        ->assertSee('No tasks were changed. Not changed: 1 already have a Rider.');

    expect(BulkOperation::query()->sole()->status)->toBe('cancelled');
});

test('task details reassigns a rider on unfinished work and shows both histories as timelines', function (string $workStatus): void {
    [$assignment, $newRider] = assignmentAndRider();
    $task = numberedTask($assignment, 'JAN101');
    $first = Staff::factory()->create(['is_rider' => true, 'first_name' => 'Hakim', 'last_name' => '']);
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $first->id]);
    $task->forceFill(['work_status' => $workStatus])->save();
    $actor = staffManager();

    $this->withoutVite()->actingAs($actor)->get(route('admin.tasks.show', $task))->assertOk()->assertSee('Reassign Rider')->assertDontSee('Assign Rider');

    Livewire::actingAs($actor)->test(Allocation::class, ['task' => $task->refresh()])
        ->assertSet('operation', 'reassign')
        ->set('riderId', (string) $newRider->id)->set('reason', 'Synthetic reassignment')
        ->call('assign')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.tasks.index'));

    expect(session('status'))->toBe('1 task assigned to Abdul.')
        ->and($task->refresh()->currentAllocation->collector_id)->toBe($newRider->id);

    session()->flush();
    Livewire::actingAs($actor)->test(Admin\Tasks\Show::class, ['task' => $task])
        ->assertSeeHtml('border-l-2 border-ink-200')
        ->assertDontSeeHtml('rounded-xl border border-ink-200 p-4 text-sm')
        ->assertSeeInOrder(['Rider allocation history', 'Abdul', 'Current', 'Hakim', 'Synthetic reassignment']);
})->with(['unstarted', 'in_progress']);

test('task details offers assign rider before anyone is assigned and nothing once work is finished', function (): void {
    [$assignment] = assignmentAndRider();
    $task = numberedTask($assignment, 'JAN101');
    $done = numberedTask($assignment, 'JAN102');
    $done->forceFill(['work_status' => 'completed', 'review_status' => 'approved'])->save();
    $actor = staffManager();

    $this->withoutVite()->actingAs($actor)->get(route('admin.tasks.show', $task))->assertOk()->assertSee('Assign Rider')->assertDontSee('Reassign Rider');
    $this->get(route('admin.tasks.show', $done))->assertOk()->assertDontSee('Assign Rider')->assertDontSee('Reassign Rider');

    Livewire::actingAs($actor)->test(Allocation::class, ['task' => $task])->assertSet('operation', 'new');
});

test('unassign and cancel keep their own label and ask for confirmation first', function (): void {
    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('operation', 'cancel')
        ->assertSee('Cancel this work?')
        ->assertDontSee('Assign to Rider')
        ->set('operation', 'unassign')
        ->assertSee('Unassign the Rider?')
        ->assertDontSee('Assign to Rider');
    expect(Route::has('admin.tasks.operation'))->toBeFalse();
});

test('assign task refuses an excel file from another assignment', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    $foreign = uploadedFile(AssignmentBatch::factory()->create(), 'other-assignment.xlsx');

    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('mode', 'single')
        ->set('importId', $foreign->public_id)
        ->set('taskIds', '1')->set('operation', 'cancel')->set('reason', 'Synthetic reason')
        ->call('assign')->assertHasErrors(['importId']);
    expect(BulkOperation::query()->count())->toBe(0);
});

test('changing the mode clears the range boxes', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)
        ->set('mode', 'range')->set('rangeFrom', '1')->set('rangeTo', '2')
        ->set('mode', 'single')->assertSet('rangeFrom', '')->assertSet('rangeTo', '');
});

test('assign task requires a mode and reports ids it cannot find', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)
        ->set('reason', 'Synthetic reason')
        ->call('assign')->assertHasErrors(['mode'])
        ->set('mode', 'single')->set('taskIds', '7')
        ->call('assign')->assertHasErrors(['taskIds']);
    expect(BulkOperation::query()->count())->toBe(0);
});

test('the list fills in ids according to the mode and changing assignment clears them', function (): void {
    $assignment = AssignmentBatch::factory()->create();
    numberedTask($assignment, 'FG001');
    numberedTask($assignment, 'FG002');
    Livewire::actingAs(staffManager())->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)
        ->set('mode', 'selected')
        ->call('toggleTask', 1)->assertSet('taskIds', '1')
        ->call('toggleTask', 2)->assertSet('taskIds', '1, 2')
        ->call('toggleTask', 1)->assertSet('taskIds', '2')
        ->set('mode', 'single')->assertSet('taskIds', '')
        ->call('toggleTask', 1)->call('toggleTask', 2)->assertSet('taskIds', '2')
        ->set('batchId', '')->assertSet('taskIds', '');
});

test('tasks ticked on manage task go straight to assign task and stay usable', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    $task = numberedTask($assignment, 'FG001');
    $rider = Staff::factory()->create(['is_rider' => true]);
    User::factory()->for($rider)->create()->assignRole('Rider');
    session()->put('task_selection.'.$actor->id, [$task->public_id]);

    Livewire::actingAs($actor)->test(Allocation::class)
        ->assertSet('batchId', (string) $assignment->id)
        ->assertSee('FG001')
        ->assertDontSee('Selection mode')
        ->set('riderId', (string) $rider->id)
        ->set('reason', 'Synthetic ticked allocation')
        ->call('assign')
        ->assertHasNoErrors();
    expect(BulkOperation::query()->sole()->items()->sole()->task_id)->toBe($task->id)
        ->and(session()->has('task_selection.'.$actor->id))->toBeFalse();
});
