<?php

namespace App\Services\Tasks;

use App\Models\AssignmentBatch;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Rider navigation: read-only projections of canonical current allocations (M03/M06). */
class RiderAssignments
{
    public function __construct(private RiderAccess $access) {}

    public function tasks(User $actor): Builder
    {
        Gate::forUser($actor)->authorize('use-rider-interface');
        abort_unless($actor->can('tasks.view'), 403);

        return $this->access->scope(Task::query(), $actor);
    }

    public function batches(User $actor): Builder
    {
        $summary = $this->tasks($actor)->select('assignment_batch_id')
            ->selectRaw("count(*) filter (where work_status <> 'cancelled') as task_count, count(*) filter (where work_status in ('unstarted','in_progress')) as pending_count, count(*) filter (where work_status='submitted') as submitted_count, count(*) filter (where work_status='completed' and review_status='approved') as verified_count, count(*) filter (where review_status='returned') as returned_count")
            ->selectRaw("count(*) filter (where ((work_status='submitted' and review_status='pending') or (work_status='completed' and review_status='approved')) and exists (select 1 from task_submissions where task_submissions.task_id=tasks.id and task_submissions.submitted_by=?)) as completed_count", [$actor->id])
            ->groupBy('assignment_batch_id');

        return AssignmentBatch::query()->joinSub($summary, 'rider_work', 'assignment_batches.id', '=', 'rider_work.assignment_batch_id')
            ->select(['assignment_batches.id', 'assignment_batches.public_id', 'assignment_batches.assignment_code', 'assignment_batches.name', 'assignment_batches.external_reference', 'assignment_batches.start_date', 'assignment_batches.due_date', 'assignment_batches.status', 'rider_work.task_count', 'rider_work.pending_count', 'rider_work.submitted_count', 'rider_work.completed_count', 'rider_work.verified_count', 'rider_work.returned_count'])
            ->orderBy('assignment_batches.due_date')->orderBy('assignment_batches.id');
    }
}
