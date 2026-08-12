<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a secure password';

    public function test_login_screen_is_private_entry_point_and_registration_is_absent(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Credit Investigation Management System')
            ->assertSee('Login');
        $this->get('/register')->assertNotFound();
    }

    public function test_active_user_can_log_in_with_username_and_session_is_regenerated(): void
    {
        $user = User::factory()->create(['username' => 'active.user', 'password' => self::PASSWORD]);

        $response = $this->withSession(['probe' => 'kept'])->post('/login', [
            'username' => 'active.user',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->auth_session_version, session('auth_session_version'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create(['username' => 'active.user', 'password' => self::PASSWORD]);

        $this->from('/login')->post('/login', [
            'username' => $user->username,
            'password' => 'incorrect password',
            'remember' => '1',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'authentication' => 'Invalid username or password. Please check your credentials and try again.',
        ]);

        $this->followingRedirects()->from('/login')->post('/login', [
            'username' => $user->username,
            'password' => 'incorrect password',
            'remember' => '1',
        ])
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Invalid username or password. Please check your credentials and try again.')
            ->assertSee('value="active.user"', false)
            ->assertSee('name="remember" type="checkbox" value="1"', false)
            ->assertSee('checked', false)
            ->assertDontSee('name="password" type="password" value=', false);

        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'username' => 'disabled.user',
            'password' => self::PASSWORD,
            'status' => UserStatus::Disabled,
        ]);

        $this->post('/login', [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid username or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->create(['username' => 'limited.user', 'password' => self::PASSWORD]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['username' => 'limited.user', 'password' => 'incorrect password']);
        }

        $response = $this->post('/login', ['username' => 'limited.user', 'password' => self::PASSWORD]);

        $response->assertSessionHasErrors([
            'authentication' => 'Too many login attempts. Please wait a moment and try again.',
        ]);
        $this->assertGuest();
        $this->assertTrue(RateLimiter::tooManyAttempts('limited.user|127.0.0.1', 5));
    }

    public function test_blank_login_fields_use_concise_field_level_validation(): void
    {
        $this->from('/login')->post('/login', [
            'username' => '',
            'password' => '',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'username' => 'Username is required.',
            'password' => 'Password is required.',
        ]);

        $this->followingRedirects()->from('/login')->post('/login', [
            'username' => '',
            'password' => '',
        ])
            ->assertOk()
            ->assertSee('Username is required.')
            ->assertSee('Password is required.')
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('name="password" type="password" value=', false);
    }

    public function test_remember_me_issues_a_recaller_cookie(): void
    {
        $user = User::factory()->create(['username' => 'remember.user', 'password' => self::PASSWORD]);

        $response = $this->post('/login', [
            'username' => $user->username,
            'password' => self::PASSWORD,
            'remember' => '1',
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create(['username' => 'logout.user', 'password' => self::PASSWORD]);

        $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD]);

        $this->withSession(['sensitive_probe' => 'present'])
            ->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('sensitive_probe');

        $this->assertGuest();
    }

    public function test_passwords_are_stored_only_as_secure_hashes(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->assertNotSame(self::PASSWORD, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->getRawOriginal('password')));
    }
}
