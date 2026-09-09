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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Renaming a custom Activity Type edits exactly one ActivityDefinition row; every linked
 * CI Activity resolves its displayed name through that definition, so no CiActivity row is
 * rewritten and no status, schedule, remark or person scope moves.
 */
class CustomActivityTypeRenameTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_NAME = 'Credit Verification';

    private const NEW_NAME = 'Credit Background Verification';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', config('cims.display_timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renaming_keeps_a_pending_activity_intact_and_shows_the_new_name_everywhere(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Pending Rename Client');
        $definition = $this->customDefinition(self::OLD_NAME);
        $activity = $this->customActivity($folder, $ci, $definition);
        $definitionCount = ActivityDefinition::query()->count();
        $activityCount = CiActivity::query()->count();

        $this->rename($ci, $folder, $definition);

        $this->assertSame($definition->id, $definition->fresh()->id);
        $this->assertSame(self::NEW_NAME, $definition->fresh()->name);
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $this->assertSame($activityCount, CiActivity::query()->count());
        $this->assertSame($activity->id, $activity->fresh()->id);
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->assertSame($definition->id, $activity->fresh()->activity_definition_id);

        $folderPage = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $row = $this->activityRow($folderPage, $activity);
        $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $row);
        $this->assertStringNotContainsString(self::OLD_NAME, $row);

        $global = $this->get(route('ci-activities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>'.self::NEW_NAME.'</span>', $global);
        $this->assertStringNotContainsString(self::OLD_NAME, $global);
    }

    public function test_renaming_leaves_a_scheduled_activity_schedule_time_and_remarks_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Scheduled Rename Client');
        $definition = $this->customDefinition(self::OLD_NAME);
        // Stored in UTC exactly like the app writes it; 10:00 AM in the display timezone.
        $scheduledAt = Carbon::parse('2026-09-15 10:00:00', config('cims.display_timezone'))->utc();
        $activity = $this->customActivity($folder, $ci, $definition, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => true,
            'remarks' => 'Coordinate with the employer HR desk.',
        ]);

        $this->rename($ci, $folder, $definition);

        $fresh = $activity->fresh();
        $this->assertSame($activity->id, $fresh->id);
        $this->assertSame(ActivityStatus::Scheduled, $fresh->status);
        $this->assertSame(
            '2026-09-15 10:00',
            $fresh->scheduled_at->timezone(config('cims.display_timezone'))->format('Y-m-d H:i'),
        );
        $this->assertTrue((bool) $fresh->scheduled_has_time);
        $this->assertSame('Coordinate with the employer HR desk.', $fresh->remarks);

        $row = $this->activityRow(
            $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
            $activity,
        );
        $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $row);
        $this->assertStringContainsString('Sep 15, 2026', $row);
        $this->assertStringContainsString('10:00 AM', $row);

        // The custom Edit modal title follows the renamed definition too.
        $editModal = $this->get(route('client-folders.activities.custom-check.show', [$folder, $activity]))->assertOk()->getContent();
        $this->assertStringContainsString('Edit '.self::NEW_NAME.'</span></h2>', $editModal);
        $this->assertStringContainsString('value="2026-09-15"', $editModal);
        $this->assertStringContainsString('value="10:00"', $editModal);
        $this->assertStringContainsString('Coordinate with the employer HR desk.', $editModal);
        $this->assertStringNotContainsString(self::OLD_NAME, $editModal);
    }

    public function test_renaming_preserves_a_completed_activity_and_its_green_checkmark(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Completed Rename Client');
        $definition = $this->customDefinition(self::OLD_NAME);
        $activity = $this->customActivity($folder, $ci, $definition, ['status' => ActivityStatus::Completed]);
        $completedUpdatedAt = $activity->updated_at;

        $this->rename($ci, $folder, $definition);

        $fresh = $activity->fresh();
        $this->assertSame($activity->id, $fresh->id);
        $this->assertSame(ActivityStatus::Completed, $fresh->status);
        $this->assertSame($completedUpdatedAt->format('Y-m-d H:i:s'), $fresh->updated_at->format('Y-m-d H:i:s'));

        $row = $this->activityRow(
            $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
            $activity,
        );
        $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $row);
        $this->assertStringContainsString('>Completed</span>', $row);
        // Same checkbox convention as Barangay / Neighbor Check, still checked and locked.
        $this->assertStringContainsString('class="ci-completion-checkbox"', $row);
        $this->assertMatchesRegularExpression('/data-ci-activity-completion="'.$activity->id.'"[^>]*checked[^>]*disabled/s', $row);
    }

    public function test_renaming_updates_every_linked_status_without_changing_any_of_them(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Mixed Status Client');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $definition = $this->customDefinition(self::OLD_NAME);
        $pending = $this->customActivity($folder, $ci, $definition);
        $scheduled = $this->customActivity($folder, $ci, $definition, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-15 10:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);
        $completed = $this->customActivity($folder, $ci, $definition, [
            'co_maker_id' => $coMaker->id,
            'status' => ActivityStatus::Completed,
        ]);

        $globalLabelCount = substr_count(
            $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 50]))->assertOk()->getContent(),
            '>'.self::OLD_NAME.'</span>',
        );
        $this->assertGreaterThanOrEqual(3, $globalLabelCount);

        $this->rename($ci, $folder, $definition);

        $this->assertSame(ActivityStatus::Pending, $pending->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $scheduled->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $completed->fresh()->status);
        $this->assertNull($pending->fresh()->co_maker_id);
        $this->assertNull($scheduled->fresh()->co_maker_id);
        $this->assertSame($coMaker->id, $completed->fresh()->co_maker_id);

        $applicantPage = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        foreach ([$pending, $scheduled] as $activity) {
            $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $this->activityRow($applicantPage, $activity));
        }
        $coMakerPage = $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent();
        $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $this->activityRow($coMakerPage, $completed));
        // No activity row keeps the old name; it survives only where it must — the audit trail.
        foreach ([$pending, $scheduled] as $activity) {
            $this->assertStringNotContainsString(self::OLD_NAME, $this->activityRow($applicantPage, $activity));
        }
        $this->assertStringContainsString(
            e('Updated Activity Type from "'.self::OLD_NAME.'" to "'.self::NEW_NAME.'"'),
            $applicantPage,
        );

        $global = $this->get(route('ci-activities.index', ['per_page' => 50]))->assertOk()->getContent();
        $this->assertSame($globalLabelCount, substr_count($global, '>'.self::NEW_NAME.'</span>'));
        $this->assertStringNotContainsString(self::OLD_NAME, $global);
    }

    public function test_global_activity_type_filter_uses_the_new_name_and_the_same_definition_identity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Filter Rename Client');
        $definition = $this->customDefinition(self::OLD_NAME);
        $this->customActivity($folder, $ci, $definition);
        $this->customActivity($folder, $ci, $definition, ['status' => ActivityStatus::Completed]);

        $filteredLabelCount = substr_count(
            $this->actingAs($ci)->get(route('ci-activities.index', ['activity_type' => $definition->code, 'per_page' => 50]))->assertOk()->getContent(),
            '>'.self::OLD_NAME.'</span>',
        );
        $this->assertGreaterThanOrEqual(2, $filteredLabelCount);

        $this->rename($ci, $folder, $definition);

        $global = $this->get(route('ci-activities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="'.$definition->code.'"', $global);
        $this->assertSame(1, substr_count($global, '>'.self::NEW_NAME.'</option>'));
        $this->assertStringNotContainsString('>'.self::OLD_NAME.'</option>', $global);

        // Identity is the definition code, untouched by the rename, so filtering still matches.
        $this->assertSame($definition->code, $definition->fresh()->code);
        $filtered = $this->get(route('ci-activities.index', ['activity_type' => $definition->code, 'per_page' => 50]))->assertOk()->getContent();
        $this->assertStringContainsString('FILTER RENAME CLIENT', $filtered);
        $this->assertSame($filteredLabelCount, substr_count($filtered, '>'.self::NEW_NAME.'</span>'));
        $this->assertStringNotContainsString(self::OLD_NAME, $filtered);
    }

    public function test_active_renamed_type_is_offered_under_its_new_name_in_add_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition(self::OLD_NAME);

        $this->rename($ci, $folder, $definition);

        $content = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-activity-type-option data-value="'.$definition->id.'"', $content);
        $this->assertStringContainsString('data-label="'.self::NEW_NAME.'"', $content);
        $this->assertStringNotContainsString('data-label="'.self::OLD_NAME.'"', $content);
        $this->assertTrue($definition->fresh()->is_active);

        // Selecting it still creates against the very same definition.
        $definitionCount = ActivityDefinition::query()->count();
        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => $definition->id,
            'create_new_activity_type' => false,
            'status' => ActivityStatus::Pending->value,
        ])->assertRedirect();
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $this->assertSame(1, CiActivity::query()->where('activity_definition_id', $definition->id)->count());
    }

    public function test_renaming_an_inactive_type_keeps_it_inactive_while_records_show_the_new_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Inactive Rename Client');
        $definition = $this->customDefinition(self::OLD_NAME, false);
        $pending = $this->customActivity($folder, $ci, $definition);
        $completed = $this->customActivity($folder, $ci, $definition, ['status' => ActivityStatus::Completed]);

        $this->rename($ci, $folder, $definition);

        $this->assertFalse($definition->fresh()->is_active);
        $this->assertSame(ActivityStatus::Pending, $pending->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $completed->fresh()->status);

        $content = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $this->activityRow($content, $pending));
        $this->assertStringContainsString('>'.self::NEW_NAME.'</p>', $this->activityRow($content, $completed));
        // Still not selectable for new activities, and still listed as Inactive for management.
        $this->assertStringNotContainsString('data-activity-type-option data-value="'.$definition->id.'"', $content);
        $this->assertStringContainsString('data-activity-type-name="'.self::NEW_NAME.'"', $content);
        $this->assertStringContainsString('data-activity-type-state="inactive"', $content);

        $global = $this->get(route('ci-activities.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>'.self::NEW_NAME.'</span>', $global);
        $this->assertStringNotContainsString(self::OLD_NAME, $global);
    }

    public function test_renaming_does_not_change_the_used_in_activities_count(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->customDefinition(self::OLD_NAME);
        foreach ([ActivityStatus::Pending, ActivityStatus::Scheduled, ActivityStatus::Completed] as $status) {
            $this->customActivity($folder, $ci, $definition, ['status' => $status]);
        }

        $before = $this->managementRow(
            $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
            $definition,
        );
        $this->assertStringContainsString('data-activity-type-usage="3"', $before);

        $this->rename($ci, $folder, $definition);

        $after = $this->managementRow(
            $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(),
            $definition,
        );
        $this->assertStringContainsString('data-activity-type-usage="3"', $after);
        $this->assertStringContainsString('data-activity-type-name="'.self::NEW_NAME.'"', $after);
        $this->assertStringContainsString('data-activity-type-state="active"', $after);
    }

    private function rename(User $ci, ClientFolder $folder, ActivityDefinition $definition, string $name = self::NEW_NAME): void
    {
        $this->actingAs($ci)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => $name])
            ->assertOk();
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
            // The creation-time snapshot deliberately keeps the old name: display must resolve
            // through the definition, not through this column.
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
            'status' => ActivityStatus::Pending,
        ], $overrides));
    }

    private function activityRow(string $content, CiActivity $activity): string
    {
        $start = strpos($content, 'id="activity-'.$activity->id.'"');
        $this->assertNotFalse($start, 'Activity row not found for id '.$activity->id);
        $end = strpos($content, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }

    private function managementRow(string $content, ActivityDefinition $definition): string
    {
        $start = strpos($content, 'data-activity-type-id="'.$definition->id.'"');
        $this->assertNotFalse($start);
        $rowStart = strrpos(substr($content, 0, $start), '<tr ');
        $end = strpos($content, '</tr>', $start);

        return substr($content, $rowStart, $end - $rowStart);
    }
}
