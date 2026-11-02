<?php

namespace App\Livewire\Rider\Tasks;

use App\Models\Task;
use App\Models\TaskSubmission;
use App\Services\Tasks\RiderAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.rider')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    #[Url]
    public string $batch = '';

    #[Url]
    public string $due = '';

    public string $query = '';

    public function mount(): void
    {
        $this->query = session('rider_search.'.Auth::id(), '');
    }

    public function updated(string $property): void
    {
        $this->resetPage();
        if ($property === 'query') {
            session()->put('rider_search.'.Auth::id(), mb_substr($this->query, 0, 100));
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'batch', 'due', 'query');
        $this->resetPage();
    }

    public function render(RiderAccess $access): View
    {
        Gate::authorize('use-rider-interface');
        abort_unless(Auth::user()->can('tasks.view'), 403);
        $this->validate(['status' => ['required', Rule::in(['all', 'today', 'pending', 'assigned', 'in_progress', 'returned', 'submitted', 'completed', 'history'])],
            'batch' => ['nullable', 'string', 'max:100'], 'due' => ['nullable', 'date_format:Y-m-d'], 'query' => ['nullable', 'string', 'max:100']]);
        $tasks = $access->scope(Task::query(), Auth::user())->with(['batch', 'customer', 'account', 'currentAllocation', 'latestBalance']);
        match ($this->status) {
            'today' => $tasks->whereRaw('(COALESCE(scheduled_visit_at,due_at,((SELECT due_date FROM assignment_batches WHERE id=tasks.assignment_batch_id)+1)::timestamp AT TIME ZONE ? - interval \'1 microsecond\') AT TIME ZONE ?)::date=?', [config('dcms.business_timezone'), config('dcms.business_timezone'), now()->timezone(config('dcms.business_timezone'))->toDateString()]),
            'pending' => $tasks->whereIn('work_status', ['unstarted', 'in_progress']),
            'assigned' => $tasks->where('work_status', 'unstarted'),
            'in_progress' => $tasks->where('work_status', 'in_progress')->where('review_status', 'none'),
            'returned' => $tasks->where('review_status', 'returned'),
            'submitted','completed' => $tasks->where('work_status', $this->status),
            default => null,
        };
        if ($this->batch !== '') {
            $tasks->whereHas('batch', fn ($q) => $q->where('external_reference', $this->batch));
        }
        if ($this->due !== '') {
            $start = Carbon::parse($this->due, config('dcms.business_timezone'))->startOfDay()->utc();
            $tasks->where(fn ($q) => $q->where(fn ($q) => $q->where('due_at', '>=', $start)->where('due_at', '<', $start->copy()->addDay()))->orWhere(fn ($q) => $q->whereNull('due_at')->whereHas('batch', fn ($b) => $b->whereDate('due_date', $this->due))));
        }
        if (trim($this->query) !== '') {
            $term = trim($this->query);
            $tasks->where(fn ($q) => $q->whereHas('account', fn ($a) => $a->where('account_number', $term))->orWhereHas('customer', fn ($c) => $c->where('customer_reference', $term)));
        }
        $history = TaskSubmission::query()->where('submitted_by', Auth::id())->orderByDesc('submitted_at')->orderByDesc('id');

        return view('livewire.rider.tasks.index', ['tasks' => $this->status === 'history' ? null : $tasks->orderByRaw('COALESCE(scheduled_visit_at,due_at) ASC NULLS LAST')->orderBy('id')->paginate(12), 'history' => $this->status === 'history' ? $history->paginate(12) : null]);
    }
}
