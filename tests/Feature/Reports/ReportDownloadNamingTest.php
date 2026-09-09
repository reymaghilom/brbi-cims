<?php

namespace Tests\Feature\Reports;

use App\Enums\RecordState;
use App\Models\BusinessReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Reports\ReportDownloadName;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The user-facing download filename contract: one "BRBI", a short report name, the exact person,
 * and nothing else — while the stored artifact keeps its own collision-safe identity.
 */
class ReportDownloadNamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_helper_writes_one_brbi_and_a_readable_name(): void
    {
        $this->assertSame('BRBI_CIBI_Juan-Dela-Cruz.pdf', ReportDownloadName::make('CIBI', 'Juan Dela Cruz', 'pdf'));
        $this->assertSame('BRBI_CIBI_Pedro-Santos.xlsx', ReportDownloadName::make('CIBI', 'Pedro Santos', 'xlsx'));
        $this->assertSame('BRBI_Residence_Juan-Dela-Cruz.pdf', ReportDownloadName::make('Residence', 'Juan Dela Cruz', 'pdf'));
        $this->assertSame('BRBI_BusinessCheck_Pedro-Santos.pdf', ReportDownloadName::make('BusinessCheck', 'Pedro Santos', 'pdf'));
        $this->assertSame('BRBI_Business_Juan-Dela-Cruz_Sari-Sari.pdf', ReportDownloadName::make('Business', 'Juan Dela Cruz', 'pdf', 'Sari Sari'));

        // A folder number pasted in as a part can never double the prefix.
        $this->assertSame('BRBI_CIBI_CI-2026-00032.pdf', ReportDownloadName::make('CIBI', 'BRBI-CI-2026-00032', 'pdf'));

        // Sanitisation: no invalid characters, no doubled or trailing separators.
        $messy = ReportDownloadName::make('Business', '  Juan   Dela/Cruz, Jr.  ', 'xlsx', '***');
        $this->assertSame('BRBI_Business_Juan-Dela-Cruz-Jr.xlsx', $messy);
        $this->assertDoesNotMatchRegularExpression('/[\\\\\/:*?"<>|]/', $messy);
        $this->assertDoesNotMatchRegularExpression('/[-_]\./', $messy);
        $this->assertDoesNotMatchRegularExpression('/__|--/', $messy);
    }

    public function test_no_download_name_ever_repeats_brbi_or_carries_a_version(): void
    {
        foreach ([
            ReportDownloadName::make('CIBI', 'BRBI-CI-2026-00032', 'pdf'),
            ReportDownloadName::make('Business', 'DELA CRUZ, JUAN', 'xlsx', 'OTHER BUSINESS/SOURCE OF INCOME'),
            ReportDownloadName::make('Residence', 'Pedro Santos', 'docx'),
        ] as $name) {
            $this->assertSame(1, substr_count($name, 'BRBI'), $name.' must name BRBI once.');
            $this->assertDoesNotMatchRegularExpression('/_v\d+\./', $name, $name.' must not expose a version.');
            $this->assertLessThanOrEqual(130, strlen($name));
        }
    }

    public function test_the_applicant_and_co_maker_cibi_pdf_downloads_are_short_and_person_exact(): void
    {
        [$ci, $folder] = $this->folderWithCompletedCibi('DELA CRUZ, JUAN');
        $coMaker = $folder->coMakers()->create(['full_name' => 'Pedro Santos']);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-08-01', 'submitted_date' => '2026-08-02', 'branch_name' => 'Main',
            'account_officer_name' => 'AO', 'ci_risk_level' => 'low', 'purpose_codes' => ['working_capital'],
            'prepared_by_name' => 'Investigator', 'revision' => 1, 'state' => RecordState::Complete,
        ]);

        $applicant = $this->actingAs($ci)->get('/client-folders/'.$folder->id.'/cibi-report/export-pdf')->assertOk();
        $this->assertSame('BRBI_CIBI_DELA-CRUZ-JUAN.pdf', $this->downloadName($applicant));

        $comaker = $this->actingAs($ci)
            ->get('/client-folders/'.$folder->id.'/cibi-report/export-pdf?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk();
        $this->assertSame('BRBI_CIBI_Pedro-Santos.pdf', $this->downloadName($comaker));

        // Person isolation is untouched: each download is its own person's artifact.
        $rows = GeneratedReport::query()->where('client_folder_id', $folder->id)->orderBy('id')->get();
        $this->assertNull($rows->first()->co_maker_id);
        $this->assertSame($coMaker->id, $rows->last()->co_maker_id);
    }

    public function test_the_cibi_excel_download_uses_the_same_short_name(): void
    {
        [$ci, $folder] = $this->folderWithCompletedCibi('DELA CRUZ, JUAN');

        $response = $this->actingAs($ci)->post(route('client-folders.cibi-report.export-excel', $folder))->assertOk();

        $this->assertSame('BRBI_CIBI_DELA-CRUZ-JUAN.xlsx', $this->downloadName($response));
    }

    /**
     * The stored artifact keeps every part that stops one generation overwriting another; only the
     * name handed to the browser is short.
     */
    public function test_the_stored_artifact_keeps_its_collision_safe_identity(): void
    {
        [$ci, $folder] = $this->folderWithCompletedCibi('DELA CRUZ, JUAN');

        $this->actingAs($ci)->get('/client-folders/'.$folder->id.'/cibi-report/export-pdf')->assertOk();
        $this->actingAs($ci)->get('/client-folders/'.$folder->id.'/cibi-report/export-pdf')->assertOk();

        $rows = GeneratedReport::query()->where('client_folder_id', $folder->id)->orderBy('version')->get();
        $this->assertSame([1, 2], $rows->pluck('version')->all(), 'Internal versioning still increments.');
        $this->assertNotSame(
            $rows->first()->private_file_reference,
            $rows->last()->private_file_reference,
            'Two versions must not share one stored artifact.',
        );

        $stored = basename($rows->last()->private_file_reference);
        $this->assertStringContainsString($folder->folder_number, $stored, 'The stored name keeps the folder number.');
        $this->assertStringContainsString('_v2.pdf', $stored, 'The stored name keeps its version.');

        $documents = app(CiTeamDocumentStorage::class);
        foreach ($rows as $row) {
            $this->assertTrue($documents->disk()->exists($row->private_file_reference));
        }
    }

    /** A Business Report download names the person and the business, still with one BRBI. */
    public function test_the_business_report_downloads_name_the_person_and_the_business(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'income_source_template_id' => $template->id,
            'template_type' => $template->template_type, 'template_version' => 1,
            'source_name' => 'Sari Sari', 'applicant_name_snapshot' => 'DELA CRUZ, JUAN',
        ]);
        BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => 'Sari Sari', 'registered_owner' => 'Owner']);

        $pdf = $this->actingAs($ci)->get(route('client-folders.income-sources.export-pdf', [$folder, $source]))->assertOk();
        $this->assertSame('BRBI_Business_DELA-CRUZ-JUAN_Sari-Sari.pdf', $this->downloadName($pdf));

        $excel = $this->actingAs($ci)->post(route('client-folders.income-sources.export-excel', [$folder, $source]))->assertOk();
        $this->assertSame('BRBI_Business_DELA-CRUZ-JUAN_Sari-Sari.xlsx', $this->downloadName($excel));

        // The download name never becomes the report's identity: the row is still the exact source.
        $this->assertSame($source->id, $source->fresh()->id);
        $this->assertSame('Sari Sari', $source->fresh()->source_name);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folderWithCompletedCibi(string $displayName): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => $displayName]);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-08-01', 'submitted_date' => '2026-08-02', 'branch_name' => 'Main',
            'account_officer_name' => 'AO', 'ci_risk_level' => 'low', 'purpose_codes' => ['working_capital'],
            'prepared_by_name' => 'Investigator', 'revision' => 1, 'state' => RecordState::Complete,
        ]);

        return [$ci, $folder->fresh()];
    }

    private function downloadName(TestResponse $response): string
    {
        preg_match('/filename="?([^";]+)"?/', (string) $response->headers->get('content-disposition'), $matches);

        return $matches[1] ?? '';
    }
}
