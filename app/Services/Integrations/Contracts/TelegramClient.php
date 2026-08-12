<?php

namespace App\Services\Integrations\Contracts;

interface TelegramClient
{
    /**
     * @param  list<string>  $mediaPaths
     */
    public function send(string $chatId, string $caption, array $mediaPaths = []): TelegramSendResult;
}
