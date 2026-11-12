<?php

namespace App\Livewire\Rider;

use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Rider header bell: how many notifications the Rider has not seen yet, kept current while the app is open. */
class NotificationBell extends Component
{
    /** Re-count straight away when the Notifications page marks items as seen. */
    #[On('rider-notifications-read')]
    public function refreshCount(): void {}

    public function render(): View
    {
        $user = auth()->user();
        $unread = $user ? $user->unreadNotifications()->whereIn('type', Notifications::typesFor($user))->count() : 0;

        return view('livewire.rider.notification-bell', ['unread' => $unread]);
    }
}
