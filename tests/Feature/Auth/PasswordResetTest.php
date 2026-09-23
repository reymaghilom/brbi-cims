<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'the old secure password';

    private const NEW_PASSWORD = 'the new secure password';

    public function test_forgot_password_page_renders_in_the_binhi_auth_layout(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('assets/branding/binhi-rural-bank-wordmark.png', false)
            ->assertSee('Credit Investigation Management System')
            ->assertSee('Reset password')
            ->assertSee('Enter your email address.')
            ->assertSee('Email Address')
            ->assertDontSee('Enter your account email and we will send instructions for resetting your password.')
            ->assertSee('name="email"', false)
            ->assertSee('data-action-icon="mail"', false)
            ->assertSee('type="submit" class="ui-button-primary w-full"', false)
            ->assertSee(route('password.email'), false);
    }

    public function test_registered_email_receives_reset_link_and_unknown_email_gets_the_same_response(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset.user@example.com']);

        $knownResponse = $this->post(route('password.email'), [
            'email' => ' Reset.User@Example.COM ',
        ])->assertRedirect();
        $knownStatus = $knownResponse->getSession()->get('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $unknownResponse = $this->post(route('password.email'), [
            'email' => 'unknown.user@example.com',
        ])->assertRedirect();

        $this->assertSame($knownStatus, $unknownResponse->getSession()->get('status'));
        $this->assertSame("If the email is registered, we'll send password reset instructions.", $knownStatus);
        Notification::assertNotSentTo(new User(['email' => 'unknown.user@example.com']), ResetPasswordNotification::class);
    }

    public function test_reset_email_is_branded_and_retains_the_secure_laravel_reset_url(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset.user@example.com']);

        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($user): bool {
                $message = $notification->toMail($user);

                $this->assertSame('BRBI CIMS - Password Reset Request', $message->subject);
                $this->assertSame('Reset Password', $message->actionText);
                $this->assertSame('BRBI CIMS', config('mail.from.name'));
                $this->assertContains('BRBI CIMS', $message->introLines);
                $this->assertContains('Credit Investigation Management System', $message->introLines);
                $this->assertContains(
                    'We received a request to reset the password for your BRBI CIMS account.',
                    $message->introLines
                );
                $this->assertContains(
                    'If you did not request a password reset, you can safely ignore this email.',
                    $message->outroLines
                );
                $this->assertSame(
                    route('password.reset', [
                        'token' => $notification->token,
                        'email' => $user->email,
                    ]),
                    $message->actionUrl
                );

                return true;
            }
        );
    }

    public function test_successful_modal_request_returns_to_login_with_compact_feedback_and_closed_modal(): void
    {
        $this->followingRedirects()->from(route('login'))->post(route('password.email'), [
            'email' => 'unknown.user@example.com',
        ])
            ->assertOk()
            ->assertSee("If the email is registered, we'll send password reset instructions.")
            ->assertSee('role="status" aria-live="polite"', false)
            ->assertSee('data-open-on-error="false"', false)
            ->assertSee('data-modal-open="forgot-password-dialog"', false);
    }

    public function test_invalid_modal_request_returns_to_login_and_reopens_with_field_feedback(): void
    {
        $this->from(route('login'))->post(route('password.email'), [
            'email' => 'not-an-email',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email', null, 'passwordReset');

        $this->followingRedirects()->from(route('login'))->post(route('password.email'), [
            'email' => 'not-an-email',
        ])
            ->assertOk()
            ->assertSee('data-open-on-error="true"', false)
            ->assertSee('The email field must be a valid email address.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_valid_reset_token_opens_the_reset_form(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('Reset password')
            ->assertSee('Email Address')
            ->assertSee('New Password')
            ->assertSee('Confirm Password')
            ->assertDontSee('Choose a new password for your account.')
            ->assertSee('data-action-icon="key"', false)
            ->assertSee('type="submit" class="ui-button-primary w-full"', false)
            ->assertSee('name="token" value="'.$token.'"', false)
            ->assertSee('value="'.$user->email.'"', false)
            ->assertSee('readonly="readonly"', false);
    }

    public function test_valid_token_resets_password_invalidates_token_and_allows_email_login(): void
    {
        $user = User::factory()->create([
            'email' => 'password.user@example.com',
            'password' => self::OLD_PASSWORD,
            'must_change_password' => true,
        ]);
        $previousSessionVersion = $user->auth_session_version;
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => ' Password.User@Example.COM ',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame($previousSessionVersion + 1, $user->auth_session_version);
        $this->assertFalse(Password::tokenExists($user, $token));

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another secure password',
            'password_confirmation' => 'another secure password',
        ])->assertSessionHasErrors('email');

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ])->assertSessionHasErrors('authentication');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_requires_password_confirmation_and_rejects_invalid_token(): void
    {
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $validToken = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $validToken,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('password');

        $this->post(route('password.update'), [
            'token' => 'invalid-reset-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }

    public function test_expired_token_cannot_reset_password(): void
    {
        $user = User::factory()->create(['password' => self::OLD_PASSWORD]);
        $token = Password::createToken($user);
        $this->travel(61)->minutes();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }
}
