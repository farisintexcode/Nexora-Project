<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\TaskOperation;
use App\Models\AssignmentBatch;
use App\Models\BulkOperation;
use App\Models\Task;
use App\Services\AuditWriter;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskIdSelection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.admin')]
class Allocation extends Component
{
    public string $batchId = '';

    public string $operation = 'new';

    public string $mode = '';

    public string $taskIds = '';

    public string $rangeFrom = '';

    public string $rangeTo = '';

    public string $riderId = '';

    public string $reason = '';

    public array $selected = [];

    #[Locked]
    public ?string $operationId = null;

    #[Locked]
    public string $digest = '';

    public function mount(?Task $task = null, ?BulkOperation $operation = null): void
    {
        Gate::authorize('viewAny', Task::class);
        if ($operation) {
            $this->operationId = $operation->public_id;

            return;
        }
        $selected = $task ? [$task->public_id] : session()->pull('task_selection.'.Auth::id(), []);
        $this->selected = array_values(array_filter($selected, fn ($id) => is_string($id) && Str::isUuid($id)));
        $tasks = Task::query()->whereIn('public_id', $this->selected)->with('batch')->get();
        if ($tasks->count() !== count($this->selected)) {
            abort(404);
        }
        if ($tasks->pluck('assignment_batch_id')->unique()->count() === 1) {
            $this->batchId = (string) $tasks->first()->assignment_batch_id;
        }
    }

    public function updatedBatchId(): void
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

    private function operationRecord(): ?BulkOperation
    {
        if (! $this->operationId) {
            return null;
        }
        $record = BulkOperation::query()->with(['batch', 'collector'])->where('public_id', $this->operationId)->firstOrFail();
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('viewAny', Task::class);

        return $record;
    }

    public function preview(TaskOperation $actions, TaskIdSelection $selection): void
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
            $this->validate(['batchId' => ['required', 'integer'], 'mode' => ['required', Rule::in(array_keys(TaskIdSelection::MODES))], 'rangeFrom' => $singleId, 'rangeTo' => $singleId, 'taskIds' => [Rule::requiredIf($this->mode !== 'range'), 'nullable', 'string', 'max:2000']], attributes: ['batchId' => 'assignment', 'mode' => 'selection mode', 'rangeFrom' => 'Task ID (From)', 'rangeTo' => 'Task ID (To)', 'taskIds' => 'task IDs']);
            $range = $hasRange ? "{$this->rangeFrom}-{$this->rangeTo}" : '';
            $tasks = $selection->resolve((int) $this->batchId, $this->mode, match ($this->mode) {
                'range' => $range,
                'range_selected' => "{$range}, {$this->taskIds}",
                default => $this->taskIds,
            });
            $mode = $this->mode;
        }
        $operation = $actions->prepare(Auth::user(), ['assignment_batch_id' => $this->batchId, 'operation_code' => $this->operation, 'selection_mode' => $mode, 'task_ids' => $tasks, 'target_collector_id' => $this->riderId ?: null, 'reason' => $this->reason]);
        $this->operationId = $operation->public_id;
        $this->digest = $operation->preview_digest;
        $this->redirectRoute('admin.tasks.operation', ['operation' => $operation]);
    }

    public function confirm(TaskOperation $actions): void
    {
        $record = $this->operationRecord();
        abort_unless($record, 404);
        $actions->confirm(Auth::user(), $record, $this->digest);
        session()->flash('status', 'Operation queued. Processing starts when the default queue worker receives it.');
    }

    public function cancel(TaskOperation $actions): void
    {
        $record = $this->operationRecord();
        abort_unless($record, 404);
        $actions->cancel(Auth::user(), $record);
        session()->flash('status', 'Outstanding items cancelled. Applied records and history remain.');
    }

    public function downloadResults(): StreamedResponse
    {
        $record = $this->operationRecord();
        abort_unless($record, 404);
        app(AuditWriter::class)->record(Auth::user(), 'task_operation_results_downloaded', $record->public_id, module: 'M05', entityType: 'bulk_operation');

        return response()->streamDownload(function () use ($record): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Task public ID', 'State', 'Result']);
            foreach ($record->items()->with('task')->lazyById(100) as $item) {
                fputcsv($out, [$item->task->public_id, $item->state, $item->result_code]);
            } fclose($out);
        }, 'task-operation-'.$record->public_id.'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    public function render(TaskAccess $tasks): View
    {
        Gate::authorize('viewAny', Task::class);
        $record = $this->operationRecord();
        if ($record) {
            $this->digest = $record->preview_digest;
        }
        $batches = AssignmentBatch::query()->with('type')->where('status', 'active')->latest('id')->limit(200)->get();
        $batch = ctype_digit($this->batchId) ? $batches->firstWhere('id', (int) $this->batchId) : null;
        $choosable = $batch && $this->selected === [] && $this->mode !== '' && $this->mode !== 'range';

        return view('livewire.admin.tasks.allocation', ['record' => $record, 'batches' => $batches, 'selectedAssignment' => $batch, 'riders' => $batch ? $tasks->riders()->orderBy('first_name')->get() : collect(), 'items' => $record ? $record->items()->with(['task.customer', 'task.account', 'task.currentAllocation.collector'])->orderBy('id')->get() : collect(), 'selection' => Task::query()->whereIn('public_id', array_filter($this->selected, fn ($id) => is_string($id) && Str::isUuid($id)))->with(['customer', 'account'])->get(), 'choices' => $choosable ? Task::query()->where('assignment_batch_id', $batch->id)->whereNotNull('source_task_number')->with('customer')->orderBy('source_task_number')->limit(200)->get() : collect()]);
    }
}
