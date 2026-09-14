<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a secure password';

    public function test_login_screen_is_private_entry_point_and_registration_is_absent(): void
    {
        $response = $this->get('/login')->assertOk()
            ->assertSee('Credit Investigation Management System')
            ->assertSee('Login');
        $this->assertMatchesRegularExpression('/<p[^>]*text-brand-sidebar[^>]*>Credit Investigation Management System<\/p>/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/Credit Investigation\s*<br[^>]*>\s*Management System/', $response->getContent());
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

    public function test_repeated_failed_attempts_never_lock_the_account(): void
    {
        $user = User::factory()->create(['username' => 'many.attempts', 'password' => self::PASSWORD]);

        // Well past the old five-attempt limit: every failure keeps the same generic message.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->post('/login', ['username' => 'many.attempts', 'password' => 'incorrect password'])
                ->assertSessionHasErrors([
                    'authentication' => 'Invalid username or password. Please check your credentials and try again.',
                ]);
            $this->assertGuest();
        }

        // The correct password still signs in immediately — no lockout, no waiting.
        $this->withSession(['probe' => 'kept'])
            ->post('/login', ['username' => 'many.attempts', 'password' => self::PASSWORD, 'remember' => '1'])
            ->assertRedirect(route('home'))
            ->assertSessionDoesntHaveErrors()
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
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

    // ==================================================
    // Permanent invariant: LOGIN MUST NEVER CREATE USERS.
    // ==================================================
    // Login authenticates existing users only (Auth::attempt() against Laravel's stock Eloquent
    // user provider — a read-only lookup + Hash::check(), with zero creation logic anywhere in
    // the chain). These three cases lock that guarantee in as a permanent regression test rather
    // than an implicit assumption, per the authentication audit's "Audit decision: A — SAFE"
    // conclusion. No new runtime guard is added — a User-creating login would be an actual
    // regression in AuthenticatedSessionController/LoginRequest, which these tests would then
    // catch directly.

    public function test_login_with_a_completely_nonexistent_username_creates_zero_users(): void
    {
        $userCount = User::count();

        $this->post('/login', [
            'username' => 'this-username-does-not-exist',
            'password' => 'whatever-arbitrary-password',
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid username or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
        $this->assertSame($userCount, User::count(), 'An unknown username must never cause a new User row to be created.');
    }

    public function test_login_with_wrong_password_creates_zero_users_and_leaves_the_existing_user_intact(): void
    {
        $user = User::factory()->create(['username' => 'invariant.wrongpass', 'password' => self::PASSWORD]);
        $userCount = User::count();

        $this->post('/login', [
            'username' => $user->username,
            'password' => 'definitely the wrong password',
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid username or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
        $this->assertSame($userCount, User::count(), 'A wrong password must never create a replacement/default/fake User.');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username]);
    }

    public function test_login_with_an_inactive_user_creates_zero_users(): void
    {
        $user = User::factory()->create([
            'username' => 'invariant.disabled',
            'password' => self::PASSWORD,
            'status' => UserStatus::Disabled,
        ]);
        $userCount = User::count();

        $this->post('/login', [
            'username' => $user->username,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid username or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
        $this->assertSame($userCount, User::count(), 'An inactive/disabled account being denied login must never create a new User.');
    }

    public function test_login_uses_username_and_password_only_and_the_session_identity_is_the_users_id(): void
    {
        $user = User::factory()->create(['username' => 'identity.user', 'password' => self::PASSWORD]);
        User::factory()->create(['username' => 'other.user', 'password' => self::PASSWORD]);

        $this->get('/login')->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false);

        $this->post('/login', ['username' => 'identity.user', 'password' => self::PASSWORD])->assertRedirect(route('home'));

        // The authenticated session resolves exactly this users.id, and the Dashboard works as that user.
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, Auth::id());
        $this->get(route('home'))->assertOk();
        $this->assertTrue(Auth::user()->is($user));
    }

    public function test_unknown_username_and_wrong_password_share_one_generic_message(): void
    {
        User::factory()->create(['username' => 'known.user', 'password' => self::PASSWORD]);
        $generic = ['authentication' => 'Invalid username or password. Please check your credentials and try again.'];

        $this->post('/login', ['username' => 'known.user', 'password' => 'not the password'])->assertSessionHasErrors($generic);
        $this->assertGuest();
        $this->post('/login', ['username' => 'nobody.here', 'password' => self::PASSWORD])->assertSessionHasErrors($generic);
        $this->assertGuest();
    }

    public function test_remember_me_checked_persists_a_remember_token_and_unchecked_uses_the_session_only(): void
    {
        $remembered = User::factory()->create(['username' => 'remembered.user', 'password' => self::PASSWORD, 'remember_token' => null]);
        $this->post('/login', ['username' => $remembered->username, 'password' => self::PASSWORD, 'remember' => '1'])
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($remembered->fresh()->remember_token, 'Remember Me stores a persistent token.');
        $this->post('/logout');

        $sessionOnly = User::factory()->create(['username' => 'session.user', 'password' => self::PASSWORD, 'remember_token' => null]);
        $this->post('/login', ['username' => $sessionOnly->username, 'password' => self::PASSWORD])
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($sessionOnly);
        $this->assertNull($sessionOnly->fresh()->remember_token, 'Without Remember Me no persistent token is issued.');
    }

    public function test_login_stays_csrf_protected_and_logout_rotates_the_csrf_token(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false);
        $this->assertContains('web', app('router')->getRoutes()->getByName('login.store')->gatherMiddleware(), 'The web group carries CSRF validation.');
        $this->assertContains('web', app('router')->getRoutes()->getByName('logout')->gatherMiddleware());

        $user = User::factory()->create(['username' => 'csrf.user', 'password' => self::PASSWORD]);
        $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD]);
        $tokenBefore = session()->token();

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($tokenBefore, session()->token(), 'Logout regenerates the CSRF token.');
    }

    public function test_password_minimum_is_eight_characters_not_exactly_eight(): void
    {
        foreach (['short77' => false, 'exactly8' => true, 'a much longer passphrase than eight characters' => true] as $candidate => $accepted) {
            $user = User::factory()->create(['password' => self::PASSWORD, 'must_change_password' => true]);
            // A fresh session per candidate: the previous user's auth_session_version must not carry over.
            $this->flushSession();

            $response = $this->actingAs($user)->put(route('password.change-required.update'), [
                'current_password' => self::PASSWORD,
                'password' => $candidate,
                'password_confirmation' => $candidate,
            ]);

            if ($accepted) {
                $response->assertSessionHasNoErrors();
                $this->assertTrue(Hash::check($candidate, $user->fresh()->password), strlen($candidate).' characters must be accepted.');
            } else {
                $response->assertSessionHasErrors('password');
                $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password), '7 characters must be rejected.');
            }
        }
    }
}
