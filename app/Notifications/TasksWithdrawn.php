<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** M05: tells a Rider that work left their list (moved to another Rider, unassigned or cancelled); one notice per run. */
class TasksWithdrawn extends Notification
{
    public function __construct(public string $assignment, public string $assignmentLabel, public int $count, public string $operation) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $subject = $this->count === 1 ? "A task in {$this->assignmentLabel} was" : "{$this->count} tasks in {$this->assignmentLabel} were";
        $outcome = match ($this->operation) {
            'cancel' => 'cancelled',
            'unassign' => 'taken off your list',
            default => 'moved to another rider',
        };

        return [
            'assignment' => $this->assignment,
            'count' => $this->count,
            'operation' => $this->operation,
            'message' => "{$subject} {$outcome}. You no longer need to work on ".($this->count === 1 ? 'it.' : 'them.'),
        ];
    }
}
