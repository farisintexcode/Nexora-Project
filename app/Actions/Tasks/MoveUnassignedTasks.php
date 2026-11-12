<?php

namespace App\Actions\Tasks;

use App\Models\AssignmentBatch;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M05: brings unassigned tasks left in an archived assignment into the active assignment they are being given out under,
 * so an uploaded Excel file's remaining customers can be worked later. Runs inside the caller's transaction, with the
 * target assignment already locked.
 */
class MoveUnassignedTasks
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  array<int, int>  $taskIds  selected task ids; those already in the target assignment are left alone
     * @return int how many tasks were moved
     */
    public function handle(User $actor, AssignmentBatch $target, array $taskIds): int
    {
        $outside = Task::query()->whereIn('id', $taskIds)->where('assignment_batch_id', '<>', $target->id)->orderBy('id')->lockForUpdate()->get();
        if ($outside->isEmpty()) {
            return 0;
        }
        $movable = Task::query()->whereIn('id', $outside->pluck('id'))->movableInto($target->id)->pluck('id');
        $blocked = $outside->whereNotIn('id', $movable);
        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages(['operation' => 'These tasks belong to another assignment and cannot move into this one: '.$this->labels($blocked).'. Only unassigned, unstarted tasks from an archived assignment of the same type can move.']);
        }
        $clashes = $outside->filter(fn (Task $task): bool => $task->source_task_number !== null && DB::table('tasks')
            ->where('assignment_batch_id', $target->id)->where('source_task_number', $task->source_task_number)->exists());
        if ($clashes->isNotEmpty()) {
            throw ValidationException::withMessages(['operation' => 'This assignment already has tasks with the same number as '.$this->labels($clashes).', so they cannot move into it.']);
        }
        foreach ($outside->load('batch') as $task) {
            $from = $task->batch;
            $task->assignment_batch_id = $target->id;
            $task->lock_version++;
            $task->last_updated_by = $actor->id;
            $task->save();
            $this->audit->record($actor, 'task_moved', $task->public_id, ['assignment' => $target->public_id], 'Unassigned task moved from archived assignment '.$from->assignment_code.' to '.$target->assignment_code.'.', 'M05', 'task', ['assignment' => $from->public_id]);
        }

        return $outside->count();
    }

    /** @param  iterable<Task>  $tasks */
    private function labels(iterable $tasks): string
    {
        return collect($tasks)->map(fn (Task $task): string => $task->source_task_id ?? $task->public_id)->implode(', ');
    }
}
