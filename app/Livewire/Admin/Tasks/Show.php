<?php

namespace App\Livewire\Admin\Tasks;

use App\Models\Document;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class Show extends Component
{
    #[Locked]
    public string $taskId;

    public function mount(Task $task): void
    {
        $this->taskId = $task->public_id;
    }

    public function render(): View
    {
        $task = Task::query()->with(['batch', 'account.property', 'customer.contacts', 'currentAllocation.collector', 'history.actor', 'allocations.collector', 'balances.recorder'])->where('public_id', $this->taskId)->firstOrFail();
        abort_unless(Auth::user()->isActive(), 404);
        Gate::authorize('view', $task);

        return view('livewire.admin.tasks.show', [
            'task' => $task,
            'visits' => $task->activities()->where('activity_type_code', 'visit')->with(['performer', 'inspection', 'followUps'])->latest('id')->limit(30)->get(),
            'submissions' => $task->submissions()->with(['task.batch', 'submitter', 'documents.document', 'review.reviewer'])->latest('submission_version')->limit(20)->get(),
            'proof' => Document::query()->where('owner_type', 'task')->where('owner_id', $task->id)->where('document_type_code', 'proof_image')->latest('id')->limit(30)->get(),
        ]);
    }
}
