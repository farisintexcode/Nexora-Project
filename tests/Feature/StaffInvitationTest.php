<?php

use App\Actions\Staff\InviteStaffAccount;
use App\Actions\Staff\SaveStaff;
use App\Enums\AccountStatus;
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

uses(LazilyRefreshDatabase::class);
beforeEach(function (): void {
    $this->withoutVite();
    $this->seed(DatabaseSeeder::class);
    config(['dcms.terms_version' => 'synthetic-terms-v1', 'dcms.privacy_url' => 'https://example.test/privacy', 'mail.default' => 'array']);
    Notification::fake();
});

function invitationToken(User $account): string
{
    $notification = Notification::sent($account, StaffInvitation::class)->last();

    return basename(parse_url($notification->toMail($account)->actionUrl, PHP_URL_PATH));
}

test('authorized Rider invitation creates only the selected role and a single-use activation', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());

    $status = app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());

    $account = $staff->user()->firstOrFail();
    expect($status)->toBe('local_capture')->and($account->status)->toBe(AccountStatus::Invited)
        ->and($account->hasRole('Rider'))->toBeTrue()->and($account->can('admin.access'))->toBeFalse()
        ->and($account->isActive())->toBeFalse();
    Notification::assertSentTo($account, StaffInvitation::class);
    $invitationMail = Notification::sent($account, StaffInvitation::class)->last()->toMail($account);
    expect($invitationMail->subject)->toBe('Activate your Nexora staff account');
    expect((string) $invitationMail->render())->toContain('Welcome to Nexora', 'Debt Collection Management System');
    $token = invitationToken($account);
    expect(Password::tokenExists($account, $token))->toBeTrue();
    expect(DB::table('password_reset_tokens')->where('email', $account->email)->value('token') === $token)->toBeFalse();
    expect(AuditLog::query()->where('action', 'account_invitation_prepared')->first()->toJson())->not->toContain($token, $account->email);
    $payload = ['email' => $account->email, 'token' => $token, 'password' => 'A-new-secure-123!', 'password_confirmation' => 'A-new-secure-123!', 'name' => 'Invited Rider', 'terms' => '1'];
    $this->post(route('activation.update'), $payload)->assertRedirect(route('rider.home'));
    $this->post(route('logout'));
    expect($account->fresh()->status)->toBe(AccountStatus::Active);
    $this->post(route('activation.update'), $payload)->assertSessionHasErrors('email');
});

test('staff management alone cannot issue invitations or escalate the requested role', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(['admin.access', 'riders.manage']);
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toThrow(AuthorizationException::class);
    $actor->givePermissionTo('users.invite');
    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'System Admin', $staff->revision()))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('users', 1);
    Notification::assertNothingSent();
});

test('explicit Admin invitation requires role authority and never infers access from position', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo(['users.invite', 'roles.manage']);
    $staff = app(SaveStaff::class)->handle($actor, staffInput(['is_rider' => false, 'position_code' => '01']));

    app(InviteStaffAccount::class)->handle($actor, $staff, 'System Admin', $staff->revision());

    $account = $staff->user()->firstOrFail();
    expect($account->hasRole('System Admin'))->toBeTrue()->and($account->can('roles.manage'))->toBeTrue();
});

test('inactive staff missing email and ineligible Rider cannot be invited', function (array $changes): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput($changes));

    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toThrow(ValidationException::class);
    expect($staff->user()->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with([[['email' => '']], [['is_rider' => false]]]);

test('invitation requires approved terms configuration before any account is written', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    config(['dcms.terms_version' => null]);

    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toThrow(ValidationException::class);
    expect($staff->user()->exists())->toBeFalse();
});

test('duplicate account email is never linked or merged into a different staff identity', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    User::factory()->create(['email' => $staff->email]);

    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toThrow(ValidationException::class);
    expect($staff->user()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('staff email change invalidates pending token and reissue supersedes the old invitation', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());
    $account = $staff->user()->firstOrFail();
    $old = invitationToken($account);
    $updated = app(SaveStaff::class)->handle($actor, staffInput(['email' => 'new-address@example.test']), $staff, $staff->revision());

    expect(Password::tokenExists($account, $old))->toBeFalse();
    app(InviteStaffAccount::class)->handle($actor, $updated, 'Rider', $updated->revision());
    expect($account->fresh()->email)->toBe('new-address@example.test');
    expect(Password::tokenExists($account->fresh(), $old))->toBeFalse();
});

test('activation rejects an old staff email even if the broker token still exists', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());
    $account = $staff->user()->firstOrFail();
    $token = invitationToken($account);
    DB::table('staff')->where('id', $staff->id)->update(['email' => 'changed@example.test']);

    $this->post(route('activation.update'), ['email' => $account->email, 'token' => $token, 'password' => 'A-new-secure-123!', 'password_confirmation' => 'A-new-secure-123!', 'name' => 'Invited Rider', 'terms' => '1'])->assertSessionHasErrors('email');
    expect($account->fresh()->status)->toBe(AccountStatus::Invited);
});

test('reissue is throttled and cannot reset an active account', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());
    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toThrow(ValidationException::class);
    $account = $staff->user()->firstOrFail();
    $account->status = AccountStatus::Active;
    $account->save();
    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toThrow(ValidationException::class);
});

test('mail transport failure leaves a recoverable invitation without leaking the exception into audit', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    config(['mail.default' => 'smtp']);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic transport failure'));

    $status = app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());

    expect($status)->toBe('delivery_failed')->and($staff->user()->first()->status)->toBe(AccountStatus::Invited);
    expect(AuditLog::query()->where('action', 'account_invitation_prepared')->first()->toJson())->not->toContain('Synthetic transport failure');
});

test('reissuing an expired or older invitation consumes the prior token without creating another identity', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());
    $account = $staff->user()->firstOrFail();
    $old = invitationToken($account);
    $this->travel(61)->seconds();

    app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision());

    $this->assertDatabaseCount('users', 2);
    expect(Password::tokenExists($account, $old))->toBeFalse()->and(Password::tokenExists($account, invitationToken($account)))->toBeTrue();
});

test('log and failover-to-log transports never receive an activation token', function (string $mailer): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    config(['mail.default' => $mailer]);

    expect(app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $staff->revision()))->toBe('unsafe_transport');
    Notification::assertNothingSent();
})->with(['log', 'failover']);

test('stale invitation and staff deactivation cannot activate an old pending link', function (): void {
    $actor = staffManager();
    $actor->givePermissionTo('users.invite');
    $staff = app(SaveStaff::class)->handle($actor, staffInput());
    $oldRevision = $staff->revision();
    app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $oldRevision);
    expect(fn () => app(InviteStaffAccount::class)->handle($actor, $staff, 'Rider', $oldRevision))->toThrow(ValidationException::class);
    $account = $staff->user()->firstOrFail();
    $token = invitationToken($account);

    app(SaveStaff::class)->handle($actor, staffInput(['is_active' => false, 'reason' => 'Invitation withdrawn with employment status']), $staff, $staff->revision());

    expect(Password::tokenExists($account, $token))->toBeFalse()->and($account->fresh()->status)->toBe(AccountStatus::Invited);
});

test('invitation email is Nexora branded, names the role and carries the logo inline', function (): void {
    $staff = Staff::factory()->create(['first_name' => 'Aina', 'is_rider' => false]);
    $account = User::factory()->for($staff)->create(['status' => AccountStatus::Invited, 'email_verified_at' => null]);

    $account->notify(new StaffInvitation(Password::createToken($account), 'Administrative'));

    $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    $html = $sent->getHtmlBody();
    expect($sent->getSubject())->toBe('Activate your Nexora account')
        ->and($html)->toContain('Hi Aina,')->toContain('<strong>Administrative</strong>')->toContain('Activate my account')
        ->toContain('Regards,<br>Nexora')->not->toContain('DebtOps')->toContain('#fb8500')
        ->and(collect($sent->getAttachments())->map->getName()->all())->toContain('nexora-logo');
});
