<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityUiPolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // Bank / Coop Add Activity — row remove data-loss warning
    // ==================================================

    public function test_bank_target_remove_button_and_canonical_warning_dialog_hooks_are_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-bank-target-remove', $content);
        $this->assertStringContainsString('data-bank-target-remove-dialog', $content);
        $this->assertStringContainsString('data-bank-target-remove-confirm', $content);
        $this->assertStringContainsString('data-bank-target-remove-cancel', $content);
        $this->assertStringContainsString('Remove this Bank / Coop entry?', $content);
        $this->assertStringContainsString('This row already contains information. Removing it will discard the data entered in this row.', $content);
        $this->assertStringContainsString('>Remove Entry<', $content);
    }

    public function test_bank_target_remove_warning_dialog_uses_secondary_cancel_and_danger_confirm_styling(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="ui-button-secondary" data-bank-target-remove-cancel/', $content);
        $this->assertMatchesRegularExpression('/class="ui-button-danger" data-bank-target-remove-confirm/', $content);
    }

    public function test_bank_target_remove_dirty_check_helper_inspects_the_meaningful_row_fields(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('const bankTargetRowHasData = (row) =>', $content);
        // The dirty check reads institution name, inquiry type, branch, status, date, time and
        // remarks — never CSRF/hidden/structural attributes — so a truly blank row never warns.
        $this->assertStringContainsString("row.querySelector('[name\$=\"[institution_name]\"]')", $content);
        $this->assertStringContainsString("row.querySelector('[data-bank-target-status]')", $content);
        $this->assertStringContainsString("statusControl.value !== 'pending'", $content);
    }

    public function test_bank_target_remove_click_checks_for_data_before_opening_the_warning_dialog(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $bindStart = strpos($content, 'const bindBankTargetRow = (row) =>');
        $this->assertNotFalse($bindStart);
        $bindEnd = strpos($content, 'const addBankTargetRow', $bindStart);
        $this->assertNotFalse($bindEnd);
        $bindSource = substr($content, $bindStart, $bindEnd - $bindStart);

        $this->assertStringContainsString('bankTargetRowHasData(row)', $bindSource);
        $this->assertStringContainsString('bankTargetRemoveDialog.showModal()', $bindSource);
        $this->assertStringContainsString('row.remove();', $bindSource);
        // The canonical modal is used, never a browser-native prompt.
        $this->assertStringNotContainsString('window.confirm(', $bindSource);
        $this->assertStringNotContainsString('confirm(\'', $bindSource);
    }

    public function test_bank_target_remove_confirm_removes_only_the_pending_row_and_syncs_parent_status(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString(
            "bankTargetRemoveDialog?.querySelector('[data-bank-target-remove-confirm]')?.addEventListener('click', () => {",
            $content,
        );
        $confirmStart = strpos($content, "bankTargetRemoveDialog?.querySelector('[data-bank-target-remove-confirm]')");
        $confirmEnd = strpos($content, '});', $confirmStart);
        $confirmSource = substr($content, $confirmStart, $confirmEnd - $confirmStart);
        $this->assertStringContainsString('row.remove();', $confirmSource);
        $this->assertStringContainsString('syncBankTargetRows();', $confirmSource);
    }

    public function test_bank_target_remove_cancel_closes_the_dialog_without_touching_the_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString(
            "bankTargetRemoveDialog?.querySelector('[data-bank-target-remove-cancel]')?.addEventListener('click', () => bankTargetRemoveDialog.close());",
            $content,
        );
    }

    public function test_bank_target_row_still_requires_at_least_one_row_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('if (currentBankTargetRows().length === 1) return;', $content);
    }

    // ==================================================
    // Bank / Coop Check — Schedule Date + Time weighted grid
    // ==================================================

    public function test_bank_target_add_row_schedule_date_and_time_fields_are_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Schedule Date')
            ->assertSee('name="bank_targets[0][scheduled_at]"', false)
            ->assertSee('name="bank_targets[0][scheduled_time]"', false);
    }

    public function test_bank_target_schedule_group_uses_the_weighted_responsive_grid_with_date_wider_than_time(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        // Initial row + template both use the same weighted grid (2fr date vs capped 1fr time).
        $this->assertSame(2, substr_count($content, 'sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]"><div><label for="bank-target-date-'));
    }

    public function test_bank_target_date_and_time_controls_use_the_shared_ui_control_class(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-bank-target-date', $content);
        $this->assertStringContainsString('class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-bank-target-time', $content);
    }

    public function test_dynamically_added_bank_target_row_template_uses_the_same_canonical_schedule_grid(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $templateStart = strpos($content, 'data-bank-target-template');
        $this->assertNotFalse($templateStart);
        $templateSource = substr($content, $templateStart, 3000);
        $this->assertStringContainsString('sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]"><div><label for="bank-target-date-__INDEX__"', $templateSource);
    }

    public function test_existing_saved_bank_target_renders_its_values_inside_the_weighted_schedule_grid_on_edit(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $bank->bankTargets()->create([
            'inquiry_type' => 'bank_coop_check',
            'institution_name' => 'BPI Cagayan de Oro',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-10 09:00:00',
            'scheduled_has_time' => true,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk()->getContent();

        $this->assertStringContainsString('sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]" data-bank-target-detail-schedule', $content);
        $this->assertStringContainsString('id="target-date-'.$target->id.'"', $content);
        $localSchedule = $target->scheduled_at->timezone(config('cims.display_timezone'));
        $this->assertStringContainsString('value="'.$localSchedule->format('Y-m-d').'"', $content);
        $this->assertStringContainsString('value="'.$localSchedule->format('H:i').'"', $content);
    }

    public function test_bank_and_asset_use_the_same_normal_checkbox_and_persisted_partial_state_logic(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $completed = $bank->bankTargets()->create([
            'inquiry_type' => 'bank_coop_check', 'institution_name' => 'Completed Bank', 'status' => 'completed',
            'created_by' => $ci->id, 'updated_by' => $ci->id,
        ]);
        $pending = $bank->bankTargets()->create([
            'inquiry_type' => 'bank_coop_check', 'institution_name' => 'Pending Bank', 'status' => 'pending',
            'created_by' => $ci->id, 'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk()->getContent();

        // Both modules use a simple project/native checkbox rather than suppressing browser
        // appearance through the custom completion-checkbox class.
        $completedStart = strpos($content, 'data-bank-target-card="'.$completed->id.'"');
        $completedRow = substr($content, $completedStart, 500);
        $this->assertStringContainsString('size-5 shrink-0 rounded border-ui-border-strong text-success', $completedRow);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*checked[^>]*disabled/', $completedRow);

        $pendingStart = strpos($content, 'data-bank-target-card="'.$pending->id.'"');
        $pendingRow = substr($content, $pendingStart, 500);
        $this->assertStringContainsString('size-5 shrink-0 rounded border-ui-border-strong text-success', $pendingRow);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-bank-bulk-target[^>]*\schecked/', $pendingRow);
        $this->assertStringNotContainsString('ci-completion-checkbox', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*size-5[^>]*data-bank-bulk-select-all/', $content);

        $bankSource = file_get_contents(resource_path('views/client-folders/activities/bank-coop-show.blade.php'));
        $assetSource = file_get_contents(resource_path('views/client-folders/activities/asset-check-show.blade.php'));
        $indexSource = file_get_contents(resource_path('views/client-folders/activities/index.blade.php'));
        $this->assertSame(1, substr_count($bankSource, 'checked.length > 0 && checked.length <'));
        $this->assertSame(1, substr_count($assetSource, 'checked.length > 0 && checked.length <'));
        $this->assertSame(2, substr_count($indexSource, 'checked.length > 0 && checked.length <'));
        $this->assertStringNotContainsString('ci-completion-checkbox', $assetSource);
        $this->assertSame(2, substr_count($assetSource, 'type="checkbox" class='));
    }

    public function test_bank_target_row_remove_warning_triggers_when_only_schedule_date_or_time_is_filled(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        // The dirty-check helper inspects the date and time controls alongside the other fields.
        $this->assertStringContainsString("row.querySelector('[name\$=\"[scheduled_at]\"]')", $content);
        $this->assertStringContainsString("row.querySelector('[name\$=\"[scheduled_time]\"]')", $content);
        $helperStart = strpos($content, 'const bankTargetRowHasData = (row) =>');
        $helperEnd = strpos($content, 'const bindBankTargetRow', $helperStart);
        $helperSource = substr($content, $helperStart, $helperEnd - $helperStart);
        $this->assertStringContainsString('date, time, remarks', $helperSource);
    }

    // ==================================================
    // Asset Check — Schedule Date + Time weighted grid
    // ==================================================

    public function test_asset_target_add_row_schedule_date_and_time_fields_are_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Schedule / Follow-up Date')
            ->assertSee('name="asset_targets[0][scheduled_at]"', false)
            ->assertSee('name="asset_targets[0][scheduled_time]"', false);
    }

    public function test_asset_target_schedule_group_uses_the_weighted_responsive_grid_with_date_wider_than_time(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]"><div><label class="ui-label" for="asset-target-date-'));
    }

    public function test_asset_target_date_and_time_controls_use_the_shared_ui_control_class(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-asset-target-date', $content);
        $this->assertStringContainsString('class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-asset-target-time', $content);
    }

    public function test_dynamically_added_asset_target_row_template_uses_the_same_canonical_schedule_grid(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $templateStart = strpos($content, 'data-asset-target-template');
        $this->assertNotFalse($templateStart);
        $templateSource = substr($content, $templateStart, 3000);
        $this->assertStringContainsString('sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]"><div><label class="ui-label" for="asset-target-date-__INDEX__"', $templateSource);
    }

    public function test_existing_saved_asset_target_renders_its_values_inside_the_weighted_schedule_grid_on_edit(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $target = $asset->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Cagayan de Oro City Hall',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-12 14:30:00',
            'scheduled_has_time' => true,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.asset-check.show', [$folder, $asset]))->assertOk()->getContent();

        $this->assertStringContainsString('sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]"><div><label class="ui-label" for="edit-'.$target->id.'-date"', $content);
        $localSchedule = $target->scheduled_at->timezone(config('cims.display_timezone'));
        $this->assertStringContainsString('value="'.$localSchedule->format('Y-m-d').'"', $content);
        $this->assertStringContainsString('value="'.$localSchedule->format('H:i').'"', $content);
    }

    public function test_asset_target_row_remove_warning_hooks_are_present_and_reuse_the_canonical_dialog(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-asset-target-remove-dialog', $content);
        $this->assertStringContainsString('data-asset-target-remove-confirm', $content);
        $this->assertStringContainsString('data-asset-target-remove-cancel', $content);
        $this->assertStringContainsString('Remove this Assessor entry?', $content);
        $this->assertStringContainsString('const assetTargetRowHasData = (row) =>', $content);
        $bindStart = strpos($content, 'const bindAssetTargetRow = (row) =>');
        $bindEnd = strpos($content, 'const addAssetTargetRow', $bindStart);
        $bindSource = substr($content, $bindStart, $bindEnd - $bindStart);
        $this->assertStringContainsString('assetTargetRowHasData(row)', $bindSource);
        $this->assertStringContainsString('assetTargetRemoveDialog.showModal()', $bindSource);
        $this->assertStringNotContainsString('window.confirm(', $bindSource);
    }

    public function test_persisted_asset_target_delete_still_uses_its_own_confirmation_dialog_not_the_row_remove_dialog(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $target = $asset->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Existing Office',
            'status' => 'pending',
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.asset-check.show', [$folder, $asset]))->assertOk()->getContent();

        $this->assertStringContainsString('id="delete-asset-target-'.$target->id.'"', $content);
        $this->assertStringContainsString(route('client-folders.activities.asset-targets.destroy', [$folder, $asset, $target]), $content);
    }

    // ==================================================
    // Barangay / Neighbor tracker — Schedule Date + Time alignment
    // ==================================================

    public function test_barangay_tracker_still_shows_schedule_date_and_time_fields(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $barangay]))
            ->assertOk()
            ->assertSee('Schedule / Follow-up Date')
            ->assertSee('Time')
            ->assertSee('name="scheduled_at"', false)
            ->assertSee('name="scheduled_time"', false);
    }

    public function test_barangay_tracker_date_and_time_share_one_deliberately_weighted_full_width_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $barangay]))->assertOk()->getContent();

        // The group spans the full form width (not squeezed beside Status) and Date is given a
        // clearly larger share (1fr) than Time (0.55fr, capped to a sane minimum of 8rem).
        $this->assertStringContainsString('sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.55fr)]', $content);

        $groupStart = strpos($content, 'sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.55fr)]');
        $datePos = strpos($content, 'default-check-date-', $groupStart);
        $timePos = strpos($content, 'default-check-time-', $groupStart);
        $this->assertNotFalse($datePos);
        $this->assertNotFalse($timePos);
        $this->assertLessThan($timePos, $datePos, 'Date must be the wider, leading field ahead of Time.');
    }

    public function test_barangay_tracker_date_and_time_controls_share_the_same_ui_control_class(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $barangay]))->assertOk()->getContent();

        $this->assertStringContainsString('ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-default-check-date', $content);
        $this->assertStringContainsString('ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-default-check-time', $content);
    }

    public function test_neighbor_tracker_uses_the_same_weighted_schedule_grid_as_barangay(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $neighbor]))
            ->assertOk()
            ->assertSee('sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.55fr)]', false);
    }

    public function test_barangay_tracker_backend_field_names_and_update_route_are_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $barangay]))->assertOk()->getContent();

        $this->assertStringContainsString(route('client-folders.activities.update', [$folder, $barangay]), $content);
        $this->assertStringContainsString('name="scheduled_at"', $content);
        $this->assertStringContainsString('name="scheduled_time"', $content);
    }

    // ==================================================
    // CI Activities action menus — trigger, destructive separation, existing rules
    // ==================================================

    public function test_action_menu_trigger_carries_a_contextual_aria_label_per_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        // Bank / Coop Check (multiple actions) still uses the 3-dot menu — unlike Barangay /
        // Neighbor Check (single Edit action), which shows a direct Edit button instead.
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Actions for '.$bank->name, $content);
    }

    public function test_bank_coop_action_menu_still_only_offers_open_and_delete_with_a_divider(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $menuStart = strpos($content, 'data-bank-coop-open="'.$bank->id.'"');
        $this->assertNotFalse($menuStart);
        $menuSource = substr($content, $menuStart, 1200);

        $this->assertStringContainsString('Open</button>', $menuSource);
        $this->assertStringContainsString('my-1 border-t border-ui-border', $menuSource);
        $this->assertStringContainsString('text-danger" data-modal-open="delete-activity-'.$bank->id.'">', $menuSource);
        $this->assertStringContainsString('Delete</button>', $menuSource);
        $this->assertSame(1, substr_count($menuSource, 'Open</button>'), 'No duplicate Open action.');
        $this->assertSame(1, substr_count($menuSource, 'Delete</button>'), 'No duplicate Delete action.');
    }

    public function test_asset_check_action_menu_still_only_offers_open_and_delete_with_a_divider(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $menuStart = strpos($content, 'data-asset-check-open="'.$asset->id.'"');
        $this->assertNotFalse($menuStart);
        $menuSource = substr($content, $menuStart, 1200);

        $this->assertStringContainsString('Open</button>', $menuSource);
        $this->assertStringContainsString('my-1 border-t border-ui-border', $menuSource);
        $this->assertStringContainsString('text-danger" data-modal-open="delete-activity-'.$asset->id.'">', $menuSource);
        $this->assertStringContainsString('Delete</button>', $menuSource);
    }

    public function test_barangay_action_menu_offers_only_edit_no_duplicate_or_delete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $menuStart = strpos($content, 'data-default-check-open="'.$barangay->id.'"');
        $this->assertNotFalse($menuStart);
        $menuSource = substr($content, $menuStart, 800);

        $this->assertStringContainsString('Edit</button>', $menuSource);
        $this->assertStringNotContainsString('delete-activity-'.$barangay->id, $menuSource);
    }

    public function test_neighbor_action_menu_offers_only_edit_no_duplicate_or_delete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $menuStart = strpos($content, 'data-default-check-open="'.$neighbor->id.'"');
        $this->assertNotFalse($menuStart);
        $menuSource = substr($content, $menuStart, 800);

        $this->assertStringContainsString('Edit</button>', $menuSource);
        $this->assertStringNotContainsString('delete-activity-'.$neighbor->id, $menuSource);
    }

    public function test_applicant_and_co_maker_action_menus_stay_isolated_per_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Menu Isolation Co-Maker']);
        $applicantBarangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => null, 'name' => 'Applicant Barangay Check']);
        $coMakerBarangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMaker->id, 'name' => 'Co-Maker Barangay Check']);

        $applicantContent = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Edit Applicant Barangay Check"', $applicantContent);
        $this->assertStringNotContainsString('aria-label="Edit Co-Maker Barangay Check"', $applicantContent);

        $coMakerContent = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Edit Co-Maker Barangay Check"', $coMakerContent);
        $this->assertStringNotContainsString('aria-label="Edit Applicant Barangay Check"', $coMakerContent);
    }

    // ==================================================
    // Helpers
    // ==================================================

    // ==================================================
    // Action buttons — the shared icon + button convention
    // ==================================================

    /**
     * Every action button in CI Activities leads with an icon from the shared <x-ui.icon> set, at
     * the size its button class calls for: size-3.5 on the compact header pair, size-4 on the
     * full-size form/modal pairs. These assertions pin the convention, never a full HTML snapshot.
     */
    public function test_the_header_pair_keeps_one_compact_size_and_both_carry_icons(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        // Add Activity: iconed, and still the compact class Show Panel beside it uses — height,
        // padding, font size and radius all come from that one shared pair of classes.
        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-primary-compact shrink-0" data-ci-activity-dialog-open><svg[^>]*class="[^"]*size-3\.5[^"]*"[^>]*>.*?<\/svg>\s*Add Activity<\/button>/s',
            $content,
        );
        $this->assertStringContainsString('class="ui-button-secondary-compact shrink-0" title="Show Panel"', $content);
        // The retired full-size treatment must not come back.
        $this->assertStringNotContainsString('class="ui-button-primary shrink-0" data-ci-activity-dialog-open', $content);
    }

    public function test_the_add_activity_dialog_cancel_carries_the_shared_close_icon(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-ci-activity-dialog-close><svg[^>]*>.*?<\/svg>\s*Cancel<\/button>/s',
            $content,
        );
        // The dialog's own close/submit wiring is untouched.
        $this->assertStringContainsString('data-ci-activity-submit', $content);
        $this->assertSame(2, substr_count($content, ' data-ci-activity-dialog-close'));
    }

    /** Barangay Check and Neighbor Check share one edit form, so both get the same pair. */
    public function test_the_default_check_edit_form_offers_iconed_cancel_and_save_changes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE] as $code) {
            $activity = $this->activity($folder, $ci, $code);

            $content = $this->actingAs($ci)
                ->get(route('client-folders.activities.default-check.show', [$folder, $activity]))
                ->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<h2 id="default-check-title"[^>]*><svg[^>]*class="[^"]*size-5[^"]*"[^>]*>.*?<\/svg>\s*<span[^>]*>\s*Edit '.preg_quote($activity->name, '/').'\s*<\/span>\s*<\/h2>/s',
                $content,
                $code.' edit title',
            );
            $this->assertMatchesRegularExpression(
                '/<button type="button" class="ui-button-secondary w-full sm:w-auto" data-default-check-cancel><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s',
                $content,
                $code.' cancel',
            );
            $this->assertMatchesRegularExpression(
                '/<button type="submit" class="ui-button-primary w-full sm:w-auto" data-default-check-submit><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Save Changes<\/button>/s',
                $content,
                $code.' save',
            );
        }
    }

    /** Barangay Check and Neighbor Check share this completion dialog and its action wiring. */
    public function test_the_default_check_completion_dialog_has_iconed_title_and_actions(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE] as $code) {
            $activity = $this->activity($folder, $ci, $code);

            $content = $this->actingAs($ci)
                ->get(route('client-folders.activities.default-check.show', [$folder, $activity]))
                ->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<h2 id="default-check-completion-title-[^"]+"[^>]*><svg[^>]*class="[^"]*size-5[^"]*"[^>]*>.*?<\/svg>\s*<span>\s*Mark '.preg_quote($activity->name, '/').' as completed\?\s*<\/span>\s*<\/h2>/s',
                $content,
                $code.' completion title',
            );
            $this->assertMatchesRegularExpression(
                '/<button type="button" class="ui-button-secondary" data-default-check-completion-cancel><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s',
                $content,
                $code.' completion cancel',
            );
            $this->assertMatchesRegularExpression(
                '/<button type="button" class="ui-button-primary" data-default-check-completion-confirm><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Mark Completed<\/button>/s',
                $content,
                $code.' completion confirm',
            );
        }
    }

    public function test_barangay_and_neighbor_share_the_iconed_quick_completion_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<p class="mt-0\.5 flex items-center gap-1\.5 text-sm text-text-muted"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Complete this activity\?<\/p>/s', $content);
        $this->assertMatchesRegularExpression('/data-quick-complete-cancel><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $content);
        $this->assertMatchesRegularExpression('/data-quick-complete-confirm><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*<span data-quick-complete-confirm-label>Mark as Completed<\/span><\/button>/s', $content);
        $this->assertStringContainsString("confirmLabel.textContent = 'Completing…';", $content);
        $this->assertStringNotContainsString("confirm.textContent = 'Mark Completed';", $content);
    }

    public function test_bank_coop_completion_and_delete_actions_keep_labels_and_gain_shared_icons(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();
        $activity = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'SAMPLE BANK',
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-bank-bulk-open-confirm disabled><svg[^>]*class="[^"]*size-3\.5[^"]*"[^>]*>.*?<\/svg>\s*Mark Selected as Completed<\/button>/s', $content);
        $this->assertMatchesRegularExpression('/data-bank-bulk-confirm-submit><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Mark as Completed<\/button>/s', $content);
        $this->assertMatchesRegularExpression('/data-modal-open="complete-bank-target-[^"]+"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Mark as Completed<\/button>/s', $content);
        $this->assertMatchesRegularExpression('/class="client-folder-menu-item text-danger" data-modal-open="delete-bank-target-[^"]+"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Delete<\/button>/s', $content);

        $completeStart = strpos($content, 'id="complete-bank-target-');
        $deleteStart = strpos($content, 'id="delete-bank-target-');
        $this->assertNotFalse($completeStart);
        $this->assertNotFalse($deleteStart);
        $completeDialog = substr($content, $completeStart, $deleteStart - $completeStart);
        $deleteDialog = substr($content, $deleteStart, 2500);

        $this->assertStringContainsString(route('client-folders.activities.bank-targets.complete', [$folder, $activity, $activity->bankTargets->sole()]), $completeDialog);
        $this->assertMatchesRegularExpression('/data-modal-close class="ui-button-secondary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $completeDialog);
        $this->assertMatchesRegularExpression('/class="ui-button-primary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Mark as Completed<\/button>/s', $completeDialog);

        $this->assertStringContainsString(route('client-folders.activities.bank-targets.destroy', [$folder, $activity, $activity->bankTargets->sole()]), $deleteDialog);
        $this->assertMatchesRegularExpression('/data-modal-close class="ui-button-secondary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $deleteDialog);
        $this->assertMatchesRegularExpression('/class="ui-button-danger"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Delete Target<\/button>/s', $deleteDialog);
    }

    public function test_the_bank_coop_target_edit_form_offers_iconed_cancel_and_save_changes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();
        $activity = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'SAMPLE BANK',
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()->getContent();

        $editStart = strpos($content, 'data-bank-target-detail-inquiry-type');
        $this->assertNotFalse($editStart, 'The edit form for target '.$target->id.' renders.');

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-modal-close><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button><button type="submit" class="ui-button-primary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Save Changes<\/button>/s',
            $content,
        );
        // The one-word label is gone, and the Add form beside it keeps its own distinct label.
        $this->assertStringNotContainsString('<button type="submit" class="ui-button-primary">Save</button>', $content);
        $this->assertStringContainsString('Add Bank / Coop</button>', $content);
    }

    public function test_the_add_bank_coop_modal_offers_iconed_actions(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankDefinition();
        $activity = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-modal-close><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button><button type="submit" class="ui-button-primary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Add Bank \/ Coop<\/button>/s',
            $content,
        );
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

    private function bankDefinition(): ActivityDefinition
    {
        return ActivityDefinition::query()->updateOrCreate(
            ['code' => ActivityDefinition::BANK_COOP_CHECK_CODE],
            ['name' => 'Bank / Coop Check', 'sort_order' => 35, 'is_required' => false, 'is_active' => true],
        );
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
