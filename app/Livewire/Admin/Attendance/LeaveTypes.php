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

    public int $max_calendar_days = 1;

    public bool $requires_document = false;

    public bool $allow_past_dates = false;

    public bool $allow_pending_cancel = false;

    public bool $is_active = true;

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
        foreach (['name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active'] as $field) {
            $this->$field = $type->$field;
        }
    }

    public function newType(): void
    {
        $this->authorizeConfiguration();
        $this->reset('typeId', 'typeVersion', 'name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active');
        $this->resetValidation();
    }

    public function saveType(ManageLeave $workflow): void
    {
        $workflow->configure(auth()->user(), $this->only(['name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active']), $this->typeId ?: null, $this->typeVersion);
        $this->newType();
        session()->flash('status', 'Leave type saved. Existing applications keep their submitted rules.');
    }

    public function setActive(string $id, bool $active, int $version, ManageLeave $workflow): void
    {
        $this->authorizeConfiguration();
        $type = LeaveType::query()->where('public_id', $id)->firstOrFail();
        $workflow->configure(auth()->user(), [...$type->only(['name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel']), 'is_active' => $active], $id, $version);
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
