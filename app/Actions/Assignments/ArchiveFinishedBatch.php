<?php

namespace App\Actions\Assignments;

use App\Enums\BatchStatus;
use App\Models\AssignmentBatch;
use App\Models\User;
use App\Services\Assignments\BatchWork;
use App\Services\AuditWriter;
use Illuminate\Support\Facades\DB;

/**
 * M03: archives an active assignment on its own once its work is done, meaning at least one task is verified, every other
 * task is verified or cancelled, and no import is pending. Runs inside the caller's transaction, after the work change.
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
        $work = $this->work->summary($batch);
        $hasVerifiedTask = DB::table('tasks')->where('assignment_batch_id', $batch->id)->whereNull('deleted_at')
            ->where('work_status', 'completed')->where('review_status', 'approved')->exists();
        if (! $hasVerifiedTask || $work['unfinished_tasks'] > 0 || $work['unfinished_imports'] > 0) {
            return false;
        }
        $batch->status = BatchStatus::Archived;
        $batch->archived_at = now();
        $batch->lock_version++;
        $batch->save();
        $this->audit->record($actor, 'assignment_archived', $batch->public_id, ['status' => 'archived'], 'Archived automatically: every task is completed and verified.', 'M03', 'assignment_batch', ['status' => 'active']);

        return true;
    }
}
