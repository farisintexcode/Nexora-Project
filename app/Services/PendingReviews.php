<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Services\Attendance\LeaveAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Work waiting for an admin decision: submitted tasks to verify and leave requests to review. Null means the actor cannot act on it. */
class PendingReviews
{
    public function __construct(private LeaveAccess $leave) {}

    /** @return Builder<Task>|null */
    public function tasks(User $user): ?Builder
    {
        if (! Gate::forUser($user)->allows('viewAny', Task::class)) {
            return null;
        }

        return Task::query()->where('work_status', 'submitted')->where('review_status', 'pending');
    }

    /** Pending leave the actor may decide: within their Rider scope and not their own. */
    public function leave(User $user): ?Builder
    {
        if (! $this->leave->review($user)) {
            return null;
        }

        return $this->leave->scope($user)->where('status', 'pending')->where('requested_by', '<>', $user->id)
            ->when($user->staff_id, fn (Builder $query) => $query->where('rider_id', '<>', $user->staff_id));
    }

    /** @return array{tasks: int|null, leave: int|null} */
    public function counts(User $user): array
    {
        return ['tasks' => $user->can('tasks.verify') ? $this->tasks($user)?->count() : null, 'leave' => $this->leave($user)?->count()];
    }
}
