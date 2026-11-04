<?php

use App\Actions\Assignments\ChangeBatchStatus;
use App\Actions\Assignments\SaveAssignmentBatch;
use App\Enums\BatchStatus;
use App\Livewire\Admin\Assignments\Editor;
use App\Livewire\Admin\Assignments\Index;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\DebtAccount;
use App\Models\ImportBatch;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function batchInput(array $overrides = []): array
{
    return array_replace(['name' => 'October Recovery', 'external_reference' => '000123', 'type_code' => 'domestic_dr', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31', 'description' => 'Synthetic operations notes', 'dca_code' => '  001  ', 'dca_name' => '  Synthetic Agency  ', 'la_name' => '', 'state_code' => 'selangor'], $overrides);
}

test('assignment creation preserves identifiers trims metadata and audits without trusting control fields', function (): void {
    $user = staffManager();
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput(['created_by' => 9999, 'status' => 'archived', 'lock_version' => 100, 'type_category' => 'position']))->call('save')->assertHasNoErrors()->assertRedirect();
    $batch = AssignmentBatch::query()->firstOrFail();
    expect($batch->external_reference)->toBe('000123')->and($batch->dca_code)->toBe('001')->and($batch->dca_name)->toBe('Synthetic Agency')
        ->and($batch->created_by)->toBe($user->id)->and($batch->status)->toBe(BatchStatus::Active)->and($batch->lock_version)->toBe(0)
        ->and($batch->type_category)->toBe('assignment_type')->and($batch->exists)->toBeTrue();
    $audit = AuditLog::query()->where('module', 'M03')->firstOrFail();
    expect($audit->action)->toBe('assignment_created')->and($audit->toJson())->not->toContain('Synthetic operations notes', 'Synthetic Agency', 'October Recovery');
});

test('batch validation refuses invalid metadata atomically', function (array $changes, string $field): void {
    $audits = AuditLog::query()->count();
    $user = staffManager();
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput($changes))->call('save')->assertHasErrors('data.'.$field);
    $this->assertDatabaseCount('assignment_batches', 0);
    $this->assertDatabaseCount('audit_logs', $audits);
})->with([
    [['name' => ' '], 'name'], [['due_date' => '2026-09-30'], 'due_date'], [['start_date' => '2026-02-30'], 'start_date'],
    [['type_code' => '99'], 'type_code'], [['state_code' => 'unknown'], 'state_code'], [['external_reference' => str_repeat('x', 101)], 'external_reference'],
    [['dca_code' => ['bad']], 'dca_code'], [['description' => str_repeat('x', 5001)], 'description'],
]);

test('the new assignment form follows the operational field scheme and fills the assignment date', function (): void {
    $this->travelTo('2026-11-01 04:00:00');
    $user = staffManager();

    Livewire::actingAs($user)->test(Editor::class)
        ->assertSet('data.start_date', '2026-11-01')->assertSet('status', 'active')
        ->assertDontSee('Commissioning client')->assertDontSee('External batch reference')
        ->assertSeeInOrder(['Assignment type', 'Assignment name', 'Assignment due date', 'Description (optional)', 'DCA code', 'DCA name', 'LA name', 'State', 'Status'])
        ->assertSeeInOrder(['Vacant', 'Domestic DR', 'Domestic CTOS', 'Domestic LOD', 'Domestic LOD CTOS', 'COM DR', 'COM LOD']);
});

test('a new assignment can start archived but saving an existing one never changes its status', function (): void {
    $user = staffManager();
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput())->set('status', 'archived')->call('save')->assertHasNoErrors();
    $archived = AssignmentBatch::query()->firstOrFail();
    expect($archived->status)->toBe(BatchStatus::Archived)->and($archived->archived_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'assignment_created')->firstOrFail()->toJson())->toContain('archived');

    $active = app(SaveAssignmentBatch::class)->handle($user, batchInput(['external_reference' => 'B-2']));
    app(SaveAssignmentBatch::class)->handle($user, batchInput(['external_reference' => 'B-2', 'name' => 'Renamed']), $active, 0, 'archived');
    expect($active->fresh()->status)->toBe(BatchStatus::Active)->and($active->fresh()->name)->toBe('Renamed');
    Livewire::actingAs($user)->test(Editor::class)->set('data', batchInput(['external_reference' => 'B-3']))->set('status', 'deleted')->call('save')->assertHasErrors('status');
});

test('external references are unique including archives while null references can repeat', function (): void {
    $user = staffManager();
    $save = app(SaveAssignmentBatch::class);
    $first = $save->handle($user, batchInput());
    app(ChangeBatchStatus::class)->handle($user, $first, 'archived', 'Synthetic completed work package', 0);
    expect(fn () => $save->handle($user, batchInput()))->toThrow(ValidationException::class);
    $save->handle($user, batchInput(['external_reference' => '']));
    $save->handle($user, batchInput(['external_reference' => '']));
    $this->assertDatabaseCount('assignment_batches', 3);
});

test('assignment managers reach every batch and malformed references are not found', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create(['name' => 'Visible batch']);
    $this->actingAs($user)->get(route('admin.assignments.index'))->assertOk()->assertSee('Visible batch');
    $this->get(route('admin.assignments.edit', $batch))->assertOk();
    $this->get('/admin/assignments/not-a-uuid/edit')->assertNotFound();
});

test('view only staff cannot create edit or archive and Rider cannot enter the register', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $user->removeRole('Admin')->givePermissionTo(['admin.access', 'assignments.view']);
    $this->actingAs($user)->get(route('admin.assignments.edit', $batch))->assertOk()->assertSee('view-only access');
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput(), $batch, 0))->toThrow(AuthorizationException::class);
    expect(fn () => app(ChangeBatchStatus::class)->handle($user, $batch, 'archived', 'Forbidden write', 0))->toThrow(AuthorizationException::class);
    $this->get(route('admin.assignments.create'))->assertForbidden();
    $rider = User::factory()->create()->assignRole('Rider');
    $this->actingAs($rider)->get(route('admin.assignments.index'))->assertForbidden();
});

test('stale edits and status changes cannot overwrite a newer batch revision', function (): void {
    $user = staffManager();
    $save = app(SaveAssignmentBatch::class);
    $batch = $save->handle($user, batchInput());
    $save->handle($user, batchInput(['name' => 'Updated batch']), $batch, 0);
    expect(fn () => $save->handle($user, batchInput(), $batch, 0))->toThrow(ValidationException::class);
    expect(fn () => app(ChangeBatchStatus::class)->handle($user, $batch, 'archived', 'Stale attempt', 0))->toThrow(ValidationException::class);
    expect($batch->fresh()->name)->toBe('Updated batch')->and($batch->fresh()->lock_version)->toBe(1);
});

test('archive is read only reopening requires a separate grant and reason and keeps audit', function (): void {
    $user = staffManager();
    $save = app(SaveAssignmentBatch::class);
    $change = app(ChangeBatchStatus::class);
    $batch = $save->handle($user, batchInput());
    expect(fn () => $change->handle($user, $batch, 'archived', ' ', 0))->toThrow(ValidationException::class);
    $batch = $change->handle($user, $batch, 'archived', 'Work package finished', 0);
    expect($batch->archived_at)->not->toBeNull()->and($batch->lock_version)->toBe(1);
    expect(fn () => $save->handle($user, batchInput(['name' => 'Tampered archive']), $batch, 1))->toThrow(ValidationException::class);
    expect(fn () => $change->handle($user, $batch, 'active', 'Reopen review', 1))->toThrow(AuthorizationException::class);
    $user->givePermissionTo('assignments.reopen');
    $batch = $change->handle($user, $batch, 'active', 'Approved reopening for new work', 1);
    expect($batch->status)->toBe(BatchStatus::Active)->and($batch->archived_at)->toBeNull()->and($batch->lock_version)->toBe(2);
    expect(AuditLog::query()->where('module', 'M03')->orderBy('id')->pluck('action')->all())->toBe(['assignment_created', 'assignment_archived', 'assignment_reopened']);
});

test('permissions are rechecked after the editor has been opened', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $form = Livewire::actingAs($user)->test(Editor::class, ['batch' => $batch]);
    $user->syncRoles([]);
    $form->call('save')->assertForbidden();
    expect($batch->fresh()->lock_version)->toBe(0);
});

test('inactive historic lookup codes can be retained but cannot be newly assigned', function (): void {
    $user = staffManager();
    $save = app(SaveAssignmentBatch::class);
    $batch = $save->handle($user, batchInput());
    ReferenceValue::query()->where('category', 'assignment_type')->where('code', 'domestic_dr')->update(['is_active' => false]);
    $save->handle($user, batchInput(['name' => 'Retained type']), $batch, 0);
    expect(fn () => $save->handle($user, batchInput(['external_reference' => 'NEW'])))->toThrow(ValidationException::class);
});

test('literal search safe filters and archive register do not leak other records', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create(['name' => 'Percent 100% package']);
    AssignmentBatch::factory()->create(['name' => 'Other package', 'status' => 'archived', 'archived_at' => now()]);
    Livewire::actingAs($user)->test(Index::class)->set('query', '%')->assertSee('Percent 100% package')->assertDontSee('Other package')
        ->set('from', 'invalid')->assertSee('format');
    Livewire::actingAs($user)->test(Index::class)->set('query', '')->set('status', 'archived')->set('sort', 'name; drop table users')->assertSee('Other package')->assertDontSee('Percent 100% package');
});

test('register applies draft filters on demand combines them with search and counts batch tasks', function (): void {
    $user = staffManager();
    $counted = AssignmentBatch::factory()->create(['name' => 'Counted package', 'start_date' => '2026-10-05', 'due_date' => '2026-10-30']);
    AssignmentBatch::factory()->create(['name' => 'Later package', 'external_reference' => null, 'start_date' => '2026-11-05', 'due_date' => '2026-11-30']);
    $account = DebtAccount::factory()->create();
    $tasks = Task::factory()->count(3)->create(['assignment_batch_id' => $counted->id, 'debt_account_id' => $account->id]);
    $tasks[0]->forceFill(['payment_update_flag' => true])->save();
    TaskAllocation::factory()->create(['task_id' => $tasks[1]->id]);

    Livewire::actingAs($user)->test(Index::class)
        ->assertViewHas('batches', fn ($batches): bool => $batches->firstWhere('id', $counted->id)->only(['upload_count', 'assigned_count', 'completed_count', 'payment_update_count']) === ['upload_count' => 3, 'assigned_count' => 1, 'completed_count' => 0, 'payment_update_count' => 1])
        ->set('draft.from', '2026-11-01')->assertSet('from', '')->assertSee('Counted package')
        ->call('applyFilters')->assertSet('from', '2026-11-01')->assertSee('Later package')->assertDontSee('Counted package')
        ->set('query', $counted->refresh()->assignment_code)->assertSee('No assignments found')
        ->call('resetAdvancedFilters')->assertSet('draft.from', '')->assertSee('Counted package')->assertDontSee('Later package')
        ->call('resetFilters')->assertSet('query', '')->assertSee('Later package')
        ->set('sort', 'oldest')->assertSeeInOrder(['Counted package', 'Later package'])
        ->call('sortBy', 'name')->assertSet('sort', 'name_asc')->call('sortBy', 'name')->assertSet('sort', 'name_desc')
        ->set('perPage', '10')->assertViewHas('batches', fn ($batches): bool => $batches->perPage() === 10);
});

test('assignment codes run in one sequence across active and archived and never change through the lifecycle', function (): void {
    $this->travelTo('2026-11-04 04:00:00');
    $user = staffManager();
    $user->givePermissionTo('assignments.reopen');
    $save = app(SaveAssignmentBatch::class);
    $change = app(ChangeBatchStatus::class);
    $create = fn (string $name, string $status = 'active'): AssignmentBatch => $save->handle($user, batchInput(['name' => $name, 'external_reference' => '']), initialStatus: $status);

    $first = $create('First package');
    $archivedOnCreate = $create('Archived package', 'archived');
    $alpha = $create('Alpha package');
    expect([$first->assignment_code, $archivedOnCreate->assignment_code, $alpha->assignment_code])->toBe(['ASG-26-0001', 'ASG-26-0002', 'ASG-26-0003']);

    $this->actingAs($user)->get(route('admin.assignments.edit', $alpha))->assertOk()->assertSee('ASG-26-0003');
    $alpha = $save->handle($user, batchInput(['name' => 'Renamed package', 'external_reference' => '']), $alpha, $alpha->lock_version);
    expect($alpha->assignment_code)->toBe('ASG-26-0003');

    $before = $alpha->getRawOriginal();
    $alpha = $change->handle($user, $alpha, 'archived', 'Work package finished', $alpha->lock_version);
    expect($alpha->assignment_code)->toBe('ASG-26-0003')->and($alpha->archived_at)->not->toBeNull()->and($alpha->name)->toBe('Renamed package')
        ->and(array_keys(array_diff_assoc($alpha->getRawOriginal(), $before)))->toEqualCanonicalizing(['status', 'archived_at', 'lock_version']);
    expect($create('Gamma package')->assignment_code)->toBe('ASG-26-0004');

    $alpha = $change->handle($user, $alpha, 'active', 'Approved reopening for new work', $alpha->lock_version);
    expect($alpha->assignment_code)->toBe('ASG-26-0003')->and($alpha->archived_at)->toBeNull();
});

test('assignment codes restart each year and are not reused after a deletion', function (): void {
    $this->travelTo('2026-12-31 15:59:00');
    $lastOf2026 = AssignmentBatch::factory()->create()->refresh();
    $this->travelTo('2026-12-31 16:01:00');
    $firstOf2027 = AssignmentBatch::factory()->create()->refresh();
    $secondOf2027 = AssignmentBatch::factory()->create()->refresh();
    expect([$lastOf2026->assignment_code, $firstOf2027->assignment_code, $secondOf2027->assignment_code])->toBe(['ASG-26-0001', 'ASG-27-0001', 'ASG-27-0002']);

    DB::table('assignment_batches')->where('id', $secondOf2027->id)->delete();
    expect(AssignmentBatch::factory()->create()->refresh()->assignment_code)->toBe('ASG-27-0003');
});

test('only the database issues assignment codes and an issued code cannot be changed', function (): void {
    $this->travelTo('2026-11-04 04:00:00');
    $batch = AssignmentBatch::factory()->create(['assignment_code' => 'ASG-99-9999'])->refresh();
    expect($batch->assignment_code)->toBe('ASG-26-0001');

    expect(fn () => DB::transaction(fn () => DB::table('assignment_batches')->where('id', $batch->id)->update(['assignment_code' => 'ASG-26-0099'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $batch->forceFill(['assignment_code' => 'ASG-26-0099'])->save()))->toThrow(QueryException::class);
    expect($batch->refresh()->assignment_code)->toBe('ASG-26-0001');
});

test('a rolled back assignment issues no code and numbers keep their full width past 9999', function (): void {
    $this->travelTo('2026-11-04 04:00:00');
    AssignmentBatch::factory()->create();
    try {
        DB::transaction(function (): void {
            AssignmentBatch::factory()->create();
            throw new RuntimeException('Synthetic failure');
        });
    } catch (RuntimeException) {
    }
    expect(AssignmentBatch::factory()->create()->refresh()->assignment_code)->toBe('ASG-26-0002');

    DB::table('assignment_code_counters')->where('year', 2026)->update(['last_no' => 9999]);
    expect(AssignmentBatch::factory()->create()->refresh()->assignment_code)->toBe('ASG-26-10000');
});

test('admin screens show the assignment code instead of the primary key', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create(['name' => 'Labelled package'])->refresh();
    $task = Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id]);
    $label = $batch->assignment_code.' - Labelled package';

    foreach ([route('admin.tasks.index'), route('admin.tasks.show', $task), route('admin.tasks.create'), route('admin.tasks.allocate'), route('admin.imports.index'), route('admin.reports.index'), route('admin.verification.index')] as $screen) {
        $this->actingAs($user)->get($screen)->assertOk()->assertSee($label)->assertDontSee($batch->id.' - Labelled package');
    }
});

test('both register tabs show the permanent code and search finds it in full or in part', function (): void {
    $this->travelTo('2026-11-04 04:00:00');
    $user = staffManager();
    AssignmentBatch::factory()->create(['name' => 'Active package', 'external_reference' => null]);
    AssignmentBatch::factory()->create(['name' => 'Other package', 'external_reference' => null]);
    AssignmentBatch::factory()->create(['name' => 'Archived package', 'external_reference' => null, 'status' => 'archived', 'archived_at' => now()]);

    Livewire::actingAs($user)->test(Index::class)
        ->assertSee('ASG-26-0001')->assertSee('ASG-26-0002')->assertDontSee('ASG-26-0003')
        ->set('query', 'asg-26-0001')->assertSee('Active package')->assertDontSee('Other package')
        ->set('query', '0002')->assertSee('Other package')->assertDontSee('Active package')
        ->set('query', '')->set('status', 'archived')->assertSee('ASG-26-0003')->assertSee('Archived package')->assertDontSee('ASG-26-0001')
        ->set('query', '99999999999999999999')->assertSee('No assignments found');
});

test('register filters one assignment date or a range through the combined date field', function (): void {
    $user = staffManager();
    AssignmentBatch::factory()->create(['name' => 'First package', 'start_date' => '2026-10-05', 'due_date' => '2026-12-30']);
    AssignmentBatch::factory()->create(['name' => 'Second package', 'start_date' => '2026-10-06', 'due_date' => '2026-12-30']);
    AssignmentBatch::factory()->create(['name' => 'Third package', 'start_date' => '2026-10-20', 'due_date' => '2026-12-30']);

    Livewire::actingAs($user)->test(Index::class)
        ->assertSee('Assignment Date')->assertDontSee('Assignment date from')->assertDontSee('Assignment date until')
        ->set('draft.from', '2026-10-05')->set('draft.until', '2026-10-05')->call('applyFilters')
        ->assertSee('First package')->assertDontSee('Second package')->assertDontSee('Third package')
        ->set('draft.from', '2026-10-05')->set('draft.until', '2026-10-06')->call('applyFilters')
        ->assertSee('First package')->assertSee('Second package')->assertDontSee('Third package');
});

test('PostgreSQL enforces dates references and lookup categories', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    foreach (['due_date' => '2026-09-01', 'type_category' => 'position', 'status' => 'deleted'] as $column => $value) {
        expect(fn () => DB::transaction(fn () => DB::table('assignment_batches')->where('id', $batch->id)->update([$column => $value])))->toThrow(QueryException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('assignment_batches')->insert(array_merge($batch->getRawOriginal(), ['id' => 999999, 'public_id' => (string) Str::uuid7()]))))->toThrow(QueryException::class);
});

test('archive checks canonical task review and import states before preserving terminal history', function (): void {
    $user = staffManager();
    $batch = AssignmentBatch::factory()->create();
    Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id, 'work_status' => 'submitted', 'review_status' => 'pending']);
    $change = app(ChangeBatchStatus::class);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Attempt with outstanding work', 0))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('tasks')->update(['work_status' => 'completed', 'review_status' => 'pending'])))->toThrow(QueryException::class);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Invalid completion projection', 0))->toThrow(ValidationException::class);
    DB::table('tasks')->update(['work_status' => 'completed', 'review_status' => 'approved']);
    ImportBatch::factory()->create(['assignment_batch_id' => $batch->id, 'status' => 'mapping']);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Pending import', 0))->toThrow(ValidationException::class);
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput(['type_code' => 'vacant']), $batch, 0))->toThrow(ValidationException::class);
    DB::table('import_batches')->update(['status' => 'failed', 'accepted_count' => 2, 'processed_count' => 1]);
    expect(fn () => $change->handle($user, $batch, 'archived', 'Unreconciled import rows', 0))->toThrow(ValidationException::class);
    DB::table('import_batches')->update(['failed_count' => 1]);
    $change->handle($user, $batch, 'archived', 'All work disposed', 0);
    expect(DB::table('tasks')->count())->toBe(1)->and(DB::table('import_batches')->count())->toBe(1);
});

test('assignment write and audit roll back together if audit persistence fails', function (): void {
    $user = staffManager();
    DB::unprepared("CREATE FUNCTION reject_batch_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic audit failure'; END; $$; CREATE TRIGGER reject_batch_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_batch_audit();");
    expect(fn () => app(SaveAssignmentBatch::class)->handle($user, batchInput()))->toThrow(QueryException::class);
    $this->assertDatabaseCount('assignment_batches', 0);
});
