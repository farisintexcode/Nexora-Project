<?php

namespace App\Livewire\Rider\Assignments;

use App\Services\Tasks\RiderAssignments;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.rider')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public string $batchId;

    /**
     * Task lists on this page, each with the work statuses it holds. Unstarted also holds work in progress or returned
     * for correction, because the Rider still has to finish it.
     *
     * @var array<string, array<int, string>>
     */
    public const LISTS = [
        'unstarted' => ['unstarted', 'in_progress'],
        'pending' => ['submitted'],
        'completed' => ['completed'],
    ];

    public string $list = 'unstarted';

    public function mount(string $batch): void
    {
        $this->batchId = $batch;
    }

    public function updatedList(): void
    {
        $this->resetPage();
    }

    public function render(RiderAssignments $assignments): View
    {
        if (! array_key_exists($this->list, self::LISTS)) {
            $this->list = 'unstarted';
        }
        $batch = $assignments->batches(auth()->user())->where('assignment_batches.public_id', $this->batchId)->firstOrFail();
        $tasks = $assignments->tasks(auth()->user())->where('assignment_batch_id', $batch->id)->whereIn('work_status', self::LISTS[$this->list])
            ->with(['customer', 'account', 'batch', 'latestBalance'])->orderByRaw('COALESCE(scheduled_visit_at,due_at) ASC NULLS LAST')->orderBy('id')->paginate(12);
        $listCounts = ['unstarted' => (int) $batch->pending_count, 'pending' => (int) $batch->submitted_count, 'completed' => (int) $batch->verified_count];

        return view('livewire.rider.assignments.show', compact('batch', 'tasks', 'listCounts'));
    }
}
