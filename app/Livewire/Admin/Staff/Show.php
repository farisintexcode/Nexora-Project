<?php

namespace App\Livewire\Admin\Staff;

use App\Actions\Staff\InviteStaffAccount;
use App\Actions\Staff\RecoverStaffAccount;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Staff\StaffAssignments;
use App\Services\Staff\StaffTasks;
use App\Services\Tasks\WorkVolume;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $staffId;

    #[Locked]
    public string $revision;

    #[Url]
    public string $tab = 'profile';

    #[Url(as: 'tasks')]
    public string $taskFilter = 'open';

    #[Url]
    public string $period = 'daily';

    #[Url]
    public string $range = '14';

    public string $invitationRole = '';

    public string $unlockReason = '';

    public function mount(Staff $staff): void
    {
        Gate::authorize('view', $staff);
        $this->staffId = $staff->id;
        $this->revision = $staff->revision();
        $this->invitationRole = $staff->user?->getRoleNames()->first() ?? '';
        if ($this->invitationRole === 'Admin') {
            $this->invitationRole = $staff->user->can('roles.manage') ? 'System Admin' : 'Administrative';
        }
        $choices = InviteStaffAccount::invitableRolesFor(Auth::user(), $staff);
        if ($this->invitationRole === '' && count($choices) === 1) {
            $this->invitationRole = $choices[0];
        }
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->resetPage('tasksPage');
    }

    public function updatedPeriod(): void
    {
        $this->range = $this->period === 'monthly' ? '6' : '14';
    }

    public function updatedTaskFilter(): void
    {
        $this->resetPage('tasksPage');
    }

    /** Jump from a performance metric to the task list behind it. */
    public function showTasks(string $tab, string $filter = 'open'): void
    {
        $this->tab = $tab === 'completed' ? 'completed' : 'assigned';
        $this->taskFilter = array_key_exists($filter, StaffTasks::ASSIGNED_FILTERS) ? $filter : 'open';
        $this->resetPage('tasksPage');
    }

    public function invite(InviteStaffAccount $invite): void
    {
        $staff = Staff::query()->findOrFail($this->staffId);
        $status = $invite->handle(Auth::user(), $staff, $this->invitationRole, $this->revision);
        $this->revision = $staff->revision();
        session()->flash(InviteStaffAccount::needsAttention($status) ? 'warning' : 'status', match ($status) {
            'mail_accepted' => 'Invitation accepted by the mail transport. This does not confirm inbox delivery.',
            'local_capture' => 'Invitation prepared for local capture only. No email was delivered; configure Mailpit or an approved transport for activation.',
            'delivery_failed' => 'The account invitation is prepared, but mail transport failed. Wait for the resend interval, then reissue the invitation.',
            'unsafe_transport' => 'Invitation prepared, but this mail configuration includes a log transport. Activation secrets cannot be logged. Configure a safe transport and reissue.',
            'inactive_staff' => 'Account provisioned. Activate the staff record and reissue the invitation before password setup.',
            default => 'Invitation prepared. The email is sent once the save is confirmed.',
        });
    }

    /** System Admin recovery: email a fresh password reset link to the account owner. */
    public function sendPasswordReset(RecoverStaffAccount $recover): void
    {
        $status = $recover->sendPasswordReset(Auth::user(), Staff::query()->findOrFail($this->staffId));
        session()->flash($status === 'delivery_failed' ? 'warning' : 'status', match ($status) {
            'mail_accepted' => 'Password reset link accepted by the mail transport. This does not confirm inbox delivery.',
            'local_capture' => 'Password reset link prepared for local capture only. No email was delivered.',
            default => 'The mail transport failed, so no reset link was delivered. Check the mail settings, then try again.',
        });
    }

    /** System Admin recovery: release a locked account after recording why. */
    public function unlock(RecoverStaffAccount $recover): void
    {
        $account = $recover->unlock(Auth::user(), Staff::query()->findOrFail($this->staffId), $this->unlockReason);
        $this->unlockReason = '';
        session()->flash('status', $account->status->value === 'active'
            ? 'Account unlocked. The user can log in with their existing password, or reset it from the login page.'
            : 'Account unlocked. Password setup was never completed, so it is back to invited. Reissue the invitation.');
    }

    public function render(StaffAssignments $assignments, StaffTasks $tasks): View
    {
        $staff = Staff::query()->with(['position', 'gender', 'workState', 'user'])->findOrFail($this->staffId);
        Gate::authorize('view', $staff);
        $canViewTasks = Gate::allows('viewAny', Task::class);
        $tabs = $this->sections($staff, $canViewTasks);
        $tab = array_key_exists($this->tab, $tabs) ? $this->tab : 'profile';
        $taskFilter = array_key_exists($this->taskFilter, StaffTasks::ASSIGNED_FILTERS) ? $this->taskFilter : 'open';
        $trendPeriod = array_key_exists($this->period, WorkVolume::RANGES) ? $this->period : 'daily';
        $trendRange = in_array((int) $this->range, WorkVolume::RANGES[$trendPeriod], true) ? (int) $this->range : WorkVolume::RANGES[$trendPeriod][1];

        return view('livewire.admin.staff.show', [
            'staff' => $staff, 'tabs' => $tabs, 'activeTab' => $tab, 'invitableRoles' => InviteStaffAccount::invitableRolesFor(Auth::user(), $staff), 'outstanding' => $assignments->outstanding($staff),
            'activity' => $tab === 'activity' ? AuditLog::query()->where('module', 'M10')->where('entity_type', 'staff')->where('entity_id', $staff->public_id)->with('actor:id,name')->orderByDesc('id')->paginate(25) : null,
            'canViewTasks' => $canViewTasks, 'taskFilters' => StaffTasks::ASSIGNED_FILTERS, 'activeTaskFilter' => $taskFilter,
            'tasks' => $canViewTasks ? match ($tab) {
                'assigned' => $tasks->assigned($staff, $taskFilter)->paginate(10, pageName: 'tasksPage'),
                'completed' => $tasks->completed($staff)->paginate(10, pageName: 'tasksPage'),
                default => null,
            } : null,
            'performance' => $canViewTasks && in_array($tab, ['assigned', 'completed', 'performance'], true) ? $tasks->performance($staff) : null,
            'trend' => $canViewTasks && $tab === 'performance' ? $tasks->trend($staff, $trendPeriod, $trendRange) : null,
            'trendPeriod' => $trendPeriod, 'trendRange' => $trendRange,
        ]);
    }

    /**
     * Sections shown for this staff member. Office staff (Administrative, System Admin) do no field work, so only Rider
     * records have task, activity, attendance and performance sections, and each also needs the viewer's own access.
     *
     * @return array<string, string>
     */
    private function sections(Staff $staff, bool $canViewTasks): array
    {
        if (! $staff->is_rider) {
            return ['profile' => 'Profile'];
        }

        return array_filter([
            'profile' => 'Profile',
            'assigned' => $canViewTasks ? 'Assigned tasks' : null,
            'completed' => $canViewTasks ? 'Completed tasks' : null,
            'activity' => 'Activity',
            'attendance' => app(AttendanceAccess::class)->visible(Auth::user(), $staff->id) ? 'Attendance' : null,
            'performance' => $canViewTasks ? 'Performance' : null,
        ]);
    }
}
