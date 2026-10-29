<?php

namespace App\Services\Integrations\N8n;

use App\Enums\ActivityStatus;
use App\Enums\UserStatus;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReminderDeliveryAuthorizer
{
    public const STATUS_CLAIMED = 'claimed';

    /**
     * Validate current MariaDB state and atomically acquire the one permitted pre-send claim.
     *
     * @param  array{event_id: string, subject_type: string, subject_id: int, ci_activity_id: int, schedule_version: int, occurrence_at: string}  $request
     * @return array{claim_id: string, occurrence_key: string, recipient: array{user_id: int, email: string}}
     */
    public function claim(array $request): array
    {
        try {
            return DB::transaction(function () use ($request): array {
                $state = DB::table('reminder_schedule_states')
                    ->where('subject_type', $request['subject_type'])
                    ->where('subject_id', $request['subject_id'])
                    ->lockForUpdate()
                    ->first();

                $this->require($state !== null, 'unknown_subject', 404);
                $this->require((int) $state->ci_activity_id === $request['ci_activity_id'], 'activity_mismatch', 409);
                $this->require((bool) $state->is_active, 'schedule_cancelled', 409);
                $this->require((int) $state->version === $request['schedule_version'], 'outdated_schedule_version', 409);

                [$subject, $activity] = $this->loadCurrentSubject($request['subject_type'], $request['subject_id']);
                $this->require($subject !== null && $activity !== null, 'unknown_subject', 404);
                $this->require((int) $activity->getKey() === $request['ci_activity_id'], 'activity_mismatch', 409);
                $this->require(
                    $subject->status !== ActivityStatus::Completed
                        && $activity->status !== ActivityStatus::Completed
                        && $activity->completed_at === null,
                    'activity_completed',
                    409,
                );
                $this->require(
                    $subject->status === ActivityStatus::Scheduled && $subject->scheduled_at !== null,
                    'schedule_cancelled',
                    409,
                );

                $recipient = $activity->creator_id !== null
                    ? User::query()->find($activity->creator_id)
                    : null;
                $this->require($this->validRecipient($recipient), 'invalid_recipient', 409);
                $this->require((int) $state->recipient_user_id === (int) $recipient->getKey(), 'invalid_recipient', 409);

                $occurrenceAt = $this->triggerAt($subject->scheduled_at, (bool) $subject->scheduled_has_time);
                $requestedOccurrence = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $request['occurrence_at'], 'UTC');
                $this->require($requestedOccurrence !== false && $requestedOccurrence->equalTo($occurrenceAt), 'occurrence_mismatch', 409);
                $this->require(now('UTC')->greaterThanOrEqualTo($occurrenceAt), 'reminder_not_due', 409);

                $event = DB::table('reminder_outbox_events')->where('event_uuid', $request['event_id'])->first();
                $this->require($event !== null, 'unknown_event', 404);
                $this->require(
                    $event->subject_type === $request['subject_type']
                        && (int) $event->subject_id === $request['subject_id']
                        && (int) $event->version === $request['schedule_version']
                        && in_array($event->event_type, [ReminderScheduleOutbox::EVENT_CREATED, ReminderScheduleOutbox::EVENT_CHANGED], true),
                    'conflicting_event_identity',
                    409,
                );

                $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
                $this->require(
                    ($payload['reminder']['ci_activity_id'] ?? null) === $request['ci_activity_id']
                        && ($payload['reminder']['trigger_at'] ?? null) === $occurrenceAt->toIso8601ZuluString(),
                    'conflicting_event_identity',
                    409,
                );

                $occurrenceKey = hash('sha256', implode('|', [
                    'brbi-cims-reminder-v1',
                    $request['subject_type'],
                    (string) $request['subject_id'],
                    (string) $request['schedule_version'],
                    $occurrenceAt->toIso8601ZuluString(),
                ]));
                $claimId = (string) Str::uuid();

                DB::table('reminder_delivery_claims')->insert([
                    'claim_uuid' => $claimId,
                    'event_uuid' => $request['event_id'],
                    'occurrence_key' => $occurrenceKey,
                    'subject_type' => $request['subject_type'],
                    'subject_id' => $request['subject_id'],
                    'ci_activity_id' => $request['ci_activity_id'],
                    'schedule_version' => $request['schedule_version'],
                    'occurrence_at' => $occurrenceAt->format('Y-m-d H:i:s'),
                    'recipient_user_id' => $recipient->getKey(),
                    'recipient_email_hash' => hash('sha256', Str::lower(trim($recipient->email))),
                    'status' => self::STATUS_CLAIMED,
                    'claimed_at' => now('UTC'),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);

                return [
                    'claim_id' => $claimId,
                    'occurrence_key' => $occurrenceKey,
                    'recipient' => ['user_id' => (int) $recipient->getKey(), 'email' => $recipient->email],
                ];
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new ReminderAuthorizationException('duplicate_delivery_claim', 409);
            }

            throw $exception;
        }
    }

    /** @return array{0: CiActivity|CiActivityBankTarget|CiActivityAssetTarget|null, 1: CiActivity|null} */
    private function loadCurrentSubject(string $type, int $id): array
    {
        $subject = match ($type) {
            ReminderScheduleOutbox::SUBJECT_ACTIVITY => CiActivity::query()->find($id),
            ReminderScheduleOutbox::SUBJECT_BANK_TARGET => CiActivityBankTarget::query()->find($id),
            ReminderScheduleOutbox::SUBJECT_ASSET_TARGET => CiActivityAssetTarget::query()->find($id),
        };
        $activity = $subject instanceof CiActivity
            ? $subject
            : ($subject !== null ? CiActivity::query()->find($subject->ci_activity_id) : null);

        return [$subject, $activity];
    }

    private function triggerAt(Carbon $scheduledAt, bool $hasTime): Carbon
    {
        if ($hasTime) {
            return $scheduledAt->copy()->utc()->startOfSecond();
        }

        $timezone = config('cims.display_timezone');

        return Carbon::createFromFormat(
            '!Y-m-d H:i',
            $scheduledAt->copy()->timezone($timezone)->format('Y-m-d').' '.ReminderScheduleOutbox::DATE_ONLY_TRIGGER_TIME,
            $timezone,
        )->utc();
    }

    private function validRecipient(?User $recipient): bool
    {
        return $recipient !== null
            && $recipient->status === UserStatus::Active
            && filled($recipient->email)
            && filter_var($recipient->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function require(bool $condition, string $reason, int $status): void
    {
        if (! $condition) {
            throw new ReminderAuthorizationException($reason, $status);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062], true);
    }
}
