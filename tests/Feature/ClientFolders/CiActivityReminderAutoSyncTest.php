<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CiActivityReminderAutoSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_adding_a_schedule_creates_the_reminder_and_the_json_response_confirms_success(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        $response = $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '09:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ]);

        $response->assertOk();
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED;
        });
        $this->assertNull($barangay->fresh()->reminder_sent_at);
    }

    public function test_original_creator_remains_the_sole_notification_recipient(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        // A different, also-authorized CI performs the reschedule.
        $this->actingAs($otherCi)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '09:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class);
        Notification::assertNotSentTo($otherCi, CiActivityScheduledReminder::class);
    }

    public function test_rescheduling_invalidates_the_stale_reminder_and_sends_exactly_one_new_notification(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-04',
            'scheduled_time' => '10:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $this->assertNull($barangay->fresh()->reminder_sent_at, 'the stale reminder watermark must be cleared so the new schedule can fire');
        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED;
        });
    }

    public function test_clearing_the_schedule_clears_the_pending_reminder_watermark(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'pending',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $fresh = $barangay->fresh();
        $this->assertNull($fresh->scheduled_at);
        $this->assertNull($fresh->reminder_sent_at);
        Notification::assertNothingSentTo($creator);
    }

    public function test_completing_an_activity_prevents_a_stale_future_reminder_from_ever_being_sent(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        // The due-reminder command only ever considers status=Scheduled — Completed is excluded outright.
        $this->assertSame(ActivityStatus::Completed, $barangay->fresh()->status);
        $this->assertDatabaseMissing('ci_activities', ['id' => $barangay->id, 'status' => 'scheduled']);
    }

    public function test_reopening_a_completed_activity_does_not_revive_the_old_reminder(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'pending',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $fresh = $barangay->fresh();
        $this->assertSame(ActivityStatus::Pending, $fresh->status);
        $this->assertNull($fresh->scheduled_at);
        Notification::assertNothingSentTo($creator);
    }

    public function test_explicit_new_schedule_after_reopen_creates_the_correct_new_reminder(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'pending',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $reopened = $barangay->fresh();
        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-05',
            'scheduled_time' => '11:00',
            'expected_updated_at' => $reopened->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED;
        });
    }

    public function test_saving_the_exact_same_schedule_again_does_not_duplicate_the_reminder(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        $first = $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '09:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ]);
        $first->assertOk();

        // Same status, same date/time submitted again (e.g. the user reopened the tracker
        // and saved without actually changing anything reaching the backend).
        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '09:00',
            'expected_updated_at' => $barangay->fresh()->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
    }

    public function test_date_only_schedule_uses_the_existing_canonical_eight_am_reminder_time(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $fresh = $barangay->fresh();
        $this->assertFalse($fresh->scheduled_has_time);
        $this->assertSame('08:00', $fresh->scheduled_at->timezone(config('cims.display_timezone'))->format('H:i'));
    }

    public function test_a_future_schedule_never_sends_a_premature_due_reminder(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => now()->addMonth()->format('Y-m-d'),
            'scheduled_time' => '09:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['purpose'] !== CiActivityScheduledReminder::PURPOSE_DUE_REMINDER;
        });
    }

    public function test_co_maker_schedule_does_not_leak_into_the_applicants_activity(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'Scope Maker',
            'first_name' => 'Scope',
            'last_name' => 'Maker',
        ]);
        $applicantActivity = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => null]);
        $coMakerActivity = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $coMakerActivity]), [
            'co_maker_id' => $coMaker->id,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '09:00',
            'expected_updated_at' => $coMakerActivity->updated_at->toISOString(),
        ])->assertOk();

        $this->assertNull($applicantActivity->fresh()->scheduled_at);
        $this->assertSame(ActivityStatus::Pending, $applicantActivity->fresh()->status);
    }

    // ==================================================
    // Purpose-specific wording: schedule_created / schedule_changed / due_reminder
    // must be unmistakably distinct.
    // ==================================================

    public function test_schedule_created_notification_has_clear_activity_scheduled_title_and_wording(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-02',
            'scheduled_time' => '08:30',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($barangay) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED
                && $data['title'] === 'Activity Scheduled'
                && $data['message'] === $barangay->name.' has been scheduled for Sep 2, 2026 at 8:30 AM.';
        });
    }

    public function test_schedule_changed_notification_has_clear_schedule_updated_title_and_wording(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '10:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($barangay) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED
                && $data['title'] === 'Schedule Updated'
                && $data['message'] === $barangay->name.' has been rescheduled to Sep 3, 2026 at 10:00 AM.';
        });
    }

    public function test_due_reminder_notification_has_clear_ci_activity_due_title_and_wording(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $dueAt = Carbon::parse('2026-09-01 19:50:00', config('cims.display_timezone'))->utc();
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $dueAt,
            'scheduled_has_time' => true,
        ]);

        Carbon::setTestNow($dueAt);
        try {
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($barangay) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
                && $data['title'] === 'CI Activity Due'
                && $data['message'] === $barangay->name.' is due now.';
        });
    }

    public function test_schedule_created_and_due_reminder_titles_are_never_the_same(): void
    {
        $activity = new CiActivity([
            'client_folder_id' => 1,
            'name' => 'Barangay Check',
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $created = (new CiActivityScheduledReminder($activity, CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED))->toArray($activity);
        $changed = (new CiActivityScheduledReminder($activity, CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED))->toArray($activity);
        $due = (new CiActivityScheduledReminder($activity, CiActivityScheduledReminder::PURPOSE_DUE_REMINDER))->toArray($activity);

        $titles = [$created['title'], $changed['title'], $due['title']];
        $this->assertSame(3, count(array_unique($titles)), 'all three purposes must render visibly distinct titles');
        $this->assertNotSame($created['message'], $due['message']);
        $this->assertStringNotContainsString('Due', $created['title']);
        $this->assertStringNotContainsString('Scheduled', $due['title']);
    }

    public function test_date_only_schedule_created_message_does_not_show_a_fabricated_time(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($barangay) {
            $data = $notification->toArray($notification);

            return $data['message'] === $barangay->name.' has been scheduled for Sep 3, 2026.'
                && ! str_contains($data['message'], '8:00 AM')
                && ! str_contains($data['message'], 'at ');
        });
    }

    public function test_date_only_due_reminder_still_fires_at_the_canonical_eight_am(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $eightAm = Carbon::parse('2026-09-03 08:00:00', config('cims.display_timezone'))->utc();
        $neighbor = $this->activity($folder, $creator, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $eightAm,
            'scheduled_has_time' => false,
        ]);

        Carbon::setTestNow($eightAm);
        try {
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($neighbor) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
                && $data['message'] === $neighbor->name.' is due today.';
        });
    }

    public function test_due_reminder_command_does_not_duplicate_on_repeated_ticks(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $dueAt = now()->subMinute();
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $dueAt,
            'scheduled_has_time' => true,
        ]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
        $this->assertNotNull($barangay->fresh()->reminder_sent_at);
    }

    public function test_reschedule_prevents_the_old_due_time_from_ever_firing(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $oldDueAt = now()->subMinute();
        $barangay = $this->activity($folder, $creator, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $oldDueAt,
            'scheduled_has_time' => true,
        ]);

        // Reschedule to the future before any scheduler tick ever observes the old due time.
        $this->actingAs($creator)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '09:30',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        Notification::assertNotSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER;
        });
    }

    // ==================================================
    // Local scheduler auto-start (composer.json)
    // ==================================================

    public function test_composer_dev_script_auto_starts_the_scheduler_alongside_existing_processes(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $devScript = implode(' ', $composer['scripts']['dev']);

        $this->assertStringContainsString('php artisan schedule:work', $devScript);
        $this->assertStringContainsString('php artisan serve', $devScript);
        $this->assertStringContainsString('php artisan queue:listen', $devScript);
        $this->assertStringContainsString('npm run dev', $devScript);
        $this->assertStringContainsString('scheduler', $devScript);
        // Laravel Pail requires the pcntl extension, unavailable on Windows local dev — the
        // working Windows setup intentionally excludes it from `composer run dev`.
        $this->assertStringNotContainsString('php artisan pail', $devScript);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, array $overrides = []): CiActivity
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
