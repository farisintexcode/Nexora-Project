<?php

namespace App\Livewire\Admin\Attendance;

use App\Actions\Attendance\ManageLeave;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Notifications\LeaveUpdated;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\LeaveAccess;
use App\Services\Attendance\LeaveBalance;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/** M10 / Admin Human Resources: permission-scoped leave management and independent review. */
#[Layout('layouts.admin')]
class LeaveManagement extends Component
{
    use WithPagination;

    #[Locked]
    public string $requestId = '';

    #[Locked]
    public int $requestVersion = 0;

    public string $status = '';

    public string $search = '';

    public string $rider = '';

    public string $filterType = '';

    public string $from = '';

    public string $until = '';

    public string $decision = '';

    public string $remarks = '';

    public function mount(?LeaveRequest $leave = null): void
    {
        $access = app(LeaveAccess::class);
        abort_unless($access->admin(auth()->user()), 403);
        if ($leave?->exists) {
            abort_unless($access->view(auth()->user(), $leave), 404);
            $this->requestId = $leave->public_id;
            $this->requestVersion = $leave->lock_version;
        }
    }

    public function decide(ManageLeave $workflow, LeaveAccess $access): void
    {
        $request = $access->scope(auth()->user())->where('public_id', $this->requestId)->firstOrFail();
        $workflow->decide(auth()->user(), $request, $this->decision, $this->remarks, $this->requestVersion);
        session()->flash('status', 'The leave decision has been saved.');
        $this->redirectRoute('admin.leave.show', ['leave' => $request->public_id]);
    }

    public function filterRequests(): void
    {
        $this->validate(['rider' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:pending,approved,rejected,cancelled'], 'search' => ['nullable', 'string', 'max:100'], 'filterType' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->from !== '' ? ['after_or_equal:from'] : [])]]);
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('status', 'search', 'rider', 'filterType', 'from', 'until');
        $this->resetPage();
        $this->resetValidation();
    }

    public function render(LeaveAccess $access): View
    {
        $actor = auth()->user();
        abort_unless($access->admin($actor), 403);
        $selected = $this->requestId ? $access->scope($actor)->with('rider', 'requester', 'reviewer', 'document', 'schedules', 'leaveType')->where('public_id', $this->requestId)->firstOrFail() : null;
        $query = $access->scope($actor)->with('rider');
        if (Str::isUuid($this->rider)) {
            $query->whereHas('rider', fn ($q) => $q->where('public_id', $this->rider));
        }
        if (in_array($this->status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $query->where('status', $this->status);
        }
        if (Str::isUuid($this->filterType)) {
            $query->whereHas('leaveType', fn ($q) => $q->where('public_id', $this->filterType));
        }
        if ($this->search !== '') {
            $search = '%'.mb_substr($this->search, 0, 100).'%';
            $query->whereHas('rider', fn ($q) => $q->where(fn ($names) => $names->where('first_name', 'ilike', $search)->orWhere('last_name', 'ilike', $search)->orWhere('staff_no', 'ilike', $search)));
        }
        foreach (['from' => 'end_date', 'until' => 'start_date'] as $field => $column) {
            $date = $this->$field;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $query->where($column, $field === 'from' ? '>=' : '<=', $date);
            }
        }
        $counts = (clone $query)->reorder()->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $conflicts = $selected ? Attendance::query()->where('rider_id', $selected->rider_id)->whereBetween('service_date', [$selected->start_date, $selected->end_date])->count() : 0;

        $selectedBalance = $selected?->leaveType ? app(LeaveBalance::class)->forStaff($selected->rider_id, $selected->leaveType, $selected->start_date->year) : null;

        return view('livewire.admin.attendance.leave-management', ['selectedBalance' => $selectedBalance, 'riders' => app(AttendanceAccess::class)->riders($actor)->orderBy('staff_no')->get(), 'selected' => $selected, 'requests' => $query->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20), 'types' => LeaveType::query()->orderBy('name')->get(), 'counts' => $counts, 'canReview' => $access->review($actor), 'canConfigure' => $access->configure($actor), 'conflicts' => $conflicts, 'updates' => $actor->notifications()->where('type', LeaveUpdated::class)->latest()->limit(5)->get()]);
    }
}
