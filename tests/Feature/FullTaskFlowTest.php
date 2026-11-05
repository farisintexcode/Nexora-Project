<?php

use App\Actions\Reports\ManageReports;
use App\Actions\Tasks\ReviewSubmission;
use App\Actions\Tasks\RiderWork;
use App\Actions\Tasks\TaskProof;
use App\Livewire\Admin\Assignments\Editor as AssignmentEditor;
use App\Livewire\Admin\Imports\Index as UploadTask;
use App\Livewire\Admin\Imports\Review;
use App\Livewire\Admin\Tasks\Allocation;
use App\Models\AssignmentBatch;
use App\Models\ImportBatch;
use App\Models\ImportField;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CompletionChecklist;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    config([
        'dcms.search_key' => base64_encode(str_repeat('t', 32)),
        'imports.disk' => 'local', 'imports.scan_required' => false,
        'task_execution.disk' => 'local', 'task_execution.scan_required' => false,
    ]);
    $this->seed(DatabaseSeeder::class);
});

function flowWorkbook(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->setTitle('Tasks');
    $book->getActiveSheet()->fromArray($rows);
    $path = tempnam(sys_get_temp_dir(), 'flow-');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    $file = UploadedFile::fake()->createWithContent('flow.xlsx', file_get_contents($path));
    @unlink($path);

    return $file;
}

function flowAssignment(User $admin): AssignmentBatch
{
    Livewire::actingAs($admin)->test(AssignmentEditor::class)
        ->set('data.name', 'Flow assignment')
        ->set('data.type_code', 'domestic_dr')
        ->set('data.start_date', now()->timezone(config('dcms.business_timezone'))->toDateString())
        ->set('data.due_date', now()->timezone(config('dcms.business_timezone'))->addDays(7)->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    return AssignmentBatch::query()->sole();
}

test('one task travels from add assignment through upload, assign, rider work and verification to the report', function (): void {
    $admin = staffManager();
    $reviewer = staffManager();
    $staff = Staff::factory()->create(['is_rider' => true, 'first_name' => 'Abdul', 'last_name' => '']);
    $rider = User::factory()->for($staff)->create();
    $rider->assignRole('Rider');

    $assignment = flowAssignment($admin);

    Livewire::actingAs($admin)->test(UploadTask::class)
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->set('file', flowWorkbook([
            ['task_id', 'Account No', 'customer_reference', 'customer_name', 'Vehicle No', 'outstanding_balance', 'priority_code'],
            ['FG101', '8800001001', 'CUST-1001', 'Synthetic Customer', 'TEST 7001', '500.00', 'high'],
        ]))
        ->call('uploadWorkbook')
        ->assertHasNoErrors()
        ->assertRedirect();
    $import = ImportBatch::query()->sole();
    expect($import->status)->toBe('mapping')->and(Task::query()->count())->toBe(0);

    session()->flush();
    $review = Livewire::actingAs($admin)->test(Review::class, ['import' => $import])
        ->assertSee('Exact name')->assertSee('Known alias')->assertSee('Choose a field');
    $vehicle = $import->mappings()->where('original_header', 'Vehicle No')->sole();
    $review->set("selections.{$vehicle->id}.field_id", 'ignore')->call('preview')->assertHasNoErrors()->assertSet('tab', 'rows');
    expect($import->refresh()->accepted_count)->toBe(1)->and(Task::query()->count())->toBe(0);
    $review->call('confirm')->assertHasNoErrors()->assertSee('1 task created.');

    $task = Task::query()->sole();
    expect($import->refresh()->status)->toBe('completed')
        ->and($task->source_task_id)->toBe('FG101')
        ->and($task->work_status)->toBe('unstarted')
        ->and($task->priority_code)->toBe('high')
        ->and($task->latestBalance->outstandingCents())->toBe(50000)
        ->and(ImportField::query()->where('canonical_field', 'like', '%vehicle%')->exists())->toBeFalse();

    session()->flush();
    Livewire::actingAs($admin)->test(Allocation::class)
        ->set('batchId', (string) $assignment->id)->set('importId', $import->public_id)->set('mode', 'single')->set('taskIds', '101')
        ->set('riderId', (string) $staff->id)->set('reason', 'Synthetic flow allocation')
        ->call('assign')->assertHasNoErrors()->assertRedirect(route('admin.tasks.index'));
    expect(session('status'))->toBe('1 task assigned to Abdul.')
        ->and($task->refresh()->currentAllocation->collector_id)->toBe($staff->id);

    app(RiderWork::class)->start($rider, $task, $task->lock_version);
    $input = ['purpose' => 'site_visit', 'business_applicable' => false, 'signboard_available' => '', 'occurred_at' => '', 'site_address' => '10 Synthetic site', 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'payment_claimed', 'paid_amount' => '500.00', 'visit_remarks' => 'Synthetic visit', 'collection_remarks' => 'Synthetic collection', 'customer_remarks' => 'Synthetic customer'];
    app(RiderWork::class)->visit($rider, $task, $task->refresh()->lock_version, $input, (string) Str::uuid7());

    $proof = app(TaskProof::class);
    $proof->upload($rider, $task, $task->refresh()->lock_version, UploadedFile::fake()->image('site.png', 80, 60), 'premises', 'Site');
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues']['proof'])->toContain('Payment Evidence');
    $proof->upload($rider, $task, $task->refresh()->lock_version, UploadedFile::fake()->image('paid.png', 80, 60), 'payment_evidence', 'Paid');

    $check = app(CompletionChecklist::class)->review($task->refresh(), $rider);
    expect($check['issues'])->toBe([]);
    $submission = app(RiderWork::class)->submit($rider, $task, $task->lock_version, (string) Str::uuid7(), $check['digest']);
    expect($task->refresh()->work_status)->toBe('submitted')->and($task->review_status)->toBe('pending');

    $reviews = app(ReviewSubmission::class);
    $reviews->confirm($reviewer, $reviews->prepare($reviewer, [$submission->public_id], 'approved', null, [$submission->public_id => ['photo' => true, 'partial' => false]]));
    expect($task->refresh()->work_status)->toBe('completed')->and($task->review_status)->toBe('approved')
        ->and($task->latestBalance()->first()->outstandingCents())->toBe(0);

    $criteria = ['family' => 'verification', 'from' => '', 'until' => '', 'batch' => $assignment->public_id, 'rider' => '', 'status' => 'completed', 'task_id' => '', 'columns' => ['task_id', 'work_status', 'review_status', 'collected']];
    $csv = app(ManageReports::class)->download($reviewer, $criteria, 'csv');
    $pdf = app(ManageReports::class)->download($reviewer, $criteria, 'pdf');
    expect($csv['bytes'])->toContain('FG101')->toContain('completed')->toContain('approved')->toContain('500.00')
        ->and($pdf['bytes'])->toStartWith('%PDF-');
});

test('an upload fails with a clear reason when the required scanner is not reachable', function (): void {
    config(['imports.scan_required' => true, 'imports.scanner_host' => '127.0.0.1', 'imports.scanner_port' => 1]);
    $admin = staffManager();
    $assignment = AssignmentBatch::factory()->create();

    Livewire::actingAs($admin)->test(UploadTask::class)
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->set('file', flowWorkbook([['task_id', 'account_number', 'customer_reference', 'outstanding_balance'], ['FG1', 'A1', 'C1', '10.00']]))
        ->call('uploadWorkbook')
        ->assertHasErrors(['file'])
        ->assertNoRedirect()
        ->assertSee('malware scanner is not reachable')
        ->assertSee('IMPORT_SCAN_REQUIRED');

    expect(ImportBatch::query()->sole()->status)->toBe('failed')->and(Task::query()->count())->toBe(0);
});

test('an upload stops early with a clear reason when the search key is missing', function (): void {
    config(['dcms.search_key' => null]);
    $admin = staffManager();
    $assignment = AssignmentBatch::factory()->create();

    Livewire::actingAs($admin)->test(UploadTask::class)
        ->set('data.assignment_batch_id', (string) $assignment->id)
        ->set('file', flowWorkbook([['task_id', 'account_number', 'customer_reference', 'outstanding_balance'], ['FG1', 'A1', 'C1', '10.00']]))
        ->call('uploadWorkbook')
        ->assertHasErrors(['file'])
        ->assertSee('DCMS_SEARCH_KEY');

    expect(ImportBatch::query()->count())->toBe(0);
});
