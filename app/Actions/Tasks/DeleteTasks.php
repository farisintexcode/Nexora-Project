<?php

namespace App\Actions\Tasks;

use App\Enums\BatchStatus;
use App\Models\BulkOperation;
use App\Models\BulkOperationItem;
use App\Models\Communication;
use App\Models\CommunicationPreference;
use App\Models\FollowUp;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** M05: removes tasks with no field work from every page. Immutable history keeps the record, so this hides rather than erases. */
class DeleteTasks
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * Deletes every selected task or none of them.
     *
     * @param  array<int, string>  $publicIds
     * @return int number of tasks deleted
     */
    public function handle(User $actor, array $publicIds): int
    {
        Gate::forUser($actor)->authorize('viewAny', Task::class);
        abort_unless($actor->isActive() && $actor->can('tasks.delete'), 403);
        Validator::make(['selected' => $publicIds], ['selected' => ['required', 'array', 'min:1', 'max:200'], 'selected.*' => ['required', 'uuid', 'distinct']])->validate();

        return DB::transaction(function () use ($actor, $publicIds): int {
            $tasks = Task::query()->whereIn('public_id', $publicIds)->with('batch')->orderBy('id')->lockForUpdate()->get();
            if ($tasks->count() !== count($publicIds)) {
                throw ValidationException::withMessages(['selected' => 'A selected task no longer exists. Reload and try again.']);
            }
            $blocked = $tasks->map(fn (Task $task): ?string => ($reason = $this->blockedReason($task)) ? ($task->source_task_id ?? 'A task').' '.$reason : null)->filter();
            if ($blocked->isNotEmpty()) {
                throw ValidationException::withMessages(['selected' => 'Nothing was deleted. '.$blocked->implode('; ').'.']);
            }
            foreach ($tasks as $task) {
                $task->lock_version++;
                $task->last_updated_by = $actor->id;
                $task->save();
                $task->delete();
                $this->audit->record($actor, 'task_deleted', $task->public_id, ['work_status' => $task->work_status], module: 'M05', entityType: 'task');
            }

            return $tasks->count();
        }, 3);
    }

    private function blockedReason(Task $task): ?string
    {
        if ($task->batch->status !== BatchStatus::Active) {
            return 'belongs to an archived assignment';
        }
        if ($task->work_status !== 'unstarted' || $task->review_status !== 'none') {
            return 'has already started, so use Cancel instead';
        }
        if ($task->currentAllocation()->exists()) {
            return 'is assigned to a Rider, so unassign it first';
        }
        $hasWork = $task->activities()->exists() || $task->inspections()->exists() || $task->submissions()->exists()
            || FollowUp::query()->where('task_id', $task->id)->exists()
            || Communication::query()->where('task_id', $task->id)->exists()
            || CommunicationPreference::query()->where('task_id', $task->id)->exists();
        if ($hasWork) {
            return 'has recorded activity, so use Cancel instead';
        }
        $queued = BulkOperationItem::query()->where('task_id', $task->id)->where('state', 'pending')->whereIn('bulk_operation_id', BulkOperation::query()->select('id')->whereIn('status', ['queued', 'running']))->exists();

        return $queued ? 'is part of an assign operation in progress' : null;
    }
}
