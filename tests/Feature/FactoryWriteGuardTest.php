<?php

namespace Tests\Feature;

use App\Models\ClientFolder;
use App\Models\User;
use Database\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers Database\Factories\Factory — the shared base every application factory extends, which
 * blocks factory database persistence (create()/createOne()/createMany()/createQuietly()) outside
 * the testing environment unless cims.allow_factory_writes is explicitly true. This exists because
 * a manual `php artisan tinker` factory call once wrote permanent fake rows into the normal
 * development MySQL database (see the KOVACEK, HAROLD incident) — PHPUnit's own SQLite :memory:
 * isolation was never at fault, so these tests never touch a real database: only the container's
 * 'env' binding and the cims.allow_factory_writes config value are temporarily swapped, entirely
 * within this same isolated SQLite :memory: connection.
 */
class FactoryWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = app()->environment();
    }

    protected function tearDown(): void
    {
        app()->instance('env', $this->originalEnv);
        config(['cims.allow_factory_writes' => false]);
        parent::tearDown();
    }

    public function test_factory_create_is_allowed_during_the_testing_environment(): void
    {
        $this->assertSame('testing', app()->environment(), 'This suite must actually be running under APP_ENV=testing.');

        $user = User::factory()->create();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_factory_make_is_never_blocked_regardless_of_environment(): void
    {
        app()->instance('env', 'local');
        config(['cims.allow_factory_writes' => false]);

        $before = User::count();
        $user = User::factory()->make();

        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse($user->exists, 'make() must never persist a model.');
        $this->assertSame($before, User::count(), 'make() must not write to the database.');
    }

    public function test_factory_create_is_blocked_outside_testing_without_override(): void
    {
        app()->instance('env', 'local');
        config(['cims.allow_factory_writes' => false]);

        $before = User::count();

        try {
            User::factory()->create();
            $this->fail('Expected a RuntimeException blocking the factory write.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Factory database writes are disabled outside the testing environment', $e->getMessage());
            $this->assertStringContainsString('ALLOW_FACTORY_WRITES=true', $e->getMessage());
        }

        $this->assertSame($before, User::count(), 'A blocked create() must leave zero new rows.');
    }

    public function test_factory_create_is_blocked_in_production_by_default(): void
    {
        app()->instance('env', 'production');
        config(['cims.allow_factory_writes' => false]);

        $before = User::count();

        $this->expectException(RuntimeException::class);

        try {
            User::factory()->create();
        } finally {
            $this->assertSame($before, User::count(), 'Production must never silently allow a factory write.');
        }
    }

    public function test_missing_override_defaults_to_blocked_outside_testing(): void
    {
        app()->instance('env', 'local');
        // Deliberately do not set cims.allow_factory_writes at all — it must already default to
        // false (see config/cims.php), never fail open.
        $this->assertFalse(config('cims.allow_factory_writes'));

        $this->expectException(RuntimeException::class);
        User::factory()->create();
    }

    public function test_explicit_override_permits_factory_create_outside_testing(): void
    {
        app()->instance('env', 'local');
        config(['cims.allow_factory_writes' => true]);

        // Still lands on this test's own isolated SQLite :memory: connection — only the
        // environment/config simulation changed, never the actual database in use.
        $user = User::factory()->create();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_client_folder_factory_inherits_the_same_guard(): void
    {
        app()->instance('env', 'local');
        config(['cims.allow_factory_writes' => false]);

        $beforeFolders = ClientFolder::count();
        $beforeUsers = User::count();

        try {
            ClientFolder::factory()->create();
            $this->fail('Expected a RuntimeException blocking the factory write.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Factory database writes are disabled outside the testing environment', $e->getMessage());
        }

        $this->assertSame($beforeFolders, ClientFolder::count(), 'No Client Folder row may be created while blocked.');
        // ClientFolderFactory's definition() would otherwise also cascade two User::factory()
        // relations (assigned_ci_id, created_by) — confirming zero new users too proves the block
        // happened before any nested factory persistence, exactly like the original incident.
        $this->assertSame($beforeUsers, User::count(), 'No cascading User rows may be created either.');
    }

    public function test_user_factory_and_client_folder_factory_extend_the_shared_guarded_base(): void
    {
        $this->assertInstanceOf(Factory::class, User::factory());
        $this->assertInstanceOf(Factory::class, ClientFolder::factory());
    }
}
