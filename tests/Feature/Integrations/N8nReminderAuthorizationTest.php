<?php

namespace Tests\Feature\Integrations;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\Integrations\N8n\ReminderDeliveryAuthorizer;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class N8nReminderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'local-test-authorization-token-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-11-03 02:00:00', 'UTC'));
        Http::preventStrayRequests();
        Mail::fake();

        config([
            'services.n8n_reminders.enabled' => true,
            'services.n8n_reminders.dispatch_after_commit' => false,
            'services.n8n_reminder_authorization.enabled' => true,
            'services.n8n_reminder_authorization.token' => self::TOKEN,
        ]);
    }

    protected function tearDown(): void
    {
        Mail::assertNothingSent();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_due_reminder_is_authorized_for_original_creator_only(): void
    {
        $creator = User::factory()->create();
        $assigned = User::factory()->create();
        $activity = $this->scheduledActivity($creator, ['assigned_ci_id' => $assigned->id]);

        $response = $this->claim($this->claimPayload($activity));

        $response->assertCreated()
            ->assertJsonPath('result', 'authorized')
            ->assertJsonPath('recipient.user_id', $creator->id)
            ->assertJsonPath('recipient.email', $creator->email);
        $this->assertNotSame($assigned->id, $response->json('recipient.user_id'));
        $this->assertDatabaseCount('reminder_delivery_claims', 1);
    }

    public function test_duplicate_claim_is_denied(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));
        $this->claim($payload)->assertCreated();
        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'duplicate_delivery_claim');
        $this->assertDatabaseCount('reminder_delivery_claims', 1);
    }

    public function test_competing_claim_attempts_converge_on_database_unique_constraint(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));
        $first = app(ReminderDeliveryAuthorizer::class)->claim($payload);
        $claim = DB::table('reminder_delivery_claims')->sole();

        $this->expectException(QueryException::class);
        DB::table('reminder_delivery_claims')->insert([
            'claim_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'event_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'occurrence_key' => $first['occurrence_key'],
            'subject_type' => $claim->subject_type,
            'subject_id' => $claim->subject_id,
            'ci_activity_id' => $claim->ci_activity_id,
            'schedule_version' => $claim->schedule_version,
            'occurrence_at' => $claim->occurrence_at,
            'recipient_user_id' => $claim->recipient_user_id,
            'recipient_email_hash' => $claim->recipient_email_hash,
            'status' => 'claimed',
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_outdated_version_and_rescheduled_occurrence_are_denied(): void
    {
        $activity = $this->scheduledActivity(User::factory()->create());
        $old = $this->claimPayload($activity);
        $activity->forceFill([
            'scheduled_at' => Carbon::parse('2026-11-03 11:00', 'Asia/Manila')->utc(),
            'scheduled_has_time' => true,
        ])->save();

        $this->claim($old)->assertConflict()->assertJsonPath('reason', 'outdated_schedule_version');
        $this->claim($this->claimPayload($activity))->assertConflict()->assertJsonPath('reason', 'reminder_not_due');

        Carbon::setTestNow(Carbon::parse('2026-11-03 03:00:00', 'UTC'));
        $this->claim($this->claimPayload($activity))->assertCreated();
    }

    public function test_cancelled_reminder_is_denied(): void
    {
        $activity = $this->scheduledActivity(User::factory()->create());
        $payload = $this->claimPayload($activity);
        $activity->forceFill(['status' => ActivityStatus::Pending, 'scheduled_at' => null])->save();

        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'schedule_cancelled');
    }

    public function test_completed_activity_is_denied_even_if_stale_state_is_marked_active(): void
    {
        $activity = $this->scheduledActivity(User::factory()->create());
        $payload = $this->claimPayload($activity);
        $activity->withoutEvents(fn () => $activity->forceFill([
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
        ])->save());

        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'activity_completed');
    }

    public function test_invalid_authentication_is_rejected_before_claim_validation(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));

        $this->postJson(route('integrations.n8n.reminder-authorizations.claim'), $payload, [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized()->assertJsonPath('result', 'unauthorized');
        $this->assertDatabaseCount('reminder_delivery_claims', 0);
    }

    public function test_disabled_endpoint_is_not_exposed(): void
    {
        config(['services.n8n_reminder_authorization.enabled' => false]);

        $this->claim($this->claimPayload($this->scheduledActivity(User::factory()->create())))->assertNotFound();
        $this->assertDatabaseCount('reminder_delivery_claims', 0);
    }

    public function test_missing_creator_email_is_denied(): void
    {
        $creator = User::factory()->create();
        $payload = $this->claimPayload($this->scheduledActivity($creator));
        $creator->forceFill(['email' => null])->save();

        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'invalid_recipient');
    }

    public function test_duplicate_data_table_rows_are_harmless_when_every_path_claims(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));

        $results = [
            $this->claim($payload)->status(),
            $this->claim($payload)->status(),
            $this->claim($payload)->status(),
        ];

        $this->assertSame([201, 409, 409], $results);
        $this->assertDatabaseCount('reminder_delivery_claims', 1);
    }

    public function test_claim_acquired_but_gmail_not_sent_stays_blocked_for_manual_reconciliation(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));
        $this->claim($payload)->assertCreated();

        Carbon::setTestNow(now()->addDay());
        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'duplicate_delivery_claim');
        $this->assertDatabaseHas('reminder_delivery_claims', ['status' => 'claimed', 'acknowledged_at' => null]);
    }

    public function test_gmail_sent_but_acknowledgement_lost_cannot_obtain_second_claim(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));
        $claimId = $this->claim($payload)->assertCreated()->json('claim_id');

        // No Gmail call is made. This models the safety boundary after an unknowable provider result.
        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'duplicate_delivery_claim');
        $this->assertDatabaseHas('reminder_delivery_claims', [
            'claim_uuid' => $claimId,
            'status' => 'claimed',
            'acknowledged_at' => null,
        ]);
    }

    public function test_unknown_mismatched_and_conflicting_identities_are_denied(): void
    {
        $activity = $this->scheduledActivity(User::factory()->create(), [], '2026-11-03 11:00');
        $payload = $this->claimPayload($activity);

        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'reminder_not_due');
        Carbon::setTestNow(Carbon::parse('2026-11-03 03:00:00', 'UTC'));

        $payload['event_id'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->claim($payload)->assertNotFound()->assertJsonPath('reason', 'unknown_event');

        $payload = $this->claimPayload($activity);
        $payload['ci_activity_id']++;
        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'activity_mismatch');

        $other = $this->scheduledActivity(User::factory()->create());
        $otherPayload = $this->claimPayload($other);
        $payload = $this->claimPayload($activity);
        $payload['event_id'] = $otherPayload['event_id'];
        $this->claim($payload)->assertConflict()->assertJsonPath('reason', 'conflicting_event_identity');
    }

    public function test_payload_validation_rejects_missing_and_unexpected_fields(): void
    {
        $payload = $this->claimPayload($this->scheduledActivity(User::factory()->create()));
        unset($payload['schedule_version']);
        $payload['recipient_email'] = 'attacker@example.test';

        $this->claim($payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule_version', 'payload']);
        $this->assertDatabaseCount('reminder_delivery_claims', 0);
    }

    public function test_failed_webhook_and_idle_application_leave_durable_pending_outbox_event(): void
    {
        $activity = $this->scheduledActivity(User::factory()->create());
        $event = DB::table('reminder_outbox_events')->sole();

        $this->assertSame('pending', $event->status);
        $this->assertSame(0, (int) $event->attempts);
        $this->assertDatabaseCount('reminder_delivery_claims', 0);
        Http::assertNothingSent();

        // Merely advancing time performs no retry: InfinityFree has no approved cron/worker here.
        Carbon::setTestNow(now()->addDay());
        $this->assertSame('pending', DB::table('reminder_outbox_events')->where('id', $event->id)->value('status'));
        $this->assertNotNull($activity->fresh());
    }

    private function claim(array $payload)
    {
        return $this->postJson(route('integrations.n8n.reminder-authorizations.claim'), $payload, [
            'Authorization' => 'Bearer '.self::TOKEN,
        ]);
    }

    private function claimPayload(CiActivity $activity): array
    {
        $event = DB::table('reminder_outbox_events')
            ->where('subject_type', 'ci_activity')
            ->where('subject_id', $activity->id)
            ->orderByDesc('version')
            ->first();
        $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);

        return [
            'event_id' => $event->event_uuid,
            'subject_type' => 'ci_activity',
            'subject_id' => $activity->id,
            'ci_activity_id' => $activity->id,
            'schedule_version' => (int) $event->version,
            'occurrence_at' => $payload['reminder']['trigger_at'],
        ];
    }

    private function scheduledActivity(User $creator, array $overrides = [], string $localDateTime = '2026-11-03 09:00'): CiActivity
    {
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $overrides['assigned_ci_id'] ?? $creator->id,
            'created_by' => $creator->id,
        ]);
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        $activity = CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
        ], $overrides));
        $activity->forceFill([
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse($localDateTime, 'Asia/Manila')->utc(),
            'scheduled_has_time' => true,
        ])->save();

        return $activity->fresh();
    }
}
