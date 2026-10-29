<?php

namespace Tests\Feature\Integrations;

use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\Integrations\N8n\ReminderOutboxDispatcher;
use App\Services\Integrations\N8n\ReminderWebhookSignature;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 2A-6: one-way Laravel -> n8n delivery. Per-subject in-flight serialization, the
 * acknowledgement contract the n8n ingestion workflow relies on, and failure logging that never
 * carries the payload. All HTTP is faked; nothing leaves the process.
 */
class N8nReminderOrderedDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://n8n.example.test/webhook/brbi-cims/reminder-events';

    private const SECRET = 'test-only-signing-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-10-25 02:00:00', 'UTC'));
        Http::preventStrayRequests();
        Mail::fake();

        config([
            'services.n8n_reminders.enabled' => true,
            'services.n8n_reminders.webhook_url' => self::WEBHOOK,
            'services.n8n_reminders.signing_secret' => self::SECRET,
            'services.n8n_reminders.dispatch_after_commit' => false,
            'services.n8n_reminders.max_attempts' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        Mail::assertNothingSent();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_newer_version_waits_while_an_older_request_for_the_same_subject_is_in_flight(): void
    {
        Http::fake([self::WEBHOOK => Http::response('{"result":"applied"}', 200)]);
        [$creator, $folder, $activity] = $this->scheduledActivity();
        $this->schedule($creator, $folder, $activity, '2026-11-04', '10:00');

        // Version 1 is being sent by another worker right now.
        $v1 = DB::table('reminder_outbox_events')->where('version', 1)->value('id');
        DB::table('reminder_outbox_events')->where('id', $v1)->update(['claim_token' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'claimed_at' => now()]);

        $result = app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertSame(0, $result['attempted']);
        Http::assertNothingSent();
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, DB::table('reminder_outbox_events')->where('version', 2)->value('status'));
        $this->assertSame(0, (int) DB::table('reminder_outbox_events')->where('version', 2)->value('attempts'));

        // The older worker finishes; now only the newest version is sent and v1 is superseded.
        DB::table('reminder_outbox_events')->where('id', $v1)->update(['claim_token' => null, 'claimed_at' => null]);
        $result = app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertSame(['attempted' => 1, 'delivered' => 1, 'superseded' => 1, 'retrying' => 0, 'failed' => 0], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->header(ReminderWebhookSignature::HEADER_IDEMPOTENCY_KEY)[0] === 'ci_activity:'.$activity->id.':v2');
    }

    public function test_an_in_flight_request_never_blocks_a_different_subject(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 200)]);
        [$creator, $folder, $first] = $this->scheduledActivity();
        $second = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $second, '2026-11-05');
        DB::table('reminder_outbox_events')->where('subject_id', $first->id)->update(['claim_token' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'claimed_at' => now()]);

        $result = app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertSame(1, $result['delivered']);
        Http::assertSent(fn (Request $request) => $request->header(ReminderWebhookSignature::HEADER_IDEMPOTENCY_KEY)[0] === 'ci_activity:'.$second->id.':v1');
    }

    public function test_an_expired_claim_no_longer_blocks_its_subject(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 200)]);
        [$creator, $folder, $activity] = $this->scheduledActivity();
        $this->schedule($creator, $folder, $activity, '2026-11-04', '10:00');
        DB::table('reminder_outbox_events')->where('version', 1)->update(['claim_token' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'claimed_at' => now()->subMinutes(6)]);

        $result = app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertSame(1, $result['delivered']);
        $this->assertSame(1, $result['superseded']);
    }

    public function test_only_a_2xx_acknowledgement_marks_the_event_delivered(): void
    {
        config(['services.n8n_reminders.max_attempts' => 4]);
        [$creator, $folder, $activity] = $this->scheduledActivity();

        // n8n answers 503 when Data Table persistence fails, 401 for a bad signature, 422 for schema.
        $statuses = [503, 401, 422, 409];
        $sequence = Http::sequence();
        foreach ($statuses as $status) {
            $sequence->push('{"result":"rejected"}', $status);
        }
        Http::fake([self::WEBHOOK => $sequence]);

        foreach ($statuses as $status) {
            DB::table('reminder_outbox_events')->update(['next_attempt_at' => null]);
            app(ReminderOutboxDispatcher::class)->deliverPending();
            $this->assertNotSame(ReminderOutboxDispatcher::STATUS_DELIVERED, DB::table('reminder_outbox_events')->value('status'), "HTTP {$status}");
        }

        $this->assertSame(ReminderOutboxDispatcher::STATUS_FAILED, DB::table('reminder_outbox_events')->value('status'));
        $this->assertSame(409, (int) DB::table('reminder_outbox_events')->value('last_response_status'));
    }

    public function test_an_uncertain_timeout_is_retried_with_the_identical_body_and_idempotency_key(): void
    {
        [$creator, $folder, $activity] = $this->scheduledActivity();
        $bodies = [];
        Http::fake(function (Request $request) use (&$bodies) {
            $bodies[] = [$request->body(), $request->header(ReminderWebhookSignature::HEADER_IDEMPOTENCY_KEY)[0]];

            if (count($bodies) === 1) {
                throw new ConnectionException('Operation timed out');
            }

            return Http::response('{"result":"duplicate"}', 200);
        });

        app(ReminderOutboxDispatcher::class)->deliverPending();
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, DB::table('reminder_outbox_events')->value('status'));

        Carbon::setTestNow(now()->addMinutes(2));
        app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame(ReminderOutboxDispatcher::STATUS_DELIVERED, DB::table('reminder_outbox_events')->value('status'));
    }

    public function test_delivery_failures_are_logged_without_the_payload_url_or_secret(): void
    {
        Log::spy();
        Http::fake([self::WEBHOOK => Http::response('{"email":"leak@example.test"}', 500)]);
        [$creator, $folder, $activity] = $this->scheduledActivity();

        app(ReminderOutboxDispatcher::class)->deliverPending();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($creator) {
            if ($message !== 'n8n reminder event delivery failed.') {
                return false;
            }
            $encoded = json_encode($context);

            return $context['response_status'] === 500
                && $context['outcome'] === 'retrying'
                && ! str_contains($encoded, $creator->email)
                && ! str_contains($encoded, 'leak@example.test')
                && ! str_contains($encoded, self::SECRET)
                && ! str_contains($encoded, 'n8n.example.test');
        })->once();
    }

    /** @return array{0: User, 1: ClientFolder, 2: CiActivity} */
    private function scheduledActivity(): array
    {
        $creator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id, 'created_by' => $creator->id]);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        return [$creator, $folder, $activity];
    }

    private function schedule(User $actor, ClientFolder $folder, CiActivity $activity, string $date, ?string $time = null): void
    {
        $this->actingAs($actor)->putJson(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'scheduled',
            'scheduled_at' => $date,
            'scheduled_time' => $time,
            'co_maker_id' => '',
            'expected_revision' => $activity->fresh()->revision,
        ])->assertOk();
    }

    private function activity(ClientFolder $folder, User $creator): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
        ]);
    }
}
