<?php

namespace App\Services\Settings;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single system-wide, administrator-controlled decision for where NEW field evidence is stored:
 * Residence Check pictures, Business Check pictures, and CI Activity Supporting Proof. Persisted in
 * the existing `system_settings` table under one key, so no separate configuration platform exists.
 *
 * This value only ever chooses the provider for a NEW upload. Reading, downloading, replacing and
 * removing an EXISTING media record always follow that record's own stored provider/path metadata —
 * never this setting — so switching modes can never strand or relocate historical evidence.
 */
class EvidenceStorageSetting
{
    public const KEY = 'evidence_storage_provider';

    public const LOCAL = 'local';

    public const CLOUDINARY = 'cloudinary';

    /** @var list<string> */
    public const PROVIDERS = [self::LOCAL, self::CLOUDINARY];

    /**
     * The active provider for new evidence uploads. Deliberately defaults to LOCAL: pilot users may
     * be on slow or unreliable connections, and an installation that has never chosen a mode must
     * never silently start shipping evidence to a cloud account.
     */
    public function provider(): string
    {
        $stored = SystemSetting::query()->where('key', self::KEY)->value('value');
        $provider = is_array($stored) ? ($stored['provider'] ?? null) : null;

        return in_array($provider, self::PROVIDERS, true) ? $provider : self::LOCAL;
    }

    public function usesCloud(): bool
    {
        return $this->provider() === self::CLOUDINARY;
    }

    /**
     * Persists an administrator's choice and records it in the existing AuditLog. Returns the
     * previous provider, or null when the submitted value already matched (no row is rewritten and
     * no audit entry is created for a no-op, so the trail only ever shows real changes).
     */
    public function update(User $actor, string $provider): ?string
    {
        throw_unless(in_array($provider, self::PROVIDERS, true), \InvalidArgumentException::class, 'Unsupported evidence storage provider.');

        return DB::transaction(function () use ($actor, $provider): ?string {
            $previous = $this->provider();
            if ($previous === $provider) {
                return null;
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => self::KEY],
                ['value' => ['provider' => $provider], 'is_encrypted' => false, 'updated_by' => $actor->id],
            );

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => null,
                'action' => 'system_setting.evidence_storage_changed',
                'module' => 'settings',
                'description' => 'The file storage provider was changed.',
                'metadata' => ['setting_key' => self::KEY, 'from' => $previous, 'to' => $provider],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $previous;
        });
    }

    public static function label(string $provider): string
    {
        return $provider === self::CLOUDINARY ? 'Cloud Storage' : 'Local Storage';
    }
}
