<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Http;
use LogicException;

class BrevoTransactionalEmail
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /**
     * @return array<string, mixed>
     */
    public function sendPasswordReset(
        string $recipientEmail,
        string $recipientName,
        string $resetUrl,
        int $expirationMinutes,
    ): array {
        $apiKey = (string) config('brevo.api_key');
        $senderEmail = (string) config('brevo.sender_email');
        $senderName = (string) config('brevo.sender_name');

        if ($apiKey === '' || $senderEmail === '' || $senderName === '') {
            throw new LogicException('Brevo transactional email is not configured.');
        }

        if (parse_url($resetUrl, PHP_URL_SCHEME) !== 'https') {
            throw new LogicException('Password reset URLs sent by email must use HTTPS.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withHeaders(['api-key' => $apiKey])
            ->connectTimeout(min(5, max(1, (int) config('brevo.timeout_seconds', 10))))
            ->timeout(max(1, (int) config('brevo.timeout_seconds', 10)))
            ->post(self::ENDPOINT, [
                'sender' => [
                    'email' => $senderEmail,
                    'name' => $senderName,
                ],
                'to' => [[
                    'email' => $recipientEmail,
                    'name' => $recipientName,
                ]],
                'subject' => 'BRBI CIMS - Password Reset Request',
                'htmlContent' => view('emails.auth.reset-password', [
                    'recipientName' => $recipientName,
                    'resetUrl' => $resetUrl,
                    'expirationMinutes' => $expirationMinutes,
                ])->render(),
                'tags' => ['brbi-cims-password-reset'],
            ])
            ->throw();

        return $response->json() ?? [];
    }
}
