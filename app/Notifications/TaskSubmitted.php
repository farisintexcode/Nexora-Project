<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** M07: tells verifiers a Rider submitted a task; carries identifiers only, never customer or evidence details. */
class TaskSubmitted extends Notification
{
    public function __construct(public string $taskId, public string $submissionId, public string $riderName) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{task: string, submission: string, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['task' => $this->taskId, 'submission' => $this->submissionId, 'message' => $this->riderName.' submitted a task for verification.'];
    }
}
