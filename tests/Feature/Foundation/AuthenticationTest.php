<?php

use App\Enums\AccountStatus;
use App\Livewire\Account\Settings;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
});

test('admin login rotates session and records a redacted audit', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $this->withSession(['marker' => 'retained']);
    $oldSession = session()->getId();

    $this->post(route('login.store'), ['email' => mb_strtoupper($user->email), 'password' => 'password'])->assertRedirect(route('admin.home'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($oldSession);
    expect($user->fresh()->last_login_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'login']);
    expect(AuditLog::query()->where('action', 'login')->first()->new_values_redacted)->toBe(['portal' => 'admin']);
});

test('rider entry requires active eligible staff and redirects to own shell', function (): void {
    $staff = Staff::factory()->create();
    $user = User::factory()->for($staff)->create();
    $user->assignRole('Rider');

    $this->post(route('rider.login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('rider.home'));

    $this->assertAuthenticatedAs($user);
});

test('inactive account or staff cannot sign in', function (string $state): void {
    $staff = Staff::factory()->create(['is_active' => $state !== 'inactive_staff', 'is_rider' => $state !== 'ineligible_rider']);
    $user = User::factory()->for($staff)->create(['status' => in_array($state, ['invited', 'disabled', 'locked']) ? $state : 'active']);
    $user->assignRole('Rider');

    $this->post(route('rider.login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'login']);
})->with(['invited', 'disabled', 'locked', 'inactive_staff', 'ineligible_rider']);

test('login errors are neutral and do not log attempted email', function (): void {
    $this->post(route('login.store'), ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
    expect(AuditLog::query()->where('action', 'login_failed')->firstOrFail()->toJson())->not->toContain('unknown@example.test', 'wrong');
});

test('login attempts are throttled', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('login.store'), ['email' => 'throttle@example.test', 'password' => 'wrong']);
    }

    $this->post(route('login.store'), ['email' => 'throttle@example.test', 'password' => 'wrong'])->assertTooManyRequests();
});

test('disabled existing session is invalidated', function (): void {
    $user = User::factory()->create(['status' => 'disabled']);

    $this->actingAs($user)->get(route('account.settings'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('logout invalidates authenticated access', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'logout']);
    $this->get(route('admin.home'))->assertRedirect(route('login'));
});

test('recovery response is neutral for eligible and missing emails', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post(route('password.email'), ['email' => $user->email]);
    $missing = $this->post(route('password.email'), ['email' => 'missing@example.test']);

    $known->assertSessionHas('status', 'If this account is eligible, a password recovery link will be sent.');
    $missing->assertSessionHas('status', 'If this account is eligible, a password recovery link will be sent.');
    Notification::assertSentTo($user, ResetPassword::class);
});

test('valid reset changes password and consumes the token', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $data = ['email' => $user->email, 'token' => $token, 'password' => 'A-new-secure-123!', 'password_confirmation' => 'A-new-secure-123!'];

    $this->post(route('password.update'), $data)->assertRedirect(route('login'));

    expect(Hash::check('A-new-secure-123!', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset', 'actor_id' => $user->id]);
    $this->post(route('password.update'), $data)->assertSessionHasErrors('email');
});

test('activation requires approved terms and records their version', function (): void {
    config(['dcms.terms_version' => 'test-v1', 'dcms.privacy_url' => 'https://example.test/privacy']);
    $user = User::factory()->create(['status' => 'invited', 'email_verified_at' => null]);
    $token = Password::createToken($user);

    $this->post(route('activation.update'), ['email' => $user->email, 'token' => $token, 'name' => 'Invited staff', 'terms' => '1', 'password' => 'A-new-secure-123!', 'password_confirmation' => 'A-new-secure-123!'])->assertRedirect(route('rider.home'));

    expect($user->fresh()->status)->toBe(AccountStatus::Active);
    expect(AuditLog::query()->where('action', 'activated')->first()->new_values_redacted['terms_version'])->toBe('test-v1');
});

test('invited user cannot bypass activation terms through password reset', function (): void {
    $user = User::factory()->create(['status' => 'invited']);
    $token = Password::createToken($user);

    $this->post(route('password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'A-new-secure-123!', 'password_confirmation' => 'A-new-secure-123!'])->assertSessionHasErrors('email');

    expect($user->fresh()->status)->toBe(AccountStatus::Invited);
});

test('password change validates the current secret and audits the change', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    Livewire::actingAs($user)->test(Settings::class)
        ->set('current_password', 'wrong')->set('password', 'A-new-secure-123!')->set('password_confirmation', 'A-new-secure-123!')
        ->call('save')->assertHasErrors('current_password');
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

    Livewire::actingAs($user)->test(Settings::class)
        ->set('current_password', 'password')->set('password', 'A-new-secure-123!')->set('password_confirmation', 'A-new-secure-123!')
        ->call('save')->assertHasNoErrors()->assertSet('password', '');

    expect(Hash::check('A-new-secure-123!', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed', 'actor_id' => $user->id]);
});
