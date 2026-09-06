<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PhpUnitDatabaseSafetyGuardTest extends TestCase
{
    public function test_exact_sqlite_memory_test_environment_is_allowed(): void
    {
        self::assertSafePhpUnitDatabaseEnvironment($this->safeEnvironment());

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeEnvironmentProvider')]
    public function test_unsafe_or_missing_database_environment_is_rejected(array $environment): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHPUnit aborted: tests may only use SQLite :memory:.');

        self::assertSafePhpUnitDatabaseEnvironment($environment);
    }

    public static function unsafeEnvironmentProvider(): array
    {
        $safe = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ];

        return [
            'MySQL connection' => [array_replace($safe, ['DB_CONNECTION' => 'mysql'])],
            'real development database name' => [array_replace($safe, ['DB_DATABASE' => 'brbi_cims'])],
            'file-backed SQLite database' => [array_replace($safe, ['DB_DATABASE' => 'database/testing.sqlite'])],
            'missing connection' => [array_replace($safe, ['DB_CONNECTION' => null])],
            'missing database' => [array_replace($safe, ['DB_DATABASE' => null])],
            'non-testing application environment' => [array_replace($safe, ['APP_ENV' => 'local'])],
            'non-empty database URL' => [array_replace($safe, ['DB_URL' => 'configured'])],
        ];
    }

    /** @return array{APP_ENV: string, DB_CONNECTION: string, DB_DATABASE: string, DB_URL: string} */
    private function safeEnvironment(): array
    {
        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ];
    }
}
