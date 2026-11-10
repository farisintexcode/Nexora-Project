<?php

use App\Actions\Staff\ManageStaffAccount;
use App\Actions\Staff\SaveStaff;
use App\Enums\AccountStatus;
use App\Livewire\Admin\Staff\Editor;
use App\Livewire\Admin\Staff\Index;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    config(['dcms.terms_version' => 'test-v1', 'dcms.privacy_url' => 'https://example.test/privacy', 'mail.default' => 'array']);
});

test('staff registration provisions one invited linked account for each selectable role', function (string $role): void {
    Notification::fake();
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $this->travelTo(now()->startOfSecond());

    $staff = app(SaveStaff::class)->handle($actor, staffInput(['system_role' => $role, 'create_account' => true, 'created_at' => '2000-01-01']));

    expect($staff->user->staff_id)->toBe($staff->id)->and($staff->user->getRoleNames()->all())->toBe([$role])
        ->and($staff->user->status)->toBe(AccountStatus::Invited)->and($staff->is_rider)->toBe($role === 'Rider')
        ->and($staff->created_at->equalTo(now()))->toBeTrue();
    Notification::assertSentTo($staff->user, StaffInvitation::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'staff_created', 'entity_id' => $staff->public_id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'account_invitation_prepared', 'entity_id' => $staff->public_id]);
})->with(['System Admin', 'Administrative', 'Rider']);

test('account provisioning failure rolls back the staff and audit writes', function (): void {
    Notification::fake();
    $actor = staffManager();
    $auditCount = AuditLog::query()->count();

    expect(fn () => app(SaveStaff::class)->handle($actor, staffInput(['system_role' => 'Rider', 'create_account' => true])))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('staff', 0);
    $this->assertDatabaseCount('audit_logs', $auditCount);
    Notification::assertNothingSent();
});

test('Administrative cannot grant System Admin even with invitation authority', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('Administrative');
    $actor->givePermissionTo('users.invite');

    Livewire::actingAs($actor)->test(Editor::class)->set('data', staffInput(['system_role' => 'System Admin', 'create_account' => true]))->call('save')->assertForbidden();

    $this->assertDatabaseCount('staff', 0);
});

test('duplicate account email prevents staff persistence', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    User::factory()->create(['email' => 'nadia@example.test']);

    Livewire::actingAs($actor)->test(Editor::class)->set('data', staffInput(['system_role' => 'Rider', 'create_account' => true]))->call('save')->assertHasErrors('data.email');

    $this->assertDatabaseCount('staff', 0);
});

test('inactive staff accounts stay invited and cannot activate or receive an invitation', function (): void {
    Notification::fake();
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = app(SaveStaff::class)->handle($actor, staffInput(['system_role' => 'Rider', 'create_account' => true, 'is_active' => false]));
    $account = $staff->user;
    $token = Password::createToken($account);

    $this->post(route('activation.update'), ['email' => $account->email, 'token' => $token, 'name' => 'Test Rider', 'terms' => '1', 'password' => 'Strong-personal-123!', 'password_confirmation' => 'Strong-personal-123!'])->assertSessionHasErrors('email');

    expect($account->fresh()->status)->toBe(AccountStatus::Invited);
    Notification::assertNothingSent();
});

test('invited accounts cannot access direct dashboards or a previously opened Livewire component', function (): void {
    $staff = Staff::factory()->create();
    $account = User::factory()->for($staff)->create();
    $account->assignRole('System Admin');
    $component = Livewire::actingAs($account)->test(Editor::class);
    $account->status = AccountStatus::Invited;
    $account->save();

    $component->call('save')->assertForbidden();
    $this->actingAs($account)->get(route('admin.home'))->assertRedirect(route('login'));
    $this->actingAs($account)->get(route('rider.home'))->assertRedirect(route('login'));
    $this->assertDatabaseCount('staff', 1);
});

test('activation sends each role to its dashboard only after password confirmation', function (string $role, string $destination): void {
    Notification::fake();
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = app(SaveStaff::class)->handle($actor, staffInput(['system_role' => $role, 'create_account' => true]));
    $account = $staff->user;
    $token = basename(parse_url(Notification::sent($account, StaffInvitation::class)->last()->toMail($account)->actionUrl, PHP_URL_PATH));
    $payload = ['email' => $account->email, 'token' => $token, 'name' => 'Activated Staff', 'terms' => '1', 'password' => 'Strong-personal-123!', 'password_confirmation' => 'mismatch'];

    $this->post(route('activation.update'), $payload)->assertSessionHasErrors('password');
    expect($account->fresh()->isActive())->toBeFalse();
    $payload['password_confirmation'] = $payload['password'];
    $this->post(route('activation.update'), $payload)->assertRedirect(route($destination));
    $this->assertAuthenticatedAs($account);
    expect(Password::tokenExists($account, $token))->toBeFalse();
})->with([['System Admin', 'admin.home'], ['Administrative', 'admin.home'], ['Rider', 'rider.home']]);

test('the last active permission administrator cannot be disabled or demoted', function (): void {
    $staff = Staff::factory()->create(['is_rider' => false]);
    $last = User::factory()->for($staff)->create();
    $last->assignRole('System Admin');
    $actor = User::factory()->create(['status' => AccountStatus::Invited]);
    $actor->assignRole('System Admin');

    expect(fn () => app(ManageStaffAccount::class)->handle($last, $staff, 'Administrative', 'active'))->toThrow(ValidationException::class);
    expect($last->fresh()->can('roles.manage'))->toBeTrue();
});

test('concurrent stale administrator sessions cannot remove the final active administrator', function (): void {
    $staff = Staff::factory()->create(['is_rider' => false]);
    $last = User::factory()->for($staff)->create();
    $last->assignRole('System Admin');
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    DB::table('users')->where('id', $actor->id)->update(['status' => 'disabled']);

    try {
        app(ManageStaffAccount::class)->handle($actor, $staff, 'System Admin', 'disabled');
        $this->fail('The final administrator must remain active.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['account'])->toContain('The last active System Admin cannot lose access.');
    }

    expect($last->fresh()->status)->toBe(AccountStatus::Active);
});

test('an invited account cannot be manually enabled and role changes are audited', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['is_rider' => false]);
    $account = User::factory()->for($staff)->create(['status' => AccountStatus::Invited, 'email_verified_at' => null]);
    $account->assignRole('Administrative');

    expect(fn () => app(ManageStaffAccount::class)->handle($actor, $staff, 'Administrative', 'active'))->toThrow(ValidationException::class);
    $account->status = AccountStatus::Active;
    $account->email_verified_at = now();
    $account->save();
    app(ManageStaffAccount::class)->handle($actor, $staff, 'System Admin', 'active');
    expect($account->fresh()->can('roles.manage'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'staff_account_updated', 'entity_id' => $staff->public_id]);
    expect(AuditLog::query()->where('action', 'staff_account_updated')->first()->previous_values_redacted['roles'])->toBe(['Administrative']);
});

test('disabling a pending invitation does not allow password setup to be skipped later', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['is_rider' => false]);
    $account = User::factory()->for($staff)->create(['status' => AccountStatus::Invited, 'email_verified_at' => null]);
    $account->assignRole('Administrative');

    app(ManageStaffAccount::class)->handle($actor, $staff, 'Administrative', 'disabled');
    expect(fn () => app(ManageStaffAccount::class)->handle($actor, $staff, 'Administrative', 'active'))->toThrow(ValidationException::class);
    expect($account->fresh()->status)->toBe(AccountStatus::Disabled);
    app(ManageStaffAccount::class)->handle($actor, $staff, 'Administrative', 'invited');
    expect($account->fresh()->status)->toBe(AccountStatus::Invited);
});

test('equivalent legacy Admin edits retain custom grants and Rider eligibility', function (bool $privileged): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['is_rider' => true]);
    $account = User::factory()->for($staff)->create();
    $account->assignRole('Admin');
    $account->givePermissionTo('templates.approve');
    if ($privileged) {
        $account->givePermissionTo('roles.manage');
    }

    Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff])->assertSet('data.system_role', $privileged ? 'System Admin' : 'Administrative')->set('data.last_name', 'Changed')->call('save')->assertHasNoErrors();

    expect($account->fresh()->getRoleNames()->all())->toBe(['Admin'])->and($account->fresh()->hasDirectPermission('templates.approve'))->toBeTrue()
        ->and($staff->fresh()->is_rider)->toBeTrue();
})->with([false, true]);

test('removing required system account fields from Livewire payload cannot bypass validation', function (): void {
    $actor = staffManager();

    Livewire::actingAs($actor)->test(Editor::class)->set('data', staffInput())->call('save')->assertHasErrors('data.system_role');

    $this->assertDatabaseCount('staff', 0);
});

test('directory availability actions retain employment history and separate account status', function (): void {
    $actor = staffManager();
    $staff = Staff::factory()->create(['appointment_date' => '2020-01-01']);
    $account = User::factory()->for($staff)->create();
    $account->assignRole('Rider');

    Livewire::actingAs($actor)->test(Index::class)->set('reason', 'Employment paused')
        ->call('changeAvailability', $staff->public_id, false)->assertHasNoErrors();

    expect($staff->fresh()->is_active)->toBeFalse()->and($account->fresh()->status)->toBe(AccountStatus::Active)
        ->and($staff->fresh()->appointment_date->format('Y-m-d'))->toBe('2020-01-01');
});

test('directory deactivation asks for the reason in a dialog for the selected staff', function (): void {
    $actor = staffManager();
    $staff = Staff::factory()->create();

    $page = Livewire::actingAs($actor)->test(Index::class)
        ->call('confirmDeactivation', $staff->public_id)
        ->assertSet('deactivating', $staff->public_id)
        ->assertSee('Deactivate '.$staff->full_name.'?');

    $page->call('changeAvailability', $staff->public_id, false)->assertHasErrors('data.reason')->assertSet('deactivating', $staff->public_id);
    expect($staff->fresh()->is_active)->toBeTrue();

    $page->set('reason', 'Employment paused')->call('changeAvailability', $staff->public_id, false)
        ->assertHasNoErrors()->assertSet('deactivating', null);
    expect($staff->fresh()->is_active)->toBeFalse();
});

test('directory hides inactive staff by default but keeps them reachable through the status filter', function (): void {
    $actor = staffManager();
    Staff::factory()->create(['first_name' => 'Current', 'last_name' => 'Worker', 'is_rider' => true]);
    Staff::factory()->create(['first_name' => 'Resigned', 'last_name' => 'Worker', 'is_rider' => true, 'is_active' => false]);

    Livewire::actingAs($actor)->test(Index::class)
        ->assertSet('status', 'active')->assertSee('Current Worker')->assertDontSee('Resigned Worker')
        ->set('status', 'inactive')->assertSee('Resigned Worker')->assertDontSee('Current Worker')
        ->set('status', '')->assertSee('Resigned Worker')->assertSee('Current Worker')
        ->call('resetFilters')->assertSet('status', 'active')->assertDontSee('Resigned Worker');
});

test('HR forms hide appointment date and preserve its historical value on editing', function (): void {
    $actor = staffManager();
    $staff = Staff::factory()->create(['appointment_date' => '2020-01-01']);

    Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff])->assertSee('Created At')->assertDontSee('Appointment')
        ->set('data.create_account', false)->set('data.last_name', 'Updated')->call('save')->assertHasNoErrors();

    expect($staff->fresh()->appointment_date->format('Y-m-d'))->toBe('2020-01-01');
});

test('directory filters linked roles account states and Kuala Lumpur creation dates', function (): void {
    $actor = staffManager();
    $staff = Staff::factory()->create(['first_name' => 'Boundary', 'last_name' => 'Person', 'created_at' => '2026-11-06 16:00:00']);
    $account = User::factory()->for($staff)->create(['status' => AccountStatus::Invited]);
    $account->assignRole('Rider');

    Livewire::actingAs($actor)->test(Index::class)->set('kind', 'all')->set('role', 'Rider')->set('accountStatus', 'invited')
        ->set('from', '2026-11-07')->set('until', '2026-11-07')->assertSee('Boundary Person')->assertDontSee('Appointment')
        ->set('accountStatus', 'active')->assertDontSee('Boundary Person')->set('accountStatus', 'invited')
        ->set('from', '2026-11-08')->assertDontSee('Boundary Person');
});

test('staff form provisions each system role without an employment position', function (string $role): void {
    Notification::fake();
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $input = staffInput(['system_role' => $role, 'create_account' => true]);
    unset($input['position_code']);

    Livewire::actingAs($actor)->test(Editor::class)->assertDontSee('Employment Position')->set('data', $input)->call('save')->assertHasNoErrors();

    $staff = Staff::query()->sole();
    expect($staff->position_code)->toBeNull()->and($staff->user->getRoleNames()->all())->toBe([$role])->and($staff->user->status)->toBe(AccountStatus::Invited);
    Notification::assertSentTo($staff->user, StaffInvitation::class);
})->with(['System Admin', 'Administrative', 'Rider']);

test('authorized role changes preserve historical position values without exposing a position editor', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['position_code' => '99']);
    $account = User::factory()->for($staff)->create();
    $account->assignRole('Rider');

    Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff])->assertDontSee('Employment Position')
        ->set('data.system_role', 'Administrative')->set('data.reason', 'Authorized office access transition')->call('save')->assertHasNoErrors();

    expect($staff->fresh()->position_code)->toBe('99')->and($account->fresh()->getRoleNames()->all())->toBe(['Administrative']);
    $this->actingAs($actor)->get(route('admin.staff.show', $staff))->assertOk()->assertSee('Administrative')->assertDontSee('Employment Position');
});

test('explicit legacy Admin eligibility removal requires a reason and preserves grants', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['is_rider' => true]);
    $account = User::factory()->for($staff)->create();
    $account->assignRole('Admin');
    $account->givePermissionTo('templates.approve');

    $editor = Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff])
        ->set('data.is_rider', false)->call('save')->assertHasErrors('data.reason');
    expect($staff->fresh()->is_rider)->toBeTrue();
    $editor->set('data.reason', 'Approved removal of field eligibility')->call('save')->assertHasNoErrors();
    expect($staff->fresh()->is_rider)->toBeFalse();
    expect($account->fresh()->getRoleNames()->all())->toBe(['Admin']);
    expect($account->fresh()->hasDirectPermission('templates.approve'))->toBeTrue();
});

test('explicit legacy Admin role conversion still synchronizes eligibility and requires a reason', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole('System Admin');
    $staff = Staff::factory()->create(['is_rider' => true]);
    $account = User::factory()->for($staff)->create();
    $account->assignRole('Admin');

    $editor = Livewire::actingAs($actor)->test(Editor::class, ['staff' => $staff])
        ->set('data.system_role', 'System Admin')->call('save')->assertHasErrors('data.reason');
    expect($staff->fresh()->is_rider)->toBeTrue();
    $editor->set('data.reason', 'Converted to system administration')->call('save')->assertHasNoErrors();
    expect($staff->fresh()->is_rider)->toBeFalse();
    expect($account->fresh()->getRoleNames()->all())->toBe(['System Admin']);
});
