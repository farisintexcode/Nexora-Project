<?php

namespace App\Services\Staff;

use App\Models\Staff;
use App\Models\Task;
use App\Models\TaskReview;
use App\Models\TaskSubmission;
use App\Services\Tasks\TaskAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Staff profile view of task management: the same current allocations a Rider sees in the Rider interface,
 * plus the Rider's own submission and verification record.
 */
class StaffTasks
{
    /** @var array<string, string> */
    public const ASSIGNED_FILTERS = ['open' => 'All open work', 'unstarted' => 'Not started', 'in_progress' => 'In progress', 'returned' => 'Returned for rework', 'submitted' => 'Pending verification', 'overdue' => 'Overdue'];

    public function __construct(private TaskAccess $access) {}

    /** Tasks currently allocated to this staff member, whatever their status. */
    public function allocated(Staff $staff): Builder
    {
        return Task::query()->whereHas('currentAllocation', fn (Builder $query) => $query->where('collector_id', $staff->id));
    }

    /** Unfinished work currently allocated to this staff member, narrowed by one of ASSIGNED_FILTERS. */
    public function assigned(Staff $staff, string $filter = 'open'): Builder
    {
        $tasks = $this->allocated($staff)->whereNotIn('work_status', ['completed', 'cancelled']);

        match ($filter) {
            'unstarted', 'in_progress', 'submitted' => $tasks->where('work_status', $filter),
            'returned' => $tasks->where('review_status', 'returned'),
            'overdue' => $this->access->overdue($tasks),
            'open' => null,
            default => $tasks->whereRaw('false'),
        };

        return $tasks->with(['batch', 'account', 'customer', 'currentAllocation', 'latestBalance'])
            ->orderByRaw('COALESCE(scheduled_visit_at, due_at) ASC NULLS LAST')->orderBy('id');
    }

    /** Verified completed work that remains allocated to this staff member. */
    public function completed(Staff $staff): Builder
    {
        return $this->allocated($staff)->where('work_status', 'completed')
            ->with(['batch', 'account', 'customer', 'currentAllocation', 'latestBalance'])
            ->orderByDesc('completed_at')->orderByDesc('id');
    }

    /**
     * @return array{open: int, unstarted: int, in_progress: int, submitted: int, returned: int, overdue: int, completed: int, completed_recently: int, completion_rate: ?int, submissions: int, approved: int, rework: int, approval_rate: ?int}
     */
    public function performance(Staff $staff): array
    {
        $counts = $this->allocated($staff)->toBase()->selectRaw(
            "count(*) filter (where work_status not in ('completed', 'cancelled')) as open,
            count(*) filter (where work_status = 'unstarted') as unstarted,
            count(*) filter (where work_status = 'in_progress') as in_progress,
            count(*) filter (where work_status = 'submitted') as submitted,
            count(*) filter (where review_status = 'returned' and work_status not in ('completed', 'cancelled')) as returned,
            count(*) filter (where work_status = 'completed') as completed,
            count(*) filter (where work_status = 'completed' and completed_at >= ?) as completed_recently",
            [now()->subDays(30)]
        )->first();
        $overdue = $this->access->overdue($this->allocated($staff))->count();

        $userId = $staff->user()->value('id');
        $submissions = $userId ? TaskSubmission::query()->where('submitted_by', $userId)->count() : 0;
        $decisions = $userId ? TaskReview::query()->whereHas('submission', fn (Builder $query) => $query->where('submitted_by', $userId))
            ->toBase()->selectRaw("count(*) filter (where decision = 'approved') as approved, count(*) filter (where decision = 'returned') as rework")->first() : null;
        $approved = (int) ($decisions?->approved ?? 0);
        $rework = (int) ($decisions?->rework ?? 0);
        $open = (int) $counts->open;
        $completed = (int) $counts->completed;

        return [
            'open' => $open,
            'unstarted' => (int) $counts->unstarted,
            'in_progress' => (int) $counts->in_progress,
            'submitted' => (int) $counts->submitted,
            'returned' => (int) $counts->returned,
            'overdue' => $overdue,
            'completed' => $completed,
            'completed_recently' => (int) $counts->completed_recently,
            'completion_rate' => $open + $completed > 0 ? (int) round($completed / ($open + $completed) * 100) : null,
            'submissions' => $submissions,
            'approved' => $approved,
            'rework' => $rework,
            'approval_rate' => $approved + $rework > 0 ? (int) round($approved / ($approved + $rework) * 100) : null,
        ];
    }

    /**
     * Rider app submissions and their verification outcomes per local day or month.
     *
     * @return array{buckets: Collection<int, array{label: string, date: string, submitted: int, approved: int}>, submitted: int, approved: int, returned: int}|null
     */
    public function trend(Staff $staff, string $period, int $range): ?array
    {
        $userId = $staff->user()->value('id');
        if (! $userId) {
            return null;
        }

        $monthly = $period === 'monthly';
        $timezone = config('dcms.business_timezone');
        $now = now()->timezone($timezone);
        $start = $monthly ? $now->copy()->startOfMonth()->subMonthsNoOverflow($range - 1) : $now->copy()->startOfDay()->subDays($range - 1);
        $bucketFormat = $monthly ? 'YYYY-MM' : 'YYYY-MM-DD';
        $count = fn (Builder $query, string $column): Collection => $query->where($column, '>=', $start->copy()->utc())->where($column, '<=', now())
            ->selectRaw("to_char({$column} at time zone ?, '{$bucketFormat}') as bucket, count(*) as total", [$timezone])
            ->groupBy('bucket')->pluck('total', 'bucket');
        $reviews = fn (string $decision): Builder => TaskReview::query()->where('decision', $decision)
            ->whereHas('submission', fn (Builder $query) => $query->where('submitted_by', $userId));

        $submitted = $count(TaskSubmission::query()->where('submitted_by', $userId), 'submitted_at');
        $approved = $count($reviews('approved'), 'reviewed_at');
        $returned = $count($reviews('returned'), 'reviewed_at');

        $buckets = collect(range(0, $range - 1))->map(function (int $offset) use ($monthly, $start, $range, $submitted, $approved): array {
            $date = $monthly ? $start->copy()->addMonthsNoOverflow($offset) : $start->copy()->addDays($offset);
            $key = $date->format($monthly ? 'Y-m' : 'Y-m-d');

            return [
                'label' => $date->format($monthly ? 'M' : ($range === 7 ? 'D' : 'j')),
                'date' => $date->format($monthly ? 'M Y' : 'd M Y'),
                'submitted' => (int) ($submitted[$key] ?? 0),
                'approved' => (int) ($approved[$key] ?? 0),
            ];
        });

        return ['buckets' => $buckets, 'submitted' => $buckets->sum('submitted'), 'approved' => $buckets->sum('approved'), 'returned' => (int) $returned->sum()];
    }
}
