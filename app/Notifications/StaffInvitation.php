<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** M01/M10: short-lived activation through the existing broker; never put tokens in audit/log channels. */
class StaffInvitation extends Notification
{
    public function __construct(#[\SensitiveParameter] private string $token, private ?string $role = null) {}

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
