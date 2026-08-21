<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class BusinessBatchExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_manage_page_renders_batch_selection_controls_alongside_existing_row_actions(): void
    {
        [$ci, $folder, $truck] = $this->createSource('leasing_truck_equipment');
        [, , $agri] = $this->createSource('leasing_agricultural', $ci, $folder);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('data-business-batch-panel', false)
            ->assertSee('data-business-select-all', false)
            ->assertSee('data-business-print-selected', false)
            ->assertSee('data-business-batch-pdf-submit', false)
            ->assertSee('data-business-batch-excel-submit', false)
            ->assertSee('data-business-selected-count', false)
            ->assertSee('Print Summary')
            // Existing per-row actions must still be present for every saved business.
            ->assertSee("business-{$truck->id}-export-pdf-form", false)
            ->assertSee("business-{$truck->id}-export-excel-form", false)
            ->assertSee("business-{$agri->id}-export-pdf-form", false)
            ->assertSee("delete-business-{$truck->id}", false)
            ->assertSee("delete-business-{$agri->id}", false)
            ->assertSee(route('client-folders.income-sources.edit', [$folder, $truck]), false)
            ->assertSee(route('client-folders.income-sources.batch-print', $folder), false)
            ->assertSee(route('client-folders.income-sources.batch-export-pdf', $folder), false)
            ->assertSee(route('client-folders.income-sources.batch-export-excel', $folder), false);
    }

    public function test_manage_page_with_no_saved_businesses_shows_the_empty_state_and_no_batch_panel(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('No businesses saved yet')
            ->assertDontSee('data-business-batch-panel', false)
            ->assertDontSee('Print Summary');
    }

    public function test_batch_print_combines_both_selected_reports_in_submitted_order_without_a_forced_page_break(): void
    {
        [$ci, $folder, $truck] = $this->createSource('leasing_truck_equipment');
        [, , $agri] = $this->createSource('leasing_agricultural', $ci, $folder);

        $html = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-print', $folder), ['income_source_ids' => [$truck->id, $agri->id]])
            ->assertOk()
            ->getContent();

        // Both reports rendered, each still using its own real template partial — not a
        // redesigned/generic combined layout.
        $this->assertStringContainsString('LEASING OPERATIONS (TRUCK OR EQUIPMENT)', strtoupper($html));
        $this->assertStringContainsString('AGRICULTURAL REAL ESTATE', strtoupper($html));
        // One continuous outer report border for the whole batch (not one per business), and the
        // shared CI/client header appears exactly once, inside it, above the first business.
        $this->assertSame(1, substr_count($html, 'class="business-official-sheet"'));
        $this->assertSame(1, substr_count(strtoupper($html), 'CREDIT INVESTIGATION REPORT'));
        $this->assertSame(1, substr_count(strtoupper($html), 'CI-IN CHARGE'));
        $this->assertSame(2, substr_count($html, 'class="business-batch-item"'));
        // No forced page-break between combined items (that would defeat "share one page when short").
        $this->assertStringNotContainsString('page-break-before: always', $html);
        $this->assertStringNotContainsString('.business-batch-item', substr($html, strpos($html, '</head>')));
        // The shared-border rules that make business 2's own top border sit flush against
        // business 1's bottom border (no gap, no doubled line) — confirmed visually by
        // screenshotting the actual rendered seam at gap = 0px during development.
        $this->assertStringContainsString('.business-batch-item:not(:first-child) { margin-top: 0; }', $html);
        $this->assertStringContainsString('.business-batch-continuation-first { margin-top: 0 !important; }', $html);
        $this->assertStringContainsString('.business-batch-continuation-first tr:first-child th,', $html);
        // Business 2's own first table actually carries the class (proves the Blade-side flag
        // reaches the template partial, not just that the CSS rule text is present).
        $this->assertStringContainsString('business-batch-continuation-first"><colgroup>', $html);
        // The override must be declared AFTER business-styles.blade.php's include: with equal
        // (single-class) specificity and !important on both sides, CSS falls back to source
        // order, so this only works if it comes later in the document than the !important rule
        // it overrides.
        $this->assertGreaterThan(
            strpos($html, '.business-form-table{margin-top:.03in!important}'),
            strpos($html, '.business-batch-continuation-first { margin-top: 0 !important; }'),
        );

        // Order: the truck report's own markup must appear before the agricultural one.
        $truckPos = strpos($html, 'LEASING OPERATIONS (TRUCK OR EQUIPMENT)') ?: strpos(strtoupper($html), 'LEASING OPERATIONS (TRUCK OR EQUIPMENT)');
        $agriPos = strpos(strtoupper($html), 'AGRICULTURAL REAL ESTATE');
        $this->assertLessThan($agriPos, $truckPos);

        // Submitting the reverse order flips which report renders first.
        $reversed = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-print', $folder), ['income_source_ids' => [$agri->id, $truck->id]])
            ->assertOk()
            ->getContent();
        $truckPos2 = strpos(strtoupper($reversed), 'LEASING OPERATIONS (TRUCK OR EQUIPMENT)');
        $agriPos2 = strpos(strtoupper($reversed), 'AGRICULTURAL REAL ESTATE');
        $this->assertLessThan($truckPos2, $agriPos2);
    }

    public function test_batch_print_rejects_a_business_that_does_not_belong_to_the_folder(): void
    {
        [$ci, $folder, $truck] = $this->createSource('leasing_truck_equipment');
        [, , $foreignSource] = $this->createSource('leasing_truck_equipment');

        $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-print', $folder), ['income_source_ids' => [$truck->id, $foreignSource->id]])
            ->assertOk()
            ->assertSeeText('LEASING OPERATIONS (TRUCK OR EQUIPMENT)', false);

        // Only the folder's own source resolves — the foreign id is dropped, not substituted.
        $this->assertSame(
            1,
            substr_count(
                $this->actingAs($ci)->post(route('client-folders.income-sources.batch-print', $folder), ['income_source_ids' => [$truck->id, $foreignSource->id]])->getContent(),
                'class="business-batch-item"',
            ),
        );
    }

    public function test_batch_export_pdf_downloads_a_combined_pdf_for_both_selected_reports(): void
    {
        [$ci, $folder, $truck] = $this->createSource('leasing_truck_equipment');
        [, , $agri] = $this->createSource('leasing_agricultural', $ci, $folder);

        $response = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-export-pdf', $folder), ['income_source_ids' => [$truck->id, $agri->id]])
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        $bytes = $response->streamedContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(2000, strlen($bytes));

        // The PRIORITY requirement: two genuinely short reports must land on the SAME physical
        // page, not one-per-page. A PDF's page tree root object always declares "/Count N" (its
        // total leaf /Page count) — reading it straight from the raw bytes is the same fact a PDF
        // viewer's page counter would show, without needing a PDF-parsing library in this project.
        $this->assertMatchesRegularExpression('/\/Type\s*\/Pages.{0,80}\/Count\s+1(?!\d)/s', $bytes, 'Two short business reports should be combined onto a single PDF page.');

        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'income_source.batch_pdf_downloaded',
        ]);
    }

    public function test_batch_export_excel_produces_one_workbook_with_one_worksheet_containing_both_businesses_in_order(): void
    {
        [$ci, $folder, $truck] = $this->createSource('leasing_truck_equipment');
        [, , $agri] = $this->createSource('leasing_agricultural', $ci, $folder);
        // report_remarks lands on a known, template-specific "OTHER REMARKS" cell for every
        // SECTIONS-mapped template (see BusinessExcelExporter's many setRemarks() calls) — a
        // reliable, test-controlled marker for finding each business's own content inside the
        // single combined sheet, independent of any reference-workbook cell text this test can't
        // otherwise see.
        $truck->businessReport->update(['report_remarks' => 'TRUCK REMARKS MARKER']);
        $agri->businessReport->update(['report_remarks' => 'AGRI REMARKS MARKER']);

        $response = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-export-excel', $folder), ['income_source_ids' => [$truck->id, $agri->id]])
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $bytes = $response->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'brbi-batch-test-');
        file_put_contents($path, $bytes);
        try {
            $book = IOFactory::load($path);
            // PRIORITY requirement: one workbook, one worksheet only — never a tab per business.
            $this->assertSame(1, $book->getSheetCount());

            $sheet = $book->getSheet(0);
            $this->assertGreaterThan(0, count($sheet->getDrawingCollection()), 'The BINHI logo drawing (from the first business only) should still be present.');
            $this->assertGreaterThan(0, count($sheet->getMergeCells()));

            $findRow = function (string $marker) use ($sheet): int {
                foreach ($sheet->getRowIterator() as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        if ($cell->getValue() === $marker) {
                            return $row->getRowIndex();
                        }
                    }
                }
                $this->fail("Marker \"$marker\" not found in the combined sheet.");
            };

            $truckRow = $findRow('TRUCK REMARKS MARKER');
            $agriRow = $findRow('AGRI REMARKS MARKER');
            // Order preserved: truck submitted first, so its content — and so its remarks row —
            // must come before agri's, appended directly below it in the same sheet.
            $this->assertLessThan($agriRow, $truckRow);

            // No cross-business contamination: the two templates trim to different content
            // lengths (leasing_truck_equipment's own section is longer than leasing_agricultural's),
            // so both remarks markers actually being found, in the right order, on one sheet
            // confirms neither business's section overwrote or replaced the other's.
            $this->assertGreaterThan(30, $sheet->getHighestRow(), 'Combined sheet should contain far more than one business report worth of rows.');

            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'income_source.batch_excel_downloaded',
        ]);
    }

    public function test_batch_selection_never_mixes_applicant_and_co_maker_businesses(): void
    {
        [$ci, $folder, $applicantSource] = $this->createSource('leasing_truck_equipment');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_truck_equipment')->firstOrFail();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id,
            'source_name' => 'Co-Maker Business',
            'business_name' => 'Co-Maker Trucking',
            'main_business_address' => 'Co-Maker Street',
            'year_established' => 2018,
            'co_maker_id' => $coMaker->id,
        ])->assertRedirect();
        $coMakerSource = $folder->incomeSources()->where('co_maker_id', $coMaker->id)->firstOrFail();

        // Viewing as the Applicant (no co_maker_id): only the applicant's own source resolves,
        // even though the co-maker's id was also submitted.
        $applicantHtml = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-print', $folder), [
                'income_source_ids' => [$applicantSource->id, $coMakerSource->id],
            ])
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($applicantHtml, 'class="business-batch-item"'));

        // Viewing as the Co-Maker: only that co-maker's own source resolves.
        $coMakerHtml = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.batch-print', $folder), [
                'co_maker_id' => $coMaker->id,
                'income_source_ids' => [$applicantSource->id, $coMakerSource->id],
            ])
            ->assertOk()
            ->getContent();
        $this->assertSame(1, substr_count($coMakerHtml, 'class="business-batch-item"'));
    }

    public function test_another_ci_cannot_batch_export_a_folder_they_are_not_assigned_to(): void
    {
        [$ci, $folder, $truck] = $this->createSource('leasing_truck_equipment');
        [, , $agri] = $this->createSource('leasing_agricultural', $ci, $folder);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(route('client-folders.income-sources.batch-print', $folder), ['income_source_ids' => [$truck->id, $agri->id]])
            ->assertForbidden();
    }

    private function createSource(string $templateType, ?User $ci = null, ?ClientFolder $folder = null): array
    {
        $ci ??= User::factory()->create();
        $folder ??= ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', $templateType)->firstOrFail();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id,
            'source_name' => 'Income Source',
            'business_name' => 'Sample Business',
            'main_business_address' => 'Main Street',
            'year_established' => 2015,
        ]);

        return [$ci, $folder, $folder->incomeSources()->latest('id')->firstOrFail()];
    }
}
