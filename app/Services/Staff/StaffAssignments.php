<?php

namespace App\Services\Staff;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Database.md task_allocations.collector_id; M05 must lock staff before new allocations. */
class StaffAssignments
{
    public function outstanding(Staff $staff): ?int
    {
        $allocations = Schema::hasTable('task_allocations');
        $tasks = Schema::hasTable('tasks');
        if (! $allocations && ! $tasks) {
            return null;
        }
        if (! $allocations || ! $tasks) {
            throw ValidationException::withMessages(['data.is_active' => 'Task integrity cannot be checked. Complete the task migrations before changing staff availability.']);
        }

        return DB::table('task_allocations as allocation')->join('tasks', 'tasks.id', '=', 'allocation.task_id')
            ->where('allocation.collector_id', $staff->id)->whereNull('allocation.released_at')->whereNull('tasks.deleted_at')
            ->whereNotIn('tasks.work_status', ['completed', 'cancelled'])->count();
    }
}
