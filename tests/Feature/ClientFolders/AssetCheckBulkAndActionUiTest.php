<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset Check's multi-item editing workflow, brought in line with the Bank / Coop Check it already
 * resembles: per-item checkboxes, a Select All with an indeterminate half-state, and a bulk
 * "Mark Selected as Completed" that posts to the activity's own complete-many endpoint.
 *
 * Only the interaction pattern is reused — the data model was already multi-target with per-item
 * status, so nothing here required a schema change. Every guard the single-target route enforces
 * (exact folder, exact activity, exact person) is enforced identically for the bulk one.
 */
class AssetCheckBulkAndActionUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------------
    // TEST A — Edit is reachable from the 3-dots menu
    // ---------------------------------------------------------------------

    public function test_each_item_offers_edit_asset_check_from_the_shared_three_dots_menu(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $target = $this->createTarget($activity, $ci, 'City Assessor Office');

        $html = $this->show($ci, $folder, $activity);

        // The project's own context-menu component and dots trigger — not a second design.
        $this->assertStringContainsString('ui-dots-trigger', $html);
        $this->assertStringContainsString('data-context-menu', $html);
        $this->assertMatchesRegularExpression(
            '/data-asset-modal-open="edit-asset-target-'.$target->id.'"><svg[^>]*>.*?<\/svg>\s*Edit Asset Check<\/button>/s',
            $html,
        );

        // The edit dialog for that exact target is present and pre-filled from its saved values.
        $this->assertStringContainsString('id="edit-asset-target-'.$target->id.'"', $html);
        $this->assertStringContainsString('City Assessor Office', $html);
        $this->assertStringContainsString(
            route('client-folders.activities.asset-targets.update', [$folder, $activity, $target]),
            $html,
        );
    }

    public function test_the_edit_dialog_offers_iconed_cancel_and_save_changes(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $this->createTarget($activity, $ci, 'City Assessor Office');

        $html = $this->show($ci, $folder, $activity);

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-asset-modal-close><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button><button type="submit" class="ui-button-primary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Save Changes<\/button>/s',
            $html,
        );
        $this->assertStringNotContainsString('<button type="submit" class="ui-button-primary">Save</button>', $html);
    }

    // ---------------------------------------------------------------------
    // TEST B — per-item checkbox state
    // ---------------------------------------------------------------------

    public function test_completing_one_item_leaves_the_others_untouched_and_survives_reload(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $a = $this->createTarget($activity, $ci, 'OFFICE A');
        $b = $this->createTarget($activity, $ci, 'OFFICE B');

        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete', [$folder, $activity, $a]),
            ['co_maker_id' => null],
        )->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $a->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $b->fresh()->status, 'Item B is untouched.');

        // Reload: A renders checked and locked, B renders unchecked and still selectable.
        $html = $this->show($ci, $folder, $activity);
        $this->assertMatchesRegularExpression('/data-asset-bulk-target="'.$a->id.'"[^>]*checked[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/data-asset-bulk-target="'.$b->id.'"(?![^>]*checked)/', $html);

        // Completion is stated by more than colour: an icon and the shared status badge.
        $this->assertStringContainsString('name="check-circle"', file_get_contents(resource_path('views/client-folders/activities/asset-check-show.blade.php')));
        $this->assertStringContainsString('x-ui.status-badge', file_get_contents(resource_path('views/client-folders/activities/asset-check-show.blade.php')));
        $this->assertStringContainsString('aria-label="OFFICE A — is completed"', str_replace('OFFICE A —', 'OFFICE A —', $html));
    }

    // ---------------------------------------------------------------------
    // TEST C — Select All + bulk complete
    // ---------------------------------------------------------------------

    public function test_select_all_marks_every_eligible_item_through_the_bulk_endpoint(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $a = $this->createTarget($activity, $ci, 'OFFICE A');
        $b = $this->createTarget($activity, $ci, 'OFFICE B');

        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $activity]),
            ['co_maker_id' => null, 'asset_target_ids' => [$a->id, $b->id]],
        )->assertRedirect()->assertSessionHas('status', 'Selected assessor targets marked as completed.');

        $this->assertSame(ActivityStatus::Completed, $a->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $b->fresh()->status);
    }

    public function test_the_select_all_panel_supports_the_indeterminate_half_state(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $this->createTarget($activity, $ci, 'OFFICE A');

        $html = $this->show($ci, $folder, $activity);

        $this->assertStringContainsString('data-asset-bulk-select-all', $html);
        $this->assertStringContainsString('Select All', $html);
        $this->assertStringContainsString('data-asset-bulk-counter', $html);
        $this->assertMatchesRegularExpression('/data-asset-bulk-open-confirm disabled><svg[^>]*>.*?<\/svg>\s*Mark Selected as Completed<\/button>/s', $html);

        // The same selection contract Bank / Coop uses, including the partial state.
        $this->assertStringContainsString('selectAll.indeterminate = selected.length > 0 && selected.length < eligible.length;', $html);
        $this->assertStringContainsString("form.querySelectorAll('input[name=\"asset_target_ids[]\"]')", $html);
    }

    public function test_the_bulk_endpoint_refuses_a_target_from_another_activity(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $mine = $this->createTarget($activity, $ci, 'OFFICE A');
        $otherActivity = $this->activity($folder, $ci);
        $foreign = $this->createTarget($otherActivity, $ci, 'FOREIGN OFFICE');

        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $activity]),
            ['co_maker_id' => null, 'asset_target_ids' => [$mine->id, $foreign->id]],
        )->assertNotFound();

        // All or nothing: the legitimate one is not quietly completed either.
        $this->assertSame(ActivityStatus::Pending, $mine->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $foreign->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // TEST D — person isolation
    // ---------------------------------------------------------------------

    public function test_bulk_completion_never_crosses_between_people(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = $this->coMaker($folder, 'Ana Santos');
        $coMakerB = $this->coMaker($folder, 'Ben Cruz');

        $applicantActivity = $this->activity($folder, $ci);
        $applicantTarget = $this->createTarget($applicantActivity, $ci, 'APPLICANT OFFICE');
        $aActivity = $this->activity($folder, $ci, $coMakerA->id);
        $aTarget = $this->createTarget($aActivity, $ci, 'A OFFICE');
        $bActivity = $this->activity($folder, $ci, $coMakerB->id);
        $bTarget = $this->createTarget($bActivity, $ci, 'B OFFICE');

        // The Applicant's own bulk completion touches only the Applicant's target.
        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $applicantActivity]),
            ['co_maker_id' => null, 'asset_target_ids' => [$applicantTarget->id]],
        )->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $applicantTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $aTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $bTarget->fresh()->status);

        // A Co-Maker's target cannot be reached through the Applicant's activity...
        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $applicantActivity]),
            ['co_maker_id' => null, 'asset_target_ids' => [$aTarget->id]],
        )->assertNotFound();

        // ...nor can the wrong person be claimed for a Co-Maker's own activity.
        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $aActivity]),
            ['co_maker_id' => $coMakerB->id, 'asset_target_ids' => [$aTarget->id]],
        )->assertNotFound();

        // ...and Co-Maker A's own bulk never reaches Co-Maker B.
        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $aActivity]),
            ['co_maker_id' => $coMakerA->id, 'asset_target_ids' => [$aTarget->id]],
        )->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $aTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $bTarget->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{User, ClientFolder, CiActivity} */
    private function assetCheck(): array
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        return [$ci, $folder, $this->activity($folder, $ci)];
    }

    private function show(User $ci, ClientFolder $folder, CiActivity $activity): string
    {
        return $this->actingAs($ci)
            ->get(route('client-folders.activities.asset-check.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();
    }

    private function activity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $this->assetDefinition()->id,
            'name' => 'Asset Check',
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createTarget(CiActivity $activity, User $actor, string $location): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => $location,
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
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

        return CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}
