<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY_PASSWORD = 'temporary passphrase';

    public function test_user_with_temporary_password_is_sent_to_required_change_flow(): void
    {
        $user = User::factory()->create([
            'username' => 'forced.user',
            'password' => self::TEMPORARY_PASSWORD,
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => self::TEMPORARY_PASSWORD,
        ])->assertRedirect(route('password.change-required.edit'));

        $this->get('/')->assertRedirect(route('password.change-required.edit'));
        $this->get(route('password.change-required.edit'))->assertOk();
    }

    public function test_required_password_change_validates_current_confirmation_and_length(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->put(route('password.change-required.update'), [
            'current_password' => 'wrong password',
            'password' => 'too-short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['current_password', 'password']);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_user_can_replace_temporary_password_and_continue(): void
    {
        $user = User::factory()->create([
            'username' => 'forced.user',
            'password' => self::TEMPORARY_PASSWORD,
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
        $newPassword = 'a new secure passphrase';

        $this->post('/login', ['username' => $user->username, 'password' => self::TEMPORARY_PASSWORD]);
        $response = $this->put(route('password.change-required.update'), [
            'current_password' => self::TEMPORARY_PASSWORD,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertRedirect(route('home'));
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->get('/')->assertOk();
    }
}
