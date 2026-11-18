<?php

namespace App\Actions\Assignments;

use App\Actions\Tasks\DeleteTasks;
use App\Models\AssignmentBatch;
use App\Models\Task;
use App\Models\User;
use App\Services\Assignments\BatchWork;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * M03: removes an assignment with no field work from every page, along with its unstarted tasks.
 * Immutable history keeps the record, so this hides rather than erases.
 */
class DeleteAssignmentBatch
{
    public function __construct(private AuditWriter $audit, private BatchWork $work, private DeleteTasks $tasks) {}

    /** Deletes the assignment and its tasks, or nothing at all. Returns the number of tasks deleted with it. */
    public function handle(User $actor, string $publicId): int
    {
        return DB::transaction(function () use ($actor, $publicId): int {
            abort_unless($actor->isActive(), 404);
            $batch = AssignmentBatch::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $batch);
            if ($this->work->summary($batch)['unfinished_imports'] > 0) {
                throw ValidationException::withMessages(['delete' => 'Nothing was deleted. Finish or cancel the pending import for this assignment first.']);
            }
            $tasks = Task::query()->where('assignment_batch_id', $batch->id)->with('batch')->orderBy('id')->lockForUpdate()->get();
            if ($tasks->isNotEmpty()) {
                abort_unless($actor->can('tasks.delete'), 403);
                $this->tasks->deleteLocked($actor, $tasks, 'delete');
            }
            $batch->lock_version++;
            $batch->save();
            $batch->delete();
            $this->audit->record($actor, 'assignment_deleted', $batch->public_id, ['status' => $batch->status->value, 'tasks_deleted' => $tasks->count()], module: 'M03', entityType: 'assignment_batch');

            return $tasks->count();
        }, 3);
    }
}
