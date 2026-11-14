<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * M01/M10: short-lived activation through the existing broker; never put tokens in audit/log channels.
 * Sent from the "email" queue so saving a staff record does not wait for the mail server; the payload is encrypted
 * because it carries the activation token.
 */
class StaffInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(#[\SensitiveParameter] private string $token, private ?string $role = null)
    {
        $this->onConnection(self::connectionName())->onQueue('email');
    }

    /** The queue connection invitations use: ACCOUNT_MAIL_CONNECTION when set, otherwise the default connection. */
    public static function connectionName(): string
    {
        return (string) (config('dcms.account_mail_connection') ?: config('queue.default'));
    }

    /** Whether invitations leave the request and are delivered by a queue worker. */
    public static function sentInBackground(): bool
    {
        return self::connectionName() !== 'sync';
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Staff invitation mail transport failed.', ['exception_type' => $exception::class, 'reason' => mb_substr($exception->getMessage(), 0, 300)]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('dcms.branding.name');
        $invited = "You have been invited to {$brand}, the ".config('dcms.branding.system_name');

        return (new MailMessage)->subject("Activate your {$brand} account")
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line($this->role ? "{$invited}, as **{$this->role}**." : "{$invited}.")
            ->line('Click the button below to choose your password and activate your account.')
            ->action('Activate my account', route('activation.edit', ['token' => $this->token, 'email' => $notifiable->email]))
            ->line('For your security, this link works only once and expires in '.config('auth.passwords.users.expire').' minutes. If it has expired, ask your System Admin to send a new invitation.')
            ->line('Not expecting this email? You can safely ignore it. No account is activated unless the link is used.')
            ->salutation(new HtmlString('Regards,<br>'.e($brand)));
    }
}
