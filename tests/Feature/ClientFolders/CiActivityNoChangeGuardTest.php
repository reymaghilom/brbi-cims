<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edit forms that would persist nothing must say so instead of issuing the update, and the
 * CI Activities table names its creator column "Created By".
 */
class CiActivityNoChangeGuardTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'No changes detected. Nothing needs to be updated.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_bank_coop_edit_form_carries_the_no_change_guard_and_notice(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $activity->bankTargets()->create([
            'inquiry_type' => 'bank_coop_check',
            'institution_name' => 'BPI Cagayan de Oro',
            'status' => 'pending',
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $form = $this->formMarkup(
            $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))->assertOk()->getContent(),
            'data-bank-target-edit-form="'.$target->id.'"',
        );

        $this->assertStringContainsString('data-no-change-guard', $form);
        $this->assertStringContainsString('data-no-change-message', $form);
        $this->assertStringContainsString(self::NOTICE, $form);
        $this->assertStringContainsString('role="status" aria-live="polite" hidden', $form);
        // The update route and method are untouched — only the guard is new.
        $this->assertStringContainsString(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), $form);
    }

    public function test_a_real_bank_coop_edit_still_saves(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $activity->bankTargets()->create([
            'inquiry_type' => 'bank_coop_check',
            'institution_name' => 'BPI Cagayan de Oro',
            'status' => 'pending',
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $this->actingAs($ci)->put(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), [
            'co_maker_id' => null,
            'inquiry_type' => 'bank_coop_check',
            'institution_name' => 'BPI Divisoria',
            'status' => 'pending',
        ])->assertRedirect();

        $this->assertSame($target->id, $target->fresh()->id);
        $this->assertSame('BPI Divisoria', $target->fresh()->institution_name);
    }

    public function test_asset_edit_form_carries_the_no_change_guard_and_notice(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $target = $activity->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Cagayan de Oro City Hall',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.asset-check.show', [$folder, $activity]))->assertOk()->getContent();
        $start = strpos($content, 'id="edit-asset-target-'.$target->id.'"');
        $this->assertNotFalse($start);
        $form = substr($content, $start, strpos($content, '</dialog>', $start) - $start);

        $this->assertStringContainsString('data-asset-target-form data-no-change-guard', $form);
        $this->assertStringContainsString('data-no-change-message', $form);
        $this->assertStringContainsString(self::NOTICE, $form);
        $this->assertStringContainsString(route('client-folders.activities.asset-targets.update', [$folder, $activity, $target]), $form);
    }

    public function test_a_real_asset_edit_still_saves(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $target = $activity->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Cagayan de Oro City Hall',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $this->actingAs($ci)->put(route('client-folders.activities.asset-targets.update', [$folder, $activity, $target]), [
            'co_maker_id' => null,
            'assessor_type' => 'city_assessor',
            'office_location' => 'Provincial Assessor Office',
            'status' => ActivityStatus::Pending->value,
        ])->assertRedirect();

        $this->assertSame($target->id, $target->fresh()->id);
        $this->assertSame('Provincial Assessor Office', $target->fresh()->office_location);
        $this->assertSame(1, $activity->fresh()->assetTargets()->count());
    }

    public function test_the_custom_activity_type_edit_dialog_guards_an_unchanged_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->customDefinition('Credit Verification');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-activity-type-edit-no-changes', $content);
        $this->assertStringContainsString(self::NOTICE, $content);
        // The dialog compares against the row's own current name and returns before any request.
        $this->assertStringContainsString("if (name === (activeRow.dataset.activityTypeName ?? '')) {", $content);
        $this->assertMatchesRegularExpression(
            "/if \\(name === \\(activeRow\\.dataset\\.activityTypeName \\?\\? ''\\)\\) \\{\\s*if \\(editNoChanges instanceof HTMLElement\\) editNoChanges\\.hidden = false;\\s*return;/s",
            $content,
        );
    }

    public function test_the_ci_activities_table_names_its_creator_column_created_by(): void
    {
        $ci = User::factory()->create(['full_name' => 'Rey Maghilom']);
        $other = User::factory()->create(['full_name' => 'Bea Santos']);
        $folder = $this->folderFor($ci);
        $custom = $this->customDefinition('Credit Verification');

        $pending = $this->activity($folder, $other, ActivityDefinition::BARANGAY_CHECK_CODE, ['name' => 'Barangay Pending']);
        $scheduled = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'name' => 'Neighbor Scheduled',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDays(3),
        ]);
        $completed = CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $custom->id,
            'name' => $custom->name,
            'status' => ActivityStatus::Completed,
            'creator_id' => $other->id,
            'updated_by' => $ci->id,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('>Created By', $content);
        $this->assertStringNotContainsString('Locked creator', $content);
        $this->assertStringNotContainsString('Locked Creator', $content);

        // The creator shown is the CI Activity's own creator, whatever its status, and it is the
        // activity's creator — never the person who created the reusable Activity Type.
        $this->assertStringContainsString('>Bea Santos</span>', $this->activityRow($content, $pending));
        $this->assertStringContainsString('>Rey Maghilom</span>', $this->activityRow($content, $scheduled));
        $this->assertStringContainsString('>Bea Santos</span>', $this->activityRow($content, $completed));

        // Nothing about creator ownership was touched.
        $this->assertSame($other->id, $pending->fresh()->creator_id);
        $this->assertSame($ci->id, $scheduled->fresh()->creator_id);
        $this->assertSame($other->id, $completed->fresh()->creator_id);
        $this->assertSame($ci->id, $completed->fresh()->updated_by);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function customDefinition(string $name): ActivityDefinition
    {
        return ActivityDefinition::create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.str()->uuid(),
            'name' => $name,
            'sort_order' => 500,
            'is_required' => false,
            'is_active' => true,
        ]);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, array $overrides = []): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ], $overrides));
    }

    private function formMarkup(string $content, string $needle): string
    {
        $start = strpos($content, $needle);
        $this->assertNotFalse($start, 'Form not found for '.$needle);
        $formStart = strrpos(substr($content, 0, $start), '<form ');
        $end = strpos($content, '</form>', $start);
        $this->assertNotFalse($end);

        return substr($content, $formStart, $end - $formStart);
    }

    private function activityRow(string $content, CiActivity $activity): string
    {
        $start = strpos($content, 'id="activity-'.$activity->id.'"');
        $this->assertNotFalse($start, 'Row not found for activity '.$activity->id);
        $end = strpos($content, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }
}
