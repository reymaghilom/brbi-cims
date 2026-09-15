<?php

namespace Tests\Feature\Database;

use App\Support\Database\NormalizedKeyCollisionPreflight;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-1 migration safety, exercised only on the isolated SQLite :memory: test database.
 *
 * Each test rolls its migration back with that migration's own down(), seeds raw rows with
 * DB::table() (bypassing model saving hooks, like pre-migration production data), and runs up()
 * again — so a conflict must be refused while the schema and the data are still untouched.
 */
class PreLaunchMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIES = '2026_09_14_000300_add_normalized_name_to_custom_business_categories_table.php';

    private const DEFINITIONS = '2026_09_14_000400_add_normalized_name_to_activity_definitions_table.php';

    private const EMPLOYEE_ID = '2026_09_14_000500_drop_employee_id_from_users_table.php';

    // ------------------------------------------------------------------ custom_business_categories

    public function test_category_migration_populates_unique_not_null_keys_for_non_conflicting_rows(): void
    {
        $migration = $this->migration(self::CATEGORIES);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('custom_business_categories', 'normalized_name'));

        $fish = DB::table('custom_business_categories')->insertGetId(['name' => '  Fish Pond ', 'is_active' => true]);
        $rental = DB::table('custom_business_categories')->insertGetId(['name' => 'Tricycle  Rental', 'is_active' => false]);

        $migration->up();

        $this->assertSame('fish pond', DB::table('custom_business_categories')->where('id', $fish)->value('normalized_name'));
        // This rule trims and lower-cases only; inner spacing is preserved.
        $this->assertSame('tricycle  rental', DB::table('custom_business_categories')->where('id', $rental)->value('normalized_name'));

        // Unique constraint works.
        $this->expectException(QueryException::class);
        DB::table('custom_business_categories')->insert(['name' => 'FISH POND', 'normalized_name' => 'fish pond', 'is_active' => true]);
    }

    public function test_category_migration_rejects_nullable_keys_after_it_runs(): void
    {
        $migration = $this->migration(self::CATEGORIES);
        $migration->down();
        $migration->up();

        $this->expectException(QueryException::class);
        DB::table('custom_business_categories')->insert(['name' => 'No Key', 'normalized_name' => null, 'is_active' => true]);
    }

    public function test_category_duplicates_fail_before_any_schema_or_data_change(): void
    {
        $migration = $this->migration(self::CATEGORIES);
        $migration->down();

        DB::table('custom_business_categories')->insert([
            ['name' => 'Fish Pond', 'is_active' => true],
            ['name' => ' fish pond', 'is_active' => false],
            ['name' => 'Rice Mill', 'is_active' => true],
        ]);
        $before = DB::table('custom_business_categories')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $columnsBefore = Schema::getColumnListing('custom_business_categories');

        try {
            $migration->up();
            $this->fail('Conflicting categories must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
            $this->assertStringContainsString('[fish pond]', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('custom_business_categories', 'normalized_name'));
        $this->assertSame($columnsBefore, Schema::getColumnListing('custom_business_categories'));
        $this->assertSame($before, DB::table('custom_business_categories')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_category_migration_refuses_an_unexpected_partial_column_without_repairing_it(): void
    {
        // The normal test schema already has the column but the migration is being run again.
        $this->assertTrue(Schema::hasColumn('custom_business_categories', 'normalized_name'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->migration(self::CATEGORIES)->up();
    }

    // ------------------------------------------------------------------ activity_definitions

    public function test_definition_migration_populates_squished_lowercase_keys_for_non_conflicting_rows(): void
    {
        $migration = $this->migration(self::DEFINITIONS);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('activity_definitions', 'normalized_name'));

        $spaced = DB::table('activity_definitions')->insertGetId(['code' => 'custom_spaced', 'name' => "  Employer \t Verification  "]);
        $plain = DB::table('activity_definitions')->insertGetId(['code' => 'custom_plain', 'name' => 'Supplier Call']);

        $migration->up();

        $this->assertSame('employer verification', DB::table('activity_definitions')->where('id', $spaced)->value('normalized_name'));
        $this->assertSame('supplier call', DB::table('activity_definitions')->where('id', $plain)->value('normalized_name'));
        $this->assertSame(0, DB::table('activity_definitions')->whereNull('normalized_name')->count());

        $this->expectException(QueryException::class);
        DB::table('activity_definitions')->insert(['code' => 'custom_dupe', 'name' => 'SUPPLIER CALL', 'normalized_name' => 'supplier call']);
    }

    public function test_definition_duplicates_fail_before_any_schema_or_data_change(): void
    {
        $migration = $this->migration(self::DEFINITIONS);
        $migration->down();

        DB::table('activity_definitions')->insert([
            ['code' => 'custom_employer_a', 'name' => 'Employer Verification'],
            ['code' => 'custom_employer_b', 'name' => 'employer   verification '],
        ]);
        $before = DB::table('activity_definitions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $columnsBefore = Schema::getColumnListing('activity_definitions');

        try {
            $migration->up();
            $this->fail('Conflicting Activity Types must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
            $this->assertStringContainsString('[employer verification]', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('activity_definitions', 'normalized_name'));
        $this->assertSame($columnsBefore, Schema::getColumnListing('activity_definitions'));
        $this->assertSame($before, DB::table('activity_definitions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_definition_migration_refuses_an_unexpected_partial_column_without_repairing_it(): void
    {
        $this->assertTrue(Schema::hasColumn('activity_definitions', 'normalized_name'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $this->migration(self::DEFINITIONS)->up();
    }

    // ------------------------------------------------------------------ users.employee_id

    public function test_employee_id_drop_removes_only_that_column_and_its_values(): void
    {
        $migration = $this->migration(self::EMPLOYEE_ID);
        $migration->down();
        $this->assertTrue(Schema::hasColumn('users', 'employee_id'), 'Setup restores the pre-migration column.');

        $userId = DB::table('users')->insertGetId([
            'employee_id' => 'EMP-0001', 'full_name' => 'Kept User', 'username' => 'kept.user',
            'password' => 'hashed-value', 'role' => 'credit_investigator', 'status' => 'active',
        ]);
        $columnsBefore = Schema::getColumnListing('users');

        $migration->up();

        $this->assertFalse(Schema::hasColumn('users', 'employee_id'));
        $this->assertEqualsCanonicalizing(array_values(array_diff($columnsBefore, ['employee_id'])), Schema::getColumnListing('users'));
        foreach (['id', 'full_name', 'username', 'password', 'role', 'status', 'remember_token', 'last_login_at', 'last_login_ip', 'must_change_password', 'auth_session_version', 'profile_photo_path'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "users.{$column} must survive.");
        }

        $user = DB::table('users')->where('id', $userId)->first();
        $this->assertSame('kept.user', $user->username);
        $this->assertSame('credit_investigator', $user->role);
        $this->assertSame('hashed-value', $user->password);

        // Running it again on the already-dropped schema is a safe no-op.
        $migration->up();
        $this->assertFalse(Schema::hasColumn('users', 'employee_id'));
    }

    // ------------------------------------------------------------------ database-collation preflight

    public function test_sqlite_preflight_has_nothing_to_compare_because_its_unique_index_is_exact(): void
    {
        $result = app(NormalizedKeyCollisionPreflight::class)->collisions(DB::connection(), 'custom_business_categories', [1 => 'café pond', 2 => 'cafe pond']);

        $this->assertSame(['collation' => null, 'conflicts' => []], $result);
    }

    #[DataProvider('mysqlFamilyDrivers')]
    public function test_mysql_family_preflight_asks_the_database_to_compare_keys_under_the_table_collation(string $driver): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn($driver);
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('selectOne')->once()
            ->with(Mockery::on(fn (string $sql) => str_contains($sql, 'information_schema.TABLES')), ['custom_business_categories'])
            ->andReturn((object) ['collation_name' => 'utf8mb4_unicode_ci']);
        $connection->shouldReceive('selectOne')->once()
            ->with(Mockery::on(fn (string $sql) => str_contains($sql, 'information_schema.COLLATIONS')), ['utf8mb4_unicode_ci'])
            ->andReturn((object) ['charset_name' => 'utf8mb4']);
        $connection->shouldReceive('select')->once()
            ->with(
                Mockery::on(fn (string $sql) => str_contains($sql, 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci')
                    && str_contains($sql, 'UNION ALL')
                    && str_contains($sql, 'GROUP BY keys_to_check.normalized_key HAVING COUNT(*) > 1')
                    && ! str_contains(strtoupper($sql), 'CREATE')
                    && ! str_contains(strtoupper($sql), 'INSERT')),
                [3, 'café pond', 7, 'cafe pond', 9, 'rice mill'],
            )
            ->andReturn([(object) ['ids' => '3,7']]);

        $result = (new NormalizedKeyCollisionPreflight)->collisions($connection, 'custom_business_categories', [3 => 'café pond', 7 => 'cafe pond', 9 => 'rice mill']);

        $this->assertSame(['collation' => 'utf8mb4_unicode_ci', 'conflicts' => [[3, 7]]], $result);
    }

    public static function mysqlFamilyDrivers(): array
    {
        return ['mysql' => ['mysql'], 'mariadb' => ['mariadb']];
    }

    public function test_mysql_preflight_refuses_an_undeterminable_or_unsafe_collation_and_unsupported_drivers(): void
    {
        $unsafe = Mockery::mock(Connection::class);
        $unsafe->shouldReceive('getDriverName')->andReturn('mysql');
        $unsafe->shouldReceive('getTablePrefix')->andReturn('');
        $unsafe->shouldReceive('selectOne')->once()->andReturn((object) ['collation_name' => 'utf8mb4_bin; DROP TABLE users']);
        $unsafe->shouldNotReceive('select');

        try {
            (new NormalizedKeyCollisionPreflight)->collisions($unsafe, 'activity_definitions', [1 => 'a']);
            $this->fail('An unsafe collation name must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Could not determine a valid collation', $exception->getMessage());
        }

        $missing = Mockery::mock(Connection::class);
        $missing->shouldReceive('getDriverName')->andReturn('mariadb');
        $missing->shouldReceive('getTablePrefix')->andReturn('');
        $missing->shouldReceive('selectOne')->once()->andReturn(null);
        $missing->shouldNotReceive('select');

        try {
            (new NormalizedKeyCollisionPreflight)->collisions($missing, 'activity_definitions', [1 => 'a']);
            $this->fail('A missing table collation must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Could not determine a valid collation', $exception->getMessage());
        }

        $pgsql = Mockery::mock(Connection::class);
        $pgsql->shouldReceive('getDriverName')->andReturn('pgsql');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support the [pgsql] database driver');
        (new NormalizedKeyCollisionPreflight)->collisions($pgsql, 'activity_definitions', [1 => 'a']);
    }

    public function test_a_database_collation_collision_stops_the_category_migration_before_any_schema_change(): void
    {
        $migration = $this->migration(self::CATEGORIES);
        $migration->down();
        $cafe = DB::table('custom_business_categories')->insertGetId(['name' => 'Café Pond', 'is_active' => true]);
        $plain = DB::table('custom_business_categories')->insertGetId(['name' => 'Cafe Pond', 'is_active' => true]);
        $before = DB::table('custom_business_categories')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->fakeCollationCollision([$cafe, $plain]);

        try {
            $migration->up();
            $this->fail('A collation collision must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
            $this->assertStringContainsString('utf8mb4_unicode_ci', $exception->getMessage());
            $this->assertStringContainsString("{$cafe} [café pond]", $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('custom_business_categories', 'normalized_name'));
        $this->assertSame($before, DB::table('custom_business_categories')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_a_database_collation_collision_stops_the_definition_migration_before_any_schema_change(): void
    {
        $migration = $this->migration(self::DEFINITIONS);
        $migration->down();
        $accented = DB::table('activity_definitions')->insertGetId(['code' => 'custom_cafe_a', 'name' => 'Café Visit']);
        $plain = DB::table('activity_definitions')->insertGetId(['code' => 'custom_cafe_b', 'name' => 'Cafe Visit']);
        $before = DB::table('activity_definitions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->fakeCollationCollision([$accented, $plain]);

        try {
            $migration->up();
            $this->fail('A collation collision must stop the migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nothing was changed', $exception->getMessage());
            $this->assertStringContainsString("{$plain} [cafe visit]", $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('activity_definitions', 'normalized_name'));
        $this->assertSame($before, DB::table('activity_definitions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    /** Stands in for MySQL/MariaDB reporting that two PHP-distinct keys compare equal under the collation. */
    private function fakeCollationCollision(array $ids): void
    {
        $this->app->instance(NormalizedKeyCollisionPreflight::class, new class($ids) extends NormalizedKeyCollisionPreflight
        {
            public function __construct(private readonly array $ids) {}

            public function collisions(ConnectionInterface $connection, string $table, array $keysById): array
            {
                return ['collation' => 'utf8mb4_unicode_ci', 'conflicts' => [$this->ids]];
            }
        });
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }
}
