<?php

namespace App\Livewire\Rider;

use App\Models\AssignmentBatch;
use App\Models\LeaveRequest;
use App\Models\Task;
use App\Models\User;
use App\Notifications\LeaveUpdated;
use App\Notifications\TaskReviewed;
use App\Notifications\TasksAssigned;
use App\Notifications\TasksWithdrawn;
use App\Services\Tasks\RiderAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Own operational notifications only. Rendering never changes read state; once the list is on screen the page
 * marks what the Rider has now seen as read (a Livewire action, not the page request) and keeps those marked "New".
 */
#[Layout('layouts.rider')]
class Notifications extends Component
{
    use WithPagination;

    /** @var list<string> Notification ids that were unread when this Rider looked, still highlighted as new. */
    #[Locked]
    public array $fresh = [];

    /**
     * Notification types this person may see, by their own permissions.
     *
     * @return list<class-string>
     */
    public static function typesFor(User $user): array
    {
        $types = [];
        if ($user->can('tasks.view')) {
            array_push($types, TaskReviewed::class, TasksAssigned::class, TasksWithdrawn::class);
        }
        if ($user->can('attendance.view_own')) {
            $types[] = LeaveUpdated::class;
        }

        return $types;
    }

    /** Mark everything now visible to the Rider as read; runs after the list renders and on each refresh. */
    public function markSeen(): void
    {
        Gate::authorize('use-rider-interface');
        $user = auth()->user();
        $unread = $user->unreadNotifications()->whereIn('type', self::typesFor($user))->pluck('id');
        if ($unread->isEmpty()) {
            return;
        }
        $this->fresh = array_values(array_unique([...$this->fresh, ...$unread->all()]));
        $user->notifications()->whereKey($unread)->update(['read_at' => now()]);
        $this->dispatch('rider-notifications-read');
    }

    public function render(RiderAccess $access): View
    {
        Gate::authorize('use-rider-interface');
        $notifications = auth()->user()->notifications()->whereIn('type', self::typesFor(auth()->user()))->orderByDesc('created_at')->orderByDesc('id')->paginate(15);
        $taskIds = $notifications->getCollection()->whereIn('type', [TaskReviewed::class, TasksAssigned::class])->pluck('data.task')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->all();
        $accessibleTasks = $access->scope(Task::query(), auth()->user())->whereIn('public_id', $taskIds)->pluck('public_id')->all();

        $accessibleLeave = LeaveRequest::query()->where('requested_by', auth()->id())->where('rider_id', auth()->user()->staff_id)->whereIn('public_id', $notifications->getCollection()->where('type', LeaveUpdated::class)->pluck('data.leave_request')->filter(fn ($id) => is_string($id) && Str::isUuid($id)))->pluck('public_id')->all();

        $assignmentIds = $notifications->getCollection()->where('type', TasksAssigned::class)->pluck('data.assignment')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->all();
        $accessibleAssignments = AssignmentBatch::query()->whereIn('public_id', $assignmentIds)->whereIn('id', $access->scope(Task::query(), auth()->user())->select('assignment_batch_id'))->pluck('public_id')->all();

        return view('livewire.rider.notifications', compact('notifications', 'accessibleTasks', 'accessibleLeave', 'accessibleAssignments'));
    }
}
