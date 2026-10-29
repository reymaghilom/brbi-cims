<?php

namespace App\Services\Integrations\N8n;

/**
 * HMAC-SHA256 request signing for the n8n reminder webhook.
 *
 * Signed string: "{unix timestamp}.{exact raw JSON body}". The receiver must recompute it over the
 * RAW body bytes (not re-serialized JSON), compare in constant time, and reject a timestamp outside
 * the tolerance window. The shared secret is dedicated to this one webhook and never sent.
 */
final class ReminderWebhookSignature
{
    public const HEADER_SIGNATURE = 'X-CIMS-Signature';

    public const HEADER_TIMESTAMP = 'X-CIMS-Timestamp';

    public const HEADER_EVENT_ID = 'X-CIMS-Event-Id';

    public const HEADER_IDEMPOTENCY_KEY = 'Idempotency-Key';

    public const DEFAULT_TOLERANCE_SECONDS = 300;

    public static function sign(#[\SensitiveParameter] string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function verify(
        #[\SensitiveParameter] string $secret,
        string $signature,
        int $timestamp,
        string $body,
        int $now,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        return abs($now - $timestamp) <= $toleranceSeconds
            && hash_equals(self::sign($secret, $timestamp, $body), $signature);
    }
}
