<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_and_create_user_with_temporary_password(): void
    {
        $administrator = User::factory()->administrator()->create();
        $temporaryPassword = 'administrator selected temporary password';

        $this->actingAs($administrator)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('data-user-form-dialog', false)
            ->assertSee('data-user-form-mode="create"', false)
            ->assertSee('Create User');

        $this->actingAs($administrator)->post(route('admin.users.store'), [
            'full_name' => 'New Credit Investigator',
            'username' => 'New.CI',
            'email' => ' New.CI@Example.COM ',
            'role' => UserRole::CreditInvestigator->value,
            'password' => $temporaryPassword,
            'password_confirmation' => 'does not match',
        ])->assertSessionHasErrors('password');

        $response = $this->actingAs($administrator)->post(route('admin.users.store'), [
            'full_name' => 'New Credit Investigator',
            'username' => 'New.CI',
            'email' => ' New.CI@Example.COM ',
            'role' => UserRole::CreditInvestigator->value,
            'password' => $temporaryPassword,
            'password_confirmation' => $temporaryPassword,
        ]);

        $user = User::where('username', 'new.ci')->sole();
        $response->assertRedirect(route('admin.users.index'));
        $this->assertSame(UserRole::CreditInvestigator, $user->role);
        $this->assertSame('new.ci@example.com', $user->email);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'user_id' => $administrator->id]);
        $this->assertStringNotContainsString($temporaryPassword, json_encode(AuditLog::latest('id')->first()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_user_management_keeps_the_list_as_the_page_and_opens_create_and_edit_forms_in_one_modal(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'full_name' => 'Modal Managed User',
            'username' => 'modal.user',
            'email' => 'modal.user@example.com',
            'role' => UserRole::SeniorCreditInvestigator,
        ]);

        $response = $this->actingAs($administrator)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('<table class="ui-table">', false)
            ->assertSee('data-modal-open="user-form-dialog"', false)
            ->assertSee('data-user-form-mode="create"', false)
            ->assertSee('data-user-form-mode="edit"', false)
            ->assertSee('data-user-id="'.$user->id.'"', false)
            ->assertSee('data-user-full-name="Modal Managed User"', false)
            ->assertSee('data-user-email="modal.user@example.com"', false)
            ->assertSee('data-user-role="'.UserRole::SeniorCreditInvestigator->value.'"', false)
            ->assertSee('data-action-icon="edit"', false)
            ->assertSee('id="user-form-dialog"', false)
            ->assertSee('data-scroll-body-only="true"', false)
            ->assertSee('data-modal-scroll-region', false)
            ->assertSee('min-h-0 flex-1 overflow-y-auto', false)
            ->assertSee('shrink-0', false)
            ->assertSee('data-modal-close', false)
            ->assertSee('Cancel')
            ->assertSee('data-user-create-icon', false)
            ->assertSee('data-user-save-icon', false)
            ->assertSee('data-user-form-submit', false)
            ->assertSee('data-user-status-trigger', false)
            ->assertSee('data-user-password-reset-trigger', false)
            ->assertSee('data-user-password-section', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('Use at least 8 characters.');

        $this->assertStringNotContainsString('href="'.route('admin.users.create').'"', $response->getContent());
        $this->assertStringNotContainsString('href="'.route('admin.users.edit', $user).'"', $response->getContent());

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('form.action = trigger.dataset.userFormAction || form.dataset.userStoreUrl;', $script);
        $this->assertStringContainsString("setFieldValue('full_name', editing ? trigger.dataset.userFullName : '');", $script);
        $this->assertStringContainsString("setFieldValue('role', editing ? trigger.dataset.userRole", $script);
        $this->assertStringContainsString("accountActions?.toggleAttribute('hidden', !editing || trigger.dataset.userIsSelf === 'true');", $script);
        $this->assertStringContainsString("passwordSection?.toggleAttribute('hidden', editing);", $script);
        $this->assertStringContainsString('password.disabled = editing;', $script);

        $markup = $response->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($markup, 'data-action-icon="edit"'));
        $this->assertMatchesRegularExpression('/data-user-form-mode="edit".*?<svg[^>]*data-action-icon="edit"[^>]*>.*?<\/svg>\s*<span>Edit<\/span>/s', $markup);
        $this->assertMatchesRegularExpression('/<button type="button" data-modal-close[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*<span>Cancel<\/span>\s*<\/button>/s', $markup);
        $this->assertMatchesRegularExpression('/<button type="submit" form="user-management-form"[^>]*>.*?data-user-create-icon.*?data-user-save-icon.*?<span data-user-form-submit>Create User<\/span>/s', $markup);
    }

    public function test_user_list_displays_saved_email_on_desktop_and_mobile_while_username_stays_in_the_form(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create([
            'full_name' => 'Visible Email User',
            'username' => 'visible.username',
            'email' => 'visible.user@example.com',
        ]);
        User::factory()->create([
            'full_name' => 'Legacy No Email User',
            'username' => 'legacy.username',
            'email' => null,
        ]);

        $html = $this->actingAs($administrator)->get(route('admin.users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<th scope="col">Email Address</th>', $html);
        $this->assertStringNotContainsString('<th scope="col">Username</th>', $html);
        $this->assertMatchesRegularExpression('/<td class="font-medium" data-user-email-display>visible\.user@example\.com<\/td>/', $html);
        $this->assertMatchesRegularExpression('/<p class="[^"]*" data-user-email-display><span class="font-semibold">Email Address:<\/span> visible\.user@example\.com<\/p>/', $html);
        $this->assertMatchesRegularExpression('/data-user-email-display>—<\/td>/', $html);
        $this->assertStringNotContainsString('<td class="font-medium">visible.username</td>', $html);

        // Username remains available to the shared Create/Edit modal and its edit trigger.
        $this->assertStringContainsString('name="username"', $html);
        $this->assertStringContainsString('data-user-username="'.$user->username.'"', $html);
        $this->assertStringContainsString('data-user-email="'.$user->email.'"', $html);
    }

    public function test_create_validation_reopens_the_user_modal_with_non_sensitive_input(): void
    {
        $administrator = User::factory()->administrator()->create();
        $index = route('admin.users.index');

        $response = $this->actingAs($administrator)->followingRedirects()->from($index)->post(route('admin.users.store'), [
            'user_modal' => 'create',
            'full_name' => 'Preserved Create Name',
            'username' => '',
            'email' => 'invalid-email',
            'role' => UserRole::CreditInvestigator->value,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertOk()
            ->assertSee('data-open-on-error="true"', false)
            ->assertSee('name="user_modal" value="create"', false)
            ->assertSee('value="Preserved Create Name"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="username-error"', false)
            ->assertSee('id="email-error"', false)
            ->assertSee('id="password-error"', false)
            ->assertDontSee('value="short"', false);
    }

    public function test_edit_validation_reopens_the_correct_user_modal_and_success_updates_that_user(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create(['full_name' => 'Original Modal User']);
        $index = route('admin.users.index');

        $response = $this->actingAs($administrator)->followingRedirects()->from($index)->put(route('admin.users.update', $target), [
            'user_modal' => 'edit',
            'user_modal_id' => $target->id,
            'full_name' => 'Preserved Edit Name',
            'username' => '',
            'email' => $target->email,
            'role' => $target->role->value,
        ]);

        $response->assertOk()
            ->assertSee('data-open-on-error="true"', false)
            ->assertSee('data-user-modal-notice', false)
            ->assertSee('data-user-modal-notice-message', false)
            ->assertSee('name="user_modal" value="edit"', false)
            ->assertSee('name="user_modal_id" value="'.$target->id.'"', false)
            ->assertSee('value="Preserved Edit Name"', false)
            ->assertSee('id="username-error"', false)
            ->assertSee('action="'.route('admin.users.update', $target).'"', false)
            ->assertSee('<span data-user-form-submit>Save Changes</span>', false)
            ->assertSee('data-user-save-icon', false);

        $this->from($index)->put(route('admin.users.update', $target), [
            'user_modal' => 'edit',
            'user_modal_id' => $target->id,
            'full_name' => 'Updated Through Modal',
            'username' => $target->username,
            'email' => $target->email,
            'role' => $target->role->value,
        ])->assertRedirect($index)->assertSessionHasNoErrors();

        $this->assertSame('Updated Through Modal', $target->fresh()->full_name);
    }

    public function test_identical_normalized_edit_is_a_server_confirmed_inline_modal_no_op(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create([
            'full_name' => 'Unchanged User',
            'username' => 'unchanged.user',
            'email' => 'unchanged.user@example.com',
            'role' => UserRole::CreditInvestigator,
            'updated_at' => now()->subDay(),
        ]);
        $originalUpdatedAt = $target->updated_at;
        $originalSessionVersion = $target->auth_session_version;
        $originalAuditCount = AuditLog::query()->where('action', 'user.updated')->count();
        DB::table('sessions')->insert(['id' => 'unchanged-user-session', 'user_id' => $target->id, 'payload' => 'payload', 'last_activity' => now()->timestamp]);

        $userUpdateQueries = [];
        DB::listen(function ($query) use (&$userUpdateQueries): void {
            if (preg_match('/^update\s+["`\[]?users["`\]]?\s+set/i', ltrim($query->sql))) {
                $userUpdateQueries[] = $query->sql;
            }
        });

        $response = $this->actingAs($administrator)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $target), [
                'user_modal' => 'edit',
                'user_modal_id' => $target->id,
                'full_name' => $target->full_name,
                'username' => '  UNCHANGED.USER  ',
                'email' => '  UNCHANGED.USER@EXAMPLE.COM  ',
                'role' => $target->role->value,
            ]);

        $response->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('user_modal_notice', 'No changes were made.')
            ->assertSessionMissing('status')
            ->assertSessionMissing('statusType')
            ->assertSessionHasInput('user_modal', 'edit')
            ->assertSessionHasInput('user_modal_id', $target->id);

        $reopenedModal = $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('No changes were made.')
            ->assertSee('data-user-modal-notice', false)
            ->assertSee('data-user-modal-notice-user-id="'.$target->id.'"', false)
            ->assertDontSee('<div data-toast role="status"', false)
            ->assertSee('data-open-on-error="true"', false)
            ->assertSee('name="user_modal" value="edit"', false)
            ->assertSee('name="user_modal_id" value="'.$target->id.'"', false)
            ->assertSee('action="'.route('admin.users.update', $target).'"', false)
            ->assertSee('value="'.$target->full_name.'"', false)
            ->assertSee('value="'.$target->username.'"', false)
            ->assertSee('value="'.$target->email.'"', false)
            ->assertSee('value="'.$target->role->value.'"', false)
            ->assertSee('<span data-user-form-submit>Save Changes</span>', false);

        $this->assertSame(1, substr_count($reopenedModal->getContent(), 'No changes were made.'));
        $this->assertMatchesRegularExpression('/id="user-form-dialog".*?data-user-modal-notice.*?No changes were made\..*?<form\s+id="user-management-form"/s', $reopenedModal->getContent());

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("notice?.toggleAttribute('hidden', !editing || trigger.dataset.userId !== notice.dataset.userModalNoticeUserId);", $script);
        $this->assertStringContainsString("headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }", $script);
        $this->assertStringContainsString('if (response.ok && payload.no_change === true)', $script);
        $this->assertStringContainsString("notice?.removeAttribute('hidden');", $script);
        $this->assertStringContainsString("form.addEventListener('input', hideNotice);", $script);

        $this->actingAs($administrator)
            ->putJson(route('admin.users.update', $target), [
                'user_modal' => 'edit',
                'user_modal_id' => $target->id,
                'full_name' => $target->full_name,
                'username' => $target->username,
                'email' => $target->email,
                'role' => $target->role->value,
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'No changes were made.', 'no_change' => true]);

        $target->refresh();
        $this->assertSame([], $userUpdateQueries);
        $this->assertTrue($originalUpdatedAt->equalTo($target->updated_at));
        $this->assertSame($originalSessionVersion, $target->auth_session_version);
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged-user-session', 'user_id' => $target->id]);
        $this->assertSame($originalAuditCount, AuditLog::query()->where('action', 'user.updated')->count());

        $this->from(route('admin.users.index'))->put(route('admin.users.update', $target), [
            'user_modal' => 'edit',
            'user_modal_id' => $target->id,
            'full_name' => 'Changed After No-op',
            'username' => $target->username,
            'email' => $target->email,
            'role' => $target->role->value,
        ])->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'User information updated.');

        $this->assertSame('Changed After No-op', $target->fresh()->full_name);
        $this->assertSame($originalAuditCount + 1, AuditLog::query()->where('action', 'user.updated')->count());
    }

    public function test_new_profile_photo_is_treated_as_a_real_user_update(): void
    {
        Storage::fake('public');

        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create(['profile_photo_path' => null]);

        $this->actingAs($administrator)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $target), [
                'full_name' => $target->full_name,
                'username' => $target->username,
                'email' => $target->email,
                'role' => $target->role->value,
                'profile_photo' => UploadedFile::fake()->image('replacement-avatar.jpg'),
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'User information updated.');

        $target->refresh();
        $this->assertNotNull($target->profile_photo_path);
        Storage::disk('public')->assertExists($target->profile_photo_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'user_id' => $administrator->id]);
        $this->assertTrue((bool) AuditLog::latest('id')->firstOrFail()->metadata['profile_photo_replaced']);
    }

    public function test_administrator_can_update_basic_information_and_role(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $oldVersion = $user->auth_session_version;
        DB::table('sessions')->insert(['id' => 'role-session', 'user_id' => $user->id, 'payload' => 'payload', 'last_activity' => now()->timestamp]);

        $this->actingAs($administrator)->put(route('admin.users.update', $user), [
            'full_name' => 'Updated User',
            'username' => 'updated.user',
            'role' => UserRole::Administrator->value,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('Updated User', $user->full_name);
        $this->assertSame(UserRole::Administrator, $user->role);
        $this->assertSame($oldVersion + 1, $user->auth_session_version);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_profile_photo_uses_the_same_url_in_the_account_page_and_header_with_safe_fallbacks(): void
    {
        Storage::fake('public');

        $photoPath = 'profile-photos/existing-avatar.png';
        Storage::disk('public')->put($photoPath, 'avatar image contents');
        $administrator = User::factory()->administrator()->create([
            'full_name' => 'Avatar Administrator',
            'profile_photo_path' => $photoPath,
        ]);
        $photoUrl = Storage::disk('public')->url($photoPath);

        $accountResponse = $this->actingAs($administrator)
            ->get(route('admin.users.edit', $administrator))
            ->assertOk();

        $this->assertSame(2, substr_count($accountResponse->getContent(), 'src="'.$photoUrl.'"'));
        $this->assertSame($photoPath, $administrator->fresh()->profile_photo_path);

        $administratorWithoutPhoto = User::factory()->administrator()->create([
            'full_name' => 'Fallback Administrator',
            'profile_photo_path' => null,
        ]);

        $this->actingAs($administratorWithoutPhoto)
            ->get(route('admin.users.edit', $administratorWithoutPhoto))
            ->assertOk()
            ->assertSee('FA')
            ->assertSee('data-photo-preview-placeholder', false);
        $this->assertNull($administratorWithoutPhoto->fresh()->profile_photo_path);
    }

    public function test_administrator_cannot_change_own_role_or_status(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->put(route('admin.users.update', $administrator), [
            'full_name' => $administrator->full_name,
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
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'existing user password'])
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

    public function test_email_column_is_nullable_and_unique_for_phase_one_compatibility(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'email'));

        $emailIndex = collect(Schema::getIndexes('users'))
            ->first(fn (array $index): bool => $index['columns'] === ['email']);

        $this->assertNotNull($emailIndex);
        $this->assertTrue($emailIndex['unique']);

        User::factory()->count(2)->create(['email' => null]);
        $this->assertSame(2, User::query()->whereNull('email')->count());
    }

    public function test_user_email_is_normalized_validated_and_unique_on_create(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->create(['email' => 'existing@example.com']);
        $basePayload = [
            'full_name' => 'Email Account User',
            'username' => 'email.account',
            'role' => UserRole::CreditInvestigator->value,
            'password' => 'temporary password',
            'password_confirmation' => 'temporary password',
        ];

        $this->actingAs($administrator)->post(route('admin.users.store'), $basePayload + [
            'email' => 'not-an-email',
        ])->assertSessionHasErrors('email');

        $this->post(route('admin.users.store'), $basePayload + [
            'email' => ' Existing@Example.COM ',
        ])->assertSessionHasErrors('email');

        $this->post(route('admin.users.store'), $basePayload + [
            'email' => ' New.User@Example.COM ',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'username' => 'email.account',
            'email' => 'new.user@example.com',
        ]);
    }

    public function test_administrator_can_update_email_and_keep_the_same_normalized_email(): void
    {
        $administrator = User::factory()->administrator()->create();
        $target = User::factory()->create(['email' => null]);
        $payload = [
            'full_name' => $target->full_name,
            'username' => $target->username,
            'role' => $target->role->value,
        ];

        $this->actingAs($administrator)->put(route('admin.users.update', $target), $payload + [
            'email' => ' Updated.User@Example.COM ',
        ])->assertSessionHasNoErrors();
        $this->assertSame('updated.user@example.com', $target->fresh()->email);

        $this->put(route('admin.users.update', $target), $payload + [
            'email' => 'updated.user@example.com',
        ])->assertSessionHasNoErrors();
        $this->assertSame('updated.user@example.com', $target->fresh()->email);

        $other = User::factory()->create(['email' => 'other@example.com']);
        $this->put(route('admin.users.update', $target), $payload + [
            'email' => strtoupper($other->email),
        ])->assertSessionHasErrors('email');
        $this->assertSame('updated.user@example.com', $target->fresh()->email);
    }

    public function test_users_are_managed_by_account_fields_only_and_audit_trail_backend_remains(): void
    {
        $administrator = User::factory()->administrator()->create();
        $historical = User::factory()->create(['full_name' => 'Historical User']);

        // The column no longer exists; users are identified only by users.id.
        $this->assertFalse(Schema::hasColumn('users', 'employee_id'));

        // Neither the Users list, the Create form nor the Edit form shows or asks for an employee number.
        foreach ([route('admin.users.index'), route('admin.users.create'), route('admin.users.edit', $historical)] as $url) {
            $this->actingAs($administrator)->get($url)->assertOk()
                ->assertDontSee('Employee')
                ->assertDontSee('employee', false);
        }

        // Create succeeds with the required account fields.
        $this->actingAs($administrator)->post(route('admin.users.store'), [
            'full_name' => 'Plain Account User',
            'username' => 'plain.account',
            'email' => 'plain.account@example.com',
            'role' => UserRole::CreditInvestigator->value,
            'password' => 'exactly8',
            'password_confirmation' => 'exactly8',
        ])->assertSessionHasNoErrors();
        $created = User::where('username', 'plain.account')->sole();
        $this->assertSame(UserRole::CreditInvestigator, $created->role);

        // Editing works with only the account fields and keeps the same users.id.
        $this->actingAs($administrator)->put(route('admin.users.update', $historical), [
            'full_name' => 'Historical User Renamed',
            'username' => $historical->username,
            'role' => $historical->role->value,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Historical User Renamed', User::query()->findOrFail($historical->id)->full_name);

        // Full name, username, email and role stay required; the password minimum stays 8.
        $this->actingAs($administrator)->post(route('admin.users.store'), [
            'password' => 'short77',
            'password_confirmation' => 'short77',
        ])->assertSessionHasErrors(['full_name', 'username', 'email', 'role', 'password']);
        $this->actingAs($administrator)->put(route('admin.users.update', $historical), [])
            ->assertSessionHasErrors(['full_name', 'username', 'role']);

        // Audit logging still records these actions, and the Audit Trail page itself still works.
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'user_id' => $administrator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'user_id' => $administrator->id]);
        $this->actingAs($administrator)->get(route('admin.audit-logs.index'))->assertOk();
    }
}
