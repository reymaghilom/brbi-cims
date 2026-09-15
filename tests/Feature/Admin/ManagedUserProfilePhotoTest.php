<?php

namespace Tests\Feature\Admin;

use App\Actions\Users\CreateManagedUser;
use App\Actions\Users\UpdateManagedUser;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ManagedUserProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_with_a_profile_photo_persists_the_file_and_database_path(): void
    {
        Storage::fake('public');
        $administrator = User::factory()->administrator()->create();

        $user = app(CreateManagedUser::class)->execute($administrator, [
            'full_name' => 'Photo User',
            'username' => 'photo.user',
            'role' => UserRole::CreditInvestigator->value,
            'password' => 'temporary password',
            'profile_photo' => UploadedFile::fake()->image('profile.png'),
        ]);

        $this->assertNotNull($user->profile_photo_path);
        $this->assertSame($user->profile_photo_path, $user->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);
    }

    public function test_successful_photo_replacement_retains_the_new_file_and_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $administrator = User::factory()->administrator()->create();
        $previousPath = 'profile-photos/previous.png';
        Storage::disk('public')->put($previousPath, 'previous photo');
        $user = User::factory()->create(['profile_photo_path' => $previousPath]);

        app(UpdateManagedUser::class)->execute($administrator, $user, $this->updateData($user, [
            'profile_photo' => UploadedFile::fake()->image('replacement.webp'),
        ]));

        $newPath = $user->fresh()->profile_photo_path;
        $this->assertNotSame($previousPath, $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($previousPath);
    }

    public function test_update_without_a_new_photo_keeps_the_previous_path_and_file(): void
    {
        Storage::fake('public');
        $administrator = User::factory()->administrator()->create();
        $previousPath = 'profile-photos/keep.png';
        Storage::disk('public')->put($previousPath, 'existing photo');
        $user = User::factory()->create(['profile_photo_path' => $previousPath]);

        app(UpdateManagedUser::class)->execute($administrator, $user, $this->updateData($user));

        $this->assertSame($previousPath, $user->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists($previousPath);
    }

    public function test_failed_transaction_removes_the_replacement_and_preserves_the_previous_photo_and_user(): void
    {
        Storage::fake('public');
        $administrator = User::factory()->administrator()->create();
        $previousPath = 'profile-photos/preserved.png';
        Storage::disk('public')->put($previousPath, 'preserved photo');
        $user = User::factory()->create([
            'full_name' => 'Original Name',
            'profile_photo_path' => $previousPath,
        ]);
        AuditLog::creating(static function (): void {
            throw new RuntimeException('Simulated audit failure.');
        });

        $caught = null;
        try {
            app(UpdateManagedUser::class)->execute($administrator, $user, $this->updateData($user, [
                'full_name' => 'Changed Name',
                'profile_photo' => UploadedFile::fake()->image('rolled-back.png'),
            ]));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Simulated audit failure.', $caught->getMessage());
        $user->refresh();
        $this->assertSame('Original Name', $user->full_name);
        $this->assertSame($previousPath, $user->profile_photo_path);
        Storage::disk('public')->assertExists($previousPath);
        $this->assertSame([$previousPath], Storage::disk('public')->allFiles('profile-photos'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.updated']);
    }

    public function test_profile_update_with_an_unchanged_role_does_not_invalidate_sessions(): void
    {
        Storage::fake('public');
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create(['remember_token' => 'unchanged-remember-token']);
        $previousVersion = $user->auth_session_version;
        DB::table('sessions')->insert([
            'id' => 'unchanged-role-session',
            'user_id' => $user->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        app(UpdateManagedUser::class)->execute($administrator, $user, $this->updateData($user, [
            'profile_photo' => UploadedFile::fake()->image('profile-only.png'),
        ]));

        $user->refresh();
        $this->assertSame($previousVersion, $user->auth_session_version);
        $this->assertSame('unchanged-remember-token', $user->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'unchanged-role-session', 'user_id' => $user->id]);
        Storage::disk('public')->assertExists($user->profile_photo_path);
    }

    public function test_role_change_with_a_photo_replacement_keeps_photo_and_session_invalidation_behavior(): void
    {
        Storage::fake('public');
        $administrator = User::factory()->administrator()->create();
        $previousPath = 'profile-photos/role-change-previous.png';
        Storage::disk('public')->put($previousPath, 'previous photo');
        $user = User::factory()->create([
            'role' => UserRole::CreditInvestigator,
            'remember_token' => 'previous-remember-token',
            'profile_photo_path' => $previousPath,
        ]);
        $previousVersion = $user->auth_session_version;
        DB::table('sessions')->insert([
            'id' => 'role-change-session',
            'user_id' => $user->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        app(UpdateManagedUser::class)->execute($administrator, $user, $this->updateData($user, [
            'role' => UserRole::SeniorCreditInvestigator->value,
            'profile_photo' => UploadedFile::fake()->image('role-change-new.jpg'),
        ]));

        $user->refresh();
        $this->assertSame(UserRole::SeniorCreditInvestigator, $user->role);
        $this->assertSame($previousVersion + 1, $user->auth_session_version);
        $this->assertNotSame('previous-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'role-change-session']);
        $this->assertNotSame($previousPath, $user->profile_photo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);
        Storage::disk('public')->assertMissing($previousPath);
    }

    /** @param array<string, mixed> $overrides */
    private function updateData(User $user, array $overrides = []): array
    {
        return array_replace([
            'full_name' => $user->full_name,
            'username' => $user->username,
            'role' => $user->role->value,
        ], $overrides);
    }
}
