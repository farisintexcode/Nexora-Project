<?php

namespace App\Actions\Assignments;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\User;
use App\Services\Assignments\BatchWork;
use App\Services\AuditWriter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * M03: archives an active assignment on its own once its assigned work is done, meaning at least one task is verified and
 * every task given to a Rider (or already started) is verified or cancelled, with no import or bulk operation pending.
 * Unstarted tasks that were never given to a Rider do not hold the assignment open and are left untouched.
 * Runs inside the caller's transaction, after the work change.
 */
class ArchiveFinishedBatch
{
    public function __construct(private AuditWriter $audit, private BatchWork $work) {}

    /** Returns true when the assignment was archived by this call. */
    public function handle(?User $actor, int $batchId): bool
    {
        $batch = AssignmentBatch::query()->lockForUpdate()->findOrFail($batchId);
        if ($batch->status !== BatchStatus::Active) {
            return false;
        }
        $tasks = DB::table('tasks')->where('assignment_batch_id', $batch->id)->whereNull('deleted_at');
        $hasVerifiedTask = (clone $tasks)->where('work_status', 'completed')->where('review_status', 'approved')->exists();
        $hasOutstandingAssignedTask = (clone $tasks)
            ->where(fn (Builder $q) => $q->where('work_status', '!=', 'unstarted')->orWhereExists(fn (Builder $q) => $q->from('task_allocations')
                ->whereColumn('task_allocations.task_id', 'tasks.id')->whereNull('task_allocations.released_at')))
            ->where(fn (Builder $q) => $q->whereNotIn('work_status', ['completed', 'cancelled'])
                ->orWhere(fn (Builder $q) => $q->where('work_status', 'completed')->where('review_status', '!=', 'approved')))
            ->exists();
        $hasPendingBulkOperation = DB::table('bulk_operations')->where('assignment_batch_id', $batch->id)->whereIn('status', ['queued', 'running'])->exists();
        if (! $hasVerifiedTask || $hasOutstandingAssignedTask || $hasPendingBulkOperation || $this->work->summary($batch)['unfinished_imports'] > 0) {
            return false;
        }
        $batch->status = BatchStatus::Archived;
        $batch->archived_at = now();
        $batch->lock_version++;
        $batch->save();
        $this->audit->record($actor, 'assignment_archived', $batch->public_id, ['status' => 'archived'], 'Archived automatically: every assigned task is completed and verified.', 'M03', 'assignment_batch', ['status' => 'active']);

        return true;
    }
}
