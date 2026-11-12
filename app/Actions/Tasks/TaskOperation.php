<?php

namespace App\Actions\Tasks;

use App\Actions\Assignments\ArchiveFinishedBatch;
use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\BulkOperationItem;
use App\Models\OutboxEvent;
use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskAllocation;
use App\Models\User;
use App\Notifications\TasksAssigned;
use App\Services\AuditWriter;
use App\Services\Tasks\TaskHistory;
use App\Services\Tasks\TaskIdSelection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M05 / UI16–18: frozen exact selection, per-row authorization and replay-safe mutations. */
class TaskOperation
{
    public function authorize(User $actor, AssignmentBatch $batch, string $operation): void
    {
        Gate::forUser($actor)->authorize('viewAny', Task::class);
        abort_unless($actor->isActive(), 404);
        $permission = match ($operation) {
            'new' => 'tasks.assign','replace','reassign','unassign' => 'tasks.reassign','cancel' => 'tasks.cancel',default => null
        };
        abort_unless($permission && $actor->can($permission), 403);
        if ($batch->status !== BatchStatus::Active) {
            throw ValidationException::withMessages(['operation' => 'Archived batches are read-only.']);
        }
    }

    public function prepare(User $actor, array $input): BulkOperation
    {
        $data = Validator::make($input, ['assignment_batch_id' => ['required', 'integer'], 'operation_code' => ['required', Rule::in(['new', 'replace', 'reassign', 'unassign', 'cancel'])], 'selection_mode' => ['required', Rule::in(array_keys(TaskIdSelection::MODES))], 'task_ids' => ['required', 'array', 'min:1', 'max:200'], 'task_ids.*' => ['integer', 'distinct'], 'target_collector_id' => ['nullable', 'required_unless:operation_code,unassign,cancel', 'integer'], 'reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();
        $context = AssignmentBatch::query()->findOrFail($data['assignment_batch_id']);

        return DB::transaction(function () use ($actor, $data, $context): BulkOperation {
            $batch = AssignmentBatch::query()->lockForUpdate()->findOrFail($context->id);
            $this->authorize($actor, $batch, $data['operation_code']);
            if ($data['selection_mode'] === 'single' && count($data['task_ids']) !== 1) {
                throw ValidationException::withMessages(['operation' => 'Single-task mode requires exactly one task.']);
            }
            $target = in_array($data['operation_code'], ['unassign', 'cancel'], true) ? null : $this->rider((int) $data['target_collector_id']);
            $tasks = Task::query()->where('assignment_batch_id', $batch->id)->whereIn('id', $data['task_ids'])->with('currentAllocation')->orderBy('id')->lockForUpdate()->get();
            if ($tasks->count() !== count($data['task_ids'])) {
                abort(404);
            }
            $criteria = $tasks->map(fn (Task $task) => [$task->id, $task->lock_version, $task->currentAllocation?->id])->all();
            $operation = new BulkOperation;
            $operation->forceFill(['requested_by' => $actor->id, 'assignment_batch_id' => $batch->id, 'operation_code' => $data['operation_code'], 'selection_mode' => $data['selection_mode'], 'target_collector_id' => $target?->id, 'reason' => trim($data['reason']), 'preview_digest' => hash('sha256', json_encode([$batch->id, $data['operation_code'], $target?->id, trim($data['reason']), $criteria], JSON_THROW_ON_ERROR)), 'idempotency_key' => (string) Str::uuid7(), 'status' => 'previewed'])->save();
            foreach ($tasks as $task) {
                $code = $this->exclusion($task, $operation->operation_code, $target?->id);
                $item = new BulkOperationItem;
                $item->forceFill(['bulk_operation_id' => $operation->id, 'task_id' => $task->id, 'expected_version' => $task->lock_version, 'state' => $code ? 'excluded' : 'pending', 'result_code' => $code])->save();
            }
            $this->counts($operation);
            app(AuditWriter::class)->record($actor, 'task_operation_previewed', $operation->public_id, ['status' => 'previewed'], module: 'M05', entityType: 'bulk_operation');

            return $operation->refresh();
        }, 3);
    }

    public function confirm(User $actor, BulkOperation $original, string $digest): void
    {
        $this->locked($original->id, function (BulkOperation $operation, AssignmentBatch $batch) use ($actor, $digest): void {
            $this->authorize($actor->fresh(), $batch, $operation->operation_code);
            abort_unless($actor->id === $operation->requested_by, 403);
            if (in_array($operation->status, ['queued', 'running', 'completed'], true)) {
                return;
            }
            if ($operation->status !== 'previewed' || ! hash_equals($operation->preview_digest, $digest)) {
                throw ValidationException::withMessages(['operation' => 'This preview is no longer current. Prepare a new selection.']);
            }
            if ($operation->target_collector_id) {
                $this->rider($operation->target_collector_id);
            }
            $selection = $operation->items()->with('task.currentAllocation')->orderBy('task_id')->get();
            $criteria = $selection->map(fn ($item) => [$item->task_id, $item->task->lock_version, $item->task->currentAllocation?->id])->all();
            $currentDigest = hash('sha256', json_encode([$batch->id, $operation->operation_code, $operation->target_collector_id, $operation->reason, $criteria], JSON_THROW_ON_ERROR));
            if (! hash_equals($operation->preview_digest, $currentDigest)) {
                throw ValidationException::withMessages(['operation' => 'The exact selection or its allocation changed. Prepare a fresh preview.']);
            }
            $items = $operation->items()->where('state', 'pending')->orderBy('task_id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['operation' => 'No eligible tasks were selected. Review exclusions.']);
            }
            foreach ($items as $item) {
                $task = Task::query()->lockForUpdate()->findOrFail($item->task_id);
                if ($task->lock_version !== $item->expected_version || $this->exclusion($task, $operation->operation_code, $operation->target_collector_id)) {
                    throw ValidationException::withMessages(['operation' => 'A selected task changed. Prepare a fresh preview before applying.']);
                }
            }
            $operation->status = 'queued';
            $operation->save();
            $event = new OutboxEvent;
            $event->forceFill(['event_key' => (string) Str::uuid7(), 'event_type' => 'tasks.operation', 'aggregate_type' => 'bulk_operation', 'aggregate_id' => $operation->id, 'payload' => [], 'available_at' => now()])->save();
            app(AuditWriter::class)->record($actor, 'task_operation_confirmed', $operation->public_id, ['status' => 'queued'], module: 'M05', entityType: 'bulk_operation');
        });
    }

    public function process(int $id): void
    {
        $operation = BulkOperation::query()->findOrFail($id);
        if (! in_array($operation->status, ['queued', 'running'], true)) {
            return;
        }
        foreach ($operation->items()->where('state', 'pending')->orderBy('task_id')->pluck('id') as $itemId) {
            $this->locked($id, function (BulkOperation $operation, AssignmentBatch $batch) use ($itemId): void {
                if (! in_array($operation->status, ['queued', 'running'], true)) {
                    return;
                }
                $item = $operation->items()->lockForUpdate()->findOrFail($itemId);
                if ($item->state !== 'pending') {
                    return;
                }
                $task = Task::query()->lockForUpdate()->findOrFail($item->task_id);
                $actor = User::query()->findOrFail($operation->requested_by);
                try {
                    DB::transaction(function () use ($actor, $batch, $operation, $task, $item): void {
                        $this->authorize($actor, $batch, $operation->operation_code);
                        if ($operation->target_collector_id) {
                            $this->rider($operation->target_collector_id);
                        }
                        if ($task->lock_version !== $item->expected_version || $this->exclusion($task, $operation->operation_code, $operation->target_collector_id)) {
                            throw ValidationException::withMessages(['operation' => 'stale_task']);
                        }
                        $from = $task->work_status;
                        $review = $task->review_status;
                        $current = $task->currentAllocation()->lockForUpdate()->first();
                        $allocationId = $current?->id;
                        if ($current) {
                            $current->released_at = now();
                            $current->release_reason = $operation->reason;
                            $current->save();
                        }
                        if (in_array($operation->operation_code, ['new', 'replace', 'reassign'], true)) {
                            $allocation = new TaskAllocation;
                            $allocation->forceFill(['task_id' => $task->id, 'collector_id' => $operation->target_collector_id, 'assigned_by' => $actor->id, 'operation_code' => $operation->operation_code, 'assigned_at' => now(), 'bulk_operation_id' => $operation->id])->save();
                            $allocationId = $allocation->id;
                        }
                        if ($operation->operation_code === 'cancel') {
                            $task->work_status = 'cancelled';
                            $task->review_status = 'none';
                            $task->cancelled_at = now();
                        }
                        $task->lock_version++;
                        $task->last_updated_by = $actor->id;
                        $task->save();
                        $event = match ($operation->operation_code) {
                            'new' => 'assigned','unassign' => 'unassigned','cancel' => 'cancelled',default => 'reassigned'
                        };
                        app(TaskHistory::class)->record($actor, $task, $event, $from, $review, $allocationId, $operation->reason);
                        $item->state = 'applied';
                        $item->result_code = 'applied';
                        $item->save();
                    });
                } catch (\Throwable $error) {
                    $item->refresh();
                    $item->state = 'failed';
                    $item->result_code = $error instanceof ValidationException ? 'stale_or_ineligible' : 'permission_or_processing_failure';
                    $item->save();
                }
                $operation->status = 'running';
                $this->counts($operation);
            });
        }
        $this->locked($id, function (BulkOperation $operation, AssignmentBatch $batch): void {
            if (! in_array($operation->status, ['queued', 'running'], true) || $operation->items()->where('state', 'pending')->exists()) {
                return;
            }
            $operation->status = $operation->items()->where('state', 'failed')->exists() ? 'failed' : 'completed';
            $this->counts($operation);
            app(AuditWriter::class)->record(User::query()->find($operation->requested_by), 'task_operation_reconciled', $operation->public_id, ['status' => $operation->status], module: 'M05', entityType: 'bulk_operation');
            $this->notifyRider($operation, $batch);
            if ($operation->operation_code === 'cancel') {
                app(ArchiveFinishedBatch::class)->handle(User::query()->find($operation->requested_by), $batch->id);
            }
        });
    }

    public function cancel(User $actor, BulkOperation $original): void
    {
        $this->locked($original->id, function (BulkOperation $operation, AssignmentBatch $batch) use ($actor): void {
            $this->authorize($actor->fresh(), $batch, $operation->operation_code);
            abort_unless($actor->id === $operation->requested_by, 403);
            if (! in_array($operation->status, ['previewed', 'queued', 'running'], true)) {
                return;
            }
            $operation->items()->where('state', 'pending')->update(['state' => 'excluded', 'result_code' => 'operator_cancelled']);
            $operation->status = 'cancelled';
            $this->counts($operation);
        });
    }

    public function interrupted(int $id): void
    {
        $this->locked($id, function (BulkOperation $operation, AssignmentBatch $batch): void {
            if (in_array($operation->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            } $operation->items()->where('state', 'pending')->update(['state' => 'failed', 'result_code' => 'worker_interrupted']);
            $operation->status = 'failed';
            $this->counts($operation);
        });
    }

    /** Tell the Rider who received work, once per run, counting only the tasks that were actually allocated. */
    private function notifyRider(BulkOperation $operation, AssignmentBatch $batch): void
    {
        if (! in_array($operation->operation_code, ['new', 'replace', 'reassign'], true) || ! $operation->target_collector_id) {
            return;
        }
        $taskIds = $operation->items()->where('state', 'applied')->pluck('task_id');
        $rider = User::query()->where('staff_id', $operation->target_collector_id)->first();
        if ($taskIds->isEmpty() || ! $rider?->canUseRiderInterface()) {
            return;
        }
        $label = trim($batch->assignment_code.' - '.$batch->name, ' -');
        $singleTask = $taskIds->count() === 1 ? Task::query()->whereKey($taskIds->first())->value('public_id') : null;
        $rider->notify(new TasksAssigned($batch->public_id, $label, $taskIds->count(), $singleTask));
    }

    private function rider(int $id): Staff
    {
        $staff = Staff::query()->with('user')->lockForUpdate()->findOrFail($id);
        if (! $staff->is_active || ! $staff->is_rider || ! $staff->user || ! $staff->user->canUseRiderInterface()) {
            throw ValidationException::withMessages(['target_collector_id' => 'Choose an active Rider login.']);
        }

        return $staff;
    }

    private function exclusion(Task $task, string $operation, ?int $target): ?string
    {
        if ($task->excluded) {
            return 'excluded_source';
        }
        if (in_array($task->work_status, ['submitted', 'completed', 'cancelled'], true) || $task->review_status === 'pending') {
            return 'protected_work';
        }
        $current = $task->currentAllocation;
        if ($operation === 'new' && $current) {
            return 'already_assigned';
        }
        if (in_array($operation, ['replace', 'reassign', 'unassign'], true) && ! $current) {
            return 'not_assigned';
        }
        if (in_array($operation, ['replace', 'reassign'], true) && $current?->collector_id === $target) {
            return 'same_rider';
        }

        return null;
    }

    private function counts(BulkOperation $operation): void
    {
        $counts = $operation->items()->selectRaw('state,count(*) as total')->groupBy('state')->pluck('total', 'state');
        $operation->result_counts = ['selected' => $counts->sum(), 'applied' => (int) ($counts['applied'] ?? 0), 'excluded' => (int) ($counts['excluded'] ?? 0), 'failed' => (int) ($counts['failed'] ?? 0), 'outstanding' => (int) ($counts['pending'] ?? 0)];
        $operation->save();
    }

    private function locked(int $id, callable $callback): mixed
    {
        return DB::transaction(function () use ($id, $callback): mixed {
            $original = BulkOperation::query()->with('batch')->findOrFail($id);
            $batch = AssignmentBatch::query()->lockForUpdate()->findOrFail($original->assignment_batch_id);
            $operation = BulkOperation::query()->lockForUpdate()->findOrFail($id);

            return $callback($operation, $batch);
        }, 3);
    }
}
