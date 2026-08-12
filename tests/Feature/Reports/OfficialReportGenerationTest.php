<?php

namespace Tests\Feature\Reports;

use App\Enums\GenerationStatus;
use App\Enums\OfficialReportType;
use App\Enums\RecordState;
use App\Enums\ReportFormat;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceBusinessReport;
use App\Models\User;
use App\Services\Reports\Contracts\PdfGenerator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;
use ZipArchive;

class OfficialReportGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_pdf_and_editable_docx_are_real_versioned_private_artifacts_with_8_5_by_13_geometry(): void
    {
        [$ci, $folder] = $this->cibiContext();

        foreach ([ReportFormat::Pdf, ReportFormat::Docx] as $format) {
            $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => OfficialReportType::Cibi->value, 'format' => $format->value])->assertRedirect();
        }

        $reports = GeneratedReport::orderBy('id')->get();
        $this->assertCount(2, $reports);
        $pdf = $reports->firstWhere('format', ReportFormat::Pdf);
        $docx = $reports->firstWhere('format', ReportFormat::Docx);
        $this->assertSame(GenerationStatus::Completed, $pdf->status);
        $pdfBytes = Storage::disk('local')->get($pdf->private_file_reference);
        $this->assertSame('%PDF', substr($pdfBytes, 0, 4));
        $this->assertMatchesRegularExpression('/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+612(?:\.0+)?\s+936(?:\.0+)?\s*\]/', $pdfBytes);
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes));
        $this->assertSame('PK', substr(Storage::disk('local')->get($docx->private_file_reference), 0, 2));

        $temporary = tempnam(sys_get_temp_dir(), 'docx-test-');
        file_put_contents($temporary, Storage::disk('local')->get($docx->private_file_reference));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);
        $this->assertStringContainsString('w:w="12240"', $documentXml);
        $this->assertStringContainsString('w:h="18720"', $documentXml);
        $this->assertStringContainsString('SAVED CLIENT', $documentXml);
        $this->assertStringNotContainsString('VALIDATED SNAPSHOT NAME', $documentXml);
        $this->assertSame('BRBI_'.$folder->folder_number.'_SAVED-CLIENT_CI-BI-Report_v1.pdf', basename($pdf->private_file_reference));
    }

    public function test_all_four_report_families_preview_and_generate_from_saved_data(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $dedicated = $this->businessSource($folder);
        $fallback = $this->fallbackSource($folder);
        ResidenceBusinessReport::factory()->create(['client_folder_id' => $folder->id, 'ci_user_id' => $ci->id]);

        $cases = [
            [OfficialReportType::Cibi, null, 'CREDIT INVESTIGATION'],
            [OfficialReportType::BusinessIncomeSource, $dedicated, 'OFFICIAL BUSINESS'],
            [OfficialReportType::GeneralIncomeSource, $fallback, 'SOURCES OF INCOME'],
            [OfficialReportType::ResidenceBusinessPhoto, null, 'RESIDENCE & BUSINESS'],
        ];
        foreach ($cases as [$type, $source, $text]) {
            $parameters = ['report_type' => $type->value] + ($source ? ['income_source_id' => $source->id] : []);
            $preview = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder] + $parameters))->assertOk()->assertSee($text);
            if ($type === OfficialReportType::Cibi) {
                $preview->assertDontSee('Official CI / BI Report')->assertDontSee('Read-only Report Preview');
            } else {
                $preview->assertSee('8.5 × 13 inches');
            }
            $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), $parameters + ['format' => 'pdf'])->assertRedirect();
        }
        $this->assertSame(4, GeneratedReport::where('status', GenerationStatus::Completed)->count());
        $this->assertSame(2, GeneratedReport::whereNotNull('income_source_id')->count());
        $this->assertTrue($folder->completionResults()->whereHas('rule', fn ($query) => $query->where('code', 'required_reports'))->firstOrFail()->is_satisfied);
        $this->assertEquals(16.67, $folder->refresh()->progress_percent);
    }

    public function test_cibi_preview_and_direct_pdf_export_require_completion_and_use_the_official_saved_data_template(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $folder->cibiReport->update(['state' => RecordState::Draft]);

        $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))->assertStatus(422);
        $this->actingAs($ci)->post(route('client-folders.cibi-report.export-pdf', $folder))->assertStatus(422);
        $this->actingAs($ci)->post(route('client-folders.cibi-report.export-excel', $folder))->assertStatus(422);

        $folder->cibiReport->update(['state' => RecordState::Complete]);
        $folder->cibiReport->incomeSourceSummaries()->firstOrFail()->update(['stability_result' => 'existing_strong_capacity']);
        $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))
            ->assertOk()
            ->assertSee('Official BRBI Credit Investigation Report')
            ->assertSee('Validated report address')
            ->assertDontSee('Official CI / BI Report')
            ->assertSee('Binhi Rural Bank Inc.')
            ->assertSee('href="'.route('client-folders.show', $folder).'"', false)
            ->assertSee('( ✓ ) LOW')
            ->assertSee('( ✓ ) MARRIED')
            ->assertSee('( ✓ ) MEDIUM')
            ->assertSee('( ✓ ) GOOD')
            ->assertSee('( ✓ ) NO LEGAL CASES')
            ->assertSee('( ✓ ) MODEST')
            ->assertSee('HEAVILY INDEBTED/FREQUENT COLLECTOR VISITS')
            ->assertSee('cibi-section-bar cibi-section-left', false)
            ->assertSee('cibi-section-heading-line', false)
            ->assertSee('cibi-section-title-detail', false)
            ->assertSee('III. BANK / FINANCIAL INSTITUTION')
            ->assertSee('ADB LEVEL')
            ->assertDontSee('ADB LEVEL / FIGURES')
            ->assertSee('cibi-adb-print', false)
            ->assertDontSee('cibi-bank-remarks', false)
            ->assertDontSee('cibi-loan-total', false)
            ->assertDontSee('cibi-credit-guide', false)
            ->assertSee('.cibi-official-sheet .cibi-section-bar th', false)
            ->assertSee('cibi-purpose-remarks-box', false)
            ->assertSee('cibi-other-remarks-row', false)
            ->assertSee('<span>OTHER REMARKS:</span>', false)
            ->assertDontSee('<strong>OTHER REMARKS:</strong>', false)
            ->assertSee('EXAMPLE: SELLER OR SUPPLIER OF CHATTEL OR REAL ESTATE TO BE ACQUIRED / LOAN FOR BUYOUT IS PAST DUE / AMOUNT APPLIED IS SO MUCH HIGHER THAN AMOUNT REQUIRED FOR PURPOSE')
            ->assertSee('cibi-purpose-guide', false)
            ->assertSee('.cibi-purpose-guide{margin:.02in 0 .025in;', false)
            ->assertSee('cibi-income-stability', false)
            ->assertSee('<div class="cibi-section-iv-remarks">', false)
            ->assertSee('<div class="cibi-section-iv-remarks-box">', false)
            ->assertSee('EXAMPLE: FREQUENT COLLECTOR VISITS / OFF HAND VALIDATION THAT CLIENT HAS SEVERAL PENDING LOAN APPLICATIONS')
            ->assertSee('cibi-section-iv-guide', false)
            ->assertSee('.cibi-section-iv-guide{margin:.02in 0 .025in;', false)
            ->assertSee('cibi-signatory-name', false)
            ->assertSee('cibi-signature-space', false)
            ->assertSee('class="cibi-prepared-by"', false)
            ->assertSee('</span><small>CREDIT INVESTIGATOR</small>', false)
            ->assertSee('<span class="cibi-signatory-name"></span>', false)
            ->assertDontSee('<span class="cibi-signatory-name">N/A</span>', false)
            ->assertSee('( ✓ ) EXISTING WITH STRONG CAPACITY')
            ->assertSee('(   ) EXISTING BUT WEAK CAPACITY')
            ->assertSee('(   ) WAS NOT/CANNOT BE VALIDATED')
            ->assertDontSee('( X )')
            ->assertDontSee(' / (')
            ->assertDontSee('Read-only Report Preview')
            ->assertDontSee('8.5 × 13 inches · saved data only');

        $this->actingAs($ci)->post(route('client-folders.cibi-report.export-pdf', $folder))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $report = GeneratedReport::where('report_type', 'cibi')->where('format', ReportFormat::Pdf)->sole();
        $this->assertSame(GenerationStatus::Completed, $report->status);
        $this->assertSame('%PDF', substr(Storage::disk('local')->get($report->private_file_reference), 0, 4));
    }

    #[RunInSeparateProcess]
    public function test_completed_cibi_excel_download_is_a_styled_saved_data_workbook_with_folio_print_setup(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $report = $folder->cibiReport;
        $report->update([
            'revision' => 7,
            'personal_snapshot' => array_replace($report->personal_snapshot ?? [], [
                'present_address' => 'LATEST SAVED EXCEL ADDRESS',
                'other_remarks' => null,
            ]),
        ]);
        $report->bankAccounts()->create([
            'institution' => 'LATEST SAVED BANK',
            'branch' => 'Main Branch',
            'adb_level' => 'High / Figures: 25000',
            'sort_order' => 1,
        ]);
        $report->bankAccounts()->create([
            'institution' => 'BANK WITHOUT FIGURES',
            'adb_level' => 'Low',
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($ci)
            ->post(route('client-folders.cibi-report.export-excel', $folder))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('BRBI_'.$folder->folder_number.'_SAVED-CLIENT_CI-BI-Report_v7.xlsx');

        $temporary = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($temporary, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedStrings = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $stylesXml = $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($temporary);

        $content = $sheetXml.$sharedStrings;
        $this->assertStringContainsString('CI REPORT - CIBI', $workbookXml);
        $this->assertStringContainsString('LATEST SAVED EXCEL ADDRESS', $content);
        $this->assertStringContainsString('LATEST SAVED BANK', $content);
        $this->assertStringContainsString('N/A', $content);
        $this->assertStringNotContainsString('FORGED POSTED REPORT', $content);
        $this->assertStringNotContainsString('OBASA, REYNALDO JAYSON', $content);
        $this->assertStringNotContainsString('IRISH JANE ALBOR', $content);
        $this->assertStringNotContainsString('IPIL2X ST.', $content);
        $this->assertStringContainsString('<mergeCell', $sheetXml);
        $this->assertStringContainsString('paperSize="14"', $sheetXml);
        $this->assertStringContainsString('fitToWidth="1"', $sheetXml);
        $this->assertStringContainsString('<fills count=', $stylesXml);

        $generated = IOFactory::load($temporary = $this->writeTemporaryWorkbook($response->streamedContent()));
        $template = IOFactory::load(resource_path('report-templates/cibi-report.xlsx'));
        try {
            $this->assertSame(['CI REPORT - CIBI'], $generated->getSheetNames());
            $generatedSheet = $generated->getSheetByName('CI REPORT - CIBI');
            $templateSheet = $template->getSheetByName('CI REPORT - CIBI');
            $this->assertSame($templateSheet->getMergeCells(), $generatedSheet->getMergeCells());
            $this->assertSame($templateSheet->getPageSetup()->getPrintArea(), $generatedSheet->getPageSetup()->getPrintArea());
            $this->assertSame($templateSheet->getPageSetup()->getPaperSize(), $generatedSheet->getPageSetup()->getPaperSize());
            $this->assertSame($templateSheet->getPageMargins()->getTop(), $generatedSheet->getPageMargins()->getTop());
            $this->assertSame($templateSheet->getPageMargins()->getLeft(), $generatedSheet->getPageMargins()->getLeft());
            foreach (['B', 'C', 'G', 'L', 'P', 'V', 'AB'] as $column) {
                $this->assertSame($templateSheet->getColumnDimension($column)->getWidth(), $generatedSheet->getColumnDimension($column)->getWidth());
            }
            foreach ([3, 10, 27, 34, 43, 58, 64] as $row) {
                $this->assertSame($templateSheet->getRowDimension($row)->getRowHeight(), $generatedSheet->getRowDimension($row)->getRowHeight());
            }
            foreach (['C3', 'C10', 'C27', 'C34', 'C43', 'C58', 'G63'] as $cell) {
                $this->assertSame($templateSheet->getStyle($cell)->getHashCode(), $generatedSheet->getStyle($cell)->getHashCode());
            }
            $this->assertSame($templateSheet->getStyle('Q36')->getHashCode(), $generatedSheet->getStyle('Q36')->getHashCode());
            $this->assertCount(count($templateSheet->getDrawingCollection()), $generatedSheet->getDrawingCollection());
            $this->assertSame('LATEST SAVED EXCEL ADDRESS', $generatedSheet->getCell('G13')->getValue());
            $this->assertSame('LATEST SAVED BANK', $generatedSheet->getCell('C36')->getValue());
            $this->assertStringContainsString('/ FIGURES:', $generatedSheet->getCell('L36')->getValue());
            $this->assertStringNotContainsString('25000', $generatedSheet->getCell('L36')->getValue());
            $this->assertSame(25000, $generatedSheet->getCell('Q36')->getValue());
            $this->assertSame('N/A', $generatedSheet->getCell('Q37')->getValue());
        } finally {
            $generated->disconnectWorksheets();
            $template->disconnectWorksheets();
            unlink($temporary);
        }
        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'cibi_report.excel_downloaded',
        ]);
    }

    private function writeTemporaryWorkbook(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-structure-');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_cibi_output_renders_only_populated_bank_rows_and_separates_granted_and_maturity_dates_with_a_dash(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $report = $folder->cibiReport;
        $report->bankAccounts()->createMany([
            ['institution' => 'First Saved Bank', 'branch' => 'Main', 'adb_level' => 'Low / Figures: 1000', 'sort_order' => 1],
            ['institution' => 'Second Saved Bank', 'branch' => 'Uptown', 'adb_level' => 'High / Figures: 2000', 'sort_order' => 2],
            ['institution' => '', 'sort_order' => 3],
        ]);
        $report->loanRecords()->create([
            'institution' => 'Saved Cooperative',
            'granted_date' => '2023-11-12',
            'maturity_date' => '2027-11-12',
            'sort_order' => 1,
        ]);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))
            ->assertOk()
            ->assertSee('<span class="cibi-section-heading-line">III. BANK / FINANCIAL INSTITUTION</span>', false)
            ->assertSee('11/12/2023 - 11/12/2027')
            ->assertDontSee('11/12/2023 / 11/12/2027')
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="cibi-adb-print"'));
    }

    public function test_cibi_output_renders_one_stability_choice_block_per_saved_income_summary(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $report = $folder->cibiReport;
        $report->incomeSourceSummaries()->firstOrFail()->update(['stability_result' => 'existing_strong_capacity']);
        $report->incomeSourceSummaries()->create([
            'source_name' => 'Second Saved Income',
            'stability_result' => 'not_validated',
            'sort_order' => 2,
        ]);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="cibi-income-stability"'));
        $this->assertSame(1, substr_count($html, '( ✓ ) EXISTING WITH STRONG CAPACITY'));
        $this->assertSame(1, substr_count($html, '( ✓ ) WAS NOT/CANNOT BE VALIDATED'));
    }

    public function test_access_is_policy_scoped_and_forged_cross_folder_sources_and_artifacts_are_rejected(): void
    {
        [$assigned, $folder] = $this->cibiContext();
        $other = User::factory()->create();
        $administrator = User::factory()->administrator()->create();
        [, $otherFolder] = $this->cibiContext();
        $otherSource = $this->businessSource($otherFolder);

        $this->actingAs($other)->get(route('client-folders.generated-reports.index', $folder))->assertForbidden();
        $this->actingAs($other)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => 'cibi', 'format' => 'pdf'])->assertForbidden();
        $this->actingAs($other)->post(route('client-folders.cibi-report.export-excel', $folder))->assertForbidden();
        $this->actingAs($administrator)->get(route('client-folders.generated-reports.index', $folder))->assertOk();
        $this->actingAs($assigned)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => 'business_income_source', 'format' => 'pdf', 'income_source_id' => $otherSource->id])->assertNotFound();

        $foreignArtifact = GeneratedReport::factory()->create(['client_folder_id' => $otherFolder->id]);
        $this->actingAs($assigned)->get(route('client-folders.generated-reports.download', [$folder, $foreignArtifact]))->assertNotFound();
        $folder->delete();
        $this->actingAs($administrator)->get(route('client-folders.generated-reports.index', $folder->id))->assertNotFound();
    }

    public function test_regeneration_preserves_history_download_is_protected_and_events_are_audited(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $payload = ['report_type' => 'cibi', 'format' => 'pdf'];
        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), $payload);
        $first = GeneratedReport::firstOrFail();
        $this->actingAs($ci)->post(route('client-folders.generated-reports.regenerate', [$folder, $first]))->assertRedirect();
        $this->assertDatabaseHas('generated_reports', ['scope_key' => 'folder:'.$folder->id.':cibi', 'format' => 'pdf', 'version' => 1]);
        $this->assertDatabaseHas('generated_reports', ['scope_key' => 'folder:'.$folder->id.':cibi', 'format' => 'pdf', 'version' => 2]);
        $this->actingAs($ci)->get(route('client-folders.generated-reports.download', [$folder, $first]))->assertOk()->assertHeader('content-type', 'application/pdf');
        foreach (['generated_report.requested', 'generated_report.completed', 'generated_report.downloaded'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists());
        }
    }

    public function test_generation_failure_is_recorded_without_removing_encoded_report_or_leaking_exception_details(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $mock = Mockery::mock(PdfGenerator::class);
        $mock->shouldReceive('generate')->once()->andThrow(new \RuntimeException('C:\\private\\secret-path failure'));
        $this->app->instance(PdfGenerator::class, $mock);

        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => 'cibi', 'format' => 'pdf'])->assertRedirect()->assertSessionHas('error');
        $report = GeneratedReport::firstOrFail();
        $this->assertSame(GenerationStatus::Failed, $report->status);
        $this->assertStringNotContainsString('secret-path', $report->failure_message);
        $this->assertDatabaseHas('cibi_reports', ['client_folder_id' => $folder->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'generated_report.failed']);
    }

    public function test_template_compatibility_and_request_body_boundaries_are_enforced(): void
    {
        [$ci, $folder] = $this->cibiContext();
        $dedicated = $this->businessSource($folder);
        $fallback = $this->fallbackSource($folder);

        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => 'general_income_source', 'format' => 'pdf', 'income_source_id' => $dedicated->id])->assertSessionHasErrors('income_source_id');
        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => 'business_income_source', 'format' => 'pdf', 'income_source_id' => $fallback->id])->assertSessionHasErrors('income_source_id');
        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), ['report_type' => 'cibi', 'format' => 'pdf', 'report_body' => 'FORGED POSTED REPORT'])->assertRedirect();
        $snapshot = GeneratedReport::where('status', GenerationStatus::Completed)->firstOrFail()->source_snapshot;
        $this->assertSame('SAVED CLIENT', $snapshot['client']);
        $this->assertStringNotContainsString('FORGED', json_encode($snapshot));
    }

    private function cibiContext(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'SAVED CLIENT']);
        $report = CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-08-01', 'submitted_date' => '2026-08-02',
            'branch_name' => 'Main', 'account_officer_name' => 'AO Name', 'ci_risk_level' => 'low', 'purpose_codes' => ['working_capital'], 'prepared_by_name' => 'Investigator', 'revision' => 3, 'state' => RecordState::Complete,
            'personal_snapshot' => ['name' => 'VALIDATED SNAPSHOT NAME', 'present_address' => 'Validated report address', 'civil_status' => 'Married', 'material_cost_level' => 'Medium', 'reputation' => 'Good', 'barangay_findings' => 'No Legal Cases', 'court_background_status' => 'No Legal Cases', 'lifestyle' => 'Modest'],
        ]);
        $report->incomeSourceSummaries()->create(['source_name' => 'Saved Income', 'monthly_amount' => 25000, 'sort_order' => 1]);
        $report->legalFindings()->create(['source_level' => 'barangay', 'result' => 'Clear', 'sort_order' => 1]);

        return [$ci, $folder];
    }

    private function businessSource(ClientFolder $folder): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = IncomeSource::factory()->create(['client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => 1, 'source_name' => 'Saved Store']);
        BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => 'Saved Store', 'registered_owner' => 'Saved Owner']);

        return $source;
    }

    private function fallbackSource(ClientFolder $folder): IncomeSource
    {
        $template = IncomeSourceTemplate::where('is_fallback', true)->firstOrFail();
        $source = IncomeSource::factory()->create(['client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => 1, 'source_name' => 'Saved Declared Sources', 'applicant_name_snapshot' => 'SAVED CLIENT']);
        $report = $source->generalReport()->create(['general_remarks' => 'Saved remarks']);
        $report->declaredItems()->create(['source_name' => 'Salary', 'contribution_rank' => 1, 'sort_order' => 1]);

        return $source;
    }
}
