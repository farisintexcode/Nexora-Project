<?php

use App\Actions\Reports\ManageReports;
use App\Jobs\GenerateReport;
use App\Livewire\Admin\Reports\Index;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\DebtAccount;
use App\Models\Document;
use App\Models\OutboxEvent;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\TaskReports;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-reports']);
});
function reportFixture(): array
{
    $actor = staffManager();
    $batch = AssignmentBatch::factory()->create();
    $task = Task::factory()->create(['assignment_batch_id' => $batch->id, 'debt_account_id' => DebtAccount::factory()->create()->id]);

    return [$actor, $task];
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

test('CSV export freezes values encrypts storage and handles duplicate queue delivery once', function () {
    [$actor,$task] = reportFixture();
    $manage = app(ManageReports::class);
    $job = $manage->request($actor, reportCriteria(), 'csv');
    $event = OutboxEvent::query()->where('event_type', 'report.generate')->sole();
    expect(DB::table('export_jobs')->value('snapshot_ciphertext'))->not->toContain($task->public_id);
    $oldBatch = $task->batch->name;
    $task->batch->forceFill(['name' => 'New mutable assignment name'])->save();
    $worker = new GenerateReport($event->id);
    $worker->handle($manage);
    $worker->handle($manage);
    $job->refresh();
    expect($job->status)->toBe('completed')->and(Document::query()->where('owner_type', 'export')->count())->toBe(1);
    $snapshot = $job->snapshot_ciphertext;
    expect($snapshot['rows'][0]['batch'])->toBe($oldBatch);
    $doc = $job->artifact;
    expect(Storage::disk('s3')->get($doc->object_key))->not->toContain($task->public_id);
    expect($manage->bytes($doc))->toContain($task->public_id);
});

test('private downloads require signature owner and expiry and detect tampering', function () {
    [$actor] = reportFixture();
    $manage = app(ManageReports::class);
    $job = $manage->request($actor, reportCriteria(), 'csv');
    $manage->generate($job->id);
    $job->refresh();
    $url = URL::temporarySignedRoute('admin.reports.download', now()->addMinutes(5), ['exportJob' => $job->public_id, 'user' => $actor->id]);
    $this->actingAs($actor)->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $this->get(route('admin.reports.download', $job))->assertForbidden();
    $other = staffManager();
    $this->actingAs($other)->get($url)->assertForbidden();
    $this->actingAs($actor);
    Storage::disk('s3')->put($job->artifact->object_key, Crypt::encryptString('tampered'));
    $this->get($url)->assertStatus(503);
    $this->travel(25)->hours();
    $url = URL::temporarySignedRoute('admin.reports.download', now()->addMinutes(5), ['exportJob' => $job->public_id, 'user' => $actor->id]);
    $this->get($url)->assertGone();
});

test('queued exports cancel after permission revocation and failed exports require explicit authorized retry', function () {
    [$actor] = reportFixture();
    $manage = app(ManageReports::class);
    $job = $manage->request($actor, reportCriteria(), 'csv');
    $manage->failed($job->id);
    expect($job->refresh()->status)->toBe('failed');
    $manage->retry($actor, $job);
    expect($job->refresh()->status)->toBe('queued')->and(OutboxEvent::query()->where('event_type', 'report.generate')->count())->toBe(2);
    $actor->syncRoles([]);
    $manage->generate($job->id);
    expect($job->refresh()->status)->toBe('cancelled')->and(Document::query()->where('owner_type', 'export')->count())->toBe(0);
});

test('renderer escapes spreadsheet formula injection and produces trusted PDF', function () {
    [$actor] = reportFixture();
    $snapshot = app(TaskReports::class)->snapshot($actor, reportCriteria());
    $snapshot['rows'][0]['batch'] = ' =HYPERLINK("https://example.test")';
    $renderer = app(ReportRenderer::class);
    expect($renderer->render($snapshot, 'csv'))->toContain("' =HYPERLINK")->and($renderer->render($snapshot, 'pdf'))->toStartWith('%PDF-');
});

test('outbox publishes exports on Redis and Livewire cannot bypass export authorization', function () {
    [$actor] = reportFixture();
    $this->actingAs($actor);
    $manage = app(ManageReports::class);
    $manage->request($actor, reportCriteria(), 'csv');
    Queue::fake();
    $this->artisan('reports:dispatch')->assertSuccessful();
    Queue::assertPushedOn('exports', GenerateReport::class);
    $actor->syncRoles([]);
    $actor->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor', 'reports.view']);
    Livewire::actingAs($actor)->test(Index::class)->call('preview')->assertSee('Matching tasks')->call('requestExport', 'csv')->assertForbidden();
});

test('PDF export uses the canonical artifact pipeline and database rejects snapshot rewrites', function () {
    [$actor] = reportFixture();
    $manage = app(ManageReports::class);
    $job = $manage->request($actor, reportCriteria(), 'pdf');
    expect(fn () => DB::transaction(fn () => DB::table('export_jobs')->where('id', $job->id)->update(['row_count' => 99])))->toThrow(QueryException::class);
    $manage->generate($job->id);
    $job->refresh();
    expect($job->status)->toBe('completed')->and($manage->bytes($job->artifact))->toStartWith('%PDF-');
    expect(fn () => DB::transaction(fn () => DB::table('export_jobs')->where('id', $job->id)->update(['status' => 'queued'])))->toThrow(QueryException::class);
});

test('storage failure never creates a completed report and terminal failure is auditable', function (): void {
    [$actor] = reportFixture();
    $manage = app(ManageReports::class);
    $job = $manage->request($actor, reportCriteria(), 'csv');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    expect(fn () => $manage->generate($job->id))->toThrow(RuntimeException::class);
    expect($job->refresh()->status)->toBe('queued')->and($job->artifact_document_id)->toBeNull();
    $manage->failed($job->id);
    expect($job->refresh()->status)->toBe('failed')->and(AuditLog::query()->where('action', 'report.failed')->where('entity_id', $job->public_id)->exists())->toBeTrue();
});
