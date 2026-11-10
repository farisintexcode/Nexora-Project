<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ManageLeave;
use App\Models\LeaveType;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\LeaveAccess;
use App\Services\Attendance\LeaveBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public string $description = '';

    public bool $tracks_balance = false;

    public int $leaveYear = 2026;

    public int $entitlementDays = 0;

    public string $balanceStaff = '';

    public string $balanceType = '';

    public int $adjustmentDays = 0;

    public string $adjustmentReason = '';

    public function mount(): void
    {
        $this->authorizeConfiguration();
        $this->leaveYear = now()->timezone(config('dcms.business_timezone'))->year;
    }

    public function editType(string $id): void
    {
        $this->authorizeConfiguration();
        $type = LeaveType::query()->where('public_id', $id)->firstOrFail();
        $this->resetValidation();
        $this->typeId = $type->public_id;
        $this->typeVersion = $type->lock_version;
        $this->description = $type->description ?? '';
        $this->entitlementDays = (int) DB::table('leave_entitlements')->where('leave_type_id', $type->id)->where('leave_year', $this->leaveYear)->value('days');
        foreach (['tracks_balance', 'name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active'] as $field) {
            $this->$field = $type->$field;
        }
    }

    public function updatedLeaveYear(): void
    {
        $this->validate(['leaveYear' => ['required', 'integer', 'between:2000,2200']]);
        if ($this->typeId !== '') {
            $this->editType($this->typeId);
        }
    }

    public function newType(): void
    {
        $this->authorizeConfiguration();
        $this->reset('description', 'tracks_balance', 'entitlementDays', 'typeId', 'typeVersion', 'name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active');
        $this->resetValidation();
    }

    public function saveType(ManageLeave $workflow): void
    {
        DB::transaction(function () use ($workflow): void {
            $type = $workflow->configure(auth()->user(), $this->only(['description', 'tracks_balance', 'name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel', 'is_active']), $this->typeId ?: null, $this->typeVersion);
            if ($type->tracks_balance) {
                app(LeaveBalance::class)->entitlement(auth()->user(), $type, $this->leaveYear, $this->entitlementDays);
            }
        });
        $this->newType();
        session()->flash('status', 'Leave type saved. Existing applications keep their submitted rules.');
    }

    public function setActive(string $id, bool $active, int $version, ManageLeave $workflow): void
    {
        $this->authorizeConfiguration();
        $type = LeaveType::query()->where('public_id', $id)->firstOrFail();
        $workflow->configure(auth()->user(), [...$type->only(['description', 'tracks_balance', 'name', 'max_calendar_days', 'requires_document', 'allow_past_dates', 'allow_pending_cancel']), 'is_active' => $active], $id, $version);
        if ($this->typeId === $id) {
            $this->newType();
        }
        session()->flash('status', 'Leave type availability updated. Existing applications are preserved.');
    }

    public function adjustBalance(LeaveBalance $balances): void
    {
        $this->authorizeConfiguration();
        $this->validate(['balanceStaff' => ['required', 'uuid'], 'balanceType' => ['required', 'uuid']]);
        $type = LeaveType::query()->where('public_id', $this->balanceType)->firstOrFail();
        $balances->adjust(auth()->user(), $this->balanceStaff, $type, $this->leaveYear, $this->adjustmentDays, $this->adjustmentReason);
        $this->reset('adjustmentDays', 'adjustmentReason');
        session()->flash('status', 'Leave balance adjustment recorded.');
    }

    private function authorizeConfiguration(): void
    {
        abort_unless(app(LeaveAccess::class)->configure(auth()->user()->fresh()), 403);
    }

    public function render(): View
    {
        $this->authorizeConfiguration();

        $staff = Str::isUuid($this->balanceStaff) ? app(AttendanceAccess::class)->riders(auth()->user())->where('public_id', $this->balanceStaff)->first() : null;
        $type = Str::isUuid($this->balanceType) ? LeaveType::query()->where('public_id', $this->balanceType)->first() : null;
        $balance = $staff && $type ? app(LeaveBalance::class)->forStaff($staff->id, $type, $this->leaveYear) : null;
        $adjustments = $staff && $type ? DB::table('leave_balance_adjustments as a')->join('users as u', 'u.id', '=', 'a.authorized_by')->where('a.rider_id', $staff->id)->where('a.leave_type_id', $type->id)->where('a.leave_year', $this->leaveYear)->select('a.*', 'u.name as author_name')->orderByDesc('a.id')->limit(50)->get() : collect();

        return view('livewire.admin.attendance.leave-types', ['adjustments' => $adjustments, 'balance' => $balance, 'riders' => app(AttendanceAccess::class)->riders(auth()->user())->orderBy('staff_no')->get(), 'entitlements' => DB::table('leave_entitlements')->where('leave_year', $this->leaveYear)->pluck('days', 'leave_type_id'), 'types' => LeaveType::query()->withCount('requests')->orderBy('name')->get()]);
    }
}
