<?php

use App\Livewire\Admin;
use App\Livewire\Admin\Tasks\Allocation;
use App\Livewire\Admin\Tasks\Editor;
use App\Livewire\Admin\Tasks\Index;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\Reports\TaskReports;
use App\Services\Tasks\TaskIdSelection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        ->assertSee($assignment->id.' - Filter assignment')
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

test('manage task cards each use their own colour', function (): void {
    $page = Livewire::actingAs(staffManager())->test(Index::class);
    foreach (['blue' => 'Active work', 'orange' => 'Awaiting allocation', 'green' => 'Assigned work', 'yellow' => 'Pending verification'] as $tone => $label) {
        $page->assertSeeHtmlInOrder(["task-metric-{$tone}", $label]);
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
        ->assertSee($assignment->id.' - Typed assignment')
        ->assertDontSee('Assignment batch')
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->assertSee('02 Nov 2026 - 03 Nov 2026');
});

test('verification shows three main filters and applies the rest only from the panel', function (): void {
    $assignment = AssignmentBatch::factory()->create(['name' => 'Review assignment']);
    Livewire::actingAs(staffManager())->test(Admin\Verification\Index::class)
        ->assertSee($assignment->id.' - Review assignment')
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
        ->assertSee($assignment->id.' - Report assignment')
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
        ->assertSee($assignment->id.' - Typed assignment')
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
        ->call('preview')
        ->assertHasNoErrors();
    $operation = BulkOperation::query()->sole();
    $page->assertRedirect(route('admin.tasks.operation', $operation));
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
        ->call('preview')
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
    $page->call('preview')->assertHasErrors($errors);
    expect(BulkOperation::query()->count())->toBe(0);
})->with([
    'both blank' => [[], ['rangeFrom', 'rangeTo']],
    'to blank' => [['rangeFrom' => '1'], ['rangeTo']],
    'comma in from' => [['rangeFrom' => '1, 2', 'rangeTo' => '5'], ['rangeFrom']],
    'range in to' => [['rangeFrom' => '1', 'rangeTo' => '2-5'], ['rangeTo']],
    'backwards' => [['rangeFrom' => '20', 'rangeTo' => '10'], ['taskIds']],
]);

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
        ->call('preview')->assertHasErrors(['mode'])
        ->set('mode', 'single')->set('taskIds', '7')
        ->call('preview')->assertHasErrors(['taskIds']);
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
        ->call('preview')
        ->assertHasNoErrors();
    expect(BulkOperation::query()->sole()->items()->sole()->task_id)->toBe($task->id)
        ->and(session()->has('task_selection.'.$actor->id))->toBeFalse();
});
