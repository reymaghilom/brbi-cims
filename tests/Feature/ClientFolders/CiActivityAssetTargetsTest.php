<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CiActivityAssetTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_asset_ui_is_conditional_repeatable_and_preserves_sparse_indexes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->assetDefinition();
        $index = route('client-folders.activities.index', $folder);

        $page = $this->actingAs($ci)->get($index);
        $page->assertOk()
            ->assertSee('data-code="'.ActivityDefinition::ASSET_CHECK_CODE.'"', false)
            ->assertSee('data-asset-targets-section', false)
            ->assertSee('Add Assessor')
            ->assertSee('Assessor Office / Type')
            ->assertSee('Select assessor office')
            ->assertSee('addingAssetCheck', false);
        $this->assertMatchesRegularExpression('/data-asset-targets-section[^>]*\shidden(?:\s|>)/', $page->getContent());

        $selected = $this->withSession(['_old_input' => $this->payload([
            0 => $this->target('city_assessor', 'Cagayan de Oro', ActivityStatus::Pending),
            2 => $this->target('provincial_assessor', 'Misamis Oriental', ActivityStatus::Scheduled, '2026-09-04'),
            5 => $this->target('other', 'Registry of Deeds — CDO', ActivityStatus::Completed),
        ])])->get($index);
        $selected->assertOk()->assertSee('data-asset-targets-section', false);
        $this->assertDoesNotMatchRegularExpression('/data-asset-targets-section[^>]*\shidden(?:\s|>)/', $selected->getContent());

        $this->post(route('client-folders.activities.store', $folder), $this->payload([
            0 => $this->target('city_assessor', 'Cagayan de Oro', ActivityStatus::Pending),
            2 => $this->target('provincial_assessor', 'Misamis Oriental', ActivityStatus::Scheduled, '2026-09-04'),
            5 => $this->target('other', 'Registry of Deeds — CDO', ActivityStatus::Completed),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $activity = $folder->activities()->sole();
        $this->assertSame($definition->id, $activity->activity_definition_id);
        $this->assertSame(3, $activity->assetTargets()->count());
        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);
    }

    public function test_status_derivation_and_schedule_normalization_follow_asset_target_state(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $this->payload([
            $this->target('city_assessor', 'Pending Office', ActivityStatus::Pending, '2026-09-03', '09:00'),
            $this->target('provincial_assessor', 'Scheduled Date', ActivityStatus::Scheduled, '2026-09-04'),
            $this->target('other', 'Scheduled Time', ActivityStatus::Scheduled, '2026-09-05', '14:30'),
            $this->target('city_assessor', 'Follow-up Date', ActivityStatus::FollowUp, '2026-09-07'),
            $this->target('provincial_assessor', 'Completed Office', ActivityStatus::Completed, '2026-09-06', '15:00'),
        ]))->assertSessionHasNoErrors();

        $activity = $folder->activities()->sole();
        $this->assertSame(ActivityStatus::FollowUp, $activity->status);
        $pending = $activity->assetTargets()->where('office_location', 'Pending Office')->sole();
        $completed = $activity->assetTargets()->where('office_location', 'Completed Office')->sole();
        $dateOnly = $activity->assetTargets()->where('office_location', 'Scheduled Date')->sole();
        $withTime = $activity->assetTargets()->where('office_location', 'Scheduled Time')->sole();
        $this->assertNull($pending->scheduled_at);
        $this->assertFalse($pending->scheduled_has_time);
        $this->assertNull($completed->scheduled_at);
        $this->assertFalse($completed->scheduled_has_time);
        $this->assertTrue($dateOnly->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-04 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($dateOnly->scheduled_has_time);
        $this->assertTrue($withTime->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-05 14:30', 'Asia/Manila')->utc()));
        $this->assertTrue($withTime->scheduled_has_time);

        $this->from(route('client-folders.activities.index', $folder))->post(route('client-folders.activities.store', $this->folderFor($ci)), $this->payload([
            $this->target('city_assessor', 'Forged Time Only', ActivityStatus::Scheduled, null, '09:30'),
        ]))->assertSessionHasErrors('asset_targets.0.scheduled_time');

        $scheduledWithoutDateFolder = $this->folderFor($ci);
        $this->from(route('client-folders.activities.index', $scheduledWithoutDateFolder))->post(route('client-folders.activities.store', $scheduledWithoutDateFolder), $this->payload([
            $this->target('municipal_assessor', 'Scheduled Without Date', ActivityStatus::Scheduled),
            $this->target('city_assessor', 'Follow-up Without Date', ActivityStatus::FollowUp),
        ]))->assertSessionHasErrors([
            'asset_targets.0.scheduled_at' => 'Please select a scheduled date.',
            'asset_targets.1.scheduled_at' => 'Please select a scheduled date.',
        ]);
        $this->assertSame(0, $scheduledWithoutDateFolder->activities()->count());

        $this->assertSame(ActivityStatus::Pending, CiActivityAssetTarget::deriveParentStatus([]));
        $this->assertSame(ActivityStatus::Completed, CiActivityAssetTarget::deriveParentStatus([ActivityStatus::Completed, ActivityStatus::Completed]));
        $this->assertSame(ActivityStatus::FollowUp, CiActivityAssetTarget::deriveParentStatus([ActivityStatus::Scheduled, ActivityStatus::FollowUp]));
    }

    public function test_applicant_and_exact_co_maker_ownership_is_inherited_from_parent(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $applicant = $this->activity($folder, $ci);
        $forA = $this->activity($folder, $ci, $makerA->id);
        $forB = $this->activity($folder, $ci, $makerB->id);
        $applicantTarget = $this->createTarget($applicant, $ci, 'Applicant City');
        $targetA = $this->createTarget($forA, $ci, 'Maker A City');

        $this->actingAs($ci)->get(route('client-folders.activities.asset-check.show', [$folder, $applicant]))
            ->assertOk()->assertSee('Applicant City')->assertDontSee('Maker A City');
        $this->get(route('client-folders.activities.asset-check.show', [$folder, $forA, 'person' => 'co-maker', 'co_maker_id' => $makerA->id]))
            ->assertOk()->assertSee('Maker A City')->assertDontSee('Applicant City');
        $this->get(route('client-folders.activities.asset-check.show', [$folder, $forA]))->assertNotFound();

        $payload = $this->target('city_assessor', 'Substituted', ActivityStatus::Pending) + ['co_maker_id' => $makerB->id];
        $this->put(route('client-folders.activities.asset-targets.update', [$folder, $forB, $targetA]), $payload)->assertNotFound();
        $this->put(route('client-folders.activities.asset-targets.update', [$folder, $forA, $applicantTarget]), $payload + ['co_maker_id' => $makerA->id])->assertNotFound();
        $this->assertSame('Maker A City', $targetA->fresh()->office_location);
    }

    public function test_cross_folder_target_substitution_is_not_found(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci);
        $otherActivity = $this->activity($otherFolder, $ci);
        $target = $this->createTarget($activity, $ci, 'Original Office');

        $this->actingAs($ci)->put(
            route('client-folders.activities.asset-targets.update', [$otherFolder, $otherActivity, $target]),
            $this->target('city_assessor', 'Substituted', ActivityStatus::Pending) + ['co_maker_id' => ''],
        )->assertNotFound();
    }

    public function test_crud_completion_audit_and_actor_fields_preserve_parent_and_unrelated_targets(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator);
        $first = $this->createTarget($activity, $creator, 'City Office');
        $second = $this->createTarget($activity, $creator, 'Province Office');

        $this->actingAs($updater)->put(route('client-folders.activities.asset-targets.update', [$folder, $activity, $first]),
            $this->target('city_assessor', 'Updated City Office', ActivityStatus::Scheduled, '2026-09-07', '10:15') + ['co_maker_id' => ''])
            ->assertRedirect();
        $this->assertSame($creator->id, $first->fresh()->created_by);
        $this->assertSame($updater->id, $first->fresh()->updated_by);

        $this->patch(route('client-folders.activities.asset-targets.complete', [$folder, $activity, $first]), ['co_maker_id' => ''])->assertRedirect();
        $this->assertSame(ActivityStatus::Completed, $first->fresh()->status);
        $this->assertNull($first->fresh()->scheduled_at);
        $this->assertSame(ActivityStatus::Pending, $second->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.asset_target_completed', 'user_id' => $updater->id]);

        $this->delete(route('client-folders.activities.asset-targets.destroy', [$folder, $activity, $first]), ['co_maker_id' => ''])->assertRedirect();
        $this->assertDatabaseHas('ci_activities', ['id' => $activity->id]);
        $this->delete(route('client-folders.activities.asset-targets.destroy', [$folder, $activity, $second]), ['co_maker_id' => ''])->assertRedirect();
        $this->assertDatabaseHas('ci_activities', ['id' => $activity->id, 'status' => ActivityStatus::Pending->value]);
        $this->assertDatabaseMissing('ci_activity_asset_targets', ['ci_activity_id' => $activity->id]);
        $this->assertSame(1, AuditLog::where('action', 'ci_activity.asset_target_completed')->count());
    }

    public function test_invalid_target_rolls_back_parent_and_non_asset_creation_stays_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $this->payload([
            $this->target('city_assessor', 'Valid Office', ActivityStatus::Pending),
            $this->target('provincial_assessor', '', ActivityStatus::Pending),
        ]))->assertSessionHasErrors('asset_targets.1.office_location');
        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_asset_targets', 0);

        $other = ActivityDefinition::where('code', 'barangay_check')->firstOrFail();
        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $other->id,
            'create_new_activity_type' => false,
            'status' => ActivityStatus::Pending->value,
            'remarks' => 'Existing flow',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Existing flow', $folder->activities()->sole()->remarks);
        $this->assertDatabaseCount('ci_activity_asset_targets', 0);
    }

    public function test_child_creation_exception_rolls_back_parent_and_prior_targets(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $creating = 0;
        CiActivityAssetTarget::creating(function () use (&$creating): void {
            $creating++;
            if ($creating === 2) {
                throw new \RuntimeException('Simulated assessor target persistence failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $this->payload([
                $this->target('city_assessor', 'First Office', ActivityStatus::Pending),
                $this->target('provincial_assessor', 'Failing Office', ActivityStatus::Pending),
            ]));
            $this->fail('The simulated child failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated assessor target persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_asset_targets', 0);
    }

    private function payload(array $targets): array
    {
        return ['activity_definition_id' => $this->assetDefinition()->id, 'create_new_activity_type' => false, 'asset_targets' => $targets];
    }

    private function target(string $type, string $location, ActivityStatus $status, ?string $date = null, ?string $time = null): array
    {
        return ['assessor_type' => $type, 'office_location' => $location, 'status' => $status->value, 'scheduled_at' => $date, 'scheduled_time' => $time, 'remarks' => $location.' remarks.'];
    }

    private function activity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        return CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $this->assetDefinition()->id, 'name' => 'Asset Check', 'status' => ActivityStatus::Pending, 'creator_id' => $creator->id, 'updated_by' => $creator->id]);
    }

    private function createTarget(CiActivity $activity, User $actor, string $location): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create(['assessor_type' => 'city_assessor', 'office_location' => $location, 'status' => ActivityStatus::Pending, 'scheduled_has_time' => false, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }

    private function assetDefinition(): ActivityDefinition
    {
        return ActivityDefinition::where('code', ActivityDefinition::ASSET_CHECK_CODE)->firstOrFail();
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $name, 2);

        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name, 'first_name' => $firstName, 'last_name' => $lastName]);
    }
}
