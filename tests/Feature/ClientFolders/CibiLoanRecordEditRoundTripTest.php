<?php

namespace Tests\Feature\ClientFolders;

use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CI/BI Report → IV. SUMMARY ON CREDIT / LOAN INFORMATION, Edit round trip.
 *
 * The grouped flat table has ONE implementation, shared by the Applicant and every Co-Maker, and
 * these tests hold it to that: whatever was saved for a person is exactly what their Edit form
 * replays — zero-result Bank/Coops included — with the same CibiLoanRecord ids, in the same order,
 * and with no row ever crossing between the Applicant and a Co-Maker, or between two Co-Makers.
 */
class CibiLoanRecordEditRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_edit_replays_zero_one_and_many_loan_rows_exactly_as_saved(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, $this->mixedLoanRows());

        $this->assertMixedStructureLoads($this->editSection($ci, $folder, null));
    }

    public function test_co_maker_edit_replays_zero_one_and_many_loan_rows_exactly_as_saved(): void
    {
        [$ci, $folder] = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET CO MAKER']);
        $this->save($ci, $folder, $coMaker->id, $this->mixedLoanRows());

        // Identical assertions, identical shared UI — nothing here is Applicant-specific.
        $this->assertMixedStructureLoads($this->editSection($ci, $folder, $coMaker->id));
    }

    public function test_applicant_save_then_reopen_preserves_rows_ids_and_order(): void
    {
        [$ci, $folder] = $this->folder();
        $this->assertRoundTripKeepsRowIdentity($ci, $folder, null);
    }

    public function test_co_maker_save_then_reopen_preserves_rows_ids_and_order(): void
    {
        [$ci, $folder] = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ROUND TRIP CO MAKER']);
        $this->assertRoundTripKeepsRowIdentity($ci, $folder, $coMaker->id);
    }

    public function test_edit_replays_a_bank_coops_loans_in_their_saved_order_not_incidental_id_order(): void
    {
        [$ci, $folder] = $this->folder();
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id]);
        // sort_order deliberately disagrees with id order — what a real folder ends up with once
        // rows have been removed and re-added across several saves.
        $first = $report->loanRecords()->create(['institution' => 'MCCB', 'original_amount' => 90000, 'sort_order' => 2]);
        $second = $report->loanRecords()->create(['institution' => 'MCCB', 'original_amount' => 10000, 'sort_order' => 1]);
        $this->assertTrue($first->id < $second->id);

        $section = $this->editSection($ci, $folder, null);
        $rendered = [];
        foreach (explode('<tr data-repeater-row', $section) as $row) {
            if (preg_match('/name="loan_records\[\d+\]\[original_amount\]" value="([\d.]+)"/', $row, $matches)) {
                $rendered[] = $matches[1];
            }
        }

        // Saved order (sort_order) wins over the order the ids happen to be in.
        $this->assertSame(['10000.00', '90000.00'], $rendered);
    }

    public function test_edit_forms_never_leak_loan_rows_between_the_applicant_and_any_co_maker(): void
    {
        [$ci, $folder] = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $people = [
            [null, 'APPLICANT ONLY COOP'],
            [$coMakerA->id, 'CO MAKER A ONLY COOP'],
            [$coMakerB->id, 'CO MAKER B ONLY COOP'],
        ];
        foreach ($people as [$coMakerId, $institution]) {
            $this->save($ci, $folder, $coMakerId, [
                $this->loanRow($institution, 'Salary Loan', '10,000'),
                $this->loanRow($institution, 'Emergency Loan', '20,000'),
            ]);
        }

        // Each person's Edit form shows its own two rows and nobody else's — in both directions.
        foreach ($people as [$coMakerId, $institution]) {
            $section = $this->editSection($ci, $folder, $coMakerId);
            $this->assertSame(2, substr_count($section, '<tr data-repeater-row'), $institution);
            $this->assertSame(2, substr_count($section, 'value="'.$institution.'"'), $institution);
            foreach ($people as [, $otherInstitution]) {
                if ($otherInstitution === $institution) {
                    continue;
                }
                $this->assertStringNotContainsString($otherInstitution, $section);
            }
        }
    }

    public function test_the_bank_coop_placeholder_reads_enter_bank_coop_name(): void
    {
        [$ci, $folder] = $this->folder();
        $this->save($ci, $folder, null, [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')]);

        $page = $this->editPage($ci, $folder, null);
        $this->assertStringContainsString('placeholder="Enter bank/coop name"', $page);
        foreach (['Enter institution name', 'Institution name', 'Enter bank or cooperative name', 'Enter bank/cooperative name', 'Enter bank/cooperative and branch'] as $rejected) {
            $this->assertStringNotContainsString('placeholder="'.$rejected.'"', $page);
        }
    }

    public function test_the_three_credit_totals_survive_the_edit_round_trip_untouched(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['summary_totals'] = ['institutions_checked' => 9, 'institutions_declared' => 4, 'loan_records_found' => 7];
        $payload['loan_records'] = $this->mixedLoanRows();
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        // Four saved rows across three institutions must not have re-derived any of the three.
        $report = CibiReport::whereBelongsTo($folder)->sole();
        $this->assertSame(['institutions_checked' => 9, 'institutions_declared' => 4, 'loan_records_found' => 7], $report->summary_totals);

        $page = $this->editPage($ci, $folder, null);
        $this->assertStringContainsString('name="summary_totals[institutions_checked]"', $page);
        $this->assertStringContainsString('value="9"', $page);
        $this->assertStringContainsString('value="4"', $page);
        $this->assertStringContainsString('value="7"', $page);
    }

    /** FICCO = zero result, MCCB = two loans, OIC = one loan — the sheet from the official form. */
    private function mixedLoanRows(): array
    {
        return [
            ['institution' => 'FICCO', 'combined_findings' => 'TO BE FOLLOW'],
            $this->loanRow('MCCB', 'Deposit', '50,000'),
            $this->loanRow('MCCB', 'Deposit', '30,000'),
            $this->loanRow('OIC', 'Deposit', '60,000'),
        ];
    }

    private function assertMixedStructureLoads(string $section): void
    {
        // Four saved rows come back as four rows — never truncated to the first of each group.
        $this->assertSame(4, substr_count($section, '<tr data-repeater-row'));

        // MCCB is ONE run of two rows: both post it, only the first shows it.
        $this->assertSame(2, substr_count($section, 'value="MCCB"'));
        $this->assertSame(1, substr_count($section, 'value="FICCO"'));
        $this->assertSame(1, substr_count($section, 'value="OIC"'));
        $this->assertSame(1, preg_match_all('/data-loan-institution-controls\s+hidden/', $section));

        // FICCO stays a genuine zero-result row: no fake loan, findings intact.
        $ficco = $this->rowContaining($section, 'value="FICCO"');
        $this->assertStringContainsString('data-loan-empty', $ficco);
        $this->assertStringContainsString('TO BE FOLLOW</textarea>', $ficco);
        $this->assertSame(5, substr_count($ficco, 'cibi-loan-blank'));
        $this->assertStringNotContainsString('value="50,000"', $ficco);

        // …while the three real loan rows are not in the empty state at all. (The negative
        // lookahead skips the table's own data-loan-empty-state row, which shares the prefix.)
        $this->assertSame(1, preg_match_all('/data-loan-empty(?!-)/', $section));
    }

    private function assertRoundTripKeepsRowIdentity(User $ci, ClientFolder $folder, ?int $coMakerId): void
    {
        $this->save($ci, $folder, $coMakerId, $this->mixedLoanRows());
        $report = $folder->cibiReport()->where('co_maker_id', $coMakerId)->sole();
        $before = $report->loanRecords()->orderBy('sort_order')->pluck('institution', 'id')->all();
        $this->assertCount(4, $before);

        // Reopen, edit one row and add a third MCCB loan, then save again against that revision.
        $ids = array_keys($before);
        $payload = $this->payload();
        $payload['co_maker_id'] = $coMakerId;
        $payload['expected_revision'] = $report->revision;
        $payload['loan_records'] = [
            ['id' => $ids[0], 'institution' => 'FICCO', 'combined_findings' => 'STILL TO BE FOLLOW'],
            ['id' => $ids[1]] + $this->loanRow('MCCB', 'Deposit', '55,000'),
            ['id' => $ids[2]] + $this->loanRow('MCCB', 'Deposit', '30,000'),
            $this->loanRow('MCCB', 'Deposit', '15,000'),
            ['id' => $ids[3]] + $this->loanRow('OIC', 'Deposit', '60,000'),
        ];
        $this->actingAs($ci)->put($this->updateRoute($folder), $payload)->assertRedirect();

        // Every original row kept its id — nothing was deleted and recreated behind the scenes.
        $after = $report->fresh()->loanRecords()->orderBy('sort_order')->get();
        $this->assertSame(5, $after->count());
        foreach ($ids as $id) {
            $this->assertTrue($after->contains('id', $id), "Row $id lost its identity across the round trip.");
        }
        $this->assertSame('55000.00', $after->firstWhere('id', $ids[1])->original_amount);
        $this->assertSame('30000.00', $after->firstWhere('id', $ids[2])->original_amount);
        $this->assertSame('STILL TO BE FOLLOW', $after->firstWhere('id', $ids[0])->payment_performance);
        $this->assertSame(['FICCO', 'MCCB', 'MCCB', 'MCCB', 'OIC'], $after->pluck('institution')->all());

        // …and reopening replays exactly that: 5 rows, MCCB as one run of three.
        $section = $this->editSection($ci, $folder, $coMakerId);
        $this->assertSame(5, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(3, substr_count($section, 'value="MCCB"'));
        $this->assertSame(2, preg_match_all('/data-loan-institution-controls\s+hidden/', $section));
        $this->assertStringContainsString('STILL TO BE FOLLOW</textarea>', $section);
        $this->assertStringContainsString('data-loan-empty', $section);
    }

    private function rowContaining(string $section, string $needle): string
    {
        foreach (explode('<tr data-repeater-row', $section) as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail("No rendered loan row contains: $needle");
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folder(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id])];
    }

    private function save(User $ci, ClientFolder $folder, ?int $coMakerId, array $loanRows): void
    {
        $payload = $this->payload();
        $payload['co_maker_id'] = $coMakerId;
        $payload['loan_records'] = $loanRows;
        $this->actingAs($ci)->put($this->updateRoute($folder), $payload)->assertRedirect();
    }

    private function editPage(User $ci, ClientFolder $folder, ?int $coMakerId): string
    {
        $url = route('client-folders.cibi-report.edit', $folder);
        if ($coMakerId !== null) {
            $url .= '?person=co-maker&co_maker_id='.$coMakerId;
        }

        return $this->actingAs($ci)->get($url)->assertOk()->getContent();
    }

    private function editSection(User $ci, ClientFolder $folder, ?int $coMakerId): string
    {
        $content = $this->editPage($ci, $folder, $coMakerId);
        $start = strpos($content, 'id="loan_records-section"');
        $this->assertNotFalse($start, 'Missing the Summary on Credit / Loan Information section.');
        $end = strpos($content, '</table>', $start);

        return substr($content, $start, $end - $start);
    }

    private function updateRoute(ClientFolder $folder): string
    {
        return route('client-folders.cibi-report.update', $folder);
    }

    private function loanRow(string $institution, string $security, string $amount): array
    {
        return [
            'institution' => $institution, 'original_amount' => $amount, 'remaining_balance' => '20,000',
            'amortization_amount' => '4,000', 'granted_date' => '2026-01-01', 'maturity_date' => '2027-01-01',
            'cycle_number' => 1, 'cycle_label' => '1st Cycle', 'security_type' => $security,
            'combined_findings' => 'SATISFACTORY',
        ];
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
