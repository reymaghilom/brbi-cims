<?php

namespace App\Services\Integrations\N8n;

use RuntimeException;

class ReminderAuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
    ) {
        parent::__construct($reason);
    }
}
