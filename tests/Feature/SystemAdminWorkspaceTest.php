<?php

use App\Enums\AccountStatus;
use App\Livewire\Admin\Home;
use App\Livewire\Admin\Staff\Index as StaffIndex;
use App\Livewire\Admin\SystemOverview;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

function workspaceUser(string $role, ?Staff $staff = null): User
{
    $user = User::factory()->when($staff, fn ($factory) => $factory->for($staff))->create();
    $user->assignRole($role);

    return $user;
}

test('System Admin lands on the account and settings overview instead of the operations dashboard', function (): void {
    $admin = workspaceUser('System Admin');

    Livewire::actingAs($admin)->test(Home::class)->assertRedirect(route('admin.system'));
    $this->actingAs($admin)->get(route('admin.system'))->assertOk()
        ->assertSee('Account status')->assertSee('Accounts by role')->assertSee('Staff Management')->assertSee('System Health')
        ->assertDontSee('Manage Assignment')->assertDontSee('Task Management')->assertDontSee('Leave Management')->assertDontSee('Assignment progress');
});

test('System Admin reaches accounts but not assignment, task, attendance or leave work', function (): void {
    $admin = workspaceUser('System Admin');

    foreach (['admin.staff.index', 'admin.staff.create', 'admin.system'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk();
    }
    foreach (['admin.assignments.index', 'admin.tasks.index', 'admin.imports.index', 'admin.verification.index', 'admin.reports.index', 'admin.attendance.index', 'admin.attendance.configuration', 'admin.leave.index', 'admin.leave.types', 'admin.calls.index'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertForbidden();
    }
});

test('Administrative keeps the operations dashboard and owns attendance and leave setup', function (): void {
    $administrative = workspaceUser('Administrative', Staff::factory()->create(['is_rider' => false]));

    $this->actingAs($administrative)->get(route('admin.home'))->assertOk()->assertSee('Manage Assignment')->assertSee('Leave Management')->assertDontSee('System Health');
    foreach (['admin.attendance.index', 'admin.attendance.configuration', 'admin.leave.index', 'admin.leave.types'] as $route) {
        $this->actingAs($administrative)->get(route($route))->assertOk();
    }
    $this->actingAs($administrative)->get(route('admin.system'))->assertForbidden();
});

test('overview counts accounts by status and role, treating legacy Admin as the role its permissions represent', function (): void {
    $admin = workspaceUser('System Admin');
    workspaceUser('Administrative', Staff::factory()->create(['is_rider' => false]));
    $legacy = workspaceUser('Admin');
    $legacy->givePermissionTo('roles.manage');
    $locked = workspaceUser('Rider', Staff::factory()->create(['is_rider' => true]));
    $locked->forceFill(['status' => AccountStatus::Locked])->save();

    Livewire::actingAs($admin)->test(SystemOverview::class)
        ->assertViewHas('roleCounts', fn ($counts): bool => $counts->all() === ['System Admin' => 2, 'Administrative' => 1, 'Rider' => 1])
        ->assertViewHas('statusCounts', fn ($counts): bool => $counts['locked'] === 1 && $counts['active'] === 3)
        ->assertSee($locked->name);
});

test('staff management lists login accounts without a staff record under System accounts', function (): void {
    $admin = workspaceUser('System Admin');
    $admin->forceFill(['name' => 'Root Operator'])->save();
    Staff::factory()->create(['first_name' => 'Field', 'last_name' => 'Person', 'is_rider' => true]);

    Livewire::actingAs($admin)->test(StaffIndex::class)->assertSet('kind', 'all')
        ->assertSee('System accounts')->assertSee('Root Operator')->assertSee('Field Person')
        ->set('role', 'Rider')->assertDontSee('Root Operator')
        ->set('role', 'System Admin')->assertSee('Root Operator')
        ->set('role', '')->set('accountStatus', 'locked')->assertDontSee('Root Operator');
});
