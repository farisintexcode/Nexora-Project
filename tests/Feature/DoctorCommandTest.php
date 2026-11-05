<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    config([
        'imports.disk' => 'local', 'imports.scan_required' => false,
        'task_execution.disk' => 'local', 'task_execution.scan_required' => false,
        'dcms.search_key' => base64_encode(str_repeat('t', 32)),
    ]);
});

test('doctor explains a scanner that uploads need but cannot reach', function (): void {
    config(['imports.scan_required' => true, 'imports.scanner_host' => '127.0.0.1', 'imports.scanner_port' => 1]);

    $this->artisan('dcms:doctor')->expectsOutputToContain('ClamAV is not reachable')->assertFailed();
});

test('doctor explains a missing search key without printing secret values', function (): void {
    config(['dcms.search_key' => null]);

    $this->artisan('dcms:doctor')->expectsOutputToContain('DCMS_SEARCH_KEY')->assertFailed();
});

test('doctor explains proof storage that points at unconfigured s3', function (): void {
    config(['task_execution.disk' => 's3', 'filesystems.disks.s3.bucket' => null]);

    $this->artisan('dcms:doctor')->expectsOutputToContain('PROOF_DISK=local')->assertFailed();
});

test('doctor reports missing role permissions and only fixes them when asked', function (): void {
    $admin = Role::findByName('Admin', 'web');
    $admin->revokePermissionTo('imports.map');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->artisan('dcms:doctor')->expectsOutputToContain('imports.map');
    expect($admin->refresh()->hasPermissionTo('imports.map'))->toBeFalse();

    $this->artisan('dcms:doctor --fix')->expectsOutputToContain('Granted Admin: imports.map');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Role::findByName('Admin', 'web')->hasPermissionTo('imports.map'))->toBeTrue()
        ->and(Role::findByName('Admin', 'web')->hasPermissionTo('communications.generate_letter'))->toBeFalse();
});
