<?php

use App\Actions\Imports\ImportWorkflow;
use App\Enums\BatchStatus;
use App\Jobs\ProcessImport;
use App\Livewire\Admin\Imports\Index;
use App\Livewire\Admin\Imports\Review;
use App\Models\AccountParty;
use App\Models\AssignmentBatch;
use App\Models\AuditLog;
use App\Models\DebtAccount;
use App\Models\Debtor;
use App\Models\ImportBatch;
use App\Models\ImportField;
use App\Models\ImportTemplate;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\Imports\ImportTargets;
use App\Services\Imports\MalwareScanner;
use App\Services\Imports\TaskUploadTemplate;
use App\Services\Imports\WorkbookReader;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    config(['filesystems.disks.s3.bucket' => 'synthetic-imports']);
    Storage::fake('s3');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->andReturnNull();
    app()->instance(MalwareScanner::class, $scanner);
});
/** Task workbooks must carry a balance; tests that are about something else get one added. */
function withBalance(array $rows): array
{
    return array_map(fn (array $row, int $index): array => [...$row, $index === 0 ? 'outstanding_balance' : '100.00'], $rows, array_keys($rows));
}
function importFile(array $rows, string $format = 'xlsx'): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Intake');
    $book->getActiveSheet()->fromArray($rows);
    $path = tempnam(sys_get_temp_dir(), 'import-fixture-');
    ($format === 'xls' ? new Xls($book) : new Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    $file = new UploadedFile($path, 'synthetic.'.$format, null, null, true);

    return $file;
}
function stagedImport(User $actor, array $rows, string $kind = 'assignment_batch', string $format = 'xlsx'): ImportBatch
{
    $file = importFile($rows, $format);
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => $kind, 'template_id' => ImportTemplate::query()->where('kind', $kind)->firstOrFail()->id], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    app(ImportWorkflow::class)->scan($batch->id);
    $batch->refresh();
    expect($batch->status)->toBe('mapping');
    app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1);

    return $batch->refresh();
}
function mappedSelections(ImportBatch $batch): array
{
    return $batch->mappings()->get()->mapWithKeys(fn ($mapping) => [$mapping->id => ['field_id' => $mapping->field_id ? (string) $mapping->field_id : 'ignore', 'transform' => 'exact']])->all();
}
function readyImport(User $actor, array $rows, string $kind = 'assignment_batch'): ImportBatch
{
    $batch = stagedImport($actor, $rows, $kind);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));

    return $batch->refresh();
}

test('workbook preview is encrypted non-writing and commit is atomic replay safe and reconciled', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Synthetic Batch', 'domestic_dr', '2026-10-01', '2026-10-31'], ['000124', '', 'domestic_dr', '2026-10-01', '2026-10-31'], ['000123', 'Duplicate Batch', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    expect($batch->accepted_count)->toBe(1)->and($batch->rejected_count)->toBe(1)->and($batch->duplicate_count)->toBe(1);
    $this->assertDatabaseCount('assignment_batches', 0);
    expect(DB::table('import_rows')->value('raw_payload_ciphertext'))->not->toContain('Synthetic Batch');
    expect(DB::table('audit_logs')->where('module', 'M03')->count())->toBe(0);
    expect(Storage::disk('s3')->get($batch->source->object_key))->not->toContain('Synthetic');
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);
    app(ImportWorkflow::class)->process($batch->id);
    $batch->refresh();
    expect($batch->status)->toBe('completed')->and($batch->processed_count)->toBe(1)->and($batch->failed_count)->toBe(0);
    expect(AssignmentBatch::query()->firstOrFail()->external_reference)->toBe('000123');
    $this->assertDatabaseCount('assignment_batches', 1);
    $this->assertDatabaseCount('notifications', 1);
    expect($batch->rows()->where('state', 'processed')->firstOrFail()->target_assignment_batch_id)->not->toBeNull();
});

test('batch imports use existing validations preserve identifiers and cannot commit stale target revisions', function (): void {
    $actor = staffManager();
    $rows = [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000001', 'Synthetic Batch', 'domestic_dr', '2026-10-01', '2026-10-31']];
    $batch = readyImport($actor, $rows, 'assignment_batch');
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);
    $target = AssignmentBatch::query()->firstOrFail();
    expect($target->external_reference)->toBe('000001');
    $rows[1][1] = 'Updated batch';
    $update = readyImport($actor, $rows, 'assignment_batch');
    $target->name = 'Concurrent edit';
    $target->lock_version++;
    $target->save();
    app(ImportWorkflow::class)->confirm($actor, $update, $update->mapping_digest);
    app(ImportWorkflow::class)->process($update->id);
    expect($update->refresh()->status)->toBe('failed')->and($target->refresh()->name)->toBe('Concurrent edit');
    app(ImportWorkflow::class)->retry($actor, $update);
    app(ImportWorkflow::class)->process($update->id);
    expect($update->refresh()->failed_count)->toBe(1)->and(AssignmentBatch::query()->count())->toBe(1);
});

test('matching reordered normalized headers preserves exact values and profiles are immutable new versions', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [["\xEF\xBB\xBFNAME", 'Type Code', 'External Reference', 'Start Date', 'Due Date', 'Unused'], ['Synthetic Batch', 'domestic_dr', '000007', '2026-10-01', '2026-10-31', 'secret unused value']]);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    $batch->refresh();
    $one = app(ImportWorkflow::class)->profile($actor, $batch, 'Synthetic Source');
    $two = app(ImportWorkflow::class)->profile($actor, $batch, 'Synthetic Source');
    expect($one->version)->toBe(1)->and($two->version)->toBe(2)->and($two->mapping_definition)->toBe($one->mapping_definition);
    expect(fn () => DB::transaction(fn () => DB::table('import_mapping_profiles')->where('id', $one->id)->update(['name' => 'Overwritten'])))->toThrow(QueryException::class);
    app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1, $two->id);
    expect($batch->mappings()->where('match_method', 'profile')->count())->toBe(6);
});

test('unresolved required and duplicate target mappings are rejected before row writes', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date', 'name'], ['A1', 'Synthetic Batch', 'domestic_dr', '2026-10-01', '2026-10-31', 'Second Name']]);
    $selections = mappedSelections($batch);
    $last = $batch->mappings()->latest('source_column_index')->firstOrFail();
    expect($last->field_id)->toBeNull();
    $selections[$last->id]['field_id'] = (string) ImportField::query()->where('module', 'assignment_batch')->where('canonical_field', 'name')->firstOrFail()->id;
    expect(fn () => app(ImportWorkflow::class)->preview($actor, $batch, $selections))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('assignment_batches', 0);
});

test('headers without a known name or alias stay unmatched for review', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['Mystery Column', 'external_reference', 'name', 'type_code'], ['x', 'A1', 'Synthetic Batch', 'domestic_dr']]);
    $mapping = $batch->mappings()->orderBy('source_column_index')->firstOrFail();
    expect($mapping->field_id)->toBeNull()->and($mapping->match_method)->toBe('unmatched');
});

test('scanner rejection and scanner unavailability fail closed without parsing or staging', function (string $failure): void {
    $actor = staffManager();
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException($failure));
    app()->instance(MalwareScanner::class, $scanner);
    $file = importFile([['name'], ['Synthetic']]);
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => 'assignment_batch', 'template_id' => ImportTemplate::query()->where('kind', 'assignment_batch')->value('id')], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    app(ImportWorkflow::class)->scan($batch->id);
    expect($batch->refresh()->status)->toBe('failed')->and($batch->source->scan_status)->toBe($failure === 'malware_detected' ? 'rejected' : 'error');
    expect($batch->rows()->count())->toBe(0);
    $this->assertDatabaseCount('assignment_batches', 0);
})->with(['malware_detected', 'scanner_unavailable']);

test('source tampering is detected and formulas are never evaluated', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    Storage::disk('s3')->put($batch->source->object_key, Crypt::encryptString('tampered'));
    expect(fn () => app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1))->toThrow(RuntimeException::class);
    $file = importFile([['name'], ['=1+1']]);
    try {
        expect(fn () => app(WorkbookReader::class)->read($file->getRealPath(), 'Intake', 1))->toThrow(RuntimeException::class, 'formulas_not_allowed');
    } finally {
        @unlink($file->getRealPath());
    }
});

test('XLS files and bounded workbook limits use the validated parser', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000007', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']], 'assignment_batch', 'xls');
    expect($batch->rows()->firstOrFail()->raw_payload_ciphertext[1])->toBe('000007');
    config(['imports.max_rows' => 0]);
    $file = importFile([['name'], ['Synthetic']]);
    try {
        expect(fn () => app(WorkbookReader::class)->read($file->getRealPath(), 'Intake', 1))->toThrow(RuntimeException::class);
    } finally {
        @unlink($file->getRealPath());
    }
});

test('permission revocation is checked again during queued execution', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    $actor->syncRoles([]);
    app(ImportWorkflow::class)->process($batch->id);
    expect($batch->refresh()->status)->toBe('failed')->and($batch->failed_count)->toBe(1);
    $this->assertDatabaseCount('assignment_batches', 0);
    $this->actingAs($actor)->get(route('admin.imports.review', $batch))->assertForbidden();
});

test('read only and rider access cannot map or confirm', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    $actor->removeRole('Admin')->givePermissionTo(['admin.access', 'imports.view']);
    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->call('confirm')->assertForbidden();
    $rider = User::factory()->create();
    $rider->assignRole('Rider');
    $this->actingAs($rider)->get(route('admin.imports.index'))->assertForbidden();
});

test('exact preview confirmation and immutable payload prevent silent mapping changes', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    expect(fn () => app(ImportWorkflow::class)->confirm($actor, $batch, str_repeat('0', 64)))->toThrow(ValidationException::class);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    $row = $batch->rows()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('import_rows')->where('id', $row->id)->update(['raw_payload_ciphertext' => 'changed'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('import_column_mappings')->where('import_batch_id', $batch->id)->delete()))->toThrow(QueryException::class);
    expect(fn () => app(ImportWorkflow::class)->map($actor, $batch, 'Intake', 1))->toThrow(ValidationException::class);
});

test('outbox queue publication is durable and worker replay records one checkpoint', function (): void {
    $actor = staffManager();
    $file = importFile([['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['A1', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => 'assignment_batch', 'template_id' => ImportTemplate::query()->where('kind', 'assignment_batch')->value('id')], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    Queue::fake();
    $this->artisan('imports:dispatch')->assertSuccessful();
    Queue::assertPushed(ProcessImport::class, 1);
    $event = OutboxEvent::query()->where('aggregate_id', $batch->id)->firstOrFail();
    expect($event->dispatched_at)->not->toBeNull();
    (new ProcessImport($event->id))->handle(app(ImportWorkflow::class));
    (new ProcessImport($event->id))->handle(app(ImportWorkflow::class));
    expect(ProcessedEvent::query()->where('event_key', $event->event_key)->count())->toBe(1)->and($batch->refresh()->status)->toBe('mapping');
});

test('cancellation reconciles outstanding rows and redacted errors never expose sensitive source data', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Sensitive Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->cancel($actor, $batch);
    app(ImportWorkflow::class)->process($batch->id);
    expect($batch->refresh()->status)->toBe('cancelled')->and($batch->failed_count)->toBe(1);
    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->call('downloadErrors')->assertFileDownloaded('import-errors-'.$batch->public_id.'.csv');
    $this->assertDatabaseCount('assignment_batches', 0);
    expect(AuditLog::query()->where('module', 'M04')->get()->toJson())->not->toContain('Sensitive Person', '000123');
});

test('import register and review render without exposing raw staged payloads', function (): void {
    $actor = staffManager();
    $batch = readyImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000123', 'Sensitive Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    $this->actingAs($actor)->get(route('admin.imports.index'))->assertOk()->assertSee('Excel imports');
    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->set('tab', 'rows')->assertDontSee('000123')->assertDontSee('Sensitive Person');
});

test('duplicate upload returns the existing review and failed frozen imports permit a separate fresh review', function (): void {
    $actor = staffManager();
    $file = importFile([['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['A1', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    $input = ['kind' => 'assignment_batch', 'template_id' => ImportTemplate::query()->where('kind', 'assignment_batch')->value('id')];
    try {
        $one = app(ImportWorkflow::class)->upload($actor, $input, $file);
        $two = app(ImportWorkflow::class)->upload($actor, $input, $file);
        expect($two->id)->toBe($one->id);
        $this->assertDatabaseCount('documents', 1);
        app(ImportWorkflow::class)->scan($one->id);
        app(ImportWorkflow::class)->map($actor, $one, 'Intake', 1);
        app(ImportWorkflow::class)->preview($actor, $one, mappedSelections($one));
        app(ImportWorkflow::class)->confirm($actor, $one, $one->refresh()->mapping_digest);
        app(ImportWorkflow::class)->interrupted($one->id);
        $fresh = app(ImportWorkflow::class)->upload($actor, $input, $file);
        expect($fresh->id)->not->toBe($one->id)->and($fresh->status)->toBe('uploaded')->and($one->refresh()->confirmed_at)->not->toBeNull();
    } finally {
        @unlink($file->getRealPath());
    }
});

test('database blocks mismatched import target links and immutable document source mutation', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['A1', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('id', $batch->source_document_id)->update(['sha256' => str_repeat('0', 64)])))->toThrow(QueryException::class);
    $task = Task::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('import_rows')->where('import_batch_id', $batch->id)->update(['target_task_id' => $task->id])))->toThrow(QueryException::class);
});

test('unsafe archive external relationships are rejected before workbook parsing', function (): void {
    $file = importFile([['name'], ['Synthetic']]);
    try {
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $zip->addFromString('xl/externalLinks/_rels/externalLink1.xml.rels', '<Relationship TargetMode="External" Target="https://example.test"/>');
        $zip->close();
        expect(fn () => app(WorkbookReader::class)->inspect($file->getRealPath()))->toThrow(RuntimeException::class);
    } finally {
        @unlink($file->getRealPath());
    }
});

test('archived parent batches block import retries until explicitly reopened', function (): void {
    $actor = staffManager();
    $parent = AssignmentBatch::factory()->create();
    $job = ImportBatch::factory()->create(['assignment_batch_id' => $parent->id, 'status' => 'failed']);
    $parent->status = BatchStatus::Archived;
    $parent->save();
    expect(fn () => app(ImportWorkflow::class)->retry($actor, $job))->toThrow(ValidationException::class);
    expect($job->refresh()->status)->toBe('failed');
});

test('queue publication failures keep the outbox pending and return an actionable command failure', function (): void {
    ImportBatch::factory()->create();
    $event = new OutboxEvent;
    $event->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'import.scan', 'aggregate_type' => 'import', 'aggregate_id' => ImportBatch::query()->value('id'), 'payload' => ['phase' => 'scan'], 'available_at' => now()])->save();
    $manager = Mockery::mock(QueueManager::class);
    $manager->shouldReceive('connection')->andThrow(new RuntimeException('Synthetic queue failure'));
    app()->instance('queue', $manager);
    $this->artisan('imports:dispatch')->assertFailed();
    expect($event->refresh()->dispatched_at)->toBeNull()->and($event->attempts)->toBe(1)->and($event->last_error)->not->toContain('Synthetic');
});

test('task workbooks create import-origin tasks under the selected assignment after review', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    $party = AccountParty::factory()->create();
    $reference = $party->customer->customer_reference;
    $accountNumber = $party->account->account_number;
    $template = ImportTemplate::query()->where('kind', 'task')->firstOrFail();
    $file = importFile(withBalance([['task_id', 'account_number', 'customer_reference', 'priority_code', 'instructions'], ['FG001', $accountNumber, $reference, 'high', 'Call first'], ['FG002', 'MISSING', 'NO-SUCH-CUSTOMER', '', '']]));
    $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => 'task', 'template_id' => $template->id, 'assignment_batch_id' => $assignment->id], $file);
    @unlink($file->getRealPath());
    app(ImportWorkflow::class)->scan($batch->id);
    app(ImportWorkflow::class)->map($actor, $batch->refresh(), 'Intake', 1);
    app(ImportWorkflow::class)->preview($actor, $batch->refresh(), mappedSelections($batch->refresh()));
    $batch->refresh();
    expect($batch->accepted_count)->toBe(1)->and($batch->rejected_count)->toBe(1);
    $this->assertDatabaseCount('tasks', 0);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);
    $task = Task::query()->sole();
    $row = $batch->rows()->where('state', 'processed')->sole();
    expect($task->assignment_batch_id)->toBe($assignment->id)
        ->and($task->type_code)->toBe($assignment->type_code)
        ->and($task->origin_code)->toBe('import')
        ->and($task->source_import_row_id)->toBe($row->id)
        ->and($task->source_task_id)->toBe('FG001')
        ->and($task->source_task_number)->toBe(1)
        ->and($task->priority_code)->toBe('high')
        ->and($task->work_status)->toBe('unstarted')
        ->and($row->target_task_id)->toBe($task->id);
});

test('task rows are rejected when the account already has an open task in the assignment', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    $party = AccountParty::factory()->create();
    Task::factory()->create(['assignment_batch_id' => $assignment->id, 'debt_account_id' => $party->debt_account_id, 'customer_id' => $party->debtor_id]);
    $batch = new ImportBatch;
    $batch->forceFill(['kind' => 'task', 'assignment_batch_id' => $assignment->id]);
    expect(fn () => app(ImportTargets::class)->prepare($actor, $batch, ['task_id' => 'FG900', 'outstanding_balance' => '100.00', 'account_number' => $party->account->account_number, 'customer_reference' => $party->customer->customer_reference]))
        ->toThrow(ValidationException::class);
});

test('upload task page lists active assignments and fills type and date from the selection', function (): void {
    $active = AssignmentBatch::factory()->create(['name' => 'Selectable assignment', 'start_date' => '2026-11-02', 'due_date' => '2026-11-03']);
    AssignmentBatch::factory()->create(['name' => 'Hidden assignment', 'status' => BatchStatus::Archived, 'archived_at' => now()]);
    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSee($active->id.' - Selectable assignment')
        ->assertDontSee('Hidden assignment')
        ->assertDontSee('Target contract')
        ->set('data.assignment_batch_id', (string) $active->id)
        ->assertSee('02 Nov 2026 - 03 Nov 2026')
        ->call('uploadWorkbook')
        ->assertHasErrors(['file']);
});

test('upload task history filters by the uploaded date calendar', function (): void {
    $actor = staffManager();
    $batch = stagedImport($actor, [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['A1', 'Synthetic Batch', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    $today = $batch->created_at->timezone('Asia/Kuala_Lumpur')->toDateString();

    Livewire::actingAs($actor)->test(Index::class)
        ->assertSee('Uploaded Date')
        ->assertDontSee('Uploaded from')
        ->assertDontSee('Assignment batch')
        ->assertSee('Assignment')
        ->set('from', '2000-01-01')->set('until', '2000-01-02')
        ->assertSee('No imports yet')
        ->set('from', $today)->set('until', $today)
        ->assertDontSee('No imports yet');
});

test('the upload page sends a workbook through to review', function (): void {
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $assignment = AssignmentBatch::factory()->create();
    $path = importFile(withBalance([['task_id', 'account_number', 'customer_reference', 'customer_name'], ['FG001', 'A1', 'C1', 'Synthetic Person']]))->getRealPath();
    $file = UploadedFile::fake()->createWithContent('tasks.xlsx', file_get_contents($path));
    @unlink($path);

    Livewire::actingAs(staffManager())->test(Index::class)
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->set('file', $file)
        ->call('uploadWorkbook')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.imports.review', ImportBatch::query()->sole()));
});

test('no page action uses a name the browser side of Livewire reserves', function (): void {
    $reserved = ['upload', 'uploadMultiple', 'removeUpload', 'cancelUpload', 'set', 'get', 'call', 'commit', 'watch', 'refresh', 'on', 'hook', 'dispatch', 'entangle', 'intercept'];
    $actions = collect(File::allFiles(app_path('Livewire')))
        ->flatMap(fn ($file) => preg_match_all('/public function (\w+)\(/', $file->getContents(), $matches) ? $matches[1] : []);

    expect($actions->intersect($reserved)->values()->all())->toBe([]);
});

function uploadToPage(User $actor, array $rows, string $name = 'tasks.xlsx')
{
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $path = importFile($rows)->getRealPath();
    $file = UploadedFile::fake()->createWithContent($name, file_get_contents($path));
    @unlink($path);

    return Livewire::actingAs($actor)->test(Index::class)
        ->set('data.assignment_batch_id', (string) AssignmentBatch::factory()->create()->id)
        ->set('file', $file)
        ->call('uploadWorkbook');
}

test('a workbook without the required columns fails straight away with a message and stays out of history', function (): void {
    $actor = staffManager();
    $page = uploadToPage($actor, [['task_id', 'Colour'], ['FG001', 'Blue']], 'wrong-columns.xlsx');

    $page->assertHasErrors(['file'])->assertNoRedirect()->assertSee('Missing required columns: account_number, customer_reference')->assertDontSee('wrong-columns.xlsx');
    expect(ImportBatch::query()->sole()->status)->toBe('failed');
    $this->assertDatabaseCount('tasks', 0);
});

test('a good workbook is scanned and mapped straight away and shows a success message', function (): void {
    $page = uploadToPage(staffManager(), withBalance([['task_id', 'account_number', 'customer_reference'], ['FG001', 'A1', 'C1']]));

    $batch = ImportBatch::query()->sole();
    $page->assertHasNoErrors()->assertRedirect(route('admin.imports.review', $batch));
    expect($batch->status)->toBe('mapping')->and($batch->mappings()->count())->toBe(4)->and(session('status'))->toContain('Excel file uploaded');
});

test('a failed or cancelled workbook can be uploaded again', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $file = importFile([['task_id', 'account_number', 'customer_reference'], ['FG001', 'A1', 'C1']]);
    $input = ['kind' => 'task', 'template_id' => ImportTemplate::query()->where('kind', 'task')->value('id'), 'assignment_batch_id' => $assignment->id];
    try {
        $first = app(ImportWorkflow::class)->upload($actor, $input, $file);
        app(ImportWorkflow::class)->cancel($actor, $first);
        $second = app(ImportWorkflow::class)->upload($actor, $input, $file);
    } finally {
        @unlink($file->getRealPath());
    }

    expect($second->id)->not->toBe($first->id)->and($first->refresh()->status)->toBe('cancelled');
});

test('import history searches file names and only lists successful uploads', function (): void {
    $actor = staffManager();
    $assignment = AssignmentBatch::factory()->create();
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $input = ['kind' => 'task', 'template_id' => ImportTemplate::query()->where('kind', 'task')->value('id'), 'assignment_batch_id' => $assignment->id];
    foreach (['january_tasks.xlsx' => 'J1', 'february-tasks.xlsx' => 'F1'] as $name => $id) {
        $path = importFile([['task_id', 'account_number', 'customer_reference'], [$id, $id, $id]])->getRealPath();
        app(ImportWorkflow::class)->upload($actor, $input, UploadedFile::fake()->createWithContent($name, file_get_contents($path)));
        @unlink($path);
    }

    Livewire::actingAs($actor)->test(Index::class)
        ->assertSee('january_tasks.xlsx')->assertSee('february-tasks.xlsx')
        ->assertDontSee('Filter import type')->assertDontSee('Filter state')->assertDontSee('Contract')
        ->assertSeeInOrder(['Note:', 'Required columns', 'Optional columns'])
        ->set('search', 'JANUARY')
        ->assertSee('january_tasks.xlsx')->assertDontSee('february-tasks.xlsx')
        ->set('search', '%')
        ->assertDontSee('january_tasks.xlsx');
});

test('committing on the sync queue creates the tasks and reports how many', function (): void {
    $actor = staffManager();
    uploadToPage($actor, withBalance([['task_id', 'account_number', 'customer_reference', 'customer_name'], ['FG001', 'A1', 'C1', 'Synthetic Person']]));
    $batch = ImportBatch::query()->sole();
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));

    Livewire::actingAs($actor)->test(Review::class, ['import' => $batch->refresh()])->call('confirm')->assertHasNoErrors()->assertSee('1 task created.');

    expect($batch->refresh()->status)->toBe('completed');
    $this->assertDatabaseCount('tasks', 1);
});

test('a repeated new customer only needs its name on the first row', function (): void {
    $actor = staffManager();
    $batch = scannedTaskImport($actor, [
        ['task_id', 'account_number', 'customer_reference', 'customer_name'],
        ['FG001', 'NEW-A', 'REF-NEW', 'Synthetic First'],
        ['FG002', 'NEW-B', 'REF-NEW', ''],
        ['FG003', 'NEW-C', 'REF-OTHER', ''],
    ]);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    expect($batch->refresh()->accepted_count)->toBe(2)->and($batch->rejected_count)->toBe(1);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);

    expect($batch->refresh()->processed_count)->toBe(2)
        ->and(Debtor::query()->where('customer_reference', 'REF-NEW')->sole()->display_name_ciphertext)->toBe('Synthetic First');
    $this->assertDatabaseCount('tasks', 2);
});

test('the template matches the published task contract and has no rows that could become tasks', function (): void {
    $path = app(TaskUploadTemplate::class)->write();
    try {
        $info = app(WorkbookReader::class)->inspect($path);
        $headers = app(WorkbookReader::class)->read($path, 'Tasks', 1)[1];
    } finally {
        @unlink($path);
    }
    $template = ImportTemplate::query()->where('kind', 'task')->firstOrFail();
    $fields = ImportField::query()->whereIn('id', $template->schema_definition['field_ids'])->pluck('canonical_field');

    expect(collect($info)->pluck('worksheetName')->all())->toBe(['Tasks', 'Guide'])
        ->and($info[0]['totalRows'])->toBe(1)
        ->and(array_values($headers))->toEqualCanonicalizing($fields->all())
        ->and(collect(TaskUploadTemplate::COLUMNS)->filter(fn (array $column): bool => $column['required'])->keys()->all())->toEqualCanonicalizing($template->schema_definition['required']);
});

test('a filled-in template uploads and creates tasks with its leading zeros intact', function (): void {
    $actor = staffManager();
    $path = app(TaskUploadTemplate::class)->write();
    $book = IOFactory::load($path);
    $book->setActiveSheetIndexByName('Tasks');
    $book->getActiveSheet()->fromArray(['FG001', '000123', 'C-1', 'Synthetic Person', '0123456789', 'person@example.test', '1,234.50', '45', '2026-11-01', '1 Road', null, '00501', 'Kuala Lumpur', 'high', 'Call first'], null, 'A2');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent(TaskUploadTemplate::FILE_NAME, file_get_contents($path));
    @unlink($path);

    Livewire::actingAs($actor)->test(Index::class)
        ->set('data.assignment_batch_id', (string) AssignmentBatch::factory()->create()->id)
        ->set('file', $file)
        ->call('uploadWorkbook')
        ->assertHasNoErrors();
    $batch = ImportBatch::query()->sole();
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    app(ImportWorkflow::class)->confirm($actor, $batch->refresh(), $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);

    $task = Task::query()->sole();
    expect($task->source_task_id)->toBe('FG001')
        ->and($task->account->account_number)->toBe('000123')
        ->and($task->account->property->postcode)->toBe('00501')
        ->and($task->account->property->state_code)->toBe('kuala_lumpur')
        ->and($task->priority_code)->toBe('high')
        ->and($task->latestBalance->outstandingCents())->toBe(123450)
        ->and($task->latestBalance->monthlyCents())->toBe(4500)
        ->and($task->latestBalance->balance_date->toDateString())->toBe('2026-11-01')
        ->and($task->latestBalance->source_code)->toBe('import')
        ->and($task->customer->contacts()->pluck('kind_code')->sort()->values()->all())->toBe(['email', 'mobile']);
    expect(DB::table('financial_snapshots')->value('outstanding_balance_ciphertext'))->not->toContain('1234');
});

test('admins who can upload can download the template and others cannot', function (): void {
    Livewire::actingAs(staffManager())->test(Index::class)
        ->assertSee('Download Excel template')
        ->call('downloadTemplate')
        ->assertFileDownloaded(TaskUploadTemplate::FILE_NAME);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['admin.access', 'imports.view']);
    Livewire::actingAs($viewer)->test(Index::class)->assertDontSee('Download Excel template')->call('downloadTemplate')->assertForbidden();
});

test('upload task requires an assignment', function (): void {
    Livewire::actingAs(staffManager())->test(Index::class)->call('uploadWorkbook')->assertHasErrors(['data.assignment_batch_id', 'file']);
});

test('workbooks can use encrypted local storage and skip the scan when it is not required', function (): void {
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(MalwareScanner::class, $scanner);
    $batch = stagedImport(staffManager(), [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['A1', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    expect($batch->source->disk)->toBe('local')
        ->and($batch->source->scan_status)->toBe('skipped')
        ->and(Storage::disk('local')->get($batch->source->object_key))->not->toContain('Synthetic');
});

test('production always scans workbooks even when the scan is configured as optional', function (): void {
    config(['imports.disk' => 'local', 'imports.scan_required' => false]);
    Storage::fake('local');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andReturnNull();
    app()->instance(MalwareScanner::class, $scanner);
    app()->detectEnvironment(fn (): string => 'production');
    $batch = stagedImport(staffManager(), [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['A1', 'Synthetic Person', 'domestic_dr', '2026-10-01', '2026-10-31']]);
    expect($batch->source->scan_status)->toBe('clean');
});

function scannedTaskImport(User $actor, array $rows): ImportBatch
{
    $file = importFile(withBalance($rows));
    try {
        $batch = app(ImportWorkflow::class)->upload($actor, ['kind' => 'task', 'template_id' => ImportTemplate::query()->where('kind', 'task')->firstOrFail()->id, 'assignment_batch_id' => AssignmentBatch::factory()->create()->id], $file);
    } finally {
        @unlink($file->getRealPath());
    }
    app(ImportWorkflow::class)->scan($batch->id);

    return $batch->refresh();
}

function taskFieldId(string $name): int
{
    return ImportField::query()->where('module', 'task')->where('canonical_field', $name)->value('id');
}

test('the scan detects mappings from normalized exact headers and aliases without calling any external service', function (): void {
    Http::fake();
    $batch = scannedTaskImport(staffManager(), [['ACCOUNT-NO', ' customer_ref ', 'Priority_Code', 'special   instructions', 'Mystery Column'], ['A1', 'C1', 'high', 'Note', 'x']]);
    $mappings = $batch->mappings()->orderBy('source_column_index')->get();
    expect($batch->status)->toBe('mapping')
        ->and($mappings->pluck('field_id')->all())->toBe([taskFieldId('account_number'), taskFieldId('customer_reference'), taskFieldId('priority_code'), taskFieldId('instructions'), null, taskFieldId('outstanding_balance')])
        ->and($mappings->pluck('match_method')->all())->toBe(['alias', 'alias', 'canonical', 'alias', 'unmatched', 'canonical']);
    Http::assertNothingSent();
});

test('exact headers outrank aliases and aliases competing for a field are left for review', function (): void {
    $batch = scannedTaskImport(staffManager(), [['Acc No', 'account_number', 'Customer Ref', 'Cust No'], ['A1', 'A2', 'C1', 'C2']]);
    $mappings = $batch->mappings()->orderBy('source_column_index')->get();
    expect($mappings->pluck('field_id')->all())->toBe([null, taskFieldId('account_number'), null, null, taskFieldId('outstanding_balance')])
        ->and($mappings->pluck('match_method')->all())->toBe(['unmatched', 'canonical', 'unmatched', 'unmatched', 'canonical'])
        ->and($mappings[0]->matched_rule_snapshot['ambiguous'])->toBeTrue()
        ->and($mappings[2]->matched_rule_snapshot['ambiguous'])->toBeTrue();
});

test('no alias suggests two fields within one import type', function (): void {
    foreach (config('imports.aliases') as $fields) {
        $aliases = collect($fields)->flatten()->map(fn (string $alias): string => ImportWorkflow::normalize($alias));
        expect($aliases->duplicates())->toBeEmpty();
    }
});

test('admins can map an unmatched column manually and the review screen shows detected mappings', function (): void {
    $actor = staffManager();
    $party = AccountParty::factory()->create();
    $batch = scannedTaskImport($actor, [['task_id', 'account_number', 'Kod Khas'], ['FG001', $party->account->account_number, $party->customer->customer_reference]]);
    $component = Livewire::actingAs($actor)->test(Review::class, ['import' => $batch])->assertSet('tab', 'mapping')->assertSee('Exact name')->assertSee('Choose a field');
    $unmatched = $batch->mappings()->where('match_method', 'unmatched')->sole();
    $component->set("selections.{$unmatched->id}.field_id", (string) taskFieldId('customer_reference'))->call('preview')->assertHasNoErrors();
    expect($unmatched->refresh()->match_method)->toBe('manual')->and($batch->refresh()->accepted_count)->toBe(1);
});

test('preview rejects missing required values unknown records invalid values and duplicates with specific codes', function (): void {
    $actor = staffManager();
    $party = AccountParty::factory()->create();
    $account = $party->account->account_number;
    $customer = $party->customer->customer_reference;
    $linked = AccountParty::factory()->create();
    $batch = scannedTaskImport($actor, [
        ['task_id', 'account_number', 'customer_reference', 'priority_code'],
        ['FG001', $account, $customer, 'HIGH'],
        ['FG002', $account, $customer, 'high'],
        ['FG003', '', $customer, ''],
        ['FG004', 'NEW-ACCOUNT', 'NEW-CUSTOMER', ''],
        ['FG005', AccountParty::factory()->create()->account->account_number, 'NO-SUCH-CUSTOMER', ''],
        ['FG006', $linked->account->account_number, $linked->customer->customer_reference, 'very urgent'],
    ]);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    $rows = $batch->rows()->orderBy('row_number')->get();
    expect($rows->pluck('state')->all())->toBe(['accepted', 'duplicate', 'rejected', 'rejected', 'rejected', 'rejected'])
        ->and($rows[2]->validation_errors)->toEqual([['field' => 'account_number', 'code' => 'required']])
        ->and($rows[3]->validation_errors)->toEqual([['field' => 'customer_name', 'code' => 'required']])
        ->and($rows[4]->validation_errors)->toEqual([['field' => 'customer_name', 'code' => 'required']])
        ->and($rows[5]->validation_errors)->toEqual([['field' => 'data.priority_code', 'code' => 'invalid_value']]);
    $this->assertDatabaseCount('tasks', 0);
});

test('task rows create new customers and accounts on commit but nothing during preview', function (): void {
    $actor = staffManager();
    $batch = scannedTaskImport($actor, [
        ['task_id', 'account_number', 'customer_reference', 'customer_name', 'address', 'postcode', 'state_code'],
        ['FG001', 'NEW-ACC-1', 'NEW-REF-1', 'Synthetic First', '1 Synthetic Road', '10000', 'Kuala Lumpur'],
        ['FG002', 'NEW-ACC-2', 'NEW-REF-1', 'Synthetic First', '2 Synthetic Road', '', ''],
    ]);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    $batch->refresh();
    expect($batch->accepted_count)->toBe(2);
    $this->assertDatabaseCount('debtors', 0);
    $this->assertDatabaseCount('debt_accounts', 0);
    app(ImportWorkflow::class)->confirm($actor, $batch, $batch->mapping_digest);
    app(ImportWorkflow::class)->process($batch->id);
    expect($batch->refresh()->status)->toBe('completed')->and($batch->processed_count)->toBe(2);
    $customer = Debtor::query()->sole();
    $account = DebtAccount::query()->where('account_number', 'NEW-ACC-1')->sole();
    expect($customer->customer_reference)->toBe('NEW-REF-1')
        ->and($customer->display_name_ciphertext)->toBe('Synthetic First')
        ->and($account->property->address_lines_ciphertext)->toBe('1 Synthetic Road')
        ->and($account->property->state_code)->toBe('kuala_lumpur');
    $this->assertDatabaseCount('tasks', 2);
});

test('task rows need a numbered id that is unique within the assignment and workbook', function (): void {
    $actor = staffManager();
    $party = AccountParty::factory()->create();
    $second = AccountParty::factory()->create();
    $third = AccountParty::factory()->create();
    $batch = scannedTaskImport($actor, [
        ['task_id', 'account_number', 'customer_reference'],
        ['FG001', $party->account->account_number, $party->customer->customer_reference],
        ['DT1', $second->account->account_number, $second->customer->customer_reference],
        ['NODIGITS', $third->account->account_number, $third->customer->customer_reference],
    ]);
    app(ImportWorkflow::class)->preview($actor, $batch, mappedSelections($batch));
    $rows = $batch->rows()->orderBy('row_number')->get();
    expect($rows->pluck('state')->all())->toBe(['accepted', 'duplicate', 'rejected'])
        ->and($rows[2]->validation_errors[0]['code'])->toBe('invalid_task_id');
    Task::factory()->create(['assignment_batch_id' => $batch->assignment_batch_id, 'source_task_id' => 'FG001', 'source_task_number' => 1]);
    expect(fn () => app(ImportTargets::class)->prepare($actor, $batch, ['task_id' => 'XX001', 'outstanding_balance' => '100.00', 'account_number' => $third->account->account_number, 'customer_reference' => $third->customer->customer_reference]))
        ->toThrow(ValidationException::class);
});

test('the customer import type is gone', function (): void {
    expect(ImportTemplate::query()->where('kind', 'customer')->exists())->toBeFalse()
        ->and(ImportField::query()->where('module', 'customer')->exists())->toBeFalse();
    $file = importFile([['customer_reference'], ['A1']]);
    try {
        expect(fn () => app(ImportWorkflow::class)->upload(staffManager(), ['kind' => 'customer', 'template_id' => 1], $file))->toThrow(ValidationException::class);
    } finally {
        @unlink($file->getRealPath());
    }
});

test('date fields reject values that are not real calendar dates', function (): void {
    $batch = readyImport(staffManager(), [['external_reference', 'name', 'type_code', 'start_date', 'due_date'], ['000001', 'Synthetic Batch', 'domestic_dr', '2026-02-31', '2026-10-31']], 'assignment_batch');
    expect($batch->rows()->sole()->validation_errors)->toEqual([['field' => 'start_date', 'code' => 'invalid_date']]);
});
