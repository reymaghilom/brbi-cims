<?php

namespace Tests\Feature\Integrations;

use App\Actions\Admin\ResetOperationalData;
use App\Actions\ClientFolders\DeleteCiActivity;
use App\Actions\ClientFolders\PurgeClientFolder;
use App\Actions\ClientFolders\RemoveCoMaker;
use App\Actions\ClientFolders\SaveCiActivityAssetTarget;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\Integrations\N8n\ReminderOutboxDispatcher;
use App\Services\Integrations\N8n\ReminderScheduleOutbox;
use App\Services\Integrations\N8n\ReminderWebhookSignature;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class N8nReminderOutboxTest extends TestCase
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

    // ==================================================
    // Recipient and isolation
    // ==================================================

    public function test_event_goes_to_the_activity_creator_never_the_assigned_ci_or_the_editor(): void
    {
        $creator = User::factory()->create();
        $assignedCi = User::factory()->create();
        $editor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id, 'created_by' => $creator->id]);
        $activity = $this->activity($folder, $creator, ['assigned_ci_id' => $assignedCi->id]);

        $this->schedule($editor, $folder, $activity, '2026-11-03', '09:00');

        $payload = $this->onlyEvent()['payload'];
        $this->assertSame(ReminderScheduleOutbox::EVENT_CREATED, $payload['event_type']);
        $this->assertSame(['user_id' => $creator->id, 'email' => $creator->email], $payload['recipient']);
        $this->assertStringNotContainsString($assignedCi->email, json_encode($payload));
        $this->assertStringNotContainsString($editor->email, json_encode($payload));
    }

    public function test_payload_is_minimal_and_carries_no_client_details(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->multiTargetActivity($folder, $creator, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $this->actingAs($creator)->post(route('client-folders.activities.bank-targets.store', [$folder, $bank]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'Secret Savings Bank',
            'branch_location' => 'Hidden Branch',
            'status' => 'scheduled',
            'scheduled_at' => '2026-11-03',
            'scheduled_time' => '09:00',
            'remarks' => 'Confidential remark',
        ])->assertRedirect();

        $raw = DB::table('reminder_outbox_events')->sole()->payload;
        foreach (['Secret Savings Bank', 'Hidden Branch', 'Confidential remark', $folder->display_name, $folder->last_name] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $raw);
        }
        $payload = json_decode($raw, true);
        $this->assertSame('Bank / Coop Check', $payload['content']['subject_label']);
        $this->assertSame(['schema', 'event_id', 'idempotency_key', 'event_type', 'occurred_at', 'reminder', 'recipient', 'content'], array_keys($payload));
        $this->assertStringNotContainsString(self::SECRET, $raw);
    }

    public function test_applicant_and_co_maker_reminders_are_independent_subjects(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Scope Maker', 'first_name' => 'Scope', 'last_name' => 'Maker']);
        $applicant = $this->activity($folder, $creator);
        $coMakerActivity = $this->activity($folder, $creator, ['co_maker_id' => $coMaker->id]);

        $this->schedule($creator, $folder, $applicant, '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $coMakerActivity, '2026-11-04', '10:00', $coMaker->id);
        $this->schedule($creator, $folder, $coMakerActivity, '2026-11-05', '10:00', $coMaker->id);

        $this->assertSame(1, $this->stateVersion('ci_activity', $applicant->id));
        $this->assertSame(2, $this->stateVersion('ci_activity', $coMakerActivity->id));
        $coMakerPayload = $this->latestPayload('ci_activity', $coMakerActivity->id);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $coMakerPayload['content']['activities_url']);
        $this->assertStringNotContainsString('co_maker_id', $this->latestPayload('ci_activity', $applicant->id)['content']['activities_url']);

        // Removing the Co-Maker (database cascade) cancels only the Co-Maker's reminder.
        app(RemoveCoMaker::class)->execute(User::factory()->administrator()->create(), $folder, $coMaker);

        $this->assertSame(ReminderScheduleOutbox::EVENT_CANCELLED, $this->latestPayload('ci_activity', $coMakerActivity->id)['event_type']);
        $this->assertSame(ReminderScheduleOutbox::REASON_DELETED, $this->latestPayload('ci_activity', $coMakerActivity->id)['reminder']['cancel_reason']);
        $this->assertSame(1, $this->stateVersion('ci_activity', $applicant->id));
    }

    // ==================================================
    // Date-only versus specific time
    // ==================================================

    public function test_date_only_schedule_triggers_at_8am_manila(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->schedule($creator, $folder, $activity, '2026-11-03');

        $reminder = $this->onlyEvent()['payload']['reminder'];
        $this->assertFalse($reminder['has_specific_time']);
        $this->assertSame('2026-11-03T00:00:00Z', $reminder['trigger_at']);
        $this->assertSame('2026-11-03T08:00:00+08:00', $reminder['trigger_at_local']);
        $this->assertSame('Asia/Manila', $reminder['timezone']);
    }

    public function test_specific_time_schedule_triggers_at_that_time(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->schedule($creator, $folder, $activity, '2026-11-03', '14:30');

        $reminder = $this->onlyEvent()['payload']['reminder'];
        $this->assertTrue($reminder['has_specific_time']);
        $this->assertSame('2026-11-03T06:30:00Z', $reminder['trigger_at']);
        $this->assertSame('2026-11-03T14:30:00+08:00', $reminder['trigger_at_local']);
    }

    // ==================================================
    // Lifecycle: create, reschedule, no-op, complete, unschedule, delete
    // ==================================================

    public function test_reschedule_records_a_new_version_and_a_no_change_save_records_nothing(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $activity, '2026-11-04', '09:00');

        $events = $this->events('ci_activity', $activity->id);
        $this->assertSame([1, 2], $events->pluck('version')->all());
        $this->assertSame([ReminderScheduleOutbox::EVENT_CREATED, ReminderScheduleOutbox::EVENT_CHANGED], $events->pluck('event_type')->all());
        $this->assertSame('2026-11-04T01:00:00Z', $this->latestPayload('ci_activity', $activity->id)['reminder']['trigger_at']);
    }

    public function test_completion_and_unscheduling_cancel_the_pending_reminder(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $completed = $this->activity($folder, $creator);
        $unscheduled = $this->activity($folder, $creator, [], ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $this->schedule($creator, $folder, $completed, '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $unscheduled, '2026-11-03', '09:00');
        $this->update($creator, $folder, $completed, ['status' => 'completed']);
        $this->update($creator, $folder, $unscheduled, ['status' => 'pending']);

        $this->assertCancelled('ci_activity', $completed->id, ReminderScheduleOutbox::REASON_COMPLETED, 2);
        $this->assertCancelled('ci_activity', $unscheduled->id, ReminderScheduleOutbox::REASON_UNSCHEDULED, 2);
    }

    public function test_deleting_the_activity_cancels_the_pending_reminder(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        app(DeleteCiActivity::class)->execute($creator, $folder, $activity->fresh());

        $this->assertCancelled('ci_activity', $activity->id, ReminderScheduleOutbox::REASON_DELETED, 2);
    }

    public function test_a_refused_stale_save_records_no_event(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $activity]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-11-03',
            'scheduled_time' => '09:00',
            'expected_revision' => $activity->fresh()->revision + 5,
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('reminder_outbox_events')->count());
    }

    // ==================================================
    // Bank / Coop and Asset targets
    // ==================================================

    public function test_bank_target_schedule_and_completion(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->multiTargetActivity($folder, $creator, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $this->actingAs($creator)->post(route('client-folders.activities.bank-targets.store', [$folder, $bank]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            'institution_name' => 'LandBank',
            'status' => 'scheduled',
            'scheduled_at' => '2026-11-03',
        ])->assertRedirect();
        $target = $bank->bankTargets()->sole();

        $created = $this->latestPayload('bank_target', $target->id);
        $this->assertSame(ReminderScheduleOutbox::EVENT_CREATED, $created['event_type']);
        $this->assertSame($bank->id, $created['reminder']['ci_activity_id']);
        $this->assertSame('Loan Inquiry', $created['content']['subject_label']);
        $this->assertSame($creator->id, $created['recipient']['user_id']);
        // The parent of a multi-target check never carries its own schedule.
        $this->assertSame(0, $this->events('ci_activity', $bank->id)->count());

        $this->actingAs($creator)->patch(route('client-folders.activities.bank-targets.complete', [$folder, $bank, $target]), [
            'co_maker_id' => null,
        ])->assertRedirect();

        $this->assertCancelled('bank_target', $target->id, ReminderScheduleOutbox::REASON_COMPLETED, 2);
    }

    public function test_asset_target_schedule_and_delete(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->multiTargetActivity($folder, $creator, ActivityDefinition::ASSET_CHECK_CODE);

        $this->actingAs($creator)->post(route('client-folders.activities.asset-targets.store', [$folder, $asset]), [
            'co_maker_id' => '',
            'assessor_type' => 'city_assessor',
            'office_location' => 'Land',
            'status' => 'scheduled',
            'scheduled_at' => '2026-11-04',
            'scheduled_time' => '10:30',
        ])->assertRedirect();
        $target = $asset->assetTargets()->sole();

        $created = $this->latestPayload('asset_target', $target->id);
        $this->assertSame('Asset Check', $created['content']['subject_label']);
        $this->assertSame('2026-11-04T02:30:00Z', $created['reminder']['trigger_at']);

        app(SaveCiActivityAssetTarget::class)->delete($creator, $folder, $asset, $target);

        $this->assertCancelled('asset_target', $target->id, ReminderScheduleOutbox::REASON_DELETED, 2);
    }

    public function test_deleting_a_parent_cancels_its_cascaded_targets(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->multiTargetActivity($folder, $creator, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $first = $this->bankTarget($bank, $creator, 'BDO');
        $second = $this->bankTarget($bank, $creator, 'BPI');

        app(DeleteCiActivity::class)->execute($creator, $folder, $bank->fresh());

        $this->assertCancelled('bank_target', $first->id, ReminderScheduleOutbox::REASON_DELETED, 2);
        $this->assertCancelled('bank_target', $second->id, ReminderScheduleOutbox::REASON_DELETED, 2);
    }

    public function test_purging_the_folder_and_resetting_data_cancel_pending_reminders(): void
    {
        $creator = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $purged = $this->folderFor($creator);
        $purgedActivity = $this->activity($purged, $creator);
        $this->schedule($creator, $purged, $purgedActivity, '2026-11-03', '09:00');
        $resetFolder = $this->folderFor($creator);
        $resetActivity = $this->activity($resetFolder, $creator);
        $this->schedule($creator, $resetFolder, $resetActivity, '2026-11-03', '09:00');

        app(PurgeClientFolder::class)->execute($admin, $purged);
        $this->assertCancelled('ci_activity', $purgedActivity->id, ReminderScheduleOutbox::REASON_DELETED, 2);
        $this->assertSame(1, $this->stateVersion('ci_activity', $resetActivity->id));

        app(ResetOperationalData::class)->execute($admin);
        $this->assertCancelled('ci_activity', $resetActivity->id, ReminderScheduleOutbox::REASON_DELETED, 2);
    }

    // ==================================================
    // Disabled / recipient unavailable / existing notifications
    // ==================================================

    public function test_nothing_is_recorded_while_the_integration_is_disabled(): void
    {
        config(['services.n8n_reminders.enabled' => false]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        $this->assertSame(0, DB::table('reminder_outbox_events')->count());
        $this->assertSame(0, DB::table('reminder_schedule_states')->count());
        $this->assertNotNull(app(ReminderOutboxDispatcher::class)->configurationError());
        $this->assertSame(0, app(ReminderOutboxDispatcher::class)->deliverPending()['attempted']);
    }

    public function test_legacy_creator_without_email_records_no_reminder_and_a_lost_email_cancels(): void
    {
        $legacy = User::factory()->create(['email' => null]);
        $folder = $this->folderFor($legacy);
        $activity = $this->activity($folder, $legacy);
        $this->schedule($legacy, $folder, $activity, '2026-11-03', '09:00');
        $this->assertSame(0, DB::table('reminder_outbox_events')->count());

        $creator = User::factory()->create();
        $other = $this->activity($this->folderFor($creator), $creator);
        $this->schedule($creator, $other->clientFolder, $other, '2026-11-03', '09:00');
        $creator->forceFill(['email' => null])->save();
        $this->schedule($creator, $other->clientFolder, $other, '2026-11-04', '09:00');

        $this->assertCancelled('ci_activity', $other->id, ReminderScheduleOutbox::REASON_RECIPIENT_UNAVAILABLE, 2);
    }

    public function test_existing_database_notifications_are_unchanged(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->schedule($editor, $folder, $activity, '2026-11-03', '09:00');

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, fn ($notification) => $notification->via($creator) === ['database']);
        Notification::assertNotSentTo($editor, CiActivityScheduledReminder::class);
        $this->assertSame(1, DB::table('reminder_outbox_events')->count());
    }

    // ==================================================
    // Delivery: signing, idempotency, retries, superseded and out-of-order versions
    // ==================================================

    public function test_delivery_is_signed_and_marks_the_event_delivered_exactly_once(): void
    {
        Http::fake([self::WEBHOOK => Http::response(['ok' => true], 200)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        $first = app(ReminderOutboxDispatcher::class)->deliverPending();
        $second = app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertSame(1, $first['delivered']);
        $this->assertSame(0, $second['attempted']);
        Http::assertSentCount(1);
        $event = DB::table('reminder_outbox_events')->sole();
        $this->assertSame(ReminderOutboxDispatcher::STATUS_DELIVERED, $event->status);
        Http::assertSent(function (Request $request) use ($event): bool {
            return $request->url() === self::WEBHOOK
                && $request->body() === $event->payload
                && $request->header(ReminderWebhookSignature::HEADER_IDEMPOTENCY_KEY)[0] === 'ci_activity:'.$event->subject_id.':v1'
                && $request->header(ReminderWebhookSignature::HEADER_EVENT_ID)[0] === $event->event_uuid
                && ReminderWebhookSignature::verify(
                    self::SECRET,
                    $request->header(ReminderWebhookSignature::HEADER_SIGNATURE)[0],
                    (int) $request->header(ReminderWebhookSignature::HEADER_TIMESTAMP)[0],
                    $request->body(),
                    now()->getTimestamp(),
                )
                && ! ReminderWebhookSignature::verify('wrong-secret', $request->header(ReminderWebhookSignature::HEADER_SIGNATURE)[0], (int) $request->header(ReminderWebhookSignature::HEADER_TIMESTAMP)[0], $request->body(), now()->getTimestamp());
        });
    }

    public function test_signature_contract_matches_the_phase_2a_cross_runtime_fixture(): void
    {
        $this->assertSame(
            'sha256=1d1fe21a032e64c85dae0e94e2c06b89ef96b9f270e9844ac0cbaf1ef3f2ae39',
            ReminderWebhookSignature::sign(
                'phase-2a-test-signing-secret-0123456789abcdef',
                1798500000,
                '{"schema":"brbi.cims.reminder.v1"}',
            ),
        );
    }

    public function test_failed_delivery_backs_off_retries_and_finally_fails_without_resending_after_success(): void
    {
        Http::fakeSequence(self::WEBHOOK)
            ->push('error', 500)
            ->pushFailedConnection()
            ->push('', 204);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $dispatcher = app(ReminderOutboxDispatcher::class);

        $this->assertSame(1, $dispatcher->deliverPending()['retrying']);
        $event = DB::table('reminder_outbox_events')->sole();
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, $event->status);
        $this->assertSame(500, (int) $event->last_response_status);
        $this->assertSame(0, $dispatcher->deliverPending()['attempted'], 'Not retried before its backoff elapses.');

        $this->travel(2)->minutes();
        $this->assertSame(1, $dispatcher->deliverPending()['retrying']);
        $this->assertSame('Delivery failed (ConnectionException).', DB::table('reminder_outbox_events')->value('last_error'));

        $this->travel(3)->minutes();
        $this->assertSame(1, $dispatcher->deliverPending()['delivered']);
        $this->travel(1)->hour();
        $this->assertSame(0, $dispatcher->deliverPending()['attempted']);
        Http::assertSentCount(3);

        // Every retry re-sent the identical body and idempotency key (lost-acknowledgement safe).
        $recorded = Http::recorded()->map(fn (array $pair) => [$pair[0]->body(), $pair[0]->header(ReminderWebhookSignature::HEADER_IDEMPOTENCY_KEY)[0]]);
        $this->assertCount(1, $recorded->unique(fn (array $pair) => implode('|', $pair)));
    }

    public function test_exhausted_attempts_mark_the_event_failed_until_requeued(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 503)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $dispatcher = app(ReminderOutboxDispatcher::class);

        foreach (range(1, 3) as $attempt) {
            $dispatcher->deliverPending();
            $this->travel(10)->minutes();
        }

        $this->assertSame(ReminderOutboxDispatcher::STATUS_FAILED, DB::table('reminder_outbox_events')->value('status'));
        $this->assertSame(0, $dispatcher->deliverPending()['attempted']);
        $this->assertSame(1, $dispatcher->requeueFailed());
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, DB::table('reminder_outbox_events')->value('status'));
    }

    public function test_superseded_versions_are_never_sent_and_only_the_newest_reaches_n8n(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 200)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $this->schedule($creator, $folder, $activity, '2026-11-04', '09:00');
        $this->update($creator, $folder, $activity, ['status' => 'completed']);

        $result = app(ReminderOutboxDispatcher::class)->deliverPending();

        $this->assertSame(2, $result['superseded']);
        $this->assertSame(1, $result['delivered']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['event_type'] === ReminderScheduleOutbox::EVENT_CANCELLED && $request['reminder']['version'] === 3);
        $this->assertSame(
            [ReminderOutboxDispatcher::STATUS_SUPERSEDED, ReminderOutboxDispatcher::STATUS_SUPERSEDED, ReminderOutboxDispatcher::STATUS_DELIVERED],
            $this->events('ci_activity', $activity->id)->pluck('status')->all(),
        );
    }

    public function test_versions_are_monotonic_so_a_receiver_can_discard_out_of_order_events(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 200)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $dispatcher = app(ReminderOutboxDispatcher::class);

        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        $dispatcher->deliverPending();
        $this->schedule($creator, $folder, $activity, '2026-11-04', '09:00');
        $dispatcher->deliverPending();
        $this->update($creator, $folder, $activity, ['status' => 'pending']);
        $dispatcher->deliverPending();

        $sent = Http::recorded()->map(fn (array $pair) => [$pair[0]['reminder']['key'], $pair[0]['reminder']['version'], $pair[0]['event_type']])->all();
        $this->assertSame([
            ['ci_activity:'.$activity->id, 1, ReminderScheduleOutbox::EVENT_CREATED],
            ['ci_activity:'.$activity->id, 2, ReminderScheduleOutbox::EVENT_CHANGED],
            ['ci_activity:'.$activity->id, 3, ReminderScheduleOutbox::EVENT_CANCELLED],
        ], $sent);
        // Uniqueness is enforced by the database, not just by the code path.
        $this->assertSame(3, DB::table('reminder_outbox_events')->distinct()->count('idempotency_key'));
    }

    public function test_a_claimed_event_is_not_delivered_by_a_second_concurrent_worker(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 200)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');
        DB::table('reminder_outbox_events')->update(['claim_token' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'claimed_at' => now()]);

        $this->assertSame(0, app(ReminderOutboxDispatcher::class)->deliverPending()['attempted']);
        Http::assertNothingSent();
    }

    public function test_after_commit_dispatch_sends_only_committed_events_when_opted_in(): void
    {
        config(['services.n8n_reminders.dispatch_after_commit' => true]);
        Http::fake([self::WEBHOOK => Http::response('', 500)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        // A failing webhook never fails the user's committed save.
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        Http::assertSentCount(1);
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, DB::table('reminder_outbox_events')->value('status'));
    }

    public function test_schedule_change_and_outbox_event_roll_back_together(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);

        try {
            DB::transaction(function () use ($activity): void {
                $activity->forceFill([
                    'status' => ActivityStatus::Scheduled,
                    'scheduled_at' => Carbon::parse('2026-11-03 09:00', 'Asia/Manila')->utc(),
                    'scheduled_has_time' => true,
                ])->save();

                throw new \RuntimeException('Force the surrounding transaction to roll back.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Force the surrounding transaction to roll back.', $exception->getMessage());
        }

        $activity->refresh();

        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertSame(0, DB::table('reminder_schedule_states')->count());
        $this->assertSame(0, DB::table('reminder_outbox_events')->count());
        Http::assertNothingSent();
    }

    public function test_misconfigured_endpoint_never_sends_and_the_command_refuses(): void
    {
        config(['services.n8n_reminders.webhook_url' => 'http://insecure.example.test/hook']);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        $this->artisan('n8n-reminders:deliver')->assertFailed();
        config(['services.n8n_reminders.webhook_url' => self::WEBHOOK, 'services.n8n_reminders.signing_secret' => 'short']);
        $this->artisan('n8n-reminders:deliver')->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(ReminderOutboxDispatcher::STATUS_PENDING, DB::table('reminder_outbox_events')->value('status'));
    }

    public function test_delivery_command_delivers_pending_events(): void
    {
        Http::fake([self::WEBHOOK => Http::response('', 200)]);
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $this->schedule($creator, $folder, $activity, '2026-11-03', '09:00');

        $this->artisan('n8n-reminders:deliver')
            ->expectsOutput('Attempted 1, delivered 1, superseded 0, retrying 0, failed 0.')
            ->assertSuccessful();
    }

    // ==================================================
    // Helpers
    // ==================================================

    private function schedule(User $actor, ClientFolder $folder, CiActivity $activity, string $date, ?string $time = null, ?int $coMakerId = null): void
    {
        $this->update($actor, $folder, $activity, [
            'status' => 'scheduled',
            'scheduled_at' => $date,
            'scheduled_time' => $time,
        ], $coMakerId);
    }

    private function update(User $actor, ClientFolder $folder, CiActivity $activity, array $data, ?int $coMakerId = null): void
    {
        $this->actingAs($actor)->putJson(route('client-folders.activities.update', [$folder, $activity]), $data + [
            'co_maker_id' => $coMakerId ?? '',
            'expected_revision' => $activity->fresh()->revision,
        ])->assertOk();
    }

    private function assertCancelled(string $type, int $id, string $reason, int $version): void
    {
        $payload = $this->latestPayload($type, $id);
        $this->assertSame(ReminderScheduleOutbox::EVENT_CANCELLED, $payload['event_type']);
        $this->assertSame($reason, $payload['reminder']['cancel_reason']);
        $this->assertSame($version, $payload['reminder']['version']);
        $this->assertNull($payload['content']);
        $this->assertArrayNotHasKey('email', $payload['recipient']);
        $this->assertFalse((bool) DB::table('reminder_schedule_states')->where('subject_type', $type)->where('subject_id', $id)->value('is_active'));
    }

    private function onlyEvent(): array
    {
        $event = DB::table('reminder_outbox_events')->sole();

        return ['row' => $event, 'payload' => json_decode($event->payload, true)];
    }

    private function events(string $type, int $id)
    {
        return DB::table('reminder_outbox_events')->where('subject_type', $type)->where('subject_id', $id)->orderBy('version')->get();
    }

    private function latestPayload(string $type, int $id): array
    {
        return json_decode($this->events($type, $id)->last()->payload, true);
    }

    private function stateVersion(string $type, int $id): int
    {
        return (int) DB::table('reminder_schedule_states')->where('subject_type', $type)->where('subject_id', $id)->value('version');
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

    private function multiTargetActivity(ClientFolder $folder, User $creator, string $code): CiActivity
    {
        return $this->activity($folder, $creator, [], $code);
    }

    private function bankTarget(CiActivity $activity, User $actor, string $institution): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $institution,
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-11-03 09:00', 'Asia/Manila')->utc(),
            'scheduled_has_time' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
