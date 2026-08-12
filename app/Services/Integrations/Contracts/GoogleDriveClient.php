<?php

namespace App\Services\Integrations\Contracts;

interface GoogleDriveClient
{
    public function ensureFolder(string $name, ?string $parentId = null): ExternalFileReference;

    public function upload(string $localPath, string $fileName, string $parentId): ExternalFileReference;
}
