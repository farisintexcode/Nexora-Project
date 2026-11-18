<?php

namespace App\Actions\Assignments;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\User;
use App\Services\Assignments\BatchWork;
use App\Services\AuditWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Plan.md M03 / Module.md M03 / UI11–12 / assignment_batches. */
class SaveAssignmentBatch
{
    public function __construct(private AuditWriter $audit, private BatchWork $work) {}

    /** The initial status applies to new assignments only; later status changes go through ChangeBatchStatus. */
    public function handle(User $actor, array $input, ?AssignmentBatch $existing = null, ?int $version = null, string $initialStatus = 'active'): AssignmentBatch
    {
        try {
            return DB::transaction(function () use ($actor, $input, $existing, $version, $initialStatus): AssignmentBatch {
                abort_unless($actor->isActive(), 404);
                $batch = $existing ? AssignmentBatch::query()->lockForUpdate()->findOrFail($existing->id) : new AssignmentBatch;
                Gate::forUser($actor)->authorize($existing ? 'update' : 'create', $existing ? $batch : AssignmentBatch::class);
                if ($existing && ($version === null || $batch->lock_version !== $version)) {
                    throw ValidationException::withMessages(['version' => 'This assignment changed. Reload and review before saving.']);
                }
                if ($existing && $batch->status !== BatchStatus::Active) {
                    throw ValidationException::withMessages(['status' => 'Archived assignments are read-only. Reopen under authorization first.']);
                }
                foreach (['name', 'external_reference', 'description', 'dca_code', 'dca_name', 'la_name', 'state_code'] as $field) {
                    if (is_string($input[$field] ?? null)) {
                        $input[$field] = trim($input[$field]);
                        if ($input[$field] === '' && $field !== 'name') {
                            $input[$field] = null;
                        }
                    }
                }
                $rules = [
                    'name' => ['required', 'string', 'max:200'],
                    'external_reference' => ['nullable', 'string', 'max:100', Rule::unique('assignment_batches')->ignore($batch->id)->withoutTrashed()],
                    'start_date' => ['required', 'date_format:Y-m-d'],
                    'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                    'description' => ['nullable', 'string', 'max:5000'],
                    'dca_code' => ['nullable', 'string', 'max:50'], 'dca_name' => ['nullable', 'string', 'max:200'], 'la_name' => ['nullable', 'string', 'max:200'],
                ];
                foreach (['type_code' => 'assignment_type', 'state_code' => 'state'] as $field => $category) {
                    $rules[$field] = [$field === 'type_code' ? 'required' : 'nullable', 'string', 'max:64', Rule::exists('reference_values', 'code')->where('category', $category)->where(fn ($q) => $q->when(! $batch->exists || ($input[$field] ?? null) !== $batch->{$field}, fn ($q) => $q->where('is_active', true)))];
                }
                if (! $existing && ! in_array($initialStatus, ['active', 'archived'], true)) {
                    throw ValidationException::withMessages(['status' => 'Choose active or archived.']);
                }
                $validator = Validator::make($input, $rules, ['due_date.after_or_equal' => 'The due date cannot be earlier than the assignment date.']);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(collect($validator->errors()->toArray())->mapWithKeys(fn ($messages, $key) => ['data.'.$key => $messages])->all());
                }
                $data = $validator->validated();
                if ($existing && $batch->type_code !== $data['type_code']) {
                    $work = $this->work->summary($batch);
                    if (($work['tasks'] ?? 0) + ($work['imports'] ?? 0) > 0) {
                        throw ValidationException::withMessages(['data.type_code' => 'Type cannot change after task or import history exists. Create a separate work package.']);
                    }
                }
                foreach ($data as $field => $value) {
                    $batch->{$field} = $value;
                }
                if (! $existing) {
                    $batch->created_by = $actor->id;
                    $batch->status = BatchStatus::from($initialStatus);
                    $batch->archived_at = $batch->status === BatchStatus::Archived ? now() : null;
                    $batch->lock_version = 0;
                } else {
                    $batch->lock_version++;
                }
                $batch->save();
                $this->audit->record($actor, $existing ? 'assignment_updated' : 'assignment_created', $batch->public_id, ['status' => $batch->status->value, 'changed_fields' => array_keys($data)], module: 'M03', entityType: 'assignment_batch');

                return $batch->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['data.external_reference' => 'An assignment with that external reference already exists.']);
        }
    }
}
