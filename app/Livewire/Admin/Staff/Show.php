<?php

namespace App\Livewire\Admin\Staff;

use App\Actions\Staff\InviteStaffAccount;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Services\Staff\StaffAssignments;
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

    public string $invitationRole = '';

    public function mount(Staff $staff): void
    {
        Gate::authorize('view', $staff);
        $this->staffId = $staff->id;
        $this->revision = $staff->revision();
        $this->invitationRole = $staff->user?->getRoleNames()->first() ?? '';
        if ($this->invitationRole === 'Admin') {
            $this->invitationRole = $staff->user->can('roles.manage') ? 'System Admin' : 'Administrative';
        }
    }

    public function invite(InviteStaffAccount $invite): void
    {
        $staff = Staff::query()->findOrFail($this->staffId);
        $status = $invite->handle(Auth::user(), $staff, $this->invitationRole, $this->revision);
        $this->revision = $staff->revision();
        session()->flash('status', match ($status) {
            'mail_accepted' => 'Invitation accepted by the mail transport. This does not confirm inbox delivery.',
            'local_capture' => 'Invitation prepared for local capture only. No email was delivered; configure Mailpit or an approved transport for activation.',
            'delivery_failed' => 'The account invitation is prepared, but mail transport failed. Wait for the resend interval, then reissue the invitation.',
            'unsafe_transport' => 'Invitation prepared, but this mail configuration includes a log transport. Activation secrets cannot be logged. Configure a safe transport and reissue.',
            'inactive_staff' => 'Account provisioned. Activate the staff record and reissue the invitation before password setup.',
            default => 'Invitation prepared. Mail is waiting for transaction commit.',
        });
    }

    public function render(StaffAssignments $assignments): View
    {
        $staff = Staff::query()->with(['position', 'gender', 'user'])->findOrFail($this->staffId);
        Gate::authorize('view', $staff);
        $tab = in_array($this->tab, ['profile', 'assigned', 'completed', 'activity', 'attendance', 'performance'], true) ? $this->tab : 'profile';

        return view('livewire.admin.staff.show', [
            'staff' => $staff, 'activeTab' => $tab, 'outstanding' => $assignments->outstanding($staff),
            'activity' => $tab === 'activity' ? AuditLog::query()->where('module', 'M10')->where('entity_type', 'staff')->where('entity_id', $staff->public_id)->with('actor:id,name')->orderByDesc('id')->paginate(25) : null,
        ]);
    }
}
