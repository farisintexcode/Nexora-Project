<?php

namespace App\Livewire\Admin;

use App\Notifications\AttendanceCorrected;
use App\Notifications\LeaveUpdated;
use App\Notifications\TaskSubmitted;
use App\Services\PendingReviews;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Admin header bell: the actor's own notifications, polled with the pending-review counts that drive sidebar badges and dashboard cards. */
class NotificationBell extends Component
{
    public const TYPES = [TaskSubmitted::class, LeaveUpdated::class, AttendanceCorrected::class];

    /** @var array{tasks: int|null, leave: int|null} */
    #[Locked]
    public array $pendingCounts = ['tasks' => null, 'leave' => null];

    public function mount(PendingReviews $reviews): void
    {
        $this->pendingCounts = $reviews->counts(Auth::user());
    }

    /** Polled: pushes fresh counts to the sidebar and tells the dashboard to refresh when they change. */
    public function refreshCounts(PendingReviews $reviews): void
    {
        $counts = $reviews->counts(Auth::user());
        $this->dispatch('pending-reviews', tasks: $counts['tasks'], leave: $counts['leave']);
        if ($counts !== $this->pendingCounts) {
            $this->pendingCounts = $counts;
            $this->dispatch('pending-reviews-changed');
        }
    }

    public function open(string $id): void
    {
        abort_unless(Str::isUuid($id), 404);
        $notification = Auth::user()->notifications()->whereIn('type', self::TYPES)->whereKey($id)->first();
        abort_if($notification === null, 404);
        $notification->markAsRead();
        $link = $this->link($notification);
        if ($link !== null) {
            $this->redirect($link);
        }
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->whereIn('type', self::TYPES)->update(['read_at' => now()]);
    }

    public function link(DatabaseNotification $notification): ?string
    {
        $user = Auth::user();
        $data = $notification->data;

        return match (true) {
            $notification->type === TaskSubmitted::class && Str::isUuid($data['submission'] ?? '') && $user->can('tasks.verify') => route('admin.verification.show', $data['submission']),
            $notification->type === LeaveUpdated::class && ($data['admin'] ?? false) && Str::isUuid($data['leave_request'] ?? '') => route('admin.leave.show', $data['leave_request']),
            $notification->type === AttendanceCorrected::class && $user->can('attendance.view_own') => route('attendance.own'),
            default => null,
        };
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('livewire.admin.notification-bell', [
            'notifications' => $user->notifications()->whereIn('type', self::TYPES)->latest()->limit(10)->get(),
            'unread' => $user->unreadNotifications()->whereIn('type', self::TYPES)->count(),
        ]);
    }
}
