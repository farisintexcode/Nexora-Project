<?php

use App\Livewire\Admin\Home;
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

test('guests are directed to the corresponding entry point', function (): void {
    $this->get(route('admin.home'))->assertRedirect(route('login'));
    $this->get(route('rider.home'))->assertRedirect(route('rider.login'));
});

test('rider cannot enter admin or gain admin through a login payload', function (): void {
    $user = User::factory()->for(Staff::factory()->create())->create();
    $user->assignRole('Rider');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'role' => 'Admin'])->assertSessionHasErrors('email');
    $this->actingAs($user)->get(route('admin.home'))->assertForbidden();
    $this->get(route('rider.home'))->assertOk()->assertSee('Rider workspace');

    expect($user->fresh()->hasRole('Admin'))->toBeFalse();
});

test('admin bundle approves leave and attendance corrections but does not imply rider eligibility or reserved authority', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)->get(route('admin.home'))->assertOk();
    $this->get(route('rider.home'))->assertForbidden();

    expect($user->can('leave.approve'))->toBeTrue();
    expect($user->can('attendance.approve_correction'))->toBeTrue();
    expect($user->can('templates.approve'))->toBeFalse();
    expect($user->can('roles.manage'))->toBeFalse();
});

test('revoked action grant is rechecked by livewire', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('admin.access');
    $component = Livewire::actingAs($user)->test(Home::class)->assertOk();
    $user->revokePermissionTo('admin.access');

    $component->call('$refresh')->assertForbidden();
});

test('untrusted display names are escaped in the shell', function (): void {
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $user->assignRole('Admin');

    $this->actingAs($user)->get(route('admin.home'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
});

test('monitoring has no local role bypass', function (): void {
    $user = User::factory()->for(Staff::factory()->create())->create();
    $user->assignRole('Rider');

    $this->actingAs($user)->get('/pulse')->assertForbidden();
});
