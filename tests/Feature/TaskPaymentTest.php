<?php

use App\Actions\Tasks\ReviewSubmission;
use App\Actions\Tasks\RiderWork;
use App\Actions\Tasks\SaveTask;
use App\Actions\Tasks\TaskProof;
use App\Livewire\Admin\Tasks\Editor;
use App\Livewire\Admin\Tasks\Index as ManageTask;
use App\Livewire\Admin\Verification\Index as VerificationQueue;
use App\Livewire\Admin\Verification\Show as VerificationShow;
use App\Livewire\Rider\Tasks\Show as RiderTask;
use App\Models\AccountParty;
use App\Models\AssignmentBatch;
use App\Models\Document;
use App\Models\FinancialSnapshot;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\Reports\TaskReports;
use App\Services\Tasks\CompletionChecklist;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    config(['dcms.search_key' => base64_encode(str_repeat('t', 32))]);
    $this->seed(DatabaseSeeder::class);
});

/**
 * @return array{0: Task, 1: User, 2: User}
 */
function paymentTask(?string $balance = '1000.00'): array
{
    $task = Task::factory()->create()->refresh();
    $admin = staffManager();
    $rider = User::factory()->for(Staff::factory()->create())->create();
    $rider->assignRole('Rider');
    TaskAllocation::factory()->create(['task_id' => $task->id, 'collector_id' => $rider->staff_id, 'assigned_by' => $admin->id]);
    if ($balance !== null) {
        FinancialSnapshot::record($task, $admin, 'manual', ['outstanding' => Money::cents($balance), 'monthly' => 4500, 'balance_date' => '2026-11-01']);
    }

    return [$task, $rider, $admin];
}

function paymentVisit(User $rider, Task $task, array $changes = []): void
{
    app(RiderWork::class)->start($rider, $task, $task->refresh()->lock_version);
    app(RiderWork::class)->visit($rider, $task, $task->refresh()->lock_version, $changes + [
        'purpose' => 'site_visit', 'business_applicable' => false, 'availability' => 'available', 'visit_status' => 'operating', 'contact_result' => 'customer_contacted', 'outcome' => 'payment_claimed', 'site_address' => 'Synthetic site', 'visit_remarks' => 'Synthetic visit', 'collection_remarks' => 'Customer paid the client',
    ], (string) Str::uuid7());
}

function paymentSubmission(User $rider, Task $task, string $amount): TaskSubmission
{
    paymentVisit($rider, $task, ['paid_amount' => $amount]);
    $visit = $task->activities()->latest('id')->firstOrFail();
    foreach (['premises', 'payment_evidence'] as $category) {
        Document::factory()->create(['owner_type' => 'task', 'owner_id' => $task->id, 'activity_id' => $visit->id, 'uploaded_by' => $rider->id, 'document_type_code' => 'proof_image', 'image_category_code' => $category, 'scan_status' => 'clean', 'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', $category)]);
    }
    $review = app(CompletionChecklist::class)->review($task->refresh(), $rider);
    expect($review['issues'])->toBe([]);

    return app(RiderWork::class)->submit($rider, $task, $task->lock_version, (string) Str::uuid7(), $review['digest']);
}

function approvePayment(User $reviewer, TaskSubmission $submission, array $check): array
{
    $reviews = app(ReviewSubmission::class);

    return $reviews->confirm($reviewer, $reviews->prepare($reviewer, [$submission->public_id], 'approved', null, [$submission->public_id => $check]));
}

test('amounts are read exactly and refuse anything unclear', function (mixed $input, ?int $cents): void {
    expect(Money::cents($input))->toBe($cents);
})->with([
    ['1234.50', 123450], ['1,234.50', 123450], ['RM 1,234.50', 123450], ['rm50', 5000], ['0', 0], ['45', 4500], ['45.5', 4550], ['0.01', 1], [1234.5, 123450],
    ['12,34', null], ['1.234', null], ['-5', null], ['abc', null], ['', null], [null, null], [true, null], ['1e5', null], ['9999999999999', null],
]);

test('money is formatted the way people read it', function (): void {
    expect(Money::format(123450))->toBe('RM 1,234.50')
        ->and(Money::plain(5))->toBe('0.05')
        ->and(Money::format(null))->toBe('Not provided');
});

test('create task requires a readable balance and records it encrypted', function (): void {
    $party = AccountParty::factory()->create();
    $batch = AssignmentBatch::factory()->create();
    $actor = staffManager();
    $input = ['assignment_batch_id' => $batch->id, 'debt_account_id' => $party->debt_account_id, 'customer_id' => $party->debtor_id, 'task_id' => 'FG001', 'priority_code' => 'normal'];

    expect(fn () => app(SaveTask::class)->handle($actor, $input))->toThrow(ValidationException::class, 'outstanding balance');
    expect(fn () => app(SaveTask::class)->handle($actor, $input + ['outstanding_balance' => 'lots']))->toThrow(ValidationException::class, 'as an amount');
    $this->assertDatabaseCount('tasks', 0);

    $task = app(SaveTask::class)->handle($actor, $input + ['outstanding_balance' => '1,234.50', 'monthly_charge' => '45', 'balance_date' => '2026-11-01']);

    expect($task->latestBalance->outstandingCents())->toBe(123450)
        ->and($task->latestBalance->monthlyCents())->toBe(4500)
        ->and($task->latestBalance->source_code)->toBe('manual')
        ->and(DB::table('financial_snapshots')->value('outstanding_balance_ciphertext'))->not->toContain('1234');
});

test('editing a task keeps earlier balances and only adds a row when something changed', function (): void {
    $actor = staffManager();
    $party = AccountParty::factory()->create();
    $input = ['assignment_batch_id' => AssignmentBatch::factory()->create()->id, 'debt_account_id' => $party->debt_account_id, 'customer_id' => $party->debtor_id, 'task_id' => 'FG001', 'priority_code' => 'normal', 'outstanding_balance' => '100.00', 'balance_date' => '2026-11-01'];
    $task = app(SaveTask::class)->handle($actor, $input);

    $task = app(SaveTask::class)->handle($actor, $input, $task, $task->lock_version);
    expect($task->balances()->count())->toBe(1);

    $task = app(SaveTask::class)->handle($actor, ['outstanding_balance' => '80.00'] + $input, $task, $task->lock_version);
    expect($task->balances()->count())->toBe(2)
        ->and($task->refresh()->latestBalance->outstandingCents())->toBe(8000)
        ->and($task->balances()->first()->outstandingCents())->toBe(10000);
});

test('the create task page takes the balance and contact details', function (): void {
    $batch = AssignmentBatch::factory()->create();
    Livewire::actingAs(staffManager())->test(Editor::class)
        ->assertSee('Outstanding balance (RM)')->assertSee('Monthly charge (RM)')->assertSee('Balance date')
        ->set('data.assignment_batch_id', (string) $batch->id)
        ->set('data.task_id', 'FG001')
        ->set('data.customer_reference', 'C-1')
        ->set('data.customer_name', 'Synthetic Person')
        ->set('data.phone', '0123456789')
        ->set('data.email', 'person@example.test')
        ->set('data.account_number', 'A-1')
        ->call('save')
        ->assertHasErrors(['data.outstanding_balance'])
        ->set('data.outstanding_balance', '500')
        ->set('data.balance_date', '2026-11-01')
        ->call('save')
        ->assertHasNoErrors();
    $task = Task::query()->sole();

    expect($task->latestBalance->outstandingCents())->toBe(50000)
        ->and($task->customer->contacts()->pluck('value_ciphertext')->sort()->values()->all())->toBe(['0123456789', 'person@example.test']);
    $this->get(route('admin.tasks.show', $task))->assertOk()->assertSee('RM 500.00')->assertSee('Balance history')->assertSee('0123456789');
    Livewire::test(ManageTask::class)->assertSee('Outstanding RM 500.00');
    Livewire::test(Editor::class, ['task' => $task])->assertSet('data.outstanding_balance', '500.00')->assertSet('data.balance_date', '2026-11-01');
});

test('balance history cannot be changed or removed', function (): void {
    [$task] = paymentTask();
    $id = FinancialSnapshot::query()->where('task_id', $task->id)->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('financial_snapshots')->where('id', $id)->update(['source_code' => 'import'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('financial_snapshots')->where('id', $id)->delete()))->toThrow(QueryException::class);
});

test('the rider sees the amount owed and can enter what was paid', function (): void {
    [$task, $rider] = paymentTask('1000.00');

    Livewire::actingAs($rider)->test(RiderTask::class, ['task' => $task])
        ->assertSee('Outstanding balance')->assertSee('RM 1,000.00')->assertSee('RM 45.00');
    $this->actingAs($rider)->get(route('rider.tasks.index'))->assertOk()->assertSee('RM 1,000.00');
    Livewire::actingAs($rider)->test(RiderTask::class, ['task' => $task])->set('section', 'visit')->assertSee('Amount the customer paid (RM)');
});

test('a payment claim cannot be submitted without an amount and payment evidence', function (string $change, string $issue): void {
    [$task, $rider] = paymentTask();
    paymentVisit($rider, $task, $change === 'amount only' ? ['outcome' => 'successful_visit', 'paid_amount' => '100'] : []);

    expect(app(CompletionChecklist::class)->review($task->refresh(), $rider)['issues'])->toHaveKey($issue);
})->with([
    'no amount' => ['outcome only', 'payment'],
    'typed amount still needs the photo' => ['amount only', 'proof'],
]);

test('riders cannot enter an unreadable amount', function (): void {
    [$task, $rider] = paymentTask();

    expect(fn () => paymentVisit($rider, $task, ['paid_amount' => 'about 100']))->toThrow(ValidationException::class, 'amount paid');
});

test('a full payment is approved only after the reviewer confirms the photo, then clears the balance', function (): void {
    [$task, $rider, $admin] = paymentTask('1000.00');
    $submission = paymentSubmission($rider, $task, '1000.00');
    $reviews = app(ReviewSubmission::class);

    expect($reviews->prepare($admin, [$submission->public_id], 'approved', null)['rows'][0]['issues'])->toHaveKey('payment');
    expect($task->refresh()->balances()->count())->toBe(1);

    approvePayment($admin, $submission, ['photo' => true, 'partial' => false]);

    $latest = $task->refresh()->latestBalance;
    expect($task->work_status)->toBe('completed')
        ->and($task->payment_update_flag)->toBeTrue()
        ->and($latest->source_code)->toBe('payment')
        ->and($latest->paidCents())->toBe(100000)
        ->and($latest->outstandingCents())->toBe(0)
        ->and($latest->submission_id)->toBe($submission->id);
});

test('a part payment needs its own confirmation and leaves the rest outstanding', function (): void {
    [$task, $rider, $admin] = paymentTask('1000.00');
    $submission = paymentSubmission($rider, $task, '400.00');
    $reviews = app(ReviewSubmission::class);

    expect($reviews->prepare($admin, [$submission->public_id], 'approved', null, [$submission->public_id => ['photo' => true, 'partial' => false]])['rows'][0]['issues']['payment'])->toContain('part payment');

    approvePayment($admin, $submission, ['photo' => true, 'partial' => true]);

    expect($task->refresh()->latestBalance->outstandingCents())->toBe(60000)
        ->and($task->latestBalance->paidCents())->toBe(40000);
});

test('an amount above the balance cannot be approved but can be returned to the rider', function (): void {
    [$task, $rider, $admin] = paymentTask('100.00');
    $submission = paymentSubmission($rider, $task, '150.00');
    $reviews = app(ReviewSubmission::class);

    expect($reviews->prepare($admin, [$submission->public_id], 'approved', null, [$submission->public_id => ['photo' => true, 'partial' => true]])['rows'][0]['issues']['payment'])->toContain('more than the outstanding balance');

    $reviews->confirm($admin, $reviews->prepare($admin, [$submission->public_id], 'returned', 'The photo shows RM 100.'));

    expect($task->refresh()->review_status)->toBe('returned')
        ->and($task->payment_update_flag)->toBeFalse()
        ->and($task->balances()->where('source_code', 'payment')->exists())->toBeFalse();
});

test('a tampered preview cannot switch on the payment confirmation', function (): void {
    [$task, $rider, $admin] = paymentTask();
    $submission = paymentSubmission($rider, $task, '1000.00');
    $reviews = app(ReviewSubmission::class);
    $preview = $reviews->prepare($admin, [$submission->public_id], 'approved', null);
    $preview['payments'] = [$submission->public_id => ['photo' => true, 'partial' => true]];

    expect(fn () => $reviews->confirm($admin, $preview))->toThrow(ValidationException::class);
    expect($task->refresh()->work_status)->toBe('submitted');
});

test('payment claims cannot be approved in bulk from the queue', function (): void {
    [$task, $rider, $admin] = paymentTask();
    $submission = paymentSubmission($rider, $task, '1000.00');

    Livewire::actingAs($admin)->test(VerificationQueue::class)
        ->assertSee('Payment claim RM 1,000.00')
        ->set('selected', [$submission->public_id])
        ->call('prepareReview')
        ->assertSee('Open this submission on its own');
    expect($task->refresh()->work_status)->toBe('submitted');
});

test('the review page shows all three figures and applies the decision', function (): void {
    [$task, $rider, $admin] = paymentTask('1000.00');
    $submission = paymentSubmission($rider, $task, '400.00');

    Livewire::actingAs($admin)->test(VerificationShow::class, ['submission' => $submission])
        ->assertSee('Payment check')->assertSee('RM 400.00')->assertSee('RM 1,000.00')->assertSee('RM 600.00')->assertSee('Part payment')
        ->set('photoMatches', true)
        ->set('partialAccepted', true)
        ->call('prepareReview')
        ->assertSee('Eligible')
        ->call('confirmReview')
        ->assertHasNoErrors();

    expect($task->refresh()->latestBalance->outstandingCents())->toBe(60000);
    $this->actingAs($admin)->get(route('admin.tasks.show', $task))->assertOk()->assertSee('Verified payment')->assertSee('RM 400.00');
});

test('reports count verified payments as collected and never an unreviewed claim', function (): void {
    [$task, $rider, $admin] = paymentTask('1000.00');
    $submission = paymentSubmission($rider, $task, '400.00');
    $criteria = ['family' => 'task', 'columns' => ['task_reference', 'outstanding_balance', 'monthly_charge', 'collected']];
    $reports = app(TaskReports::class);

    $before = $reports->snapshot($admin, $criteria);
    expect($before['rows'][0]['collected'])->toBe('0.00')
        ->and($before['rows'][0]['outstanding_balance'])->toBe('1000.00')
        ->and($before['money']['collected'])->toBe('RM 0.00');

    approvePayment($admin, $submission, ['photo' => true, 'partial' => true]);

    $after = $reports->snapshot($admin, $criteria);
    expect($after['rows'][0]['collected'])->toBe('400.00')
        ->and($after['rows'][0]['outstanding_balance'])->toBe('600.00')
        ->and($after['money']['outstanding'])->toBe('RM 600.00')
        ->and($after['money']['collected'])->toBe('RM 400.00');

    $performance = $reports->snapshot($admin, ['family' => 'rider_performance', 'columns' => ['task_reference']]);
    expect($performance['rows'][0]['collected'])->toBe('400.00')->and($performance['rows'][0]['outstanding_balance'])->toBe('600.00');
});

test('proof photos can use local storage without a scan outside production', function (): void {
    config(['task_execution.disk' => 'local', 'task_execution.scan_required' => false]);
    Storage::fake('local');
    [$task, $rider] = paymentTask();
    paymentVisit($rider, $task);

    $document = app(TaskProof::class)->upload($rider, $task->refresh(), $task->lock_version, UploadedFile::fake()->image('paid.jpg'), 'payment_evidence', null);

    expect($document->scan_status)->toBe('skipped')
        ->and($document->disk)->toBe('local')
        ->and($document->isCleared())->toBeTrue()
        ->and(TaskProof::storageReady())->toBeTrue();
    $this->actingAs($rider)->get(route('proof.show', $document))->assertOk();
});

test('a rider can submit local proof photos when scanning is not required', function (): void {
    config(['task_execution.disk' => 'local', 'task_execution.scan_required' => false]);
    Storage::fake('local');
    [$task, $rider, $admin] = paymentTask('100.00');
    paymentVisit($rider, $task, ['paid_amount' => '100.00']);
    foreach (['premises', 'payment_evidence'] as $category) {
        app(TaskProof::class)->upload($rider, $task->refresh(), $task->lock_version, UploadedFile::fake()->image($category.'.jpg', 40 + strlen($category), 40), $category, null);
    }
    $review = app(CompletionChecklist::class)->review($task->refresh(), $rider);
    expect($review['issues'])->toBe([]);

    $submission = app(RiderWork::class)->submit($rider, $task, $task->lock_version, (string) Str::uuid7(), $review['digest']);
    approvePayment($admin, $submission, ['photo' => true, 'partial' => false]);

    expect($task->refresh()->work_status)->toBe('completed')->and($task->latestBalance->outstandingCents())->toBe(0);
});

test('production always scans proof photos even if configured not to', function (): void {
    config(['task_execution.scan_required' => false]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(TaskProof::scanRequired())->toBeTrue();
});
