<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard "In Progress" = Client Folders whose MANDATORY investigation work has STARTED but is
 * not finished - strictly between 0% and 100% - each folder counted once. A folder with nothing
 * done yet has not started and is not In Progress; a folder at 100% is finished. Applicant: CIBI,
 * Business Report, Residence Check, Business Check, Barangay, Neighbor and Bank / Coop. Each
 * existing Co-Maker: CIBI, Residence Check, Barangay and Neighbor. Asset Check is optional.
 */
class DashboardInProgressKpiTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    public function test_applicant_with_all_mandatory_work_complete_is_not_in_progress_even_with_asset_pending_or_absent(): void
    {
        $withoutAsset = $this->completeFolder();
        $this->assertSame(0, $this->inProgress());

        $withAsset = $this->completeFolder();
        $this->activity($withAsset, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending);
        $this->assertSame(0, $this->inProgress());
        $this->assertNotSame($withoutAsset->id, $withAsset->id);
    }

    public function test_each_missing_or_unfinished_applicant_requirement_keeps_that_folder_in_progress(): void
    {
        $breakers = [
            'CIBI missing' => fn (ClientFolder $folder) => CibiReport::query()->where('client_folder_id', $folder->id)->delete(),
            'CIBI still draft' => fn (ClientFolder $folder) => CibiReport::query()->where('client_folder_id', $folder->id)->update(['state' => RecordState::Draft->value]),
            'Business Report missing' => fn (ClientFolder $folder) => BusinessReport::query()->whereIn('income_source_id', $folder->incomeSources()->pluck('id'))->delete(),
            'Business Report never saved' => fn (ClientFolder $folder) => $folder->incomeSources()->update(['revision' => 1]),
            'Business income source not complete' => fn (ClientFolder $folder) => $folder->incomeSources()->update(['state' => RecordState::Draft->value]),
            'Residence Check missing' => fn (ClientFolder $folder) => ResidenceCheck::query()->where('client_folder_id', $folder->id)->delete(),
            'Business Check missing' => fn (ClientFolder $folder) => BusinessCheck::query()->where('client_folder_id', $folder->id)->delete(),
            'Barangay Pending' => fn (ClientFolder $folder) => $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending),
            'Neighbor Scheduled' => fn (ClientFolder $folder) => $this->setStatus($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled),
            'Bank / Coop Follow-up' => fn (ClientFolder $folder) => $this->setStatus($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::FollowUp),
            'Bank / Coop missing' => fn (ClientFolder $folder) => $this->activityQuery($folder, ActivityDefinition::BANK_COOP_CHECK_CODE)->forceDelete(),
        ];

        $expected = 0;
        foreach ($breakers as $label => $break) {
            $break($this->completeFolder());
            $expected++;
            $this->assertSame($expected, $this->inProgress(), "{$label} must count the folder as In Progress.");
        }
    }

    public function test_one_folder_with_many_unfinished_requirements_is_counted_once(): void
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        // One requirement genuinely met, so the folder has started; the rest are outstanding.
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        foreach ([ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityDefinition::BANK_COOP_CHECK_CODE] as $code) {
            $this->activity($folder, $code, ActivityStatus::Pending);
        }
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Unfinished Co-Maker']);

        $progress = $this->progressOf($folder);
        $this->assertSame(1, $progress['completed']);
        $this->assertGreaterThan(1, count($progress['missing']), 'Many requirements are still outstanding.');
        $this->assertSame(1, $this->inProgress(), 'One folder, however many unfinished requirements.');
    }

    public function test_co_maker_needs_only_cibi_residence_barangay_and_neighbor(): void
    {
        $folder = $this->completeFolder();
        $coMaker = $this->completeCoMaker($folder);

        // No Co-Maker Business Report, Business Check, Bank / Coop or Asset Check exists at all.
        $this->assertFalse(BusinessCheck::query()->where('co_maker_id', $coMaker->id)->exists());
        $this->assertFalse(IncomeSource::query()->where('co_maker_id', $coMaker->id)->exists());
        $this->assertFalse($this->activityQuery($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, $coMaker->id)->exists());
        $this->assertSame(0, $this->inProgress());
    }

    public function test_each_missing_or_unfinished_co_maker_requirement_keeps_the_folder_in_progress(): void
    {
        $breakers = [
            'Co-Maker CIBI missing' => fn (ClientFolder $folder, CoMaker $coMaker) => CibiReport::query()->where('co_maker_id', $coMaker->id)->delete(),
            'Co-Maker Residence Check missing' => fn (ClientFolder $folder, CoMaker $coMaker) => ResidenceCheck::query()->where('co_maker_id', $coMaker->id)->delete(),
            'Co-Maker Barangay Pending' => fn (ClientFolder $folder, CoMaker $coMaker) => $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending, $coMaker->id),
            'Co-Maker Neighbor Pending' => fn (ClientFolder $folder, CoMaker $coMaker) => $this->setStatus($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending, $coMaker->id),
        ];

        $expected = 0;
        foreach ($breakers as $label => $break) {
            $folder = $this->completeFolder();
            $break($folder, $this->completeCoMaker($folder));
            $expected++;
            $this->assertSame($expected, $this->inProgress(), "{$label} must count the folder as In Progress.");

            // The Applicant's own complete records never stand in for the Co-Maker's.
            $this->assertTrue(CibiReport::query()->where('client_folder_id', $folder->id)->whereNull('co_maker_id')->where('state', RecordState::Complete->value)->exists());
            $this->assertTrue(ResidenceCheck::query()->where('client_folder_id', $folder->id)->whereNull('co_maker_id')->exists());
        }
    }

    public function test_co_maker_completion_never_satisfies_the_applicant(): void
    {
        $folder = $this->completeFolder();
        $coMaker = $this->completeCoMaker($folder);
        CibiReport::query()->where('client_folder_id', $folder->id)->whereNull('co_maker_id')->delete();
        $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);

        $this->assertTrue(CibiReport::query()->where('co_maker_id', $coMaker->id)->where('state', RecordState::Complete->value)->exists());
        $this->assertSame(1, $this->inProgress());
    }

    public function test_multiple_co_makers_are_each_required_and_never_satisfy_one_another(): void
    {
        $folder = $this->completeFolder();
        $this->completeCoMaker($folder, 'Co-Maker A');
        $coMakerB = $this->completeCoMaker($folder, 'Co-Maker B');
        $this->assertSame(0, $this->inProgress());

        // Co-Maker B's Barangay Check reopens: the whole folder is In Progress, counted once.
        $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending, $coMakerB->id);
        $this->assertSame(1, $this->inProgress());

        // Co-Maker A's (and the Applicant's) Residence Check does not satisfy Co-Maker B's.
        $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed, $coMakerB->id);
        ResidenceCheck::query()->where('co_maker_id', $coMakerB->id)->delete();
        $this->assertSame(2, ResidenceCheck::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, $this->inProgress());
    }

    public function test_the_card_shows_active_investigations(): void
    {
        // A brand-new folder has not started: 0 of 7, so the KPI stays at zero...
        ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $this->assertSame(0, $this->inProgress());

        // ...and one part-finished folder is what the card actually counts.
        $partial = $this->completeFolder();
        $this->setStatus($partial, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);

        $response = $this->actingAs($this->ci)->get(route('home'))->assertOk();

        $response->assertSee('In Progress')->assertSee('Active Investigations')->assertDontSee('Ongoing Investigations');
        $this->assertSame(1, $response->viewData('summary')['in_progress']);
    }

    /**
     * The rule is the percentage itself, so the boundaries are pinned explicitly. Auto-generated
     * pristine Barangay / Neighbor rows satisfy no requirement, so they never make a brand-new
     * folder look started.
     */
    public function test_zero_percent_is_not_started_and_one_completed_requirement_starts_it(): void
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending);

        $this->assertSame(0, $this->progressOf($folder)['completed']);
        $this->assertSame(0, $this->inProgress(), '0 of 7 is not started.');

        // One genuinely completed mandatory requirement: 1 of 7 = 14%.
        $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->assertSame(['completed' => 1, 'total' => 7, 'percent' => 14], $this->progressWithoutMissing($folder));
        $this->assertSame(1, $this->inProgress());
    }

    public function test_one_hundred_percent_is_not_in_progress(): void
    {
        $folder = $this->completeFolder();

        $this->assertSame(['completed' => 7, 'total' => 7, 'percent' => 100], $this->progressWithoutMissing($folder));
        $this->assertSame(0, $this->inProgress());
    }

    public function test_a_co_maker_moves_the_boundaries_to_eleven_requirements(): void
    {
        // 0 of 11: not started.
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Fresh Co-Maker']);
        $this->assertSame(['completed' => 0, 'total' => 11, 'percent' => 0], $this->progressWithoutMissing($folder));
        $this->assertSame(0, $this->inProgress());

        // 1 of 11 = 9%: started.
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->assertSame(['completed' => 1, 'total' => 11, 'percent' => 9], $this->progressWithoutMissing($folder));
        $this->assertSame(1, $this->inProgress());

        // 10 of 11 = 91%: still started, still unfinished.
        $complete = $this->completeFolder();
        $coMaker = $this->completeCoMaker($complete);
        $this->setStatus($complete, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending, $coMaker->id);
        $this->assertSame(['completed' => 10, 'total' => 11, 'percent' => 91], $this->progressWithoutMissing($complete));
        $this->assertSame(2, $this->inProgress());

        // 11 of 11 = 100%: finished, so it leaves the KPI again.
        $this->setStatus($complete, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed, $coMaker->id);
        $this->assertSame(100, $this->progressOf($complete)['percent']);
        $this->assertSame(1, $this->inProgress());
    }

    /** An optional Asset Check never moves the percentage, so it cannot start a 0% folder. */
    public function test_an_optional_asset_check_alone_never_starts_a_folder(): void
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Completed);

        $this->assertSame(['completed' => 0, 'total' => 7, 'percent' => 0], $this->progressWithoutMissing($folder));
        $this->assertSame(0, $this->inProgress());
    }

    private function inProgress(): int
    {
        return app(DashboardData::class)->for($this->ci)['summary']['in_progress'];
    }

    /** One folder's authoritative mandatory progress, as the Dashboard itself computed it. */
    private function progressOf(ClientFolder $folder): array
    {
        $row = collect(app(DashboardData::class)->for($this->ci)['kpiDetails']['active'])
            ->firstWhere('url', route('client-folders.show', $folder->id));

        $this->assertNotNull($row, 'The folder is missing from the Active Client Folders list.');

        return $row['progress'];
    }

    private function progressWithoutMissing(ClientFolder $folder): array
    {
        return collect($this->progressOf($folder))->except('missing')->all();
    }

    private function completeFolder(): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $this->completePerson($folder, null);

        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
            'state' => RecordState::Complete,
            'revision' => 2,
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => 'Sari-sari Store', 'report_category' => 'retail'])->save();
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
        $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Completed);

        return $folder;
    }

    private function completeCoMaker(ClientFolder $folder, string $name = 'Complete Co-Maker'): CoMaker
    {
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name]);
        $this->completePerson($folder, $coMaker->id);

        return $coMaker;
    }

    private function completePerson(ClientFolder $folder, ?int $coMakerId): void
    {
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Complete,
        ]);
        (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed, $coMakerId);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed, $coMakerId);
    }

    private function activity(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $definition->id,
            'name' => $definition->name, 'status' => $status, 'scheduled_at' => $status === ActivityStatus::Scheduled ? now()->addDay() : null,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null, 'creator_id' => $this->ci->id,
        ]);
    }

    private function activityQuery(ClientFolder $folder, string $code, ?int $coMakerId = null)
    {
        return CiActivity::query()->where('client_folder_id', $folder->id)->where('co_maker_id', $coMakerId)
            ->whereHas('definition', fn ($query) => $query->where('code', $code));
    }

    private function setStatus(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): void
    {
        $this->activityQuery($folder, $code, $coMakerId)->sole()->update([
            'status' => $status,
            'scheduled_at' => $status === ActivityStatus::Scheduled ? now()->addDay() : null,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
        ]);
    }
}
