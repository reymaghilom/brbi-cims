<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use App\Services\Reports\ReportWorkspaceQuery;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports Ready = completed report RECORDS, exactly the Global Reports "Completed" count
 * (ReportWorkspaceQuery): CI / BI, Business Report (per income source), Residence Check and
 * Business Check, per Applicant / Co-Maker. Generated output files never count.
 */
class DashboardReportsReadyTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    public function test_each_completed_report_record_counts_once_per_person_and_matches_global_reports(): void
    {
        $folder = $this->folder();
        $makerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $makerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $this->assertReady(0);

        $this->cibi($folder, null, RecordState::Complete);
        $this->assertReady(1); // Applicant CI / BI
        $this->cibi($folder, $makerA->id, RecordState::Complete);
        $this->assertReady(2); // + Co-Maker A CI / BI in the same folder
        $draftB = $this->cibi($folder, $makerB->id, RecordState::Draft);
        $this->assertReady(2); // a draft never counts

        $this->residenceCheck($folder, null);
        $this->residenceCheck($folder, $makerA->id);
        $this->assertReady(4); // Residence Check, Applicant and Co-Maker A

        $sourceOne = $this->businessReport($folder, RecordState::Complete, 2);
        $this->assertReady(5); // completed Business Report
        $this->businessReport($folder, RecordState::Complete, 2);
        $this->assertReady(6); // a second business is its own report
        $this->businessReport($folder, RecordState::Draft, 1);
        $this->assertReady(6); // an unfinished Business Report does not count

        $this->businessCheck($folder, $sourceOne);
        $this->assertReady(7); // Business Check for that exact income source

        // Co-Maker B completing their own CI / BI adds exactly one; A's never stood in for it.
        $draftB->update(['state' => RecordState::Complete]);
        $this->assertReady(8);
    }

    public function test_ci_activities_and_generated_output_history_never_count(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null, RecordState::Complete);
        $this->assertReady(1);

        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityDefinition::ASSET_CHECK_CODE, ActivityDefinition::BANK_COOP_CHECK_CODE] as $code) {
            $definition = ActivityDefinition::query()->where('code', $code)->sole();
            CiActivity::create([
                'client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name,
                'status' => ActivityStatus::Completed, 'completed_at' => now(), 'creator_id' => $this->ci->id,
            ]);
        }
        $this->assertReady(1);

        // The same CI / BI downloaded six times: six versioned generated_reports rows, still one report.
        foreach (range(1, 6) as $version) {
            GeneratedReport::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => null, 'scope_key' => 'folder:'.$folder->id.':cibi',
                'source_type' => 'cibi', 'report_type' => 'cibi', 'format' => 'pdf', 'version' => $version,
                'status' => GenerationStatus::Completed, 'generated_by' => $this->ci->id,
            ]);
        }
        $this->assertSame(6, GeneratedReport::query()->count());
        $this->assertReady(1);
    }

    /**
     * The card's own copy. The title stays "Reports Ready"; only its subtitle changed, and the
     * zero-state line is untouched. The count itself is asserted against the authoritative Global
     * Reports Completed total by assertReady() throughout this suite.
     */
    public function test_the_card_subtitle_reads_completed_reports(): void
    {
        $empty = $this->actingAs($this->ci)->get(route('home'))->assertOk();
        $this->assertSame(0, $empty->viewData('summary')['reports_ready']);
        $empty->assertSee('Reports Ready')
            ->assertSee('No completed reports yet')
            ->assertDontSee('Completed Reports')
            ->assertDontSee('Ready for release');

        $this->cibi($this->folder(), null, RecordState::Complete);

        $response = $this->actingAs($this->ci)->get(route('home'))->assertOk();
        $this->assertReady(1);
        $response->assertSee('Reports Ready')
            ->assertSee('Completed Reports')
            ->assertDontSee('Ready for release')
            ->assertDontSee('No completed reports yet');
    }

    private function assertReady(int $expected): void
    {
        $dashboard = app(DashboardData::class)->for($this->ci)['summary']['reports_ready'];
        $this->assertSame($expected, $dashboard);
        $this->assertSame(app(ReportWorkspaceQuery::class)->summary($this->ci)['completed'], $dashboard, 'Reports Ready must equal the Global Reports Completed count.');
    }

    private function folder(): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
    }

    private function cibi(ClientFolder $folder, ?int $coMakerId, RecordState $state): CibiReport
    {
        return CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_in_charge_id' => $this->ci->id, 'state' => $state,
            'completed_at' => $state === RecordState::Complete ? now() : null,
        ]);
    }

    private function residenceCheck(ClientFolder $folder, ?int $coMakerId): void
    {
        (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
    }

    private function businessReport(ClientFolder $folder, RecordState $state, int $revision): IncomeSource
    {
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
            'state' => $state, 'revision' => $revision, 'completed_at' => $state === RecordState::Complete ? now() : null,
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => $source->business_name, 'report_category' => 'retail'])->save();

        return $source;
    }

    private function businessCheck(ClientFolder $folder, IncomeSource $source): void
    {
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
    }
}
