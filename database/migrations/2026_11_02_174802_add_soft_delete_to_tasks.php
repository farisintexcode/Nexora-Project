<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lets admins delete tasks that have no field work. Task history is immutable, so a deleted task is hidden and kept.
 * Its task ID becomes available again within the assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks ADD COLUMN deleted_at timestamp(6) with time zone');
        DB::statement('DROP INDEX task_source_unique');
        DB::statement('DROP INDEX task_number_unique');
        DB::statement('CREATE UNIQUE INDEX task_source_unique ON tasks (assignment_batch_id, source_task_id) WHERE source_task_id IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX task_number_unique ON tasks (assignment_batch_id, source_task_number) WHERE source_task_number IS NOT NULL AND deleted_at IS NULL');

        $permission = Permission::findOrCreate('tasks.delete', 'web');
        Role::query()->where('name', 'Admin')->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('tasks')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Deleted tasks exist. Their task IDs may have been reused, so this migration cannot be rolled back.');
        }
        DB::statement('DROP INDEX task_source_unique');
        DB::statement('DROP INDEX task_number_unique');
        DB::statement('CREATE UNIQUE INDEX task_source_unique ON tasks (assignment_batch_id, source_task_id) WHERE source_task_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX task_number_unique ON tasks (assignment_batch_id, source_task_number) WHERE source_task_number IS NOT NULL');
        DB::statement('ALTER TABLE tasks DROP COLUMN deleted_at');
        Permission::query()->where('name', 'tasks.delete')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
