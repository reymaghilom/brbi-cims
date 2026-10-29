<?php

namespace App\Services\Integrations\N8n;

use App\Enums\ActivityStatus;
use App\Enums\UserStatus;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records CI Activity email-reminder schedule events in a transactional outbox for n8n.
 *
 * Laravel stays the source of truth. Every call compares the subject's CURRENT row against the
 * last state recorded for that exact subject (parent CI Activity, one Bank/Coop target or one
 * Asset target — never inferred by position or type) and writes an event only when the
 * email-relevant state actually changed:
 *
 *  - schedule.created    the subject became remindable (Scheduled, has a schedule, creator reachable)
 *  - schedule.changed    still remindable, but the trigger time or recipient changed
 *  - schedule.cancelled  it stopped being remindable (completed, unscheduled, deleted, or the
 *                        creator can no longer be emailed)
 *
 * Each event carries a per-subject monotonic version. The state row and the event row are written
 * in the SAME database transaction as the schedule change that caused them, so a rolled-back save
 * leaves no event, and a committed save always leaves its event behind for delivery.
 *
 * The recipient is always the activity's creator (creator_id), exactly like the database
 * notifications. assigned_ci_id is never consulted.
 */
class ReminderScheduleOutbox
{
    public const SCHEMA = 'brbi.cims.reminder.v1';

    public const SUBJECT_ACTIVITY = 'ci_activity';

    public const SUBJECT_BANK_TARGET = 'bank_target';

    public const SUBJECT_ASSET_TARGET = 'asset_target';

    public const EVENT_CREATED = 'schedule.created';

    public const EVENT_CHANGED = 'schedule.changed';

    public const EVENT_CANCELLED = 'schedule.cancelled';

    public const REASON_COMPLETED = 'completed';

    public const REASON_UNSCHEDULED = 'unscheduled';

    public const REASON_DELETED = 'deleted';

    public const REASON_RECIPIENT_UNAVAILABLE = 'recipient_unavailable';

    /** Date-only schedules are emailed at this local time, matching the in-app due reminder. */
    public const DATE_ONLY_TRIGGER_TIME = '08:00';

    public function __construct(private readonly ReminderOutboxDispatcher $dispatcher) {}

    public static function enabled(): bool
    {
        return (bool) config('services.n8n_reminders.enabled');
    }

    public function syncActivity(CiActivity $activity): void
    {
        $this->resyncSubject(self::SUBJECT_ACTIVITY, (int) $activity->getKey());
    }

    public function syncBankTarget(CiActivityBankTarget $target): void
    {
        $this->resyncSubject(self::SUBJECT_BANK_TARGET, (int) $target->getKey());
    }

    public function syncAssetTarget(CiActivityAssetTarget $target): void
    {
        $this->resyncSubject(self::SUBJECT_ASSET_TARGET, (int) $target->getKey());
    }

    /**
     * Re-evaluates one exact subject from the database. A subject whose row (or, for a target,
     * whose parent activity) no longer exists is treated as deleted.
     */
    public function resyncSubject(string $subjectType, int $subjectId): void
    {
        $model = match ($subjectType) {
            self::SUBJECT_ACTIVITY => CiActivity::query()->with('definition')->find($subjectId),
            self::SUBJECT_BANK_TARGET => CiActivityBankTarget::query()->find($subjectId),
            self::SUBJECT_ASSET_TARGET => CiActivityAssetTarget::query()->find($subjectId),
        };

        $this->syncSubject($subjectType, $subjectId, $model);
    }

    /** Every recorded Bank/Asset target of one parent, e.g. after the parent was deleted or restored. */
    public function resyncChildrenOf(int $ciActivityId): void
    {
        DB::table('reminder_schedule_states')
            ->where('ci_activity_id', $ciActivityId)
            ->whereIn('subject_type', [self::SUBJECT_BANK_TARGET, self::SUBJECT_ASSET_TARGET])
            ->orderBy('id')
            ->get(['subject_type', 'subject_id'])
            ->each(fn (object $state) => $this->resyncSubject($state->subject_type, (int) $state->subject_id));
    }

    /**
     * Cancels every recorded-active subject whose row was removed without an Eloquent event — a
     * folder purge or Co-Maker removal (database cascades) or the operational data reset.
     */
    public function reconcileMissing(?int $clientFolderId = null): int
    {
        $cancelled = 0;

        DB::table('reminder_schedule_states')
            ->where('is_active', true)
            ->when($clientFolderId !== null, fn ($query) => $query->where('client_folder_id', $clientFolderId))
            ->orderBy('id')
            ->get(['subject_type', 'subject_id'])
            ->each(function (object $state) use (&$cancelled): void {
                $before = $this->stateVersion($state->subject_type, (int) $state->subject_id);
                $this->resyncSubject($state->subject_type, (int) $state->subject_id);
                $cancelled += (int) ($this->stateVersion($state->subject_type, (int) $state->subject_id) !== $before);
            });

        return $cancelled;
    }

    /**
     * Full re-evaluation used to recover from drift (e.g. the integration was disabled while
     * schedules changed): every recorded-active subject plus every currently scheduled row.
     */
    public function reconcileAll(): void
    {
        $this->reconcileMissing();

        CiActivity::query()->where('status', ActivityStatus::Scheduled)->whereNotNull('scheduled_at')->orderBy('id')
            ->eachById(fn (CiActivity $activity) => $this->resyncSubject(self::SUBJECT_ACTIVITY, $activity->id));
        CiActivityBankTarget::query()->where('status', ActivityStatus::Scheduled)->whereNotNull('scheduled_at')->orderBy('id')
            ->eachById(fn (CiActivityBankTarget $target) => $this->resyncSubject(self::SUBJECT_BANK_TARGET, $target->id));
        CiActivityAssetTarget::query()->where('status', ActivityStatus::Scheduled)->whereNotNull('scheduled_at')->orderBy('id')
            ->eachById(fn (CiActivityAssetTarget $target) => $this->resyncSubject(self::SUBJECT_ASSET_TARGET, $target->id));
    }

    private function syncSubject(string $subjectType, int $subjectId, CiActivity|CiActivityBankTarget|CiActivityAssetTarget|null $model): void
    {
        if ($subjectId === 0) {
            return;
        }

        $parent = match (true) {
            $model instanceof CiActivity => $model,
            $model !== null => CiActivity::query()->find($model->ci_activity_id),
            default => null,
        };

        [$desired, $cancelReason] = $this->desiredState($subjectType, $model, $parent);

        DB::transaction(function () use ($subjectType, $subjectId, $parent, $desired, $cancelReason): void {
            $state = $this->lockState($subjectType, $subjectId);

            if ($state === null) {
                if ($desired === null) {
                    // Never remindable, so there is nothing to create and nothing to cancel.
                    return;
                }

                DB::table('reminder_schedule_states')->insert([
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'ci_activity_id' => $parent->id,
                    'client_folder_id' => $parent->client_folder_id,
                    'version' => 0,
                    'is_active' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $state = $this->lockState($subjectType, $subjectId);
            }

            if ($desired === null && ! $state->is_active) {
                return;
            }

            if ($desired !== null && $state->is_active && $this->sameState($state, $desired)) {
                return;
            }

            $eventType = match (true) {
                $desired === null => self::EVENT_CANCELLED,
                (bool) $state->is_active => self::EVENT_CHANGED,
                default => self::EVENT_CREATED,
            };
            $version = (int) $state->version + 1;

            DB::table('reminder_schedule_states')->where('id', $state->id)->update([
                'version' => $version,
                'is_active' => $desired !== null,
                'recipient_user_id' => $desired['recipient']->id ?? $state->recipient_user_id,
                'scheduled_at' => $desired === null ? null : $desired['scheduled_at']->copy()->utc()->format('Y-m-d H:i:s'),
                'scheduled_has_time' => $desired['has_time'] ?? false,
                'updated_at' => now(),
            ]);

            $eventId = DB::table('reminder_outbox_events')->insertGetId([
                'event_uuid' => $uuid = (string) Str::uuid(),
                'idempotency_key' => $this->idempotencyKey($subjectType, $subjectId, $version),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'version' => $version,
                'event_type' => $eventType,
                'payload' => json_encode($this->payload(
                    $uuid, $eventType, $subjectType, $subjectId, (int) $state->ci_activity_id, $version,
                    $desired, $cancelReason, $state->recipient_user_id !== null ? (int) $state->recipient_user_id : null,
                ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'status' => ReminderOutboxDispatcher::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->deliverAfterCommit($eventId);
        });
    }

    /**
     * @return array{0: array{recipient: User, scheduled_at: Carbon, has_time: bool, trigger_at: Carbon, label: string, url: string}|null, 1: string|null}
     */
    private function desiredState(string $subjectType, CiActivity|CiActivityBankTarget|CiActivityAssetTarget|null $model, ?CiActivity $parent): array
    {
        if ($model === null || $parent === null) {
            return [null, self::REASON_DELETED];
        }

        if ($model->status === ActivityStatus::Completed) {
            return [null, self::REASON_COMPLETED];
        }

        // Same eligibility as ProcessDueCiActivityReminders: only Scheduled rows with a schedule.
        if ($model->status !== ActivityStatus::Scheduled || $model->scheduled_at === null) {
            return [null, self::REASON_UNSCHEDULED];
        }

        $recipient = $parent->creator_id !== null ? User::query()->find($parent->creator_id) : null;
        if (! $this->recipientReachable($recipient)) {
            Log::warning('n8n reminder skipped: the activity creator has no usable email address.', [
                'subject_type' => $subjectType,
                'subject_id' => $model->getKey(),
                'creator_id' => $parent->creator_id,
            ]);

            return [null, self::REASON_RECIPIENT_UNAVAILABLE];
        }

        $hasTime = (bool) $model->scheduled_has_time;

        return [[
            'recipient' => $recipient,
            'scheduled_at' => $model->scheduled_at->copy()->utc(),
            'has_time' => $hasTime,
            'trigger_at' => $this->triggerAt($model->scheduled_at, $hasTime),
            'label' => $this->label($subjectType, $model, $parent),
            'url' => route('client-folders.activities.index', [
                $parent->client_folder_id,
            ] + ($parent->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $parent->co_maker_id] : [])),
        ], null];
    }

    private function recipientReachable(?User $recipient): bool
    {
        return $recipient !== null
            && $recipient->status === UserStatus::Active
            && filled($recipient->email)
            && filter_var($recipient->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Date-only: 8:00 AM local on the scheduled local date. With a time: exactly that time. */
    private function triggerAt(Carbon $scheduledAt, bool $hasTime): Carbon
    {
        if ($hasTime) {
            return $scheduledAt->copy()->utc();
        }

        $timezone = config('cims.display_timezone');

        return Carbon::createFromFormat(
            '!Y-m-d H:i',
            $scheduledAt->copy()->timezone($timezone)->format('Y-m-d').' '.self::DATE_ONLY_TRIGGER_TIME,
            $timezone,
        )->utc();
    }

    /**
     * Generic labels only — never a client name, institution, branch, office, remarks or a
     * free-text custom Activity Type name, any of which could identify or describe the client.
     */
    private function label(string $subjectType, CiActivity|CiActivityBankTarget|CiActivityAssetTarget $model, CiActivity $parent): string
    {
        return match ($subjectType) {
            self::SUBJECT_BANK_TARGET => $model->inquiryTypeLabel(),
            self::SUBJECT_ASSET_TARGET => 'Asset Check',
            default => $parent->definition !== null && ! $parent->definition->isCustom()
                ? $parent->definition->name
                : 'CI Activity',
        };
    }

    private function sameState(object $state, array $desired): bool
    {
        return (int) $state->recipient_user_id === $desired['recipient']->id
            && (bool) $state->scheduled_has_time === $desired['has_time']
            && $state->scheduled_at !== null
            && Carbon::parse($state->scheduled_at, 'UTC')->equalTo($desired['scheduled_at']);
    }

    private function payload(
        string $uuid,
        string $eventType,
        string $subjectType,
        int $subjectId,
        int $ciActivityId,
        int $version,
        ?array $desired,
        ?string $cancelReason,
        ?int $previousRecipientId,
    ): array {
        $timezone = config('cims.display_timezone');

        return [
            'schema' => self::SCHEMA,
            'event_id' => $uuid,
            'idempotency_key' => $this->idempotencyKey($subjectType, $subjectId, $version),
            'event_type' => $eventType,
            'occurred_at' => now()->utc()->toIso8601ZuluString(),
            'reminder' => [
                'key' => $subjectType.':'.$subjectId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'ci_activity_id' => $ciActivityId,
                'version' => $version,
                'scheduled_at' => ($desired['scheduled_at'] ?? null)?->toIso8601ZuluString(),
                'has_specific_time' => $desired === null ? null : $desired['has_time'],
                'trigger_at' => ($desired['trigger_at'] ?? null)?->toIso8601ZuluString(),
                'trigger_at_local' => ($desired['trigger_at'] ?? null)?->copy()->timezone($timezone)->toIso8601String(),
                'timezone' => $timezone,
                'cancel_reason' => $desired === null ? $cancelReason : null,
            ],
            'recipient' => $desired === null
                ? ['user_id' => $previousRecipientId]
                : ['user_id' => $desired['recipient']->id, 'email' => $desired['recipient']->email],
            'content' => $desired === null ? null : [
                'subject_label' => $desired['label'],
                'activities_url' => $desired['url'],
            ],
        ];
    }

    private function idempotencyKey(string $subjectType, int $subjectId, int $version): string
    {
        return $subjectType.':'.$subjectId.':v'.$version;
    }

    private function lockState(string $subjectType, int $subjectId): ?object
    {
        return DB::table('reminder_schedule_states')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->lockForUpdate()
            ->first();
    }

    private function stateVersion(string $subjectType, int $subjectId): int
    {
        return (int) DB::table('reminder_schedule_states')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->value('version');
    }

    /**
     * Only this transaction's own event, only once it has committed, and only when explicitly
     * enabled. A delivery failure never fails the user's already-committed save; the event stays
     * pending for the delivery command.
     */
    private function deliverAfterCommit(int $eventId): void
    {
        if (! config('services.n8n_reminders.dispatch_after_commit')) {
            return;
        }

        DB::afterCommit(function () use ($eventId): void {
            try {
                $this->dispatcher->deliverPending(1, [$eventId]);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
