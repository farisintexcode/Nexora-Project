<?php

namespace App\Policies;

use App\Models\Debtor;
use App\Models\User;

class DebtorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('customers.view');
    }

    public function view(User $user, Debtor $customer): bool
    {
        return $this->viewAny($user);
    }

    public function updateContacts(User $user, Debtor $customer): bool
    {
        return $this->view($user, $customer) && $user->can('customers.update_contacts');
    }
}
