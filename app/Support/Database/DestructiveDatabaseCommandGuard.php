<?php

namespace App\Support\Database;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

final class DestructiveDatabaseCommandGuard
{
    private const PROTECTED_DATABASE = 'brbi_cims';

    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    public function handle(CommandStarting $event): void
    {
        $requestedConnection = $event->input->hasParameterOption('--database')
            ? $event->input->getParameterOption('--database')
            : null;

        $this->assertCommandIsSafe(
            $event->command,
            is_string($requestedConnection) ? $requestedConnection : null,
            (array) config('database', []),
        );
    }

    public function assertCommandIsSafe(string $command, ?string $requestedConnection, array $databaseConfig): void
    {
        if (! in_array($command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        $connectionName = $this->effectiveConnectionName($requestedConnection, $databaseConfig);
        $connection = $databaseConfig['connections'][$connectionName] ?? null;

        if (! is_array($connection)) {
            $this->blockUnresolvedTarget($command, $connectionName);
        }

        [$driver, $database] = $this->effectiveDriverAndDatabase($connection);

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite', 'pgsql', 'sqlsrv'], true) || $database === null) {
            $this->blockUnresolvedTarget($command, $connectionName);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true) && $this->normalize($database) === self::PROTECTED_DATABASE) {
            throw new RuntimeException("BLOCKED: {$command} cannot run against the protected ".self::PROTECTED_DATABASE." database. Connection: {$connectionName}.");
        }
    }

    private function effectiveConnectionName(?string $requestedConnection, array $databaseConfig): string
    {
        $connectionName = trim((string) ($requestedConnection ?: ($databaseConfig['default'] ?? '')));

        if ($connectionName === '') {
            throw new RuntimeException('BLOCKED: destructive database command target could not be safely determined (connection: <unresolved>).');
        }

        return $connectionName;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function effectiveDriverAndDatabase(array $connection): array
    {
        $url = $connection['url'] ?? null;

        if (is_string($url) && trim($url) !== '') {
            $parts = parse_url(trim($url));

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['path'])) {
                return [null, null];
            }

            $driver = match (strtolower(trim((string) $parts['scheme']))) {
                'mysql' => 'mysql',
                'mariadb' => 'mariadb',
                'postgres', 'postgresql', 'pgsql' => 'pgsql',
                'mssql', 'sqlsrv' => 'sqlsrv',
                'sqlite' => 'sqlite',
                default => null,
            };
            $database = trim(rawurldecode(ltrim((string) $parts['path'], '/')));

            return [$driver, $database === '' ? null : $database];
        }

        $driver = isset($connection['driver']) && is_string($connection['driver'])
            ? strtolower(trim($connection['driver']))
            : null;
        $database = isset($connection['database']) && is_string($connection['database'])
            ? trim($connection['database'])
            : null;

        return [$driver === '' ? null : $driver, $database === '' ? null : $database];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    private function blockUnresolvedTarget(string $command, string $connectionName): never
    {
        throw new RuntimeException("BLOCKED: {$command} target could not be safely determined (connection: {$connectionName}).");
    }
}
