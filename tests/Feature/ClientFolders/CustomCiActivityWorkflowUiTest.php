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

    public function test_custom_row_uses_one_native_checkbox_and_an_open_delete_menu_only(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Custom Field Verification');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $row = $this->activityRow($content, $activity);

        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*class="[^"]*size-5[^"]*"[^>]*data-ci-activity-completion="'.$activity->id.'"[^>]*data-completion-kind="custom"[^>]*>/s', $row);
        $this->assertStringContainsString('data-ci-activity-status-badge="'.$activity->id.'"', $row);
        $this->assertStringContainsString('>Pending</span>', $row);
        $this->assertSame(1, substr_count($row, 'aria-label="Actions for '.$activity->name.'"'));
        $this->assertSame(2, substr_count($row, 'role="menuitem"'));
        $this->assertMatchesRegularExpression('/data-custom-activity-open="'.$activity->id.'"[^>]*><svg[^>]*>.*?<\/svg>\s*Open<\/button>/s', $row);
        $this->assertMatchesRegularExpression('/data-modal-open="delete-activity-'.$activity->id.'"[^>]*><svg[^>]*>.*?<\/svg>\s*Delete<\/button>/s', $row);
        $this->assertStringNotContainsString('Schedule / Reschedule', $row);
        $this->assertStringNotContainsString('title="Complete"', $row);
        $this->assertStringNotContainsString('View notes', $row);
        $this->assertStringNotContainsString('Manage proof', $row);
        $this->assertStringNotContainsString('Delete Activity', $row);
    }

    public function test_open_uses_the_scoped_modal_source_with_existing_custom_data_and_creates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $activity = $this->customActivity($folder, $ci, $coMaker, 'Custom Employer Visit', [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay()->startOfHour(),
            'scheduled_has_time' => true,
            'remarks' => 'Verify employment tenure.',
            'supporting_reference' => 'EMP-REF-42',
        ]);
        $params = [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id];

        $index = $this->actingAs($ci)->get(route('client-folders.activities.index', $params))->assertOk();
        $index
            ->assertSee('data-custom-activity-modal', false)
            ->assertSee('data-custom-activity-open="'.$activity->id.'"', false)
            ->assertSee('data-custom-activity-url="'.e(route('client-folders.activities.edit', [$folder, $activity, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id])).'"', false);
        $this->assertStringNotContainsString('<a href="'.route('client-folders.activities.edit', [$folder, $activity, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]).'"', $this->activityRow($index->getContent(), $activity));

        $before = CiActivity::query()->count();
        $detail = $this->get(route('client-folders.activities.edit', [$folder, $activity, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk();
        $detail
            ->assertSee('data-custom-activity-modal-source', false)
            ->assertSee('data-custom-activity-id="'.$activity->id.'"', false)
            ->assertSee('data-custom-activity-context="Co-Maker: ALPHA MAKER"', false)
            ->assertSee('Custom Employer Visit')
            ->assertSee('Verify employment tenure.')
            ->assertSee('EMP-REF-42')
            ->assertSee('Notes Timeline')
            ->assertSee('Supporting Proof')
            ->assertSee('Schedule / Follow-up Date');
        $this->assertSame($before, CiActivity::query()->count());
    }

    public function test_custom_checkbox_completion_persists_and_reloads_in_sync_with_status(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $activity = $this->customActivity($folder, $ci, null, 'Custom Reference Call');

        $pendingRow = $this->activityRow($this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(), $activity);
        $this->assertDoesNotMatchRegularExpression('/data-ci-activity-completion="'.$activity->id.'"[^>]*\schecked(?:\s|=)/', $pendingRow);

        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), [
            'co_maker_id' => null,
            'expected_updated_at' => $activity->updated_at->toISOString(),
            'status' => 'completed',
            'intent' => 'return',
        ])->assertOk()->assertJson(['updated' => true]);

        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
        $completedRow = $this->activityRow($this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent(), $activity);
        $this->assertMatchesRegularExpression('/data-ci-activity-completion="'.$activity->id.'"[^>]*checked[^>]*disabled/s', $completedRow);
        $this->assertStringContainsString('>Completed</span>', $completedRow);
        $this->assertStringNotContainsString('title="Complete"', $completedRow);
    }

    public function test_custom_open_completion_and_delete_remain_exact_person_scoped(): void
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

        $this->get(route('client-folders.activities.edit', [$folder, $activityA]))->assertNotFound();
        $this->get(route('client-folders.activities.edit', [$folder, $activityA, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id]))->assertNotFound();
        $this->put(route('client-folders.activities.update', [$folder, $activityA]), ['co_maker_id' => $coMakerB->id, 'status' => 'completed'])->assertForbidden();
        $this->assertSame(ActivityStatus::Pending, $activityA->fresh()->status);

        $this->put(route('client-folders.activities.update', [$folder, $applicant]), ['co_maker_id' => null, 'status' => 'completed'])->assertRedirect();
        $this->assertSame(ActivityStatus::Completed, $applicant->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $activityA->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $activityB->fresh()->status);

        $this->delete(route('client-folders.activities.destroy', [$folder, $activityA]), ['co_maker_id' => $coMakerB->id])->assertNotFound();
        $this->delete(route('client-folders.activities.destroy', [$folder, $activityA]), ['co_maker_id' => $coMakerA->id])->assertRedirect();
        $this->assertDatabaseMissing('ci_activities', ['id' => $activityA->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $applicant->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $activityB->id]);
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
