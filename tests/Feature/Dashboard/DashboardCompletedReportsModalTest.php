<?php

namespace Tests\Feature\Dashboard;

use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
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
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The Dashboard "Reports Ready / Completed Reports" detail modal.
 *
 * The rows are the very ReportWorkspaceQuery::completedItems() collection the KPI is counted from,
 * grouped Client Folder -> exact person -> report, and each row opens that report's OWN existing
 * web output: a GET preview link for CI / BI and Business Report, and the shared POST batch-preview
 * endpoint for the two photo checks - the identical endpoints and fields the Global Reports Preview
 * action uses. Opening a row is navigation only; nothing is generated, downloaded or written.
 */
class DashboardCompletedReportsModalTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    private function folder(string $name = 'DELA CRUZ, JUAN'): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'display_name' => $name,
        ]);
    }

    private function cibi(ClientFolder $folder, ?int $coMakerId): void
    {
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
            'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Complete, 'completed_at' => now(),
        ]);
    }

    private function residenceCheck(ClientFolder $folder, ?int $coMakerId): ResidenceCheck
    {
        $check = (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ]);
        $check->save();

        return $check;
    }

    private function business(ClientFolder $folder, string $name): IncomeSource
    {
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'business_name' => $name,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
            'state' => RecordState::Complete, 'revision' => 2, 'completed_at' => now(),
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => $name, 'report_category' => 'retail'])->save();

        return $source;
    }

    private function businessCheck(ClientFolder $folder, IncomeSource $source): BusinessCheck
    {
        $check = (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ]);
        $check->save();

        return $check;
    }

    /** Every report row the modal renders, flattened: person label => list of rows. */
    private function rowsByPerson(): Collection
    {
        $details = app(DashboardData::class)->for($this->ci)['kpiDetails']['reports_ready'];

        return collect($details)->flatMap(fn (array $group) => collect($group['people'])
            ->mapWithKeys(fn (array $person) => [$person['person'] => $person['reports']]));
    }

    private function modal(): string
    {
        $html = $this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent();
        $start = strpos($html, 'id="dashboard-reports-ready-dialog"');
        $this->assertNotFalse($start, 'The Completed Reports modal is missing.');

        return substr($html, $start, strpos($html, '</dialog>', $start) - $start);
    }

    // =====================================================================================
    // One authoritative dataset
    // =====================================================================================

    public function test_the_modal_rows_are_the_same_authoritative_collection_the_kpi_counts(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->cibi($folder, null);
        $this->cibi($folder, $coMaker->id);
        $this->residenceCheck($folder, null);
        $source = $this->business($folder, 'SARI-SARI STORE');
        $this->businessCheck($folder, $source);

        $data = app(DashboardData::class)->for($this->ci);
        $rows = collect($data['kpiDetails']['reports_ready'])
            ->flatMap(fn (array $group) => collect($group['people'])->flatMap(fn (array $person) => $person['reports']));

        $this->assertSame(5, $data['summary']['reports_ready']);
        $this->assertCount(5, $rows, 'One modal row per counted report record.');
        // ...and that count is still the Global Reports Completed total, untouched by this work.
        $this->assertSame(
            app(ReportWorkspaceQuery::class)->summary($this->ci)['completed'],
            $data['summary']['reports_ready']
        );
        // Each folder group's own tally agrees with its rows.
        $this->assertSame(5, collect($data['kpiDetails']['reports_ready'])->sum('count'));
    }

    public function test_repeated_generated_output_files_never_duplicate_a_row(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null);

        foreach (range(1, 6) as $version) {
            GeneratedReport::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => null, 'scope_key' => 'folder:'.$folder->id.':cibi',
                'source_type' => 'cibi', 'report_type' => 'cibi', 'format' => 'pdf', 'version' => $version,
                'status' => GenerationStatus::Completed, 'generated_by' => $this->ci->id,
            ]);
        }

        $data = app(DashboardData::class)->for($this->ci);
        $this->assertSame(6, GeneratedReport::query()->count());
        $this->assertSame(1, $data['summary']['reports_ready']);
        $this->assertCount(1, $this->rowsByPerson()['Applicant']);
    }

    public function test_only_the_four_centralized_report_types_appear(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null);
        $this->residenceCheck($folder, null);
        $source = $this->business($folder, 'SARI-SARI STORE');
        $this->businessCheck($folder, $source);

        $labels = collect($this->rowsByPerson()['Applicant'])->pluck('label')->all();

        $this->assertSame(
            ['Business Check — SARI-SARI STORE', 'Business Report — SARI-SARI STORE', 'CI / BI Report', 'Residence Check'],
            collect($labels)->sort()->values()->all()
        );
        foreach (['Barangay Check', 'Neighbor Check', 'Asset Check', 'Bank / Coop Check'] as $excluded) {
            $this->assertStringNotContainsString($excluded, implode(' ', $labels));
        }
    }

    // =====================================================================================
    // Applicant web output
    // =====================================================================================

    public function test_each_applicant_report_opens_its_own_existing_web_output(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null);
        $residence = $this->residenceCheck($folder, null);
        $source = $this->business($folder, 'SARI-SARI STORE');
        $businessCheck = $this->businessCheck($folder, $source);

        $rows = collect($this->rowsByPerson()['Applicant'])->keyBy('label');

        // CI / BI and Business Report: GET preview links, exact report_type and income_source_id.
        $this->assertSame(
            ['GET', route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi'])],
            [$rows['CI / BI Report']['method'], $rows['CI / BI Report']['url']]
        );
        $this->assertSame(
            ['GET', route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'business_income_source', 'income_source_id' => $source->id])],
            [$rows['Business Report — SARI-SARI STORE']['method'], $rows['Business Report — SARI-SARI STORE']['url']]
        );

        // The two photo checks reach their web output through the existing shared batch-preview
        // endpoint, addressed by this one check's own id.
        $residenceRow = $rows['Residence Check'];
        $this->assertSame('POST', $residenceRow['method']);
        $this->assertSame(route('client-folders.residence-business-checks.batch-print', $folder->id), $residenceRow['url']);
        $this->assertSame(['residence_check_ids[]' => $residence->id], $residenceRow['fields']);

        $businessCheckRow = $rows['Business Check — SARI-SARI STORE'];
        $this->assertSame('POST', $businessCheckRow['method']);
        $this->assertSame(route('client-folders.residence-business-checks.batch-print', $folder->id), $businessCheckRow['url']);
        $this->assertSame(['business_check_ids[]' => $businessCheck->id], $businessCheckRow['fields']);

        // No row ever points at a generate or download endpoint.
        foreach ($rows as $label => $row) {
            foreach (['export-pdf', 'export-excel', 'export-docx', 'download'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $row['url'], $label.' must not download.');
            }
        }
    }

    // =====================================================================================
    // Co-Maker web output and isolation
    // =====================================================================================

    public function test_each_co_maker_is_grouped_separately_and_carries_its_own_exact_id(): void
    {
        $folder = $this->folder();
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);
        $this->cibi($folder, null);
        $this->cibi($folder, $maria->id);
        $this->cibi($folder, $pedro->id);
        $mariaResidence = $this->residenceCheck($folder, $maria->id);

        $byPerson = $this->rowsByPerson();

        $this->assertSame(
            ['Applicant', 'Co-Maker: MARIA DELA CRUZ', 'Co-Maker: PEDRO DELA CRUZ'],
            $byPerson->keys()->all()
        );
        $this->assertCount(1, $byPerson['Applicant']);
        $this->assertCount(2, $byPerson['Co-Maker: MARIA DELA CRUZ']);
        $this->assertCount(1, $byPerson['Co-Maker: PEDRO DELA CRUZ']);

        // Each CI / BI link carries that exact person — and the Applicant's carries none.
        $applicantCibi = collect($byPerson['Applicant'])->firstWhere('label', 'CI / BI Report')['url'];
        $mariaCibi = collect($byPerson['Co-Maker: MARIA DELA CRUZ'])->firstWhere('label', 'CI / BI Report')['url'];
        $pedroCibi = collect($byPerson['Co-Maker: PEDRO DELA CRUZ'])->firstWhere('label', 'CI / BI Report')['url'];

        $this->assertSame(route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi']), $applicantCibi);
        $this->assertSame(route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi', 'person' => 'co-maker', 'co_maker_id' => $maria->id]), $mariaCibi);
        $this->assertSame(route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi', 'person' => 'co-maker', 'co_maker_id' => $pedro->id]), $pedroCibi);

        // No leakage in either direction, and never A -> B.
        $this->assertStringNotContainsString('co_maker_id', $applicantCibi);
        $this->assertStringNotContainsString('co_maker_id='.$pedro->id, $mariaCibi);
        $this->assertStringNotContainsString('co_maker_id='.$maria->id, $pedroCibi);

        // The Co-Maker's Residence Check posts her own check id under her own person.
        $mariaResidenceRow = collect($byPerson['Co-Maker: MARIA DELA CRUZ'])->firstWhere('label', 'Residence Check');
        $this->assertSame(
            ['residence_check_ids[]' => $mariaResidence->id, 'co_maker_id' => $maria->id],
            $mariaResidenceRow['fields']
        );
    }

    // =====================================================================================
    // Business isolation
    // =====================================================================================

    public function test_two_businesses_keep_their_own_exact_income_source_and_check_ids(): void
    {
        $folder = $this->folder();
        $store = $this->business($folder, 'SARI-SARI STORE');
        $farm = $this->business($folder, 'FARMING');
        $storeCheck = $this->businessCheck($folder, $store);
        $farmCheck = $this->businessCheck($folder, $farm);

        $rows = collect($this->rowsByPerson()['Applicant'])->keyBy('label');

        $this->assertNotSame($store->id, $farm->id);
        $this->assertSame(
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'business_income_source', 'income_source_id' => $store->id]),
            $rows['Business Report — SARI-SARI STORE']['url']
        );
        $this->assertSame(
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'business_income_source', 'income_source_id' => $farm->id]),
            $rows['Business Report — FARMING']['url']
        );
        // The two checks are addressed by their own ids, never by business name.
        $this->assertSame(['business_check_ids[]' => $storeCheck->id], $rows['Business Check — SARI-SARI STORE']['fields']);
        $this->assertSame(['business_check_ids[]' => $farmCheck->id], $rows['Business Check — FARMING']['fields']);
        $this->assertNotSame($storeCheck->id, $farmCheck->id);
    }

    // =====================================================================================
    // Rendered modal
    // =====================================================================================

    public function test_the_modal_renders_grouped_actionable_rows_for_every_report_type(): void
    {
        $folder = $this->folder();
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->cibi($folder, null);
        $this->cibi($folder, $maria->id);
        $residence = $this->residenceCheck($folder, null);
        $source = $this->business($folder, 'SARI-SARI STORE');
        $this->businessCheck($folder, $source);

        $modal = $this->modal();
        // Title, summary line and the folder -> person grouping.
        $this->assertStringContainsString('Reports Ready', $modal);
        $this->assertStringContainsString('5 completed reports', $modal);
        $this->assertStringContainsString('DELA CRUZ, JUAN', $modal);
        $this->assertStringContainsString('Applicant', $modal);
        $this->assertStringContainsString('Co-Maker: MARIA DELA CRUZ', $modal);
        $this->assertStringContainsString('Business Report — SARI-SARI STORE', $modal);
        $this->assertSame(5, substr_count($modal, 'data-kpi-detail-row='));
        $this->assertSame(5, substr_count($modal, '>Completed<'));

        // GET rows are links; the two checks are forms posting to the existing preview endpoint.
        $this->assertStringContainsString(
            'href="'.e(route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi'])).'"',
            $modal
        );
        $this->assertSame(2, substr_count($modal, '<form method="POST" action="'.e(route('client-folders.residence-business-checks.batch-print', $folder->id)).'" target="_blank"'));
        $this->assertStringContainsString('name="residence_check_ids[]" value="'.$residence->id.'"', $modal);
        $this->assertStringContainsString('name="_token"', $modal, 'The POST rows are CSRF protected.');

        // Every row is one whole actionable control with pointer, hover and a focus-visible ring.
        $this->assertSame(5, substr_count($modal, 'group flex w-full cursor-pointer items-start gap-3'));
        $this->assertSame(5, substr_count($modal, 'focus-visible:ring-brand-primary/40'));
        // Responsive: the row stacks its title and badge on a narrow screen, as it always has.
        $this->assertSame(5, substr_count($modal, 'flex min-w-0 flex-col gap-1.5 sm:flex-row'));
    }

    // =====================================================================================
    // Every web output opens beside the Dashboard
    // =====================================================================================

    public function test_all_four_report_types_open_their_web_output_in_a_new_tab(): void
    {
        $folder = $this->folder();
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->cibi($folder, null);
        $this->cibi($folder, $maria->id);
        $applicantResidence = $this->residenceCheck($folder, null);
        $mariaResidence = $this->residenceCheck($folder, $maria->id);
        $source = $this->business($folder, 'SARI-SARI STORE');
        $businessCheck = $this->businessCheck($folder, $source);

        $modal = $this->modal();

        // Six completed reports, six actionable controls, every one of them new-tab and guarded.
        $this->assertSame(6, substr_count($modal, 'data-kpi-detail-row='));
        $this->assertSame(6, substr_count($modal, 'target="_blank" rel="noopener noreferrer"'));
        $this->assertStringNotContainsString('rel="noopener"><', $modal, 'rel must carry noreferrer too.');

        // The four GET web outputs stay REAL anchors, so Ctrl/Cmd-click, middle-click and the
        // browser context menu all keep working. Each carries its own exact context.
        $getOutputs = [
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi']),
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'cibi', 'person' => 'co-maker', 'co_maker_id' => $maria->id]),
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'business_income_source', 'income_source_id' => $source->id]),
        ];
        foreach ($getOutputs as $url) {
            $this->assertStringContainsString(
                '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer"',
                $modal,
                'The web output must be an anchor opening in a new tab: '.$url
            );
        }
        $this->assertSame(0, substr_count($modal, 'window.open'), 'No JavaScript-only navigation.');

        // The two photo checks post to the shared preview endpoint in a new tab, each carrying its
        // own exact check id (and the Co-Maker's own person) - never the Applicant's.
        $this->assertSame(3, substr_count($modal, '<form method="POST" action="'.e(route('client-folders.residence-business-checks.batch-print', $folder->id)).'" target="_blank" rel="noopener noreferrer"'));
        $this->assertStringContainsString('name="residence_check_ids[]" value="'.$applicantResidence->id.'"', $modal);
        $this->assertStringContainsString('name="residence_check_ids[]" value="'.$mariaResidence->id.'"', $modal);
        $this->assertStringContainsString('name="co_maker_id" value="'.$maria->id.'"', $modal);
        $this->assertStringContainsString('name="business_check_ids[]" value="'.$businessCheck->id.'"', $modal);

        // A new tab is a change of context, so the accessible name says so.
        $this->assertSame(6, substr_count($modal, '(opens in a new tab)'));
    }

    public function test_the_client_folder_kpi_modals_still_navigate_in_the_same_tab(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null);

        $html = $this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent();
        $activeStart = strpos($html, 'id="dashboard-active-folders-dialog"');
        $this->assertNotFalse($activeStart, 'The Active Client Folders modal is missing.');
        $activeModal = substr($html, $activeStart, strpos($html, '</dialog>', $activeStart) - $activeStart);

        // Opening a Client Folder is ordinary in-page navigation, not a report web output, so the
        // opt-in new-tab treatment must not have leaked into it.
        $this->assertStringContainsString('href="'.e(route('client-folders.show', $folder->id)).'"', $activeModal);
        $this->assertStringNotContainsString('target="_blank"', $activeModal);
        $this->assertStringNotContainsString('(opens in a new tab)', $activeModal);
    }

    public function test_no_web_output_row_points_at_a_generate_or_download_endpoint(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null);
        $this->residenceCheck($folder, null);
        $source = $this->business($folder, 'SARI-SARI STORE');
        $this->businessCheck($folder, $source);

        $modal = $this->modal();

        // The modal offers viewing only: no export/download endpoint is reachable from a row, and
        // rendering it produces no output file.
        foreach (['export-pdf', 'export-excel', 'export-docx', 'batch/export'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $modal, 'A row must never download: '.$forbidden);
        }
        $this->assertSame(0, GeneratedReport::query()->count());
    }

    public function test_rendering_the_modal_writes_nothing_and_leaves_the_count_alone(): void
    {
        $folder = $this->folder();
        $this->cibi($folder, null);
        $this->residenceCheck($folder, null);

        $before = app(DashboardData::class)->for($this->ci)['summary']['reports_ready'];
        $this->modal();
        $after = app(DashboardData::class)->for($this->ci)['summary']['reports_ready'];

        $this->assertSame(2, $before);
        $this->assertSame($before, $after);
        // Opening the Dashboard never produces an output file or a new report record.
        $this->assertSame(0, GeneratedReport::query()->count());
        $this->assertSame(1, CibiReport::query()->count());
        $this->assertSame(1, ResidenceCheck::query()->count());
    }

    public function test_at_zero_the_card_stays_non_interactive_and_renders_no_modal(): void
    {
        $html = $this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent();

        $this->assertSame(0, app(DashboardData::class)->for($this->ci)['summary']['reports_ready']);
        // The existing zero-state pattern: no modal trigger and no dialog at all.
        $this->assertStringNotContainsString('data-modal-open="dashboard-reports-ready-dialog"', $html);
        $this->assertStringNotContainsString('id="dashboard-reports-ready-dialog"', $html);
        $this->assertStringContainsString('Reports Ready', $html);
        $this->assertStringContainsString('No completed reports yet', $html);
    }
}
