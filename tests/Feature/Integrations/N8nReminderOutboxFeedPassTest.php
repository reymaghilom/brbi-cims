<?php

namespace Tests\Feature\Integrations;

use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\Integrations\N8n\ReminderOutboxDispatcher;
use App\Services\Integrations\N8n\ReminderScheduleOutbox;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * The bell feed's bounded no-cron n8n outbox retry pass (Phase 3A item 9): at most one pass per
 * minute across all users, at most two events per pass, never breaking the feed itself.
 */
class N8nReminderOutboxFeedPassTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://n8n.example.test/webhook/cims-reminders';

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

    public function test_a_feed_poll_delivers_a_pending_event(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['result' => 'applied'])]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $this->schedule($creator, $folder, $this->activity($folder, $creator), '2026-11-03', '09:00');
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, DB::table('reminder_outbox_events')->sole()->status);

        $this->poll($creator)->assertOk()->assertJsonStructure(['html', 'unread_count']);

        Http::assertSentCount(1);
        $event = DB::table('reminder_outbox_events')->sole();
        $this->assertSame(ReminderOutboxDispatcher::STATUS_DELIVERED, $event->status);
        $this->assertSame(200, (int) $event->last_response_status);
    }

    public function test_a_second_poll_within_the_minute_does_not_run_another_pass(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['result' => 'applied'])]);
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $folder = $this->folderFor($creator);
        $secondFolder = $this->folderFor($creator);
        $this->schedule($creator, $folder, $this->activity($folder, $creator), '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $this->activity($folder, $creator, [], ActivityDefinition::NEIGHBOR_CHECK_CODE), '2026-11-03', '09:00');
        $this->schedule($creator, $secondFolder, $this->activity($secondFolder, $creator), '2026-11-03', '09:00');

        $this->poll($creator)->assertOk();
        Http::assertSentCount(2);

        // The guard is global, not per user: another user's poll in the same minute adds nothing.
        $this->poll($other)->assertOk();
        $this->poll($creator)->assertOk();
        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('reminder_outbox_events')->where('status', ReminderOutboxDispatcher::STATUS_PENDING)->count());

        Carbon::setTestNow(now()->addSeconds(61));
        $this->poll($creator)->assertOk();
        Http::assertSentCount(3);
        $this->assertSame(0, DB::table('reminder_outbox_events')->where('status', ReminderOutboxDispatcher::STATUS_PENDING)->count());
    }

    public function test_a_disabled_integration_sends_nothing_and_the_feed_is_unchanged(): void
    {
        Http::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $this->schedule($creator, $folder, $this->activity($folder, $creator), '2026-11-03', '09:00');
        $enabledResponse = $this->poll($creator)->assertOk()->json();
        Http::assertSentCount(1);

        config(['services.n8n_reminders.enabled' => false]);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->schedule($creator, $folder, $this->activity($folder, $creator, [], ActivityDefinition::NEIGHBOR_CHECK_CODE), '2026-11-04', '09:00');

        $disabledResponse = $this->poll($creator)->assertOk()->json();

        Http::assertSentCount(1);
        $this->assertSame(array_keys($enabledResponse), array_keys($disabledResponse));
        $this->assertSame(0, DB::table('reminder_outbox_events')->where('status', ReminderOutboxDispatcher::STATUS_PENDING)->count());
    }

    public function test_a_failing_dispatcher_never_breaks_the_feed(): void
    {
        $creator = User::factory()->create();
        $this->mock(ReminderOutboxDispatcher::class)
            ->shouldReceive('deliverPending')->once()->andThrow(new RuntimeException('boom'));
        Log::spy();

        $this->poll($creator)->assertOk()->assertJsonStructure(['html', 'unread_count']);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'n8n reminder outbox fallback failed.')->once();
    }

    public function test_a_non_ci_user_poll_still_runs_the_pass_and_gets_the_empty_payload(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['result' => 'applied'])]);
        $creator = User::factory()->create();
        $administrator = User::factory()->administrator()->create();
        $folder = $this->folderFor($creator);
        $this->schedule($creator, $folder, $this->activity($folder, $creator), '2026-11-03', '09:00');

        $this->poll($administrator)->assertOk()->assertExactJson(['html' => '', 'unread_count' => 0]);

        Http::assertSentCount(1);
        $this->assertSame(ReminderOutboxDispatcher::STATUS_DELIVERED, DB::table('reminder_outbox_events')->sole()->status);
    }

    public function test_a_pending_cancellation_supersedes_older_pending_versions_of_the_same_subject(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['result' => 'applied'])]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $activity, '2026-11-05', '10:00');
        $this->update($creator, $folder, $activity, ['status' => 'pending']);

        // v1 and v2 are superseded without any request. They use this pass's two-event budget,
        // so the cancellation (v3) goes out on the next pass.
        $this->poll($creator)->assertOk();
        Http::assertNothingSent();

        Carbon::setTestNow(now()->addSeconds(61));
        $this->poll($creator)->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $payload['event_type'] === ReminderScheduleOutbox::EVENT_CANCELLED
                && $payload['reminder']['version'] === 3
                && $payload['reminder']['cancel_reason'] === ReminderScheduleOutbox::REASON_UNSCHEDULED;
        });
        $this->assertSame(
            [1 => ReminderOutboxDispatcher::STATUS_SUPERSEDED, 2 => ReminderOutboxDispatcher::STATUS_SUPERSEDED, 3 => ReminderOutboxDispatcher::STATUS_DELIVERED],
            DB::table('reminder_outbox_events')->orderBy('version')->pluck('status', 'version')->all(),
        );
    }

    private function poll(User $user)
    {
        return $this->actingAs($user)->getJson(route('notifications.ci-activities.feed'));
    }

    private function schedule(User $actor, ClientFolder $folder, CiActivity $activity, string $date, ?string $time = null): void
    {
        $this->update($actor, $folder, $activity, [
            'status' => 'scheduled',
            'scheduled_at' => $date,
            'scheduled_time' => $time,
        ]);
    }

    private function update(User $actor, ClientFolder $folder, CiActivity $activity, array $data): void
    {
        $this->actingAs($actor)->putJson(route('client-folders.activities.update', [$folder, $activity]), $data + [
            'co_maker_id' => '',
            'expected_revision' => $activity->fresh()->revision,
        ])->assertOk();
    }

    private function activity(ClientFolder $folder, User $creator, array $overrides = [], string $code = ActivityDefinition::BARANGAY_CHECK_CODE): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
        ], $overrides));
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
