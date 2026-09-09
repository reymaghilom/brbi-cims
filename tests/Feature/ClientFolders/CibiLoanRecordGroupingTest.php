<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\OfficialReportType;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\Reports\CibiExcelExporter;
use App\Services\Reports\OfficialReportDataBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * CI/BI Report → IV. SUMMARY ON CREDIT / LOAN INFORMATION.
 *
 * One Bank / Coop / Branch may have ZERO, ONE or MANY loan results. The persisted shape is
 * unchanged — still one flat cibi_loan_records row per loan result — so these tests pin the two
 * things that make the grouping trustworthy: a zero-result institution stays a real, editable
 * entry (institution + Performance & Findings, every loan column genuinely NULL), and rows that
 * share an institution are rendered/exported as ONE group without ever being merged, reordered
 * into, or lifted out of another institution, report, or person.
 */
class CibiLoanRecordGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_a_new_form_renders_no_blank_bank_coop_groups_and_offers_a_single_add_action(): void
    {
        [$ci, $folder] = $this->folder();

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $section = $this->loanSection($content);

        // A Bank/Coop group is a real institution, so none are invented up front.
        $this->assertSame(0, substr_count($section, 'data-loan-group-header'));
        $this->assertSame(0, substr_count($section, '<tr data-repeater-row'));
        $this->assertStringContainsString('No bank or cooperative added yet.', $section);
        // …and exactly one action creates one, rendered below the table rather than in the header.
        $this->assertStringNotContainsString('data-repeater-add', $section);
        $this->assertSame(1, substr_count($content, 'cibi-loan-group-add'));
    }

    public function test_a_saved_bank_coop_renders_as_exactly_one_group_with_no_blank_companions(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        $this->assertSame(1, substr_count($section, 'data-loan-group-header'));
        $this->assertSame(1, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(1, substr_count($section, 'Bank / Coop / Branch'));
        $this->assertStringContainsString('>Loan 1</span>', $section);
        // The result row must fill exactly the 8 declared columns — a miscounted cell silently
        // shears the whole grouped grid out of alignment.
        $this->assertSame(8, substr_count($section, '<th scope="col"'));
        $resultRow = substr($section, strpos($section, '<tr data-repeater-row'));
        $this->assertSame(8, substr_count(substr($resultRow, 0, strpos($resultRow, '</tr>')), '<td'));
    }

    public function test_a_zero_result_group_shows_em_dashes_for_loan_columns_and_keeps_findings_editable(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [['institution' => 'ABC Cooperative', 'combined_findings' => 'No existing loan record found during verification.']];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        $this->assertStringContainsString('data-loan-empty', $section);
        $this->assertStringContainsString('No loan record added.', $section);
        // Five loan columns collapse to an em dash; Performance & Findings never does.
        $this->assertSame(5, substr_count($section, 'cibi-loan-blank'));
        $this->assertStringContainsString('No existing loan record found during verification.</textarea>', $section);
    }

    public function test_a_bank_coop_with_zero_loan_results_saves_with_findings_and_no_fabricated_loan_values(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [[
            'institution' => 'ABC Cooperative',
            'combined_findings' => 'No existing loan record found during verification.',
        ]];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $loan = CibiReport::whereBelongsTo($folder)->sole()->loanRecords()->sole();
        $this->assertSame('ABC Cooperative', $loan->institution);
        $this->assertSame('No existing loan record found during verification.', $loan->payment_performance);
        // No "0" / "N/A" / "NO LOAN" filler is ever invented for a zero-result inquiry.
        foreach (['original_amount', 'remaining_balance', 'amortization_amount', 'granted_date', 'maturity_date', 'cycle_number', 'cycle_label', 'security_type'] as $field) {
            $this->assertNull($loan->{$field}, $field);
        }
    }

    public function test_a_bank_coop_can_have_one_loan_result(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $loan = CibiReport::whereBelongsTo($folder)->sole()->loanRecords()->sole();
        $this->assertSame('ABC Cooperative', $loan->institution);
        $this->assertSame('100000.00', $loan->original_amount);
        $this->assertSame('Salary Loan', $loan->security_type);
    }

    public function test_a_bank_coop_can_have_many_loan_results_and_still_renders_as_one_group(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
            $this->loanRow('ABC Cooperative', 'Business Loan', '300,000'),
        ];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $report = CibiReport::whereBelongsTo($folder)->sole();
        $this->assertSame(3, $report->loanRecords()->count());
        $this->assertSame(['ABC Cooperative'], $report->loanRecords()->pluck('institution')->unique()->values()->all());

        // Three loan results under ONE institution must never render as three separate Bank/Coop
        // groups — one group bar, three loan-result rows beneath it.
        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $section = $this->loanSection($content);
        $this->assertSame(1, substr_count($section, 'data-loan-group-header'));
        $this->assertSame(3, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(1, substr_count($section, 'ABC Cooperative'));
        $this->assertStringContainsString('data-loan-add-result', $section);
        $this->assertStringContainsString('data-loan-result-remove', $section);
        $this->assertStringContainsString('data-repeater-add', $content);
        $this->assertStringContainsString('Add Bank / Coop', $content);
    }

    public function test_editing_the_second_loan_result_leaves_its_siblings_untouched(): void
    {
        [$ci, $folder, $report] = $this->savedReportWithThreeLoans();
        [$first, $second, $third] = $report->loanRecords()->orderBy('sort_order')->get()->all();

        $payload = $this->payload();
        $payload['expected_revision'] = $report->revision;
        $payload['loan_records'] = [
            ['id' => $first->id] + $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            ['id' => $second->id] + $this->loanRow('ABC Cooperative', 'Emergency Loan', '55,000'),
            ['id' => $third->id] + $this->loanRow('ABC Cooperative', 'Business Loan', '300,000'),
        ];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $this->assertSame('100000.00', $first->fresh()->original_amount);
        $this->assertSame('55000.00', $second->fresh()->original_amount);
        $this->assertSame('300000.00', $third->fresh()->original_amount);
        $this->assertSame(3, $report->loanRecords()->count());
    }

    public function test_removing_one_loan_result_preserves_its_siblings_and_their_ids(): void
    {
        [$ci, $folder, $report] = $this->savedReportWithThreeLoans();
        [$first, $second, $third] = $report->loanRecords()->orderBy('sort_order')->get()->all();

        $payload = $this->payload();
        $payload['expected_revision'] = $report->revision;
        $payload['loan_records'] = [
            ['id' => $first->id] + $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            ['id' => $second->id, '_delete' => '1'],
            ['id' => $third->id] + $this->loanRow('ABC Cooperative', 'Business Loan', '300,000'),
        ];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $this->assertDatabaseMissing('cibi_loan_records', ['id' => $second->id]);
        $this->assertDatabaseHas('cibi_loan_records', ['id' => $first->id, 'security_type' => 'Salary Loan']);
        $this->assertDatabaseHas('cibi_loan_records', ['id' => $third->id, 'security_type' => 'Business Loan']);
        $this->assertSame(2, $report->loanRecords()->count());
    }

    public function test_removing_the_final_loan_result_leaves_the_bank_coop_with_its_findings(): void
    {
        [$ci, $folder, $report] = $this->savedReportWithThreeLoans();
        [$first, $second, $third] = $report->loanRecords()->orderBy('sort_order')->get()->all();

        // The last surviving row keeps the institution and Performance & Findings while every
        // loan-specific field is cleared — exactly what the empty group state posts.
        $payload = $this->payload();
        $payload['expected_revision'] = $report->revision;
        $payload['loan_records'] = [
            ['id' => $first->id, 'institution' => 'ABC Cooperative', 'original_amount' => '', 'remaining_balance' => '', 'amortization_amount' => '', 'granted_date' => '', 'maturity_date' => '', 'cycle_number' => '', 'cycle_label' => '', 'security_type' => '', 'combined_findings' => 'Loans fully settled; nothing outstanding.'],
            ['id' => $second->id, '_delete' => '1'],
            ['id' => $third->id, '_delete' => '1'],
        ];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $loan = $report->loanRecords()->sole();
        $this->assertSame($first->id, $loan->id);
        $this->assertSame('ABC Cooperative', $loan->institution);
        $this->assertSame('Loans fully settled; nothing outstanding.', $loan->payment_performance);
        $this->assertNull($loan->original_amount);
        $this->assertNull($loan->security_type);

        // …and it comes back as a zero-result group: the institution appears exactly once, in its
        // empty state, alongside the blank starter groups this section has always padded up to.
        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());
        $this->assertSame(1, substr_count($section, 'ABC Cooperative'));
        $this->assertStringContainsString('data-loan-empty', $section);
        $this->assertStringContainsString('No loan record added.', $section);
        $this->assertStringNotContainsString('value="100,000"', $section);
    }

    public function test_bank_coop_prefill_renders_as_one_zero_result_group_and_stays_unsaved_until_the_report_is_saved(): void
    {
        [$ci, $folder] = $this->folder();
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id]);
        $report->loanRecords()->create(['institution' => 'Manual Bank', 'original_amount' => 125000, 'sort_order' => 1]);

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        // The one saved institution is its own group; the padded starter groups carry no data.
        $this->assertStringContainsString('value="Manual Bank"', $section);
        $this->assertSame(1, $report->loanRecords()->count());
        $this->assertDatabaseCount('cibi_loan_records', 1);
    }

    public function test_loan_groups_stay_isolated_between_the_applicant_and_each_co_maker(): void
    {
        [$ci, $folder] = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        foreach ([[null, 'Applicant Coop'], [$coMakerA->id, 'Co Maker A Coop'], [$coMakerB->id, 'Co Maker B Coop']] as [$coMakerId, $institution]) {
            $payload = $this->payload();
            $payload['co_maker_id'] = $coMakerId;
            $payload['loan_records'] = [
                $this->loanRow($institution, 'Salary Loan', '10,000'),
                $this->loanRow($institution, 'Emergency Loan', '20,000'),
            ];
            $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();
        }

        $applicant = $folder->cibiReport()->whereNull('co_maker_id')->sole();
        $reportA = $folder->cibiReport()->where('co_maker_id', $coMakerA->id)->sole();
        $reportB = $folder->cibiReport()->where('co_maker_id', $coMakerB->id)->sole();

        $this->assertSame(['Applicant Coop'], $applicant->loanRecords()->pluck('institution')->unique()->all());
        $this->assertSame(['Co Maker A Coop'], $reportA->loanRecords()->pluck('institution')->unique()->all());
        $this->assertSame(['Co Maker B Coop'], $reportB->loanRecords()->pluck('institution')->unique()->all());
        $this->assertSame(2, $reportA->loanRecords()->count());

        // Co-Maker A can never be handed Co-Maker B's rows, in either direction.
        $payload = $this->payload();
        $payload['co_maker_id'] = $coMakerA->id;
        $payload['expected_revision'] = $reportA->revision;
        $payload['loan_records'] = [['id' => $reportB->loanRecords()->first()->id, 'institution' => 'Stolen Coop']];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertSessionHasErrors(['loan_records.0.id']);
        $this->assertSame(['Co Maker A Coop'], $reportA->fresh()->loanRecords()->pluck('institution')->unique()->all());
    }

    public function test_official_web_pdf_and_docx_output_prints_each_bank_coop_once_and_keeps_zero_result_findings(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
            ['institution' => 'Zero Result Bank', 'combined_findings' => 'No existing loan record found during verification.'],
        ];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);

        // Web preview + PDF share this table: the institution cell is printed once per group.
        $rows = $document['cibi']['loan_records'];
        $this->assertSame(['ABC Cooperative', '', 'Zero Result Bank'], array_column($rows, 0));
        $this->assertSame('N/A', $rows[2][1]);
        $this->assertSame('No existing loan record found during verification.', $rows[2][7]);

        // DOCX renders from the sections list and must group identically — a section table's blank
        // cell is this builder's existing em-dash placeholder, not a repeated institution.
        $loanSection = collect($document['sections'])->firstWhere('title', 'Loan Records');
        $this->assertSame(['ABC Cooperative', '—', 'Zero Result Bank'], array_column($loanSection['rows'], 0));
        $this->assertSame('No existing loan record found during verification.', $loanSection['rows'][2][6]);
    }

    public function test_official_excel_output_prints_each_bank_coop_once_and_keeps_zero_result_findings(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
            ['institution' => 'Zero Result Bank', 'combined_findings' => 'No existing loan record found during verification.'],
        ];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $temporary = tempnam(sys_get_temp_dir(), 'cibi-loan-xlsx-');
        file_put_contents($temporary, app(CibiExcelExporter::class)->generate($folder->fresh()));
        $sheet = IOFactory::load($temporary)->getSheetByName('CI REPORT - CIBI');
        $cells = [
            'C45' => (string) $sheet->getCell('C45')->getValue(),
            'C46' => (string) $sheet->getCell('C46')->getValue(),
            'C47' => (string) $sheet->getCell('C47')->getValue(),
            'V47' => (string) $sheet->getCell('V47')->getValue(),
            'G47' => (string) $sheet->getCell('G47')->getValue(),
        ];
        unlink($temporary);

        $this->assertSame('ABC Cooperative', $cells['C45']);
        $this->assertSame('', $cells['C46']);
        $this->assertSame('Zero Result Bank', $cells['C47']);
        $this->assertSame('No existing loan record found during verification.', $cells['V47']);
        $this->assertSame('N/A', $cells['G47']);
    }

    public function test_the_three_credit_totals_keep_their_own_submitted_values_and_are_never_derived_from_the_grouping(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['summary_totals'] = ['institutions_checked' => 8, 'institutions_declared' => 3, 'loan_records_found' => 4];
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
            ['institution' => 'Zero Result Bank', 'combined_findings' => 'No loan found.'],
        ];

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)->assertOk()
            ->assertJsonPath('report.institutions_checked', 8)
            ->assertJsonPath('report.institutions_declared', 3)
            ->assertJsonPath('report.loan_records_found', 4);

        // Three saved rows / two groups must not have rewritten any of the three totals.
        $this->assertSame(
            ['institutions_checked' => 8, 'institutions_declared' => 3, 'loan_records_found' => 4],
            CibiReport::whereBelongsTo($folder)->sole()->summary_totals,
        );
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folder(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id])];
    }

    /** @return array{0: User, 1: ClientFolder, 2: CibiReport} */
    private function savedReportWithThreeLoans(): array
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
            $this->loanRow('ABC Cooperative', 'Business Loan', '300,000'),
        ];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        return [$ci, $folder, CibiReport::whereBelongsTo($folder)->sole()];
    }

    private function loanRow(string $institution, string $security, string $amount): array
    {
        return [
            'institution' => $institution, 'original_amount' => $amount, 'remaining_balance' => '50,000',
            'amortization_amount' => '5,000', 'granted_date' => '2026-01-01', 'maturity_date' => '2027-01-01',
            'cycle_number' => 2, 'cycle_label' => '2nd Cycle', 'security_type' => $security,
            'combined_findings' => 'Satisfactory / no adverse findings',
        ];
    }

    private function loanSection(string $content): string
    {
        $start = strpos($content, 'id="loan_records-section"');
        $this->assertNotFalse($start, 'Missing the Summary on Credit / Loan Information section.');
        $end = strpos($content, '</table>', $start);

        return substr($content, $start, $end - $start);
    }

    private function payload(): array
    {
        return [
            'intent' => 'complete', 'start_date' => '2026-07-03', 'submitted_date' => '2026-07-04', 'party_type' => 'borrower',
            'branch_name' => 'BLU TIN-AO', 'account_officer_name' => 'IRISH JANE ALBOR', 'amount_applied' => '340000.00',
            'ci_risk_level' => 'mid', 'purpose_codes' => ['building_construction_home_renovation'], 'purpose_other' => null,
            'summary_totals' => ['institutions_checked' => 1, 'institutions_declared' => 1, 'loan_records_found' => 1],
            'personal_snapshot' => [
                'name' => 'VALIDATED CLIENT NAME', 'age' => 42, 'spouse_name' => 'VALIDATED SPOUSE', 'spouse_age' => 40,
                'present_address' => 'Validated present address', 'length_of_stay_months' => 36, 'residence_status' => 'Owned', 'residence_status_from' => null,
                'monthly_rent' => null, 'living_with_parents' => false, 'other_residences' => 'One other residence',
                'home_condition' => 'New', 'number_of_storeys' => 2, 'material_cost_level' => 'Medium', 'living_condition' => 'Good',
                'previous_address' => 'Validated previous address', 'previous_address_length_of_stay_months' => 24,
                'parents_address' => 'Validated parents address', 'dependents_count' => 2, 'civil_status' => 'Married',
                'separated_year' => null, 'reputation' => 'Good', 'barangay_findings' => 'Validated barangay findings',
                'court_background_status' => 'No Legal Cases', 'court_background' => 'No adverse court record',
                'lifestyle' => 'Modest', 'vehicles_owned' => 'One vehicle', 'contact_details' => '09170000000 / client@example.test',
                'other_remarks' => 'Validated personal remarks',
            ],
            'purpose_remarks' => 'Confidential narrative about the purpose.', 'negative_credit_findings' => 'Confidential narrative findings.',
            'other_remarks' => 'Validated facts.', 'prepared_by_name' => 'Assigned Investigator', 'noted_by_name' => 'CA1',
            'bank_accounts' => [['institution' => 'Primary Bank', 'branch' => 'Main', 'year_opened' => 2020, 'adb_level_choice' => 'mid', 'adb_level_figures' => null, 'capital_share_amount' => '50000', 'capital_share_text' => 'CA 25,000 / SA 25,000', 'relevant_remarks' => 'Validated']],
            'credit_checks' => [['institution' => 'Primary Bank', 'branch' => 'Main', 'is_declared' => '1', 'check_status' => 'Validated', 'checked_date' => '2026-07-03', 'key_information' => 'Account confirmed', 'remarks' => null]],
            'income_summaries' => [['source_name' => 'Retail Store', 'source_type' => 'Business', 'stability_result' => 'Strong capacity', 'validation_status' => 'Validated', 'key_information' => 'Business observed', 'monthly_amount' => '45000']],
            'legal_findings' => [['source_level' => 'Barangay', 'result' => 'No legal cases', 'details' => 'No adverse record', 'checked_at' => '2026-07-03']],
        ];
    }
}
