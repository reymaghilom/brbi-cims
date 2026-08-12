<?php

namespace App\Services\Integrations\Contracts;

final readonly class ExternalFileReference
{
    public function __construct(
        public string $providerId,
        public ?string $webViewLink = null,
    ) {}
}
