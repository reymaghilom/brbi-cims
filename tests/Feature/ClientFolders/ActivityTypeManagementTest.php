<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_ci_activities_page_exposes_the_activity_type_management_entry_point(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-modal-open="activity-type-management"><svg[^>]*>.*?<\/svg>\s*Activity Types<\/button>/s', $content);
        $this->assertStringContainsString('id="activity-type-management"', $content);
        $this->assertStringContainsString('Activity Type Management', $content);
        $this->assertStringContainsString('Manage custom activity types. Activate, deactivate, or delete activity types as needed.', $content);
        foreach (['all', 'active', 'inactive'] as $tab) {
            $this->assertStringContainsString('data-activity-type-tab="'.$tab.'"', $content);
            $this->assertStringContainsString('data-activity-type-tab-count="'.$tab.'"', $content);
        }
        $this->assertStringContainsString('placeholder="Search activity types..."', $content);
        $this->assertStringContainsString('data-activity-type-search', $content);
        $this->assertStringContainsString('data-activity-type-add', $content);
        $this->assertStringContainsString('Used In Activities', $content);
        // Counts are derived from the rendered rows, never printed as fixed numbers.
        $this->assertStringContainsString('data-activity-type-tab-count="all">0</span>', $content);
    }

    public function test_system_activity_types_are_read_only_in_the_management_table(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        foreach ([
            ActivityDefinition::BARANGAY_CHECK_CODE,
            ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ActivityDefinition::ASSET_CHECK_CODE,
            ActivityDefinition::BANK_COOP_CHECK_CODE,
        ] as $code) {
            $definition = ActivityDefinition::query()->where('code', $code)->sole();
            $row = $this->managementRow($content, $definition);
            $this->assertStringContainsString('data-activity-type-kind="system"', $row);
            $this->assertStringContainsString('data-activity-type-state="active"', $row);
            $this->assertStringContainsString('>System</span>', $row);
            $this->assertStringContainsString('>Active</span>', $row);
            $this->assertStringNotContainsString('data-activity-type-deactivate', $row);
            $this->assertStringNotContainsString('data-activity-type-delete', $row);
            $this->assertStringNotContainsString('data-activity-type-edit', $row);
            $this->assertStringContainsString('&mdash;', $row);
        }
    }

    public function test_forged_requests_cannot_manage_system_activity_types(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci);

        foreach ([
            ActivityDefinition::BARANGAY_CHECK_CODE,
            ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ActivityDefinition::ASSET_CHECK_CODE,
            ActivityDefinition::BANK_COOP_CHECK_CODE,
        ] as $code) {
            $definition = ActivityDefinition::query()->where('code', $code)->sole();

            $this->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])->assertNotFound();
            $this->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]))->assertNotFound();
            $this->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Hijacked Type'])->assertNotFound();

            $fresh = $definition->fresh();
            $this->assertTrue($fresh->is_active);
            $this->assertSame($definition->name, $fresh->name);
        }
    }

    public function test_active_custom_type_is_listed_as_custom_with_edit_and_deactivate_actions(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verification');

        $row = $this->managementRow(
            $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
            $definition,
        );

        $this->assertStringContainsString('data-activity-type-kind="custom"', $row);
        $this->assertStringContainsString('data-activity-type-state="active"', $row);
        $this->assertStringContainsString('data-activity-type-usage="0"', $row);
        $this->assertStringContainsString('>Custom</span>', $row);
        $this->assertStringContainsString('>Active</span>', $row);
        $this->assertMatchesRegularExpression('/data-activity-type-edit><svg[^>]*>.*?<\/svg>\s*Edit<\/button>/s', $row);
        $this->assertMatchesRegularExpression('/data-activity-type-deactivate><svg[^>]*>.*?<\/svg>\s*Deactivate<\/button>/s', $row);
        // Unused, so permanent deletion is offered.
        $this->assertMatchesRegularExpression('/data-activity-type-delete><svg[^>]*>.*?<\/svg>\s*Delete Permanently<\/button>/s', $row);
        $this->assertStringNotContainsString('data-activity-type-activate', $row);
    }

    public function test_used_custom_type_never_offers_permanent_deletion(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verification');
        $this->customActivity($folder, $ci, $definition);

        $row = $this->managementRow(
            $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
            $definition,
        );

        $this->assertStringContainsString('data-activity-type-usage="1"', $row);
        $this->assertStringNotContainsString('data-activity-type-delete', $row);
        $this->assertStringContainsString('data-activity-type-deactivate', $row);
    }

    public function test_deactivating_a_custom_type_keeps_every_existing_activity_and_hides_it_from_new_activity_selection(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $definition = $this->customDefinition('Credit Verification');
        $pending = $this->customActivity($folder, $ci, $definition, ['remarks' => 'Pending one']);
        $completed = $this->customActivity($folder, $ci, $definition, [
            'co_maker_id' => $coMaker->id,
            'status' => ActivityStatus::Completed,
            'remarks' => 'Completed one',
        ]);

        $this->actingAs($ci)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])
            ->assertOk();

        $this->assertFalse($definition->fresh()->is_active);
        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id]);
        $this->assertSame(ActivityStatus::Pending, $pending->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $completed->fresh()->status);
        $this->assertNull($pending->fresh()->co_maker_id);
        $this->assertSame($coMaker->id, $completed->fresh()->co_maker_id);
        $this->assertSame('Pending one', $pending->fresh()->remarks);

        $content = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        // Gone from the Add Activity type list, still listed (inactive) in management.
        $this->assertStringNotContainsString('data-activity-type-option data-value="'.$definition->id.'"', $content);
        $this->assertStringContainsString('data-activity-type-state="inactive"', $this->managementRow($content, $definition));

        // Server-side too: an inactive definition can no longer back a new CI Activity.
        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => $definition->id,
            'create_new_activity_type' => false,
            'status' => ActivityStatus::Pending->value,
        ])->assertSessionHasErrors('activity_definition_id');
    }

    public function test_reactivating_a_custom_type_makes_it_selectable_again_without_duplicating_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verification', false);
        $existing = $this->customActivity($folder, $ci, $definition);
        $definitionCount = ActivityDefinition::query()->count();

        $this->actingAs($ci)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => true])
            ->assertOk();

        $this->assertTrue($definition->fresh()->is_active);
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $this->assertSame(ActivityStatus::Pending, $existing->fresh()->status);

        $content = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-activity-type-option data-value="'.$definition->id.'"', $content);
        $this->assertStringContainsString('data-activity-type-state="active"', $this->managementRow($content, $definition));
    }

    public function test_unused_custom_type_is_hard_deleted(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Background Verification');

        $this->actingAs($ci)
            ->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]))
            ->assertOk();

        // Hard delete — no soft-delete/archive row survives.
        $this->assertDatabaseMissing('activity_definitions', ['id' => $definition->id]);
        $this->assertSame(0, ActivityDefinition::query()->withoutGlobalScopes()->whereKey($definition->id)->count());

        $content = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-activity-type-id="'.$definition->id.'"', $content);
        $this->assertStringNotContainsString('data-activity-type-option data-value="'.$definition->id.'"', $content);
    }

    public function test_permanent_deletion_of_a_used_custom_type_is_rejected_server_side(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verification');
        $activity = $this->customActivity($folder, $ci, $definition);

        $this->actingAs($ci)
            ->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]))
            ->assertStatus(422);

        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id, 'is_active' => true]);
        $this->assertDatabaseHas('ci_activities', ['id' => $activity->id, 'activity_definition_id' => $definition->id]);
    }

    public function test_usage_count_is_exact_across_pending_and_completed_activities(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verification');
        $otherDefinition = $this->customDefinition('Employment Check');
        $this->customActivity($folder, $ci, $definition);
        $this->customActivity($folder, $ci, $definition, ['status' => ActivityStatus::Completed]);
        $this->customActivity($otherFolder, $ci, $definition, ['status' => ActivityStatus::Scheduled]);
        $this->customActivity($folder, $ci, $otherDefinition);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-activity-type-usage="3"', $this->managementRow($content, $definition));
        $this->assertStringContainsString('data-activity-type-usage="1"', $this->managementRow($content, $otherDefinition));
    }

    public function test_editing_a_custom_type_renames_the_definition_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verifcation');
        $activity = $this->customActivity($folder, $ci, $definition);

        $this->actingAs($ci)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Credit Verification'])
            ->assertOk();

        $this->assertSame('Credit Verification', $definition->fresh()->name);
        $this->assertSame($definition->id, $activity->fresh()->activity_definition_id);
        $this->assertSame($activity->id, $activity->fresh()->id);
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
    }

    public function test_editing_rejects_reserved_and_duplicate_activity_type_names(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition('Credit Verification');
        $this->customDefinition('Employment Check');
        $this->actingAs($ci);

        $this->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Barangay Check'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Residence Check'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'employment check'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame('Credit Verification', $definition->fresh()->name);
    }

    public function test_management_rows_carry_the_state_and_name_data_the_tabs_and_search_filter_on(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $active = $this->customDefinition('Credit Verification');
        $inactive = $this->customDefinition('Employment Check', false);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-activity-type-name="Credit Verification"', $this->managementRow($content, $active));
        $this->assertStringContainsString('data-activity-type-state="inactive"', $this->managementRow($content, $inactive));
        // Four system rows are always active; the custom pair adds one of each state.
        $this->assertSame(5, substr_count($content, 'data-activity-type-state="active"'));
        $this->assertSame(1, substr_count($content, 'data-activity-type-state="inactive"'));
        $this->assertSame(1, substr_count($content, 'data-activity-type-id="'.$active->id.'"'));
    }

    public function test_inactive_custom_type_activities_stay_visible_in_global_ci_activities(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Inactive Type Client');
        $definition = $this->customDefinition('Credit Verification');
        $this->customActivity($folder, $ci, $definition);

        $this->actingAs($ci)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])
            ->assertOk();

        $global = $this->get(route('ci-activities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('INACTIVE TYPE CLIENT', $global);
        $this->assertStringContainsString('>Credit Verification</span>', $global);

        $filtered = $this->get(route('ci-activities.index', ['activity_type' => $definition->code]))->assertOk()->getContent();
        $this->assertStringContainsString('INACTIVE TYPE CLIENT', $filtered);
    }

    public function test_deactivate_confirmation_explains_an_in_use_activity_type(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->customActivity($folder, $ci, $this->customDefinition('Credit Verification'));

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString(
            "usedMessage: 'This activity type is already in use. It will be removed from future selection, while all existing records will be preserved.',",
            $content,
        );
        // The wording is chosen from the row's authoritative usage count, not from a click.
        $this->assertStringContainsString(
            "const isInUse = Number(row.dataset.activityTypeUsage ?? '0') > 0;",
            $content,
        );
    }

    public function test_creating_a_custom_type_shows_a_generic_one_shot_success_message(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Credit Verification',
        ])->assertRedirect()->assertSessionHas('status', 'Activity type created successfully.')
            ->baseResponse->headers->get('location');

        $page = $this->get($content)->assertOk()->getContent();
        $this->assertStringContainsString('role="status" data-ci-activity-success>', $page);
        $this->assertStringContainsString('Activity type created successfully.', $page);
        // Never names the type, so it can never read as feedback for a later selection.
        $this->assertStringNotContainsString('Credit Verification activity type', $page);
        $this->assertStringNotContainsString('is ready to use', $page);
        // The newly created type is still selectable through the same authoritative flow.
        $definition = ActivityDefinition::query()->where('name', 'Credit Verification')->sole();
        $this->assertStringContainsString('data-activity-type-option data-value="'.$definition->id.'"', $page);
    }

    public function test_the_creation_success_message_is_dismissed_on_selection_change_and_on_close(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        // One dismissal helper, wired to the Activity Type change and to the modal close.
        $this->assertStringContainsString("document.querySelector('[data-ci-activity-success]')?.remove();", $content);
        $this->assertStringContainsString("select.addEventListener('change', dismissActivityTypeSuccess);", $content);
        $this->assertMatchesRegularExpression(
            '/const finishClose = \(\) => \{\s*closeTimer = null;\s*dismissActivityTypeSuccess\(\);/s',
            $content,
        );
    }

    public function test_a_fresh_add_activity_modal_carries_no_success_message(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->customDefinition('Credit Verification');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('role="status" data-ci-activity-success>', $content);
        $this->assertStringNotContainsString('Activity type created successfully.', $content);
    }

    public function test_a_later_creation_shows_the_message_again(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci);

        foreach (['Credit Verification', 'Employment Verification'] as $name) {
            $redirect = $this->post(route('client-folders.activities.store', $folder), [
                'co_maker_id' => null,
                'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
                'create_new_activity_type' => true,
                'new_activity_type' => $name,
            ])->assertRedirect()->assertSessionHas('status', 'Activity type created successfully.')
                ->baseResponse->headers->get('location');

            $this->assertStringContainsString('Activity type created successfully.', $this->get($redirect)->assertOk()->getContent());
            // The flash is consumed by that render, so the next plain visit is clean again.
            $this->assertStringNotContainsString('role="status" data-ci-activity-success>', $this->get($redirect)->assertOk()->getContent());
        }

        $this->assertSame(2, ActivityDefinition::query()->where('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%')->count());
    }

    public function test_add_activity_picker_is_selection_only_for_custom_types(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $first = $this->customDefinition('Credit Verification');
        $second = $this->customDefinition('Employment Verification');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $panel = $this->activityTypeOptions($content);

        // Both custom types are listed and selectable by their authoritative definition id.
        $this->assertStringContainsString('My Custom Activity Types', $panel);
        foreach ([$first, $second] as $definition) {
            $this->assertStringContainsString('data-activity-type-option data-value="'.$definition->id.'"', $panel);
            $this->assertStringContainsString('data-label="'.$definition->name.'"', $panel);
            $this->assertStringContainsString('data-custom-activity-type-row="'.$definition->id.'"', $panel);
        }
        $this->assertStringContainsString('+ Add New Activity Type', $panel);

        // No management control of any kind survives inside the picker.
        $this->assertStringNotContainsString('data-activity-type-remove', $panel);
        $this->assertStringNotContainsString('&minus;', $panel);
        $this->assertStringNotContainsString('text-danger', $panel);
        $this->assertStringNotContainsString('data-activity-type-edit', $panel);
        $this->assertStringNotContainsString('data-activity-type-deactivate', $panel);
        $this->assertStringNotContainsString('data-activity-type-delete', $panel);
        $this->assertStringNotContainsString('Remove Activity Type', $content);
        $this->assertStringNotContainsString('id="remove-activity-definition-'.$first->id.'"', $content);
    }

    public function test_add_activity_picker_lists_only_active_custom_types_and_follows_reactivation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $active = $this->customDefinition('Credit Verification');
        $inactive = $this->customDefinition('Employment Check', false);

        $panel = $this->activityTypeOptions(
            $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
        );
        $this->assertStringContainsString('data-label="Credit Verification"', $panel);
        $this->assertStringNotContainsString('data-label="Employment Check"', $panel);
        $this->assertStringNotContainsString('data-activity-type-option data-value="'.$inactive->id.'"', $panel);

        $this->patchJson(route('client-folders.activity-definitions.activation', [$folder, $inactive]), ['is_active' => true])->assertOk();

        $panel = $this->activityTypeOptions(
            $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
        );
        $this->assertStringContainsString('data-label="Employment Check"', $panel);
        $this->assertStringContainsString('data-activity-type-option data-value="'.$inactive->id.'"', $panel);
        $this->assertStringContainsString('data-label="Credit Verification"', $panel);
        $this->assertSame($active->id, $active->fresh()->id);
    }

    public function test_a_selected_custom_type_still_creates_an_activity_for_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $definition = $this->customDefinition('Credit Verification');
        $definitionCount = ActivityDefinition::query()->count();

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'activity_definition_id' => $definition->id,
            'create_new_activity_type' => false,
            'status' => ActivityStatus::Pending->value,
        ])->assertRedirect();

        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $activity = CiActivity::query()->where('activity_definition_id', $definition->id)->sole();
        $this->assertSame($coMaker->id, $activity->co_maker_id);
        $this->assertSame($folder->id, $activity->client_folder_id);
        $this->assertSame('Credit Verification', $activity->name);
    }

    private function activityTypeOptions(string $content): string
    {
        $start = strpos($content, 'data-activity-type-options');
        $this->assertNotFalse($start);
        $end = strpos($content, '</div>', strpos($content, '+ Add New Activity Type', $start));
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }

    private function folderFor(User $ci, ?string $displayName = null): ClientFolder
    {
        return ClientFolder::factory()->create(array_filter([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => $displayName,
        ]));
    }

    private function customDefinition(string $name, bool $active = true): ActivityDefinition
    {
        return ActivityDefinition::create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.str()->uuid(),
            'name' => $name,
            'sort_order' => 500,
            'is_required' => false,
            'is_active' => $active,
        ]);
    }

    private function customActivity(ClientFolder $folder, User $creator, ActivityDefinition $definition, array $overrides = []): CiActivity
    {
        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
            'status' => ActivityStatus::Pending,
        ], $overrides));
    }

    private function managementRow(string $content, ActivityDefinition $definition): string
    {
        $start = strpos($content, 'data-activity-type-id="'.$definition->id.'"');
        $this->assertNotFalse($start, 'Activity type row not found for '.$definition->name);
        $rowStart = strrpos(substr($content, 0, $start), '<tr ');
        $end = strpos($content, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($content, $rowStart, $end - $rowStart);
    }
}
