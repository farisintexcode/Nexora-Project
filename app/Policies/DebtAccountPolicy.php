<?php

namespace App\Policies;

use App\Models\DebtAccount;
use App\Models\User;

class DebtAccountPolicy
{
    public function view(User $user, DebtAccount $account): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('customers.view');
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('tasks.create');
    }

    public function update(User $user, DebtAccount $account): bool
    {
        return $this->view($user, $account) && $this->create($user);
    }
}
