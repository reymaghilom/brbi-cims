<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityActionMenuAndBankBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // Redundant footer Close button removal
    // ==================================================

    public function test_bank_asset_and_default_tracker_modals_have_no_redundant_footer_close_button(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE, null, 1);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'aria-label="Close Bank / Coop Check"'));
        $this->assertSame(1, substr_count($content, 'aria-label="Close Asset Check"'));
        $this->assertSame(1, substr_count($content, 'aria-label="Close activity tracker"'));

        foreach (['bank-coop-tracker-modal', 'asset-check-tracker-modal', 'default-check-tracker-modal'] as $modalId) {
            $start = strpos($content, 'id="'.$modalId.'"');
            $this->assertNotFalse($start, "Modal {$modalId} not found.");
            $end = strpos($content, '</dialog>', $start);
            $this->assertNotFalse($end);
            $modalMarkup = substr($content, $start, $end - $start);
            $this->assertStringNotContainsString('>Close<', $modalMarkup, "Modal {$modalId} still has a footer Close button.");
        }
    }

    // ==================================================
    // Three-dot action menu matrix
    // ==================================================

    public function test_bank_check_menu_contains_open_and_delete_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('aria-label="Actions for '.$bank->name.'"', false)
            ->assertSee('data-bank-coop-open="'.$bank->id.'"', false)
            ->assertSee('data-modal-open="delete-activity-'.$bank->id.'"', false)
            ->assertDontSee('View notes')
            ->assertDontSee('Manage proof');
    }

    public function test_asset_check_menu_contains_open_and_delete_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('aria-label="Actions for '.$asset->name.'"', false)
            ->assertSee('data-asset-check-open="'.$asset->id.'"', false)
            ->assertSee('data-modal-open="delete-activity-'.$asset->id.'"', false)
            ->assertDontSee('View notes');
    }

    public function test_barangay_shows_direct_edit_action_with_no_3dot_menu_and_no_delete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('aria-label="Edit '.$barangay->name.'"', false)
            ->assertSee('data-default-check-open="'.$barangay->id.'"', false);
        $content = $page->getContent();
        $this->assertStringNotContainsString('aria-label="Actions for '.$barangay->name.'"', $content);
        $this->assertStringNotContainsString('id="delete-activity-'.$barangay->id.'"', $content);
    }

    public function test_neighbor_shows_direct_edit_action_with_no_3dot_menu_and_no_delete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('aria-label="Edit '.$neighbor->name.'"', false)
            ->assertSee('data-default-check-open="'.$neighbor->id.'"', false);
        $content = $page->getContent();
        $this->assertStringNotContainsString('aria-label="Actions for '.$neighbor->name.'"', $content);
        $this->assertStringNotContainsString('id="delete-activity-'.$neighbor->id.'"', $content);
    }

    public function test_barangay_and_neighbor_backend_delete_protection_remains(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($ci)->delete(route('client-folders.activities.destroy', [$folder, $barangay]), ['co_maker_id' => null])
            ->assertSessionHasErrors('activity');

        $this->assertNotNull($barangay->fresh());
    }

    public function test_bank_and_asset_delete_requires_confirmation_dialog_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('id="delete-activity-'.$bank->id.'"', false)
            ->assertSee('id="delete-activity-'.$asset->id.'"', false)
            ->assertSee('This action cannot be undone.');
    }

    public function test_actions_remain_scoped_to_exact_applicant_or_co_maker_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Alpha Maker');
        $applicantBank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $coMakerBank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, $coMaker->id, 1);

        $applicantPage = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $applicantPage->assertOk()->assertSee('data-bank-coop-open="'.$applicantBank->id.'"', false);
        $this->assertStringNotContainsString('data-bank-coop-open="'.$coMakerBank->id.'"', $applicantPage->getContent());

        $coMakerPage = $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]));
        $coMakerPage->assertOk()->assertSee('data-bank-coop-open="'.$coMakerBank->id.'"', false);
        $this->assertStringNotContainsString('data-bank-coop-open="'.$applicantBank->id.'"', $coMakerPage->getContent());
    }

    // ==================================================
    // Bank Select All / bulk completion
    // ==================================================

    public function test_bank_modal_renders_select_all_and_asset_modal_does_not(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $this->createTarget($bank, $ci, 'BDO', ActivityStatus::Pending);

        $bankPage = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]));
        $bankPage->assertOk()
            ->assertSee('data-bank-bulk-select-all', false)
            ->assertSee('Select All')
            ->assertSee('Mark Selected as Completed');

        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE, null, 1);
        $assetPage = $this->get(route('client-folders.activities.asset-check.show', [$folder, $asset]));
        $assetPage->assertOk()->assertDontSee('data-bank-bulk-select-all', false);
    }

    public function test_bulk_completion_marks_only_selected_incomplete_targets(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $pending1 = $this->createTarget($bank, $ci, 'BDO', ActivityStatus::Pending);
        $pending2 = $this->createTarget($bank, $ci, 'BPI', ActivityStatus::Scheduled);
        $completed = $this->createTarget($bank, $ci, 'Metrobank', ActivityStatus::Completed);
        $followUp = $this->createTarget($bank, $ci, 'Landbank', ActivityStatus::FollowUp);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$pending1->id, $pending2->id, $followUp->id],
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $pending1->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $pending2->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $followUp->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $completed->fresh()->status);
    }

    public function test_bulk_completion_leaves_parent_not_completed_when_an_incomplete_target_remains(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $a = $this->createTarget($bank, $ci, 'Target A', ActivityStatus::Completed);
        $b = $this->createTarget($bank, $ci, 'Target B', ActivityStatus::Completed);
        $c = $this->createTarget($bank, $ci, 'Target C', ActivityStatus::Completed);
        $d = $this->createTarget($bank, $ci, 'Target D', ActivityStatus::Pending);
        $bank->update(['status' => ActivityStatus::Pending]);

        $this->assertSame(ActivityStatus::Pending, $bank->fresh()->status);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$d->id],
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $d->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $bank->fresh()->status);
    }

    public function test_parent_scheduled_and_follow_up_and_pending_derivation_remain_correct(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $scheduledBank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, null, 0);
        $this->createTarget($scheduledBank, $ci, 'Scheduled Target', ActivityStatus::Scheduled);
        $this->createTarget($scheduledBank, $ci, 'Pending Target', ActivityStatus::Pending);
        $scheduledBank->update(['status' => ActivityStatus::Scheduled]);
        $this->assertSame(
            ActivityStatus::Scheduled,
            CiActivityBankTarget::deriveParentStatus($scheduledBank->bankTargets()->pluck('status')),
        );

        $followUpBank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, null, 1);
        $this->createTarget($followUpBank, $ci, 'FollowUp Target', ActivityStatus::FollowUp);
        $this->createTarget($followUpBank, $ci, 'Scheduled Target', ActivityStatus::Scheduled);
        $this->assertSame(
            ActivityStatus::FollowUp,
            CiActivityBankTarget::deriveParentStatus($followUpBank->bankTargets()->pluck('status')),
        );

        $pendingBank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, null, 2);
        $this->createTarget($pendingBank, $ci, 'Pending Target', ActivityStatus::Pending);
        $this->assertSame(
            ActivityStatus::Pending,
            CiActivityBankTarget::deriveParentStatus($pendingBank->bankTargets()->pluck('status')),
        );
    }

    public function test_bulk_completion_requires_at_least_one_target_id(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $this->createTarget($bank, $ci, 'BDO', ActivityStatus::Pending);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [],
        ])->assertSessionHasErrors('bank_target_ids');
    }

    public function test_cross_activity_bank_target_ids_are_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bankA = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, null, 0);
        $bankB = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, null, 1);
        $targetA = $this->createTarget($bankA, $ci, 'Target A', ActivityStatus::Pending);
        $targetB = $this->createTarget($bankB, $ci, 'Target B', ActivityStatus::Pending);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bankA]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$targetA->id, $targetB->id],
        ])->assertNotFound();

        $this->assertSame(ActivityStatus::Pending, $targetA->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $targetB->fresh()->status);
    }

    public function test_cross_folder_bank_activity_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $foreignBank = $this->activity($otherFolder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $this->createTarget($bank, $ci, 'BDO', ActivityStatus::Pending);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$otherFolder, $foreignBank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$target->id],
        ])->assertNotFound();

        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
    }

    public function test_cross_co_maker_bank_target_completion_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = $this->coMaker($folder, 'Maker A');
        $coMakerB = $this->coMaker($folder, 'Maker B');
        $bankForB = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, $coMakerB->id);
        $target = $this->createTarget($bankForB, $ci, 'BDO', ActivityStatus::Pending);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bankForB]), [
            'co_maker_id' => $coMakerA->id,
            'bank_target_ids' => [$target->id],
        ])->assertNotFound();

        $this->assertSame(ActivityStatus::Pending, $target->fresh()->status);
    }

    public function test_existing_unselected_targets_remain_unchanged_after_bulk_completion(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $selected = $this->createTarget($bank, $ci, 'Selected Target', ActivityStatus::Pending);
        $untouched = $this->createTarget($bank, $ci, 'Untouched Target', ActivityStatus::Scheduled);
        $untouched->update(['remarks' => 'Keep this remark intact.']);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$selected->id],
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $selected->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $untouched->fresh()->status);
        $this->assertSame('Keep this remark intact.', $untouched->fresh()->remarks);
    }

    public function test_bulk_completion_is_audited_per_target(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target1 = $this->createTarget($bank, $ci, 'BDO', ActivityStatus::Pending);
        $target2 = $this->createTarget($bank, $ci, 'BPI', ActivityStatus::Pending);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$target1->id, $target2->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.bank_target_completed',
            'metadata->bank_target_id' => $target1->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.bank_target_completed',
            'metadata->bank_target_id' => $target2->id,
        ]);
    }

    public function test_main_completion_checkbox_reflects_completed_after_all_targets_complete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $this->createTarget($bank, $ci, 'BDO', ActivityStatus::Pending);

        $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$target->id],
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $bank->fresh()->status);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk();
        $this->assertMatchesRegularExpression(
            '/data-ci-activity-completion="'.$bank->id.'"[^>]*\bchecked\b/s',
            $page->getContent(),
        );
    }

    private function activity(
        ClientFolder $folder,
        User $creator,
        string $code,
        ?int $coMakerId = null,
        int $definitionOffset = 0,
    ): CiActivity {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();
        $name = $definition->name.($definitionOffset > 0 ? ' '.$definitionOffset : '');

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
        ]);
    }

    private function createTarget(CiActivity $activity, User $actor, string $name, ActivityStatus $status): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $name,
            'status' => $status,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
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
