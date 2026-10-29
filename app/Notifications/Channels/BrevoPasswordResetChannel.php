<?php

namespace App\Notifications\Channels;

use App\Notifications\Auth\ResetPasswordNotification;
use App\Services\Email\BrevoTransactionalEmail;
use Illuminate\Notifications\Notification;

class BrevoPasswordResetChannel
{
    public function __construct(private readonly BrevoTransactionalEmail $brevo) {}

    /**
     * @return array<string, mixed>|null
     */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! $notification instanceof ResetPasswordNotification) {
            return null;
        }

        $email = (string) $notifiable->routeNotificationFor('mail', $notification);

        if ($email === '') {
            return null;
        }

        return $this->brevo->sendPasswordReset(
            recipientEmail: $email,
            recipientName: (string) ($notifiable->full_name ?? ''),
            resetUrl: $notification->resetUrlFor($notifiable),
            expirationMinutes: (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        );
    }
}
