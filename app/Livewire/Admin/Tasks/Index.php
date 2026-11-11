<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\DeleteTasks;
use App\Models\AssignmentBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Tasks\TaskAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    private const ADVANCED_FILTERS = ['priority', 'rider', 'from', 'until'];

    /** File filter value for tasks created manually without an Excel file. */
    public const MANUAL_FILE = 'manual';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $batch = '';

    #[Url]
    public string $file = '';

    #[Url]
    public string $rider = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public string $query = '';

    public array $selected = [];

    /**
     * Unapplied filter panel values; they reach the query only through applyFilters().
     *
     * @var array{priority: string, rider: string, from: string, until: string}
     */
    public array $draft = ['priority' => '', 'rider' => '', 'from' => '', 'until' => ''];

    public function mount(): void
    {
        $this->query = session('task_search.'.Auth::id(), '');
        $this->syncDraft();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'draft')) {
            return;
        }
        if ($property === 'batch') {
            $this->file = '';
        }
        if ($property !== 'selected' && ! str_starts_with($property, 'selected.')) {
            $this->selected = [];
            $this->resetPage();
        } if ($property === 'query') {
            session()->put('task_search.'.Auth::id(), mb_substr($this->query, 0, 100));
        }
    }

    private function syncDraft(): void
    {
        foreach (self::ADVANCED_FILTERS as $filter) {
            $this->draft[$filter] = $this->{$filter};
        }
    }

    public function applyFilters(): void
    {
        foreach (self::ADVANCED_FILTERS as $filter) {
            $value = $this->draft[$filter] ?? '';
            $this->{$filter} = is_string($value) ? mb_substr(trim($value), 0, 64) : '';
        }
        $this->syncDraft();
        $this->selected = [];
        $this->resetPage();
    }

    public function resetAdvancedFilters(): void
    {
        $this->reset(self::ADVANCED_FILTERS);
        $this->syncDraft();
        $this->selected = [];
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([...self::ADVANCED_FILTERS, 'query', 'status', 'batch', 'file']);
        $this->syncDraft();
        session()->forget('task_search.'.Auth::id());
        $this->selected = [];
        $this->resetPage();
    }

    /** Open one uploaded Excel file (or the manually created tasks) and list every customer in it. */
    public function showFile(string $publicId): void
    {
        if ($publicId !== self::MANUAL_FILE) {
            $excelFile = $this->taskFiles()->where('public_id', $this->validUuid($publicId))->first();
            if (! $excelFile) {
                return;
            }
        }
        $this->clearTaskFilters();
        $this->file = $publicId === self::MANUAL_FILE ? self::MANUAL_FILE : $excelFile->public_id;
        $this->batch = $publicId === self::MANUAL_FILE ? $this->batch : ($excelFile->assignment?->public_id ?? '');
    }

    /** Return to the first view: the list of uploaded Excel files. */
    public function backToFiles(): void
    {
        $this->clearTaskFilters();
        $this->file = '';
    }

    private function clearTaskFilters(): void
    {
        $this->reset([...self::ADVANCED_FILTERS, 'query', 'status']);
        session()->forget('task_search.'.Auth::id());
        $this->syncDraft();
        $this->selected = [];
        $this->resetPage();
    }

    /** Customers are listed once a file is open or a task search/filter is applied; otherwise the Excel files are listed. */
    private function showsCustomers(): bool
    {
        return $this->file !== '' || trim($this->query) !== '' || $this->status !== 'all'
            || array_filter([$this->priority, $this->rider, $this->from, $this->until], fn (string $value): bool => $value !== '') !== [];
    }

    public function allocate(): void
    {
        Gate::authorize('viewAny', Task::class);
        $this->validate(['selected' => ['required', 'array', 'min:1', 'max:200'], 'selected.*' => ['required', 'uuid', 'distinct']]);
        session()->put('task_selection.'.Auth::id(), $this->selected);
        $this->redirectRoute('admin.tasks.allocate');
    }

    public function deleteSelected(DeleteTasks $tasks): void
    {
        $deleted = $tasks->handle(Auth::user(), $this->selected);
        $this->selected = [];
        session()->flash('status', $deleted === 1 ? '1 task deleted.' : "{$deleted} tasks deleted.");
    }

    public function render(TaskAccess $access): View
    {
        Gate::authorize('viewAny', Task::class);
        $scope = Task::query();
        $tasks = clone $scope;
        match ($this->status) {
            'all' => null,
            'active' => $tasks->whereNotIn('work_status', ['completed', 'cancelled']),
            'unassigned' => $tasks->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled']),
            'assigned' => $tasks->whereHas('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled']),
            'unstarted','in_progress','completed','cancelled' => $tasks->where('work_status', $this->status),
            'pending_verification' => $tasks->where('work_status', 'submitted')->where('review_status', 'pending'),
            'returned' => $tasks->where('review_status', 'returned'),
            'visited','pending_completion' => $tasks->where('work_status', 'in_progress')->whereHas('activities', fn ($q) => $q->where('activity_type_code', 'visit')),
            'overdue' => $access->overdue($tasks),
            default => $tasks->whereRaw('false'),
        };
        if ($this->batch !== '') {
            $tasks->whereHas('batch', fn ($q) => $q->where('public_id', $this->validUuid($this->batch)));
        }
        $activeFile = in_array($this->file, ['', self::MANUAL_FILE], true) ? null : $this->taskFiles()->where('public_id', $this->validUuid($this->file))->first();
        if ($this->file === self::MANUAL_FILE) {
            $tasks->whereNull('import_batch_id')->whereNull('source_import_row_id');
        } elseif ($this->file !== '') {
            $activeFile ? $tasks->fromImport($activeFile->id) : $tasks->whereRaw('false');
        }
        if ($this->rider !== '') {
            $tasks->whereHas('currentAllocation.collector', fn ($q) => $q->where('public_id', $this->validUuid($this->rider)));
        }
        $tasks->when($this->priority !== '', fn ($q) => $q->where('priority_code', mb_substr($this->priority, 0, 64)));
        $search = trim(mb_substr($this->query, 0, 100));
        if ($search !== '') {
            $tasks->where(fn ($q) => $q->whereHas('customer', fn ($q) => $q->where('customer_reference', $search))->orWhereHas('account', fn ($q) => $q->where('account_number', $search))->orWhere('source_task_id', $search)->when(ctype_digit($search) && strlen($search) < 19, fn ($q) => $q->orWhere('source_task_number', (int) $search))->when(Str::isUuid($search), fn ($q) => $q->orWhere('tasks.public_id', $search)));
        }
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d']]);
        if ($dates->fails()) {
            $tasks->whereRaw('false');
        } else {
            $tasks->when($this->from !== '', fn ($q) => $q->where('tasks.created_at', '>=', Carbon::parse($this->from, config('dcms.business_timezone'))->utc()))->when($this->until !== '', fn ($q) => $q->where('tasks.created_at', '<', Carbon::parse($this->until, config('dcms.business_timezone'))->addDay()->utc()));
        }

        $selectedAssignment = $this->batch === '' ? null : AssignmentBatch::query()->where('public_id', $this->validUuid($this->batch))->first();
        $assignmentId = $this->batch === '' ? null : ($selectedAssignment?->id ?? 0);
        $excelFiles = $this->taskFiles()->when($assignmentId !== null, fn ($query) => $query->where('assignment_batch_id', $assignmentId))->limit(200)->get();
        $showsCustomers = $this->showsCustomers();
        $manualTasks = Task::query()->whereNull('import_batch_id')->whereNull('source_import_row_id')->when($assignmentId !== null, fn ($query) => $query->where('assignment_batch_id', $assignmentId));

        return view('livewire.admin.tasks.index', [
            'showsCustomers' => $showsCustomers,
            'activeFile' => $activeFile,
            'excelFiles' => $excelFiles,
            'fileList' => $showsCustomers ? null : $this->fileList($assignmentId),
            'manualSummary' => $showsCustomers ? null : ['customers' => (clone $manualTasks)->count(), 'unassigned' => (clone $manualTasks)->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'completed' => (clone $manualTasks)->where('work_status', 'completed')->count()],
            'tasks' => $showsCustomers ? $tasks->with(['batch', 'account', 'customer', 'currentAllocation.collector', 'latestBalance', 'importBatch.source', 'sourceRow' => fn ($query) => $query->select('id', 'import_batch_id'), 'sourceRow.import.source'])->withMax('history', 'occurred_at')->latest('id')->paginate(10) : null, 'batches' => AssignmentBatch::query()->where('status', 'active')->latest('id')->limit(200)->get(), 'riders' => Staff::query()->where('is_rider', true)->orderBy('first_name')->limit(200)->get(), 'activeFilterCount' => count(array_filter([$this->priority, $this->rider, $this->from.$this->until], fn (string $value): bool => $value !== '')), 'metrics' => ['active' => (clone $scope)->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'unassigned' => (clone $scope)->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'assigned' => (clone $scope)->whereHas('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'pending' => (clone $scope)->where('work_status', 'submitted')->where('review_status', 'pending')->count()]]);
    }

    /**
     * First view: uploaded Excel files with how many customers each created and their progress.
     *
     * @return LengthAwarePaginator<int, ImportBatch>
     */
    private function fileList(?int $assignmentId): LengthAwarePaginator
    {
        $tasksInFile = fn (): Builder => Task::query()->selectRaw('count(*)')->where(fn ($query) => $query->whereColumn('tasks.import_batch_id', 'import_batches.id')
            ->orWhereIn('tasks.source_import_row_id', ImportRow::query()->select('import_rows.id')->whereColumn('import_rows.import_batch_id', 'import_batches.id')));

        return $this->taskFiles()->with('uploader')
            ->when($assignmentId !== null, fn ($query) => $query->where('assignment_batch_id', $assignmentId))
            ->select('import_batches.*')
            ->addSelect([
                'customers_count' => $tasksInFile(),
                'unassigned_count' => $tasksInFile()->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled']),
                'completed_count' => $tasksInFile()->where('work_status', 'completed'),
            ])
            ->paginate(10);
    }

    /**
     * Uploaded Excel files that created tasks, newest first.
     *
     * @return Builder<ImportBatch>
     */
    private function taskFiles(): Builder
    {
        return ImportBatch::query()->with(['source', 'assignment'])->where('kind', 'task')->where('processed_count', '>', 0)->latest('id');
    }

    private function validUuid(string $value): string
    {
        return Str::isUuid($value) ? $value : '00000000-0000-0000-0000-000000000000';
    }
}
