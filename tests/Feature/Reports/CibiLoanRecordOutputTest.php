<?php

namespace Tests\Feature\Reports;

use App\Enums\OfficialReportType;
use App\Enums\RecordState;
use App\Enums\ReportFormat;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\User;
use App\Services\Reports\CibiExcelExporter;
use App\Services\Reports\OfficialReportDataBuilder;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * CI/BI official outputs for IV. SUMMARY ON CREDIT / LOAN INFORMATION.
 *
 * The encoding table supports one Bank/Coop with zero, one or many loan results. These tests hold
 * all four official outputs to the same relationship: a flat run of consecutive rows per
 * institution, the institution printed once per run, a zero-result Bank/Coop kept (with its
 * findings) instead of dropped or faked, saved sort_order respected over incidental id order, and
 * strict Applicant / exact-Co-Maker scoping.
 *
 * Fixture is the official sheet's own shape: FICCO (zero result), MCCB (two loans), OIC (two
 * loans), with distinct amounts so a mis-grouped or mis-ordered row is unmistakable.
 */
class CibiLoanRecordOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_web_preview_prints_each_bank_coop_once_and_keeps_the_zero_result_findings(): void
    {
        [$ci, $folder] = $this->folderWithMixedLoans();

        $html = $this->actingAs($ci)
            ->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))
            ->assertOk()->getContent();
        $table = $this->loanTable($html);

        // A zero-result Bank/Coop is never dropped, and never given a fabricated amount.
        $this->assertStringContainsString('FICCO', $table);
        $this->assertStringContainsString('TO BE FOLLOW', $table);

        // Each institution is printed once, on the first row of its own consecutive run.
        $this->assertSame(1, substr_count($table, '>FICCO<'));
        $this->assertSame(1, substr_count($table, '>MCCB<'));
        $this->assertSame(1, substr_count($table, '>OIC<'));

        // …and all five saved rows are present, in saved order.
        $this->assertSame(
            ['11,000.00', '12,000.00', '21,000.00', '22,000.00'],
            $this->orderedAmounts($table),
        );
    }

    public function test_official_document_data_feeds_web_pdf_and_docx_the_same_grouped_ordered_rows(): void
    {
        [$ci, $folder] = $this->folderWithMixedLoans();
        $this->actingAs($ci);

        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);

        // Web Preview + PDF share this table: institution once per run, five flat rows in order.
        $rows = $document['cibi']['loan_records'];
        $this->assertSame(['FICCO', 'MCCB', '', 'OIC', ''], array_column($rows, 0));
        $this->assertSame('N/A', $rows[0][1]);                 // no fabricated FICCO amount
        $this->assertSame('TO BE FOLLOW', $rows[0][7]);        // findings survive
        $this->assertSame(['N/A', '11,000.00', '12,000.00', '21,000.00', '22,000.00'], array_column($rows, 1));

        // DOCX renders from the sections list and must carry the identical relationship.
        $section = collect($document['sections'])->firstWhere('title', 'Loan Records');
        $this->assertSame(['FICCO', 'MCCB', '—', 'OIC', '—'], array_column($section['rows'], 0));
        $this->assertSame('TO BE FOLLOW', $section['rows'][0][6]);
        $this->assertSame(['N/A', '11,000.00', '12,000.00', '21,000.00', '22,000.00'], array_column($section['rows'], 1));
    }

    public function test_saved_sort_order_beats_incidental_id_order_in_the_official_outputs(): void
    {
        [$ci, $folder] = $this->folder();
        $report = $this->completeReport($folder, $ci, null);
        // sort_order deliberately disagrees with id order, as it does once rows have been removed
        // and re-added across saves.
        $second = $report->loanRecords()->create(['institution' => 'MCCB', 'original_amount' => 99000, 'payment_performance' => 'SECOND', 'sort_order' => 2]);
        $first = $report->loanRecords()->create(['institution' => 'MCCB', 'original_amount' => 11000, 'payment_performance' => 'FIRST', 'sort_order' => 1]);
        $this->assertTrue($second->id < $first->id, 'Fixture must have id order disagree with sort_order.');

        $this->actingAs($ci);
        $rows = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi)['cibi']['loan_records'];

        $this->assertSame(['11,000.00', '99,000.00'], array_column($rows, 1));
        $this->assertSame(['FIRST', 'SECOND'], array_column($rows, 7));
    }

    public function test_docx_output_carries_each_bank_coop_once_with_its_loans_in_saved_order(): void
    {
        [$ci, $folder] = $this->folderWithMixedLoans();

        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), [
            'report_type' => OfficialReportType::Cibi->value,
            'format' => ReportFormat::Docx->value,
        ])->assertRedirect();

        $documentXml = $this->docxDocumentXml(GeneratedReport::where('format', ReportFormat::Docx)->sole());

        // The zero-result institution and its findings are really in the Word document.
        $this->assertStringContainsString('FICCO', $documentXml);
        $this->assertStringContainsString('TO BE FOLLOW', $documentXml);
        $this->assertStringContainsString('MCCB', $documentXml);
        $this->assertStringContainsString('OIC', $documentXml);

        // Amounts appear in saved order, and each institution's name appears once in the table.
        $this->assertSame(['11,000.00', '12,000.00', '21,000.00', '22,000.00'], $this->orderedAmounts($documentXml));
        $this->assertSame(1, substr_count($documentXml, '>MCCB<'));
        $this->assertSame(1, substr_count($documentXml, '>OIC<'));
    }

    public function test_pdf_artifact_is_produced_from_that_same_authoritative_data(): void
    {
        [$ci, $folder] = $this->folderWithMixedLoans();

        $this->actingAs($ci)->post(route('client-folders.generated-reports.store', $folder), [
            'report_type' => OfficialReportType::Cibi->value,
            'format' => ReportFormat::Pdf->value,
        ])->assertRedirect();

        $pdf = GeneratedReport::where('format', ReportFormat::Pdf)->sole();
        $bytes = app(CiTeamDocumentStorage::class)->disk()->get($pdf->private_file_reference);

        // The PDF is a real rendered artifact. Its text is inside compressed streams, so the row
        // relationship itself is asserted on the shared blade/data above — the Web Preview and the
        // PDF are the same 'reports.official.document' view fed by the same builder output.
        $this->assertSame('%PDF', substr($bytes, 0, 4));
        $this->assertGreaterThan(5000, strlen($bytes));
    }

    public function test_excel_output_lists_each_bank_coop_once_with_its_loans_in_saved_order(): void
    {
        [$ci, $folder] = $this->folderWithMixedLoans();
        $this->actingAs($ci);

        $sheet = $this->workbook($folder);

        $loanStartRow = $this->rowContaining($sheet, 'IV. SUMMARY ON CREDIT/LOAN INFORMATION') + 2;
        $this->assertSame('FICCO', (string) $sheet->getCell('C'.$loanStartRow)->getValue());
        $this->assertSame('TO BE FOLLOW', (string) $sheet->getCell('V'.$loanStartRow)->getValue());
        $this->assertSame('', (string) $sheet->getCell('G'.$loanStartRow)->getValue());

        // MCCB and OIC each print once, with their second loan on the next consecutive row.
        $this->assertSame(['FICCO', 'MCCB', '', 'OIC', ''], array_map(
            fn (int $row): string => (string) $sheet->getCell('C'.$row)->getValue(),
            range($loanStartRow, $loanStartRow + 4),
        ));
        $this->assertSame([11000.0, 12000.0, 21000.0, 22000.0], array_map(
            fn (int $row): float => (float) $sheet->getCell('G'.$row)->getValue(),
            range($loanStartRow + 1, $loanStartRow + 4),
        ));
    }

    public function test_every_output_stays_scoped_to_the_applicant_or_the_exact_co_maker(): void
    {
        [$ci, $folder] = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        foreach ([[null, 'APPLICANTCOOP'], [$coMakerA, 'COMAKERACOOP'], [$coMakerB, 'COMAKERBCOOP']] as [$person, $institution]) {
            $report = $this->completeReport($folder, $ci, $person?->id);
            $report->loanRecords()->create(['institution' => $institution, 'original_amount' => 31000, 'payment_performance' => $institution.' FINDING', 'sort_order' => 1]);
        }
        $this->actingAs($ci);

        foreach ([[null, 'APPLICANTCOOP'], [$coMakerA, 'COMAKERACOOP'], [$coMakerB, 'COMAKERBCOOP']] as [$person, $institution]) {
            $builder = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi, null, $person);
            $encoded = json_encode($builder['cibi']['loan_records'], JSON_THROW_ON_ERROR);
            $excel = $this->workbook($folder, $person);
            $loanStartRow = $this->rowContaining($excel, 'IV. SUMMARY ON CREDIT/LOAN INFORMATION') + 2;
            $excelInstitutions = implode('|', array_map(fn (int $row): string => (string) $excel->getCell('C'.$row)->getValue(), range($loanStartRow, $loanStartRow + 2)));

            $this->assertStringContainsString($institution, $encoded);
            $this->assertStringContainsString($institution, $excelInstitutions);
            foreach (['APPLICANTCOOP', 'COMAKERACOOP', 'COMAKERBCOOP'] as $other) {
                if ($other === $institution) {
                    continue;
                }
                $this->assertStringNotContainsString($other, $encoded, "$institution output leaked $other");
                $this->assertStringNotContainsString($other, $excelInstitutions, "$institution workbook leaked $other");
            }
        }
    }

    public function test_the_three_credit_totals_render_from_their_saved_values_untouched(): void
    {
        [$ci, $folder] = $this->folderWithMixedLoans();
        $folder->cibiReport()->whereNull('co_maker_id')->sole()
            ->update(['summary_totals' => ['institutions_checked' => 9, 'institutions_declared' => 4, 'loan_records_found' => 7]]);
        $this->actingAs($ci);

        $totals = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi)['cibi']['totals'];

        // Five saved rows across three institutions must not have re-derived any of the three.
        $this->assertSame(['checked' => 9, 'declared' => 4, 'loans' => 7], $totals);
        $sheet = $this->workbook($folder);
        $totalRow = $this->rowContaining($sheet, 'TOTAL # OF LOAN RECORDS FOUND');
        $this->assertSame(7, (int) $sheet->getCell('H'.$totalRow)->getValue());
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folder(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'SAVED CLIENT'])];
    }

    /** FICCO = zero result, MCCB = two loans, OIC = two loans. */
    private function folderWithMixedLoans(): array
    {
        [$ci, $folder] = $this->folder();
        $report = $this->completeReport($folder, $ci, null);
        $report->loanRecords()->create(['institution' => 'FICCO', 'payment_performance' => 'TO BE FOLLOW', 'sort_order' => 1]);
        $report->loanRecords()->create(['institution' => 'MCCB', 'original_amount' => 11000, 'remaining_balance' => 1100, 'payment_performance' => 'MCCB ONE', 'sort_order' => 2]);
        $report->loanRecords()->create(['institution' => 'MCCB', 'original_amount' => 12000, 'remaining_balance' => 1200, 'payment_performance' => 'MCCB TWO', 'sort_order' => 3]);
        $report->loanRecords()->create(['institution' => 'OIC', 'original_amount' => 21000, 'remaining_balance' => 2100, 'payment_performance' => 'OIC ONE', 'sort_order' => 4]);
        $report->loanRecords()->create(['institution' => 'OIC', 'original_amount' => 22000, 'remaining_balance' => 2200, 'payment_performance' => 'OIC TWO', 'sort_order' => 5]);

        return [$ci, $folder];
    }

    private function completeReport(ClientFolder $folder, User $ci, ?int $coMakerId): CibiReport
    {
        return CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-08-01', 'submitted_date' => '2026-08-02', 'branch_name' => 'Main',
            'account_officer_name' => 'AO Name', 'ci_risk_level' => 'low', 'purpose_codes' => ['working_capital'],
            'prepared_by_name' => 'Investigator', 'state' => RecordState::Complete,
            'personal_snapshot' => ['name' => 'SNAPSHOT NAME', 'present_address' => 'Address', 'civil_status' => 'Married', 'material_cost_level' => 'Medium', 'reputation' => 'Good', 'barangay_findings' => 'No Legal Cases', 'court_background_status' => 'No Legal Cases', 'lifestyle' => 'Modest'],
        ]);
    }

    private function workbook(ClientFolder $folder, ?CoMaker $person = null)
    {
        $temporary = tempnam(sys_get_temp_dir(), 'cibi-output-xlsx-');
        file_put_contents($temporary, app(CibiExcelExporter::class)->generate($folder->fresh(), $person));
        $sheet = IOFactory::load($temporary)->getSheetByName('CI REPORT - CIBI');
        unlink($temporary);

        return $sheet;
    }

    private function rowContaining($sheet, string $text): int
    {
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (str_contains((string) $cell->getValue(), $text)) {
                    return $row->getRowIndex();
                }
            }
        }

        $this->fail("Could not find workbook row containing: {$text}");
    }

    private function docxDocumentXml(GeneratedReport $report): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'cibi-output-docx-');
        file_put_contents($temporary, app(CiTeamDocumentStorage::class)->disk()->get($report->private_file_reference));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($temporary) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($temporary);

        return (string) $xml;
    }

    private function loanTable(string $html): string
    {
        $start = strpos($html, 'class="cibi-form-table cibi-grid-table cibi-loans"');
        $this->assertNotFalse($start, 'Missing the Summary on Credit / Loan Information table.');
        $end = strpos($html, '</table>', $start);

        return substr($html, $start, $end - $start);
    }

    /** @return array<int, string> */
    private function orderedAmounts(string $markup): array
    {
        preg_match_all('/(1[12],000\.00|2[12],000\.00)/', $markup, $matches);

        return array_values(array_unique($matches[1]));
    }
}
