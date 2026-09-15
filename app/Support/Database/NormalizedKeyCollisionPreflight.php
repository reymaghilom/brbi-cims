<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Read-only preflight for adding a UNIQUE index on normalized keys that PHP has already proven
 * distinct. It asks the DATABASE ENGINE whether any of those keys would still compare equal under
 * the collation the new column will actually use — the unique index's own equality rule — before a
 * migration makes any persistent schema change.
 *
 * - MySQL / MariaDB: a new column added without an explicit collation inherits the table's default
 *   collation, so that collation is read from information_schema and used to compare the keys
 *   (e.g. utf8mb4_unicode_ci treats "café pond" and "cafe pond" as equal). The comparison runs as a
 *   single SELECT over a derived table of bound parameters: no table is created, nothing is written.
 * - SQLite: a unique index compares values exactly, so keys PHP already found distinct cannot
 *   collide and there is nothing further to check.
 * - Any other driver fails clearly instead of guessing its comparison rules.
 */
class NormalizedKeyCollisionPreflight
{
    /**
     * @param  array<int|string, string>  $keysById  record id => normalized key (already PHP-distinct)
     * @return array{collation: ?string, conflicts: list<list<int>>} groups of record ids the database considers equal
     */
    public function collisions(ConnectionInterface $connection, string $table, array $keysById): array
    {
        $driver = method_exists($connection, 'getDriverName') ? $connection->getDriverName() : null;

        if ($driver === 'sqlite') {
            return ['collation' => null, 'conflicts' => []];
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("The normalized-name collision preflight does not support the [{$driver}] database driver. Verify uniqueness manually before migrating.");
        }

        $prefixedTable = (method_exists($connection, 'getTablePrefix') ? $connection->getTablePrefix() : '').$table;
        $collation = $connection->selectOne(
            'SELECT TABLE_COLLATION AS collation_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$prefixedTable],
        )?->collation_name;

        if (! is_string($collation) || preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
            throw new RuntimeException("Could not determine a valid collation for table [{$prefixedTable}]; the normalized-name uniqueness preflight cannot run safely.");
        }

        $charset = $connection->selectOne(
            'SELECT CHARACTER_SET_NAME AS charset_name FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?',
            [$collation],
        )?->charset_name;

        if (! is_string($charset) || preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            throw new RuntimeException("Could not determine the character set of collation [{$collation}]; the normalized-name uniqueness preflight cannot run safely.");
        }

        if ($keysById === []) {
            return ['collation' => $collation, 'conflicts' => []];
        }

        $rows = [];
        $bindings = [];
        foreach ($keysById as $id => $key) {
            $rows[] = "SELECT CAST(? AS UNSIGNED) AS id, CONVERT(? USING {$charset}) COLLATE {$collation} AS normalized_key";
            $bindings[] = (int) $id;
            $bindings[] = $key;
        }

        $groups = $connection->select(
            'SELECT GROUP_CONCAT(keys_to_check.id ORDER BY keys_to_check.id SEPARATOR \',\') AS ids '
            .'FROM ('.implode(' UNION ALL ', $rows).') AS keys_to_check '
            .'GROUP BY keys_to_check.normalized_key HAVING COUNT(*) > 1',
            $bindings,
        );

        return [
            'collation' => $collation,
            'conflicts' => array_values(array_map(
                fn (object $group): array => array_map('intval', explode(',', (string) $group->ids)),
                $groups,
            )),
        ];
    }
}
