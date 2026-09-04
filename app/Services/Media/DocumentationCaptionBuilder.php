<?php

namespace App\Services\Media;

use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;

/** Builds the Telegram caption preview for a Residence/Business Documentation set — always derived fresh from the set's current client name/type/location, never stored, so it can never go stale relative to a later edit before the set is actually sent. */
class DocumentationCaptionBuilder
{
    public const TELEGRAM_TEXT_LIMIT = 4096;

    public function build(ResidenceBusinessDocumentation $documentation): string
    {
        $documentation->loadMissing(['clientFolder', 'coMaker']);

        if (! $documentation->isResidence()) {
            $lines = ['Business: '.$documentation->businessDisplayName()];

            if (filled($documentation->location)) {
                $lines[] = 'Location: '.$documentation->location;
            }

            if (filled($documentation->remarks)) {
                $lines[] = 'Remarks: '.$documentation->remarks;
            }

            return implode("\n", $lines);
        }

        $clientName = $documentation->co_maker_id
            ? $documentation->coMaker->full_name
            : $documentation->clientFolder->display_name;

        $caption = "Client Name: {$clientName}\nResidence pictures and videos with Google Map located at {$documentation->location}";

        // Remarks are optional for Residence and Business alike, and appear only when non-blank —
        // a set without them must never produce an empty "Remarks:" block.
        if (filled($documentation->remarks)) {
            $caption .= "\n\nRemarks:\n{$documentation->remarks}";
        }

        return $caption;
    }

    public function buildForSender(ResidenceBusinessDocumentation $documentation, User $sender, ?string $messageBody = null): string
    {
        return $this->appendSender($messageBody ?? $this->build($documentation), $sender);
    }

    public function appendSender(string $messageBody, User $sender): string
    {
        return trim($messageBody)."\n\nSent by: {$sender->full_name}";
    }

    public function maxMessageBodyLength(User $sender): int
    {
        return self::TELEGRAM_TEXT_LIMIT - mb_strlen("\n\nSent by: {$sender->full_name}");
    }
}
