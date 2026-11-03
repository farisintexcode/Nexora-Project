<?php

namespace App\Services\Tasks;

use App\Models\AssignmentBatch;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard progress over non-cancelled tasks.
 * Complete means verified (completed and approved); "today" means currently allocated to a Rider today, business timezone.
 */
class TaskProgress
{
    private const COMPLETE = "tasks.work_status = 'completed' and tasks.review_status = 'approved'";

    /**
     * @return array{total: int, complete: int, outstanding: int, completionRate: float, today: int, completeToday: int, outstandingToday: int, todayCompletionRate: float, riders: Collection<int, object{name: string, staff_no: string, total: int, complete: int, outstanding: int, assigned_today: int}>, riderCount: int}|null
     */
    public function forUser(User $user, ?int $batchId = null, ?int $riderLimit = 5): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Task::class)) {
            return null;
        }
        $tasks = fn (): Builder => $this->tasks($user)->when($batchId !== null, fn ($q) => $q->where('tasks.assignment_batch_id', $batchId));

        $today = now()->timezone(config('dcms.business_timezone'))->startOfDay();
        $todayBounds = [$today->format('Y-m-d H:i:s.uP'), $today->copy()->addDay()->format('Y-m-d H:i:s.uP')];
        $assignedToday = 'allocation.assigned_at >= ? and allocation.assigned_at < ?';

        $totals = $this->allocated($tasks(), 'leftJoin')
            ->selectRaw('count(*) as total, count(*) filter (where '.self::COMPLETE.') as complete', [])
            ->selectRaw("count(*) filter (where {$assignedToday}) as today", $todayBounds)
            ->selectRaw("count(*) filter (where {$assignedToday} and ".self::COMPLETE.') as complete_today', $todayBounds)
            ->toBase()->first();

        $allocatedTasks = $this->allocated($tasks(), 'join');
        $riderCount = (clone $allocatedTasks)->distinct()->count('allocation.collector_id');
        $riderRows = $allocatedTasks->join('staff', 'staff.id', '=', 'allocation.collector_id')
            ->groupBy('staff.id', 'staff.first_name', 'staff.last_name', 'staff.staff_no')
            ->select('staff.first_name', 'staff.last_name', 'staff.staff_no')
            ->selectRaw('count(*) as total, count(*) filter (where '.self::COMPLETE.') as complete')
            ->selectRaw("count(*) filter (where {$assignedToday}) as assigned_today", $todayBounds)
            ->orderByDesc('total')->orderBy('staff.first_name')->orderBy('staff.id')->when($riderLimit !== null, fn ($q) => $q->limit($riderLimit))->toBase()->get()
            ->map(fn (object $row): object => (object) [
                'name' => trim($row->first_name.' '.$row->last_name), 'staff_no' => $row->staff_no,
                'total' => (int) $row->total, 'complete' => (int) $row->complete,
                'outstanding' => (int) $row->total - (int) $row->complete, 'assigned_today' => (int) $row->assigned_today,
            ]);

        $total = (int) $totals->total;
        $complete = (int) $totals->complete;
        $todayTotal = (int) $totals->today;
        $completeToday = (int) $totals->complete_today;

        return [
            'total' => $total, 'complete' => $complete, 'outstanding' => $total - $complete,
            'completionRate' => $this->rate($complete, $total),
            'today' => $todayTotal, 'completeToday' => $completeToday, 'outstandingToday' => $todayTotal - $completeToday,
            'todayCompletionRate' => $this->rate($completeToday, $todayTotal),
            'riders' => $riderRows, 'riderCount' => $riderCount,
        ];
    }

    /**
     * Active assignments with unfinished tasks, soonest due first; a status flags work past or near its due date.
     *
     * @return array{items: Collection<int, object{public_id: string, name: string, external_reference: ?string, due_date: CarbonInterface, total: int, complete: int, rate: float, status: ?string}>, count: int}|null
     */
    public function assignments(User $user, int $limit = 6): ?array
    {
        if (! Gate::forUser($user)->allows('viewAny', Task::class)) {
            return null;
        }

        $today = now()->timezone(config('dcms.business_timezone'))->startOfDay();
        $open = AssignmentBatch::query()->where('status', 'active')
            ->whereHas('tasks', fn ($q) => $q->whereNotIn('work_status', ['completed', 'cancelled']));
        $items = (clone $open)->select(['id', 'public_id', 'name', 'external_reference', 'due_date'])->withCount([
            'tasks as total_tasks' => fn ($q) => $q->where('work_status', '!=', 'cancelled'),
            'tasks as complete_tasks' => fn ($q) => $q->where('work_status', 'completed')->where('review_status', 'approved'),
        ])->orderBy('due_date')->orderBy('id')->limit($limit)->get()
            ->map(fn (AssignmentBatch $batch): object => (object) [
                'id' => $batch->id, 'public_id' => $batch->public_id, 'name' => $batch->name, 'external_reference' => $batch->external_reference, 'due_date' => $batch->due_date,
                'total' => $batch->total_tasks, 'complete' => $batch->complete_tasks, 'rate' => $this->rate($batch->complete_tasks, $batch->total_tasks),
                'status' => match (true) {
                    $batch->due_date->toDateString() < $today->toDateString() => 'past_due',
                    $batch->due_date->toDateString() <= $today->copy()->addDays(3)->toDateString() => 'due_soon',
                    default => null,
                },
            ]);

        return ['items' => $items, 'count' => $open->count()];
    }

    private function tasks(User $user): Builder
    {
        return Task::query()->where('tasks.work_status', '!=', 'cancelled');
    }

    private function allocated(Builder $tasks, string $join): Builder
    {
        return $tasks->{$join}('task_allocations as allocation', fn ($q) => $q->on('allocation.task_id', '=', 'tasks.id')->whereNull('allocation.released_at'));
    }

    private function rate(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole * 100, 2);
    }
}
