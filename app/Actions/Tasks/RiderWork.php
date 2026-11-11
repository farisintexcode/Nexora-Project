<?php

namespace App\Actions\Tasks;

use App\Models\CollectionActivity;
use App\Models\FollowUp;
use App\Models\PropertyInspection;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\TaskSubmission;
use App\Models\TaskSubmissionDocument;
use App\Models\User;
use App\Notifications\TaskSubmitted;
use App\Services\AuditWriter;
use App\Services\Tasks\CompletionChecklist;
use App\Services\Tasks\RiderAccess;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M06 / Module.md confirmed Rider work; UI22,50–52 / canonical activity and submissions. */
class RiderWork
{
    public function __construct(private RiderAccess $access, private CompletionChecklist $checklist, private AuditWriter $audit) {}

    public function start(User $actor, Task $context, int $version): Task
    {
        return DB::transaction(function () use ($actor, $context, $version): Task {
            $task = $this->access->locked($actor, $context, null);
            // A duplicate start has no second history event, even after the version advanced.
            if ($task->work_status === 'in_progress' && $task->review_status !== 'returned') {
                return $task;
            }
            if ($task->lock_version !== $version) {
                throw ValidationException::withMessages(['version' => 'This task changed. Reload and review.']);
            }
            $this->change($actor, $task, 'started', 'in_progress', 'none');

            return $task->refresh();
        }, 3);
    }

    public function visit(User $actor, Task $context, int $version, array $input, string $key): CollectionActivity
    {
        Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $context, $version, $input, $key): CollectionActivity {
            $task = $this->access->locked($actor, $context, null, false);
            if ($old = CollectionActivity::query()->where('idempotency_key', $key)->first()) {
                abort_unless($old->task_id === $task->id && $old->performed_by === $actor->id, 404);

                return $old;
            }
            $task = $this->access->locked($actor, $task, $version);
            if ($task->work_status !== 'in_progress' || $task->review_status === 'returned') {
                throw ValidationException::withMessages(['status' => 'Start this task or its correction before recording a visit.']);
            }
            $rules = ['occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'ended_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'purpose' => ['required', Rule::exists('reference_values', 'code')->where('category', 'visit_purpose')->where('is_active', true)],
                'backdating_reason' => ['nullable', 'string', 'max:3000'], 'business_applicable' => ['required', 'boolean'], 'signboard_available' => ['nullable', 'boolean'],
                'site_address' => ['required', 'string', 'max:3000'], 'person_contacted' => ['nullable', 'string', 'max:255'],
                'follow_up_due' => ['nullable', 'date_format:Y-m-d\TH:i'], 'follow_up_remarks' => ['nullable', 'string', 'max:5000'],
                'shop_name' => ['nullable', 'string', 'max:255'], 'business_other' => ['nullable', 'string', 'max:3000']];
            foreach (['availability' => 'availability', 'visit_status' => 'visit_status', 'contact_result' => 'contact_result', 'outcome' => 'activity_outcome'] as $field => $category) {
                $rules[$field] = ['required', Rule::exists('reference_values', 'code')->where('category', $category)->where('is_active', true)];
            }
            foreach (['visit', 'collection', 'customer'] as $field) {
                $rules[$field.'_remarks'] = ['nullable', 'string', 'max:5000'];
            }
            $policy = $this->checklist->policy($task, is_string($input['purpose'] ?? null) ? $input['purpose'] : 'site_visit');
            if (! $policy) {
                throw ValidationException::withMessages(['purpose' => 'An approved task-type policy is required for this visit purpose.']);
            }
            foreach (['business_type', 'business_nature', 'business_status'] as $field) {
                $rules[$field] = [(($policy['business_required'] ?? false) || ($input['business_applicable'] ?? false)) ? 'required' : 'nullable', Rule::exists('reference_values', 'code')->where('category', $field)->where('is_active', true)];
            }
            foreach (['dr_code' => 'dr_code', 'usage' => 'property_usage', 'property_type' => 'property_type'] as $field => $category) {
                $rules[$field] = ['nullable', Rule::exists('reference_values', 'code')->where('category', $category)->where('is_active', true)];
            }
            $rules['usage_other'] = ['nullable', 'string', 'max:2000'];
            $rules['paid_amount'] = ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! Money::cents($value)) {
                    $fail('Enter the amount paid, for example 1234.50.');
                }
            }];
            if (($input['signboard_available'] ?? '') === '') {
                $input['signboard_available'] = null;
            }
            foreach (['business_type', 'business_nature', 'business_status', 'dr_code', 'usage', 'property_type'] as $field) {
                if (($input[$field] ?? null) === '') {
                    $input[$field] = null;
                }
            }
            $data = Validator::make($input, $rules)->validate();
            $outcome = ReferenceValue::query()->where('category', 'activity_outcome')->where('code', $data['outcome'])->firstOrFail();
            if (isset($outcome->metadata['activity_types']) && ! in_array('visit', $outcome->metadata['activity_types'], true)) {
                throw ValidationException::withMessages(['outcome' => 'Choose an approved site visit outcome.']);
            }
            if (($policy['signboard_when_available'] ?? false) && ! array_key_exists('signboard_available', $data)) {
                throw ValidationException::withMessages(['signboard_available' => 'Confirm whether the business signboard is available.']);
            }
            if (($policy['signboard_when_available'] ?? false) && $data['signboard_available'] === null) {
                throw ValidationException::withMessages(['signboard_available' => 'Confirm whether the business signboard is available.']);
            }
            foreach ($policy['required_remarks'] as $field) {
                if (! trim($data[$field.'_remarks'] ?? '')) {
                    throw ValidationException::withMessages([$field.'_remarks' => 'These remarks are required for the selected visit purpose.']);
                }
            }
            $selected = array_filter([$data['business_type'] ?? null, $data['business_nature'] ?? null, $data['business_status'] ?? null]);
            if (count($selected) > 0 && count($selected) !== 3) {
                throw ValidationException::withMessages(['business_type' => 'Select business type, nature and status together.']);
            }
            if (in_array('other', $selected, true) && ! trim($data['business_other'] ?? '')) {
                throw ValidationException::withMessages(['business_other' => 'Explain the Other selection.']);
            }
            if (trim($data['follow_up_remarks'] ?? '') && empty($data['follow_up_due'])) {
                throw ValidationException::withMessages(['follow_up_due' => 'Choose a due time for this follow-up.']);
            }
            if (($data['contact_result'] === 'call_back_requested' || in_array($data['outcome'], ['promise_to_pay', 'follow_up_required'], true)) && empty($data['follow_up_due'])) {
                throw ValidationException::withMessages(['follow_up_due' => 'A due follow-up is required for this contact result or outcome.']);
            }
            $time = empty($data['occurred_at']) ? now() : Carbon::createFromFormat('!Y-m-d\TH:i', $data['occurred_at'], config('dcms.business_timezone'))->utc();
            $earliest = now()->timezone(config('dcms.business_timezone'))->startOfDay()->subDays($policy['backdate_calendar_days'])->utc();
            if ($time->lt($earliest) || $time->gt(now())) {
                throw ValidationException::withMessages(['occurred_at' => 'Visit time must be today or yesterday (MYT), never in the future.']);
            }
            $backdated = $time->lt(now()->startOfMinute());
            if ($backdated && ! trim($data['backdating_reason'] ?? '')) {
                throw ValidationException::withMessages(['backdating_reason' => 'A Backdating Reason is required for an earlier visit time.']);
            }
            $end = empty($data['ended_at']) ? null : Carbon::createFromFormat('!Y-m-d\TH:i', $data['ended_at'], config('dcms.business_timezone'))->utc();
            if ($end && ($end->lt($time) || $end->gt(now()))) {
                throw ValidationException::withMessages(['ended_at' => 'End time must be after the visit and not in the future.']);
            }
            $activity = new CollectionActivity;
            $values = ['task_id' => $task->id, 'customer_id' => $task->customer_id, 'performed_by' => $actor->id, 'activity_type_code' => 'visit', 'occurred_at' => $time, 'ended_at' => $end, 'idempotency_key' => $key, 'visit_purpose_code' => $data['purpose'], 'business_applicable' => $data['business_applicable'], 'signboard_available' => $data['signboard_available'] ?? null, 'backdating_reason_ciphertext' => $backdated ? trim($data['backdating_reason']) : null];
            foreach (['availability', 'visit_status', 'contact_result', 'outcome'] as $field) {
                $values[$field.'_code'] = $data[$field];
            }
            foreach (['site_address', 'person_contacted', 'visit_remarks', 'collection_remarks', 'customer_remarks'] as $field) {
                $values[$field.'_ciphertext'] = trim($data[$field] ?? '') ?: null;
            }
            $values['paid_amount_ciphertext'] = filled($data['paid_amount'] ?? null) ? Money::plain(Money::cents($data['paid_amount'])) : null;
            $activity->forceFill($values)->save();
            if ($backdated) {
                $this->audit->record($actor, 'visit_backdated', $activity->public_id, ['status' => 'backdated'], module: 'M06', entityType: 'activity');
            }
            if (count($selected) === 3 || ! empty($data['dr_code']) || ! empty($data['usage']) || ! empty($data['property_type'])) {
                $inspection = new PropertyInspection;
                $inspection->forceFill(['task_id' => $task->id, 'activity_id' => $activity->id, 'property_id' => $task->account->property_id, 'observed_at' => $time, 'business_type_code' => $data['business_type'] ?? null, 'business_nature_code' => $data['business_nature'] ?? null, 'business_status_code' => $data['business_status'] ?? null, 'dr_code' => $data['dr_code'] ?? null, 'usage_code' => $data['usage'] ?? null, 'property_type_code' => $data['property_type'] ?? null, 'usage_other' => $data['usage_other'] ?? null, 'business_other_ciphertext' => trim($data['business_other'] ?? '') ?: null, 'shop_company_ciphertext' => trim($data['shop_name'] ?? '') ?: null])->save();
            }
            if (! empty($data['follow_up_due'])) {
                $due = Carbon::createFromFormat('!Y-m-d\TH:i', $data['follow_up_due'], config('dcms.business_timezone'))->utc();
                if ($due->lte(now())) {
                    throw ValidationException::withMessages(['follow_up_due' => 'Follow-up must be in the future.']);
                }
                $follow = new FollowUp;
                $follow->forceFill(['task_id' => $task->id, 'activity_id' => $activity->id, 'customer_id' => $task->customer_id, 'assigned_to' => $actor->id, 'due_at' => $due, 'type_code' => 'reminder', 'notes_ciphertext' => trim($data['follow_up_remarks'] ?? '') ?: null])->save();
            }
            $this->change($actor, $task, 'visited', 'in_progress', 'none');

            return $activity->refresh();
        }, 3);
    }

    public function submit(User $actor, Task $context, int $version, string $key, string $digest): TaskSubmission
    {
        Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $context, $version, $key, $digest): TaskSubmission {
            $task = $this->access->locked($actor, $context, null, false);
            if ($old = TaskSubmission::query()->where('idempotency_key', $key)->first()) {
                abort_unless($old->task_id === $task->id && $old->submitted_by === $actor->id, 404);

                return $old;
            }
            $task = $this->access->locked($actor, $task, $version);
            if ($task->work_status !== 'in_progress' || $task->review_status === 'returned') {
                throw ValidationException::withMessages(['status' => 'Start work before submitting.']);
            }
            $review = $this->checklist->review($task, $actor);
            if ($review['issues']) {
                throw ValidationException::withMessages($review['issues']);
            }
            if (! hash_equals($review['digest'], $digest)) {
                throw ValidationException::withMessages(['version' => 'The draft or evidence changed. Review completion again.']);
            }
            $submission = new TaskSubmission;
            $submission->forceFill(['task_id' => $task->id, 'submitted_by' => $actor->id, 'submitted_at' => now(), 'submission_version' => 1 + (int) $task->submissions()->max('submission_version'), 'task_version' => $task->lock_version + 1, 'completion_snapshot_ciphertext' => $review['snapshot'], 'idempotency_key' => $key])->save();
            foreach ($review['documents'] as $document) {
                $link = new TaskSubmissionDocument;
                $link->forceFill(['submission_id' => $submission->id, 'document_id' => $document->id, 'category_code' => $document->image_category_code])->save();
            }
            $task->submitted_at = now();
            $this->change($actor, $task, 'submitted', 'submitted', 'pending', $submission->id);
            $this->notifyVerifiers($actor, $task, $submission);

            return $submission;
        }, 3);
    }

    /** Active verifiers other than the submitter who can open this submission. */
    private function notifyVerifiers(User $actor, Task $task, TaskSubmission $submission): void
    {
        $riderName = $actor->staff?->full_name ?: $actor->name;
        foreach (User::permission('tasks.verify')->where('id', '<>', $actor->id)->get() as $verifier) {
            if ($verifier->isActive() && $verifier->can('admin.access') && Gate::forUser($verifier)->allows('view', $submission)) {
                $verifier->notify(new TaskSubmitted($task->public_id, $submission->public_id, $riderName));
            }
        }
    }

    public function change(User $actor, Task $task, string $event, string $work, string $review, ?int $submission = null): void
    {
        $from = $task->work_status;
        $oldReview = $task->review_status;
        $task->forceFill(['work_status' => $work, 'review_status' => $review, 'lock_version' => $task->lock_version + 1, 'last_updated_by' => $actor->id])->save();
        $history = new TaskStatusHistory;
        $history->forceFill(['task_id' => $task->id, 'actor_id' => $actor->id, 'event_code' => $event, 'from_work_status' => $from, 'to_work_status' => $work, 'from_review_status' => $oldReview, 'to_review_status' => $review, 'allocation_id' => $task->currentAllocation?->id, 'submission_id' => $submission, 'occurred_at' => now()])->save();
        $this->audit->record($actor, 'task_'.$event, $task->public_id, ['status' => $work], module: 'M06', entityType: 'task');
    }
}
