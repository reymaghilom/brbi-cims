<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateInitialAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_interactively_creates_active_administrator_without_forced_change(): void
    {
        $password = 'personally chosen password';

        $this->artisan('cims:create-admin')
            ->expectsQuestion('Full name', 'Primary Administrator')
            ->expectsQuestion('Username', 'Primary.Admin')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->expectsOutput("Administrator 'primary.admin' created successfully.")
            ->assertSuccessful();

        $administrator = User::sole();
        $this->assertSame(UserRole::Administrator, $administrator->role);
        $this->assertSame(UserStatus::Active, $administrator->status);
        $this->assertFalse($administrator->must_change_password);
        $this->assertTrue(Hash::check($password, $administrator->password));
        $this->assertNotNull($administrator->password_changed_at);

        $audit = AuditLog::where('action', 'administrator.bootstrapped')->sole();
        $this->assertStringNotContainsString($password, json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
        // Only these prompts exist; the audit event keeps its identity by users.id and username alone.
        $this->assertSame(['administrator_user_id', 'username', 'source'], array_keys($audit->metadata));
        $this->assertSame($administrator->id, $audit->metadata['administrator_user_id']);
    }

    public function test_command_rejects_duplicate_username_with_clear_validation_error(): void
    {
        User::factory()->create(['username' => 'existing.user']);

        $this->artisan('cims:create-admin')
            ->expectsQuestion('Full name', 'Primary Administrator')
            ->expectsQuestion('Username', 'existing.user')
            ->expectsQuestion('Password', 'personally chosen password')
            ->expectsQuestion('Confirm password', 'personally chosen password')
            ->expectsOutputToContain('The username has already been taken.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['role' => UserRole::Administrator->value]);
    }

    public function test_command_rejects_short_or_unconfirmed_password(): void
    {
        $this->artisan('cims:create-admin')
            ->expectsQuestion('Full name', 'Primary Administrator')
            ->expectsQuestion('Username', 'primary.admin')
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'different')
            ->expectsOutputToContain('Administrator account was not created')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_refuses_non_interactive_execution(): void
    {
        $this->artisan('cims:create-admin', ['--no-interaction' => true])
            ->expectsOutputToContain('This command is interactive only.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_refuses_to_bootstrap_a_second_administrator(): void
    {
        User::factory()->administrator()->create();

        $this->artisan('cims:create-admin')
            ->expectsOutputToContain('An Administrator account already exists.')
            ->assertFailed();

        $this->assertSame(1, User::where('role', UserRole::Administrator->value)->count());
    }
}
