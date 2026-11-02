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

    public function mount(string $batch): void
    {
        $this->batchId = $batch;
    }

    public function render(RiderAssignments $assignments): View
    {
        $batch = $assignments->batches(auth()->user())->where('assignment_batches.public_id', $this->batchId)->firstOrFail();
        $tasks = $assignments->tasks(auth()->user())->where('assignment_batch_id', $batch->id)->with(['customer', 'account', 'batch', 'latestBalance'])->orderByRaw('COALESCE(scheduled_visit_at,due_at) ASC NULLS LAST')->orderBy('id')->paginate(12);

        return view('livewire.rider.assignments.show', compact('batch', 'tasks'));
    }
}
