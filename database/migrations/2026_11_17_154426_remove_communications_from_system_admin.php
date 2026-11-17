<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * System Admin only manages staff accounts and troubleshoots the system, so it no longer sees Communications
 * or approves email templates. Administrative keeps template approval through its office permissions.
 */
return new class extends Migration
{
    /** Communication grants System Admin held before this change. */
    private const REMOVED_PERMISSIONS = ['communications.view', 'templates.approve'];

    public function up(): void
    {
        $systemAdmin = $this->systemAdmin();
        foreach (self::REMOVED_PERMISSIONS as $permission) {
            if ($systemAdmin?->hasPermissionTo($permission)) {
                $systemAdmin->revokePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->systemAdmin()?->givePermissionTo(self::REMOVED_PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function systemAdmin(): ?Role
    {
        return Role::query()->where('name', 'System Admin')->where('guard_name', 'web')->first();
    }
};
