<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CiActivityTargetReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // Bank / Coop target reminders
    // ==================================================

    public function test_new_bank_target_scheduled_notifies_with_the_correct_title_and_institution_identity(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);

        $this->actingAs($creator)->post(route('client-folders.activities.bank-targets.store', [$folder, $bank]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'branch_location' => 'Carmen Branch',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-02',
            'scheduled_time' => '09:00',
        ])->assertRedirect();

        $target = $bank->bankTargets()->sole();
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($target) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED
                && $data['title'] === 'Bank / Coop Check Scheduled'
                && $data['target_type'] === 'bank_target'
                && $data['target_id'] === $target->id
                && $data['message'] === 'BDO – Carmen Branch has been scheduled for Sep 2, 2026 at 9:00 AM.';
        });
    }

    public function test_bank_target_without_branch_displays_institution_only(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);

        $this->actingAs($creator)->post(route('client-folders.activities.bank-targets.store', [$folder, $bank]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            'institution_name' => 'LandBank',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
        ])->assertRedirect();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['message'] === 'LandBank has been scheduled for Sep 3, 2026.';
        });
    }

    public function test_bank_target_reschedule_sends_schedule_updated_and_invalidates_the_old_due_time(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $target = $this->bankTarget($bank, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($creator)->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $target]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $target->institution_name,
            'branch_location' => $target->branch_location,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '14:00',
        ])->assertRedirect();

        $this->assertNull($target->fresh()->reminder_sent_at);
        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED
                && $notification->toArray($notification)['title'] === 'Bank / Coop Schedule Updated';
        });
    }

    public function test_bank_target_no_change_save_sends_no_notification(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $target = $this->bankTarget($bank, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-02 09:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $target]), [
            'co_maker_id' => '',
            'inquiry_type' => $target->inquiry_type,
            'institution_name' => $target->institution_name,
            'branch_location' => $target->branch_location,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-02',
            'scheduled_time' => '09:00',
        ])->assertRedirect();

        Notification::assertNothingSentTo($creator);
    }

    public function test_bank_target_exact_time_due_reminder_names_the_exact_institution(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $dueAt = now()->subMinute();
        $target = $this->bankTarget($bank, $creator, [
            'institution_name' => 'BDO',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $dueAt,
            'scheduled_has_time' => true,
        ]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($target) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
                && $data['title'] === 'Bank / Coop Check Due'
                && $data['target_id'] === $target->id
                && $data['message'] === 'BDO – Carmen Branch is due now.';
        });
        $this->assertNotNull($target->fresh()->reminder_sent_at);
    }

    public function test_bank_target_date_only_due_reminder_fires_at_the_canonical_eight_am(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $eightAm = Carbon::parse('2026-09-03 08:00:00', config('cims.display_timezone'))->utc();
        $target = $this->bankTarget($bank, $creator, [
            'institution_name' => 'LandBank',
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

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['message'] === 'LandBank is due today.';
        });
    }

    public function test_bank_target_editor_does_not_become_recipient(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);

        $this->actingAs($otherCi)->post(route('client-folders.activities.bank-targets.store', [$folder, $bank]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BPI',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-02',
            'scheduled_time' => '09:00',
        ])->assertRedirect();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class);
        Notification::assertNotSentTo($otherCi, CiActivityScheduledReminder::class);
    }

    public function test_multiple_bank_targets_notify_independently_of_each_other(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $due = $this->bankTarget($bank, $creator, ['institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'scheduled_has_time' => true]);
        $notYetDue = $this->bankTarget($bank, $creator, ['institution_name' => 'BPI', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        $this->assertNotNull($due->fresh()->reminder_sent_at);
        $this->assertNull($notYetDue->fresh()->reminder_sent_at);
        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
    }

    public function test_bank_target_reminder_command_does_not_duplicate_on_repeated_ticks(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $this->bankTarget($bank, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'scheduled_has_time' => true]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
    }

    public function test_bank_target_clearing_schedule_cancels_the_future_due_reminder(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $target = $this->bankTarget($bank, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $target]), [
            'co_maker_id' => '',
            'inquiry_type' => $target->inquiry_type,
            'institution_name' => $target->institution_name,
            'branch_location' => $target->branch_location,
            'status' => 'pending',
        ])->assertRedirect();

        $fresh = $target->fresh();
        $this->assertNull($fresh->scheduled_at);
        $this->assertNull($fresh->reminder_sent_at);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        Notification::assertNothingSentTo($creator);
    }

    public function test_completing_a_bank_target_prevents_a_stale_future_reminder(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bank = $this->bankActivity($folder, $creator);
        $target = $this->bankTarget($bank, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->patch(route('client-folders.activities.bank-targets.complete', [$folder, $bank, $target]), [
            'co_maker_id' => '',
        ])->assertRedirect();

        $fresh = $target->fresh();
        $this->assertSame(ActivityStatus::Completed, $fresh->status);
        $this->assertNull($fresh->scheduled_at);
        $this->assertNull($fresh->reminder_sent_at);
    }

    public function test_bank_target_cross_parent_isolation(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $bankA = $this->bankActivity($folder, $creator, 0);
        $bankB = $this->bankActivity($folder, $creator, 1);
        $targetA = $this->bankTarget($bankA, $creator, ['institution_name' => 'A Bank']);

        $this->actingAs($creator)->put(route('client-folders.activities.bank-targets.update', [$folder, $bankB, $targetA]), [
            'co_maker_id' => '',
            'inquiry_type' => $targetA->inquiry_type,
            'institution_name' => 'Tampered',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-02',
        ])->assertNotFound();

        $this->assertSame('A Bank', $targetA->fresh()->institution_name);
    }

    public function test_bank_target_co_maker_schedule_stays_scoped_to_the_exact_co_maker(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Scope Maker', 'first_name' => 'Scope', 'last_name' => 'Maker']);
        $coMakerBank = $this->bankActivity($folder, $creator, 0, $coMaker->id);

        $this->actingAs($creator)->post(route('client-folders.activities.bank-targets.store', [$folder, $coMakerBank]), [
            'co_maker_id' => $coMaker->id,
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'Metrobank',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-02',
            'scheduled_time' => '09:00',
        ])->assertRedirect();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($coMaker) {
            $data = $notification->toArray($notification);

            return str_contains((string) $data['url'], 'co_maker_id='.$coMaker->id);
        });
    }

    // ==================================================
    // Asset target reminders
    // ==================================================

    public function test_new_asset_target_scheduled_notifies_with_the_correct_title_and_label(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);

        $this->actingAs($creator)->post(route('client-folders.activities.asset-targets.store', [$folder, $asset]), [
            'co_maker_id' => '',
            'assessor_type' => 'city_assessor',
            'office_location' => 'Land',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-04',
            'scheduled_time' => '10:30',
        ])->assertRedirect();

        $target = $asset->assetTargets()->sole();
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($target) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED
                && $data['title'] === 'Asset Check Scheduled'
                && $data['target_type'] === 'asset_target'
                && $data['target_id'] === $target->id
                && $data['message'] === 'City Assessor — Land has been scheduled for Sep 4, 2026 at 10:30 AM.';
        });
    }

    public function test_asset_target_date_only_schedule_confirmation_hides_a_fabricated_time(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);

        $this->actingAs($creator)->post(route('client-folders.activities.asset-targets.store', [$folder, $asset]), [
            'co_maker_id' => '',
            'assessor_type' => 'city_assessor',
            'office_location' => 'House & Lot',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-05',
        ])->assertRedirect();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            $message = $notification->toArray($notification)['message'];

            return $message === 'City Assessor — House & Lot has been scheduled for Sep 5, 2026.'
                && ! str_contains($message, '8:00 AM');
        });
    }

    public function test_asset_target_reschedule_sends_schedule_updated_and_invalidates_the_old_due_time(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $target = $this->assetTarget($asset, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($creator)->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $target]), [
            'co_maker_id' => '',
            'assessor_type' => $target->assessor_type,
            'office_location' => $target->office_location,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-05',
            'scheduled_time' => '13:30',
        ])->assertRedirect();

        $this->assertNull($target->fresh()->reminder_sent_at);
        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return $notification->toArray($notification)['title'] === 'Asset Schedule Updated';
        });
    }

    public function test_asset_target_no_change_save_sends_no_notification(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $target = $this->assetTarget($asset, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-04 10:30:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $target]), [
            'co_maker_id' => '',
            'assessor_type' => $target->assessor_type,
            'office_location' => $target->office_location,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-04',
            'scheduled_time' => '10:30',
        ])->assertRedirect();

        Notification::assertNothingSentTo($creator);
    }

    public function test_asset_target_exact_time_due_reminder_names_the_exact_asset(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $target = $this->assetTarget($asset, $creator, [
            'office_location' => 'Land',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
            'scheduled_has_time' => true,
        ]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) use ($target) {
            $data = $notification->toArray($notification);

            return $data['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
                && $data['title'] === 'Asset Check Due'
                && $data['target_id'] === $target->id
                && str_ends_with((string) $data['message'], 'Land is due now.');
        });
    }

    public function test_asset_target_date_only_due_reminder_fires_at_the_canonical_eight_am(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $eightAm = Carbon::parse('2026-09-06 08:00:00', config('cims.display_timezone'))->utc();
        $this->assetTarget($asset, $creator, [
            'office_location' => 'Warehouse',
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

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function ($notification) {
            return str_ends_with((string) $notification->toArray($notification)['message'], 'Warehouse is due today.');
        });
    }

    public function test_asset_target_editor_does_not_become_recipient(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);

        $this->actingAs($otherCi)->post(route('client-folders.activities.asset-targets.store', [$folder, $asset]), [
            'co_maker_id' => '',
            'assessor_type' => 'city_assessor',
            'office_location' => 'Vehicle',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-04',
            'scheduled_time' => '10:30',
        ])->assertRedirect();

        Notification::assertSentTo($creator, CiActivityScheduledReminder::class);
        Notification::assertNotSentTo($otherCi, CiActivityScheduledReminder::class);
    }

    public function test_multiple_asset_targets_notify_independently_of_each_other(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $due = $this->assetTarget($asset, $creator, ['office_location' => 'Land', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'scheduled_has_time' => true]);
        $notYetDue = $this->assetTarget($asset, $creator, ['office_location' => 'Vehicle', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        $this->assertNotNull($due->fresh()->reminder_sent_at);
        $this->assertNull($notYetDue->fresh()->reminder_sent_at);
        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
    }

    public function test_asset_target_reminder_command_does_not_duplicate_on_repeated_ticks(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $this->assetTarget($asset, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'scheduled_has_time' => true]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($creator, CiActivityScheduledReminder::class, 1);
    }

    public function test_asset_target_clearing_schedule_cancels_the_future_due_reminder(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $target = $this->assetTarget($asset, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $target]), [
            'co_maker_id' => '',
            'assessor_type' => $target->assessor_type,
            'office_location' => $target->office_location,
            'status' => 'pending',
        ])->assertRedirect();

        $fresh = $target->fresh();
        $this->assertNull($fresh->scheduled_at);
        $this->assertNull($fresh->reminder_sent_at);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        Notification::assertNothingSentTo($creator);
    }

    public function test_completing_an_asset_target_prevents_a_stale_future_reminder(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $target = $this->assetTarget($asset, $creator, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
        ]);

        $this->actingAs($creator)->patch(route('client-folders.activities.asset-targets.complete', [$folder, $asset, $target]), [
            'co_maker_id' => '',
        ])->assertRedirect();

        $fresh = $target->fresh();
        $this->assertSame(ActivityStatus::Completed, $fresh->status);
        $this->assertNull($fresh->scheduled_at);
        $this->assertNull($fresh->reminder_sent_at);
    }

    public function test_asset_target_cross_folder_isolation(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $folder = $this->folderFor($creator);
        $otherFolder = $this->folderFor($creator);
        $asset = $this->assetActivity($folder, $creator);
        $foreignAsset = $this->assetActivity($otherFolder, $creator);
        $target = $this->assetTarget($asset, $creator, ['office_location' => 'Land']);

        $this->actingAs($creator)->put(route('client-folders.activities.asset-targets.update', [$otherFolder, $foreignAsset, $target]), [
            'co_maker_id' => '',
            'assessor_type' => $target->assessor_type,
            'office_location' => 'Tampered',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-04',
        ])->assertNotFound();

        $this->assertSame('Land', $target->fresh()->office_location);
    }

    // ==================================================
    // Helpers
    // ==================================================

    private function bankActivity(ClientFolder $folder, User $creator, int $offset = 0, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name.($offset > 0 ? ' '.$offset : ''),
            'creator_id' => $creator->id,
        ]);
    }

    private function assetActivity(ClientFolder $folder, User $creator, int $offset = 0): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::ASSET_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name.($offset > 0 ? ' '.$offset : ''),
            'creator_id' => $creator->id,
        ]);
    }

    private function bankTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityBankTarget
    {
        return $activity->bankTargets()->create(array_merge([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    private function assetTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create(array_merge([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Land',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
