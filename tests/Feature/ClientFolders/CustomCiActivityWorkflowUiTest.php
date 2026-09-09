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

class CustomCiActivityWorkflowUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_custom_row_menu_offers_only_iconed_edit_and_delete(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Custom Field Verification');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $row = $this->activityRow($content, $activity);

        $this->assertSame(1, substr_count($row, 'aria-label="Actions for '.$activity->name.'"'));
        $this->assertSame(2, substr_count($row, 'role="menuitem"'));
        $this->assertMatchesRegularExpression('/data-default-check-open="'.$activity->id.'"[^>]*><svg[^>]*>.*?<\/svg>\s*Edit<\/button>/s', $row);
        $this->assertStringContainsString('data-default-check-url="'.e(route('client-folders.activities.custom-check.show', [$folder, $activity])).'"', $row);
        $this->assertMatchesRegularExpression('/data-modal-open="delete-activity-'.$activity->id.'"[^>]*><svg[^>]*>.*?<\/svg>\s*Delete<\/button>/s', $row);
        $this->assertStringNotContainsString('>Open</button>', $row);
        $this->assertStringNotContainsString('Schedule / Reschedule', $row);
        $this->assertStringNotContainsString('View notes', $row);
        $this->assertStringNotContainsString('Manage proof', $row);
        $this->assertStringNotContainsString('Delete Activity', $row);
    }

    public function test_edit_modal_title_uses_the_actual_custom_activity_name_with_an_edit_icon(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Credit Verification');

        $content = $this->actingAs($ci)
            ->get(route('client-folders.activities.custom-check.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<h2 id="default-check-title"[^>]*><svg[^>]*>.*?<\/svg>\s*<span[^>]*>Edit Credit Verification<\/span><\/h2>/s', $content);
        $this->assertStringNotContainsString('Edit New Activity', $content);
    }

    public function test_edit_modal_exposes_status_schedule_time_and_short_remarks_with_barangay_actions(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Employment Verification', [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay()->setTime(14, 30),
            'scheduled_has_time' => true,
            'remarks' => 'Verify employment tenure.',
        ]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.activities.custom-check.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.e(route('client-folders.activities.update', [$folder, $activity])).'"', $content);
        $this->assertStringContainsString('>Status</label>', $content);
        $this->assertStringContainsString('<select id="default-check-status-'.$activity->id.'" name="status"', $content);
        $this->assertStringContainsString('Schedule / Follow-up Date', $content);
        $this->assertStringContainsString('<input id="default-check-date-'.$activity->id.'" name="scheduled_at" type="date"', $content);
        $this->assertStringContainsString('<input id="default-check-time-'.$activity->id.'" name="scheduled_time" type="time"', $content);
        $this->assertStringContainsString('Short Remarks', $content);
        $this->assertStringContainsString('name="remarks"', $content);
        $this->assertStringContainsString('Verify employment tenure.', $content);
        $this->assertMatchesRegularExpression('/data-default-check-cancel><svg[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $content);
        $this->assertMatchesRegularExpression('/data-default-check-submit><svg[^>]*>.*?<\/svg>\s*Save Changes<\/button>/s', $content);
    }

    public function test_saving_changes_updates_the_same_custom_activity_without_creating_another(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Credit Verification');
        $definitionCount = ActivityDefinition::query()->count();
        $activityCount = CiActivity::query()->count();
        $scheduledDate = now()->addDays(3)->format('Y-m-d');

        $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $activity]), [
            'co_maker_id' => null,
            'expected_updated_at' => $activity->updated_at->toISOString(),
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => $scheduledDate,
            'scheduled_time' => '09:45',
            'remarks' => 'Coordinate with the employer HR desk.',
            'intent' => 'return',
        ])->assertOk();

        $this->assertSame($activityCount, CiActivity::query()->count());
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $fresh = $activity->fresh();
        $this->assertSame(ActivityStatus::Scheduled, $fresh->status);
        $this->assertNull($fresh->co_maker_id);
        $this->assertSame('Coordinate with the employer HR desk.', $fresh->remarks);
        $this->assertSame(
            $scheduledDate.' 09:45',
            $fresh->scheduled_at->timezone(config('cims.display_timezone'))->format('Y-m-d H:i')
        );

        $reloaded = $this->get(route('client-folders.activities.custom-check.show', [$folder, $activity]))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$scheduledDate.'"', $reloaded);
        $this->assertStringContainsString('value="09:45"', $reloaded);
        $this->assertStringContainsString('Coordinate with the employer HR desk.', $reloaded);
    }

    public function test_completed_custom_activity_uses_the_same_green_checkmark_as_barangay_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $barangay = $this->activityForDefinition(
            $folder,
            $ci,
            ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->firstOrFail(),
            null,
            'Barangay reference'
        );
        $activity = $this->customActivity($folder, $ci, null, 'Credit Verification');

        $pending = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $pendingRow = $this->activityRow($pending, $activity);
        $this->assertStringContainsString('class="ci-completion-checkbox"', $pendingRow);
        $this->assertStringContainsString('class="ci-completion-checkbox"', $this->activityRow($pending, $barangay));
        $this->assertDoesNotMatchRegularExpression('/data-ci-activity-completion="'.$activity->id.'"[^>]*\schecked(?:\s|=|>)/s', $pendingRow);
        $this->assertStringContainsString('>Pending</span>', $pendingRow);

        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), [
            'co_maker_id' => null,
            'expected_updated_at' => $activity->fresh()->updated_at->toISOString(),
            'status' => ActivityStatus::Completed->value,
            'intent' => 'return',
        ])->assertOk();

        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
        $completedRow = $this->activityRow($this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(), $activity);
        $this->assertStringContainsString('class="ci-completion-checkbox"', $completedRow);
        $this->assertMatchesRegularExpression('/data-ci-activity-completion="'.$activity->id.'"[^>]*checked[^>]*disabled/s', $completedRow);
        $this->assertStringContainsString('>Completed</span>', $completedRow);
        $this->assertStringContainsString('Credit Verification', $completedRow);
    }

    public function test_custom_edit_and_delete_remain_exact_person_scoped(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $definition = $this->customDefinition('Custom Scope Check');
        $applicant = $this->activityForDefinition($folder, $ci, $definition, null, 'APPLICANT ONLY');
        $activityA = $this->activityForDefinition($folder, $ci, $definition, $coMakerA, 'CO-MAKER A ONLY');
        $activityB = $this->activityForDefinition($folder, $ci, $definition, $coMakerB, 'CO-MAKER B ONLY');
        $this->actingAs($ci);

        $this->get(route('client-folders.activities.custom-check.show', [$folder, $activityA]))->assertNotFound();
        $this->get(route('client-folders.activities.custom-check.show', [$folder, $activityA, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id]))->assertNotFound();
        $this->get(route('client-folders.activities.custom-check.show', [$folder, $activityA, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]))
            ->assertOk()
            ->assertSee('Co-Maker: ALPHA MAKER');

        $this->put(route('client-folders.activities.update', [$folder, $activityA]), ['co_maker_id' => $coMakerB->id, 'status' => 'completed'])->assertForbidden();
        $this->assertSame(ActivityStatus::Pending, $activityA->fresh()->status);

        $this->put(route('client-folders.activities.update', [$folder, $applicant]), ['co_maker_id' => null, 'status' => 'completed'])->assertRedirect();
        $this->assertSame(ActivityStatus::Completed, $applicant->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $activityA->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $activityB->fresh()->status);

        $this->put(route('client-folders.activities.update', [$folder, $activityA]), ['co_maker_id' => $coMakerA->id, 'status' => 'completed'])->assertRedirect();
        $this->assertSame(ActivityStatus::Completed, $activityA->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $activityB->fresh()->status);

        $this->delete(route('client-folders.activities.destroy', [$folder, $activityA]), ['co_maker_id' => $coMakerB->id])->assertNotFound();
        $this->delete(route('client-folders.activities.destroy', [$folder, $activityA]), ['co_maker_id' => $coMakerA->id])->assertRedirect();
        $this->assertDatabaseMissing('ci_activities', ['id' => $activityA->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $applicant->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $activityB->id]);
    }

    public function test_custom_check_route_rejects_canonical_activity_types(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $barangay = $this->activityForDefinition(
            $folder,
            $ci,
            ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->firstOrFail(),
            null,
            'Barangay reference'
        );

        $this->actingAs($ci)->get(route('client-folders.activities.custom-check.show', [$folder, $barangay]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $barangay]))->assertOk();
    }

    public function test_completion_modal_uses_iconed_cancel_edit_and_complete_actions(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $this->customActivity($folder, $ci, null, 'Credit Verification');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $start = strpos($content, 'data-quick-complete-modal');
        $this->assertNotFalse($start);
        $dialog = substr($content, $start, strpos($content, '</dialog>', $start) - $start);

        $this->assertMatchesRegularExpression('/<svg[^>]*>.*?<\/svg>\s*Complete this activity\?/s', $dialog);
        $this->assertMatchesRegularExpression('/data-quick-complete-cancel><svg[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $dialog);
        $this->assertMatchesRegularExpression('/data-quick-complete-edit><svg[^>]*>.*?<\/svg>\s*Edit<\/button>/s', $dialog);
        $this->assertMatchesRegularExpression('/data-quick-complete-confirm><svg[^>]*>.*?<\/svg>/s', $dialog);
    }

    public function test_custom_delete_dialog_uses_iconed_cancel_and_delete_for_the_exact_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Custom Delete Check');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $start = strpos($content, 'id="delete-activity-'.$activity->id.'"');
        $this->assertNotFalse($start);
        $dialog = substr($content, $start, strpos($content, '</dialog>', $start) - $start);

        $this->assertStringContainsString('action="'.route('client-folders.activities.destroy', [$folder, $activity]).'"', $dialog);
        $this->assertMatchesRegularExpression('/data-modal-close class="ui-button-secondary"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $dialog);
        $this->assertMatchesRegularExpression('/class="ui-button-danger"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Delete<\/button>/s', $dialog);
        $this->assertStringNotContainsString('Permanently Delete</button>', $dialog);
    }

    private function customActivity(ClientFolder $folder, User $creator, ?CoMaker $coMaker, string $name, array $overrides = []): CiActivity
    {
        return $this->activityForDefinition($folder, $creator, $this->customDefinition($name), $coMaker, $name, $overrides);
    }

    private function customDefinition(string $name): ActivityDefinition
    {
        return ActivityDefinition::create([
            'name' => $name,
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.str()->uuid(),
            'sort_order' => 500,
            'is_required' => false,
            'is_active' => true,
        ]);
    }

    private function activityForDefinition(ClientFolder $folder, User $creator, ActivityDefinition $definition, ?CoMaker $coMaker, string $remarks, array $overrides = []): CiActivity
    {
        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker?->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'remarks' => $remarks,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ], $overrides));
    }

    private function activityRow(string $content, CiActivity $activity): string
    {
        $start = strpos($content, 'id="activity-'.$activity->id.'"');
        $this->assertNotFalse($start);
        $end = strpos($content, '</tr>', $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }
}
