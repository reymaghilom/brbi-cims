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

class CiActivityQuickCompleteModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_barangay_checkbox_carries_confirmation_modal_and_edit_wiring(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('id="quick-complete-activity-modal"', false)
            ->assertSee('Complete this activity?')
            ->assertSee('data-quick-complete-edit', false)
            ->assertSee('data-quick-complete-cancel', false)
            ->assertSee('data-quick-complete-confirm-label>Mark as Completed', false)
            ->assertSee('data-completion-status-label="Pending"', false)
            ->assertSee('data-default-check-open="'.$barangay->id.'"', false);

        $this->assertMatchesRegularExpression('/data-quick-complete-cancel[^>]*>.*?<svg.*?<\/svg>\s*Cancel<\/button>/s', $page->getContent());
        $this->assertMatchesRegularExpression('/data-quick-complete-edit[^>]*>.*?<svg.*?<\/svg>\s*Edit<\/button>/s', $page->getContent());
        $this->assertMatchesRegularExpression('/data-quick-complete-confirm[^>]*>.*?<svg.*?<\/svg>.*?Mark as Completed.*?<\/button>/s', $page->getContent());
    }

    public function test_neighbor_uses_the_exact_same_confirmation_pattern_as_barangay(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-completion-kind="default"', false)
            ->assertSee('data-ci-activity-completion="'.$neighbor->id.'"', false)
            ->assertSee('data-completion-name="'.$neighbor->name.'"', false);
    }

    public function test_scheduled_activity_carries_schedule_text_for_the_confirmation_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $barangay->update([
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => '2026-09-03 09:00:00',
            'scheduled_has_time' => true,
        ]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-completion-status-label="Scheduled"', false)
            ->assertSee('data-completion-schedule-text="Sep 3, 2026', false);
    }

    public function test_activity_with_remarks_carries_remarks_text_for_the_confirmation_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $barangay->update(['remarks' => 'Barangay requested a return visit.']);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-completion-remarks="Barangay requested a return visit."', false);
    }

    public function test_pending_activity_with_no_schedule_or_remarks_carries_empty_attributes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-completion-schedule-text=""', false)
            ->assertSee('data-completion-remarks=""', false);
    }

    public function test_bank_and_asset_checkboxes_do_not_carry_default_completion_attributes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk();
        $this->assertStringNotContainsString('data-completion-status-label', $this->extractCheckboxMarkup($page->getContent(), $bank->id));
        $this->assertStringNotContainsString('data-completion-status-label', $this->extractCheckboxMarkup($page->getContent(), $asset->id));
    }

    public function test_completion_confirmation_note_about_clearing_schedule_remains(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Maker Alpha', 'first_name' => 'Maker', 'last_name' => 'Alpha']);
        $coMakerActivity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $coMaker->id);
        $coMakerParams = ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];
        $note = 'The schedule and time will be cleared. This completion will be recorded in Recent Activity under the user who confirms it.';
        $oldNote = 'Completion will be recorded in Recent Activity under the actual user confirming this action.';

        $this->actingAs($ci);
        foreach ([
            route('client-folders.activities.index', $folder),
            route('client-folders.activities.index', [$folder] + $coMakerParams),
            route('client-folders.activities.default-check.show', [$folder, $applicant]),
            route('client-folders.activities.default-check.show', [$folder, $coMakerActivity] + $coMakerParams),
        ] as $url) {
            $this->get($url)->assertOk()->assertSee($note)->assertDontSee($oldNote);
        }
    }

    public function test_target_and_submission_helper_wording_is_shared_by_applicant_and_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Maker Alpha', 'first_name' => 'Maker', 'last_name' => 'Alpha']);
        $coMakerParams = ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];
        $this->actingAs($ci);

        foreach ([[null, []], [$coMaker->id, $coMakerParams]] as [$coMakerId, $params]) {
            $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, $coMakerId);
            $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE, $coMakerId);
            $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $coMakerId)->update(['status' => ActivityStatus::Completed]);

            $this->get(route('client-folders.activities.bank-coop.show', [$folder, $bank] + $params))->assertOk()
                ->assertSee('Each institution has its own status, schedule, and remarks.')
                ->assertDontSee('Each institution keeps its own status, schedule, and remarks.');
            $this->get(route('client-folders.activities.asset-check.show', [$folder, $asset] + $params))->assertOk()
                ->assertSee('Each office has its own status, schedule, remarks, and updater.')
                ->assertDontSee('Each office keeps its own status, schedule, remarks, and updater.');
            $this->get(route('client-folders.activities.index', [$folder] + $params))->assertOk()
                ->assertSee('placeholder="Include a short handoff or submission note."', false)
                ->assertDontSee('concise handoff or submission note');
        }
    }

    private function extractCheckboxMarkup(string $content, int $activityId): string
    {
        $start = strpos($content, 'data-ci-activity-completion="'.$activityId.'"');
        if ($start === false) {
            return '';
        }

        $end = strpos($content, '>', $start);

        return substr($content, $start, $end - $start);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
