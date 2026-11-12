<?php

namespace App\Actions\Staff;

use App\Enums\AccountStatus;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\Staff\AccountMailTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** M01/M10: System Admin account recovery — reset links and unlocking, always audited, never exposing secrets. */
class RecoverStaffAccount
{
    public function __construct(private AuditWriter $audit, private AccountMailTransport $transport) {}

    /**
     * Email a single-use password reset link to the staff member's active account.
     *
     * @return 'mail_accepted'|'local_capture'|'delivery_failed'
     */
    public function sendPasswordReset(User $actor, Staff $target): string
    {
        Gate::forUser($actor)->authorize('recoverAccount', $target);
        $account = $target->user()->first();
        if (! $account || ! $account->isActive() || $account->email_verified_at === null) {
            throw ValidationException::withMessages(['recovery' => 'Only an active, set-up account can receive a password reset link. Reissue the invitation or unlock the account first.']);
        }
        if ($this->transport->usesLogTransport()) {
            throw ValidationException::withMessages(['recovery' => 'This mail configuration includes a log transport, so reset links cannot be sent safely. Configure a safe transport first.']);
        }

        try {
            $status = Password::broker()->sendResetLink(['email' => $account->email, 'status' => AccountStatus::Active->value]);
        } catch (\Throwable $exception) {
            Log::warning('Password reset mail transport failed.', ['user_id' => $account->public_id, 'exception_type' => $exception::class, 'reason' => Str::limit($exception->getMessage(), 300)]);
            $this->audit->record($actor, 'account_password_reset_failed', $target->public_id, ['status' => 'delivery_failed'], module: 'M10', entityType: 'staff');

            return 'delivery_failed';
        }
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['recovery' => 'A reset link was sent recently. Wait a minute before sending another.']);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['recovery' => 'This account cannot receive a reset link. Reload and check its status.']);
        }
        $this->audit->record($actor, 'account_password_reset_sent', $target->public_id, ['status' => 'active'], module: 'M10', entityType: 'staff');

        return config('mail.default') === 'array' ? 'local_capture' : 'mail_accepted';
    }

    /** Release a locked account: back to active when its password was set, otherwise back to invited. */
    public function unlock(User $actor, Staff $target, string $reason): User
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']], attributes: ['reason' => 'unlock reason'])->validate();
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Unlock reason is required.']);
        }

        return DB::transaction(function () use ($actor, $target, $reason): User {
            $staff = Staff::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('recoverAccount', $staff);
            $account = $staff->user()->lockForUpdate()->first();
            if (! $account || $account->status !== AccountStatus::Locked) {
                throw ValidationException::withMessages(['recovery' => 'This account is not locked. Reload and check its status.']);
            }
            if ($actor->is($account)) {
                throw ValidationException::withMessages(['recovery' => 'Ask another System Admin to unlock your own account.']);
            }
            $account->status = $account->email_verified_at === null ? AccountStatus::Invited : AccountStatus::Active;
            $account->remember_token = null;
            $account->save();
            $this->audit->record($actor, 'account_unlocked', $staff->public_id, ['status' => $account->status->value], trim($reason), module: 'M10', entityType: 'staff', previous: ['status' => 'locked']);

            return $account;
        }, 3);
    }
}
