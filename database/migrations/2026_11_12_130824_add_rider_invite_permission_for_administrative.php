<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Administrative staff send Rider login invitations; System Admins keep office invitations (users.invite). */
return new class extends Migration
{
    private const ROLES = ['Administrative', 'Admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('riders.invite', 'web');
        Role::query()->whereIn('name', self::ROLES)->where('guard_name', 'web')->with('permissions')->get()->each->givePermissionTo('riders.invite');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'riders.invite')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
