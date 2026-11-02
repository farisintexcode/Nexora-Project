<?php

namespace App\Services\Tasks;

use App\Models\CollectionActivity;
use App\Models\Document;
use App\Models\PropertyInspection;
use App\Models\ReferenceValue;
use App\Models\Task;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/** M06 / UI51. The reviewed digest pins the exact draft, policy and private manifest. */
class CompletionChecklist
{
    public function policy(Task $task, string $purpose = 'site_visit'): ?array
    {
        $type = ReferenceValue::query()->where('category', 'assignment_type')->where('code', $task->type_code)->where('is_active', true)->first();
        $assignment = $type?->metadata['execution_policy'] ?? null;
        $reference = ReferenceValue::query()->where('category', 'visit_purpose')->where('code', $purpose)->where('is_active', true)->first();
        if (! is_array($assignment) || ! is_string($assignment['version'] ?? null) || trim($assignment['version']) === '' || ! is_array($assignment['purpose_codes'] ?? null) || ! in_array($purpose, $assignment['purpose_codes'], true) || ! $reference || ! is_array($reference->metadata) || ! is_array($assignment['overrides'][$purpose] ?? [])) {
            return null;
        }
        $policy = array_replace($reference->metadata ?? [], $assignment['overrides'][$purpose] ?? []);
        $policy['assignment_policy_version'] = $assignment['version'];
        $policy['purpose'] = $purpose;
        $policy['max_images'] = config('task_execution.max_images');
        if (! is_array($policy) || ! is_string($policy['version'] ?? null) || trim($policy['version']) === '' ||
            ! is_int($policy['backdate_calendar_days'] ?? null) || $policy['backdate_calendar_days'] < 0 || $policy['backdate_calendar_days'] > 1 ||
            ! is_int($policy['min_images'] ?? null) || $policy['min_images'] < 1 || $policy['min_images'] > config('task_execution.max_images') ||
            ! is_array($policy['required_categories'] ?? null) || ! is_array($policy['required_remarks'] ?? null) ||
            ! is_bool($policy['business_required'] ?? null) || ! is_bool($policy['signboard_when_available'] ?? null) ||
            array_diff($policy['required_remarks'], ['visit', 'collection', 'customer'])) {
            return null;
        }
        foreach ($policy['required_categories'] as $code) {
            if (! is_string($code) || ! ReferenceValue::query()->where('category', 'image_category')->where('code', $code)->where('is_active', true)->exists()) {
                return null;
            }
        }

        return $policy;
    }

    public function documents(Task $task, User $actor, ?int $activityId = null): Collection
    {
        return Document::query()->where('owner_type', 'task')->where('owner_id', $task->id)->where('activity_id', $activityId)->where('uploaded_by', $actor->id)->where('document_type_code', 'proof_image')->withExists('submissionDocuments')->orderBy('id')->get();
    }

    public function review(Task $task, User $actor): array
    {
        $visit = $task->activities()->where('performed_by', $actor->id)->where('activity_type_code', 'visit')->latest('id')->first();
        $policy = $this->policy($task, $visit?->visit_purpose_code ?? 'site_visit');
        $inspection = $visit?->inspection;
        if ($policy && $visit) {
            if ($this->claimsPayment($visit, $inspection)) {
                $policy['required_categories'][] = 'payment_evidence';
            }
            if (($policy['signboard_when_available'] ?? false) && $visit->signboard_available) {
                $policy['required_categories'][] = 'business_signboard';
            }
            $policy['business_required'] = $policy['business_required'] || $visit->business_applicable;
            $policy['required_categories'] = array_values(array_unique($policy['required_categories']));
        }
        $documents = $this->documents($task, $actor, $visit?->id);
        $clean = $documents->filter(fn (Document $document): bool => $document->isCleared());
        $issues = [];
        if ($inspection?->dr_code === 'DR02' && ! $visit->followUps()->where('status', '!=', 'cancelled')->exists()) {
            $issues['follow_up'] = 'Record a due follow-up for Promised To Pay.';
        }
        $paymentClaimed = $visit && $this->claimsPayment($visit, $inspection);
        if ($paymentClaimed && ! Money::cents($visit->paid_amount_ciphertext)) {
            $issues['payment'] = 'Enter the amount the customer paid.';
        }
        if (! $policy) {
            $issues['policy'] = 'An approved task-type completion policy is required (G06).';
        }
        if (! $visit) {
            $issues['visit'] = 'Record a site visit and outcome.';
        }
        if ($visit && $task->submissions()->get()->contains(fn ($s) => ($s->completion_snapshot_ciphertext['visit'] ?? null) === $visit->public_id) && $task->work_status === 'in_progress') {
            $issues['visit'] = 'Record a new correction visit; the previous version is immutable.';
        }
        if ($visit && ! $visit->outcome_code) {
            $issues['visit'] = 'Record a visit outcome.';
        }
        if ($visit && (! $visit->availability_code || ! $visit->visit_status_code || ! $visit->contact_result_code)) {
            $issues['visit'] = 'Record availability, site status and contact result.';
        }
        if (($policy['signboard_when_available'] ?? false) && $visit?->signboard_available === null) {
            $issues['visit'] = 'Confirm whether the business signboard is available.';
        }
        foreach ($policy['required_remarks'] ?? [] as $field) {
            if (! $visit || ! trim($visit->{$field.'_remarks_ciphertext'} ?? '')) {
                $issues['visit'] = 'Add the required '.$field.' remarks.';
            }
        }
        if (($policy['business_required'] ?? false) && (! $inspection || ! $inspection->business_type_code || ! $inspection->business_nature_code || ! $inspection->business_status_code)) {
            $issues['business'] = 'Record the required business details.';
        }
        $proofIssues = [];
        if ($documents->contains(fn ($d) => ! $d->isCleared())) {
            $proofIssues[] = 'Resolve pending, rejected or failed scans, or remove those draft images.';
        }
        if ($clean->count() < ($policy['min_images'] ?? 1) || $clean->count() > config('task_execution.max_images')) {
            $proofIssues[] = 'Clean images: '.$clean->count().'; required minimum: '.($policy['min_images'] ?? 1).'.';
        }
        $missing = array_diff($policy['required_categories'] ?? [], $clean->pluck('image_category_code')->all());
        if ($missing) {
            $proofIssues[] = 'Required proof: '.ReferenceValue::query()->where('category', 'image_category')->whereIn('code', $missing)->orderBy('code')->pluck('label')->implode(', ').'.';
        }
        if ($proofIssues) {
            $issues['proof'] = implode(' ', $proofIssues);
        }
        $balance = $task->latestBalance()->first();
        $snapshot = [
            'schema_version' => 1, 'policy' => $policy, 'task' => $task->public_id, 'allocation' => $task->currentAllocation?->public_id,
            'task_version' => $task->lock_version, 'visit' => $visit?->public_id,
            'visit_time' => $visit?->occurred_at?->toIso8601String(), 'outcome' => $visit?->outcome_code,
            'recorded_at' => $visit?->created_at?->toIso8601String(), 'backdating_reason' => $visit?->backdating_reason_ciphertext,
            'purpose' => $visit?->visit_purpose_code, 'business_applicable' => $visit?->business_applicable, 'signboard_available' => $visit?->signboard_available,
            'availability' => $visit?->availability_code, 'site_status' => $visit?->visit_status_code, 'contact_result' => $visit?->contact_result_code,
            'site_address' => $visit?->site_address_ciphertext, 'person_contacted' => $visit?->person_contacted_ciphertext,
            'remarks' => ['visit' => $visit?->visit_remarks_ciphertext, 'collection' => $visit?->collection_remarks_ciphertext, 'customer' => $visit?->customer_remarks_ciphertext],
            'business' => $inspection ? ['reference' => $inspection->public_id, 'type' => $inspection->business_type_code, 'nature' => $inspection->business_nature_code, 'status' => $inspection->business_status_code, 'other' => $inspection->business_other_ciphertext, 'name' => $inspection->shop_company_ciphertext, 'dr_code' => $inspection->dr_code, 'usage' => $inspection->usage_code, 'property_type' => $inspection->property_type_code, 'usage_other' => $inspection->usage_other] : null,
            'follow_ups' => $visit?->followUps()->orderBy('id')->get()->map(fn ($f) => ['reference' => $f->public_id, 'due' => $f->due_at->toIso8601String(), 'remarks' => $f->notes_ciphertext])->all() ?? [],
            'payment' => ['claimed' => (bool) $paymentClaimed, 'amount' => $visit?->paid_amount_ciphertext, 'balance' => $balance?->outstanding_balance_ciphertext, 'monthly' => $balance?->monthly_charge_ciphertext, 'balance_date' => $balance?->balance_date?->toDateString()],
            'customer' => ['reference' => $task->customer->customer_reference, 'name' => $task->customer->display_name_ciphertext],
            'account' => $task->account->account_number,
            'proof' => $documents->map(fn ($d) => ['id' => $d->id, 'reference' => $d->public_id, 'activity' => $d->activity_id, 'reference_time' => $d->created_at?->toIso8601String(), 'category' => $d->image_category_code, 'status' => $d->scan_status, 'sha256' => $d->sha256, 'bucket' => $d->bucket, 'key' => $d->object_key, 'version' => $d->version_id, 'caption' => $d->caption_ciphertext])->all(),
        ];
        $snapshot['lookup_labels'] = ReferenceValue::query()->whereIn('category', ['dr_code', 'property_usage', 'property_type', 'availability', 'visit_status', 'contact_result', 'activity_outcome', 'visit_purpose', 'business_type', 'business_nature', 'business_status', 'image_category'])->orderBy('category')->orderBy('code')->get(['category', 'code', 'label'])->map(fn ($r) => $r->only(['category', 'code', 'label']))->all();

        return ['issues' => $issues, 'snapshot' => $snapshot, 'digest' => hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), config('app.key')), 'visit' => $visit, 'documents' => $documents];
    }

    /** A typed amount counts as a claim even if the Rider forgot the outcome, so it can never skip the proof and review. */
    private function claimsPayment(CollectionActivity $visit, ?PropertyInspection $inspection): bool
    {
        return $visit->outcome_code === 'payment_claimed' || $inspection?->dr_code === 'DR01' || filled($visit->paid_amount_ciphertext);
    }
}
