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

class NotificationBellFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_authenticated_ci_can_request_the_feed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();

            $response = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'));

            $response->assertOk()->assertJsonStructure(['html', 'unread_count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $this->get(route('notifications.ci-activities.feed'))->assertRedirect(route('login'));
    }

    public function test_feed_contains_a_parent_notification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            $barangayDefinition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
            $barangay = CiActivity::create([
                'client_folder_id' => $folder->id,
                'activity_definition_id' => $barangayDefinition->id,
                'name' => 'Barangay Check',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 19:50:00', config('cims.display_timezone'))->utc(),
                'scheduled_has_time' => true,
                'creator_id' => $ci->id,
            ]);
            $ci->notify(new CiActivityScheduledReminder($barangay));

            $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

            $this->assertStringContainsString('Barangay Check', $payload['html']);
            $this->assertSame(1, $payload['unread_count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_feed_contains_an_individual_bank_target_notification_with_null_parent_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            $bank = $this->bankActivity($folder, $ci);
            $target = $this->bankTarget($bank, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($ci, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true);

            $this->assertNull($bank->fresh()->scheduled_at);

            $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

            $this->assertStringContainsString($target->targetLabel(), $payload['html']);
            $this->assertSame(1, $payload['unread_count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_feed_contains_an_individual_asset_target_notification_with_null_parent_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            $asset = $this->assetActivity($folder, $ci);
            $target = $this->assetTarget($asset, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 10:30:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($ci, $asset, $target, CiActivityScheduledReminder::TARGET_TYPE_ASSET, $target->targetLabel(), $target->scheduled_at, true);

            $this->assertNull($asset->fresh()->scheduled_at);

            $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

            $this->assertStringContainsString($target->targetLabel(), $payload['html']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unread_count_reflects_multiple_current_notifications(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            $bank = $this->bankActivity($folder, $ci);
            $bankTarget = $this->bankTarget($bank, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $asset = $this->assetActivity($folder, $ci);
            $assetTarget = $this->assetTarget($asset, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 10:30:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($ci, $bank, $bankTarget, CiActivityScheduledReminder::TARGET_TYPE_BANK, $bankTarget->targetLabel(), $bankTarget->scheduled_at, true);
            $this->notifyTarget($ci, $asset, $assetTarget, CiActivityScheduledReminder::TARGET_TYPE_ASSET, $assetTarget->targetLabel(), $assetTarget->scheduled_at, true);

            $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

            $this->assertSame(2, $payload['unread_count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_zero_unread_returns_no_badge_worthy_count(): void
    {
        $ci = User::factory()->create();

        $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

        $this->assertSame(0, $payload['unread_count']);
    }

    public function test_more_than_nine_unread_is_reported_as_the_raw_count_for_client_side_nine_plus_formatting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            for ($i = 1; $i <= 10; $i++) {
                $definition = ActivityDefinition::factory()->create();
                $activity = CiActivity::create([
                    'client_folder_id' => $folder->id,
                    'activity_definition_id' => $definition->id,
                    'name' => "Overflow Activity {$i}",
                    'status' => ActivityStatus::Scheduled,
                    'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc()->addMinutes($i),
                    'scheduled_has_time' => true,
                    'creator_id' => $ci->id,
                ]);
                $ci->notify(new CiActivityScheduledReminder($activity));
            }

            $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

            $this->assertSame(10, $payload['unread_count']);
            $this->assertSame(5, substr_count($payload['html'], 'data-scheduled-today-item='));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_creator_only_and_co_maker_isolation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $otherCi = User::factory()->create();
            $folder = $this->folderFor($ci);
            $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Scope Maker', 'first_name' => 'Scope', 'last_name' => 'Maker']);
            $bank = $this->bankActivity($folder, $ci, 0, $coMaker->id);
            $target = $this->bankTarget($bank, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($ci, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true);

            $otherPayload = $this->actingAs($otherCi)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();
            $this->assertSame(0, $otherPayload['unread_count']);

            $creatorPayload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();
            $this->assertStringContainsString('Co-Maker: Scope Maker', $creatorPayload['html']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_malformed_historical_notification_does_not_crash_the_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            $activity = $this->bankActivity($folder, $ci);

            $ci->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => CiActivityScheduledReminder::class,
                'data' => [
                    'ci_activity_id' => $activity->id,
                    'client_folder_id' => $folder->id,
                    'target_type' => 'unknown',
                    'target_id' => 'nope',
                    'scheduled_at' => null,
                ],
                'read_at' => null,
            ]);

            $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_feed_request_creates_no_notification_rows_and_marks_nothing_read(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));
        try {
            $ci = User::factory()->create();
            $folder = $this->folderFor($ci);
            $bank = $this->bankActivity($folder, $ci);
            $target = $this->bankTarget($bank, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
            $this->notifyTarget($ci, $bank, $target, CiActivityScheduledReminder::TARGET_TYPE_BANK, $target->targetLabel(), $target->scheduled_at, true);

            $before = DB::table('notifications')->count();
            $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk();
            $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk();
            $after = DB::table('notifications')->count();

            $this->assertSame($before, $after);
            $this->assertNull($ci->notifications()->sole()->read_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ==================================================
    // Front-end wiring (static assertions on the shared layout script)
    // ==================================================

    public function test_bell_javascript_polls_approximately_every_thirty_seconds(): void
    {
        $content = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('window.setInterval(checkForUpdates, 30000)', $content);
    }

    public function test_bell_javascript_prevents_overlapping_requests(): void
    {
        $content = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('let requestInFlight = false;', $content);
        $this->assertStringContainsString('if (requestInFlight || document.hidden) return;', $content);
        $this->assertStringContainsString('requestInFlight = true;', $content);
        $this->assertStringContainsString('requestInFlight = false;', $content);
    }

    public function test_bell_javascript_skips_polling_while_hidden_and_rechecks_on_visible(): void
    {
        $content = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $content);
        $this->assertStringContainsString('if (!document.hidden) checkForUpdates();', $content);
    }

    public function test_bell_javascript_never_reloads_the_page(): void
    {
        $content = file_get_contents(resource_path('js/app.js'));
        $bellSectionStart = strpos($content, 'Header "Scheduled Today" bell');
        $this->assertNotFalse($bellSectionStart);
        $bellSection = substr($content, $bellSectionStart, 3000);

        $this->assertStringNotContainsString('.reload(', $bellSection);
        $this->assertStringNotContainsString('location.assign', $bellSection);
    }

    public function test_bell_javascript_does_not_introduce_websockets_or_sse(): void
    {
        $content = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('WebSocket', $content);
        $this->assertStringNotContainsString('EventSource', $content);
        $this->assertStringNotContainsString('Echo.', $content);
    }

    public function test_bell_javascript_swaps_only_the_panel_element_preserving_dropdown_open_state(): void
    {
        $content = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('current.replaceWith(fresh)', $content);
    }

    private function notifyTarget(User $creator, CiActivity $activity, $target, string $targetType, string $label, ?Carbon $scheduledAt, bool $scheduledHasTime): void
    {
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
