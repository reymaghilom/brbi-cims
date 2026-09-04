<?php

namespace App\Services\Media;

use App\Models\MediaReference;
use App\Services\Settings\EvidenceStorageSetting;

/**
 * Records where NEW evidence files were ACTUALLY written during the current request, so the success
 * toast can name the real provider instead of guessing from the global Evidence Storage setting.
 * That distinction matters: the setting can change between the upload and the response, a save can
 * touch no file at all, and a replacement can legitimately cross providers.
 *
 * Request-scoped (bound with `scoped()`), and it only ever holds provider names — never paths,
 * public ids, or credentials.
 */
class EvidenceStorageRecorder
{
    /** @var list<string> */
    private array $providers = [];

    public function record(?string $provider): void
    {
        if (blank($provider) || in_array($provider, $this->providers, true)) {
            return;
        }

        $this->providers[] = $provider;
    }

    public function storedAnything(): bool
    {
        return $this->providers !== [];
    }

    /** @return list<string> */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * The single provider every file in this request used, or null when nothing was stored (a
     * text-only save) or when one request genuinely spanned both providers.
     */
    public function provider(): ?string
    {
        $normalized = array_unique(array_map(
            fn (string $provider): string => $provider === MediaReference::STORAGE_PROVIDER_CLOUDINARY
                ? EvidenceStorageSetting::CLOUDINARY
                : EvidenceStorageSetting::LOCAL,
            $this->providers,
        ));

        return count($normalized) === 1 ? reset($normalized) : null;
    }

    /** Human-readable provider name for the toast, or null when this request stored no file. */
    public function label(): ?string
    {
        if (! $this->storedAnything()) {
            return null;
        }

        return match ($this->provider()) {
            EvidenceStorageSetting::CLOUDINARY => 'Cloud Storage (Cloudinary)',
            EvidenceStorageSetting::LOCAL => 'Local Storage',
            default => 'Local Storage and Cloud Storage (Cloudinary)',
        };
    }

    public function reset(): void
    {
        $this->providers = [];
    }
}
