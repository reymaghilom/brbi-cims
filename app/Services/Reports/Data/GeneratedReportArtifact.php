<?php

namespace App\Services\Reports\Data;

final readonly class GeneratedReportArtifact
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public string $sha256,
        public int $sizeBytes,
    ) {}
}
