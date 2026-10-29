<?php

namespace App\Services\Integrations\N8n;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers recorded reminder outbox events to the n8n webhook — at least once, never concurrently
 * for the same row, and never an event a newer version of the same subject has superseded.
 *
 * Delivery is AT-LEAST-ONCE: a lost acknowledgement is retried, so the receiver must dedupe on
 * the Idempotency-Key and must ignore any version not newer than the one it already holds. Laravel
 * cannot guarantee delivery by itself on hosting without a background worker — something must
 * call deliverPending() (the n8n-reminders:deliver command, or the opt-in after-commit attempt).
 */
class ReminderOutboxDispatcher
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_FAILED = 'failed';

    private const CLAIM_TIMEOUT_MINUTES = 5;

    private const MAX_BACKOFF_MINUTES = 360;

    private const MIN_SECRET_LENGTH = 32;

    /** Null when delivery may run; otherwise why it must not (never includes the secret). */
    public function configurationError(): ?string
    {
        $config = config('services.n8n_reminders');

        if (! ($config['enabled'] ?? false)) {
            return 'The n8n reminder integration is disabled (N8N_REMINDERS_ENABLED).';
        }

        $url = (string) ($config['webhook_url'] ?? '');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! Str::startsWith(Str::lower($url), 'https://')) {
            return 'N8N_REMINDERS_WEBHOOK_URL must be an absolute https:// URL.';
        }

        if (strlen((string) ($config['signing_secret'] ?? '')) < self::MIN_SECRET_LENGTH) {
            return 'N8N_REMINDERS_SIGNING_SECRET must be at least '.self::MIN_SECRET_LENGTH.' characters.';
        }

        return null;
    }

    /**
     * @param  list<int>|null  $onlyIds
     * @return array{attempted: int, delivered: int, superseded: int, retrying: int, failed: int}
     */
    public function deliverPending(int $limit = 50, ?array $onlyIds = null): array
    {
        $result = ['attempted' => 0, 'delivered' => 0, 'superseded' => 0, 'retrying' => 0, 'failed' => 0];

        if ($this->configurationError() !== null) {
            return $result;
        }

        $seen = [];
        while (count($seen) < max(0, $limit)) {
            $event = $this->claim($seen, $onlyIds);
            if ($event === null) {
                break;
            }
            $seen[] = (int) $event->id;

            if ($this->isSuperseded($event)) {
                $this->finish($event, ['status' => self::STATUS_SUPERSEDED]);
                $result['superseded']++;

                continue;
            }

            $result['attempted']++;
            $outcome = $this->send($event);
            $result[$outcome]++;
        }

        return $result;
    }

    /** Puts permanently failed events back in the queue (manual operator action only). */
    public function requeueFailed(): int
    {
        return DB::table('reminder_outbox_events')->where('status', self::STATUS_FAILED)->update([
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }

    /** @return 'delivered'|'retrying'|'failed' */
    private function send(object $event): string
    {
        $config = config('services.n8n_reminders');
        $body = (string) $event->payload;
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(max(1, (int) $config['timeout_seconds']))
                ->connectTimeout(max(1, (int) $config['timeout_seconds']))
                // Never follow a redirect: the signed request must reach only the configured host.
                ->withoutRedirecting()
                ->withHeaders([
                    ReminderWebhookSignature::HEADER_SIGNATURE => ReminderWebhookSignature::sign((string) $config['signing_secret'], $timestamp, $body),
                    ReminderWebhookSignature::HEADER_TIMESTAMP => (string) $timestamp,
                    ReminderWebhookSignature::HEADER_EVENT_ID => (string) $event->event_uuid,
                    ReminderWebhookSignature::HEADER_IDEMPOTENCY_KEY => (string) $event->idempotency_key,
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post((string) $config['webhook_url']);
        } catch (Throwable $exception) {
            return $this->failAttempt($event, null, 'Delivery failed ('.class_basename($exception).').');
        }

        if ($response->successful()) {
            $this->finish($event, [
                'status' => self::STATUS_DELIVERED,
                'delivered_at' => now(),
                'last_response_status' => $response->status(),
                'last_error' => null,
            ]);

            return 'delivered';
        }

        // The response body is never stored: only the status code.
        return $this->failAttempt($event, $response->status(), 'Webhook responded with HTTP '.$response->status().'.');
    }

    /** @return 'retrying'|'failed' */
    private function failAttempt(object $event, ?int $status, string $error): string
    {
        $attempts = (int) $event->attempts;
        $exhausted = $attempts >= max(1, (int) config('services.n8n_reminders.max_attempts'));

        $this->finish($event, [
            'status' => $exhausted ? self::STATUS_FAILED : self::STATUS_PENDING,
            'next_attempt_at' => $exhausted ? null : now()->addMinutes(min(self::MAX_BACKOFF_MINUTES, 2 ** max(0, $attempts - 1))),
            'last_response_status' => $status,
            'last_error' => $error,
        ]);

        // Identifiers only: never the payload (recipient email), the webhook URL or the secret.
        Log::warning('n8n reminder event delivery failed.', [
            'event_id' => $event->event_uuid,
            'idempotency_key' => $event->idempotency_key,
            'attempt' => $attempts,
            'response_status' => $status,
            'error' => $error,
            'outcome' => $exhausted ? self::STATUS_FAILED : 'retrying',
        ]);

        return $exhausted ? 'failed' : 'retrying';
    }

    /** A newer version exists for the same subject, so this one must never reach n8n. */
    private function isSuperseded(object $event): bool
    {
        return DB::table('reminder_outbox_events')
            ->where('subject_type', $event->subject_type)
            ->where('subject_id', $event->subject_id)
            ->where('version', '>', $event->version)
            ->exists();
    }

    /** Releases the claim; scoped to this claim token so a stale worker can never overwrite. */
    private function finish(object $event, array $changes): void
    {
        DB::table('reminder_outbox_events')
            ->where('id', $event->id)
            ->where('claim_token', $event->claim_token)
            ->update($changes + ['claim_token' => null, 'claimed_at' => null, 'updated_at' => now()]);
    }

    /**
     * Claims the oldest deliverable event, at most ONE in flight per subject.
     *
     * The subject's schedule-state row is locked first and the event row second (by primary key),
     * the same order ReminderScheduleOutbox uses when it records a new version, so a user's save
     * and a delivery worker can never deadlock on each other. While another worker holds a live
     * claim on any event of the same subject, this subject is skipped: a newer version is not
     * sent until the older in-flight request has finished. That narrows, but cannot eliminate,
     * out-of-order arrival — a request whose response was lost may still be running in n8n when
     * its claim expires — so the receiver must still reject non-newer versions.
     *
     * @param  list<int>  $seen
     * @param  list<int>|null  $onlyIds
     */
    private function claim(array &$seen, ?array $onlyIds): ?object
    {
        while (true) {
            $candidate = DB::table('reminder_outbox_events')
                ->where('status', self::STATUS_PENDING)
                ->where(fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($free) => $free->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)))
                ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
                ->when($onlyIds !== null, fn ($query) => $query->whereIn('id', $onlyIds))
                ->orderBy('id')
                ->first(['id', 'subject_type', 'subject_id']);
            if ($candidate === null) {
                return null;
            }

            $event = $this->claimCandidate($candidate);
            if ($event !== null) {
                return $event;
            }

            // Taken by another worker, no longer due, or its subject has a request in flight.
            $seen[] = (int) $candidate->id;
        }
    }

    private function claimCandidate(object $candidate): ?object
    {
        return DB::transaction(function () use ($candidate): ?object {
            DB::table('reminder_schedule_states')
                ->where('subject_type', $candidate->subject_type)
                ->where('subject_id', $candidate->subject_id)
                ->lockForUpdate()
                ->first(['id']);

            $staleBefore = now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES);
            $event = DB::table('reminder_outbox_events')
                ->where('id', $candidate->id)
                ->lockForUpdate()
                ->first();
            if ($event === null
                || $event->status !== self::STATUS_PENDING
                || ($event->next_attempt_at !== null && Carbon::parse($event->next_attempt_at)->isFuture())
                || ($event->claimed_at !== null && Carbon::parse($event->claimed_at)->greaterThan($staleBefore))) {
                return null;
            }

            $siblingInFlight = DB::table('reminder_outbox_events')
                ->where('subject_type', $event->subject_type)
                ->where('subject_id', $event->subject_id)
                ->where('id', '!=', $event->id)
                ->whereNotNull('claim_token')
                ->where('claimed_at', '>', $staleBefore)
                ->exists();
            if ($siblingInFlight) {
                return null;
            }

            $claimToken = (string) Str::uuid();
            DB::table('reminder_outbox_events')->where('id', $event->id)->update([
                'attempts' => DB::raw('attempts + 1'),
                'claim_token' => $claimToken,
                'claimed_at' => now(),
                'last_attempted_at' => now(),
                'updated_at' => now(),
            ]);
            $event->claim_token = $claimToken;
            $event->attempts = (int) $event->attempts + 1;

            return $event;
        });
    }
}
