<?php

use App\Actions\Accounts\SaveDebtAccount;
use App\Actions\Assignments\ChangeBatchStatus;
use App\Actions\Tasks\SaveTask;
use App\Actions\Tasks\TaskOperation;
use App\Enums\BatchStatus;
use App\Jobs\ProcessTaskOperation;
use App\Livewire\Admin\Tasks\Editor;
use App\Livewire\Admin\Tasks\Index;
use App\Models\AccountParty;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\BulkOperationItem;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});
function taskContext(): array
{
    $actor = staffManager();
    $customer = Debtor::factory()->create();
    $account = app(SaveDebtAccount::class)->handle($actor, $customer, ['account_number' => '000123', 'billing_reference' => 'B001', 'currency' => 'MYR', 'status' => 'active', 'role_code' => 'billing_customer', 'address' => '10 Synthetic Road', 'postcode' => '00001']);
    $batch = AssignmentBatch::factory()->create();

    return [$actor, $customer, $account, $batch];
}
function taskInput(AssignmentBatch $batch, DebtAccount $account, Debtor $customer, array $changes = []): array
{
    static $nextTaskNumber = 0;

    return $changes + ['outstanding_balance' => '1000.00', 'task_id' => 'T'.++$nextTaskNumber, 'assignment_batch_id' => $batch->id, 'debt_account_id' => $account->id, 'customer_id' => $customer->id, 'priority_code' => 'normal', 'scheduled_visit_at' => '2026-10-20T09:30', 'due_at' => '2026-10-21T17:30', 'instructions' => 'Synthetic private instructions'];
}
function eligibleRider(): Staff
{
    $staff = Staff::factory()->create(['is_active' => true, 'is_rider' => true]);
    $user = User::factory()->for($staff)->create();
    $user->assignRole('Rider');

    return $staff;
}
function taskPreview(User $actor, Task $task, string $operation, ?Staff $rider = null): BulkOperation
{
    return app(TaskOperation::class)->prepare($actor, ['assignment_batch_id' => $task->assignment_batch_id, 'operation_code' => $operation, 'selection_mode' => 'single', 'task_ids' => [$task->id], 'target_collector_id' => $rider?->id, 'reason' => 'Synthetic operational reason']);
}

test('manual task preserves source identity encrypts instructions normalizes timezone and writes history atomically', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer, ['work_status' => 'completed', 'source_task_number' => 99999, 'created_by' => 99999]));
    expect($task->origin_code)->toBe('manual')->and($task->source_task_number)->toBe(Task::numberFromId($task->source_task_id))->and($task->work_status)->toBe('unstarted')->and($task->created_by)->toBe($actor->id)->and($task->scheduled_visit_at->format('H:i'))->toBe('01:30')->and($task->history()->count())->toBe(1);
    expect(DB::table('tasks')->value('special_instruction_ciphertext'))->not->toContain('Synthetic private');
    expect($task->toJson())->not->toContain('Synthetic private');
    expect($account->account_number)->toBe('000123')->and(AccountParty::query()->count())->toBe(1);
});

test('task metadata edits reject stale versions preserve original snapshots and disallow direct completion', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $input = taskInput($batch, $account, $customer);
    $task = app(SaveTask::class)->handle($actor, $input);
    $snapshot = $task->getRawOriginal('source_snapshot_ciphertext');
    $changed = app(SaveTask::class)->handle($actor, ['priority_code' => 'urgent'] + $input, $task, 0);
    expect($changed->lock_version)->toBe(1)->and($changed->getRawOriginal('source_snapshot_ciphertext'))->toBe($snapshot);
    expect(fn () => app(SaveTask::class)->handle($actor, $input, $task, 0))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('tasks')->where('id', $task->id)->update(['work_status' => 'completed'])))->toThrow(QueryException::class);
});

test('account role changes retain history and do not merge properties by address', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $old = $account->parties()->firstOrFail();
    $account = app(SaveDebtAccount::class)->handle($actor, $customer, ['account_number' => '000123', 'currency' => 'MYR', 'status' => 'active', 'role_code' => 'owner', 'address' => '10 Synthetic Road'], $account, $account->revision());
    expect($old->refresh()->valid_to)->not->toBeNull()->and($account->parties()->count())->toBe(2);
    expect(fn () => app(SaveDebtAccount::class)->handle($actor, $customer, ['account_number' => '000123', 'currency' => 'MYR', 'status' => 'active', 'role_code' => 'owner']))->toThrow(ValidationException::class);
});

test('new replace reassign unassign and cancellation preserve allocation and task history on replay', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $one = eligibleRider();
    $two = eligibleRider();
    foreach ([['new', $one], ['replace', $two], ['reassign', $one], ['unassign', null], ['cancel', null]] as [$code,$rider]) {
        $preview = taskPreview($actor, $task, $code, $rider);
        app(TaskOperation::class)->confirm($actor, $preview, $preview->preview_digest);
        app(TaskOperation::class)->process($preview->id);
        app(TaskOperation::class)->process($preview->id);
        expect($preview->refresh()->status)->toBe('completed');
        $task->refresh();
    }
    expect($task->work_status)->toBe('cancelled')->and($task->allocations()->count())->toBe(3)->and($task->currentAllocation)->toBeNull()->and($task->history()->count())->toBe(6);
});

test('new cannot replace assigned work and pending verification is excluded from allocation', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $rider = eligibleRider();
    $op = taskPreview($actor, $task, 'new', $rider);
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    app(TaskOperation::class)->process($op->id);
    $second = taskPreview($actor, $task, 'new', $rider);
    expect($second->items()->firstOrFail()->result_code)->toBe('already_assigned');
    $task->refresh();
    $task->work_status = 'submitted';
    $task->review_status = 'pending';
    $task->save();
    $blocked = taskPreview($actor, $task, 'reassign', eligibleRider());
    expect($blocked->result_counts['excluded'])->toBe(1);
});

test('stale previews and permissions revoked after confirmation cannot change allocation', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $input = taskInput($batch, $account, $customer);
    $task = app(SaveTask::class)->handle($actor, $input);
    $rider = eligibleRider();
    $op = taskPreview($actor, $task, 'new', $rider);
    app(SaveTask::class)->handle($actor, ['priority_code' => 'urgent'] + $input, $task, 0);
    expect(fn () => app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest))->toThrow(ValidationException::class);
    $op = taskPreview($actor, $task->refresh(), 'new', $rider);
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    $actor->syncRoles([]);
    app(TaskOperation::class)->process($op->id);
    expect($op->refresh()->status)->toBe('failed')->and(TaskAllocation::query()->count())->toBe(0);
});

test('rider eligibility is rechecked during processing', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $rider = eligibleRider();
    $op = taskPreview($actor, $task, 'new', $rider);
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    $rider->is_active = false;
    $rider->save();
    app(TaskOperation::class)->process($op->id);
    expect($op->refresh()->status)->toBe('failed')->and($op->result_counts['failed'])->toBe(1)->and(TaskAllocation::query()->count())->toBe(0);
});

test('an account without a current relationship to the customer cannot be tasked', function (): void {
    [$actor,$customer,,$batch] = taskContext();
    $unrelated = DebtAccount::factory()->create();
    expect(fn () => app(SaveTask::class)->handle($actor, taskInput($batch, $unrelated, $customer)))->toThrow(ValidationException::class);
});

test('task monitoring and assign task render', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $this->actingAs($actor)->get(route('admin.tasks.index'))->assertOk()->assertSee('Task monitoring');
    $this->get(route('admin.tasks.show', $task))->assertOk()->assertSee('Task details');
    $this->get(route('admin.tasks.edit', $task))->assertOk();
    $this->get(route('admin.tasks.create'))->assertOk();
    $this->get(route('admin.tasks.allocate-one', $task))->assertOk();
});

test('selected task operations reconcile exclusions and interrupted work without losing applied records', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $one = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $two = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $rider = eligibleRider();
    $operation = app(TaskOperation::class)->prepare($actor, ['assignment_batch_id' => $batch->id, 'operation_code' => 'new', 'selection_mode' => 'selected', 'task_ids' => [$one->id, $two->id], 'target_collector_id' => $rider->id, 'reason' => 'Synthetic selected tasks']);
    app(TaskOperation::class)->confirm($actor, $operation, $operation->preview_digest);
    app(TaskOperation::class)->interrupted($operation->id);
    expect($operation->refresh()->result_counts['failed'])->toBe(2)->and($operation->status)->toBe('failed');
    app(TaskOperation::class)->process($operation->id);
    expect(TaskAllocation::query()->count())->toBe(0);
});

test('cancelling a queued operation and replaying its outbox event cannot alter tasks', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $operation = taskPreview($actor, $task, 'cancel');
    app(TaskOperation::class)->confirm($actor, $operation, $operation->preview_digest);
    app(TaskOperation::class)->cancel($actor, $operation);
    $event = OutboxEvent::query()->where('aggregate_type', 'bulk_operation')->firstOrFail();
    $job = new ProcessTaskOperation($event->id);
    $job->handle(app(TaskOperation::class));
    $job->handle(app(TaskOperation::class));
    expect($task->refresh()->work_status)->toBe('unstarted')->and($operation->refresh()->status)->toBe('cancelled')->and($operation->result_counts['excluded'])->toBe(1);
    expect(ProcessedEvent::query()->where('consumer', 'm05')->count())->toBe(1);
});

test('database protects operation criteria task provenance and one current allocation', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $rider = eligibleRider();
    $op = taskPreview($actor, $task, 'new', $rider);
    expect(fn () => DB::transaction(fn () => DB::table('bulk_operations')->where('id', $op->id)->update(['reason' => 'Changed behind preview'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('tasks')->where('id', $task->id)->update(['source_task_id' => '123'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('task_status_histories')->where('task_id', $task->id)->delete()))->toThrow(QueryException::class);
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    app(TaskOperation::class)->process($op->id);
    expect(fn () => DB::transaction(fn () => TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->id])))->toThrow(QueryException::class);
});

test('task and account actions reject missing permissions and the Rider portal cannot enter admin workspaces', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $rider = eligibleRider();
    $actor->removeRole('Admin')->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor']);
    expect(fn () => app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer)))->toThrow(AuthorizationException::class);
    expect(fn () => taskPreview($actor, $task, 'new', $rider))->toThrow(HttpException::class);
    $this->actingAs($rider->user)->get(route('admin.tasks.index'))->assertForbidden();
});

test('queued work blocks batch archival and dispatch only publishes the task outbox events', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $op = taskPreview($actor, $task, 'cancel');
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    expect(fn () => app(ChangeBatchStatus::class)->handle($actor, $batch, 'archived', 'Synthetic closure', 0))->toThrow(ValidationException::class);
    Queue::fake();
    $this->artisan('tasks:dispatch')->assertSuccessful();
    Queue::assertPushed(ProcessTaskOperation::class, 1);
    app(TaskOperation::class)->process($op->id);
    app(ChangeBatchStatus::class)->handle($actor, $batch, 'archived', 'Synthetic completed disposition', 0);
    expect($batch->refresh()->status)->toBe(BatchStatus::Archived);
});

test('invalid schedules inactive accounts and archived batches cannot create partial tasks', function (array $changes): void {
    [$actor,$customer,$account,$batch] = taskContext();
    expect(fn () => app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer, $changes)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('tasks', 0);
})->with([[['scheduled_visit_at' => 'invalid']], [['scheduled_visit_at' => '2026-10-22T10:00']], [['priority_code' => 'unknown']]]);

test('returned incomplete work can be recoverably cancelled without a conflicting review projection', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $task->work_status = 'in_progress';
    $task->review_status = 'returned';
    $task->save();
    $op = taskPreview($actor, $task, 'cancel');
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    app(TaskOperation::class)->process($op->id);
    expect($task->refresh()->work_status)->toBe('cancelled')->and($task->review_status)->toBe('none')->and($task->history()->latest('id')->first()->from_review_status)->toBe('returned');
});

test('task monitoring supports exact account selection filters due fallback and cancelled states', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer, ['scheduled_visit_at' => '', 'due_at' => '']));
    Livewire::actingAs($actor)->test(Index::class)->set('query', $account->account_number)->assertSee($customer->display_name_ciphertext)->set('status', 'completed')->assertSee('No matching tasks');
    expect($task->effectiveDue()->timezone(config('dcms.business_timezone'))->format('Y-m-d H:i:s'))->toBe('2026-10-31 23:59:59');
});

test('the reviewed selection digest prevents hidden expansion before confirmation', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $one = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $two = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $op = taskPreview($actor, $one, 'cancel');
    BulkOperationItem::factory()->create(['bulk_operation_id' => $op->id, 'task_id' => $two->id]);
    expect(fn () => app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest))->toThrow(ValidationException::class);
    $op = taskPreview($actor, $one, 'cancel');
    app(TaskOperation::class)->confirm($actor, $op, $op->preview_digest);
    expect(fn () => DB::transaction(fn () => BulkOperationItem::factory()->create(['bulk_operation_id' => $op->id, 'task_id' => $two->id])))->toThrow(QueryException::class);
});

test('creating a task with new references creates the customer and account behind it', function (): void {
    [$actor,,,$batch] = taskContext();
    Livewire::actingAs($actor)->test(Editor::class)
        ->set('data.assignment_batch_id', (string) $batch->id)
        ->set('data.task_id', 'FG001')
        ->set('data.outstanding_balance', '1234.50')
        ->set('data.customer_reference', 'NEW-REF')
        ->set('data.customer_name', 'Synthetic New Customer')
        ->set('data.account_number', 'NEW-ACC')
        ->set('data.address', '12 Synthetic Road')
        ->call('save')
        ->assertHasNoErrors();
    $task = Task::query()->sole();
    expect($task->customer->customer_reference)->toBe('NEW-REF')
        ->and($task->customer->display_name_ciphertext)->toBe('Synthetic New Customer')
        ->and($task->account->account_number)->toBe('NEW-ACC')
        ->and($task->account->property->address_lines_ciphertext)->toBe('12 Synthetic Road');
});

test('creating a task reuses an existing customer and account by reference', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    Livewire::actingAs($actor)->test(Editor::class)
        ->set('data.assignment_batch_id', (string) $batch->id)
        ->set('data.task_id', 'DT2304')
        ->set('data.outstanding_balance', '50')
        ->set('data.customer_reference', $customer->customer_reference)
        ->set('data.account_number', $account->account_number)
        ->call('save')
        ->assertHasNoErrors();
    expect(Task::query()->sole()->source_task_number)->toBe(2304);
    expect(Task::query()->sole()->customer_id)->toBe($customer->id)
        ->and(Debtor::query()->count())->toBe(1)
        ->and(DebtAccount::query()->count())->toBe(1);
});

test('a new customer reference needs a name and failed saves leave nothing behind', function (): void {
    [$actor,,,$batch] = taskContext();
    Livewire::actingAs($actor)->test(Editor::class)
        ->set('data.assignment_batch_id', (string) $batch->id)
        ->set('data.task_id', 'FG001')
        ->set('data.customer_reference', 'NEW-REF')
        ->set('data.account_number', 'NEW-ACC')
        ->call('save')
        ->assertHasErrors(['data.customer_name']);
    expect(Debtor::query()->where('customer_reference', 'NEW-REF')->exists())->toBeFalse()
        ->and(DebtAccount::query()->where('account_number', 'NEW-ACC')->exists())->toBeFalse();
});

test('an account belonging to another customer cannot be tasked and creates no customer', function (): void {
    [$actor,,$account,$batch] = taskContext();
    Livewire::actingAs($actor)->test(Editor::class)
        ->set('data.assignment_batch_id', (string) $batch->id)
        ->set('data.task_id', 'FG001')
        ->set('data.outstanding_balance', '50')
        ->set('data.customer_reference', 'OTHER-REF')
        ->set('data.customer_name', 'Synthetic Other Customer')
        ->set('data.account_number', $account->account_number)
        ->call('save')
        ->assertHasErrors(['data.debt_account_id']);
    expect(Debtor::query()->where('customer_reference', 'OTHER-REF')->exists())->toBeFalse()
        ->and(Task::query()->count())->toBe(0);
});

test('a repeated or numberless task id is refused and leaves nothing behind', function (string $taskId): void {
    [$actor,$customer,$account,$batch] = taskContext();
    app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer, ['task_id' => 'FG001']));
    Livewire::actingAs($actor)->test(Editor::class)
        ->set('data.assignment_batch_id', (string) $batch->id)
        ->set('data.task_id', $taskId)
        ->set('data.outstanding_balance', '50')
        ->set('data.customer_reference', 'REPEAT-REF')
        ->set('data.customer_name', 'Synthetic Repeat')
        ->set('data.account_number', 'REPEAT-ACC')
        ->call('save')
        ->assertHasErrors(['data.task_id']);
    expect(Debtor::query()->where('customer_reference', 'REPEAT-REF')->exists())->toBeFalse()
        ->and(Task::query()->count())->toBe(1);
})->with(['FG001', 'DT1', 'NODIGITS']);

test('task details shows field activity once and says Rider', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));
    $page = $this->actingAs($actor)->get(route('admin.tasks.show', $task))->assertOk()->assertSee('Task history')->assertSee('Rider allocation history')->assertDontSee('Current Rider');

    expect(substr_count($page->getContent(), 'Field activity &amp; completion submissions') + substr_count($page->getContent(), 'Field activity & completion submissions'))->toBe(1);
});

test('task details keeps the communication and call buttons at the bottom of source and safeguards', function (): void {
    [$actor,$customer,$account,$batch] = taskContext();
    $task = app(SaveTask::class)->handle($actor, taskInput($batch, $account, $customer));

    $this->actingAs($actor)->get(route('admin.tasks.show', $task))->assertOk()
        ->assertSeeInOrder(['Source &amp; safeguards', 'connect in M06/M07', 'Open communication templates', 'Open Call Centre workspace', '</aside>'], false)
        ->assertSee('btn btn-success min-h-9', false);
});

test('customer management pages and permissions are gone', function (): void {
    [$actor] = taskContext();
    foreach (['/admin/customers', '/admin/customers/create', '/admin/customers/categories'] as $path) {
        $this->actingAs($actor)->get($path)->assertNotFound();
    }
    expect(Route::has('admin.customers.index'))->toBeFalse()
        ->and(Route::has('admin.accounts.edit'))->toBeFalse()
        ->and(Permission::query()->whereIn('name', ['customers.create', 'customers.update', 'customers.categorize', 'customers.manage_categories', 'customers.view_sensitive'])->exists())->toBeFalse()
        ->and(Schema::hasTable('customer_categories'))->toBeFalse()
        ->and(Schema::hasColumn('import_rows', 'target_customer_id'))->toBeFalse();
});
