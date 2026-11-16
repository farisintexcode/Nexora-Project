<?php

namespace App\Livewire\Rider\Assignments;

use App\Services\Tasks\RiderAssignments;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.rider')]
class Index extends Component
{
    use WithPagination;

    /** Assignment tabs: active work first, archived (finished) assignments kept for reference. */
    public const TABS = ['active' => 'Active', 'archived' => 'Archived'];

    public string $tab = 'active';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(RiderAssignments $assignments): View
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'active';
        }
        $counts = $assignments->batches(auth()->user())->reorder()->toBase()
            ->select('assignment_batches.status')->selectRaw('count(*) as total')->groupBy('assignment_batches.status')->pluck('total', 'status');

        return view('livewire.rider.assignments.index', [
            'assignments' => $assignments->batches(auth()->user())->where('assignment_batches.status', $this->tab)->paginate(10),
            'tabCounts' => collect(self::TABS)->mapWithKeys(fn (string $label, string $status): array => [$status => (int) ($counts[$status] ?? 0)])->all(),
        ]);
    }
}
