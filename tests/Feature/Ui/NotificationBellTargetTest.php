<?php

namespace Tests\Feature\Ui;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationBellTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_bank_target_with_null_parent_schedule_does_not_crash_the_layout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $bank = $this->bankActivity($folder, $creator); // parent scheduled_at stays NULL
            $target = $this->bankTarget($bank, $creator, [
                'institution_name' => 'BDO',
                'branch_location' => 'Carmen Branch',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(),
                'scheduled_has_time' => true,
            ]);
            $this->notifyTarget($creator, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, 'BDO – Carmen Branch', $target->scheduled_at, true);

            $this->assertNull($bank->fresh()->scheduled_at);

            $response = $this->actingAs($creator)->get(route('home'));

            $response->assertOk();
            $response->assertSee('BDO – Carmen Branch');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_asset_target_with_null_parent_schedule_does_not_crash_the_layout(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $asset = $this->assetActivity($folder, $creator); // parent scheduled_at stays NULL
            $target = $this->assetTarget($asset, $creator, [
                'office_location' => 'Land',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 10:30:00', config('cims.display_timezone'))->utc(),
                'scheduled_has_time' => true,
            ]);
            $this->notifyTarget($creator, $asset, $target, CiActivityScheduledReminder::TARGET_TYPE_ASSET, $target->targetLabel(), $target->scheduled_at, true);

            $this->assertNull($asset->fresh()->scheduled_at);

            $response = $this->actingAs($creator)->get(route('home'));

            $response->assertOk();
            $response->assertSee('City Assessor — Land');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_bank_and_asset_target_notifications_increment_the_unread_badge_independently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $bank = $this->bankActivity($folder, $creator);
            $bankTarget = $this->bankTarget($bank, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $asset = $this->assetActivity($folder, $creator);
            $assetTarget = $this->assetTarget($asset, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 10:30:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($creator, $bank, $bankTarget, CiActivityScheduledReminder::TARGET_TYPE_BANK, $bankTarget->targetLabel(), $bankTarget->scheduled_at, true);
            $this->notifyTarget($creator, $asset, $assetTarget, CiActivityScheduledReminder::TARGET_TYPE_ASSET, $assetTarget->targetLabel(), $assetTarget->scheduled_at, true);

            $content = $this->actingAs($creator)->get(route('home'))->assertOk()->getContent();

            $this->assertStringContainsString('data-scheduled-today-count>2</span>', $content);
            $this->assertSame(2, substr_count($content, 'data-scheduled-today-item='));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_historical_notification_missing_title_and_target_fields_does_not_crash(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $activity = $this->bankActivity($folder, $creator);
            $activity->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc()]);

            // Simulates a pre-upgrade row: no title/message/target_type/target_id keys at all.
            $creator->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => CiActivityScheduledReminder::class,
                'data' => [
                    'ci_activity_id' => $activity->id,
                    'client_folder_id' => $folder->id,
                    'scheduled_at' => $activity->scheduled_at->toISOString(),
                ],
                'read_at' => null,
            ]);

            $response = $this->actingAs($creator)->get(route('home'));
            $response->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_malformed_target_like_payload_with_unknown_target_type_does_not_crash(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $activity = $this->bankActivity($folder, $creator);

            $creator->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => CiActivityScheduledReminder::class,
                'data' => [
                    'ci_activity_id' => $activity->id,
                    'client_folder_id' => $folder->id,
                    'target_type' => 'something_unrecognized',
                    'target_id' => 'not-numeric',
                    'scheduled_at' => null,
                ],
                'read_at' => null,
            ]);

            $response = $this->actingAs($creator)->get(route('home'));
            $response->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rendering_the_bell_creates_no_new_notification_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $bank = $this->bankActivity($folder, $creator);
            $target = $this->bankTarget($bank, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($creator, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true);

            $before = DB::table('notifications')->count();
            $this->actingAs($creator)->get(route('home'))->assertOk();
            $this->actingAs($creator)->get(route('home'))->assertOk();
            $after = DB::table('notifications')->count();

            $this->assertSame($before, $after);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_opening_the_bell_does_not_mark_target_notifications_read(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $bank = $this->bankActivity($folder, $creator);
            $target = $this->bankTarget($bank, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($creator, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true);

            $this->actingAs($creator)->get(route('home'))->assertOk();

            $this->assertNull($creator->notifications()->sole()->read_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_clicking_a_bank_target_notification_preserves_exact_person_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Scope Maker', 'first_name' => 'Scope', 'last_name' => 'Maker']);
            $bank = $this->bankActivity($folder, $creator, 0, $coMaker->id);
            $target = $this->bankTarget($bank, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($creator, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true, $coMaker->id);
            $notification = $creator->notifications()->sole();

            $this->actingAs($creator)
                ->post(route('notifications.ci-activities.read', $notification->id))
                ->assertRedirect(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]));
            $this->assertNotNull($notification->fresh()->read_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_parent_notifications_continue_to_work_alongside_target_notifications(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $creator = User::factory()->create();
            $folder = $this->folderFor($creator);
            $barangayDefinition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
            $barangay = CiActivity::create([
                'client_folder_id' => $folder->id,
                'activity_definition_id' => $barangayDefinition->id,
                'name' => 'Barangay Check',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 19:50:00', config('cims.display_timezone'))->utc(),
                'scheduled_has_time' => true,
                'creator_id' => $creator->id,
            ]);
            $creator->notify(new CiActivityScheduledReminder($barangay));

            $bank = $this->bankActivity($folder, $creator, 1);
            $target = $this->bankTarget($bank, $creator, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($creator, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true);

            $content = $this->actingAs($creator)->get(route('home'))->assertOk()->getContent();

            $this->assertStringContainsString('data-scheduled-today-count>2</span>', $content);
            $this->assertStringContainsString('Barangay Check', $content);
            $this->assertStringContainsString($target->targetLabel(), $content);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function notifyTarget(User $creator, CiActivity $activity, $target, string $targetType, string $label, ?Carbon $scheduledAt, bool $scheduledHasTime, ?int $coMakerId = null): void
    {
        if ($coMakerId !== null) {
            $activity->forceFill(['co_maker_id' => $coMakerId])->save();
        }

        $creator->notify(new CiActivityScheduledReminder(
            $activity,
            CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED,
            $targetType,
            $target->id,
            $label,
            $scheduledAt,
            $scheduledHasTime,
        ));
    }

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
