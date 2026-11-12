<?php

namespace App\Actions\Staff;

use App\Enums\AccountStatus;
use App\Models\Staff;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Services\AuditWriter;
use App\Services\Staff\AccountMailTransport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** M01/M10/M12 / UI31–32: Administrative staff invite Riders (riders.invite); System Admins invite office accounts (users.invite, + roles.manage for System Admin). */
class InviteStaffAccount
{
    public string $deliveryStatus = 'prepared';

    public function __construct(private AuditWriter $audit, private AccountMailTransport $transport) {}

    /** Delivery outcomes where no activation email reached the person, so the screen must warn rather than report success. */
    public static function needsAttention(?string $delivery): bool
    {
        return in_array($delivery, ['delivery_failed', 'unsafe_transport', 'local_capture', 'inactive_staff'], true);
    }

    /**
     * Roles this person may send login invitations for.
     *
     * @return list<string>
     */
    public static function invitableRoles(User $actor): array
    {
        return array_values(array_filter(config('dcms.staff_roles'), fn (string $role): bool => match ($role) {
            'Rider' => $actor->can('riders.invite'),
            'Administrative' => $actor->can('users.invite'),
            'System Admin' => $actor->can('users.invite') && $actor->can('roles.manage'),
            default => false,
        }));
    }

    /**
     * Roles this person may invite this staff member as: Riders get a Rider login, office staff an office login.
     *
     * @return list<string>
     */
    public static function invitableRolesFor(User $actor, Staff $staff): array
    {
        return array_values(array_intersect(self::invitableRoles($actor), $staff->is_rider ? ['Rider'] : ['Administrative', 'System Admin']));
    }

    public function handle(User $actor, Staff $target, string $role, string $revision): string
    {
        $delivery = 'prepared';
        try {
            DB::transaction(function () use ($actor, $target, $role, $revision, &$delivery): void {
                $staff = Staff::query()->lockForUpdate()->findOrFail($target->id);
                Gate::forUser($actor)->authorize('invite', $staff);
                if (! hash_equals($staff->revision(), $revision)) {
                    throw ValidationException::withMessages(['invitation' => 'This staff record changed. Reload before inviting.']);
                }
                if (! config('dcms.terms_version') || ! config('dcms.privacy_url')) {
                    throw ValidationException::withMessages(['invitation' => 'Configure the approved terms version and privacy URL before issuing invitations.']);
                }
                if (! $staff->email || ! filter_var($staff->email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['invitation' => 'A valid employment email is required.']);
                }
                Validator::make(['role' => $role], ['role' => ['required', Rule::in(config('dcms.staff_roles'))]])->validate();
                if (! in_array($role, self::invitableRoles($actor), true)) {
                    throw new AuthorizationException($role === 'Rider' ? 'Rider logins are sent by Administrative staff.' : 'Administrative and System Admin logins are sent by a System Admin.');
                }
                if ($role === 'Rider' && ! $staff->is_rider) {
                    throw ValidationException::withMessages(['invitation' => 'Rider access requires active Rider eligibility.']);
                }
                $account = $staff->user()->first();
                if ($account) {
                    DB::table('password_reset_tokens')->where('email', $account->email)->lockForUpdate()->first();
                    $account = User::query()->lockForUpdate()->findOrFail($account->id);
                    if ($account->status !== AccountStatus::Invited) {
                        throw ValidationException::withMessages(['invitation' => 'This account already exists. Use its approved account recovery or administration process.']);
                    }
                    $legacyRole = $account->getRoleNames()->all() === ['Admin'] && $role === ($account->can('roles.manage') ? 'System Admin' : 'Administrative');
                    if ((! $legacyRole && $account->getRoleNames()->sort()->values()->all() !== [$role]) || (! $legacyRole && $account->getDirectPermissions()->isNotEmpty())) {
                        throw ValidationException::withMessages(['invitation' => 'Reissuing cannot change permissions. Use authorized identity administration.']);
                    }
                    if (Password::getRepository()->recentlyCreatedToken($account)) {
                        throw ValidationException::withMessages(['invitation' => 'An invitation was issued recently. Wait before reissuing it.']);
                    }
                }
                $email = mb_strtolower(trim($staff->email));
                if (User::query()->whereRaw('lower(email) = ?', [$email])->when($account, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
                    throw ValidationException::withMessages(['invitation' => 'This email already belongs to another account. Existing identities are never merged.']);
                }
                if ($account && $account->email !== $email) {
                    DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                }
                $account ??= new User;
                $newAccount = ! $account->exists;
                $account->staff_id = $staff->id;
                $account->email = $email;
                $account->name = Str::limit($staff->full_name, 160, '');
                $account->status = AccountStatus::Invited;
                $account->password = Str::random(64);
                $account->save();
                if ($newAccount) {
                    $account->assignRole($role);
                }
                $token = Password::createToken($account);
                $this->audit->record($actor, 'account_invitation_prepared', $staff->public_id,
                    ['status' => 'invited', 'role' => $role], module: 'M10', entityType: 'staff');
                DB::afterCommit(function () use ($account, $token, $role, &$delivery): void {
                    if (! $account->staff()->where('is_active', true)->exists()) {
                        $delivery = $this->deliveryStatus = 'inactive_staff';

                        return;
                    }
                    if ($this->transport->usesLogTransport()) {
                        $delivery = $this->deliveryStatus = 'unsafe_transport';

                        return;
                    }
                    try {
                        $account->notify(new StaffInvitation($token, $role));
                        $delivery = $this->deliveryStatus = config('mail.default') === 'array' ? 'local_capture' : 'mail_accepted';
                    } catch (\Throwable $exception) {
                        $delivery = $this->deliveryStatus = 'delivery_failed';
                        Log::warning('Staff invitation mail transport failed.', ['user_id' => $account->public_id, 'exception_type' => $exception::class, 'reason' => Str::limit($exception->getMessage(), 300)]);
                    }
                });
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['invitation' => 'This staff member or email already has an account. Reload and review.']);
        }

        return $delivery;
    }
}
