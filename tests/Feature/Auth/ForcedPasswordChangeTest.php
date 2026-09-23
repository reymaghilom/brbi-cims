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
        $loginPage = $this->get(route('login'))->assertOk();

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::TEMPORARY_PASSWORD,
        ])->assertRedirect(route('password.change-required.edit'));

        $this->get('/')->assertRedirect(route('password.change-required.edit'));
        $changePage = $this->get(route('password.change-required.edit'))
            ->assertOk()
            ->assertSee('<p class="mt-6 text-center text-sm font-semibold leading-6 text-slate-700 sm:text-base">Credit Investigation Management System</p>', false)
            ->assertDontSee('Sign out instead')
            ->assertSee('<span>Change password</span>', false)
            ->assertDontSee('Change password and continue')
            ->assertSee('data-action-icon="key"', false)
            ->assertSee('aria-hidden="true"', false);

        $titleMarkup = '<p class="mt-6 text-center text-sm font-semibold leading-6 text-slate-700 sm:text-base">Credit Investigation Management System</p>';
        $this->assertStringContainsString($titleMarkup, $loginPage->getContent());
        $this->assertSame(1, substr_count($changePage->getContent(), '<button type="submit"'));
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

    public function test_successful_forced_password_change_logs_out_and_requires_sign_in_with_the_new_password(): void
    {
        $user = User::factory()->create([
            'username' => 'forced.user',
            'password' => self::TEMPORARY_PASSWORD,
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
        $newPassword = 'a new secure passphrase';

        $this->post('/login', ['email' => $user->email, 'password' => self::TEMPORARY_PASSWORD]);
        $sessionIdBefore = session()->getId();
        $csrfTokenBefore = session()->token();
        $response = $this->put(route('password.change-required.update'), [
            'current_password' => self::TEMPORARY_PASSWORD,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Password changed successfully. Please sign in with your new password.');
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertFalse(Hash::check(self::TEMPORARY_PASSWORD, $user->password));
        $this->assertGuest();
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertNotSame($csrfTokenBefore, session()->token());

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Password changed successfully. Please sign in with your new password.')
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false);
        $this->get(route('home'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::TEMPORARY_PASSWORD,
        ])->assertSessionHasErrors('authentication');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => $newPassword,
        ])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }
}
