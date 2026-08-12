<?php

namespace Tests\Feature\Auth;

use App\Actions\Authentication\ResetUserPassword;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministratorPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_reset_sets_temporary_password_forces_change_and_invalidates_sessions(): void
    {
        $administrator = User::factory()->administrator()->create();
        $user = User::factory()->create(['password' => 'the previous password']);
        $previousVersion = $user->auth_session_version;
        DB::table('sessions')->insert([
            'id' => 'existing-session',
            'user_id' => $user->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);
        $temporaryPassword = 'controlled temporary password';

        $returnedPassword = app(ResetUserPassword::class)->execute($administrator, $user, $temporaryPassword);

        $user->refresh();
        $this->assertSame($temporaryPassword, $returnedPassword);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertFalse(Hash::check('the previous password', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->assertSame($previousVersion + 1, $user->auth_session_version);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        $audit = AuditLog::where('action', 'user.password_reset')->sole();
        $this->assertSame($administrator->id, $audit->user_id);
        $this->assertSame($user->id, $audit->metadata['subject_user_id']);
        $this->assertStringNotContainsString($temporaryPassword, json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_non_administrator_cannot_execute_password_reset(): void
    {
        $investigator = User::factory()->create(['role' => UserRole::CreditInvestigator]);
        $user = User::factory()->create();

        $this->expectException(DomainException::class);

        app(ResetUserPassword::class)->execute($investigator, $user, 'controlled temporary password');
    }
}
