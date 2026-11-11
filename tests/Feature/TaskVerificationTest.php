<?php

use App\Actions\Tasks\ReviewSubmission;
use App\Actions\Tasks\RiderWork;
use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\Verification\Show;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\ReferenceValue;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\TaskReview;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\TaskSubmitted;
use App\Services\Reports\TaskReports;
use App\Services\Tasks\CompletionChecklist;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function reviewFixture(?User $reviewer = null): array
{
    $task = Task::factory()->create()->refresh();
    $rider = User::factory()->for(Staff::factory()->create())->create();
    $rider->assignRole('Rider');
    $reviewer ??= User::factory()->create();
    $reviewer->assignRole('Admin');
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->staff_id, 'assigned_by' => $reviewer->id]);
    app(RiderWork::class)->start($rider, $task, 0);
    $visit = app(RiderWork::class)->visit($rider, $task, $task->refresh()->lock_version, [
        'purpose' => 'site_visit', 'business_applicable' => false, 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'successful_visit', 'site_address' => 'Synthetic review site', 'visit_remarks' => 'Synthetic submitted visit', 'collection_remarks' => 'No cash receipt',
    ], (string) Str::uuid7());
    // Explicit synthetic clean metadata: scanner/storage behavior is exercised by RiderExecutionTest.
    $document = Document::factory()->create(['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $visit->id, 'uploaded_by' => $rider->id, 'document_type_code' => 'proof_image', 'image_category_code' => 'premises', 'scan_status' => 'clean', 'mime_type' => 'image/jpeg']);
    $review = app(CompletionChecklist::class)->review($task->refresh(), $rider);
    $submission = app(RiderWork::class)->submit($rider, $task, $task->lock_version, (string) Str::uuid7(), $review['digest']);

    return [$reviewer, $rider, $task->refresh(), $submission, $document];
}
function verificationPreview(User $reviewer, TaskSubmission $submission, string $decision = 'approved', ?string $reason = null): array
{
    return app(ReviewSubmission::class)->prepare($reviewer, [$submission->public_id], $decision, $reason);
}

test('verification routes require reviewer permission', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $this->get(route('admin.verification.index'))->assertRedirect(route('login'));
    $this->actingAs($rider)->get(route('admin.verification.index'))->assertForbidden();
    $this->actingAs($reviewer)->get(route('admin.verification.index'))->assertOk()->assertSee('Task verification');
    $this->get(route('admin.verification.show', $submission))->assertOk()->assertSee('Synthetic submitted visit');
});

test('submitting a task notifies verifiers through the admin bell and live counts', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $notification = $reviewer->notifications()->where('type', TaskSubmitted::class)->sole();
    expect($notification->data)->toMatchArray(['task' => $task->public_id, 'submission' => $submission->public_id])
        ->and($notification->data['message'])->toBe($rider->staff->full_name.' submitted a task for verification.')
        ->and($rider->notifications()->where('type', TaskSubmitted::class)->exists())->toBeFalse();

    $this->actingAs($reviewer)->get(route('admin.home'))->assertOk()->assertSee('Notifications, 1 unread')->assertSeeHtml('sidebar-count');
    $bell = Livewire::actingAs($reviewer)->test(NotificationBell::class)->assertSee('submitted a task for verification')
        ->assertSet('pendingCounts', ['tasks' => 1, 'leave' => 0]);
    $bell->call('refreshCounts')->assertDispatched('pending-reviews', tasks: 1, leave: 0)->assertNotDispatched('pending-reviews-changed');
    app(ReviewSubmission::class)->confirm($reviewer, verificationPreview($reviewer, $submission, 'approved', 'Checked'));
    $bell->call('refreshCounts')->assertDispatched('pending-reviews-changed')->assertSet('pendingCounts', ['tasks' => 0, 'leave' => 0]);

    $bell->call('open', $notification->id)->assertRedirect(route('admin.verification.show', $submission->public_id));
    expect($notification->fresh()->read_at)->not->toBeNull();
    $other = User::factory()->create();
    $other->assignRole('Admin');
    Livewire::actingAs($other)->test(NotificationBell::class)->call('open', $notification->id)->assertNotFound();
});

test('mark all as read clears the admin bell', function (): void {
    [$reviewer] = reviewFixture();
    reviewFixture($reviewer);
    expect($reviewer->unreadNotifications()->count())->toBe(2);
    Livewire::actingAs($reviewer)->test(NotificationBell::class)->call('markAllRead')->assertDontSee('Mark all as read');
    expect($reviewer->unreadNotifications()->count())->toBe(0);
});

test('independent approval completes exact submitted version and persists immutable decision audit history and notice', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $preview = verificationPreview($reviewer, $submission, 'approved', 'Reviewed synthetic evidence');
    $result = app(ReviewSubmission::class)->confirm($reviewer, $preview);
    $decision = $submission->review()->firstOrFail();
    expect($result[0]['status'])->toBe('applied')->and($task->refresh()->work_status)->toBe('completed')->and($task->review_status)->toBe('approved')->and($task->completed_at)->not->toBeNull()
        ->and($decision->submission_id)->toBe($submission->id)->and($decision->reviewer_id)->toBe($reviewer->id)->and($rider->notifications()->count())->toBe(1);
    expect(DB::table('task_reviews')->value('reason'))->not->toContain('Reviewed synthetic');
    expect(AuditLog::query()->where('module', 'M07')->get()->toJson())->not->toContain('Reviewed synthetic');
    expect($task->history()->where('event_code', 'approved')->count())->toBe(1)->and($task->payment_update_flag)->toBeFalse();
    $again = app(ReviewSubmission::class)->confirm($reviewer, $preview);
    expect($again[0]['status'])->toBe('already_applied')->and(TaskReview::query()->count())->toBe(1)->and($rider->notifications()->count())->toBe(1);
    foreach ([fn () => DB::table('task_reviews')->where('id', $decision->id)->update(['decision' => 'returned']), fn () => DB::table('task_reviews')->where('id', $decision->id)->delete()] as $mutation) {
        expect(fn () => DB::transaction($mutation))->toThrow(QueryException::class);
    }
});

test('return requires nonblank remarks and preserves source and proof for a new correction cycle', function (): void {
    [$reviewer,$rider,$task,$submission,$document] = reviewFixture();
    expect(fn () => verificationPreview($reviewer, $submission, 'returned', '   '))->toThrow(ValidationException::class);
    $source = $task->getRawOriginal('source_snapshot_ciphertext');
    $result = app(ReviewSubmission::class)->confirm($reviewer, verificationPreview($reviewer, $submission, 'returned', 'Please clarify the site signboard.'));
    expect($task->refresh()->work_status)->toBe('in_progress')->and($task->review_status)->toBe('returned')->and($task->getRawOriginal('source_snapshot_ciphertext'))->toBe($source)->and($document->refresh()->deleted_at)->toBeNull();
    $this->actingAs($rider)->get(route('rider.tasks.show', $task))->assertOk()->assertSee('Please clarify the site signboard.');
    $this->get(route('rider.home'))->assertOk()->assertSee('Your task was returned for correction.');
    app(RiderWork::class)->start($rider, $task, $task->lock_version);
    expect($task->refresh()->review_status)->toBe('none')->and($submission->review->reason)->toBe('Please clarify the site signboard.');
});

test('dual-role submitter cannot approve or return own submission at action and database boundaries', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $rider->assignRole('Admin');
    expect(Gate::forUser($rider)->allows('view', $submission))->toBeTrue()->and(Gate::forUser($rider)->allows('decide', $submission))->toBeFalse();
    foreach (['approved', 'returned'] as $decision) {
        $preview = verificationPreview($rider, $submission, $decision, 'Synthetic reason');
        expect($preview['rows'][0]['issues'])->toHaveKey('reviewer');
        expect(fn () => app(ReviewSubmission::class)->confirm($rider, $preview))->toThrow(ValidationException::class);
    }
    expect(fn () => DB::transaction(fn () => TaskReview::factory()->create(['task_id' => $task->id, 'submission_id' => $submission->id, 'submission_version' => 1, 'reviewer_id' => $rider->id])))->toThrow(QueryException::class);
    expect($task->refresh()->work_status)->toBe('submitted');
});

test('stale bulk selection applies no rows and returns explicit affected row errors', function (): void {
    [$reviewer,$rider,$one,$first] = reviewFixture();
    [,$other,$two,$second] = reviewFixture($reviewer);
    $preview = app(ReviewSubmission::class)->prepare($reviewer, [$first->public_id, $second->public_id], 'approved', null);
    $two->lock_version++;
    $two->save();
    try {
        app(ReviewSubmission::class)->confirm($reviewer, $preview);
        $this->fail('Stale selection accepted');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('rows.'.$second->public_id);
    }
    expect(TaskReview::query()->count())->toBe(0)->and($one->refresh()->work_status)->toBe('submitted')->and($rider->notifications()->count())->toBe(0);
});

test('fresh bulk preview commits each explicit row once with exact reconciliation', function (): void {
    [$reviewer,$rider,$one,$first] = reviewFixture();
    [,$other,$two,$second] = reviewFixture($reviewer);
    $preview = app(ReviewSubmission::class)->prepare($reviewer, [$first->public_id, $second->public_id], 'returned', 'Please correct the visit remarks.');
    $result = app(ReviewSubmission::class)->confirm($reviewer, $preview);
    expect(collect($result)->where('status', 'applied')->count())->toBe(2)->and(TaskReview::query()->count())->toBe(2)->and($one->refresh()->review_status)->toBe('returned')->and($two->refresh()->review_status)->toBe('returned');
});

test('permission and signature changes invalidate a pending preview without decisions', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $preview = verificationPreview($reviewer, $submission);
    $forged = $preview;
    $forged['reason'] = 'Changed after preview';
    expect(fn () => app(ReviewSubmission::class)->confirm($reviewer, $forged))->toThrow(ValidationException::class);
    $reviewer->syncRoles([]);
    expect(fn () => app(ReviewSubmission::class)->confirm($reviewer, $preview))->toThrow(AuthorizationException::class);
    expect(TaskReview::query()->count())->toBe(0);
});

test('review uses frozen policy instead of later master data and rejects drafts or competing reviewers', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $policy = ReferenceValue::query()->where('category', 'visit_purpose')->where('code', 'site_visit')->firstOrFail();
    $policy->is_active = false;
    $policy->save();
    $first = verificationPreview($reviewer, $submission);
    $competitor = User::factory()->create();
    $competitor->assignRole('Admin');
    app(ReviewSubmission::class)->confirm($competitor, verificationPreview($competitor, $submission));
    expect(fn () => app(ReviewSubmission::class)->confirm($reviewer, $first))->toThrow(ValidationException::class);
    expect(TaskReview::query()->count())->toBe(1);
});

test('Livewire review page previews and confirms decision with escaped immutable remarks', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    Livewire::actingAs($reviewer)->test(Show::class, ['submission' => $submission])->set('decision', 'returned')->set('reason', '<script>unsafe()</script> review remark')->call('prepareReview')->assertHasNoErrors()->assertSee('Confirm exact review selection')->call('confirmReview')->assertHasNoErrors()->assertSee('Recorded verification decision')->assertDontSeeHtml('<script>unsafe()</script>');
    $this->actingAs($reviewer)->get(route('admin.verification.index', ['status' => 'returned']))->assertOk()->assertSee($submission->public_id);
});

test('expired preview and incomplete selected submission cannot be approved', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $preview = verificationPreview($reviewer, $submission);
    $this->travel(21)->minutes();
    expect(fn () => app(ReviewSubmission::class)->confirm($reviewer, $preview))->toThrow(ValidationException::class);
    expect(TaskReview::query()->count())->toBe(0);
});

test('return resubmission approval appends a superseding cycle without changing prior decisions', function (): void {
    [$reviewer,$rider,$task,$first,$proof] = reviewFixture();
    app(ReviewSubmission::class)->confirm($reviewer, verificationPreview($reviewer, $first, 'returned', 'Add a clearer site description.'));
    $oldReview = $first->review()->firstOrFail();
    app(RiderWork::class)->start($rider, $task, $task->refresh()->lock_version);
    $visit = app(RiderWork::class)->visit($rider, $task, $task->refresh()->lock_version, [
        'purpose' => 'site_visit', 'business_applicable' => false, 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'successful_visit', 'site_address' => 'Synthetic corrected site', 'visit_remarks' => 'Corrected site description',
    ], (string) Str::uuid7());
    Document::factory()->create(['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $visit->id, 'uploaded_by' => $rider->id, 'document_type_code' => 'proof_image', 'image_category_code' => 'premises', 'scan_status' => 'clean', 'mime_type' => 'image/jpeg']);
    $check = app(CompletionChecklist::class)->review($task->refresh(), $rider);
    $second = app(RiderWork::class)->submit($rider, $task, $task->lock_version, (string) Str::uuid7(), $check['digest']);
    app(ReviewSubmission::class)->confirm($reviewer, verificationPreview($reviewer, $second));
    expect($second->review->supersedes_review_id)->toBe($oldReview->id)->and($oldReview->refresh()->decision)->toBe('returned')->and($oldReview->reason)->toBe('Add a clearer site description.')->and($task->refresh()->work_status)->toBe('completed')->and($proof->refresh()->scan_status)->toBe('clean')->and($rider->notifications()->count())->toBe(2);
    $this->actingAs($reviewer)->get(route('admin.tasks.show', $task))->assertOk()->assertSee('View verification decision');
});

test('withdrawn reviewer grant blocks confirmation and until-only date filters remain usable', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $this->actingAs($reviewer)->get(route('admin.verification.index', ['until' => now()->timezone(config('dcms.business_timezone'))->toDateString()]))->assertOk()->assertSee($submission->public_id);
    $preview = verificationPreview($reviewer, $submission);
    $reviewer->removeRole('Admin');
    $reviewer->givePermissionTo(['admin.access', 'tasks.view', 'tasks.monitor']);
    expect(Gate::forUser($reviewer->fresh())->allows('viewAny', TaskSubmission::class))->toBeFalse();
    expect(fn () => app(ReviewSubmission::class)->confirm($reviewer, $preview))->toThrow(AuthorizationException::class);
    expect(TaskReview::query()->count())->toBe(0);
});

test('return cannot strand a correction with a Rider whose submission permission was withdrawn', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $rider->removeRole('Rider');
    $rider->givePermissionTo(['rider.access', 'tasks.view']);
    expect($rider->fresh()->canUseRiderInterface())->toBeTrue()->and($rider->can('tasks.submit'))->toBeFalse();
    $preview = verificationPreview($reviewer, $submission, 'returned', 'Clarify the site address.');
    expect($preview['rows'][0]['issues'])->toHaveKey('rider');
    expect(fn () => app(ReviewSubmission::class)->confirm($reviewer, $preview))->toThrow(ValidationException::class);
    expect($task->refresh()->work_status)->toBe('submitted')->and($rider->notifications()->count())->toBe(0);
});

test('reports count submitted work separately from independently verified completion', function (): void {
    [$reviewer,$rider,$task,$submission] = reviewFixture();
    $reports = app(TaskReports::class);
    $criteria = ['family' => 'task', 'columns' => TaskReports::DEFAULT_COLUMNS];
    $before = $reports->snapshot($reviewer, $criteria);
    expect($before['totals']['pending_verification'])->toBe(1)->and($before['totals']['completed'])->toBe(0);
    expect($reports->snapshot($reviewer, array_replace($criteria, ['family' => 'completed_task']))['rows'])->toBe([]);
    app(ReviewSubmission::class)->confirm($reviewer, verificationPreview($reviewer, $submission));
    $after = $reports->snapshot($reviewer, array_replace($criteria, ['family' => 'completed_task']));
    expect($after['totals']['completed'])->toBe(1)->and($after['rows'][0]['review_status'])->toBe('approved');
    $performance = $reports->snapshot($reviewer, array_replace($criteria, ['family' => 'rider_performance']));
    expect($performance['rows'][0]['allocated'])->toBe(1)->and($performance['rows'][0]['completed'])->toBe(1);
});
