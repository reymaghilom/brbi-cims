<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CiActivityDefaultTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_barangay_and_neighbor_open_trackers_without_redundant_view_or_mandatory_delete_actions(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-default-check-open="'.$barangay->id.'"', false)
            ->assertSee('data-default-check-open="'.$neighbor->id.'"', false)
            ->assertSee('id="default-check-tracker-modal"', false)
            ->assertSee('data-bank-coop-open="'.$bank->id.'"', false)
            ->assertSee('data-asset-check-open="'.$asset->id.'"', false)
            ->assertDontSee('title="View"', false)
            ->assertDontSee('aria-label="View '.$barangay->name.'"', false)
            ->assertDontSee('aria-label="View '.$neighbor->name.'"', false)
            ->assertDontSee('aria-label="View '.$bank->name.'"', false)
            ->assertDontSee('aria-label="View '.$asset->name.'"', false)
            ->assertDontSee('id="delete-activity-'.$barangay->id.'"', false)
            ->assertDontSee('id="delete-activity-'.$neighbor->id.'"', false)
            ->assertSee('id="delete-activity-'.$bank->id.'"', false)
            ->assertSee('id="delete-activity-'.$asset->id.'"', false);

        foreach ([$barangay, $neighbor] as $default) {
            $tracker = $this->get(route('client-folders.activities.default-check.show', [$folder, $default]));
            $tracker->assertOk()
                ->assertSee('data-default-check-modal-source', false)
                ->assertSee('data-default-check-activity-id="'.$default->id.'"', false)
                ->assertSee('Applicant: '.$folder->display_name)
                ->assertSee($default->name)
                ->assertSee('Schedule / Follow-up Date')
                ->assertSee('Short Remarks')
                ->assertSee('Updated By')
                ->assertSee('Mark '.$default->name.' as completed?');
        }
    }

    public function test_default_tracker_status_and_optional_schedule_rules_use_timezone_and_reject_time_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $activity->update(['scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Pending, null, null, 'Pending remarks.'))->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled))->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled, '2026-09-05'))->assertRedirect();
        $activity->refresh();
        $this->assertTrue($activity->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-05 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled, '2026-09-06', '14:30'))->assertRedirect();
        $activity->refresh();
        $this->assertTrue($activity->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-06 14:30', 'Asia/Manila')->utc()));
        $this->assertTrue($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::FollowUp))->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::FollowUp, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::FollowUp, '2026-09-07', '09:15'))->assertRedirect();
        $activity->refresh();
        $baseline = $activity->scheduled_at->copy();
        $this->assertTrue($activity->scheduled_has_time);

        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::FollowUp, null, '10:45'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_time');
        $this->assertTrue($activity->fresh()->scheduled_at->equalTo($baseline));
    }

    public function test_confirmed_default_completion_clears_schedule_and_records_actual_actor_audit_and_creator(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $activity->update([
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now(),
        ]);

        $this->actingAs($updater)->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(
            ActivityStatus::Completed,
            '2026-09-08',
            '15:00',
            'Completed after confirmation.',
        ))->assertOk()->assertJson(['updated' => true]);

        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);
        $this->assertNull($activity->reminder_sent_at);
        $this->assertNotNull($activity->completed_at);
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($updater->id, $activity->updated_by);
        $this->assertSame('Completed after confirmation.', $activity->remarks);

        $audit = AuditLog::query()->where('action', 'ci_activity.completed')->sole();
        $this->assertSame($updater->id, $audit->user_id);
        $this->assertSame($folder->id, $audit->client_folder_id);
        $this->assertSame($activity->id, (int) data_get($audit->metadata, 'activity_id'));
        $this->assertSame(ActivityStatus::Completed->value, data_get($audit->metadata, 'status'));

        $this->actingAs($updater)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Neighbor Check completed')
            ->assertSee($updater->full_name);
    }

    public function test_default_tracker_and_updates_enforce_exact_applicant_co_maker_and_folder_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $makerActivity = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $makerA->id);

        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $applicant]))->assertOk();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $applicant, 'person' => 'co-maker', 'co_maker_id' => $makerA->id]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $makerActivity]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $makerActivity, 'person' => 'co-maker', 'co_maker_id' => $makerA->id]))
            ->assertOk()->assertSee('Co-Maker: '.$makerA->full_name);
        $this->get(route('client-folders.activities.default-check.show', [$folder, $makerActivity, 'person' => 'co-maker', 'co_maker_id' => $makerB->id]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$otherFolder, $applicant]))->assertNotFound();

        $this->put(route('client-folders.activities.update', [$folder, $applicant]), array_replace($this->payload(ActivityStatus::Completed), ['co_maker_id' => $makerA->id]))->assertForbidden();
        $this->put(route('client-folders.activities.update', [$folder, $makerActivity]), $this->payload(ActivityStatus::Completed))->assertForbidden();
        $this->put(route('client-folders.activities.update', [$folder, $makerActivity]), array_replace($this->payload(ActivityStatus::Completed), ['co_maker_id' => $makerB->id]))->assertForbidden();
        $this->put(route('client-folders.activities.update', [$otherFolder, $applicant]), $this->payload(ActivityStatus::Completed))->assertNotFound();
        $this->assertSame(ActivityStatus::Pending, $applicant->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $makerActivity->fresh()->status);
    }

    private function payload(ActivityStatus $status, ?string $date = null, ?string $time = null, ?string $remarks = null): array
    {
        return [
            'co_maker_id' => null,
            'status' => $status->value,
            'scheduled_at' => $date,
            'scheduled_time' => $time,
            'remarks' => $remarks,
        ];
    }

    private function activity(ClientFolder $folder, User $creator, string $code, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $name, 2);

        return CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}
