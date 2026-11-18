<?php

namespace App\Policies;

use App\Models\AssignmentBatch;
use App\Models\User;

/** M03 / UI09–12: permission-based access. */
class AssignmentBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->can('admin.access') && $user->can('assignments.view');
    }

    public function view(User $user, AssignmentBatch $batch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && $user->can('assignments.manage');
    }

    public function update(User $user, AssignmentBatch $batch): bool
    {
        return $this->view($user, $batch) && $user->can('assignments.manage');
    }

    public function delete(User $user, AssignmentBatch $batch): bool
    {
        return $this->update($user, $batch);
    }

    public function reopen(User $user, AssignmentBatch $batch): bool
    {
        return $this->update($user, $batch) && $user->can('assignments.reopen');
    }
}
