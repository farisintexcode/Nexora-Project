<?php

use App\Actions\Tasks\ReviewSubmission;
use App\Actions\Tasks\RiderWork;
use App\Actions\Tasks\TaskProof;
use App\Enums\BatchStatus;
use App\Jobs\ScanTaskProof;
use App\Livewire\Rider\Tasks\Show;
use App\Models\AuditLog;
use App\Models\CollectionActivity;
use App\Models\Document;
use App\Models\OutboxEvent;
use App\Models\ReferenceValue;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\TaskSubmission;
use App\Models\TaskSubmissionDocument;
use App\Models\User;
use App\Services\Imports\MalwareScanner;
use App\Services\Tasks\CompletionChecklist;
use App\Services\Tasks\RiderAssignments;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function executionFixture(): array
{
    $task = Task::factory()->create();
    $rider = User::factory()->for(Staff::factory()->create())->create();
    $rider->assignRole('Rider');
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->staff_id]);

    return [$rider, $task->refresh()];
}
function executionInput(array $changes = []): array
{
    return $changes + ['purpose' => 'site_visit', 'business_applicable' => false, 'signboard_available' => '', 'occurred_at' => '', 'site_address' => '10 Synthetic site', 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'successful_visit', 'visit_remarks' => 'Synthetic inspection performed', 'collection_remarks' => 'No receipt recorded', 'customer_remarks' => 'Synthetic customer response'];
}
function executionVisit(User $rider, Task $task, array $changes = []): CollectionActivity
{
    app(RiderWork::class)->start($rider, $task, $task->refresh()->lock_version);

    return app(RiderWork::class)->visit($rider, $task, $task->refresh()->lock_version, executionInput($changes), (string) Str::uuid7());
}
function executionProof(User $rider, Task $task, string $category = 'premises'): Document
{
    config(['filesystems.disks.s3.bucket' => 'synthetic-proof']);
    $proof = app(TaskProof::class)->upload($rider, $task, $task->refresh()->lock_version, UploadedFile::fake()->image('field.png', 80, 60), $category, 'Synthetic proof caption');

    return $proof;
}
function cleanExecutionProof(Document $document): void
{
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->with(Mockery::on(fn ($path) => is_file($path) && filesize($path) > 0));
    app(TaskProof::class)->scan($document->id, $scanner);
}
function executionSubmit(User $rider, Task $task, ?string $key = null): TaskSubmission
{
    $review = app(CompletionChecklist::class)->review($task->refresh(), $rider);

    return app(RiderWork::class)->submit($rider, $task, $task->lock_version, $key ?? (string) Str::uuid7(), $review['digest']);
}

test('Rider routes enforce active interface and current allocation without exposing identifiers', function (): void {
    [$rider,$task] = executionFixture();
    $task->customer->identity_ciphertext = 'PRIVATE-IDENTIFIER';
    $task->customer->save();
    $this->get(route('rider.tasks.index'))->assertRedirect(route('rider.login'));
    $this->actingAs($rider)->get(route('rider.tasks.index'))->assertOk()->assertSee('My Tasks')->assertDontSee('PRIVATE-IDENTIFIER');
    foreach (['overview', 'customer', 'visit', 'business', 'proof', 'completion', 'history'] as $section) {
        $this->get(route('rider.tasks.show', [$task, 'section' => $section]))->assertOk()->assertDontSee('PRIVATE-IDENTIFIER');
    }
    [$other,$otherTask] = executionFixture();
    $this->get(route('rider.tasks.show', $otherTask))->assertNotFound();
    $this->actingAs($other)->get(route('rider.tasks.show', $task))->assertNotFound();
});

test('start is atomic idempotent and rejects stale cancelled archived and withdrawn permission work', function (): void {
    [$rider,$task] = executionFixture();
    expect(Gate::forUser($rider)->allows('execute', $task))->toBeTrue();
    app(RiderWork::class)->start($rider, $task, 0);
    app(RiderWork::class)->start($rider, $task, 0);
    expect($task->refresh()->lock_version)->toBe(1)->and($task->history()->where('event_code', 'started')->count())->toBe(1);
    expect(fn () => app(RiderWork::class)->visit($rider, $task, 0, executionInput(), (string) Str::uuid7()))->toThrow(ValidationException::class);
    $task->batch->status = BatchStatus::Archived;
    $task->batch->save();
    expect(fn () => app(RiderWork::class)->start($rider, $task, 1))->toThrow(ValidationException::class);
    $task->batch->status = BatchStatus::Active;
    $task->batch->save();
    $rider->revokePermissionTo('tasks.submit');
    $rider->roles()->detach();
    expect(Gate::forUser($rider->fresh())->allows('execute', $task))->toBeFalse();
});

test('visit preserves encrypted typed remarks explicit business Other and follow-up context on duplicate retry', function (): void {
    [$rider,$task] = executionFixture();
    app(RiderWork::class)->start($rider, $task, 0);
    $key = (string) Str::uuid7();
    $input = executionInput(['business_applicable' => true, 'business_type' => 'other', 'business_nature' => 'retail', 'business_status' => 'active', 'business_other' => 'Synthetic special business', 'follow_up_due' => now()->timezone(config('dcms.business_timezone'))->addDay()->format('Y-m-d\TH:i'), 'follow_up_remarks' => 'Synthetic reminder']);
    $visit = app(RiderWork::class)->visit($rider, $task, 1, $input, $key);
    $duplicate = app(RiderWork::class)->visit($rider, $task, 1, $input, $key);
    expect($visit->id)->toBe($duplicate->id)->and($task->refresh()->lock_version)->toBe(2)->and($visit->inspection->business_other_ciphertext)->toBe('Synthetic special business')->and($visit->followUps()->count())->toBe(1);
    expect(DB::table('collection_activities')->where('id', $visit->id)->value('visit_remarks_ciphertext'))->not->toContain('Synthetic');
    expect($visit->toJson())->not->toContain('Synthetic inspection');
    expect(fn () => DB::transaction(fn () => DB::table('collection_activities')->where('id', $visit->id)->update(['outcome_code' => 'payment_claimed'])))->toThrow(QueryException::class);
});

test('backdating uses one MYT calendar day requires reason preserves entry time and redacts audit', function (): void {
    $this->travelTo(Carbon::parse('2026-10-16 15:50:00', 'UTC'));
    [$rider,$task] = executionFixture();
    app(RiderWork::class)->start($rider, $task, 0);
    foreach ([
        ['occurred_at' => '2026-10-17T00:00'],
        ['occurred_at' => '2026-10-14T23:59', 'backdating_reason' => 'Old'],
        ['occurred_at' => '2026-10-15T00:01'],
    ] as $changes) {
        expect(fn () => app(RiderWork::class)->visit($rider, $task, 1, executionInput($changes), (string) Str::uuid7()))->toThrow(ValidationException::class);
    }
    $visit = app(RiderWork::class)->visit($rider, $task, 1, executionInput(['occurred_at' => '2026-10-15T00:01', 'backdating_reason' => 'Offline at synthetic site']), (string) Str::uuid7());
    expect($visit->occurred_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-14 16:01')->and($visit->created_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-16 15:50')->and($visit->backdating_reason_ciphertext)->toBe('Offline at synthetic site');
    expect(AuditLog::query()->where('module', 'M06')->get()->toJson())->not->toContain('Offline at synthetic site');
});

test('visit rejects inactive options incomplete business Other and missing callback or promise follow-up', function (): void {
    [$rider,$task] = executionFixture();
    app(RiderWork::class)->start($rider, $task, 0);
    foreach ([
        ['availability' => 'forged'],
        ['purpose' => 'address_verification', 'signboard_available' => ''],
        ['business_applicable' => true],
        ['business_type' => 'other', 'business_nature' => 'retail', 'business_status' => 'active'],
        ['contact_result' => 'call_back_requested'],
        ['outcome' => 'promise_to_pay'],
        ['outcome' => 'follow_up_required'],
        ['visit_remarks' => ''],
    ] as $changes) {
        expect(fn () => app(RiderWork::class)->visit($rider, $task, 1, executionInput($changes), (string) Str::uuid7()))->toThrow(ValidationException::class);
    }
    expect($task->activities()->count())->toBe(0);
});

test('private proof validates raster content links exact visit queues scans and fails closed on outage', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    $visit = executionVisit($rider, $task);
    expect(fn () => app(TaskProof::class)->upload($rider, $task, 2, UploadedFile::fake()->createWithContent('bad.jpg', '<script>unsafe</script>'), 'premises', null))->toThrow(ValidationException::class);
    $document = executionProof($rider, $task);
    expect($document->scan_status)->toBe('pending')->and($document->activity_id)->toBe($visit->id)->and($document->mime_type)->toBe('image/jpeg');
    $bytes = app(TaskProof::class)->bytes($document);
    expect(Storage::disk('s3')->get($document->object_key))->not->toBe($bytes);
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey('proof');
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException('scanner_unavailable'));
    expect(fn () => app(TaskProof::class)->scan($document->id, $scanner))->toThrow(RuntimeException::class);
    expect($document->refresh()->scan_status)->toBe('pending');
    $event = OutboxEvent::query()->where('event_type', 'tasks.proof_scan')->firstOrFail();
    (new ScanTaskProof($event->id))->failed(new RuntimeException('outage'));
    expect($document->refresh()->scan_status)->toBe('error');
    app(TaskProof::class)->retry($rider, $task, $task->refresh()->lock_version, $document->public_id);
    cleanExecutionProof($document);
    expect($document->refresh()->scan_status)->toBe('clean');
    $this->actingAs($rider)->get(route('proof.show', $document))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Type', 'image/jpeg');
});

test('clean proof completion freezes exact snapshot manifest and returns same submission on retry', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    $document = executionProof($rider, $task);
    cleanExecutionProof($document);
    $key = (string) Str::uuid7();
    $submission = executionSubmit($rider, $task, $key);
    $again = app(RiderWork::class)->submit($rider, $task, 0, $key, 'old digest');
    expect($again->id)->toBe($submission->id)->and($task->refresh()->work_status)->toBe('completed')->and($task->review_status)->toBe('approved')->and($task->completed_at)->not->toBeNull()->and($submission->documents()->count())->toBe(1)->and($submission->task_version)->toBe($task->lock_version - 1);
    expect($submission->completion_snapshot_ciphertext['remarks']['visit'])->toBe('Synthetic inspection performed');
    expect(DB::table('task_submissions')->value('completion_snapshot_ciphertext'))->not->toContain('Synthetic');
    foreach ([
        fn () => DB::table('task_submissions')->where('id', $submission->id)->update(['task_version' => 99]),
        fn () => DB::table('documents')->where('id', $document->id)->update(['caption_ciphertext' => 'changed']),
        fn () => DB::table('task_submission_documents')->where('submission_id', $submission->id)->delete(),
        fn () => TaskSubmissionDocument::factory()->create(['submission_id' => $submission->id, 'document_id' => $document->id]),
    ] as $mutation) {
        expect(fn () => DB::transaction($mutation))->toThrow(QueryException::class);
    }
    expect(fn () => app(RiderWork::class)->visit($rider, $task, $task->lock_version, executionInput(), (string) Str::uuid7()))->toThrow(ValidationException::class);
});

test('payment claims and available signboards require their conditional proof categories', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task, ['purpose' => 'address_verification', 'signboard_available' => '1', 'outcome' => 'payment_claimed', 'paid_amount' => '100.00']);
    $premise = executionProof($rider, $task);
    cleanExecutionProof($premise);
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey('proof');
    foreach (['payment_evidence', 'business_signboard'] as $category) {
        $document = executionProof($rider, $task, $category);
        cleanExecutionProof($document);
    }
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toBe([]);
    expect($task->payment_update_flag)->toBeFalse();
});

test('policy changes and evidence changes invalidate a reviewed completion digest', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    $document = executionProof($rider, $task);
    cleanExecutionProof($document);
    $review = app(CompletionChecklist::class)->review($task->refresh(), $rider);
    $policy = ReferenceValue::query()->where('category', 'visit_purpose')->where('code', 'site_visit')->firstOrFail();
    $metadata = $policy->metadata;
    $metadata['version'] = 'm06-v2';
    $policy->metadata = $metadata;
    $policy->save();
    expect(fn () => app(RiderWork::class)->submit($rider, $task, $task->lock_version, (string) Str::uuid7(), $review['digest']))->toThrow(ValidationException::class);
    $policy->is_active = false;
    $policy->save();
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey('policy');
});

test('reassignment denies editing and proof while retaining only own masked submission receipt', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    $document = executionProof($rider, $task);
    cleanExecutionProof($document);
    $submission = executionSubmit($rider, $task);
    $task->currentAllocation->released_at = now();
    $task->currentAllocation->save();
    $this->actingAs($rider)->get(route('proof.show', $document))->assertNotFound();
    $this->get(route('rider.tasks.history', $submission))->assertOk()->assertDontSee('Synthetic inspection performed')->assertDontSee($task->account->account_number);
    [$other] = executionFixture();
    $this->actingAs($other)->get(route('rider.tasks.history', $submission))->assertNotFound();
});

test('returned work starts a new correction draft and retains previously submitted visit and proof', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task, ['purpose' => 'payment_verification']);
    $proof = executionProof($rider, $task, 'payment_evidence');
    cleanExecutionProof($proof);
    $old = executionSubmit($rider, $task);
    // Arrange an M07 return projection without introducing a Rider approval endpoint.
    $task->refresh()->forceFill(['work_status' => 'in_progress', 'review_status' => 'returned', 'lock_version' => $task->lock_version + 1])->save();
    app(RiderWork::class)->start($rider, $task, $task->lock_version);
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey('visit');
    expect(fn () => app(TaskProof::class)->remove($rider, $task, $task->lock_version, $proof->public_id))->toThrow(ValidationException::class);
    executionVisit($rider, $task, ['purpose' => 'payment_verification', 'visit_remarks' => 'Corrected synthetic visit']);
    $newProof = executionProof($rider, $task, 'payment_evidence');
    cleanExecutionProof($newProof);
    $new = executionSubmit($rider, $task);
    expect($new->submission_version)->toBe(2)->and($old->refresh()->completion_snapshot_ciphertext['remarks']['visit'])->toBe('Synthetic inspection performed')->and($task->activities()->count())->toBe(2)->and($proof->refresh()->deleted_at)->toBeNull();
});

test('Livewire saves a visit and provides anchored completion feedback without direct completion', function (): void {
    [$rider,$task] = executionFixture();
    Livewire::actingAs($rider)->test(Show::class, ['task' => $task])->call('start')->assertSet('section', 'visit')->set('data', executionInput())->call('saveVisit')->assertHasNoErrors()->assertSet('section', 'proof')->call('reviewCompletion')->assertSee('Completion review')->assertSee('Premises / Site Photo');
    expect($task->refresh()->work_status)->toBe('in_progress');
    $this->actingAs($rider)->get(route('rider.tasks.index', ['status' => 'today']))->assertOk();
});

test('proof dispatcher publishes only known durable media events and records failures without losing them', function (): void {
    Storage::fake('s3');
    Queue::fake([ScanTaskProof::class]);
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    executionProof($rider, $task);
    $this->artisan('tasks:dispatch-proof')->assertSuccessful();
    Queue::assertPushed(ScanTaskProof::class, 1);
    expect(OutboxEvent::query()->where('event_type', 'tasks.proof_scan')->whereNotNull('dispatched_at')->count())->toBe(1);
    $this->artisan('tasks:dispatch-proof')->assertSuccessful();
    Queue::assertPushed(ScanTaskProof::class, 1);
});

test('rejected scans cannot be retried as clean and draft deletion preserves a recoverable private object', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    $document = executionProof($rider, $task);
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldReceive('scan')->once()->andThrow(new RuntimeException('malware_detected'));
    app(TaskProof::class)->scan($document->id, $scanner);
    expect($document->refresh()->scan_status)->toBe('rejected');
    expect(fn () => app(TaskProof::class)->retry($rider, $task, $task->refresh()->lock_version, $document->public_id))->toThrow(ValidationException::class);
    app(TaskProof::class)->remove($rider, $task, $task->lock_version, $document->public_id);
    expect(Document::query()->find($document->id))->toBeNull();
    Storage::disk('s3')->assertExists($document->object_key);
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey('proof');
});

test('proof is bounded by content dimensions count and same-task visit ownership', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    config(['task_execution.max_images' => 1]);
    $proof = executionProof($rider, $task);
    expect(fn () => executionProof($rider, $task, 'payment_evidence'))->toThrow(ValidationException::class);
    config(['task_execution.max_dimension' => 50]);
    expect(fn () => executionProof($rider, $task))->toThrow(ValidationException::class);
    [$other,$otherTask] = executionFixture();
    executionVisit($other, $otherTask);
    $foreign = $otherTask->activities()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('id', $proof->id)->update(['activity_id' => $foreign->id])))->toThrow(QueryException::class);
    $this->actingAs($other)->get(route('proof.show', $proof))->assertNotFound();
});

test('Rider eligibility withdrawal blocks an already open component and submitted history', function (): void {
    [$rider,$task] = executionFixture();
    $component = Livewire::actingAs($rider)->test(Show::class, ['task' => $task]);
    $rider->staff->is_active = false;
    $rider->staff->save();
    $component->call('start')->assertNotFound();
    expect($task->refresh()->work_status)->toBe('unstarted');
});

test('unapproved per-type policies cannot accept a visit even with valid shared codes', function (): void {
    [$rider,$task] = executionFixture();
    app(RiderWork::class)->start($rider, $task, 0);
    $type = ReferenceValue::query()->where('category', 'assignment_type')->where('code', $task->type_code)->firstOrFail();
    $type->metadata = [];
    $type->save();
    expect(fn () => app(RiderWork::class)->visit($rider, $task, 1, executionInput(), (string) Str::uuid7()))->toThrow(ValidationException::class);
    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey('policy');
});

test('camera and gallery selections upload scan and submit through the actual Livewire actions', function (): void {
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-proof']);
    [$rider,$task] = executionFixture();
    $component = Livewire::actingAs($rider)->test(Show::class, ['task' => $task])->call('start')->set('data', executionInput())->call('saveVisit')
        ->set('photos', [UploadedFile::fake()->image('gallery.png', 80, 60)])
        ->set('camera', UploadedFile::fake()->image('camera.jpg', 81, 60))->assertCount('photos', 2)->call('uploadProof')->assertHasNoErrors()->assertSet('photos', []);
    $documents = Document::query()->where('owner_type', 'task')->where('owner_id', $task->id)->get();
    expect($documents)->toHaveCount(2);
    foreach ($documents as $document) {
        cleanExecutionProof($document);
    }
    $component->call('reviewCompletion')->assertSee('Required checks passed.')->call('submitCompletion')->assertHasNoErrors()->assertSee('verified automatically');
    expect($task->refresh()->work_status)->toBe('completed')->and($task->submissions()->count())->toBe(1);
});

test('upload retry reuses its private document and tampered object content never becomes clean', function (): void {
    Storage::fake('s3');
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    config(['filesystems.disks.s3.bucket' => 'synthetic-proof']);
    $file = UploadedFile::fake()->image('same.png', 80, 60);
    $version = $task->refresh()->lock_version;
    $first = app(TaskProof::class)->upload($rider, $task, $version, $file, 'premises', null);
    $again = app(TaskProof::class)->upload($rider, $task, $version, $file, 'premises', null);
    expect($again->id)->toBe($first->id)->and(OutboxEvent::query()->where('event_type', 'tasks.proof_scan')->count())->toBe(1);
    Storage::disk('s3')->put($first->object_key, Crypt::encryptString('tampered'));
    $scanner = Mockery::mock(MalwareScanner::class);
    $scanner->shouldNotReceive('scan');
    expect(fn () => app(TaskProof::class)->scan($first->id, $scanner))->toThrow(RuntimeException::class);
    expect($first->refresh()->scan_status)->toBe('pending');
});

test('camera JPEG orientation is preserved while EXIF is stripped from stored proof', function (): void {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('Optional EXIF decoder is unavailable.');
    }
    Storage::fake('s3');
    config(['filesystems.disks.s3.bucket' => 'synthetic-proof']);
    [$rider,$task] = executionFixture();
    executionVisit($rider, $task);
    $original = UploadedFile::fake()->image('camera.jpg', 80, 60);
    $image = file_get_contents($original->getRealPath());
    $exif = "Exif\0\0II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";
    $camera = UploadedFile::fake()->createWithContent('camera.jpg', substr($image, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($image, 2));
    $document = app(TaskProof::class)->upload($rider, $task, $task->refresh()->lock_version, $camera, 'premises', null);
    $stored = app(TaskProof::class)->bytes($document);
    $dimensions = getimagesizefromstring($stored);
    expect([$dimensions[0], $dimensions[1]])->toBe([60, 80])->and($stored)->not->toContain('Exif')->and($document->captured_at)->toBeNull();
});

test('Rider assignment progress follows successful submissions returns and approval separately', function (): void {
    Storage::fake('s3');
    [$rider, $task] = executionFixture();
    $others = Task::factory()->count(2)->create(['assignment_batch_id' => $task->assignment_batch_id]);
    foreach ($others as $other) {
        TaskAllocation::factory()->create(['task_id' => $other->id, 'collector_id' => $rider->staff_id]);
    }
    $progress = fn () => app(RiderAssignments::class)->batches($rider)->sole();
    expect((int) $progress()->completed_count)->toBe(0);
    executionVisit($rider, $task, ['purpose' => 'payment_verification']);
    $document = executionProof($rider, $task, 'payment_evidence');
    cleanExecutionProof($document);
    expect((int) $progress()->completed_count)->toBe(0);
    expect(fn () => app(RiderWork::class)->submit($rider, $task, $task->refresh()->lock_version, (string) Str::uuid7(), 'stale-digest'))->toThrow(ValidationException::class);
    expect((int) $progress()->completed_count)->toBe(0);
    $submission = executionSubmit($rider, $task);
    expect((int) $progress()->completed_count)->toBe(1)->and((int) $progress()->pending_count)->toBe(2)->and((int) $progress()->verified_count)->toBe(0);
    $this->actingAs($rider)->get(route('rider.assignments.index'))->assertOk()->assertSee('1 of 3 completed')->assertSee('width:33.33%', false);
    $admin = staffManager();
    $reviewer = app(ReviewSubmission::class);
    $reviewer->confirm($admin, $reviewer->prepare($admin, [$submission->public_id], 'returned', 'Record the missing site detail.'));
    expect((int) $progress()->completed_count)->toBe(0)->and((int) $progress()->pending_count)->toBe(3);
    executionVisit($rider, $task, ['purpose' => 'payment_verification']);
    cleanExecutionProof(executionProof($rider, $task, 'payment_evidence'));
    $correction = executionSubmit($rider, $task);
    $reviewer->confirm($admin, $reviewer->prepare($admin, [$correction->public_id], 'approved', 'Correction checked.'));
    expect((int) $progress()->completed_count)->toBe(1)->and((int) $progress()->verified_count)->toBe(1);
    foreach ($others as $other) {
        executionVisit($rider, $other, ['purpose' => 'payment_verification']);
        cleanExecutionProof(executionProof($rider, $other, 'payment_evidence'));
        executionSubmit($rider, $other);
    }
    expect((int) $progress()->completed_count)->toBe(3)->and((int) $progress()->pending_count)->toBe(0)->and((int) $progress()->verified_count)->toBe(1);
    $this->actingAs($rider)->get(route('rider.assignments.index'))->assertOk()->assertSee('3 of 3 completed')->assertSee('width:100%', false);
});

test('new proof is permanently watermarked encrypted and idempotent across preview and Admin access', function (): void {
    config(['task_execution.disk' => 'local', 'task_execution.scan_required' => false]);
    Storage::fake('local');
    $this->travelTo(now()->utc()->startOfMinute());
    [$rider, $task] = executionFixture();
    executionVisit($rider, $task);
    $photo = UploadedFile::fake()->image('field.png', 1400, 1000);
    $proof = app(TaskProof::class);
    $document = $proof->upload($rider, $task, $task->refresh()->lock_version, $photo, 'premises', 'Watermark test');
    $bytes = $proof->bytes($document);
    expect(hash('sha256', $bytes))->toBe($document->sha256)->and($document->scan_status)->toBe('skipped');
    $image = imagecreatefromstring($bytes);
    $red = 0;
    for ($y = 0; $y < 160; $y++) {
        for ($x = 700; $x < 1400; $x++) {
            $pixel = imagecolorat($image, $x, $y);
            if ((($pixel >> 16) & 255) > 160 && (($pixel >> 8) & 255) < 100 && ($pixel & 255) < 100) {
                $red++;
            }
        }
    }
    imagedestroy($image);
    expect($red)->toBeGreaterThan(100);
    expect(Storage::disk('local')->get($document->object_key))->not->toBe($bytes);
    $this->actingAs($rider)->get(route('proof.show', $document))->assertOk()->assertContent($bytes);
    $this->get(route('proof.show', $document))->assertOk()->assertContent($bytes);
    $this->get(route('rider.tasks.show', ['task' => $task, 'section' => 'proof']))->assertOk()->assertSee($document->proofReference())->assertDontSee('Skipped');
    $this->actingAs(staffManager())->get(route('proof.show', $document))->assertOk()->assertContent($bytes);
    $this->travel(30)->minutes();
    $again = $proof->upload($rider, $task, $task->refresh()->lock_version, $photo, 'premises', 'Watermark test');
    expect($again->id)->toBe($document->id)->and($proof->bytes($again))->toBe($bytes)->and(Document::where('owner_id', $task->id)->where('owner_type', 'task')->count())->toBe(1);
});

test('changed visit details must be saved before completion review or submission', function (): void {
    [$rider, $task] = executionFixture();
    executionVisit($rider, $task);
    Livewire::actingAs($rider)->test(Show::class, ['task' => $task])
        ->set('data.visit_remarks', 'Unsaved field correction')
        ->call('reviewCompletion')->assertSet('section', 'visit')->assertHasErrors('visit')
        ->call('submitCompletion')->assertHasErrors('visit');
    expect($task->submissions()->count())->toBe(0);
    $this->actingAs($rider)->get(route('rider.tasks.show', ['task' => $task, 'section' => 'visit']))->assertOk()->assertSee('Additional Details')->assertSee('Field Visit Details')->assertSee('Remarks / Notes');
});

test('call-only outcomes cannot be submitted as Rider site visits', function (): void {
    [$rider, $task] = executionFixture();
    app(RiderWork::class)->start($rider, $task, $task->lock_version);
    $this->actingAs($rider);
    Livewire::test(Show::class, ['task' => $task])->assertViewHas('choices', fn ($choices) => ! $choices['activity_outcome']->contains('code', 'answered') && $choices['activity_outcome']->contains('code', 'promise_to_pay'));
    expect(fn () => app(RiderWork::class)->visit($rider, $task, $task->refresh()->lock_version, executionInput(['outcome' => 'answered']), (string) Str::uuid7()))->toThrow(ValidationException::class);
});

test('rider task messages use the shared popup instead of an inline notice', function (): void {
    [$rider, $task] = executionFixture();

    Livewire::actingAs($rider)->test(Show::class, ['task' => $task])->call('start')
        ->assertSeeHtml('class="confirmation-dialog"')
        ->assertSee('Task started. You can now record your visit.');
});

test('rider pages confirm in the shared dialog instead of browser prompts', function (): void {
    $views = [...glob(resource_path('views/livewire/rider/*.blade.php')), ...glob(resource_path('views/livewire/rider/*/*.blade.php')), ...glob(resource_path('views/components/rider/*.blade.php')), resource_path('views/components/profile-photo-editor.blade.php')];

    expect($views)->not->toBeEmpty();
    foreach ($views as $view) {
        expect(file_get_contents($view))->not->toContain('wire:confirm', $view);
    }
});

test('only submissions involving payment wait for admin verification and the rest are verified automatically', function (array $visit, array $categories, bool $needsAdmin): void {
    Storage::fake('s3');
    [$rider, $task] = executionFixture();
    $verifier = staffManager();
    executionVisit($rider, $task, $visit);
    foreach ($categories as $category) {
        cleanExecutionProof(executionProof($rider, $task, $category));
    }
    $submission = executionSubmit($rider, $task);

    $task->refresh();
    expect($task->work_status)->toBe($needsAdmin ? 'submitted' : 'completed')
        ->and($task->review_status)->toBe($needsAdmin ? 'pending' : 'approved')
        ->and($task->completed_at === null)->toBe($needsAdmin)
        ->and($task->history()->where('event_code', 'auto_verified')->where('submission_id', $submission->id)->exists())->toBe(! $needsAdmin)
        ->and($submission->review()->exists())->toBeFalse()
        ->and($verifier->notifications()->count())->toBe($needsAdmin ? 1 : 0);
})->with([
    'normal site visit' => [[], ['premises'], false],
    'payment claimed' => [['outcome' => 'payment_claimed', 'paid_amount' => '50.00'], ['premises', 'payment_evidence'], true],
    'payment verification visit' => [['purpose' => 'payment_verification'], ['payment_evidence'], true],
    'payment evidence photo only' => [[], ['premises', 'payment_evidence'], true],
]);
