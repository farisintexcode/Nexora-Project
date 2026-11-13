<?php

namespace App\Actions\Tasks;

use App\Models\ImportBatch;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** M05: removes an uploaded task Excel file and every task it created, all or nothing, under the same rules as deleting tasks. */
class DeleteTaskFile
{
    public function __construct(private AuditWriter $audit, private DeleteTasks $tasks) {}

    /**
     * @return int number of tasks deleted with the file
     */
    public function handle(User $actor, string $filePublicId): int
    {
        Gate::forUser($actor)->authorize('viewAny', Task::class);
        abort_unless($actor->isActive() && $actor->can('tasks.delete'), 403);

        return DB::transaction(function () use ($actor, $filePublicId): int {
            $file = ImportBatch::query()->where('kind', 'task')->where('public_id', $filePublicId)->lockForUpdate()->first();
            if (! $file) {
                throw ValidationException::withMessages(['file' => 'This Excel file no longer exists. Reload and try again.']);
            }
            $tasks = Task::query()->fromImport($file->id)->with('batch')->orderBy('id')->lockForUpdate()->get();
            $deleted = $tasks->isEmpty() ? 0 : $this->tasks->deleteLocked($actor, $tasks, 'file');
            $file->deleted_by = $actor->id;
            $file->save();
            $file->delete();
            $this->audit->record($actor, 'task_file_deleted', $file->public_id, ['tasks_deleted' => $deleted], module: 'M05', entityType: 'import');

            return $deleted;
        }, 3);
    }
}
