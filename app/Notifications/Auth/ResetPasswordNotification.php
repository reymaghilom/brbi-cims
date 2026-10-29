<?php

namespace App\Notifications\Auth;

use App\Notifications\Channels\BrevoPasswordResetChannel;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    /**
     * Password resets use Brevo's HTTPS API without changing the application's default mailer.
     *
     * @return array<int, class-string>
     */
    public function via($notifiable): array
    {
        return [BrevoPasswordResetChannel::class];
    }

    public function resetUrlFor($notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        return rtrim((string) config('app.url'), '/').route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);
    }

    /**
     * Build the branded password reset email while retaining Laravel's
     * native reset URL and token handling.
     */
    protected function buildMailMessage($url): MailMessage
    {
        $expiration = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('BRBI CIMS - Password Reset Request')
            ->line('BRBI CIMS')
            ->line('Credit Investigation Management System')
            ->line('We received a request to reset the password for your BRBI CIMS account.')
            ->line('Use the button below to set a new password.')
            ->action('Reset Password', $url)
            ->line('If you did not request a password reset, you can safely ignore this email.')
            ->line("For security, this reset link will expire in {$expiration} minutes.")
            ->salutation('Regards, BRBI CIMS');
    }
}
