<?php

namespace App\Livewire\Admin\Assignments;

use App\Models\AssignmentBatch;
use App\Models\ReferenceValue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** UI09/10; search is session-private, filters are shareable, every query checks scope. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    private const ADVANCED_FILTERS = ['type', 'state', 'from', 'until'];

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $type = '';

    #[Url]
    public string $state = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $sort = 'newest';

    #[Url]
    public string $perPage = '25';

    public string $query = '';

    /**
     * Unapplied advanced filter form values; they reach the query only through applyFilters().
     *
     * @var array{type: string, state: string, from: string, until: string}
     */
    public array $draft = ['type' => '', 'state' => '', 'from' => '', 'until' => ''];

    public function mount(): void
    {
        $this->query = session('assignment_search.'.Auth::id(), '');
        $this->syncDraft();
        $this->rememberFilters();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'draft')) {
            return;
        }
        $this->resetPage();
        if ($property === 'query') {
            session()->put('assignment_search.'.Auth::id(), mb_substr($this->query, 0, 200));
        }
        $this->rememberFilters();
    }

    private function filters(): array
    {
        return array_filter(['status' => $this->status, 'type' => $this->type, 'state' => $this->state, 'from' => $this->from, 'until' => $this->until, 'sort' => $this->sort, 'perPage' => $this->perPage], fn ($value) => $value !== '');
    }

    private function rememberFilters(): void
    {
        session()->put('assignment_filters.'.Auth::id(), $this->filters());
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
        $this->resetPage();
        $this->rememberFilters();
    }

    public function resetAdvancedFilters(): void
    {
        $this->reset(self::ADVANCED_FILTERS);
        $this->syncDraft();
        $this->resetPage();
        $this->rememberFilters();
    }

    public function resetFilters(): void
    {
        $this->reset([...self::ADVANCED_FILTERS, 'query']);
        $this->syncDraft();
        session()->forget('assignment_search.'.Auth::id());
        $this->resetPage();
        $this->rememberFilters();
    }

    public function sortBy(string $column): void
    {
        $this->sort = match ($column) {
            'id' => $this->sort === 'newest' ? 'oldest' : 'newest',
            'name' => $this->sort === 'name_asc' ? 'name_desc' : 'name_asc',
            default => $this->sort,
        };
        $this->resetPage();
        $this->rememberFilters();
    }

    public function render(): View
    {
        Gate::authorize('viewAny', AssignmentBatch::class);
        $batches = AssignmentBatch::query()->with(['type', 'state']);
        in_array($this->status, ['active', 'archived'], true) ? $batches->where('status', $this->status) : $batches->whereRaw('false');
        $batches->when($this->type !== '', fn ($q) => $q->where('type_code', $this->type))->when($this->state !== '', fn ($q) => $q->where('state_code', $this->state));
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        if ($dates->fails()) {
            $batches->whereRaw('false');
        } else {
            $batches->when($this->from !== '', fn ($q) => $q->where('start_date', '>=', $this->from))->when($this->until !== '', fn ($q) => $q->where('start_date', '<=', $this->until));
        }
        $term = trim($this->query);
        if (mb_strlen($term) > 200) {
            $batches->whereRaw('false');
        } elseif ($term !== '') {
            $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $batches->where(fn ($q) => $q->where('name', 'ilike', $literal)->orWhere('external_reference', 'ilike', $literal)->when(ctype_digit($term) && strlen($term) < 10, fn ($q) => $q->orWhere('register_no', (int) $term)));
        }
        match ($this->sort) {
            'oldest' => $batches->orderBy('register_no'),
            'name', 'name_asc' => $batches->orderBy('name')->orderBy('id'),
            'name_desc' => $batches->orderByDesc('name')->orderByDesc('id'),
            default => $batches->orderByDesc('register_no'),
        };
        $batches->withCount([
            'tasks as upload_count',
            'tasks as assigned_count' => fn ($q) => $q->whereHas('currentAllocation'),
            'tasks as completed_count' => fn ($q) => $q->where('work_status', 'completed'),
            'tasks as payment_update_count' => fn ($q) => $q->where('payment_update_flag', true),
        ]);
        $size = in_array($this->perPage, ['10', '25', '50', '100'], true) ? (int) $this->perPage : 25;

        return view('livewire.admin.assignments.index', [
            'batches' => $batches->paginate($size),
            'types' => ReferenceValue::query()->where('category', 'assignment_type')->orderBy('sort_order')->get(),
            'states' => ReferenceValue::query()->where('category', 'state')->orderBy('label')->get(),
            'filterError' => $dates->fails() ? $dates->errors()->first() : null,
            'activeFilterCount' => count(array_filter(self::ADVANCED_FILTERS, fn (string $filter): bool => $this->{$filter} !== '')),
            'canViewTasks' => Auth::user()->can('tasks.view'),
        ]);
    }
}
