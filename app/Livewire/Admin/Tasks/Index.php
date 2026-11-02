<?php

namespace App\Livewire\Admin\Tasks;

use App\Actions\Tasks\DeleteTasks;
use App\Models\AssignmentBatch;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Tasks\TaskAccess;
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

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $batch = '';

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
        $this->reset([...self::ADVANCED_FILTERS, 'query', 'status', 'batch']);
        $this->syncDraft();
        session()->forget('task_search.'.Auth::id());
        $this->selected = [];
        $this->resetPage();
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

        return view('livewire.admin.tasks.index', ['tasks' => $tasks->with(['batch', 'account', 'customer', 'currentAllocation.collector', 'latestBalance'])->withMax('history', 'occurred_at')->latest('id')->paginate(25), 'batches' => AssignmentBatch::query()->latest('id')->limit(200)->get(), 'riders' => Staff::query()->where('is_rider', true)->orderBy('first_name')->limit(200)->get(), 'activeFilterCount' => count(array_filter([$this->priority, $this->rider, $this->from.$this->until], fn (string $value): bool => $value !== '')), 'metrics' => ['active' => (clone $scope)->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'unassigned' => (clone $scope)->whereDoesntHave('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'assigned' => (clone $scope)->whereHas('currentAllocation')->whereNotIn('work_status', ['completed', 'cancelled'])->count(), 'pending' => (clone $scope)->where('work_status', 'submitted')->where('review_status', 'pending')->count()]]);
    }

    private function validUuid(string $value): string
    {
        return Str::isUuid($value) ? $value : '00000000-0000-0000-0000-000000000000';
    }
}
