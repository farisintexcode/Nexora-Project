<?php

namespace App\Livewire\Admin\Staff;

use App\Actions\Staff\SaveStaff;
use App\Models\ReferenceValue;
use App\Models\Staff;
use App\Services\Staff\StaffAssignments;
use App\Services\Staff\StaffNumberGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.admin')]
class Editor extends Component
{
    #[Locked]
    public ?int $staffId = null;

    #[Locked]
    public ?string $staffPublicId = null;

    #[Locked]
    public ?string $revision = null;

    public array $data = [
        'staff_no' => '',
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'gender_code' => '',
        'system_role' => '',
        'create_account' => true,
        'phone' => '',
        'home_phone' => '',
        'address' => '',
        'is_active' => true,
        'is_rider' => false,
        'reason' => '',
    ];

    public function mount(?Staff $staff = null): void
    {
        if ($staff?->exists) {
            $staff->load('user.roles');
        }

        Gate::authorize(
            $staff?->exists ? 'update' : 'create',
            $staff?->exists ? $staff : Staff::class
        );

        if (! $staff?->exists) {
            $this->data['staff_no'] = app(StaffNumberGenerator::class)->next();

            return;
        }

        $this->staffId = $staff->id;
        $this->staffPublicId = $staff->public_id;
        $this->revision = $staff->revision();

        foreach ([
            'staff_no',
            'first_name',
            'last_name',
            'email',
            'gender_code',
        ] as $field) {
            $this->data[$field] = $staff->{$field} ?? '';
        }

        $this->data['system_role'] =
            $staff->user?->getRoleNames()->first()
            ?? ($staff->is_rider ? 'Rider' : 'Administrative');

        if ($this->data['system_role'] === 'Admin') {
            $this->data['system_role'] =
                $staff->user->can('roles.manage')
                    ? 'System Admin'
                    : 'Administrative';
        }

        $this->data['account_status'] =
            $staff->user?->status->value ?? 'invited';

        foreach ([
            'phone',
            'home_phone',
            'address',
        ] as $field) {
            $this->data[$field] =
                $staff->{$field.'_ciphertext'} ?? '';
        }

        $this->data['is_active'] = $staff->is_active;
        $this->data['is_rider'] = $staff->is_rider;
    }

    public function save(SaveStaff $save): void
    {
        $existing = $this->staffId
            ? Staff::query()->findOrFail($this->staffId)
            : null;

        $input = $this->data;

        unset($input['position_code']);

        $input['system_role'] ??= '';
        $input['create_account'] ??= true;

        $staff = $save->handle(
            Auth::user(),
            $input,
            $existing,
            $this->revision
        );

        $this->resetValidation();
        $this->reset('data');

        session()->flash(
            'status',
            'Staff record saved. '.match ($save->invitationDelivery) {
                'local_capture' => 'Account invited, but the array mailer captures locally and does not deliver email. Configure Mailpit or an approved transport, then reissue.',

                'mail_accepted' => 'Activation invitation accepted by the mail transport. Inbox delivery is not confirmed.',

                'delivery_failed' => 'Account invited, but email delivery failed. Reissue the invitation after the resend interval.',

                'unsafe_transport' => 'Account invited, but log mailers cannot receive activation secrets. Configure a safe transport and reissue.',

                'inactive_staff' => 'Account provisioned. Activate staff and reissue the invitation before password setup.',

                default => 'Invited accounts require personal password setup before access.',
            }
        );

        $this->redirectRoute(
            'admin.staff.show',
            ['staff' => $staff->public_id]
        );
    }

    public function render(StaffAssignments $assignments): View
    {
        $staff = $this->staffId
            ? Staff::query()->findOrFail($this->staffId)
            : null;

        Gate::authorize(
            $staff ? 'update' : 'create',
            $staff ?? Staff::class
        );

        return view('livewire.admin.staff.editor', [
            'staff' => $staff,

            'outstanding' => $staff
                ? $assignments->outstanding($staff)
                : null,

            'genders' => ReferenceValue::query()
                ->where('category', 'gender')
                ->where(
                    fn ($q) => $q
                        ->where('is_active', true)
                        ->orWhere('code', $staff?->gender_code)
                )
                ->orderBy('sort_order')
                ->get(),
        ]);
    }
}
