<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * System Admin invites every role, Riders included, but the earlier 2026_11_13 migration meant to grant
 * riders.invite was left empty, so existing databases never received it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('riders.invite', 'web');
        $systemAdmin = $this->systemAdmin();
        if ($systemAdmin && ! $systemAdmin->hasPermissionTo('riders.invite')) {
            $systemAdmin->givePermissionTo('riders.invite');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $systemAdmin = $this->systemAdmin();
        if ($systemAdmin?->hasPermissionTo('riders.invite')) {
            $systemAdmin->revokePermissionTo('riders.invite');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function systemAdmin(): ?Role
    {
        return Role::query()->where('name', 'System Admin')->where('guard_name', 'web')->first();
    }
};
