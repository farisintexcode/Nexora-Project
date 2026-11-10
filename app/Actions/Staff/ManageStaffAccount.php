<?php

namespace App\Actions\Staff;

use App\Enums\AccountStatus;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Staff\StaffAssignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageStaffAccount
{
    public function __construct(private AuditWriter $audit, private StaffAssignments $assignments) {}

    /** M01/M10/M12; UI30–32/54; users.staff_id. Called inside the locked staff transaction. */
    public function handle(User $actor, Staff $staff, string $role, string $status): void
    {
        DB::transaction(function () use ($actor, $staff, $role, $status): void {
            DB::select("select pg_advisory_xact_lock(hashtext('dcms.staff.availability'))");
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            $this->update($actor, $staff, $role, $status);
        }, 3);
    }

    private function update(User $actor, Staff $staff, string $role, string $status): void
    {
        Gate::forUser($actor)->authorize('invite', $staff);
        Validator::make(['role' => $role, 'status' => $status], [
            'role' => ['required', Rule::in(config('dcms.staff_roles'))],
            'status' => ['required', Rule::in(['active', 'disabled', 'invited'])],
        ])->validate();
        $account = $staff->user()->lockForUpdate()->firstOrFail();
        $previous = ['roles' => $account->getRoleNames()->all(), 'permissions' => $account->getDirectPermissions()->pluck('name')->all(), 'status' => $account->status->value];
        $legacyRole = $previous['roles'] === ['Admin'] && $role === ($account->can('roles.manage') ? 'System Admin' : 'Administrative');
        $roleChanged = ! $legacyRole && $previous['roles'] !== [$role];
        $privileged = $account->can('roles.manage');
        if ($role === 'System Admin' || $privileged || $roleChanged) {
            Gate::forUser($actor)->authorize('roles.manage');
        }
        if ($actor->is($account) && ($roleChanged || $status !== 'active')) {
            throw ValidationException::withMessages(['account' => 'Ask another authorized administrator to change your access.']);
        }
        if ((($account->status === AccountStatus::Invited || $account->email_verified_at === null) && $status === 'active') || ($account->status !== AccountStatus::Invited && $account->email_verified_at !== null && $status === 'invited') || $account->status === AccountStatus::Locked) {
            throw ValidationException::withMessages(['account' => 'Complete password setup through the invitation, or use the approved recovery process.']);
        }
        if ($status === 'active' && ! $staff->is_active) {
            throw ValidationException::withMessages(['account' => 'Activate staff before enabling login.']);
        }
        if (($status === 'disabled' || $roleChanged) && ($this->assignments->outstanding($staff) ?? 0) > 0) {
            throw ValidationException::withMessages(['account' => 'Resolve outstanding allocations before changing account access.']);
        }
        if ($privileged && ($status !== 'active' || $roleChanged) && ! User::query()->whereKeyNot($account->id)->where('status', 'active')->permission('roles.manage')->permission('admin.access')->where(fn ($q) => $q->whereNull('staff_id')->orWhereHas('staff', fn ($q) => $q->where('is_active', true)))->exists()) {
            throw ValidationException::withMessages(['account' => 'The last active System Admin cannot lose access.']);
        }
        if ($role === 'Rider' && ! $staff->is_rider) {
            throw ValidationException::withMessages(['account' => 'Rider access requires Rider eligibility.']);
        }
        if ($roleChanged) {
            $account->syncRoles([$role]);
            $account->syncPermissions([]);
        }
        $account->status = AccountStatus::from($status);
        if ($roleChanged || $status === 'disabled') {
            DB::table('password_reset_tokens')->where('email', $account->email)->delete();
            $account->remember_token = null;
        }
        $account->save();
        $this->audit->record($actor, 'staff_account_updated', $staff->public_id, ['role' => $role, 'status' => $status], module: 'M10', entityType: 'staff', previous: $previous);
    }
}
