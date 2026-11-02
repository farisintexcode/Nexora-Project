<?php

namespace App\Actions\Tasks;

use App\Models\AssignmentBatch;
use App\Models\CollectionActivity;
use App\Models\FollowUp;
use App\Models\PropertyInspection;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Tasks\CompletionChecklist;
use App\Services\Tasks\RiderAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M06 / UI48–51 / canonical inspections, activities, follow_ups and submissions.
 * Bounded synchronous batches freeze explicit selections and preserve immutable history.
 */
class RiderBatchUpdate
{
    public const OPERATIONS = ['dr_code' => 'DR Code', 'business_nature' => 'Nature of Business', 'property_type' => 'Type of Property', 'usage' => 'Property Usage', 'visit_date' => 'Visit Date', 'reminder' => 'Update Reminder', 'submit' => 'Complete Status · submit for verification'];

    public const CATEGORIES = ['dr_code' => 'dr_code', 'business_nature' => 'business_nature', 'property_type' => 'property_type', 'usage' => 'property_usage'];

    public function __construct(private RiderAccess $access, private RiderWork $work, private CompletionChecklist $checklist, private AuditWriter $audit) {}

    public function preview(User $actor, AssignmentBatch $batch, array $input): array
    {
        $actor = $actor->fresh();
        Gate::forUser($actor)->authorize('use-rider-interface');
        abort_unless($actor->can('tasks.submit'), 403);
        $data = $this->validate($input);
        $tasks = $this->access->scope(Task::query(), $actor)->where('assignment_batch_id', $batch->id)->whereIn('public_id', $data['selection'])->with(['customer', 'account', 'batch', 'currentAllocation'])->orderBy('id')->get();
        if ($tasks->count() !== count($data['selection'])) {
            throw ValidationException::withMessages(['selection' => 'Some selected tasks are unavailable. Reload your current allocation.']);
        }
        $rows = [];
        foreach ($tasks as $task) {
            $visit = $this->visit($actor, $task);
            $issues = [];
            if ($batch->status->value !== 'active' || $task->work_status !== 'in_progress' || $task->review_status !== 'none' || ! $actor->can('execute', $task)) {
                $issues[] = 'Start this task or its correction first. Submitted and completed work is read-only.';
            }
            if (! $visit || $task->submissions()->get()->contains(fn ($submission) => ($submission->completion_snapshot_ciphertext['visit'] ?? null) === $visit->public_id)) {
                $issues[] = 'Save a new visit draft first.';
            }
            if ($data['operation'] === 'business_nature' && (! $visit?->inspection?->business_type_code || ! $visit?->inspection?->business_status_code)) {
                $issues[] = 'Save business type and status in this task before changing its nature.';
            }
            $review = $this->checklist->review($task, $actor);
            if ($data['operation'] === 'submit') {
                $issues = array_merge($issues, array_values($review['issues']));
            }
            $column = $data['operation'] === 'dr_code' ? 'dr_code' : $data['operation'].'_code';
            $before = match ($data['operation']) {
                'submit' => ucfirst(str_replace('_', ' ', $task->work_status)),
                'visit_date' => $visit?->occurred_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i'),
                'reminder' => $visit?->followUps()->where('assigned_to', $actor->id)->where('status', 'pending')->latest('id')->first()?->due_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i'),
                default => $visit?->inspection?->{$column},
            };
            $summary = $data['operation'] === 'submit' ? array_intersect_key($review['snapshot'], array_flip(['visit_time', 'outcome', 'remarks', 'business'])) : [];
            $proof = $data['operation'] === 'submit' ? $review['documents']->map(fn ($document) => ['reference' => $document->public_id, 'label' => $document->proofReference(), 'category' => $document->image_category_code, 'status' => $document->scan_status])->all() : [];
            $rows[] = ['task' => $task->public_id, 'version' => $task->lock_version, 'customer' => $task->customer->display_name_ciphertext, 'account' => $task->account->account_number, 'visit' => $visit?->public_id, 'inspection' => $visit?->inspection?->public_id, 'before' => $before, 'after' => $data['operation'] === 'submit' ? 'Pending Verification' : $data['value'], 'digest' => $review['digest'], 'summary' => $summary, 'proof' => $proof, 'issues' => array_values(array_unique($issues))];
        }
        $preview = ['actor' => $actor->id, 'batch' => $batch->public_id, 'request' => (string) Str::uuid7(), 'expires_at' => now()->addMinutes(15)->timestamp, 'input' => $data, 'rows' => $rows];
        $preview['signature'] = $this->signature($preview);

        return $preview;
    }

    public function apply(User $actor, array $preview): int
    {
        if (($preview['actor'] ?? null) !== $actor->id || ! is_string($preview['signature'] ?? null) || ! hash_equals($this->signature($preview), $preview['signature']) || ($preview['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['preview' => 'This preview expired or changed. Preview the selection again.']);
        }

        return DB::transaction(function () use ($actor, $preview): int {
            $actor = $actor->fresh();
            $context = AssignmentBatch::query()->where('public_id', $preview['batch'])->firstOrFail();
            $batch = AssignmentBatch::query()->whereKey($context->id)->lockForUpdate()->firstOrFail();
            $tasks = $this->access->scope(Task::query(), $actor)->where('assignment_batch_id', $batch->id)->whereIn('public_id', $preview['input']['selection'])->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            $fresh = $this->preview($actor, $batch, $preview['input']);
            if ($fresh['rows'] !== $preview['rows'] || collect($fresh['rows'])->contains(fn ($row) => $row['issues'] !== [])) {
                throw ValidationException::withMessages(['preview' => 'A task, draft, proof or permission changed, or a task is blocked. Nothing was applied. Preview again.']);
            }
            $data = $fresh['input'];
            foreach ($fresh['rows'] as $row) {
                $task = $this->access->locked($actor, $tasks->get($row['task']), $row['version']);
                $visit = $this->visit($actor, $task);
                if ($data['operation'] === 'submit') {
                    $this->work->submit($actor, $task, $task->lock_version, (string) Str::uuid7(), $row['digest']);

                    continue;
                }
                if ($data['operation'] === 'reminder') {
                    foreach ($visit->followUps()->where('assigned_to', $actor->id)->where('status', 'pending')->lockForUpdate()->get() as $previous) {
                        $previous->forceFill(['status' => 'cancelled', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_ciphertext' => $data['reason'], 'lock_version' => $previous->lock_version + 1])->save();
                    }
                    $follow = new FollowUp;
                    $follow->forceFill(['task_id' => $task->id, 'customer_id' => $task->customer_id, 'activity_id' => $visit->id, 'assigned_to' => $actor->id, 'type_code' => 'reminder', 'due_at' => Carbon::createFromFormat('!Y-m-d\TH:i', $data['value'], config('dcms.business_timezone'))->utc(), 'notes_ciphertext' => $data['reason']])->save();
                } elseif ($data['operation'] === 'visit_date') {
                    $this->work->visit($actor, $task, $task->lock_version, $this->visitInput($visit, $data), (string) Str::uuid7());

                    continue;
                } else {
                    $inspection = $visit->inspection?->replicate(['public_id']) ?? new PropertyInspection;
                    $column = $data['operation'] === 'dr_code' ? 'dr_code' : $data['operation'].'_code';
                    $inspection->forceFill(['task_id' => $task->id, 'property_id' => $task->account->property_id, 'activity_id' => $visit->id, 'observed_at' => $visit->occurred_at, $column => $data['value']]);
                    if ($data['value'] === 'other' && $data['operation'] === 'business_nature') {
                        $inspection->business_other_ciphertext = $data['reason'];
                    }
                    if ($data['value'] === 'other' && $data['operation'] === 'usage') {
                        $inspection->usage_other = $data['reason'];
                    }
                    $inspection->save();
                }
                $this->work->change($actor, $task, 'rider_batch_updated', 'in_progress', 'none');
            }
            $this->audit->record($actor, 'rider_batch_applied', $batch->public_id, ['changed_fields' => [$data['operation']]], $data['reason'], module: 'M06', entityType: 'assignment_batch');

            return count($fresh['rows']);
        }, 3);
    }

    private function validate(array $input): array
    {
        $operation = is_string($input['operation'] ?? null) ? $input['operation'] : '';
        $rules = ['operation' => ['required', Rule::in(array_keys(self::OPERATIONS))], 'selection' => ['required', 'array', 'list', 'min:1', 'max:50'], 'selection.*' => ['required', 'uuid', 'distinct'], 'value' => ['nullable', 'string', 'max:100'], 'reason' => ['required', 'string', 'min:3', 'max:2000']];
        if (isset(self::CATEGORIES[$operation])) {
            $rules['value'] = ['required', 'string', 'max:64', Rule::exists('reference_values', 'code')->where('category', self::CATEGORIES[$operation])->where('is_active', true)];
        }
        if (in_array($operation, ['visit_date', 'reminder'], true)) {
            $rules['value'] = ['required', 'date_format:Y-m-d\TH:i'];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['value'] = $data['value'] ?? '';
        if (in_array($operation, ['visit_date', 'reminder'], true)) {
            $time = Carbon::createFromFormat('!Y-m-d\TH:i', $data['value'], config('dcms.business_timezone'))->utc();
            if (($operation === 'reminder' && $time->lte(now())) || ($operation === 'visit_date' && ($time->gt(now()) || $time->lt(now()->timezone(config('dcms.business_timezone'))->startOfDay()->subDay()->utc())))) {
                throw ValidationException::withMessages(['value' => $operation === 'reminder' ? 'Choose a future reminder time.' : 'Visit time must be today or yesterday in MYT, never in the future.']);
            }
        }

        return $data;
    }

    private function visit(User $actor, Task $task): ?CollectionActivity
    {
        return $task->activities()->where('activity_type_code', 'visit')->where('performed_by', $actor->id)->with('inspection')->latest('id')->first();
    }

    private function visitInput(CollectionActivity $visit, array $data): array
    {
        $input = ['purpose' => $visit->visit_purpose_code, 'business_applicable' => $visit->business_applicable, 'signboard_available' => $visit->signboard_available, 'occurred_at' => $data['value'], 'backdating_reason' => $data['reason']];
        foreach (['site_address', 'person_contacted', 'visit_remarks', 'collection_remarks', 'customer_remarks'] as $field) {
            $input[$field] = $visit->{$field.'_ciphertext'};
        }
        foreach (['availability', 'visit_status', 'contact_result', 'outcome'] as $field) {
            $input[$field] = $visit->{$field.'_code'};
        }
        foreach (['business_type', 'business_nature', 'business_status', 'usage', 'property_type'] as $field) {
            $input[$field] = $visit->inspection?->{$field.'_code'};
        }
        $input['dr_code'] = $visit->inspection?->dr_code;
        $input['paid_amount'] = $visit->paid_amount_ciphertext;
        $input['usage_other'] = $visit->inspection?->usage_other;
        $input['shop_name'] = $visit->inspection?->shop_company_ciphertext;
        $input['business_other'] = $visit->inspection?->business_other_ciphertext;
        $follow = $visit->followUps()->where('status', 'pending')->where('due_at', '>', now())->latest('id')->first();
        $input['follow_up_due'] = $follow?->due_at?->timezone(config('dcms.business_timezone'))->format('Y-m-d\TH:i');
        $input['follow_up_remarks'] = $follow?->notes_ciphertext;

        return $input;
    }

    private function signature(array $preview): string
    {
        unset($preview['signature']);

        return hash_hmac('sha256', json_encode($preview, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
