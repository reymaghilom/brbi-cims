<?php

namespace Tests\Unit;

use App\Support\Database\DestructiveDatabaseCommandGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DestructiveDatabaseCommandGuardTest extends TestCase
{
    #[DataProvider('destructiveCommands')]
    public function test_each_destructive_command_is_blocked_for_the_protected_mysql_database(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("BLOCKED: {$command} cannot run against the protected brbi_cims database.");

        $this->guard()->assertCommandIsSafe($command, null, $this->databaseConfig());
    }

    public static function destructiveCommands(): array
    {
        return [
            'migrate fresh' => ['migrate:fresh'],
            'migrate refresh' => ['migrate:refresh'],
            'migrate reset' => ['migrate:reset'],
            'database wipe' => ['db:wipe'],
        ];
    }

    #[DataProvider('ordinaryCommands')]
    public function test_ordinary_migration_commands_remain_allowed(string $command): void
    {
        $this->guard()->assertCommandIsSafe($command, null, $this->databaseConfig());

        $this->addToAssertionCount(1);
    }

    public static function ordinaryCommands(): array
    {
        return [
            'forward migrate' => ['migrate'],
            'migration status' => ['migrate:status'],
        ];
    }

    public function test_destructive_command_against_sqlite_memory_is_allowed(): void
    {
        $this->guard()->assertCommandIsSafe('migrate:fresh', 'sqlite', $this->databaseConfig());

        $this->addToAssertionCount(1);
    }

    public function test_protected_database_comparison_ignores_casing_and_whitespace(): void
    {
        $config = $this->databaseConfig();
        $config['connections']['mysql']['database'] = "  BrBi_CiMs\t";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected brbi_cims database');

        $this->guard()->assertCommandIsSafe('db:wipe', null, $config);
    }

    public function test_explicit_database_option_targeting_protected_connection_is_blocked(): void
    {
        $config = $this->databaseConfig();
        $config['default'] = 'disposable';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected brbi_cims database');

        $this->guard()->assertCommandIsSafe('migrate:reset', 'protected', $config);
    }

    public function test_explicit_disposable_connection_is_not_mistaken_for_protected_database(): void
    {
        $this->guard()->assertCommandIsSafe('migrate:fresh', 'disposable', $this->databaseConfig());

        $this->addToAssertionCount(1);
    }

    public function test_unresolved_destructive_target_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target could not be safely determined');

        $this->guard()->assertCommandIsSafe('migrate:refresh', 'missing', $this->databaseConfig());
    }

    public function test_unknown_driver_fails_closed(): void
    {
        $config = $this->databaseConfig();
        $config['connections']['unknown'] = ['driver' => 'custom', 'url' => null, 'database' => 'some_database'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target could not be safely determined');

        $this->guard()->assertCommandIsSafe('migrate:fresh', 'unknown', $config);
    }

    public function test_connection_url_database_is_resolved_without_exposing_url_credentials(): void
    {
        $config = $this->databaseConfig();
        $config['connections']['url-protected'] = [
            'driver' => 'mysql',
            'url' => 'mysql://example-user:example-password@example-host/BRBI_CIMS',
            'database' => 'ignored_by_url',
        ];

        try {
            $this->guard()->assertCommandIsSafe('db:wipe', 'url-protected', $config);
            $this->fail('The protected URL-backed database was not blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('protected brbi_cims database', $exception->getMessage());
            $this->assertStringNotContainsString('example-user', $exception->getMessage());
            $this->assertStringNotContainsString('example-password', $exception->getMessage());
            $this->assertStringNotContainsString('example-host', $exception->getMessage());
        }
    }

    public function test_application_provider_registers_the_command_start_listener(): void
    {
        $provider = file_get_contents(__DIR__.'/../../app/Providers/AppServiceProvider.php');

        $this->assertIsString($provider);
        $this->assertStringContainsString(
            'Event::listen(CommandStarting::class, DestructiveDatabaseCommandGuard::class);',
            $provider,
        );
    }

    private function guard(): DestructiveDatabaseCommandGuard
    {
        return new DestructiveDatabaseCommandGuard;
    }

    private function databaseConfig(): array
    {
        return [
            'default' => 'mysql',
            'connections' => [
                'mysql' => ['driver' => 'mysql', 'url' => null, 'database' => 'brbi_cims'],
                'protected' => ['driver' => 'mariadb', 'url' => null, 'database' => 'brbi_cims'],
                'disposable' => ['driver' => 'mysql', 'url' => null, 'database' => 'brbi_cims_disposable'],
                'sqlite' => ['driver' => 'sqlite', 'url' => null, 'database' => ':memory:'],
            ],
        ];
    }
}
