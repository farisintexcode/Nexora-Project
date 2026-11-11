<?php

namespace App\Livewire\Admin\Calls;

use App\Models\CollectionActivity;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Calls\CallAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M11/UI60: searchable scoped customer context, never legacy databank guesses. */
#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

    public string $query = '';

    #[Url]
    public string $searchBy = 'reference';

    #[Url]
    public string $rider = '';

    public function mount(): void
    {
        $this->query = session('calls.search.'.auth()->id(), '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'searchBy', 'rider'], true)) {
            $this->resetPage();
        }
        if ($property === 'query') {
            session()->put('calls.search.'.auth()->id(), mb_substr($this->query, 0, 200));
        }
    }

    public function render(CallAccess $access): View
    {
        Gate::authorize('viewAny', CollectionActivity::class);
        $customers = $access->search(auth()->user(), $this->only(['query', 'searchBy', 'rider']))->orderBy('customer_reference')->orderBy('id')->paginate(25);
        $canTasks = Gate::allows('viewAny', Task::class);
        $actor = auth()->user();
        $todayStart = now()->timezone(config('dcms.business_timezone'))->startOfDay()->utc();
        $myCalls = CollectionActivity::query()->where('performed_by', $actor->id)->where('activity_type_code', 'call');
        $pendingFollowUps = $access->followUps($actor)->where('status', 'pending');

        return view('livewire.admin.calls.index', [
            'stats' => [
                'callsToday' => (clone $myCalls)->where('occurred_at', '>=', $todayStart)->count(),
                'callsWeek' => (clone $myCalls)->where('occurred_at', '>=', $todayStart->subDays(6))->count(),
                'myFollowUps' => (clone $pendingFollowUps)->where('assigned_to', $actor->id)->count(),
                'overdue' => (clone $pendingFollowUps)->where('due_at', '<', now())->count(),
            ],
            'recentCalls' => (clone $myCalls)->with('customer')->latest('occurred_at')->limit(5)->get(),
            'searching' => trim($this->query) !== '' || $this->rider !== '',
            'customers' => $customers,
            'riders' => $canTasks ? Staff::query()->where('is_rider', true)->orderBy('staff_no')->limit(200)->get() : collect(),
            'canTasks' => $canTasks,
        ]);
    }
}
