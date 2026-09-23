<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_administrators_cannot_access_any_user_management_endpoint(): void
    {
        $investigator = User::factory()->create(['role' => UserRole::CreditInvestigator]);
        $target = User::factory()->create();
        $payload = [
            'full_name' => 'Forbidden Update',
            'username' => 'forbidden.update',
            'role' => UserRole::SeniorCreditInvestigator->value,
        ];

        $this->actingAs($investigator)->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.users.create'))->assertForbidden();
        $this->post(route('admin.users.store'), $payload + [
            'password' => 'temporary password',
            'password_confirmation' => 'temporary password',
        ])->assertForbidden();
        $this->get(route('admin.users.edit', $target))->assertForbidden();
        $this->put(route('admin.users.update', $target), $payload)->assertForbidden();
        $this->patch(route('admin.users.status.update', $target), ['status' => UserStatus::Disabled->value])->assertForbidden();
        $this->post(route('admin.users.password.reset', $target))->assertForbidden();

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame($target->full_name, $target->fresh()->full_name);
    }

    public function test_invalid_role_and_status_are_rejected_without_mutation_or_audit(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $target), [
            'full_name' => 'Invalid Role Update',
            'username' => 'invalid.role',
            'role' => 'not-a-real-role',
        ])->assertSessionHasErrors('role');
        $this->patch(route('admin.users.status.update', $target), [
            'status' => 'suspended',
        ])->assertSessionHasErrors('status');

        $target->refresh();
        $this->assertNotSame('Invalid Role Update', $target->full_name);
        $this->assertSame(UserStatus::Active, $target->status);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_failed_self_role_status_and_password_reset_operations_create_no_audit_entries(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $administrator), [
            'full_name' => $administrator->full_name,
            'username' => $administrator->username,
            'role' => UserRole::CreditInvestigator->value,
        ])->assertSessionHasErrors('role');
        $this->patch(route('admin.users.status.update', $administrator), [
            'status' => UserStatus::Disabled->value,
        ])->assertForbidden();
        $this->post(route('admin.users.password.reset', $administrator))->assertForbidden();

        $administrator->refresh();
        $this->assertSame(UserRole::Administrator, $administrator->role);
        $this->assertSame(UserStatus::Active, $administrator->status);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_activation_does_not_invalidate_existing_sessions_or_remember_credentials(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create([
            'status' => UserStatus::Disabled,
            'remember_token' => 'activation-remember-token',
        ]);
        $previousVersion = $target->auth_session_version;
        DB::table('sessions')->insert([
            'id' => 'activation-session',
            'user_id' => $target->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($administrator)->patch(route('admin.users.status.update', $target), [
            'status' => UserStatus::Active->value,
        ])->assertRedirect()->assertSessionHas('status', 'User activated.');

        $target->refresh();
        $this->assertSame(UserStatus::Active, $target->status);
        $this->assertSame($previousVersion, $target->auth_session_version);
        $this->assertSame('activation-remember-token', $target->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'activation-session', 'user_id' => $target->id]);

        $audit = AuditLog::where('action', 'user.activated')->sole();
        $this->assertSame($administrator->id, $audit->user_id);
        $this->assertSame($target->id, $audit->metadata['subject_user_id']);
        $this->assertSame(UserStatus::Disabled->value, $audit->metadata['previous_status']);
        $this->assertSame(UserStatus::Active->value, $audit->metadata['status']);
    }

    public function test_user_management_audits_preserve_actor_target_and_change_metadata(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->post(route('admin.users.store'), [
            'full_name' => 'Audited User',
            'username' => 'audited.user',
            'email' => 'audited.user@example.com',
            'role' => UserRole::CreditInvestigator->value,
            'password' => 'temporary password',
            'password_confirmation' => 'temporary password',
        ])->assertSessionHasNoErrors();
        $target = User::where('username', 'audited.user')->sole();

        $this->put(route('admin.users.update', $target), [
            'full_name' => 'Audited User Updated',
            'username' => 'audited.user',
            'role' => UserRole::SeniorCreditInvestigator->value,
        ])->assertSessionHasNoErrors();
        $this->patch(route('admin.users.status.update', $target), [
            'status' => UserStatus::Disabled->value,
        ])->assertSessionHasNoErrors();
        $this->patch(route('admin.users.status.update', $target), [
            'status' => UserStatus::Active->value,
        ])->assertSessionHasNoErrors();
        $resetResponse = $this->post(route('admin.users.password.reset', $target))->assertSessionHasNoErrors();
        $temporaryPassword = $resetResponse->getSession()->get('temporary_password');

        $created = AuditLog::where('action', 'user.created')->sole();
        $updated = AuditLog::where('action', 'user.updated')->sole();
        $disabled = AuditLog::where('action', 'user.disabled')->sole();
        $activated = AuditLog::where('action', 'user.activated')->sole();
        $reset = AuditLog::where('action', 'user.password_reset')->sole();

        foreach ([$created, $updated, $disabled, $activated, $reset] as $audit) {
            $this->assertSame($administrator->id, $audit->user_id);
            $this->assertSame($target->id, $audit->metadata['subject_user_id']);
        }
        $this->assertSame('user_management', $created->module);
        $this->assertSame(UserRole::CreditInvestigator->value, $created->metadata['role']);
        $this->assertSame('user_management', $updated->module);
        $this->assertTrue($updated->metadata['role_changed']);
        $this->assertSame(UserRole::CreditInvestigator->value, $updated->metadata['previous_role']);
        $this->assertSame(UserRole::SeniorCreditInvestigator->value, $updated->metadata['role']);
        $this->assertSame('user_management', $disabled->module);
        $this->assertSame('user_management', $activated->module);
        $this->assertSame('authentication', $reset->module);
        $this->assertIsString($temporaryPassword);
        $this->assertStringNotContainsString($temporaryPassword, json_encode(AuditLog::all()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_reset_temporary_password_is_displayed_once_and_never_recorded_in_audit_metadata(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create();
        $editUrl = route('admin.users.edit', $target);

        $response = $this->actingAs($administrator)
            ->from($editUrl)
            ->post(route('admin.users.password.reset', $target))
            ->assertRedirect($editUrl)
            ->assertSessionHas('temporary_password');
        $temporaryPassword = $response->getSession()->get('temporary_password');

        $this->get($editUrl)->assertOk()->assertSee($temporaryPassword);
        $this->get($editUrl)->assertOk()->assertDontSee($temporaryPassword);

        $this->assertStringNotContainsString($temporaryPassword, json_encode(
            AuditLog::where('action', 'user.password_reset')->sole()->toArray(),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function test_user_role_labels_render_from_the_existing_enum_values(): void
    {
        $administrator = User::factory()->administrator()->create();
        $senior = User::factory()->create(['role' => UserRole::SeniorCreditInvestigator]);
        User::factory()->create(['role' => UserRole::CreditInvestigator]);

        $create = $this->actingAs($administrator)->get(route('admin.users.create'))->assertOk();
        $index = $this->get(route('admin.users.index'))->assertOk();
        $edit = $this->get(route('admin.users.edit', $senior))->assertOk();

        foreach (UserRole::cases() as $role) {
            $create->assertSee('value="'.$role->value.'"', false)->assertSee($role->label());
            $index->assertSee($role->label());
        }
        $edit->assertSee($senior->role->label());
    }

    public function test_username_normalization_does_not_bypass_create_or_update_uniqueness(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->create(['username' => 'existing.user']);
        $target = User::factory()->create(['username' => 'unchanged.user']);

        $this->actingAs($administrator)->post(route('admin.users.store'), [
            'full_name' => 'Duplicate User',
            'username' => ' Existing.User ',
            'role' => UserRole::CreditInvestigator->value,
            'password' => 'temporary password',
            'password_confirmation' => 'temporary password',
        ])->assertSessionHasErrors('username');
        $this->put(route('admin.users.update', $target), [
            'full_name' => 'Should Not Persist',
            'username' => ' EXISTING.USER ',
            'role' => $target->role->value,
        ])->assertSessionHasErrors('username');

        $this->assertSame('unchanged.user', $target->fresh()->username);
        $this->assertNotSame('Should Not Persist', $target->fresh()->full_name);
        $this->assertDatabaseCount('users', 3);
        $this->assertSame(0, AuditLog::query()->count());
    }
}
