<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * System Admin now controls accounts, permissions and system settings only, so its own interface hides assignments and tasks.
 * Administrative keeps daily operations, now including attendance and leave setup, and gains the reserved office approvals
 * (templates, letters, reopening, communication permissions).
 */
return new class extends Migration
{
    /** System Admin grants before this change: every office permission plus account administration. */
    private const PREVIOUS_SYSTEM_ADMIN_EXTRAS = ['roles.manage', 'users.invite', 'integrations.manage'];

    /** Administrative grants added by this change. */
    private const ADMINISTRATIVE_ADDITIONS = ['attendance.configure'];

    public function up(): void
    {
        $this->ensurePermissions([...config('dcms.system_admin_permissions'), ...config('dcms.office_permissions'), ...self::ADMINISTRATIVE_ADDITIONS]);
        $this->role('System Admin')?->syncPermissions(config('dcms.system_admin_permissions'));
        $this->role('Administrative')?->givePermissionTo([...config('dcms.office_permissions'), ...self::ADMINISTRATIVE_ADDITIONS]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $previous = array_values(array_unique([
            ...array_diff(config('dcms.permissions'), config('dcms.rider_only_permissions'), config('dcms.reserved_permissions')),
            ...self::PREVIOUS_SYSTEM_ADMIN_EXTRAS,
        ]));
        $this->ensurePermissions($previous);
        $this->role('System Admin')?->syncPermissions($previous);
        $administrative = $this->role('Administrative');
        foreach ([...config('dcms.office_permissions'), ...self::ADMINISTRATIVE_ADDITIONS] as $permission) {
            $administrative?->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function role(string $name): ?Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->first();
    }

    /** @param  list<string>  $names */
    private function ensurePermissions(array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
};
