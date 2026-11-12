<?php

namespace App\Livewire\Rider;

use App\Models\AssignmentBatch;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Notifications\AttendanceCorrected;
use App\Notifications\LeaveUpdated;
use App\Notifications\TaskReviewed;
use App\Notifications\TasksAssigned;
use App\Services\Tasks\RiderAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Own existing operational notifications only; opening the page does not mutate read state. */
#[Layout('layouts.rider')]
class Notifications extends Component
{
    use WithPagination;

    public function render(RiderAccess $access): View
    {
        Gate::authorize('use-rider-interface');
        $types = [];
        if (auth()->user()->can('tasks.view')) {
            $types[] = TaskReviewed::class;
            $types[] = TasksAssigned::class;
        }
        if (auth()->user()->can('attendance.view_own')) {
            $types[] = AttendanceCorrected::class;
            $types[] = LeaveUpdated::class;
        }
        $notifications = auth()->user()->notifications()->whereIn('type', $types)->orderByDesc('created_at')->orderByDesc('id')->paginate(15);
        $taskIds = $notifications->getCollection()->whereIn('type', [TaskReviewed::class, TasksAssigned::class])->pluck('data.task')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->all();
        $accessibleTasks = $access->scope(Task::query(), auth()->user())->whereIn('public_id', $taskIds)->pluck('public_id')->all();

        $accessibleLeave = LeaveRequest::query()->where('requested_by', auth()->id())->where('rider_id', auth()->user()->staff_id)->whereIn('public_id', $notifications->getCollection()->where('type', LeaveUpdated::class)->pluck('data.leave_request')->filter(fn ($id) => is_string($id) && Str::isUuid($id)))->pluck('public_id')->all();

        $assignmentIds = $notifications->getCollection()->where('type', TasksAssigned::class)->pluck('data.assignment')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->all();
        $accessibleAssignments = AssignmentBatch::query()->whereIn('public_id', $assignmentIds)->whereIn('id', $access->scope(Task::query(), auth()->user())->select('assignment_batch_id'))->pluck('public_id')->all();

        return view('livewire.rider.notifications', compact('notifications', 'accessibleTasks', 'accessibleLeave', 'accessibleAssignments'));
    }
}
