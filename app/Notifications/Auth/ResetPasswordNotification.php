<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
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
