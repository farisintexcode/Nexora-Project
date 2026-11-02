<?php

namespace App\Actions\Tasks;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\FinancialSnapshot;
use App\Models\Task;
use App\Models\TaskReview;
use App\Models\TaskStatusHistory;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\TaskReviewed;
use App\Services\AuditWriter;
use App\Services\Tasks\SubmissionEvidence;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M07 / UI26,42. Explicit signed selection, immutable decisions, all-or-nothing bulk transaction. */
class ReviewSubmission
{
    public const MAX_SELECTION = 50;

    public function __construct(private SubmissionEvidence $evidence, private AuditWriter $audit) {}

    /**
     * @param  array<string, array{photo: bool, partial: bool}>  $paymentChecks  the reviewer's payment confirmations by submission reference
     */
    public function prepare(User $actor, array $selection, string $decision, ?string $reason, array $paymentChecks = []): array
    {
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('viewAny', TaskSubmission::class);
        $reason = trim($reason ?? '');
        Validator::make(compact('selection', 'decision', 'reason'), [
            'selection' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION], 'selection.*' => ['required', 'uuid', 'distinct'],
            'decision' => ['required', Rule::in(['approved', 'returned'])], 'reason' => [$decision === 'returned' ? 'required' : 'nullable', 'string', 'max:5000'],
        ])->validate();
        $records = $this->records($actor, $selection)->keyBy('public_id');
        $rows = [];
        $expected = [];
        sort($selection);
        foreach ($selection as $reference) {
            $submission = $records->get($reference);
            $issues = $submission ? $this->issues($actor, $submission, $decision, $paymentChecks) : ['access' => 'Submission unavailable or outside your current permissions.'];
            $rows[] = ['submission' => $reference, 'customer' => $submission?->completion_snapshot_ciphertext['customer']['name'] ?? 'Unavailable',
                'account' => $submission?->completion_snapshot_ciphertext['account'] ?? '—', 'version' => $submission?->submission_version, 'issues' => $issues];
            $expected[] = ['submission' => $reference, 'task_version' => $submission?->task->lock_version, 'submission_version' => $submission?->submission_version];
        }
        $preview = ['actor' => $actor->id, 'decision' => $decision, 'reason' => $reason, 'selection' => $expected, 'payments' => $paymentChecks, 'expires_at' => now()->addMinutes(20)->timestamp, 'rows' => $rows];
        $preview['digest'] = $this->signature($preview);

        return $preview;
    }

    public function confirm(User $actor, array $preview): array
    {
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('viewAny', TaskSubmission::class);
        if (($preview['actor'] ?? null) !== $actor->id || ! is_string($preview['digest'] ?? null) || ! hash_equals($this->signature($preview), $preview['digest']) || ($preview['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['preview' => 'This review preview is invalid or expired. Prepare it again.']);
        }

        return DB::transaction(function () use ($actor, $preview): array {
            $references = array_column($preview['selection'], 'submission');
            $records = $this->records($actor, $references);
            AssignmentBatch::query()->whereIn('id', $records->map(fn ($s) => $s->task->assignment_batch_id)->unique())->orderBy('id')->lockForUpdate()->get();
            Task::query()->whereIn('id', $records->pluck('task_id')->unique())->orderBy('id')->lockForUpdate()->get();
            TaskSubmission::query()->whereIn('id', $records->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            $actor = $actor->fresh();
            Gate::forUser($actor)->authorize('viewAny', TaskSubmission::class);
            $fresh = $this->records($actor, $references)->keyBy('public_id');
            $failures = [];
            $accepted = [];
            foreach ($preview['selection'] as $expected) {
                $submission = $fresh->get($expected['submission']);
                if (! $submission) {
                    $failures[$expected['submission']] = 'Unavailable or access changed.';

                    continue;
                }
                $existing = $submission->review;
                if ($existing && $existing->reviewer_id === $actor->id && $existing->decision === $preview['decision'] && ($existing->reason ?? '') === $preview['reason']) {
                    $accepted[] = ['submission' => $submission, 'review' => $existing];

                    continue;
                }
                $issues = $this->issues($actor, $submission, $preview['decision'], $preview['payments'] ?? []);
                if ($submission->task->lock_version !== $expected['task_version'] || $submission->submission_version !== $expected['submission_version']) {
                    $issues['version'] = 'Task or submission version changed.';
                }
                if ($issues) {
                    $failures[$submission->public_id] = implode(' ', $issues);
                } else {
                    $accepted[] = ['submission' => $submission, 'review' => null];
                }
            }
            if ($failures) {
                throw ValidationException::withMessages(['preview' => 'No decisions were applied. Refresh the affected rows and prepare a new preview.'] + collect($failures)->mapWithKeys(fn ($message, $id) => ['rows.'.$id => $message])->all());
            }
            $result = [];
            foreach ($accepted as $row) {
                $submission = $row['submission'];
                $task = $submission->task;
                if ($row['review']) {
                    $result[] = ['submission' => $submission->public_id, 'review' => $row['review']->public_id, 'status' => 'already_applied'];

                    continue;
                }
                $review = new TaskReview;
                $review->forceFill(['task_id' => $task->id, 'submission_id' => $submission->id, 'submission_version' => $submission->submission_version,
                    'reviewer_id' => $actor->id, 'decision' => $preview['decision'], 'reason' => $preview['reason'] !== '' ? $preview['reason'] : null, 'reviewed_at' => now(),
                    'supersedes_review_id' => $task->reviews()->latest('submission_version')->value('id')])->save();
                $from = $task->work_status;
                $oldReview = $task->review_status;
                $task->forceFill(['work_status' => $review->decision === 'approved' ? 'completed' : 'in_progress', 'review_status' => $review->decision,
                    'completed_at' => $review->decision === 'approved' ? now() : null, 'last_updated_by' => $actor->id, 'lock_version' => $task->lock_version + 1])->save();
                $history = new TaskStatusHistory;
                $history->forceFill(['task_id' => $task->id, 'actor_id' => $actor->id, 'event_code' => $review->decision, 'from_work_status' => $from, 'to_work_status' => $task->work_status,
                    'from_review_status' => $oldReview, 'to_review_status' => $task->review_status, 'submission_id' => $submission->id, 'allocation_id' => $task->currentAllocation?->id,
                    'reason' => 'Verification '.$review->public_id, 'occurred_at' => now()])->save();
                $claim = $review->decision === 'approved' ? $this->paymentClaim($submission) : null;
                if ($claim) {
                    FinancialSnapshot::record($task, $actor, 'payment', ['outstanding' => $claim['remaining'], 'monthly' => $claim['monthly'], 'paid' => $claim['amount'], 'submission_id' => $submission->id]);
                    $task->forceFill(['payment_update_flag' => true])->save();
                }
                $this->audit->record($actor, 'task_'.$review->decision, $task->public_id, ['status' => $task->work_status], module: 'M07', entityType: 'task', previous: ['status' => $from]);
                $recipient = $task->currentAllocation ? User::query()->where('staff_id', $task->currentAllocation->collector_id)->first() : null;
                if ($recipient) {
                    $recipient->notify(new TaskReviewed($review));
                }
                $result[] = ['submission' => $submission->public_id, 'review' => $review->public_id, 'status' => 'applied'];
            }

            return $result;
        }, 3);
    }

    /**
     * The frozen payment claim of a submission, in whole cents.
     *
     * @return array{amount: ?int, balance: ?int, monthly: ?int, remaining: ?int, state: string}|null null when no payment was claimed
     */
    public function paymentClaim(TaskSubmission $submission): ?array
    {
        $payment = $submission->completion_snapshot_ciphertext['payment'] ?? [];
        if (! ($payment['claimed'] ?? false)) {
            return null;
        }
        $amount = Money::cents($payment['amount'] ?? null);
        $balance = Money::cents($payment['balance'] ?? null);
        $state = match (true) {
            $amount === null => 'missing',
            $balance === null => 'no_balance',
            $amount > $balance => 'over',
            $amount < $balance => 'partial',
            default => 'full',
        };

        return ['amount' => $amount, 'balance' => $balance, 'monthly' => Money::cents($payment['monthly'] ?? null), 'remaining' => $amount !== null && $balance !== null ? max(0, $balance - $amount) : null, 'state' => $state];
    }

    private function records(User $actor, array $references): Collection
    {
        return TaskSubmission::query()->whereIn('public_id', $references)
            ->with(['task.batch', 'task.currentAllocation', 'review', 'documents.document'])->get();
    }

    private function issues(User $actor, TaskSubmission $submission, string $decision, array $paymentChecks = []): array
    {
        $issues = [];
        $task = $submission->task;
        if (! Gate::forUser($actor)->allows('decide', $submission)) {
            $issues['reviewer'] = 'An independent authorized reviewer is required.';
        }
        if ($submission->review) {
            $issues['decision'] = 'This submission has already been reviewed.';
        }
        if ($task->batch->status !== BatchStatus::Active) {
            $issues['batch'] = 'Assignment is archived.';
        }
        if ($task->work_status !== 'submitted' || $task->review_status !== 'pending' || $task->lock_version !== $submission->task_version ||
            $task->submissions()->where('submission_version', '>', $submission->submission_version)->exists()) {
            $issues['version'] = 'Only the current pending submission at its exact task version can be reviewed.';
        }
        if (($submission->completion_snapshot_ciphertext['allocation'] ?? null) !== $task->currentAllocation?->public_id) {
            $issues['allocation'] = 'The submitted allocation changed.';
        }
        if ($decision === 'returned') {
            $recipient = $task->currentAllocation ? User::query()->where('staff_id', $task->currentAllocation->collector_id)->first() : null;
            if (! $recipient || ! $recipient->canUseRiderInterface() || ! $recipient->can('tasks.view') || ! $recipient->can('tasks.submit')) {
                $issues['rider'] = 'The Rider needs active task access to receive a correction.';
            }
        }

        if ($decision === 'approved' && ($claim = $this->paymentClaim($submission))) {
            $check = $paymentChecks[$submission->public_id] ?? [];
            if (! ($check['photo'] ?? false)) {
                $issues['payment'] = 'Open this submission on its own and confirm the amount in the photo matches what the Rider entered.';
            } elseif ($claim['state'] === 'over') {
                $issues['payment'] = 'The amount claimed is more than the outstanding balance. Return it to the Rider to correct.';
            } elseif ($claim['state'] === 'partial' && ! ($check['partial'] ?? false)) {
                $issues['payment'] = 'This is less than the outstanding balance. Confirm you accept it as a part payment.';
            }
        }

        return $decision === 'approved' ? $issues + $this->evidence->issues($submission) : $issues;
    }

    private function signature(array $preview): string
    {
        return hash_hmac('sha256', json_encode(array_intersect_key($preview, array_flip(['actor', 'decision', 'reason', 'selection', 'payments', 'expires_at'])), JSON_THROW_ON_ERROR), config('app.key'));
    }
}
