<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\Settings\EvidenceStorageSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    private ?string $ciTeamTestRoot = null;

    protected function setUp(): void
    {
        self::assertSafePhpUnitDatabaseEnvironment([
            'APP_ENV' => self::rawProcessEnvironmentValue('APP_ENV'),
            'DB_CONNECTION' => self::rawProcessEnvironmentValue('DB_CONNECTION'),
            'DB_DATABASE' => self::rawProcessEnvironmentValue('DB_DATABASE'),
            'DB_URL' => self::rawProcessEnvironmentValue('DB_URL'),
        ]);

        parent::setUp();
        $this->ciTeamTestRoot = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'cims-ci-team-tests'.DIRECTORY_SEPARATOR.Str::uuid();
        config(['cims.documents_root' => $this->ciTeamTestRoot]);
    }

    /**
     * @param  array{APP_ENV: ?string, DB_CONNECTION: ?string, DB_DATABASE: ?string, DB_URL: ?string}  $environment
     */
    protected static function assertSafePhpUnitDatabaseEnvironment(array $environment): void
    {
        $safe = $environment['APP_ENV'] === 'testing'
            && $environment['DB_CONNECTION'] === 'sqlite'
            && $environment['DB_DATABASE'] === ':memory:'
            && ($environment['DB_URL'] === null || $environment['DB_URL'] === '');

        if ($safe) {
            return;
        }

        $display = static fn (?string $value): string => $value === null ? '<unset>' : ($value === '' ? '<empty>' : $value);
        $dbUrlState = $environment['DB_URL'] === null ? '<unset>' : ($environment['DB_URL'] === '' ? '<empty>' : '<non-empty>');

        throw new \RuntimeException(sprintf(
            'PHPUnit aborted: tests may only use SQLite :memory:. APP_ENV=%s; DB_CONNECTION=%s; DB_DATABASE=%s; DB_URL=%s.',
            $display($environment['APP_ENV']),
            $display($environment['DB_CONNECTION']),
            $display($environment['DB_DATABASE']),
            $dbUrlState,
        ));
    }

    private static function rawProcessEnvironmentValue(string $name): ?string
    {
        $values = [];

        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $value) {
            if ($value !== null && $value !== false) {
                $values[] = (string) $value;
            }
        }

        $values = array_values(array_unique($values));
        if (count($values) > 1) {
            throw new \RuntimeException("PHPUnit aborted: conflicting process values for {$name}; tests may only use SQLite :memory:.");
        }

        return $values[0] ?? null;
    }

    /**
     * Puts the system-wide Evidence Storage setting into Cloud mode. Tests that assert Cloudinary
     * behavior need this because the pilot default is Local — the administrator setting, not
     * whether credentials happen to be configured, is what selects the provider for a new upload.
     */
    protected function useCloudEvidenceStorage(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => EvidenceStorageSetting::KEY],
            ['value' => ['provider' => EvidenceStorageSetting::CLOUDINARY]],
        );
    }

    protected function tearDown(): void
    {
        if ($this->ciTeamTestRoot !== null && is_dir($this->ciTeamTestRoot)) {
            File::deleteDirectory($this->ciTeamTestRoot);
        }
        parent::tearDown();
    }
}
