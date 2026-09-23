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
use App\Services\Notifications\ProcessDueCiActivityReminders;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationBellReminderFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', config('cims.display_timezone'))->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_feed_poll_processes_due_supported_reminders_for_only_the_authenticated_creator(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'Exact Reminder Co-Maker',
            'first_name' => 'Exact Reminder',
            'last_name' => 'Co-Maker',
        ]);
        $barangay = $this->parentActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->parentActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id);
        $bank = $this->parentActivity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, $coMaker->id, ActivityStatus::Pending, null);
        $bankDue = $this->bankTarget($bank, $ci, 'Fallback Bank', now()->subMinute());
        $bankFuture = $this->bankTarget($bank, $ci, 'Future Bank', now()->addHour());
        $asset = $this->parentActivity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE, null, ActivityStatus::Pending, null);
        $assetDue = $this->assetTarget($asset, $ci, 'Fallback Property', now()->subMinute());
        $assetFuture = $this->assetTarget($asset, $ci, 'Future Property', now()->addHour());
        $otherFolder = $this->folderFor($otherCi);
        $otherReminder = $this->parentActivity($otherFolder, $otherCi, ActivityDefinition::BARANGAY_CHECK_CODE);

        $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

        $this->assertSame(4, $payload['unread_count']);
        $this->assertStringContainsString('Barangay Check', $payload['html']);
        $this->assertStringContainsString('Neighbor Check', $payload['html']);
        $this->assertStringContainsString('Applicant', $payload['html']);
        $this->assertStringContainsString('Co-Maker: Exact Reminder Co-Maker', $payload['html']);
        $this->assertStringContainsString('Fallback Bank', $payload['html']);
        $this->assertStringContainsString('Fallback Property', $payload['html']);
        $this->assertNotNull($barangay->fresh()->reminder_sent_at);
        $this->assertNotNull($neighbor->fresh()->reminder_sent_at);
        $this->assertNotNull($bankDue->fresh()->reminder_sent_at);
        $this->assertNotNull($assetDue->fresh()->reminder_sent_at);
        $this->assertNull($bankFuture->fresh()->reminder_sent_at);
        $this->assertNull($assetFuture->fresh()->reminder_sent_at);
        $this->assertNull($otherReminder->fresh()->reminder_sent_at);
        $this->assertSame(0, $otherCi->notifications()->count());
    }

    public function test_feed_processor_and_artisan_command_share_idempotent_backend_processing(): void
    {
        $ci = User::factory()->create();
        $activity = $this->parentActivity($this->folderFor($ci), $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk();
        $this->assertSame(0, app(ProcessDueCiActivityReminders::class)->process($ci));
        $this->artisan('ci-activities:send-reminders')
            ->expectsOutput('Sent 0 CI activity reminder(s).')
            ->assertSuccessful();
        Cache::forget('ci-activity-reminders:feed-user:'.$ci->id);
        $this->getJson(route('notifications.ci-activities.feed'))->assertOk();

        $dueNotifications = $ci->notifications()->get()->filter(
            fn ($notification): bool => data_get($notification->data, 'purpose') === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
        );
        $this->assertCount(1, $dueNotifications);
        $this->assertNotNull($activity->fresh()->reminder_sent_at);
    }

    public function test_feed_fallback_is_throttled_once_per_user_for_one_minute(): void
    {
        $ci = User::factory()->create();
        $processor = Mockery::mock(ProcessDueCiActivityReminders::class);
        $processor->shouldReceive('process')
            ->once()
            ->with(Mockery::on(fn (User $user): bool => $user->is($ci)))
            ->andReturn(0);
        $this->app->instance(ProcessDueCiActivityReminders::class, $processor);

        $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk();
        $this->getJson(route('notifications.ci-activities.feed'))->assertOk();

        $this->assertTrue(Cache::has('ci-activity-reminders:feed-user:'.$ci->id));
    }

    public function test_processing_failure_is_logged_without_breaking_the_existing_feed(): void
    {
        $ci = User::factory()->create();
        $activity = $this->parentActivity($this->folderFor($ci), $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $ci->notify(new CiActivityScheduledReminder($activity));
        $processor = Mockery::mock(ProcessDueCiActivityReminders::class);
        $processor->shouldReceive('process')->once()->andThrow(new RuntimeException('Internal reminder failure'));
        $this->app->instance(ProcessDueCiActivityReminders::class, $processor);
        Log::spy();

        $payload = $this->actingAs($ci)->getJson(route('notifications.ci-activities.feed'))->assertOk()->json();

        $this->assertSame(1, $payload['unread_count']);
        $this->assertStringContainsString('Barangay Check', $payload['html']);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Notification bell reminder fallback failed.'
                && $context['user_id'] === $ci->id
                && $context['exception'] instanceof RuntimeException
        );
    }

    private function parentActivity(
        ClientFolder $folder,
        User $creator,
        string $code,
        ?int $coMakerId = null,
        ActivityStatus $status = ActivityStatus::Scheduled,
        ?Carbon $scheduledAt = null,
    ): CiActivity {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => $status,
            'scheduled_at' => $scheduledAt ?? ($status === ActivityStatus::Scheduled ? now()->subMinute() : null),
            'scheduled_has_time' => true,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function bankTarget(CiActivity $activity, User $actor, string $name, Carbon $scheduledAt): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $name,
            'branch_location' => 'Exact Branch',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function assetTarget(CiActivity $activity, User $actor, string $location, Carbon $scheduledAt): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => $location,
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
        ]);
    }
}
