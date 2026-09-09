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

        // A row is a real institution, so none are invented up front.
        $this->assertSame(0, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(0, substr_count($section, 'data-loan-institution-input'));
        $this->assertStringContainsString('No bank or cooperative added yet.', $section);
        // …and exactly one action creates one, rendered below the table rather than in the header.
        $this->assertStringNotContainsString('data-repeater-add', $section);
        $this->assertSame(1, substr_count($content, 'cibi-loan-group-add'));

        // The Bank / Coop field names the domain it actually serves, and says so in the same
        // breath as the branch it also stores (cibi_loan_records has no separate branch column).
        $this->assertStringContainsString('aria-label="Bank / Coop / Branch" title="Bank / Coop / Branch"', $content);
        $this->assertStringContainsString('placeholder="Enter bank/coop name"', $content);
        $this->assertStringNotContainsString('placeholder="Institution name"', $content);
    }

    public function test_a_saved_bank_coop_renders_as_exactly_one_group_with_no_blank_companions(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        $this->assertSame(1, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(1, substr_count($section, '<th scope="col">Bank / Coop / Branch</th>'));
        $this->assertSame(1, substr_count($section, 'data-loan-institution-input'));
        $this->assertStringContainsString('value="ABC Cooperative"', $section);
        // The result row must fill exactly the 8 declared columns — a miscounted cell silently
        // shears the whole grouped grid out of alignment.
        $this->assertSame(8, substr_count($section, '<th scope="col"'));
        $resultRow = substr($section, strpos($section, '<tr data-repeater-row'));
        $this->assertSame(8, substr_count(substr($resultRow, 0, strpos($resultRow, '</tr>')), '<td'));
    }

    public function test_a_loan_result_stays_one_compact_line_with_its_paired_controls_side_by_side(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());
        $resultRow = substr($section, strpos($section, '<tr data-repeater-row'));
        $resultRow = substr($resultRow, 0, strpos($resultRow, '</tr>'));

        // Granted/Maturity and Cycle/Security each keep BOTH controls inside one cell, laid out
        // in a row — stacked labels would double the height of every result row in the table.
        $this->assertSame(2, substr_count($resultRow, 'cibi-loan-paired-controls'));
        $this->assertSame(1, substr_count($resultRow, 'cibi-loan-institution-controls'));
        $this->assertStringContainsString('cibi-loan-date-controls cibi-loan-paired-controls', $resultRow);
        $this->assertStringContainsString('cibi-loan-meta-controls cibi-loan-paired-controls', $resultRow);
        $this->assertStringNotContainsString('cibi-loan-micro-label', $resultRow);
        $this->assertStringNotContainsString('cibi-loan-paired-field', $resultRow);

        // Both dates live in the same wrapper, as do cycle and security.
        $dates = substr($resultRow, strpos($resultRow, 'cibi-loan-date-controls'));
        $this->assertStringContainsString('[granted_date]', substr($dates, 0, strpos($dates, '</div>')));
        $this->assertStringContainsString('[maturity_date]', substr($dates, 0, strpos($dates, '</div>')));
        $meta = substr($resultRow, strpos($resultRow, 'cibi-loan-meta-controls'));
        $this->assertStringContainsString('[cycle_label]', substr($meta, 0, strpos($meta, '</div>')));
        $this->assertStringContainsString('[security_type]', substr($meta, 0, strpos($meta, '</div>')));
    }

    public function test_the_group_and_row_remove_actions_are_icon_only_but_still_individually_named(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
            ['institution' => 'Zero Result Bank', 'combined_findings' => 'No existing loan record found during verification.'],
        ];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        // Both actions are icon-only — no visible wording widens the column or grows the row —
        // but each still names itself through its own title and aria-label.
        $this->assertStringContainsString('title="Remove Bank / Coop" aria-label="Remove Bank / Coop" data-loan-group-remove', $section);
        $this->assertStringContainsString('title="Remove Loan" aria-label="Remove Loan" data-loan-result-remove', $section);
        $this->assertStringNotContainsString('Remove Bank / Coop</button>', $section);
        $this->assertStringNotContainsString('Remove Loan</button>', $section);

        // …and they carry DIFFERENT icons, so removing one loan can't be mistaken for removing the
        // whole institution: an X for the group, a trash can for the single loan row.
        $closePath = 'm6 6 12 12M18 6 6 18';
        $trashPath = 'M4.5 7h15M9 3.5h6L16 7H8l1-3.5Z';
        $groupButton = $this->buttonMarkup($section, 'data-loan-group-remove');
        $loanButton = $this->buttonMarkup($section, 'data-loan-result-remove');
        $this->assertStringContainsString($closePath, $groupButton);
        $this->assertStringNotContainsString($trashPath, $groupButton);
        $this->assertStringContainsString($trashPath, $loanButton);
        $this->assertStringNotContainsString($closePath, $loanButton);

        // Both controls are rendered per row, but "Remove Bank / Coop" rides inside the
        // institution wrapper — which only the FIRST row of each run shows. Two runs here
        // (ABC Cooperative x2 rows, Zero Result Bank x1), so exactly one wrapper is hidden and
        // exactly two "Remove Bank / Coop" buttons are actually visible.
        $this->assertSame(3, substr_count($section, 'data-loan-group-remove'));
        $this->assertSame(3, substr_count($section, 'data-loan-result-remove'));
        $this->assertSame(1, preg_match_all('/data-loan-institution-controls\s+hidden/', $section));

        // …but the zero-result row's "Remove Loan" is hidden, because it has no loan to remove.
        $rows = explode('<tr data-repeater-row', $section);
        $zeroResultRow = collect($rows)->first(fn (string $row): bool => str_contains($row, 'Zero Result Bank'));
        $this->assertNotNull($zeroResultRow);
        $this->assertSame(1, preg_match_all('/data-loan-result-remove\s+hidden/', $zeroResultRow));
        $this->assertStringContainsString('aria-label="Remove Bank / Coop"', $zeroResultRow);
        // Its findings stay editable regardless.
        $this->assertStringContainsString('No existing loan record found during verification.</textarea>', $zeroResultRow);
    }

    public function test_the_remove_confirmation_dialog_states_exactly_what_is_being_removed(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        // The one shared dialog carries the hooks app.js rewrites per action, plus both confirm
        // icons (trash for a loan, circled X for a whole Bank / Coop) ready to be toggled.
        $this->assertStringContainsString('data-repeater-remove-note', $page);
        $this->assertStringContainsString('data-repeater-remove-confirm-label', $page);
        $this->assertStringContainsString('data-repeater-remove-confirm-icon="trash"', $page);
        $this->assertStringContainsString('data-repeater-remove-confirm-icon="close"', $page);
        // Cancel is icon + text, never icon-only.
        $this->assertMatchesRegularExpression('/data-modal-close><svg.+?<\/svg>\s*Cancel<\/button>/s', $page);

        // The exact per-action wording lives in the bundled handler — pin every required string so
        // none of it can drift. (Swapping itself is browser behaviour and is NOT asserted here.)
        $js = file_get_contents(resource_path('js/app.js'));
        foreach ([
            "title: 'Remove Bank / Coop?'",
            "message: 'This entry contains information. Are you sure you want to remove this Bank / Coop and its loan details?'",
            "confirmLabel: 'Remove Bank / Coop'",
            "confirmIcon: 'close'",
            "title: 'Remove Loan?'",
            "message: 'This loan contains information. Are you sure you want to remove it?'",
            "confirmLabel: 'Remove Loan'",
            "note: 'This change will be applied when you save the CI / BI report.'",
        ] as $required) {
            $this->assertStringContainsString($required, $js);
        }
        // Both loan actions set the dialog before opening it, so wording can never go stale.
        $this->assertSame(3, substr_count($js, 'setRepeaterRemoveDialogContent('));
    }

    public function test_both_loan_table_remove_actions_reuse_the_sections_existing_destructive_button(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [$this->loanRow('ABC Cooperative', 'Salary Loan', '100,000')];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        // Both actions use the SAME subtle-red destructive button III and V already use — no
        // one-off class, so no second red is introduced for this table.
        $this->assertStringContainsString('class="cibi-remove-entry-button" title="Remove Bank / Coop"', $section);
        $this->assertStringContainsString('class="cibi-remove-entry-button" title="Remove Loan"', $section);
        $this->assertStringNotContainsString('cibi-loan-remove-button', $section);
    }

    public function test_the_cibi_dialog_closes_after_every_successful_save_and_never_fakes_the_update_label(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        // Both callers mount the same shared dialog — there is no per-caller close flag any more.
        $folderPage = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $reportsPage = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-cibi-report-dialog', $folderPage);
        $this->assertStringContainsString('data-cibi-report-dialog', $reportsPage);
        $this->assertStringNotContainsString('data-cibi-report-close-on-save', $folderPage);
        $this->assertStringNotContainsString('data-cibi-report-close-on-save', $reportsPage);
        // The Reports workspace keeps its own opt-out of the post-save navigation.
        $this->assertStringContainsString('data-cibi-report-stay', $reportsPage);
        $this->assertStringNotContainsString('data-cibi-report-stay', $folderPage);

        $js = file_get_contents(resource_path('js/app.js'));
        // The parent page is refreshed from the authoritative payload, the toast is shown, and
        // only then does the dialog close — for every caller, with no second GET.
        $handler = substr($js, strpos($js, "event.data?.type !== 'brbi:cibi-saved'"));
        $handler = substr($handler, 0, strpos($handler, '});'));
        $this->assertLessThan(strpos($handler, 'showToast('), strpos($handler, 'cibiModuleHtml'));
        $this->assertLessThan(strpos($handler, 'dialog.close();'), strpos($handler, 'showToast('));
        $this->assertStringNotContainsString('data-cibi-report-close-on-save', $handler);
        $this->assertStringContainsString('refreshReportsWorkspace();', $handler);
        // The Save→Update wording is never faked client-side: the handler neither reads the
        // server's submit_label nor touches the button's label element at all.
        $this->assertStringNotContainsString('submit_label', $js);
        $this->assertStringNotContainsString('data-cibi-submit-text', $js);
    }

    public function test_the_cibi_dialog_reloads_on_every_open_so_a_saved_report_is_never_stale(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        // The CI/BI trigger URL is the SAME edit route before and after the report exists, so a
        // src-match check would skip the reload and reopen the cached "Save CIBI Report" document.
        // Reloading unconditionally is what makes the FIRST reopen show the persisted state.
        $this->assertStringContainsString(
            "const alwaysReload = dialog.matches('[data-cibi-report-dialog]') || dialog.matches('[data-business-report-dialog]') || dialog.matches('[data-check-report-dialog]');",
            $js,
        );
        // …and it re-navigates the IFRAME in place (location.replace, so no history entry and no
        // full-page reload of the Folder Contents behind it).
        $this->assertStringContainsString('cibiFrame.contentWindow.location.replace(requestedUrl);', $js);
    }

    public function test_the_folder_contents_cibi_action_points_at_the_same_edit_route_before_and_after_saving(): void
    {
        [$ci, $folder] = $this->folder();

        $before = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        preg_match('/data-cibi-report-url="([^"]+)"/', $before, $beforeUrl);
        $this->assertNotEmpty($beforeUrl, 'Folder Contents must expose a CI/BI open URL.');

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload())->assertRedirect();

        $after = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        preg_match('/data-cibi-report-url="([^"]+)"/', $after, $afterUrl);

        // Identical URL — which is exactly why the reload can never be skipped on a src match.
        $this->assertSame($beforeUrl[1], $afterUrl[1]);
        // Opening that URL now serves the persisted report in Update state on the FIRST request.
        $reopened = $this->actingAs($ci)->get(html_entity_decode($afterUrl[1]))->assertOk()->getContent();
        $this->assertStringContainsString('<span data-cibi-submit-text>Update CIBI Report</span>', $reopened);
        $this->assertStringNotContainsString('Save CIBI Report', $reopened);
    }

    public function test_the_present_address_length_of_stay_keeps_its_label_and_gains_an_example_placeholder(): void
    {
        [$ci, $folder] = $this->folder();

        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Length of Stay', $page);
        $this->assertStringNotContainsString('Duration of Stay', $page);
        $this->assertStringContainsString('placeholder="e.g., 12 years"', $page);

        // The example is a placeholder only — it is never persisted, and a real value reloads.
        $payload = $this->payload();
        $payload['personal_snapshot']['length_of_stay_months'] = '2 years 6 months';
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $saved = CibiReport::whereBelongsTo($folder)->sole()->personal_snapshot;
        $this->assertSame('2 years 6 months', $saved['length_of_stay_months']);
        $this->assertStringNotContainsString('e.g.', (string) $saved['length_of_stay_months']);
        $reopened = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('value="2 years 6 months"', $reopened);
    }

    public function test_a_new_cibi_offers_save_while_a_saved_one_reopens_in_update_state_with_its_data(): void
    {
        [$ci, $folder] = $this->folder();

        // A brand-new form is a Create: the server renders Save, not Update.
        $newPage = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('<span data-cibi-submit-text>Save CIBI Report</span>', $newPage);
        $this->assertStringNotContainsString('Update CIBI Report', $newPage);
        $this->assertStringContainsString('data-cibi-submit-mode="save"', $newPage);

        $payload = $this->payload();
        $payload['loan_records'] = [
            $this->loanRow('ABC Cooperative', 'Salary Loan', '100,000'),
            $this->loanRow('ABC Cooperative', 'Emergency Loan', '20,000'),
        ];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        // Reopening loads the persisted report — not a blank Create — and the Update wording comes
        // from that saved state on the server, already rendered before any JavaScript runs.
        $reopened = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('<span data-cibi-submit-text>Update CIBI Report</span>', $reopened);
        $this->assertStringContainsString('data-cibi-submit-mode="update"', $reopened);
        $this->assertStringNotContainsString('Save CIBI Report', $reopened);

        // …with the saved values intact, including both loan rows under the one Bank / Coop.
        $section = $this->loanSection($reopened);
        $this->assertSame(2, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(2, substr_count($section, 'value="ABC Cooperative"'));
        $this->assertStringContainsString('value="BLU TIN-AO"', $reopened);
    }

    public function test_a_saved_co_maker_cibi_reopens_in_update_state_scoped_to_that_person(): void
    {
        [$ci, $folder] = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'REOPEN CO MAKER']);

        $payload = $this->payload();
        $payload['co_maker_id'] = $coMaker->id;
        $payload['loan_records'] = [$this->loanRow('CO MAKER COOP', 'Salary Loan', '10,000')];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $coMakerUrl = route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id;
        $reopened = $this->actingAs($ci)->get($coMakerUrl)->assertOk()->getContent();
        $this->assertStringContainsString('<span data-cibi-submit-text>Update CIBI Report</span>', $reopened);
        $this->assertStringContainsString('value="CO MAKER COOP"', $reopened);

        // The Applicant still has no report of its own, so its form is still a Create.
        $applicantPage = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('<span data-cibi-submit-text>Save CIBI Report</span>', $applicantPage);
        $this->assertStringNotContainsString('CO MAKER COOP', $applicantPage);
    }

    public function test_the_cibi_header_fields_use_the_standard_bordered_control_not_a_bare_underline(): void
    {
        [$ci, $folder] = $this->folder();

        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        // All six top-information controls live in the header grid the new styling targets, and
        // their names / required flags / read-only nature are untouched by the restyle.
        $this->assertStringContainsString('cibi-excel-metadata', $page);
        $this->assertStringContainsString('cibi-readonly-field', $page);
        foreach (['branch_name', 'start_date', 'account_officer_name', 'submitted_date', 'amount_applied'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $page);
        }

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.cibi-encoding-page .cibi-excel-metadata .ui-control,', $css);
        $this->assertStringContainsString('.cibi-encoding-page .cibi-excel-metadata .cibi-readonly-field { min-height: 2rem; border: 1px solid var(--color-ui-border);', $css);
        // The ledger underline is still the body's style — only the header opts out of it.
        $this->assertStringContainsString('.cibi-encoding-page .ui-control:hover { border-bottom-color: #9ca3af; }', $css);
    }

    public function test_a_zero_result_group_shows_em_dashes_for_loan_columns_and_keeps_findings_editable(): void
    {
        [$ci, $folder] = $this->folder();
        $payload = $this->payload();
        $payload['loan_records'] = [['institution' => 'ABC Cooperative', 'combined_findings' => 'No existing loan record found during verification.']];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());

        $this->assertStringContainsString('data-loan-empty', $section);
        $this->assertStringContainsString('value="ABC Cooperative"', $section);
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

        // Three loan results under ONE institution render as three consecutive flat rows — never
        // as three separate Bank/Coop entries.
        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $section = $this->loanSection($content);
        $this->assertSame(3, substr_count($section, '<tr data-repeater-row'));
        $this->assertSame(3, substr_count($section, 'data-loan-institution-input'));
        // Every row still POSTS the institution, so no row can ever save it blank…
        $this->assertSame(3, substr_count($section, 'value="ABC Cooperative"'));
        // …but only the FIRST row SHOWS it; the other two hide the control, exactly like the
        // official Excel sheet's blank continuation cells.
        $this->assertSame(2, preg_match_all('/data-loan-institution-controls\s+hidden/', $section));
        $this->assertStringContainsString('data-loan-add-result', $section);
        $this->assertStringContainsString('data-loan-result-remove', $section);
        // The add action is named tersely enough to read at a glance in a compact row.
        $this->assertStringContainsString('title="Add Another Loan" aria-label="Add Another Loan" data-loan-add-result', $section);
        $this->assertStringNotContainsString('Add another loan result', $section);
        $this->assertStringNotContainsString('Add Another Loan Result', $section);
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

        // …and it comes back as a single zero-result row that still carries the institution.
        $section = $this->loanSection($this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent());
        $this->assertSame(1, substr_count($section, '<tr data-repeater-row'));
        $this->assertStringContainsString('data-loan-empty', $section);
        $this->assertStringContainsString('value="ABC Cooperative"', $section);
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

    private function buttonMarkup(string $section, string $hook): string
    {
        $start = strrpos(substr($section, 0, strpos($section, $hook)), '<button');
        $this->assertNotFalse($start, "No button carries: $hook");
        $end = strpos($section, '</button>', $start);

        return substr($section, $start, $end - $start);
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
