<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

/** M10/UI30–32: organization-level personnel access. */
class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('riders.manage');
    }

    /** System Admin accounts are visible only to people who can manage roles themselves. */
    public function view(User $user, Staff $staff): bool
    {
        return $this->viewAny($user) && ($user->can('roles.manage') || ! User::query()->where('staff_id', $staff->id)->permission('roles.manage')->exists());
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Staff $staff): bool
    {
        return $this->view($user, $staff);
    }

    /** Sending a login invitation: Administrative staff for Riders, System Admins for office accounts (the role is checked when inviting). */
    public function invite(User $user, Staff $staff): bool
    {
        return $this->view($user, $staff) && ($user->can('users.invite') || $user->can('riders.invite'));
    }

    /** Changing an existing login's role or status is account control, kept with System Admins. */
    public function manageAccount(User $user, Staff $staff): bool
    {
        return $this->view($user, $staff) && $user->can('users.invite');
    }

    /** Account recovery (reset links, unlocking) is reserved for System Admins. */
    public function recoverAccount(User $user, Staff $staff): bool
    {
        return $this->manageAccount($user, $staff) && $user->can('roles.manage');
    }
}
