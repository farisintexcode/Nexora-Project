<?php

namespace App\Services;

use App\Models\CollectionActivity;
use App\Models\ImportBatch;
use App\Models\Task;
use App\Models\User;
use App\Services\Calls\CallAccess;
use App\Services\Tasks\TaskAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

/** Dashboard counts of stuck work, each matching the list it links to; items the actor may not open are left out. Missed deadlines are urgent, and items with work waiting come first. */
class AttentionQueue
{
    public const UNFINISHED_IMPORTS = ['mapping', 'ready', 'failed'];

    public function __construct(private TaskAccess $tasks, private CallAccess $calls, private PendingReviews $reviews) {}

    /** @return list<array{key: string, label: string, count: int, detail: string, href: string, urgent: bool}> */
    public function forUser(User $user): array
    {
        $items = [];
        if (Gate::forUser($user)->allows('viewAny', Task::class)) {
            $scope = Task::query();
            $pending = $this->reviews->tasks($user);
            $oldest = (clone $pending)->min('submitted_at');
            $items[] = [
                'key' => 'pending_verification', 'label' => 'Waiting for verification', 'count' => (clone $pending)->count(),
                'detail' => $oldest ? 'Oldest waiting '.CarbonImmutable::parse($oldest)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : 'Nothing waiting',
                'href' => $user->can('tasks.verify') ? route('admin.verification.index') : route('admin.tasks.index', ['status' => 'pending_verification']),
                'urgent' => false,
            ];
            $items[] = ['key' => 'overdue', 'label' => 'Overdue tasks', 'count' => $this->tasks->overdue(clone $scope)->count(), 'detail' => 'Past due and not finished', 'href' => route('admin.tasks.index', ['status' => 'overdue']), 'urgent' => true];
            $items[] = ['key' => 'unassigned', 'label' => 'Tasks without a Rider', 'count' => (clone $scope)->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'detail' => 'Not yet allocated', 'href' => route('admin.tasks.index', ['status' => 'unassigned']), 'urgent' => false];
            $items[] = ['key' => 'returned', 'label' => 'Returned tasks', 'count' => (clone $scope)->where('review_status', 'returned')->count(), 'detail' => 'Sent back for correction', 'href' => route('admin.tasks.index', ['status' => 'returned']), 'urgent' => false];
        }
        $leave = $this->reviews->leave($user);
        if ($leave !== null) {
            $oldestLeave = (clone $leave)->min('submitted_at');
            $items[] = [
                'key' => 'pending_leave', 'label' => 'Leave requests to review', 'count' => (clone $leave)->count(),
                'detail' => $oldestLeave ? 'Oldest waiting '.CarbonImmutable::parse($oldestLeave)->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : 'Nothing waiting',
                'href' => route('admin.leave.index', ['status' => 'pending']),
                'urgent' => false,
            ];
        }
        if (Gate::forUser($user)->allows('viewAny', CollectionActivity::class)) {
            $items[] = [
                'key' => 'overdue_follow_ups', 'label' => 'Overdue follow-ups',
                'count' => $this->calls->followUps($user)->where('status', 'pending')->where('due_at', '<', now()->format('Y-m-d H:i:s.uP'))->count(),
                'detail' => 'Pending past their due time', 'href' => route('admin.calls.follow-ups', ['status' => 'pending', 'ownership' => 'all', 'due' => 'overdue']),
                'urgent' => true,
            ];
        }
        if (Gate::forUser($user)->allows('viewAny', ImportBatch::class)) {
            $items[] = [
                'key' => 'unfinished_imports', 'label' => 'Unfinished imports',
                'count' => ImportBatch::query()->whereIn('status', self::UNFINISHED_IMPORTS)->count(),
                'detail' => 'Awaiting mapping, confirmation or a retry', 'href' => route('admin.imports.index'),
                'urgent' => false,
            ];
        }

        usort($items, fn (array $first, array $second): int => [$second['count'] > 0, $second['urgent']] <=> [$first['count'] > 0, $first['urgent']]);

        return $items;
    }
}
