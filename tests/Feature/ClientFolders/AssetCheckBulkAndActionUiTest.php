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
 * Asset Check's persisted multi-item editing workflow: create, edit, selection-only checkboxes,
 * explicit completion, status derivation, and exact activity/person isolation.
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
        $this->assertStringContainsString('class="flex items-start gap-3 bg-surface px-3 py-2.5 sm:items-center"', $html);
        $this->assertStringContainsString('data-asset-bulk-target="'.$target->id.'"', $html);
        $this->assertStringContainsString('<x-ui.status-badge :status="$target->status" class="shrink-0" />', file_get_contents(resource_path('views/client-folders/activities/asset-check-show.blade.php')));
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

    public function test_an_asset_target_is_created_under_the_exact_existing_activity(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();

        $this->actingAs($ci)->post(
            route('client-folders.activities.asset-targets.store', [$folder, $activity]),
            $this->targetPayload('CITY ASSESSOR - NORTH'),
        )->assertRedirect(route('client-folders.activities.asset-check.show', [$folder, $activity]));

        $target = CiActivityAssetTarget::sole();
        $this->assertSame($activity->id, $target->ci_activity_id);
        $this->assertSame(ActivityStatus::Pending, $target->status);
        $this->assertNull($activity->fresh()->co_maker_id);
        $this->assertDatabaseCount('ci_activities', 1);
        $this->assertStringContainsString('CITY ASSESSOR - NORTH', $this->show($ci, $folder, $activity));
    }

    public function test_edit_updates_the_same_target_without_touching_other_targets_or_activities(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $target = $this->createTarget($activity, $ci, 'OLD OFFICE');
        $untouched = $this->createTarget($activity, $ci, 'OTHER OFFICE');

        $this->actingAs($ci)->put(
            route('client-folders.activities.asset-targets.update', [$folder, $activity, $target]),
            $this->targetPayload('UPDATED OFFICE'),
        )->assertRedirect();

        $this->assertSame('UPDATED OFFICE', $target->fresh()->office_location);
        $this->assertSame('OTHER OFFICE', $untouched->fresh()->office_location);
        $this->assertDatabaseCount('ci_activity_asset_targets', 2);
        $this->assertDatabaseCount('ci_activities', 1);
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
    // TEST B — checkbox selection and explicit per-item completion
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

        // Reload: persisted completion is checked and locked; pending remains selectable.
        $html = $this->show($ci, $folder, $activity);
        $this->assertMatchesRegularExpression('/data-asset-bulk-target="'.$a->id.'"[^>]*checked[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/data-asset-bulk-target="'.$b->id.'"(?![^>]*checked)/', $html);

        // Completion is stated by the checkbox checkmark and shared text status badge, without a
        // second redundant check icon beside them.
        $source = file_get_contents(resource_path('views/client-folders/activities/asset-check-show.blade.php'));
        $this->assertStringNotContainsString('ci-completion-checkbox', $source);
        $this->assertStringContainsString('size-5 shrink-0 rounded border-ui-border-strong text-success focus:ring-success', $source);
        $this->assertStringNotContainsString('@if($targetCompleted)<x-ui.icon name="check-circle"', $source);
        $this->assertStringContainsString('x-ui.status-badge', file_get_contents(resource_path('views/client-folders/activities/asset-check-show.blade.php')));
        $this->assertStringContainsString('aria-label="City Assessor — OFFICE A is completed"', $html);
    }

    public function test_add_assessor_actions_use_the_shared_button_icons(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();

        $html = $this->show($ci, $folder, $activity);

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-asset-modal-close><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button><button type="submit" class="ui-button-primary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Add Assessor<\/button>/s',
            $html,
        );
    }

    // ---------------------------------------------------------------------
    // TEST C — Select All + bulk complete
    // ---------------------------------------------------------------------

    public function test_select_all_marks_every_eligible_item_through_the_bulk_endpoint(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $a = $this->createTarget($activity, $ci, 'OFFICE A');
        $b = $this->createTarget($activity, $ci, 'OFFICE B');
        $c = $this->createTarget($activity, $ci, 'OFFICE C');

        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete-many', [$folder, $activity]),
            ['co_maker_id' => null, 'asset_target_ids' => [$a->id, $b->id, $c->id]],
        )->assertRedirect()->assertSessionHas('status', 'Selected assessor targets marked as completed.');

        $this->assertSame(ActivityStatus::Completed, $a->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $b->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $c->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    public function test_selecting_or_clearing_checkboxes_does_not_change_persisted_status(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $targets = collect(['A', 'B', 'C'])->map(fn (string $name) => $this->createTarget($activity, $ci, 'OFFICE '.$name));

        $html = $this->show($ci, $folder, $activity);
        $this->assertSame(0, $activity->assetTargets()->where('status', ActivityStatus::Completed)->count());
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->assertStringContainsString('target.checked = selectAll.checked;', $html);
        $this->assertStringNotContainsString('data-asset-completion-toggle', $html);
        $this->assertStringNotContainsString('data-asset-completion-url', $html);
        $this->assertCount(3, $targets);
    }

    public function test_zero_target_asset_check_remains_pending_and_renders_the_add_action(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();

        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $html = $this->show($ci, $folder, $activity);
        $this->assertStringContainsString('No assessor targets yet.', $html);
        $this->assertStringContainsString('data-modal-open="add-asset-target"', $html);
        $this->assertStringContainsString('Add Assessor', $html);
    }

    public function test_the_select_all_panel_supports_the_indeterminate_half_state(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $this->createTarget($activity, $ci, 'OFFICE A');

        $html = $this->show($ci, $folder, $activity);

        $this->assertStringContainsString('data-asset-bulk-select-all', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*size-5[^>]*data-asset-bulk-select-all/', $html);
        $this->assertStringContainsString('Select All', $html);
        $this->assertStringContainsString('data-asset-bulk-counter', $html);
        $this->assertStringContainsString('Mark Selected as Completed', $html);
        $this->assertStringContainsString('data-asset-bulk-confirm-modal', $html);
        $this->assertStringContainsString('selectAll.indeterminate = checked.length > 0 && checked.length < checkboxTargets.length;', $html);
        $this->assertStringContainsString("form.querySelectorAll('input[name=\"asset_target_ids[]\"]')", $html);
        $this->assertStringContainsString("confirmModal?.querySelector('[data-asset-bulk-confirm-submit]')?.addEventListener('click'", $html);
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

        $this->actingAs($ci)->put(
            route('client-folders.activities.asset-targets.update', [$folder, $aActivity, $aTarget]),
            $this->targetPayload('A OFFICE UPDATED', $coMakerA->id),
        )->assertRedirect();
        $this->assertSame('A OFFICE UPDATED', $aTarget->fresh()->office_location);
        $this->assertSame('B OFFICE', $bTarget->fresh()->office_location);

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

    public function test_edit_and_completion_reject_a_target_from_another_asset_activity(): void
    {
        [$ci, $folder, $activity] = $this->assetCheck();
        $otherActivity = $this->activity($folder, $ci);
        $foreign = $this->createTarget($otherActivity, $ci, 'FOREIGN OFFICE');

        $this->actingAs($ci)->put(
            route('client-folders.activities.asset-targets.update', [$folder, $activity, $foreign]),
            $this->targetPayload('FORGED UPDATE'),
        )->assertNotFound();
        $this->assertSame('FOREIGN OFFICE', $foreign->fresh()->office_location);

        $this->actingAs($ci)->patch(
            route('client-folders.activities.asset-targets.complete', [$folder, $activity, $foreign]),
            ['co_maker_id' => null],
        )->assertNotFound();
        $this->assertSame(ActivityStatus::Pending, $foreign->fresh()->status);
    }

    public function test_activity_modal_runtime_wires_selection_confirmation_and_validation_errors(): void
    {
        [$ci, $folder] = $this->assetCheck();

        $html = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString("body.addEventListener('change'", $html);
        $this->assertStringContainsString("control.matches('[data-asset-bulk-target]')", $html);
        $this->assertStringContainsString("control.matches('[data-asset-bulk-select-all]')", $html);
        $this->assertStringContainsString("event.target.closest('[data-asset-bulk-confirm-submit]')", $html);
        $this->assertStringContainsString('form.requestSubmit();', $html);
        $this->assertStringNotContainsString('data-asset-completion-url', $html);
        $this->assertStringContainsString('render(await response.text())', $html);
        $this->assertStringContainsString('assetFormError', $html);
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

    /** @return array<string, mixed> */
    private function targetPayload(string $location, ?int $coMakerId = null): array
    {
        return [
            'co_maker_id' => $coMakerId,
            'assessor_type' => 'city_assessor',
            'office_location' => $location,
            'status' => ActivityStatus::Pending->value,
            'scheduled_at' => null,
            'scheduled_time' => null,
            'remarks' => null,
        ];
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
