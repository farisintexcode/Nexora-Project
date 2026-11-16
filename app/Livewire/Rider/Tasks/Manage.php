<?php

namespace App\Livewire\Rider\Tasks;

use App\Actions\Tasks\RiderBatchUpdate;
use App\Models\AssignmentBatch;
use App\Models\ReferenceValue;
use App\Services\Tasks\RiderAssignments;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** M06 / UI48–51: explicit Rider-only batch update selection; existing task policies apply. */
#[Layout('layouts.rider')]
class Manage extends Component
{
    use WithPagination;

    #[Url]
    public string $batch = '';

    public string $operation = 'dr_code';

    public string $value = '';

    public string $reason = '';

    public array $selection = [];

    #[Locked]
    public array $preview = [];

    private function context(RiderAssignments $assignments): ?AssignmentBatch
    {
        Gate::authorize('use-rider-interface');
        abort_unless(auth()->user()->can('tasks.submit'), 403);

        return $this->batch === '' ? null : $assignments->batches(auth()->user())->where('assignment_batches.public_id', $this->batch)->firstOrFail();
    }

    public function updated(string $property): void
    {
        $this->preview = [];
        $this->resetValidation();
        if ($property === 'batch') {
            $this->reset('selection');
            $this->resetPage();
        }
        if ($property === 'operation') {
            $this->value = '';
        }
    }

    public function previewUpdates(RiderBatchUpdate $updates, RiderAssignments $assignments): void
    {
        $batch = $this->context($assignments);
        $this->validate(['batch' => ['required', 'uuid']]);
        $this->preview = $updates->preview(auth()->user(), $batch, ['operation' => $this->operation, 'selection' => $this->selection, 'value' => $this->value, 'reason' => $this->reason]);
    }

    public function applyUpdates(RiderBatchUpdate $updates, RiderAssignments $assignments): void
    {
        $this->context($assignments);
        $count = $updates->apply(auth()->user(), $this->preview);
        $submitted = ($this->preview['input']['operation'] ?? '') === 'submit';
        $this->reset('selection', 'preview');
        $subject = $count.' '.Str::plural('task', $count);
        session()->flash('status', $submitted ? $subject.' submitted. Tasks with a payment wait for Admin verification; the rest are completed automatically.' : $subject.' updated. Previous records remain in history.');
    }

    public function cancelPreview(): void
    {
        $this->preview = [];
    }

    public function render(RiderAssignments $assignments): View
    {
        $batch = $this->context($assignments);
        $batches = $assignments->batches(auth()->user())->orderBy('assignment_batches.name')->get();
        $tasks = $batch ? $assignments->tasks(auth()->user())->where('assignment_batch_id', $batch->id)->with(['customer', 'account'])->orderBy('id')->paginate(20) : null;
        $choices = isset(RiderBatchUpdate::CATEGORIES[$this->operation]) ? ReferenceValue::query()->where('category', RiderBatchUpdate::CATEGORIES[$this->operation])->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get() : collect();

        return view('livewire.rider.tasks.manage', compact('batches', 'tasks', 'choices'));
    }
}
