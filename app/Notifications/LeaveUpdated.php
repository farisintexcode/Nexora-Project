<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** M10: transactional in-app updates without sensitive reasons or document metadata. */
class LeaveUpdated extends Notification
{
    public function __construct(public string $requestId, public string $status, public bool $admin = false) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['leave_request' => $this->requestId, 'status' => $this->status, 'message' => $this->admin ? 'A leave request is awaiting review.' : 'Your leave request is '.$this->status.'.', 'admin' => $this->admin];
    }
}
