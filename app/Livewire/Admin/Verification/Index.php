<?php

namespace App\Livewire\Admin\Verification;

use App\Models\AssignmentBatch;
use App\Models\Staff;
use App\Models\TaskSubmission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    private const ADVANCED_FILTERS = ['rider', 'from', 'until'];

    #[Url]
    public string $status = 'pending';

    #[Url]
    public string $batch = '';

    #[Url]
    public string $rider = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public string $query = '';

    /**
     * Unapplied filter panel values; they reach the query only through applyFilters().
     *
     * @var array{rider: string, from: string, until: string}
     */
    public array $draft = ['rider' => '', 'from' => '', 'until' => ''];

    public function mount(): void
    {
        $this->query = session('verification_search.'.Auth::id(), '');
        $this->syncDraft();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'draft')) {
            return;
        }
        $this->resetPage();
        if ($property === 'query') {
            session()->put('verification_search.'.Auth::id(), mb_substr($this->query, 0, 100));
        }
    }

    private function syncDraft(): void
    {
        foreach (self::ADVANCED_FILTERS as $filter) {
            $this->draft[$filter] = $this->{$filter};
        }
    }

    private function filtersChanged(): void
    {
        $this->syncDraft();
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        foreach (self::ADVANCED_FILTERS as $filter) {
            $value = $this->draft[$filter] ?? '';
            $this->{$filter} = is_string($value) ? mb_substr(trim($value), 0, 64) : '';
        }
        $this->filtersChanged();
    }

    public function resetAdvancedFilters(): void
    {
        $this->reset(self::ADVANCED_FILTERS);
        $this->filtersChanged();
    }

    public function resetFilters(): void
    {
        $this->reset([...self::ADVANCED_FILTERS, 'query', 'status', 'batch']);
        session()->forget('verification_search.'.Auth::id());
        $this->filtersChanged();
    }

    /** Re-render the queue when the header bell sees new submissions. */
    #[On('pending-reviews-changed')]
    public function refreshQueue(): void {}

    public function render(): View
    {
        Gate::authorize('viewAny', TaskSubmission::class);
        $query = TaskSubmission::query()->with(['task.batch', 'task.customer', 'submitter', 'review']);
        match ($this->status) {
            'pending' => $query->whereDoesntHave('review')->whereHas('task', fn ($q) => $q->where('work_status', 'submitted')->where('review_status', 'pending')),
            'approved','returned' => $query->whereHas('review', fn ($q) => $q->where('decision', $this->status)),
            'all' => null,
            default => $query->whereRaw('false'),
        };
        if ($this->batch !== '') {
            $query->whereHas('task.batch', fn ($q) => $q->where('public_id', Str::isUuid($this->batch) ? $this->batch : '00000000-0000-0000-0000-000000000000'));
        }
        if ($this->rider !== '') {
            $query->whereHas('submitter.staff', fn ($q) => $q->where('public_id', Str::isUuid($this->rider) ? $this->rider : '00000000-0000-0000-0000-000000000000'));
        }
        $dates = Validator::make(['from' => $this->from ?: null, 'until' => $this->until ?: null], ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        if ($dates->fails()) {
            $query->whereRaw('false');
        } else {
            if ($this->from !== '') {
                $query->where('submitted_at', '>=', Carbon::parse($this->from, config('dcms.business_timezone'))->startOfDay()->utc());
            }
            if ($this->until !== '') {
                $query->where('submitted_at', '<', Carbon::parse($this->until, config('dcms.business_timezone'))->addDay()->startOfDay()->utc());
            }
        }
        if (trim($this->query) !== '') {
            $term = mb_substr(trim($this->query), 0, 100);
            $query->whereHas('task', fn ($q) => $q->whereHas('account', fn ($a) => $a->where('account_number', $term))->orWhereHas('customer', fn ($c) => $c->where('customer_reference', $term)));
        }
        session()->put('verification_filters.'.Auth::id(), ['status' => $this->status, 'batch' => $this->batch, 'rider' => $this->rider, 'from' => $this->from, 'until' => $this->until]);

        return view('livewire.admin.verification.index', [
            'submissions' => $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20), 'dateError' => $dates->fails() ? 'Choose a valid submission date range (MYT).' : null, 'activeFilterCount' => count(array_filter([$this->rider, $this->from.$this->until], fn (string $value): bool => $value !== '')),
            'batches' => AssignmentBatch::query()->where('status', 'active')->orderByDesc('id')->limit(200)->get(),
            'riders' => Staff::query()->whereHas('user', fn ($u) => $u->whereIn('id', TaskSubmission::query()->select('submitted_by')))->orderBy('first_name')->limit(200)->get(),
        ]);
    }
}
