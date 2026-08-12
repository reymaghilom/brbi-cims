<?php

namespace App\Services\Integrations\Contracts;

final readonly class TelegramSendResult
{
    /**
     * @param  list<string>  $messageIds
     */
    public function __construct(
        public array $messageIds,
        public ?string $messageLink = null,
    ) {}
}
