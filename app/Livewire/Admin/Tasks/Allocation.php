<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\TaskOperation;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\ImportBatch;
use App\Models\Task;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskIdSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Allocation extends Component
{
    private const CHOICE_LIMIT = 200;

    /** Plain reasons for tasks an operation left unchanged, keyed by their exclusion code. */
    private const EXCLUSION_REASONS = [
        'already_assigned' => 'already have a Rider',
        'not_assigned' => 'have no Rider',
        'same_rider' => 'are already with this Rider',
        'protected_work' => 'are submitted, completed or cancelled',
        'excluded_source' => 'are excluded from work',
    ];

    public string $batchId = '';

    public string $importId = '';

    public string $operation = 'new';

    public string $mode = '';

    public string $taskIds = '';

    public string $rangeFrom = '';

    public string $rangeTo = '';

    public string $riderId = '';

    public string $reason = '';

    public array $selected = [];

    /** Bumped by Select all so the browser redraws every tick box from the server's list. */
    public int $choiceRevision = 0;

    public function mount(?Task $task = null): void
    {
        Gate::authorize('viewAny', Task::class);
        $selected = $task ? [$task->public_id] : session()->pull('task_selection.'.Auth::id(), []);
        $this->selected = array_values(array_filter($selected, fn ($id) => is_string($id) && Str::isUuid($id)));
        $tasks = Task::query()->whereIn('public_id', $this->selected)->with('batch')->get();
        if ($tasks->count() !== count($this->selected)) {
            abort(404);
        }
        if ($tasks->pluck('assignment_batch_id')->unique()->count() === 1) {
            $this->batchId = (string) $tasks->first()->assignment_batch_id;
        }
        if ($task?->currentAllocation) {
            $this->operation = 'reassign';
        }
    }

    public function updatedBatchId(): void
    {
        $this->reset('importId');
        $this->resetIds();
    }

    public function updatedImportId(): void
    {
        $this->resetIds();
    }

    public function updatedMode(): void
    {
        $this->resetIds();
    }

    private function resetIds(): void
    {
        $this->reset('taskIds', 'rangeFrom', 'rangeTo');
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function toggleTask(int $number): void
    {
        if ($this->mode === 'single') {
            $this->taskIds = (string) $number;

            return;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $this->taskIds)), fn (string $part): bool => $part !== ''));
        $remaining = array_values(array_filter($parts, fn (string $part): bool => str_contains($part, '-') || Task::numberFromId($part) !== $number));
        $this->taskIds = implode(', ', count($remaining) === count($parts) ? [...$parts, (string) $number] : $remaining);
    }

    /**
     * Tasks offered as tick boxes for the chosen assignment and, when one is chosen, its Excel file. A file from an archived
     * assignment also offers its unassigned tasks, which move into the chosen assignment when given out.
     */
    private function choiceQuery(AssignmentBatch $assignment): Builder
    {
        $file = $this->importId === '' ? null : ImportBatch::query()->assignableFor($assignment->id)->where('public_id', $this->importId)->first();

        return Task::query()->whereNotNull('source_task_number')
            ->where(fn ($query) => $query->where('assignment_batch_id', $assignment->id)->when($file, fn ($query) => $query->orWhere(fn ($query) => $query->movableInto($assignment->id))))
            ->when($file, fn ($query) => $query->fromImport($file->id))->orderBy('source_task_number');
    }

    /**
     * Ticks every task on offer, up to the limit for one operation, or clears them all when they are already ticked.
     */
    public function toggleAllTasks(): void
    {
        $assignment = ctype_digit($this->batchId) ? AssignmentBatch::query()->where('status', 'active')->find((int) $this->batchId) : null;
        if (! $assignment || $this->mode !== 'selected') {
            return;
        }
        $numbers = $this->choiceQuery($assignment)->limit(self::CHOICE_LIMIT)->pluck('source_task_number')->all();
        $this->taskIds = $this->allTyped($numbers) ? '' : implode(', ', $numbers);
        $this->choiceRevision++;
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function allTyped(array $numbers): bool
    {
        $typed = array_map(fn (string $part): ?int => Task::numberFromId(trim($part)), explode(',', $this->taskIds));

        return $numbers !== [] && array_diff($numbers, $typed) === [];
    }

    /**
     * Applies the chosen operation straight away, then reports the outcome in a popup and returns to Manage Task.
     */
    public function assign(TaskOperation $actions, TaskIdSelection $selection): void
    {
        if ($this->selected !== []) {
            $tasks = Task::query()->whereIn('public_id', array_filter($this->selected, fn ($id) => is_string($id) && Str::isUuid($id)))->pluck('id')->all();
            if (count($this->selected) !== count($tasks)) {
                abort(404);
            }
            $mode = count($tasks) === 1 ? 'single' : 'selected';
        } else {
            $hasRange = in_array($this->mode, ['range', 'range_selected'], true);
            $singleId = [Rule::requiredIf($hasRange), 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z]*\d+$/'];
            $this->validate(['batchId' => ['required', 'integer'], 'mode' => ['required', Rule::in(array_keys(TaskIdSelection::MODES))], 'rangeFrom' => $singleId, 'rangeTo' => $singleId, 'taskIds' => [Rule::requiredIf($this->mode !== 'range'), 'nullable', 'string', 'max:5000']], attributes: ['batchId' => 'assignment', 'mode' => 'selection mode', 'rangeFrom' => 'Task ID (From)', 'rangeTo' => 'Task ID (To)', 'taskIds' => 'task IDs']);
            $range = $hasRange ? "{$this->rangeFrom}-{$this->rangeTo}" : '';
            $file = $this->importId === '' ? null : ImportBatch::query()->assignableFor((int) $this->batchId)->where('public_id', $this->importId)->first();
            if ($this->importId !== '' && ! $file) {
                throw ValidationException::withMessages(['importId' => 'Choose an Excel file from this assignment.']);
            }
            $tasks = $selection->resolve((int) $this->batchId, $this->mode, match ($this->mode) {
                'range' => $range,
                'range_selected' => "{$range}, {$this->taskIds}",
                default => $this->taskIds,
            }, $file?->id);
            $mode = $this->mode;
        }
        $operation = $actions->prepare(Auth::user(), ['assignment_batch_id' => $this->batchId, 'operation_code' => $this->operation, 'selection_mode' => $mode, 'task_ids' => $tasks, 'target_collector_id' => $this->riderId ?: null, 'reason' => $this->reason]);
        $unchanged = $this->unchangedReasons($operation);
        if ($operation->result_counts['outstanding'] === 0) {
            $actions->cancel(Auth::user(), $operation);
            $this->addError('operation', 'No tasks were changed. '.$unchanged);

            return;
        }
        $actions->confirm(Auth::user(), $operation, $operation->preview_digest);
        if (config('queue.default') !== 'sync') {
            session()->flash('status', 'The selected tasks are being updated in the background.');
            $this->redirectRoute('admin.tasks.index');

            return;
        }
        $actions->process($operation->id);
        $operation->refresh();
        $unchanged = $this->unchangedReasons($operation);
        $applied = $operation->result_counts['applied'];
        if ($applied === 0) {
            $this->addError('operation', 'No tasks were changed. '.$unchanged);

            return;
        }
        $outcome = $this->outcome($operation, $applied);
        if ($unchanged === '') {
            session()->flash('status', $outcome);
        } else {
            session()->flash('warning', $outcome.' '.$unchanged);
        }
        $this->redirectRoute('admin.tasks.index');
    }

    private function outcome(BulkOperation $operation, int $applied): string
    {
        $tasks = $applied.' '.Str::plural('task', $applied);

        return match ($operation->operation_code) {
            'unassign' => "{$tasks} unassigned.",
            'cancel' => "{$tasks} cancelled.",
            default => "{$tasks} assigned to {$operation->collector?->full_name}.",
        };
    }

    /**
     * Explains which tasks an operation left unchanged and why, or returns an empty string when none were.
     */
    private function unchangedReasons(BulkOperation $operation): string
    {
        $reasons = [];
        foreach ($operation->items()->where('state', 'excluded')->pluck('result_code')->countBy() as $code => $count) {
            $reasons[] = $count.' '.(self::EXCLUSION_REASONS[$code] ?? 'could not be changed');
        }
        $failed = $operation->items()->where('state', 'failed')->count();
        if ($failed > 0) {
            $reasons[] = $failed.' could not be processed';
        }

        return $reasons === [] ? '' : 'Not changed: '.implode(', ', $reasons).'.';
    }

    public function render(TaskAccess $tasks): View
    {
        Gate::authorize('viewAny', Task::class);
        $batches = AssignmentBatch::query()->with('type')->where('status', 'active')->latest('id')->limit(200)->get();
        $batch = ctype_digit($this->batchId) ? $batches->firstWhere('id', (int) $this->batchId) : null;
        $choosable = $batch && $this->selected === [] && $this->mode !== '' && $this->mode !== 'range';
        $excelFiles = $batch ? ImportBatch::query()->assignableFor($batch->id)->get() : collect();
        $choices = $choosable ? $this->choiceQuery($batch)->with('customer')->limit(self::CHOICE_LIMIT)->get() : collect();

        return view('livewire.admin.tasks.allocation', ['batches' => $batches, 'selectedAssignment' => $batch, 'excelFiles' => $excelFiles, 'riders' => $batch ? $tasks->riders()->orderBy('first_name')->get() : collect(), 'selection' => Task::query()->whereIn('public_id', array_filter($this->selected, fn ($id) => is_string($id) && Str::isUuid($id)))->with(['customer', 'account'])->get(), 'choices' => $choices, 'choiceTotal' => $choosable ? $this->choiceQuery($batch)->count() : 0, 'choiceLimit' => self::CHOICE_LIMIT, 'allChoicesTicked' => $this->allTyped($choices->pluck('source_task_number')->all())]);
    }
}
