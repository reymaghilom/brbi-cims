<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a secure password';

    private const THROTTLED_60 = 'Too many sign-in attempts. Please try again in 60 seconds.';

    public function test_login_screen_is_private_entry_point_and_registration_is_absent(): void
    {
        $response = $this->get('/login')->assertOk()
            ->assertSee('Sign in')
            ->assertSee('Credit Investigation Management System')
            ->assertSee('Email')
            ->assertSee('Password')
            ->assertSee('Forgot password?')
            ->assertSee('Acceptable Use Policy')
            ->assertSee('Privacy Notice')
            ->assertSee('assets/branding/binhi-rural-bank-wordmark.png', false)
            ->assertSee('name="email"', false)
            ->assertDontSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password" type="password"', false)
            ->assertSee('type="button" class="ui-icon-button', false)
            ->assertSee('aria-label="Show password"', false)
            ->assertSee('aria-controls="password"', false)
            ->assertSee('data-password-visibility-toggle', false)
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertSee('data-modal-open="forgot-password-dialog"', false)
            ->assertSee('id="forgot-password-dialog"', false)
            ->assertSee('Reset password')
            ->assertSee("We'll email a link to the address on your account. Open it to set a new password.")
            ->assertSee('id="forgot-password-form"', false)
            ->assertSee('action="'.route('password.email').'"', false)
            ->assertSee('Cancel')
            ->assertSee('Send reset link')
            ->assertSee('href="'.route('policies.acceptable-use').'"', false)
            ->assertSee('href="'.route('policies.privacy').'"', false)
            ->assertSee('data-action-icon="close"', false)
            ->assertSee('data-action-icon="mail"', false)
            ->assertSee('type="button" data-modal-close', false)
            ->assertSee('type="submit" form="forgot-password-form"', false)
            ->assertSee('data-login-form', false)
            ->assertSee('data-submit-guard', false)
            ->assertSee('data-login-submit', false)
            ->assertSee('data-login-ready', false)
            ->assertSee('data-login-loading hidden', false)
            ->assertSee('data-login-spinner', false)
            ->assertSee('animate-spin', false)
            ->assertSee('data-action-icon="login"', false)
            ->assertSee('Sign in')
            ->assertSee('Signing in...')
            ->assertDontSee('aria-busy="true"', false)
            ->assertSee('text-slate-700', false)
            ->assertDontSee('<h1', false)
            ->assertDontSee('Use your office account to continue.');
        $this->get('/register')->assertNotFound();
    }

    public function test_login_loading_state_uses_the_existing_submit_guard_and_resets_after_bfcache_restore(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("window.addEventListener('submit', (event) => {", $script);
        $this->assertStringContainsString("form.matches('[data-submit-guard]')", $script);
        $this->assertStringContainsString("form.dataset.submitting === 'true'", $script);
        $this->assertStringContainsString('event.preventDefault();', $script);
        $this->assertStringContainsString('setLoginSubmitState(form, true);', $script);
        $this->assertStringContainsString("button.setAttribute('aria-busy', 'true');", $script);
        $this->assertStringContainsString('button.disabled = true;', $script);
        $this->assertStringContainsString("window.addEventListener('pageshow', (event) => {", $script);
        $this->assertStringContainsString('document.querySelectorAll(\'form[data-submit-guard]\').forEach(resetSubmitGuard)', $script);
    }

    public function test_active_user_can_log_in_with_normalized_email_and_session_is_regenerated(): void
    {
        $user = User::factory()->create(['email' => 'active.user@example.com', 'password' => self::PASSWORD]);

        $response = $this->withSession(['probe' => 'kept'])->post('/login', [
            'email' => ' Active.User@Example.COM ',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->auth_session_version, session('auth_session_version'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'active.user@example.com', 'password' => self::PASSWORD]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect password',
            'remember' => '1',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'authentication' => 'Invalid email or password. Please check your credentials and try again.',
        ]);

        $this->followingRedirects()->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect password',
            'remember' => '1',
        ])
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Invalid email or password. Please check your credentials and try again.')
            ->assertSee('value="active.user@example.com"', false)
            ->assertSee('name="remember" type="checkbox" value="1"', false)
            ->assertSee('checked', false)
            ->assertDontSee('name="password" type="password" value=', false);

        $this->assertGuest();
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled.user@example.com',
            'password' => self::PASSWORD,
            'status' => UserStatus::Disabled,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid email or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
    }

    public function test_five_failed_attempts_keep_the_generic_message_and_the_sixth_is_throttled(): void
    {
        $this->freezeTime();
        Event::fake([Lockout::class]);
        $user = User::factory()->create(['email' => 'many.attempts@example.com', 'password' => self::PASSWORD]);

        $this->failLogin($user->email, 5);
        Event::assertNotDispatched(Lockout::class);

        // Sixth attempt is refused before credentials are checked — even the correct password.
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['authentication' => self::THROTTLED_60]);
        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
        Event::assertDispatched(Lockout::class);

        $this->followingRedirects()->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'incorrect password'])
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee(self::THROTTLED_60)
            ->assertSee('value="many.attempts@example.com"', false)
            ->assertDontSee('name="password" type="password" value=', false);
    }

    public function test_throttle_message_states_the_remaining_wait_and_lifts_after_the_window(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email' => 'cooldown@example.com', 'password' => self::PASSWORD]);

        $this->failLogin($user->email, 5);

        $this->travel(45)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['authentication' => 'Too many sign-in attempts. Please try again in 15 seconds.']);
        $this->assertGuest();

        $this->travel(16)->seconds();
        $this->withSession(['probe' => 'kept'])
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD, 'remember' => '1'])
            ->assertRedirect(route('home'))
            ->assertSessionDoesntHaveErrors()
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_clears_the_failed_attempt_counter(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email' => 'reset.counter@example.com', 'password' => self::PASSWORD]);

        $this->failLogin($user->email, 4);
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        Auth::guard('web')->logout();

        // A full fresh allowance: five more failures all answer generically, only the sixth is paused.
        $this->failLogin($user->email, 5);
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect password'])
            ->assertSessionHasErrors(['authentication' => self::THROTTLED_60]);
    }

    public function test_the_limiter_is_scoped_to_the_exact_email_and_ip_pair(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['email' => 'scoped@example.com', 'password' => self::PASSWORD]);
        $other = User::factory()->create(['email' => 'other@example.com', 'password' => self::PASSWORD]);

        $this->failLogin($user->email, 5);

        // Normalization: a differently cased / padded spelling of the same email shares the counter.
        $this->post('/login', ['email' => ' SCOPED@Example.com ', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['authentication' => self::THROTTLED_60]);

        // Same IP, different email: unaffected.
        $this->post('/login', ['email' => $other->email, 'password' => self::PASSWORD])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($other);
        Auth::guard('web')->logout();

        // Same email, different IP: unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_disabled_and_unknown_accounts_are_throttled_identically_without_revealing_existence(): void
    {
        $this->freezeTime();
        $disabled = User::factory()->create([
            'email' => 'disabled.throttle@example.com',
            'password' => self::PASSWORD,
            'status' => UserStatus::Disabled,
        ]);

        // A disabled account's correct password is still just a generic failure, and counts.
        $this->failLogin($disabled->email, 5, self::PASSWORD);
        $this->post('/login', ['email' => $disabled->email, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['authentication' => self::THROTTLED_60]);

        // An email with no account behaves exactly the same way.
        $this->failLogin('nobody@example.com', 5);
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['authentication' => self::THROTTLED_60]);

        $this->assertGuest();
        $this->assertSame(UserStatus::Disabled, $disabled->fresh()->status);
    }

    public function test_blank_or_malformed_submissions_do_not_consume_attempts(): void
    {
        $user = User::factory()->create(['email' => 'validation.only@example.com', 'password' => self::PASSWORD]);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors('password');
        }

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    private function failLogin(string $email, int $times, string $password = 'incorrect password'): void
    {
        for ($attempt = 0; $attempt < $times; $attempt++) {
            $this->post('/login', ['email' => $email, 'password' => $password])
                ->assertSessionHasErrors([
                    'authentication' => 'Invalid email or password. Please check your credentials and try again.',
                ]);
            $this->assertGuest();
        }
    }

    public function test_blank_login_fields_use_concise_field_level_validation(): void
    {
        $this->from('/login')->post('/login', [
            'email' => '',
            'password' => '',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'email' => 'Email is required.',
            'password' => 'Password is required.',
        ]);

        $this->followingRedirects()->from('/login')->post('/login', [
            'email' => '',
            'password' => '',
        ])
            ->assertOk()
            ->assertSee('Email is required.')
            ->assertSee('Password is required.')
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('name="password" type="password" value=', false);
    }

    public function test_login_rejects_an_invalid_email_format(): void
    {
        $this->post('/login', [
            'email' => 'not-an-email',
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_remember_me_issues_a_recaller_cookie(): void
    {
        $user = User::factory()->create(['email' => 'remember.user@example.com', 'password' => self::PASSWORD]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'remember' => '1',
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create(['email' => 'logout.user@example.com', 'password' => self::PASSWORD, 'remember_token' => null]);

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD, 'remember' => '1']);
        $rememberToken = $user->fresh()->remember_token;

        $this->withSession(['sensitive_probe' => 'present'])
            ->post('/logout')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('sensitive_probe');

        $this->assertGuest();
        $this->assertNotSame($rememberToken, $user->fresh()->remember_token);
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect(route('home'));
    }

    public function test_authenticated_dashboard_responses_prevent_cache_and_bfcache_reuse(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $response = $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
            ->assertSee('data-authenticated-page', false);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0', 'private'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("window.addEventListener('pageshow'", $script);
        $this->assertStringContainsString('if (event.persisted) window.location.reload();', $script);
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

    public function test_login_with_a_completely_nonexistent_email_creates_zero_users(): void
    {
        $userCount = User::count();

        $this->post('/login', [
            'email' => 'this-email-does-not-exist@example.com',
            'password' => 'whatever-arbitrary-password',
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid email or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
        $this->assertSame($userCount, User::count(), 'An unknown email must never cause a new User row to be created.');
    }

    public function test_login_with_wrong_password_creates_zero_users_and_leaves_the_existing_user_intact(): void
    {
        $user = User::factory()->create(['email' => 'invariant.wrongpass@example.com', 'password' => self::PASSWORD]);
        $userCount = User::count();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'definitely the wrong password',
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid email or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
        $this->assertSame($userCount, User::count(), 'A wrong password must never create a replacement/default/fake User.');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'username' => $user->username]);
    }

    public function test_login_with_an_inactive_user_creates_zero_users(): void
    {
        $user = User::factory()->create([
            'email' => 'invariant.disabled@example.com',
            'password' => self::PASSWORD,
            'status' => UserStatus::Disabled,
        ]);
        $userCount = User::count();

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors([
            'authentication' => 'Invalid email or password. Please check your credentials and try again.',
        ]);

        $this->assertGuest();
        $this->assertSame($userCount, User::count(), 'An inactive/disabled account being denied login must never create a new User.');
    }

    public function test_login_uses_email_only_and_the_session_identity_is_the_users_id(): void
    {
        $user = User::factory()->create(['username' => 'identity.user', 'email' => 'identity.user@example.com', 'password' => self::PASSWORD]);
        User::factory()->create(['email' => 'other.user@example.com', 'password' => self::PASSWORD]);

        $this->get('/login')->assertOk()
            ->assertSee('name="email"', false)
            ->assertDontSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false);

        $this->post('/login', ['username' => 'identity.user', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect(route('home'));

        // The authenticated session resolves exactly this users.id, and the Dashboard works as that user.
        $this->assertAuthenticatedAs($user);
        $this->assertSame($user->id, Auth::id());
        $this->get(route('home'))->assertOk();
        $this->assertTrue(Auth::user()->is($user));
    }

    public function test_unknown_email_and_wrong_password_share_one_generic_message(): void
    {
        $user = User::factory()->create(['email' => 'known.user@example.com', 'password' => self::PASSWORD]);
        $generic = ['authentication' => 'Invalid email or password. Please check your credentials and try again.'];

        $this->post('/login', ['email' => $user->email, 'password' => 'not the password'])->assertSessionHasErrors($generic);
        $this->assertGuest();
        $this->post('/login', ['email' => 'nobody.here@example.com', 'password' => self::PASSWORD])->assertSessionHasErrors($generic);
        $this->assertGuest();
    }

    public function test_legacy_user_without_email_cannot_use_username_login(): void
    {
        $user = User::factory()->create(['username' => 'legacy.user', 'email' => null, 'password' => self::PASSWORD]);

        $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_remember_me_checked_persists_a_remember_token_and_unchecked_uses_the_session_only(): void
    {
        $remembered = User::factory()->create(['email' => 'remembered.user@example.com', 'password' => self::PASSWORD, 'remember_token' => null]);
        $rememberedResponse = $this->post('/login', ['email' => $remembered->email, 'password' => self::PASSWORD, 'remember' => '1'])
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($remembered->fresh()->remember_token, 'Remember Me stores a persistent token.');
        $recallerValue = $rememberedResponse->getCookie(Auth::guard('web')->getRecallerName())->getValue();
        $this->assertStringNotContainsString(self::PASSWORD, $recallerValue);
        $this->assertStringNotContainsString($remembered->email, $recallerValue);
        $this->post('/logout');

        $sessionOnly = User::factory()->create(['email' => 'session.user@example.com', 'password' => self::PASSWORD, 'remember_token' => null]);
        $this->post('/login', ['email' => $sessionOnly->email, 'password' => self::PASSWORD])
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($sessionOnly);
        $this->assertNull($sessionOnly->fresh()->remember_token, 'Without Remember Me no persistent token is issued.');
    }

    public function test_login_stays_csrf_protected_and_logout_rotates_the_csrf_token(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false);
        $this->assertContains('web', app('router')->getRoutes()->getByName('login.store')->gatherMiddleware(), 'The web group carries CSRF validation.');
        $this->assertContains('web', app('router')->getRoutes()->getByName('logout')->gatherMiddleware());

        $user = User::factory()->create(['email' => 'csrf.user@example.com', 'password' => self::PASSWORD]);
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
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
