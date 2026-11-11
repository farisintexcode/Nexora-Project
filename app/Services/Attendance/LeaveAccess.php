<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** M10: reuse attendance permissions and current record scope, never role names. */
class LeaveAccess
{
    public function __construct(private AttendanceAccess $attendance) {}

    public function own(User $actor): bool
    {
        return $actor->canUseRiderInterface() && $actor->can('attendance.view_own');
    }

    public function admin(User $actor): bool
    {
        return $this->attendance->admin($actor);
    }

    public function configure(User $actor): bool
    {
        return $this->admin($actor) && $actor->can('attendance.configure');
    }

    public function review(User $actor): bool
    {
        return $this->admin($actor) && $actor->can('leave.approve');
    }

    public function view(User $actor, LeaveRequest $request): bool
    {
        return ($this->own($actor) && $actor->staff_id === $request->rider_id && $actor->id === $request->requested_by) || $this->attendance->visible($actor, $request->rider_id);
    }

    public function scope(User $actor): Builder
    {
        $query = LeaveRequest::query();
        if ($this->admin($actor)) {
            return $query->whereIn('rider_id', $this->attendance->riders($actor)->select('id'));
        }

        return $query->where('rider_id', $actor->staff_id)->where('requested_by', $actor->id)->when(! $this->own($actor), fn (Builder $q) => $q->whereRaw('1=0'));
    }
}
