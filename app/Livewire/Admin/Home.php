<?php

namespace App\Livewire\Admin;

use App\Models\AssignmentBatch;
use App\Services\AttentionQueue;
use App\Services\Tasks\TaskProgress;
use App\Services\Tasks\WorkVolume;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.admin')]
class Home extends Component
{
    #[Url]
    public string $period = 'daily';

    #[Url]
    public string $range = '7';

    #[Url]
    public string $assignment = '';

    /** Re-render the attention cards when the header bell sees new tasks or leave waiting. */
    #[On('pending-reviews-changed')]
    public function refreshAttention(): void {}

    public function updatedPeriod(): void
    {
        $this->range = $this->period === 'monthly' ? '12' : '7';
    }

    public function render(AttentionQueue $attention, TaskProgress $taskProgress, WorkVolume $workVolume): View
    {
        Gate::authorize('admin.access');

        $progress = $taskProgress->forUser(Auth::user());
        $assignments = $progress === null ? collect() : AssignmentBatch::query()->where('status', 'active')
            ->orderByDesc('id')->get(['id', 'public_id', 'assignment_code', 'name', 'external_reference']);
        $selectedAssignment = $assignments->firstWhere('public_id', $this->assignment);
        $report = $selectedAssignment === null ? $progress : $taskProgress->forUser(Auth::user(), $selectedAssignment->id, null);

        $period = array_key_exists($this->period, WorkVolume::RANGES) ? $this->period : 'daily';
        $range = in_array((int) $this->range, WorkVolume::RANGES[$period], true) ? (int) $this->range : WorkVolume::RANGES[$period][0];
        $volume = $workVolume->forUser(Auth::user(), $period, $range);

        return view('livewire.admin.home', [
            'attention' => $attention->forUser(Auth::user()),
            'progress' => $progress,
            'assignmentProgress' => $taskProgress->assignments(Auth::user()),
            'report' => $report,
            'assignments' => $assignments,
            'selectedAssignment' => $selectedAssignment,
            'volume' => $volume,
            'volumePeriod' => $period,
            'volumeRange' => $range,
        ]);
    }
}
