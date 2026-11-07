<?php

namespace App\Services\Reports;

use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskIdSelection;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M09/UI27: explicit operational projections; no financial or contact-data inference. */
class TaskReports
{
    public const FAMILIES = ['task' => 'Task', 'rider_assignment' => 'Rider Assignment', 'rider_performance' => 'Rider Performance', 'completed_task' => 'Completed Task', 'verification' => 'Verification'];

    public const COLUMNS = ['task_reference' => 'Task reference', 'task_id' => 'Task ID', 'assignment_id' => 'Assignment ID', 'batch' => 'Assignment', 'customer_reference' => 'Customer reference', 'account_reference' => 'Masked account', 'rider_staff_no' => 'Rider staff no.', 'work_status' => 'Work status', 'review_status' => 'Review status', 'created_at' => 'Created (MYT)', 'due_at' => 'Due (MYT)', 'submitted_at' => 'Submitted (MYT)', 'completed_at' => 'Completed (MYT)', 'excluded' => 'Excluded', 'payment_update' => 'Payment update marker', 'outstanding_balance' => 'Outstanding (RM)', 'monthly_charge' => 'Monthly charge (RM)', 'collected' => 'Verified collected (RM)'];

    public const PERFORMANCE = ['rider_staff_no' => 'Rider staff no.', 'allocated' => 'Current allocated tasks', 'completed' => 'Verified/completed tasks', 'pending_verification' => 'Pending verification', 'returned' => 'Returned tasks', 'outstanding_balance' => 'Outstanding (RM)', 'collected' => 'Verified collected (RM)'];

    public const DEFAULT_COLUMNS = ['task_reference', 'task_id', 'assignment_id', 'batch', 'rider_staff_no', 'work_status', 'review_status', 'due_at', 'outstanding_balance', 'collected'];

    public function authorize(User $actor, bool $export = false): void
    {
        abort_unless($actor->isActive(), 403);
        foreach (['admin.access', 'tasks.view', 'tasks.monitor', 'reports.view'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        if ($export) {
            Gate::forUser($actor)->authorize('reports.export');
        }
    }

    public function criteria(array $input): array
    {
        return Validator::make($input, [
            'family' => ['required', Rule::in(array_keys(self::FAMILIES))],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', Rule::when(! empty($input['from']), 'after_or_equal:from')],
            'batch' => ['nullable', 'uuid'], 'rider' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['unstarted', 'in_progress', 'submitted', 'completed', 'cancelled'])],
            'task_id' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ($error = app(TaskIdSelection::class)->filterError($value))) {
                    $fail($error);
                }
            }], 'columns' => ['required', 'array', 'min:1', 'max:20'],
            'columns.*' => ['required', 'distinct', Rule::in(array_keys(self::COLUMNS))],
        ])->validate();
    }

    public function query(User $actor, array $criteria): Builder
    {
        $this->authorize($actor);
        if ($criteria['family'] === 'verification') {
            Gate::forUser($actor)->authorize('tasks.verify');
        }
        $q = Task::query();
        foreach (['from', 'until'] as $key) {
            if (! empty($criteria[$key])) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $criteria[$key], config('dcms.business_timezone'));
                $q->where('created_at', $key === 'from' ? '>=' : '<=', ($key === 'from' ? $date->startOfDay() : $date->endOfDay())->utc());
            }
        }
        if (! empty($criteria['batch'])) {
            $q->whereHas('batch', fn ($b) => $b->where('public_id', $criteria['batch']));
        }
        if (! empty($criteria['rider'])) {
            $q->whereHas('currentAllocation.collector', fn ($s) => $s->where(Str::isUuid($criteria['rider']) ? 'public_id' : 'staff_no', $criteria['rider']));
        }
        if (! empty($criteria['status'])) {
            $q->where('work_status', $criteria['status']);
        }
        if (! empty($criteria['task_id'])) {
            app(TaskIdSelection::class)->filter($q, $criteria['task_id']);
        }
        if ($criteria['family'] === 'completed_task') {
            $q->where('work_status', 'completed');
        }
        if ($criteria['family'] === 'verification') {
            $q->where(fn ($s) => $s->where('work_status', 'submitted')->orWhereIn('review_status', ['returned', 'approved']));
        }
        if (in_array($criteria['family'], ['rider_assignment', 'rider_performance'])) {
            $q->whereHas('currentAllocation');
        }

        return $q;
    }

    public function snapshot(User $actor, array $input): array
    {
        $criteria = $this->criteria($input);

        return DB::transaction(function () use ($actor, $criteria): array {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $tasks = $this->query($actor, $criteria)->with(['batch', 'customer', 'account', 'currentAllocation.collector', 'balances'])->orderBy('id')->limit(config('reports.max_tasks') + 1)->get();
            if ($tasks->count() > config('reports.max_tasks')) {
                throw ValidationException::withMessages(['report' => 'Narrow the filters to at most '.config('reports.max_tasks').' tasks.']);
            }
            $rows = $tasks->map(fn (Task $t) => $this->row($t))->all();
            $totals = ['tasks' => $tasks->count(), 'completed' => $tasks->where('work_status', 'completed')->count(), 'pending_verification' => $tasks->where('work_status', 'submitted')->count(), 'returned' => $tasks->where('review_status', 'returned')->count()];
            if ($criteria['family'] === 'rider_performance') {
                $rows = $tasks->groupBy(fn ($t) => $t->currentAllocation->collector->staff_no)->map(fn ($group, $staff) => ['rider_staff_no' => (string) $staff, 'allocated' => $group->count(), 'completed' => $group->where('work_status', 'completed')->count(), 'pending_verification' => $group->where('work_status', 'submitted')->count(), 'returned' => $group->where('review_status', 'returned')->count(), 'outstanding_balance' => Money::plain($group->sum(fn (Task $t) => $this->outstanding($t))), 'collected' => Money::plain($group->sum(fn (Task $t) => $this->collected($t)))])->values()->all();
            }
            $columns = $criteria['family'] === 'rider_performance' ? self::PERFORMANCE : array_intersect_key(self::COLUMNS, array_flip($criteria['columns']));
            $rows = array_map(fn ($row) => array_intersect_key($row, $columns), $rows);

            return ['criteria' => $criteria, 'as_of' => now()->toIso8601String(), 'task_ids' => $tasks->pluck('public_id')->all(), 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'money' => ['outstanding' => Money::format($tasks->sum(fn (Task $t) => $this->outstanding($t))), 'collected' => Money::format($tasks->sum(fn (Task $t) => $this->collected($t)))]];
        });
    }

    private function row(Task $task): array
    {
        $time = fn ($v) => $v?->timezone(config('dcms.business_timezone'))->format('Y-m-d H:i:s') ?? '';

        $balance = $task->balances->last();

        return ['outstanding_balance' => $balance?->outstandingCents() === null ? '' : Money::plain($balance->outstandingCents()), 'monthly_charge' => $balance?->monthlyCents() === null ? '' : Money::plain($balance->monthlyCents()), 'collected' => Money::plain($this->collected($task)), 'task_reference' => $task->public_id, 'task_id' => $task->source_task_id ?? '', 'assignment_id' => $task->batch->assignment_code, 'batch' => $task->batch->name, 'customer_reference' => $task->customer->customer_reference, 'account_reference' => Str::mask((string) $task->account->account_number, '*', 0, max(0, mb_strlen((string) $task->account->account_number) - 4)), 'rider_staff_no' => $task->currentAllocation?->collector?->staff_no ?? '', 'work_status' => $task->work_status, 'review_status' => $task->review_status, 'created_at' => $time($task->created_at), 'due_at' => $time($task->effectiveDue()), 'submitted_at' => $time($task->submitted_at), 'completed_at' => $time($task->completed_at), 'excluded' => $task->excluded ? 'Yes' : 'No', 'payment_update' => $task->payment_update_flag ? 'Yes' : 'No'];
    }

    private function outstanding(Task $task): int
    {
        return $task->balances->last()?->outstandingCents() ?? 0;
    }

    /** Only verified payments count as collected; an unreviewed claim never does. */
    private function collected(Task $task): int
    {
        return $task->balances->where('source_code', 'payment')->sum(fn ($entry) => $entry->paidCents() ?? 0);
    }
}
