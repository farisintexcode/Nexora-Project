<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Attaches the Nexora logo inline to outgoing HTML mail that references it, so mail clients show it
 * without loading an image from the app URL (which is not reachable from Gmail on local or private hosts).
 */
class EmbedMailLogo
{
    public const CONTENT_ID = 'nexora-logo';

    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        if (! $message instanceof Email || ! str_contains((string) $message->getHtmlBody(), 'cid:'.self::CONTENT_ID)) {
            return;
        }
        $alreadyEmbedded = collect($message->getAttachments())->contains(fn (DataPart $part): bool => $part->getName() === self::CONTENT_ID);
        $logo = public_path('images/brand/nexora-logo-light.png');
        if (! $alreadyEmbedded && is_file($logo)) {
            $message->embedFromPath($logo, self::CONTENT_ID, 'image/png');
        }
    }
}
