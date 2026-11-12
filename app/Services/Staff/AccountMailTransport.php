<?php

namespace App\Services\Staff;

/** M01/M10: account secrets (activation and reset links) must never be written to a log transport. */
class AccountMailTransport
{
    /** Whether the mailer, or any mailer it fails over to, writes messages to the log. */
    public function usesLogTransport(?string $name = null, array $seen = []): bool
    {
        $name ??= (string) config('mail.default');
        if (in_array($name, $seen, true)) {
            return true;
        }
        $seen[] = $name;
        if (config('mail.mailers.'.$name.'.transport') === 'log') {
            return true;
        }
        foreach (config('mail.mailers.'.$name.'.mailers', []) as $child) {
            if ($this->usesLogTransport($child, $seen)) {
                return true;
            }
        }

        return false;
    }
}
