<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** M05: tells a Rider in the app that work was allocated to them; one notice per allocation run, not per task. */
class TasksAssigned extends Notification
{
    public function __construct(public string $assignment, public string $assignmentLabel, public int $count, public ?string $task = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'assignment' => $this->assignment,
            'task' => $this->task,
            'count' => $this->count,
            'message' => $this->count === 1
                ? "A new task was assigned to you in {$this->assignmentLabel}."
                : "{$this->count} new tasks were assigned to you in {$this->assignmentLabel}.",
        ];
    }
}
