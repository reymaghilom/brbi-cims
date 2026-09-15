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
 * IV. Summary on Credit / Loan Information stays readable on phones for both the Applicant and a
 * Co-Maker CI/BI report. Presentation only: the same rows (the same inputs the loan repeater and
 * save rely on) keep the desktop table, and on phones each row is styled as a labelled card — every
 * cell carries its column header as a visible label. Verified by markup/CSS contract here; real
 * phone visual QA is still required.
 */
class CibiLoanSummaryMobileTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Bank / Coop / Branch', 'Original Amount', 'Balance', 'Amortization', 'Granted / Maturity', 'Cycle / Security', 'Performance &amp; Findings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_and_co_maker_reports_render_the_same_labelled_loan_rows_without_leaking(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co Maker', 'first_name' => 'Co', 'last_name' => 'Maker']);

        $applicantReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id]);
        $longName = 'Rural Agricultural Finance Incorporated Microfinance Cooperative - Poblacion Extension Branch';
        $applicantReport->loanRecords()->create(['institution' => $longName, 'original_amount' => '20000', 'remaining_balance' => '5000', 'amortization_amount' => '850', 'cycle_label' => '3', 'security_type' => 'Chattel', 'payment_performance' => 'Always paid on time without any missed amortization', 'sort_order' => 1]);
        $applicantReport->loanRecords()->create(['institution' => $longName, 'original_amount' => '10000', 'remaining_balance' => '0', 'sort_order' => 2]);
        $applicantReport->loanRecords()->create(['institution' => 'Applicant Only Coop', 'sort_order' => 3]);
        $coMakerReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id]);
        $coMakerReport->loanRecords()->create(['institution' => 'Co-Maker Only Bank', 'original_amount' => '15000', 'sort_order' => 1]);
        $before = [$applicantReport->loanRecords()->get()->toArray(), $coMakerReport->loanRecords()->get()->toArray()];

        $applicant = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());
        $coMakerPage = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent());

        foreach ([[$applicant, 3], [$coMakerPage, 1]] as [$section, $rowCount]) {
            // Desktop / print table intact.
            $this->assertStringContainsString('cibi-loan-entry-table', $section);
            foreach (self::HEADERS as $header) {
                $this->assertStringContainsString('<th scope="col">'.$header.'</th>', $section);
            }

            // Every rendered loan row labels every one of its value cells with the column header,
            // so the phone card never relies on column position.
            $rows = $this->loanRows($section);
            $this->assertCount($rowCount, $rows);
            foreach ($rows as $row) {
                foreach (self::HEADERS as $header) {
                    $this->assertStringContainsString('data-label="'.$header.'"', $row);
                }
            }

            // The empty-state text is still part of the same table.
            $this->assertStringContainsString('No bank or cooperative added yet.', $section);
        }

        // Real values for the right person only.
        $this->assertStringContainsString($longName, $applicant);
        $this->assertStringContainsString('value="20000"', $applicant);
        $this->assertStringContainsString('Always paid on time without any missed amortization', $applicant);
        $this->assertStringContainsString('Applicant Only Coop', $applicant);
        $this->assertStringNotContainsString('Co-Maker Only Bank', $applicant);
        $this->assertStringContainsString('Co-Maker Only Bank', $coMakerPage);
        $this->assertStringNotContainsString('Applicant Only Coop', $coMakerPage);
        $this->assertStringNotContainsString($longName, $coMakerPage);

        // Opening either report changes no data.
        $this->assertSame($before, [$applicantReport->loanRecords()->get()->toArray(), $coMakerReport->loanRecords()->get()->toArray()]);
        $this->assertSame(4, $applicantReport->loanRecords()->count() + $coMakerReport->loanRecords()->count());
    }

    public function test_a_report_without_loans_keeps_its_readable_empty_state(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        $this->assertCount(0, $this->loanRows($section));
        $this->assertMatchesRegularExpression('/<tr class="cibi-loan-empty-state-row" data-loan-empty-state\s*><td colspan="8">No bank or cooperative added yet\.<\/td><\/tr>/', $section);
    }

    public function test_phone_card_styles_are_screen_only_readable_and_wrap_long_text(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $start = strpos($css, '@media screen and (max-width: 639px) {');
        $this->assertNotFalse($start, 'Phone-only (screen) rules exist, so print keeps the table.');
        $block = substr($css, $start, strpos($css, "\n}\n", $start) - $start);

        // One card per loan row, each value under its visible column label.
        $this->assertStringContainsString('.cibi-loan-entry-table tr[data-loan-result] { display: grid;', $block);
        $this->assertStringContainsString('.cibi-loan-entry-table td[data-label]::before { content: attr(data-label);', $block);
        $this->assertStringContainsString('.cibi-loan-entry-table thead { position: absolute; width: 1px; height: 1px;', $block);
        // The 62rem desktop minimum no longer forces sideways scrolling on phones.
        $this->assertStringContainsString('.cibi-loan-entry-table tbody { display: block; width: 100%; min-width: 0;', $block);
        // Readable sizes (never shrunk), wrapping for long text, wrapping paired controls.
        $this->assertStringContainsString('font-size: 1rem;', $block);
        $this->assertStringNotContainsString('font-size: .6', $block);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $block);
        $this->assertStringContainsString('.cibi-loan-entry-table .cibi-loan-paired-controls { flex-wrap: wrap;', $block);
        // A later loan of the same institution says so instead of showing an empty field.
        $this->assertStringContainsString("content: 'Another loan under the Bank / Coop above';", $block);

        // Desktop table rules are unchanged, and nothing here targets print.
        $this->assertStringContainsString('.cibi-loan-entry-table { width: 100%; min-width: 62rem; table-layout: fixed; }', $css);
        $this->assertStringNotContainsString('@media print', $block);
    }

    private function loanSection(string $html): string
    {
        $start = strpos($html, 'id="loan_records-section"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    /** @return list<string> rendered (non-template) loan rows */
    private function loanRows(string $section): array
    {
        $table = substr($section, 0, strpos($section, '<template') ?: strlen($section));
        preg_match_all('/<tr data-repeater-row data-loan-result.*?<\/tr>/s', $table, $matches);

        return $matches[0];
    }
}
