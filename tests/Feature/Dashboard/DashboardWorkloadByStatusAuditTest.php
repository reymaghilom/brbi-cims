<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Progress\MandatoryInvestigationRequirements;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dashboard "Workload by Status" is a distribution of Client Folder PROGRESS STATUS over the active
 * folder set: Not Started (0%), In Progress (>0% and <100%) and Completed (100%), all read from the
 * one authoritative mandatoryProgress() result.
 *
 * Needs Attention is deliberately absent. It is an overdue FLAG that cuts across all three
 * statuses, and while it WAS a mutually exclusive slice it silently removed overdue folders from
 * the In Progress slice - which is exactly why that slice disagreed with the In Progress KPI. It
 * keeps its own KPI card, count and detail modal; these tests pin that it still does.
 */
class DashboardWorkloadByStatusAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    private function folder(string $name, ClientFolderStatus $status = ClientFolderStatus::OnProgress): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'display_name' => $name,
            'status' => $status,
            'completed_at' => $status === ClientFolderStatus::Completed ? now() : null,
        ]);
    }

    private function activity(ClientFolder $folder, string $code, ActivityStatus $status, $scheduledAt = null, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => $status, 'scheduled_at' => $scheduledAt, 'scheduled_has_time' => false,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            'creator_id' => $this->ci->id,
        ]);
    }

    /** Every one of the Applicant's seven mandatory requirements met: 100%. */
    private function completeFolder(string $name): ClientFolder
    {
        $folder = $this->folder($name);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Complete,
        ]);
        (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
            'state' => RecordState::Complete, 'revision' => 2,
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => 'Sari-sari Store', 'report_category' => 'retail'])->save();
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityDefinition::BANK_COOP_CHECK_CODE] as $code) {
            $this->activity($folder, $code, ActivityStatus::Completed);
        }

        return $folder;
    }

    private function home(): TestResponse
    {
        return $this->actingAs($this->ci)->get(route('home'))->assertOk();
    }

    /** @return Collection<string, int> bucket key => count */
    private function buckets(?TestResponse $response = null): Collection
    {
        return collect(($response ?? $this->home())->viewData('workload')['segments'])->pluck('count', 'key');
    }

    /** The exact folder names the In Progress KPI lists behind its detail modal. */
    private function kpiInProgressNames(TestResponse $response): array
    {
        return collect($response->viewData('kpiDetails')['in_progress'])->pluck('client')->sort()->values()->all();
    }

    private function progressOf(TestResponse $response, ClientFolder $folder): array
    {
        $row = collect($response->viewData('kpiDetails')['active'])
            ->firstWhere('url', route('client-folders.show', $folder->id));
        $this->assertNotNull($row, 'The folder is missing from Active Client Folders.');

        return $row['progress'];
    }

    // =====================================================================================
    // Model: three progress statuses, no overdue slice
    // =====================================================================================

    public function test_the_chart_reports_three_progress_statuses_and_no_overdue_slice(): void
    {
        $this->folder('ALPHA, CLIENT');

        $response = $this->home();
        $segments = collect($response->viewData('workload')['segments']);

        $this->assertSame(['not_started', 'in_progress', 'completed'], $segments->pluck('key')->all());
        $this->assertSame(['Not Started', 'In Progress', 'Completed'], $segments->pluck('label')->all());
        $this->assertNull($segments->firstWhere('key', 'needs_attention'), 'Overdue work is a flag, not a status.');
        $this->assertNull($segments->firstWhere('key', 'pending'));

        // The card keeps its title, and Needs Attention keeps its own KPI card.
        $response->assertSee('Workload by Status')->assertSee('Needs Attention');
    }

    public function test_the_three_statuses_cover_the_active_folder_population_without_duplicates(): void
    {
        $this->folder('ALPHA, CLIENT');                                    // 0%
        $started = $this->folder('BRAVO, CLIENT');
        $this->activity($started, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->completeFolder('CHARLIE, CLIENT');                          // 100%

        $response = $this->home();
        $workload = $response->viewData('workload');

        $this->assertSame(['not_started' => 1, 'in_progress' => 1, 'completed' => 1], $this->buckets($response)->all());
        $this->assertSame(3, $workload['total']);
        $this->assertSame($workload['total'], collect($workload['segments'])->sum('count'));
        // The workload population is exactly the Active Client Folders KPI — no exclusions.
        $this->assertSame($response->viewData('summary')['assigned'], $workload['total']);
    }

    // =====================================================================================
    // The alignment this fix exists for
    // =====================================================================================

    public function test_the_in_progress_slice_and_kpi_are_the_same_exact_folders(): void
    {
        // Not started.
        $this->folder('ALPHA, CLIENT');
        // Part-finished, and overdue as well: it must appear in BOTH metrics.
        $overdue = $this->folder('BRAVO, CLIENT');
        $this->activity($overdue, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->activity($overdue, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled, now()->subDays(2));
        // Part-finished and calm.
        $calm = $this->folder('CHARLIE, CLIENT');
        $this->activity($calm, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        // Finished.
        $this->completeFolder('DELTA, CLIENT');

        $response = $this->home();

        $this->assertSame(['BRAVO, CLIENT', 'CHARLIE, CLIENT'], $this->kpiInProgressNames($response));
        $this->assertSame(2, $response->viewData('summary')['in_progress']);
        $this->assertSame(2, $this->buckets($response)['in_progress'], 'Same count...');
        $this->assertSame(
            count($this->kpiInProgressNames($response)),
            $this->buckets($response)['in_progress'],
            '...and the slice is the KPI folder set itself, overdue folders included.'
        );
        $this->assertSame(['not_started' => 1, 'in_progress' => 2, 'completed' => 1], $this->buckets($response)->all());

        // The overdue one is simultaneously counted by Needs Attention — different metrics.
        $this->assertSame(1, $response->viewData('summary')['needs_attention']);
        $this->assertSame(['BRAVO, CLIENT'], collect($response->viewData('needsAttention'))->pluck('client')->all());
    }

    /** The three boundaries, asserted against the percentage the Dashboard itself computed. */
    public function test_zero_and_one_hundred_percent_stay_out_of_in_progress(): void
    {
        $notStarted = $this->folder('ALPHA, CLIENT');
        $this->activity($notStarted, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);
        $complete = $this->completeFolder('BRAVO, CLIENT');

        $response = $this->home();

        $this->assertSame(0, $this->progressOf($response, $notStarted)['percent']);
        $this->assertSame(100, $this->progressOf($response, $complete)['percent']);
        $this->assertSame(0, $response->viewData('summary')['in_progress']);
        $this->assertSame(['not_started' => 1, 'in_progress' => 0, 'completed' => 1], $this->buckets($response)->all());
    }

    public function test_a_single_completed_requirement_moves_a_folder_from_not_started_to_in_progress(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);
        $this->assertSame(['not_started' => 1, 'in_progress' => 0, 'completed' => 0], $this->buckets()->all());

        $barangay->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $response = $this->home();
        $this->assertSame(14, $this->progressOf($response, $folder)['percent'], '1 of 7.');
        $this->assertSame(1, $response->viewData('summary')['in_progress']);
        $this->assertSame(['not_started' => 0, 'in_progress' => 1, 'completed' => 0], $this->buckets($response)->all());
    }

    // =====================================================================================
    // Overdue work never changes a progress status
    // =====================================================================================

    /** @return array<string, list<string>> */
    public static function overdueKinds(): array
    {
        return [
            'plain activity' => ['activity'],
            'Bank / Coop target' => ['bank'],
            'Asset target' => ['asset'],
        ];
    }

    #[DataProvider('overdueKinds')]
    public function test_a_part_finished_folder_stays_in_progress_whatever_kind_of_work_is_overdue(string $kind): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->addOverdue($folder, $kind);

        $response = $this->home();

        $this->assertSame(14, $this->progressOf($response, $folder)['percent']);
        $this->assertSame(1, $response->viewData('summary')['in_progress'], 'Still In Progress.');
        $this->assertSame(['not_started' => 0, 'in_progress' => 1, 'completed' => 0], $this->buckets($response)->all());
        $this->assertSame(1, $response->viewData('summary')['needs_attention'], 'And flagged at the same time.');
    }

    /** 100% mandatory progress with an overdue OPTIONAL Asset office: Completed AND flagged. */
    public function test_a_finished_folder_with_an_overdue_optional_asset_stays_completed(): void
    {
        $folder = $this->completeFolder('ALPHA, CLIENT');
        $this->addOverdue($folder, 'asset');

        $response = $this->home();

        // Asset Check is in neither mandatory list, so it never moved the percentage.
        $this->assertArrayNotHasKey('asset_check', MandatoryInvestigationRequirements::APPLICANT);
        $this->assertArrayNotHasKey('asset_check', MandatoryInvestigationRequirements::CO_MAKER);
        $this->assertSame(100, $this->progressOf($response, $folder)['percent']);

        $this->assertSame(['not_started' => 0, 'in_progress' => 0, 'completed' => 1], $this->buckets($response)->all());
        $this->assertSame(0, $response->viewData('summary')['in_progress'], 'Never back to In Progress.');
        $this->assertSame(1, $response->viewData('summary')['needs_attention'], 'Still flagged.');
    }

    private function addOverdue(ClientFolder $folder, string $kind): void
    {
        if ($kind === 'activity') {
            $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled, now()->subDays(2));

            return;
        }

        if ($kind === 'bank') {
            $parent = $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Scheduled);
            CiActivityBankTarget::create([
                'ci_activity_id' => $parent->id, 'institution_name' => 'Rural Bank',
                'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDays(2), 'scheduled_has_time' => false,
            ]);

            return;
        }

        $parent = $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Scheduled);
        CiActivityAssetTarget::create([
            'ci_activity_id' => $parent->id, 'assessor_type' => 'provincial_assessor',
            'office_location' => 'Provincial Capitol',
            'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDays(2), 'scheduled_has_time' => false,
        ]);
    }

    // =====================================================================================
    // Co-Maker, stored status, percentages, empty state
    // =====================================================================================

    public function test_a_co_maker_widens_the_denominator_without_becoming_a_second_folder(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co Maker']);
        // One Applicant requirement met: 1 of 11, not 1 of 7.
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);

        $response = $this->home();
        $progress = $this->progressOf($response, $folder);

        $this->assertSame(11, $progress['total']);
        $this->assertSame(1, $progress['completed']);
        $this->assertSame(9, $progress['percent']);
        $this->assertSame(1, $response->viewData('workload')['total'], 'One Client Folder, not two.');
        $this->assertSame(['not_started' => 0, 'in_progress' => 1, 'completed' => 0], $this->buckets($response)->all());
    }

    /** The chart reports the calculation, not a stored flag that was written some other way. */
    public function test_a_stored_completed_status_does_not_override_the_calculated_progress(): void
    {
        $folder = $this->folder('ALPHA, CLIENT', ClientFolderStatus::Completed);

        $response = $this->home();

        $this->assertSame(0, $this->progressOf($response, $folder)['percent']);
        $this->assertSame(['not_started' => 1, 'in_progress' => 0, 'completed' => 0], $this->buckets($response)->all());
    }

    public function test_percentages_are_each_slice_over_the_chart_total(): void
    {
        $this->folder('ALPHA, CLIENT');
        $started = $this->folder('BRAVO, CLIENT');
        $this->activity($started, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->completeFolder('CHARLIE, CLIENT');
        $this->completeFolder('DELTA, CLIENT');

        $workload = $this->home()->viewData('workload');

        $this->assertSame(4, $workload['total']);
        foreach ($workload['segments'] as $segment) {
            $this->assertSame(
                (int) round($segment['count'] / $workload['total'] * 100),
                $segment['percent'],
                $segment['key'].' percent must be its own count over the chart total.'
            );
        }
        $this->assertSame(['not_started' => 25, 'in_progress' => 25, 'completed' => 50], collect($workload['segments'])->pluck('percent', 'key')->all());
        $this->assertSame(100, collect($workload['segments'])->sum('percent'));
    }

    public function test_an_empty_workspace_never_divides_by_zero_and_shows_the_empty_state(): void
    {
        $response = $this->home();
        $workload = $response->viewData('workload');

        $this->assertSame(0, $workload['total']);
        foreach ($workload['segments'] as $segment) {
            $this->assertSame(0, $segment['count']);
            $this->assertSame(0, $segment['percent']);
        }
        $response->assertSee('The workload chart appears once active client folders exist')
            ->assertDontSee('aria-label="Workload split across', false);
    }

    public function test_the_chart_renders_its_labels_counts_and_percentages(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);

        $response = $this->home();

        $response->assertSee('Workload by Status')
            ->assertSee('aria-label="Workload split across 1 client."', false);
        foreach (['Not Started', 'In Progress', 'Completed'] as $label) {
            $response->assertSee($label);
        }
        // Each legend row carries a screen-reader sentence with the same count and percentage.
        $response->assertSee('In Progress: 1 client, 100 percent.', false)
            ->assertSee('Not Started: 0 clients, 0 percent.', false);
    }
}
