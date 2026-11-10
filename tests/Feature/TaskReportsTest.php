<?php

use App\Actions\Reports\ManageReports;
use App\Livewire\Admin\Reports\Index;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\CollectionActivity;
use App\Models\DebtAccount;
use App\Models\Document;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\TaskReports;
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
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});
function reportFixture(): array
{
    $actor = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $task = Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id]);

    return [$actor, $task];
}
function reportProofImage(Task $task, User $uploader, array $overrides = []): Document
{
    $visit = CollectionActivity::factory()->create(['task_id' => $task->id, 'customer_id' => $task->customer_id, 'performed_by' => $uploader->id, 'activity_type_code' => 'visit']);

    return Document::factory()->create([...['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $visit->id, 'uploaded_by' => $uploader->id, 'document_type_code' => 'proof_image', 'image_category_code' => 'premises', 'scan_status' => 'clean', 'mime_type' => 'image/jpeg'], ...$overrides]);
}
function reportCriteria(array $overrides = []): array
{
    return array_replace(['family' => 'task', 'from' => '', 'until' => '', 'batch' => '', 'rider' => '', 'status' => '', 'task_id' => '', 'columns' => TaskReports::DEFAULT_COLUMNS], $overrides);
}

test('reports require active administrative report and source access', function () {
    $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    $rider = User::factory()->create();
    $rider->assignRole('Rider');
    $this->actingAs($rider)->get(route('admin.reports.index'))->assertForbidden();
    [$actor] = reportFixture();
    $this->actingAs($actor)->get(route('admin.reports.index'))->assertOk()->assertSee('Reports');
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'reports.view']);
    $this->get(route('admin.reports.index'))->assertForbidden();
});

test('report snapshots mask accounts and reconcile their totals', function () {
    [$actor,$task] = reportFixture();
    $snapshot = app(TaskReports::class)->snapshot($actor, reportCriteria(['columns' => array_keys(TaskReports::COLUMNS)]));
    expect($snapshot['task_ids'])->toBe([$task->public_id])->and($snapshot['totals']['tasks'])->toBe(1)->and($snapshot['totals']['completed'])->toBe(0)->and($snapshot['rows'][0]['account_reference'])->not->toBe($task->account->account_number);
    expect(json_encode($snapshot))->not->toContain($task->customer->display_name_ciphertext);
});

test('criteria reject invalid dates columns families and excessive snapshots', function () {
    [$actor] = reportFixture();
    $reports = app(TaskReports::class);
    foreach ([['from' => '2026-10-28', 'until' => '2026-10-27'], ['columns' => ['contact_email']], ['family' => 'money_collected']] as $overrides) {
        expect(fn () => $reports->snapshot($actor, reportCriteria($overrides)))->toThrow(ValidationException::class);
    }
    expect($reports->snapshot($actor, reportCriteria(['until' => now()->addDay()->format('Y-m-d')]))['totals']['tasks'])->toBe(1);
    expect($reports->snapshot($actor, reportCriteria(['task_id' => '99999']))['rows'])->toBe([]);
    config(['reports.max_tasks' => 0]);
    expect(fn () => $reports->snapshot($actor, reportCriteria()))->toThrow(ValidationException::class);
});

test('Rider assignment and performance reports use only currently allocated tasks', function () {
    [$actor,$task] = reportFixture();
    $allocation = TaskAllocation::factory()->create(['task_id' => $task->id]);
    $reports = app(TaskReports::class);
    $performance = $reports->snapshot($actor, reportCriteria(['family' => 'rider_performance']));
    expect($performance['rows'][0]['allocated'])->toBe(1)->and($performance['rows'][0]['completed'])->toBe(0)->and($reports->snapshot($actor, reportCriteria(['family' => 'completed_task']))['rows'])->toBe([]);
    expect($reports->snapshot($actor, reportCriteria(['rider' => 'nonexistent']))['rows'])->toBe([]);
    $allocation->forceFill(['released_at' => now()])->save();
    expect($reports->snapshot($actor, reportCriteria(['family' => 'rider_assignment']))['rows'])->toBe([]);
});

test('report rows carry the permanent assignment ID beside the assignment name', function () {
    [$actor, $task] = reportFixture();
    $snapshot = app(TaskReports::class)->snapshot($actor, reportCriteria());
    expect($snapshot['columns']['assignment_id'])->toBe('Assignment ID')
        ->and($snapshot['rows'][0]['assignment_id'])->toBe($task->batch->refresh()->assignment_code)
        ->and($snapshot['rows'][0]['batch'])->toBe($task->batch->name);
});

test('renderer escapes spreadsheet formula injection and produces trusted PDF', function () {
    [$actor] = reportFixture();
    $snapshot = app(TaskReports::class)->snapshot($actor, reportCriteria());
    $snapshot['rows'][0]['batch'] = ' =HYPERLINK("https://example.test")';
    $renderer = app(ReportRenderer::class);
    expect($renderer->render($snapshot, 'csv'))->toContain("' =HYPERLINK")->and($renderer->render($snapshot, 'pdf'))->toStartWith('%PDF-');
});

test('reports link only to viewable proof images through the protected route', function () {
    [$actor, $task] = reportFixture();
    $premises = reportProofImage($task, $actor);
    $payment = reportProofImage($task, $actor, ['image_category_code' => 'payment_evidence', 'scan_status' => 'skipped']);
    $pending = reportProofImage($task, $actor, ['scan_status' => 'pending']);
    reportProofImage($task, $actor)->delete();

    $snapshot = app(TaskReports::class)->snapshot($actor, reportCriteria());

    expect($snapshot['rows'][0]['proof_images'])->toBe([
        ['label' => '1. Premises', 'url' => route('proof.show', $premises)],
        ['label' => '2. Payment Evidence', 'url' => route('proof.show', $payment)],
    ])->and(json_encode($snapshot))->not->toContain($pending->public_id);

    $renderer = app(ReportRenderer::class);
    expect($renderer->render($snapshot, 'csv'))->toContain(route('proof.show', $premises))->toContain(route('proof.show', $payment))
        ->and($renderer->render($snapshot, 'pdf'))->toContain('/URI ('.route('proof.show', $premises).')');
    expect(view('reports.tasks', compact('snapshot'))->render())->toContain('>2. Payment Evidence</a>');
});

test('reports omit proof images when the column is not selected', function () {
    [$actor, $task] = reportFixture();
    reportProofImage($task, $actor);

    $snapshot = app(TaskReports::class)->snapshot($actor, reportCriteria(['columns' => ['task_id']]));

    expect($snapshot['columns'])->not->toHaveKey('proof_images')->and($snapshot['rows'][0])->not->toHaveKey('proof_images');
});

test('livewire cannot bypass export authorization', function () {
    [$actor] = reportFixture();
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor', 'reports.view']);
    Livewire::actingAs($actor)->test(Index::class)->call('preview')->assertSee('Matching tasks')->call('downloadReport', 'csv')->assertForbidden();
});

test('report page downloads csv and pdf immediately without queueing or storing anything', function (string $kind): void {
    [$actor] = reportFixture();
    Queue::fake();

    Livewire::actingAs($actor)->test(Index::class)
        ->call('preview')
        ->assertSee('Download CSV')->assertSee('Download PDF')
        ->assertDontSee('Queue CSV')->assertDontSee('Your report exports')
        ->call('downloadReport', $kind)
        ->assertHasNoErrors()
        ->assertFileDownloaded();

    Queue::assertNothingPushed();
    expect(AuditLog::query()->where('action', 'report.downloaded')->count())->toBe(1);
})->with(['csv', 'pdf']);

test('downloaded report files contain the filtered rows and escape spreadsheet formulas', function (): void {
    [$actor, $task] = reportFixture();
    $task->batch->forceFill(['name' => '=Formula batch'])->save();

    $csv = app(ManageReports::class)->download($actor, reportCriteria(), 'csv');
    $pdf = app(ManageReports::class)->download($actor, reportCriteria(), 'pdf');

    expect($csv['mime'])->toBe('text/csv')
        ->and($csv['name'])->toEndWith('.csv')
        ->and($csv['bytes'])->toContain($task->public_id)->toContain("'=Formula batch")
        ->and($pdf['mime'])->toBe('application/pdf')
        ->and($pdf['bytes'])->toStartWith('%PDF-');
    expect(fn () => app(ManageReports::class)->download($actor, reportCriteria(), 'exe'))->toThrow(HttpException::class);
});

test('the verification report cannot be downloaded without verification permission', function (): void {
    [$actor] = reportFixture();
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor', 'reports.view', 'reports.export']);

    expect(fn () => app(ManageReports::class)->download($actor, reportCriteria(['family' => 'verification']), 'csv'))->toThrow(AuthorizationException::class);
});

test('the old background export system is gone', function (): void {
    expect(Schema::hasTable('export_jobs'))->toBeFalse()
        ->and(Route::has('admin.reports.download'))->toBeFalse()
        ->and(DB::table('reference_values')->whereIn('code', ['report_csv', 'report_pdf'])->exists())->toBeFalse()
        ->and(fn () => Document::factory()->create(['owner_type' => 'export', 'owner_id' => 1]))->toThrow(QueryException::class);
});
