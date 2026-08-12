<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_and_create_user_with_temporary_password(): void
    {
        $administrator = User::factory()->administrator()->create();
        $temporaryPassword = 'administrator selected temporary password';

        $this->actingAs($administrator)->get(route('admin.users.index'))->assertOk();

        $response = $this->actingAs($administrator)->post(route('admin.users.store'), [
            'full_name' => 'New Credit Investigator',
            'employee_id' => 'CI-100',
            'username' => 'New.CI',
            'role' => UserRole::CreditInvestigator->value,
            'password' => $temporaryPassword,
            'password_confirmation' => $temporaryPassword,
        ]);

        $user = User::where('username', 'new.ci')->sole();
        $response->assertRedirect(route('admin.users.edit', $user));
        $this->assertSame(UserRole::CreditInvestigator, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'user_id' => $administrator->id]);
        $this->assertStringNotContainsString($temporaryPassword, json_encode(AuditLog::latest('id')->first()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_administrator_can_update_basic_information_and_role(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $oldVersion = $user->auth_session_version;
        DB::table('sessions')->insert(['id' => 'role-session', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => now()->timestamp]);

        $this->actingAs($administrator)->put(route('admin.users.update', $user), [
            'full_name' => 'Updated User',
            'employee_id' => 'UPDATED-1',
            'username' => 'updated.user',
            'role' => UserRole::Administrator->value,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('Updated User', $user->full_name);
        $this->assertSame(UserRole::Administrator, $user->role);
        $this->assertSame($oldVersion + 1, $user->auth_session_version);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_administrator_cannot_change_own_role_or_status(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $administrator), [
            'full_name' => $administrator->full_name,
            'employee_id' => $administrator->employee_id,
            'username' => $administrator->username,
            'role' => UserRole::CreditInvestigator->value,
        ])->assertSessionHasErrors('role');

        $this->actingAs($administrator)->patch(route('admin.users.status.update', $administrator), [
            'status' => UserStatus::Disabled->value,
        ])->assertForbidden();

        $this->assertSame(UserRole::Administrator, $administrator->fresh()->role);
        $this->assertSame(UserStatus::Active, $administrator->fresh()->status);
    }

    public function test_disabling_user_invalidates_sessions_and_remember_credentials_without_deleting_history(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create(['remember_token' => 'previous-remember-token', 'password' => 'existing user password']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $user->id, 'created_by' => $administrator->id]);
        $oldVersion = $user->auth_session_version;
        DB::table('sessions')->insert(['id' => 'active-user-session', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => now()->timestamp]);

        $this->actingAs($administrator)->patch(route('admin.users.status.update', $user), [
            'status' => UserStatus::Disabled->value,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame(UserStatus::Disabled, $user->status);
        $this->assertSame($oldVersion + 1, $user->auth_session_version);
        $this->assertNotSame('previous-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('client_folders', ['id' => $folder->id, 'assigned_ci_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.disabled', 'user_id' => $administrator->id]);

        auth()->logout();
        $this->post(route('login.store'), ['username' => $user->username, 'password' => 'existing user password'])
            ->assertSessionHasErrors('authentication');
        $this->assertGuest();
    }

    public function test_administrator_password_reset_integration_forces_change_and_invalidates_sessions(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create(['password' => 'existing user password']);
        DB::table('sessions')->insert(['id' => 'reset-user-session', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => now()->timestamp]);

        $response = $this->actingAs($administrator)->post(route('admin.users.password.reset', $user));

        $response->assertRedirect()->assertSessionHas('temporary_password');
        $temporaryPassword = session('temporary_password');
        $user->refresh();
        $this->assertIsString($temporaryPassword);
        $this->assertGreaterThanOrEqual(12, strlen($temporaryPassword));
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'user_id' => $administrator->id]);
    }

    public function test_active_session_middleware_ejects_a_disabled_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth_session_version' => $user->auth_session_version]);
        $user->forceFill(['status' => UserStatus::Disabled])->save();

        $this->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_user_management_never_exposes_existing_password_hash(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();

        $this->actingAs($administrator)->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertDontSee($user->password)
            ->assertDontSee('current password', false);
    }
}
