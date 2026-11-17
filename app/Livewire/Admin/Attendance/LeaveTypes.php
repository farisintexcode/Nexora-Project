<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ManageLeave;
use App\Models\LeaveType;
use App\Services\Attendance\LeaveAccess;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** M10 / leave_types: permission-protected organization leave-policy configuration. */
#[Layout('layouts.admin')]
class LeaveTypes extends Component
{
    #[Locked]
    public string $typeId = '';

    #[Locked]
    public int $typeVersion = 0;

    public string $name = '';

    /** Working days a Rider may take each year; the same limit applies every year. */
    public ?int $days_per_year = null;

    public bool $requires_document = false;

    public bool $allow_past_dates = false;

    public bool $allow_pending_cancel = false;

    public bool $is_active = true;

    public string $description = '';

    public function mount(): void
    {
        $this->authorizeConfiguration();
    }

    public function editType(string $id): void
    {
        $this->authorizeConfiguration();
        $type = LeaveType::query()->where('public_id', $id)->firstOrFail();
        $this->resetValidation();
        $this->typeId = $type->public_id;
        $this->typeVersion = $type->lock_version;
        $this->description = $type->description ?? '';
        foreach (['name', 'days_per_year', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active'] as $field) {
            $this->$field = $type->$field;
        }
    }

    public function newType(): void
    {
        $this->authorizeConfiguration();
        $this->reset('description', 'typeId', 'typeVersion', 'name', 'days_per_year', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active');
        $this->resetValidation();
    }

    public function saveType(ManageLeave $workflow): void
    {
        $this->validate(['days_per_year' => ['required', 'integer', 'between:0,366']], ['days_per_year.required' => 'Enter the maximum leave days per year.']);
        $workflow->configure(auth()->user(), $this->only(['description', 'name', 'days_per_year', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active']), $this->typeId ?: null, $this->typeVersion);
        $this->newType();
        session()->flash('status', 'Leave type saved. Existing applications keep their submitted rules.');
    }

    public function deleteType(string $id, ManageLeave $workflow): void
    {
        $this->authorizeConfiguration();
        $workflow->deleteType(auth()->user(), $id);
        if ($this->typeId === $id) {
            $this->newType();
        }
        session()->flash('status', 'Leave type deleted.');
    }

    public function setActive(string $id, bool $active, int $version, ManageLeave $workflow): void
    {
        $this->authorizeConfiguration();
        $type = LeaveType::query()->where('public_id', $id)->firstOrFail();
        $workflow->configure(auth()->user(), [...$type->only(['description', 'name', 'days_per_year', 'requires_document', 'allow_past_dates', 'allow_pending_cancel']), 'is_active' => $active], $id, $version);
        if ($this->typeId === $id) {
            $this->newType();
        }
        session()->flash('status', 'Leave type availability updated. Existing applications are preserved.');
    }

    private function authorizeConfiguration(): void
    {
        abort_unless(app(LeaveAccess::class)->configure(auth()->user()->fresh()), 403);
    }

    public function render(): View
    {
        $this->authorizeConfiguration();

        return view('livewire.admin.attendance.leave-types', ['types' => LeaveType::query()->withCount('requests')->orderBy('name')->get()]);
    }
}
