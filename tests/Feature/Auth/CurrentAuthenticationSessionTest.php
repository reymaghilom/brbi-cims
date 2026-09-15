<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CurrentAuthenticationSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a secure password';

    public function test_an_authenticated_user_who_becomes_inactive_is_logged_out_securely(): void
    {
        $user = User::factory()->create([
            'username' => 'newly.disabled',
            'password' => self::PASSWORD,
        ]);
        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ]);
        $this->withSession(['sensitive_probe' => 'present']);
        $sessionIdBefore = session()->getId();
        $tokenBefore = session()->token();

        $user->forceFill(['status' => UserStatus::Disabled])->save();
        Auth::forgetUser();

        $this->get(route('password.change-required.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('sensitive_probe')
            ->assertSessionHasErrors([
                'authentication' => 'Your account is currently unavailable. Please contact the system administrator.',
            ]);

        $this->assertGuest();
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertNotSame($tokenBefore, session()->token());
    }

    public function test_an_authentication_session_version_mismatch_logs_the_user_out_securely(): void
    {
        $user = User::factory()->create([
            'username' => 'expired.session',
            'password' => self::PASSWORD,
        ]);
        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ]);
        $this->withSession([
            'auth_session_version' => $user->auth_session_version + 1,
            'sensitive_probe' => 'present',
        ]);
        $sessionIdBefore = session()->getId();
        $tokenBefore = session()->token();

        $this->get(route('password.change-required.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('sensitive_probe')
            ->assertSessionHasErrors([
                'authentication' => 'Your session has expired. Please log in again.',
            ]);

        $this->assertGuest();
        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertNotSame($tokenBefore, session()->token());
    }

    public function test_a_missing_authentication_session_version_is_initialized_without_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['sensitive_probe' => 'present'])
            ->get(route('password.change-required.edit'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('auth_session_version', $user->auth_session_version)
            ->assertSessionHas('sensitive_probe', 'present');

        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_changes_the_actual_session_id(): void
    {
        $user = User::factory()->create([
            'username' => 'session.regeneration',
            'password' => self::PASSWORD,
        ]);
        $this->get(route('login'))->assertOk();
        $sessionIdBefore = session()->getId();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBefore, session()->getId());
    }

    public function test_successful_login_redirects_to_the_intended_protected_destination(): void
    {
        $user = User::factory()->create([
            'username' => 'intended.destination',
            'password' => self::PASSWORD,
        ]);
        $destination = route('password.change-required.edit');

        $this->get($destination)->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ])->assertRedirect($destination);

        $this->assertAuthenticatedAs($user);
    }

    public function test_forced_password_change_redirect_overrides_the_intended_destination(): void
    {
        $user = User::factory()->create([
            'username' => 'forced.destination',
            'password' => self::PASSWORD,
            'must_change_password' => true,
        ]);

        $this->get(route('home'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('password.change-required.edit'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_records_the_request_ip_and_login_time(): void
    {
        $user = User::factory()->create([
            'username' => 'login.metadata',
            'password' => self::PASSWORD,
            'last_login_at' => null,
            'last_login_ip' => null,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->post(route('login.store'), [
                'username' => $user->username,
                'password' => self::PASSWORD,
            ])->assertRedirect(route('home'));

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('203.0.113.42', $user->last_login_ip);
    }

    public function test_logout_clears_the_current_remember_me_authentication_state(): void
    {
        $user = User::factory()->create([
            'username' => 'remember.logout',
            'password' => self::PASSWORD,
            'remember_token' => null,
        ]);
        $recallerName = Auth::guard('web')->getRecallerName();

        $loginResponse = $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => self::PASSWORD,
            'remember' => '1',
        ]);
        $loginResponse->assertCookie($recallerName);
        $rememberTokenBefore = $user->fresh()->remember_token;
        $recallerCookie = $loginResponse->getCookie($recallerName);

        $this->withCookie($recallerName, $recallerCookie->getValue())
            ->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertCookieExpired($recallerName);

        $this->assertGuest();
        $this->assertNotNull($rememberTokenBefore);
        $this->assertNotSame($rememberTokenBefore, $user->fresh()->remember_token);
    }
}
