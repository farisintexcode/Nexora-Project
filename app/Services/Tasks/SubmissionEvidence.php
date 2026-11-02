<?php

namespace App\Services\Tasks;

use App\Models\TaskSubmission;
use App\Support\Money;

/** M07 / UI42: assess the immutable submitted policy, never a later mutable draft or policy. */
class SubmissionEvidence
{
    public function issues(TaskSubmission $submission): array
    {
        $snapshot = $submission->completion_snapshot_ciphertext;
        $policy = $snapshot['policy'] ?? null;
        if (! is_array($snapshot) || ($snapshot['schema_version'] ?? null) !== 1 || ! is_array($policy) ||
            ! is_array($policy['required_categories'] ?? null) || ! is_array($policy['required_remarks'] ?? null) ||
            ! is_int($policy['min_images'] ?? null) || $policy['min_images'] < 1 || ! is_array($snapshot['proof'] ?? null)) {
            return ['snapshot' => 'The saved completion snapshot or policy is incomplete.'];
        }
        $issues = [];
        if (($snapshot['task'] ?? null) !== $submission->task->public_id || empty($snapshot['visit']) || empty($snapshot['visit_time']) || empty($snapshot['outcome']) ||
            empty($snapshot['availability']) || empty($snapshot['site_status']) || empty($snapshot['contact_result'])) {
            $issues['visit'] = 'Submitted visit context is incomplete.';
        }
        foreach ($policy['required_remarks'] as $field) {
            if (! is_string($field) || ! trim($snapshot['remarks'][$field] ?? '')) {
                $issues['remarks'] = 'Required submitted remarks are missing.';
            }
        }
        if (($policy['business_required'] ?? false) && (! isset($snapshot['business']) || empty($snapshot['business']['type']) || empty($snapshot['business']['nature']) || empty($snapshot['business']['status']))) {
            $issues['business'] = 'Required submitted business details are missing.';
        }
        if (($snapshot['payment']['claimed'] ?? false) && ! Money::cents($snapshot['payment']['amount'] ?? null)) {
            $issues['payment'] = 'The submitted payment claim has no amount.';
        }
        $submission->loadMissing('documents.document');
        $manifest = $submission->documents;
        if ($manifest->count() !== count($snapshot['proof']) || $manifest->count() < $policy['min_images'] || $manifest->count() > ($policy['max_images'] ?? 10)) {
            $issues['proof'] = 'The frozen proof manifest is incomplete.';
        }
        $frozen = collect($snapshot['proof'])->keyBy('id');
        foreach ($manifest as $item) {
            $document = $item->document;
            $entry = $frozen->get($item->document_id);
            if (! $document || ! is_array($entry) || ! $document->isCleared() || $document->owner_type !== 'task' || $document->owner_id !== $submission->task_id ||
                $document->uploaded_by !== $submission->submitted_by || $document->sha256 !== ($entry['sha256'] ?? null) || $document->public_id !== ($entry['reference'] ?? null) ||
                $document->bucket !== ($entry['bucket'] ?? null) || $document->object_key !== ($entry['key'] ?? null) || $document->version_id !== ($entry['version'] ?? null) ||
                $document->activity_id !== ($entry['activity'] ?? null) || $document->image_category_code !== $item->category_code || $item->category_code !== ($entry['category'] ?? null)) {
                $issues['proof'] = 'Submitted evidence is unavailable, changed or outside this submission.';
            }
        }
        if (array_diff($policy['required_categories'], $manifest->pluck('category_code')->all())) {
            $issues['categories'] = 'Required submitted proof categories are missing.';
        }

        return $issues;
    }
}
