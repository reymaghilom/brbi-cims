<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CiActivityScheduleDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // Simple parent activities: unchanged behavior
    // ==================================================

    public function test_barangay_and_neighbor_parent_schedule_rendering_is_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-02 19:50:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-03 00:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => false,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Sep 2, 2026', $content);
        $this->assertStringContainsString('7:50 PM', $content);
        $this->assertStringContainsString('Sep 3, 2026', $content);
        $this->assertStringContainsString('No specific time', $content);
    }

    // ==================================================
    // Bank / Coop derived table schedule
    // ==================================================

    public function test_bank_parent_scheduled_at_null_still_displays_child_target_schedule(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'BDO',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 20:45:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);

        $this->assertNull($bank->fresh()->scheduled_at);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $cell = $this->scheduleCellFragment($content, $bank->id);
        $this->assertStringContainsString('BDO – Carmen Branch', $cell);
        $this->assertStringContainsString('Sep 1, 2026', $cell);
        $this->assertStringContainsString('8:45 PM', $cell);
        $this->assertStringNotContainsString('Next', $cell);
    }

    public function test_bank_target_without_branch_shows_institution_only_in_table(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'LandBank',
            'branch_location' => null,
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-02 00:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => false,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('LandBank', $content);
        $this->assertStringContainsString('Sep 2, 2026', $content);
    }

    public function test_bank_date_only_schedule_does_not_show_a_fabricated_eight_am_in_table(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'BPI',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-04 08:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => false,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $cell = $this->scheduleCellFragment($content, $bank->id);

        $this->assertStringContainsString('Sep 4, 2026', $cell);
        $this->assertStringNotContainsString('8:00 AM', $cell);
    }

    public function test_multiple_bank_schedules_show_the_earliest_as_primary_with_additional_count(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 20:45:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BPI', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 20:30:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Metrobank', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-02 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $cell = $this->scheduleCellFragment($content, $bank->id);

        $this->assertStringContainsString('BPI', $cell); // earliest: 8:30 PM
        $this->assertStringContainsString('8:30 PM', $cell);
        $this->assertStringContainsString('+2 more scheduled', $cell);
        $this->assertStringNotContainsString('Next', $cell);
    }

    public function test_completed_bank_target_stops_contributing_to_the_table_schedule(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Completed Bank', 'status' => ActivityStatus::Completed]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('Completed Bank', $content);
    }

    public function test_cleared_bank_schedule_shows_the_empty_dash(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Pending Bank', 'status' => ActivityStatus::Pending, 'scheduled_at' => null]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()->assertDontSee('Pending Bank');
    }

    public function test_past_due_bank_schedule_still_displays_as_operationally_relevant(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Overdue Bank', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDay(), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Overdue Bank', $content);
    }

    // ==================================================
    // Asset derived table schedule
    // ==================================================

    public function test_asset_parent_scheduled_at_null_still_displays_child_target_schedule(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->assetActivity($folder, $ci);
        $this->assetTarget($asset, $ci, [
            'office_location' => 'Land',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 21:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);

        $this->assertNull($asset->fresh()->scheduled_at);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $cell = $this->scheduleCellFragment($content, $asset->id);
        $this->assertStringContainsString('City Assessor — Land', $cell);
        $this->assertStringContainsString('Sep 1, 2026', $cell);
        $this->assertStringContainsString('9:00 PM', $cell);
        $this->assertStringNotContainsString('Next', $cell);
    }

    public function test_asset_date_only_schedule_does_not_show_a_fabricated_eight_am(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->assetActivity($folder, $ci);
        $this->assetTarget($asset, $ci, [
            'office_location' => 'Warehouse',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-05 08:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => false,
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $cell = $this->scheduleCellFragment($content, $asset->id);

        $this->assertStringContainsString('Sep 5, 2026', $cell);
        $this->assertStringNotContainsString('8:00 AM', $cell);
    }

    public function test_multiple_asset_schedules_show_earliest_primary_with_additional_count(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->assetActivity($folder, $ci);
        $this->assetTarget($asset, $ci, ['office_location' => 'Land', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-02 10:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
        $this->assetTarget($asset, $ci, ['office_location' => 'Vehicle', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $cell = $this->scheduleCellFragment($content, $asset->id);

        $this->assertStringContainsString('Vehicle', $cell);
        $this->assertStringContainsString('+1 more scheduled', $cell);
        $this->assertStringNotContainsString('Next', $cell);
    }

    public function test_rescheduling_an_asset_target_changes_the_displayed_primary_schedule(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->assetActivity($folder, $ci);
        $target = $this->assetTarget($asset, $ci, ['office_location' => 'Land', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-02 10:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);

        $this->actingAs($ci)->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $target]), [
            'co_maker_id' => '',
            'assessor_type' => $target->assessor_type,
            'office_location' => 'Land',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-05',
            'scheduled_time' => '13:30',
        ])->assertRedirect();

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Sep 5, 2026', $content);
        $this->assertStringContainsString('1:30 PM', $content);
    }

    // ==================================================
    // No parent scheduled_at write
    // ==================================================

    public function test_no_parent_scheduled_at_or_scheduled_has_time_is_ever_written_for_bank_or_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);
        $asset = $this->assetActivity($folder, $ci);
        $this->assetTarget($asset, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);

        $bankBefore = $bank->fresh()->only(['scheduled_at', 'scheduled_has_time']);
        $assetBefore = $asset->fresh()->only(['scheduled_at', 'scheduled_has_time']);

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk();

        $this->assertNull($bank->fresh()->scheduled_at);
        $this->assertNull($asset->fresh()->scheduled_at);
        $this->assertSame($bankBefore, $bank->fresh()->only(['scheduled_at', 'scheduled_has_time']), 'rendering the table must never write to the Bank parent schedule columns');
        $this->assertSame($assetBefore, $asset->fresh()->only(['scheduled_at', 'scheduled_has_time']), 'rendering the table must never write to the Asset parent schedule columns');
    }

    // ==================================================
    // Auto-update: same mutation response carries the schedule cell
    // ==================================================

    public function test_bank_tracker_response_carries_a_ready_to_swap_schedule_cell_template(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 20:45:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk()->getContent();

        $this->assertStringContainsString('data-bank-coop-schedule-cell', $content);
        $this->assertStringContainsString('BDO', $content);
        $this->assertStringContainsString('8:45 PM', $content);
    }

    public function test_asset_tracker_response_carries_a_ready_to_swap_schedule_cell_template(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->assetActivity($folder, $ci);
        $this->assetTarget($asset, $ci, ['office_location' => 'Land', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 21:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.asset-check.show', [$folder, $asset]))->assertOk()->getContent();

        $this->assertStringContainsString('data-asset-schedule-cell', $content);
        $this->assertStringContainsString('Land', $content);
        $this->assertStringContainsString('9:00 PM', $content);
    }

    public function test_javascript_swaps_the_schedule_cell_from_the_same_response_no_second_fetch(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->bankActivity($folder, $ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString("source.querySelector('[data-bank-coop-schedule-cell]')", $content);
        $this->assertStringContainsString("source.querySelector('[data-asset-schedule-cell]')", $content);
        $this->assertStringNotContainsString('window.location.reload', $content);
    }

    // ==================================================
    // Applicant / Co-Maker isolation
    // ==================================================

    public function test_bank_schedule_summary_stays_scoped_to_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Scope Maker', 'first_name' => 'Scope', 'last_name' => 'Maker']);
        $applicantBank = $this->bankActivity($folder, $ci, 0);
        $this->bankTarget($applicantBank, $ci, ['institution_name' => 'Applicant Bank', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);
        $coMakerBank = $this->bankActivity($folder, $ci, 1, $coMaker->id);
        $this->bankTarget($coMakerBank, $ci, ['institution_name' => 'Co-Maker Bank', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);

        $applicantPage = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $applicantPage->assertOk()->assertSee('Applicant Bank');
        $this->assertStringNotContainsString('Co-Maker Bank', $applicantPage->getContent());

        $coMakerPage = $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]));
        $coMakerPage->assertOk()->assertSee('Co-Maker Bank');
        $this->assertStringNotContainsString('Applicant Bank', $coMakerPage->getContent());
    }

    private function scheduleCellFragment(string $content, int $activityId): string
    {
        $marker = 'data-ci-activity-schedule-cell="'.$activityId.'"';
        $start = strpos($content, $marker);
        $this->assertNotFalse($start, 'schedule cell not found for activity '.$activityId);
        $end = strpos($content, '</td>', $start);
        $this->assertNotFalse($end);

        return substr($content, $start, $end - $start);
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

    private function bankActivity(ClientFolder $folder, User $creator, int $offset = 0, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name.($offset > 0 ? ' '.$offset : ''),
            'creator_id' => $creator->id,
        ]);
    }

    private function assetActivity(ClientFolder $folder, User $creator, int $offset = 0): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::ASSET_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name.($offset > 0 ? ' '.$offset : ''),
            'creator_id' => $creator->id,
        ]);
    }

    private function bankTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityBankTarget
    {
        return $activity->bankTargets()->create(array_merge([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    private function assetTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create(array_merge([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Land',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
