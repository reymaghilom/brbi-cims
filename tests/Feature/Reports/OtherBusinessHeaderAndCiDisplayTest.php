<?php

namespace Tests\Feature\Reports;

use App\Enums\OfficialReportType;
use App\Models\BusinessReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\CustomBusinessCategory;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\Reports\BusinessExcelExporter;
use App\Services\Reports\OfficialReportDataBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Tests\TestCase;

/**
 * OTHER BUSINESS / SOURCE OF INCOME header parity, and the encoding form's compact CI display.
 *
 * The encoding form shortens the third and later CIs to their first given name so a long companion
 * list stays readable. That is presentation only: the stored users are untouched and every official
 * output — web preview, PDF and Excel — keeps printing the full names, from the same shared
 * OfficialReportDataBuilder data every other Business Template already uses.
 */
class OtherBusinessHeaderAndCiDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_HEADING = 'RANK ALL INCOME SOURCES THAT CLIENT DECLARED BASED ON CONRTIBUTION (1 BEING THE HIGHEST)';

    private const NEW_HEADING = 'OTHER BUSINESS/SOURCE OF INCOME';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** TESTS 4, 5, 6, 7 — the compact rule itself, at every position. */
    public function test_the_compact_display_rule_shortens_only_the_third_ci_onward(): void
    {
        // Positions: 0 = primary CI, 1 = first companion, 2+ = every companion after that.
        $this->assertSame('Rey C. Maghilom', CiParticipantService::compactDisplayName('Rey C. Maghilom', 0));
        $this->assertSame('Anthony B. Yong', CiParticipantService::compactDisplayName('Anthony B. Yong', 1));
        $this->assertSame('Reasan', CiParticipantService::compactDisplayName('Reasan Mark Gura', 2));
        $this->assertSame('Juan', CiParticipantService::compactDisplayName('Juan Carlo Santos', 3));

        // Degenerate inputs stay harmless.
        $this->assertSame('', CiParticipantService::compactDisplayName(null, 2));
        $this->assertSame('Cher', CiParticipantService::compactDisplayName('  Cher  ', 5));
    }

    /** TESTS 4, 5, 6, 8 — rendered in the encoding form, with full names still in the markup. */
    public function test_the_encoding_form_shortens_the_third_ci_but_stores_full_names(): void
    {
        [$ci, $folder, $source] = $this->business();
        $second = User::factory()->create(['full_name' => 'Anthony B. Yong']);
        $third = User::factory()->create(['full_name' => 'Reasan Mark Gura']);
        $this->app->make(CiParticipantService::class)->syncCompanions($source, [$second->id, $third->id]);

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()->getContent();

        // Scoped to the header's own participant list: the "+ Add CI" picker below legitimately
        // lists every CI by full name, and that list is not what this rule governs.
        $start = strpos($html, 'data-companion-participant-list');
        $this->assertNotFalse($start);
        $list = substr($html, $start, strpos($html, 'data-companion-hidden-inputs', $start) - $start);

        // First two CIs in full, the third by first given name only.
        $this->assertStringContainsString('>Anthony B. Yong</span>', $list);
        $this->assertStringContainsString('>Reasan</span>', $list);
        $this->assertStringNotContainsString('>Reasan Mark Gura</span>', $list);
        // The primary CI keeps their full name, in their own separate markup above the list.
        $this->assertStringContainsString('data-ci-primary-name>'.$ci->full_name.'</span>', $html);

        // TEST 8 — nothing shortened is persisted, and the full name is still carried in the markup
        // for the remove control and for anything reading the authoritative value.
        $this->assertStringContainsString('data-full-name="Reasan Mark Gura"', $list);
        $this->assertSame('Reasan Mark Gura', $third->fresh()->full_name);
        $this->assertSame(
            [$ci->full_name, 'Anthony B. Yong', 'Reasan Mark Gura'],
            explode(' / ', $this->app->make(CiParticipantService::class)->fullNames($source->fresh())),
        );
    }

    /** The browser-side re-render applies the identical rule. */
    public function test_the_javascript_render_uses_the_same_compact_rule(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('const compactDisplayName = (fullName, position)', $javascript);
        $this->assertStringContainsString("return position < 2 || name === '' ? name : name.split(' ')[0];", $javascript);
        // The full name is kept on the element and in the accessible name.
        $this->assertStringContainsString('name.dataset.fullName = fullName;', $javascript);
        $this->assertStringContainsString('name.textContent = displayName;', $javascript);
    }

    /** Web and PDF use the original four-field header and retain the current section heading. */
    public function test_the_web_and_pdf_outputs_use_only_the_official_four_field_header(): void
    {
        [$ci, $folder, $source] = $this->business();
        $second = User::factory()->create(['full_name' => 'Anthony B. Yong']);
        $third = User::factory()->create(['full_name' => 'Reasan Mark Gura']);
        $this->app->make(CiParticipantService::class)->syncCompanions($source, [$second->id, $third->id]);

        foreach ([false, true] as $pdfMode) {
            $html = $this->render($folder, $source->fresh(), $pdfMode);

            foreach (['NAME OF APPLICANT:', 'AMOUNT APPLIED:', 'BRANCH:', 'ACCOUNT OFFICER:'] as $label) {
                $this->assertStringContainsString($label, $html, $label.' must be in the output.');
            }
            foreach (['CI-IN CHARGE:', 'START DATE OF CI:', 'DATE SUBMITTED TO CA:'] as $label) {
                $this->assertStringNotContainsString($label, $html, $label.' must not be in the output.');
            }

            // Values remain sourced from the saved report and resolved active person.
            $this->assertStringContainsString('MAIN BRANCH', $html);
            $this->assertStringContainsString('JUAN DELA CRUZ', strtoupper($html));
            $this->assertMatchesRegularExpression('/☑\s*<\/span>\s*STL\/Lotto Outlet/', $html, 'The existing default selection remains checked.');
            $this->assertStringNotContainsString(self::OLD_HEADING, $html);
            $this->assertStringContainsString(self::NEW_HEADING, $html);
        }
    }

    public function test_web_and_pdf_resolve_mixed_multiple_and_inactive_custom_business_names(): void
    {
        [$ci, $folder, $source] = $this->business();
        $renamed = $this->customCategory($ci, 'Vulcanizing Shop');
        $inactive = $this->customCategory($ci, 'Computer Repair');
        $otherSourceOnly = $this->customCategory($ci, 'Must Not Leak From Another Report');
        $renamedKey = $renamed->optionKey();
        $inactiveKey = $inactive->optionKey();

        // Renames retain the key, while inactive historical categories remain resolvable.
        $renamed->update(['name' => 'Motorcycle & Vulcanizing Shop']);
        $inactive->update(['is_active' => false]);
        $source->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => [$renamedKey]]],
        ]);
        $otherSource = $this->incomeSource($folder, $ci);
        $otherSource->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => [$otherSourceOnly->optionKey()]]],
        ]);

        // Custom-only Web/PDF output is scoped to this exact IncomeSource.
        foreach ([false, true] as $pdfMode) {
            $customOnly = $this->render($folder, $source->fresh(), $pdfMode);
            $this->assertStringContainsString('☑</span> Motorcycle &amp; Vulcanizing Shop', $customOnly);
            $this->assertStringNotContainsString($renamedKey, $customOnly);
            $this->assertStringNotContainsString('Must Not Leak From Another Report', $customOnly);
        }

        $source->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => ['laundry_shop', $inactiveKey, $renamedKey]]],
        ]);

        foreach ([false, true] as $pdfMode) {
            $html = $this->render($folder, $source->fresh(), $pdfMode);

            $this->assertStringContainsString('☑</span> Laundry Shop', $html);
            $this->assertStringContainsString('☑</span> Computer Repair', $html);
            $this->assertStringContainsString('☑</span> Motorcycle &amp; Vulcanizing Shop', $html);
            $this->assertStringNotContainsString($inactiveKey, $html);
            $this->assertStringNotContainsString($renamedKey, $html);
            $this->assertLessThan(
                strpos($html, 'Motorcycle &amp; Vulcanizing Shop'),
                strpos($html, 'Computer Repair'),
                'Selected custom businesses keep their saved ordering.',
            );

            $catalogStart = strpos($html, '<table class="business-form-table business-grid-table business-other-income-grid">');
            $catalogEnd = strpos($html, '</table>', $catalogStart);
            $this->assertNotFalse($catalogStart);
            $this->assertNotFalse($catalogEnd);
            $catalog = substr($html, $catalogStart, $catalogEnd - $catalogStart);
            preg_match_all('/<td colspan="(?:7|6)">.*?<\/td>/s', $catalog, $columns);
            $this->assertCount(4, $columns[0]);

            $firstBusinessColumn = $columns[0][0];
            $thirdBusinessColumn = $columns[0][2];
            $this->assertMatchesRegularExpression(
                '/STL\/Lotto Outlet<\/span>\s*<span class="business-other-income-item">.*?Computer Repair<\/span>\s*<span class="business-other-income-item">.*?Motorcycle &amp; Vulcanizing Shop<\/span>/s',
                $firstBusinessColumn,
                'Selected custom businesses continue the first Business column directly after STL/Lotto Outlet.',
            );
            $this->assertStringNotContainsString('Computer Repair', $thirdBusinessColumn);
            $this->assertStringNotContainsString('Motorcycle &amp; Vulcanizing Shop', $thirdBusinessColumn);
            $this->assertStringNotContainsString('Custom Business:', $catalog);
        }

        $this->assertSame($renamedKey, $renamed->fresh()->optionKey());
    }

    public function test_excel_resolves_custom_only_and_mixed_custom_business_names(): void
    {
        [$ci, $folder, $source] = $this->business();
        $first = $this->customCategory($ci, 'Vulcanizing Shop');
        $second = $this->customCategory($ci, 'Computer Repair');
        $third = $this->customCategory($ci, 'Online Reselling');
        $otherSourceOnly = $this->customCategory($ci, 'Must Not Leak From Another Excel Report');
        $second->update(['is_active' => false]);
        $otherSource = $this->incomeSource($folder, $ci);
        $otherSource->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => [$otherSourceOnly->optionKey()]]],
        ]);

        // Custom-only output.
        $source->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => [$first->optionKey()]]],
        ]);
        $customOnly = $this->excelText($folder, $source->fresh());
        $this->assertStringContainsString('Vulcanizing Shop', $customOnly);
        $this->assertStringNotContainsString($first->optionKey(), $customOnly);

        // Mixed output with multiple custom categories, including inactive history.
        $source->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => ['laundry_shop', $first->optionKey(), $second->optionKey(), $third->optionKey()]]],
            'report_remarks' => 'Verified custom business remarks.',
        ]);
        $mixed = $this->excelText($folder, $source->fresh());
        foreach (['Laundry Shop', 'Vulcanizing Shop', 'Computer Repair', 'Online Reselling'] as $label) {
            $this->assertStringContainsString($label, $mixed);
        }
        $this->assertStringNotContainsString($first->optionKey(), $mixed);
        $this->assertStringNotContainsString($second->optionKey(), $mixed);
        $this->assertStringNotContainsString('Must Not Leak From Another Excel Report', $mixed);
        $this->assertStringNotContainsString('CUSTOM BUSINESS:', $mixed);

        $path = tempnam(sys_get_temp_dir(), 'other-business-layout-').'.xlsx';
        file_put_contents($path, $this->app->make(BusinessExcelExporter::class)->generate($folder, $source->fresh()));
        $sheet = IOFactory::load($path)->getSheet(0);
        $cell = fn (string $reference): string => trim((string) $sheet->getCell($reference)->getValue());

        // The first Business column preserves its defaults and continues with custom checkboxes.
        $this->assertSame('STL/Lotto Outlet', $cell('D25'));
        $this->assertSame('Vulcanizing Shop', $cell('D26'));
        $this->assertSame('✓', $cell('C26'));
        $this->assertSame('Computer Repair', $cell('D27'));
        $this->assertSame('✓', $cell('C27'));
        $this->assertSame('Online Reselling', $cell('D28'));
        $this->assertSame('✓', $cell('C28'));
        $this->assertSame('OTHER REMARKS:', $cell('B29'));
        $this->assertSame('Verified custom business remarks.', $cell('B30'));

        $borderSignature = function (string $reference) use ($sheet): array {
            $borders = $sheet->getStyle($reference)->getBorders();

            return [
                $borders->getTop()->getBorderStyle(),
                $borders->getRight()->getBorderStyle(),
                $borders->getBottom()->getBorderStyle(),
                $borders->getLeft()->getBorderStyle(),
            ];
        };
        foreach ([26, 27, 28] as $customRow) {
            $this->assertContains('D'.$customRow.':H'.$customRow, $sheet->getMergeCells());
            $this->assertSame($sheet->getRowDimension(25)->getRowHeight(), $sheet->getRowDimension($customRow)->getRowHeight());
            $this->assertSame($sheet->getStyle('C25')->getFont()->getName(), $sheet->getStyle('C'.$customRow)->getFont()->getName());
            $this->assertSame($sheet->getStyle('C25')->getFont()->getSize(), $sheet->getStyle('C'.$customRow)->getFont()->getSize());
            $this->assertSame($borderSignature('C25'), $borderSignature('C'.$customRow));
            $this->assertFalse($sheet->getStyle('D'.$customRow)->getFont()->getBold());
            $this->assertSame(Alignment::HORIZONTAL_LEFT, $sheet->getStyle('D'.$customRow)->getAlignment()->getHorizontal());
        }
        foreach (['C', 'I', 'O', 'U'] as $checkboxColumn) {
            $this->assertEqualsWithDelta(2.1, $sheet->getColumnDimension($checkboxColumn)->getWidth(), 0.001);
        }
        foreach (['D', 'J', 'P', 'V'] as $labelColumn) {
            $this->assertGreaterThan(4.875, $sheet->getColumnDimension($labelColumn)->getWidth());
        }
        // No default catalog label is long enough to need a second line at this merged width,
        // so every option row — default and custom alike — keeps the one compact height.
        foreach (range(10, 28) as $catalogRow) {
            $this->assertEqualsWithDelta(13.5, $sheet->getRowDimension($catalogRow)->getRowHeight(), 0.001, 'Row '.$catalogRow.' must stay compact.');
        }
        $this->assertTrue($sheet->getStyle('D23')->getAlignment()->getWrapText());

        // A group that stops short of the template's own range must not leave a bordered but
        // empty checkbox behind (the second Business column ends at row 24, so I25 is blank).
        $this->assertSame('', $cell('J25'));
        $this->assertSame(
            [Border::BORDER_NONE, Border::BORDER_NONE, Border::BORDER_NONE, Border::BORDER_THIN],
            $borderSignature('I25'),
            'A blank checkbox cell keeps only its group boundary.',
        );

        // Blank portions of the shorter groups keep only their structural boundaries.
        foreach (['D27', 'J27', 'P27', 'V27'] as $blankLabelCell) {
            $this->assertSame(
                [Border::BORDER_NONE, Border::BORDER_NONE, Border::BORDER_NONE, Border::BORDER_NONE],
                $borderSignature($blankLabelCell),
                'Blank cells must not inherit an unnecessary custom-row grid.',
            );
        }
        foreach (['B27', 'I27', 'O27', 'U27'] as $leftBoundaryCell) {
            $this->assertSame(Border::BORDER_THIN, $sheet->getStyle($leftBoundaryCell)->getBorders()->getLeft()->getBorderStyle());
        }
        $this->assertSame(Border::BORDER_THIN, $sheet->getStyle('AA27')->getBorders()->getRight()->getBorderStyle());

        // The dynamically tallest row has one uninterrupted bottom rule across the full catalog.
        foreach (range(2, 27) as $columnIndex) {
            $coordinate = Coordinate::stringFromColumnIndex($columnIndex).'28';
            $this->assertSame(Border::BORDER_THIN, $sheet->getStyle($coordinate)->getBorders()->getBottom()->getBorderStyle(), $coordinate.' must be on the shared bottom border.');
        }
        $thirdBusinessLabels = array_map(fn (int $row): string => $cell('P'.$row), range(10, 15));
        $this->assertNotContains('Vulcanizing Shop', $thirdBusinessLabels);
        $this->assertNotContains('Computer Repair', $thirdBusinessLabels);
        $this->assertNotContains('Online Reselling', $thirdBusinessLabels);
        $this->assertSame('B2:AA32', $sheet->getPageSetup()->getPrintArea());
        $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
        @unlink($path);
    }

    /** Only a label too long for the merged width grows, and only its own row. */
    public function test_a_long_business_label_grows_only_its_own_row(): void
    {
        [$ci, $folder, $source] = $this->business();
        $long = $this->customCategory($ci, 'Online Reselling of Preloved Household Appliances and Furniture');
        $source->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => [$long->optionKey()]]],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'other-business-long-').'.xlsx';
        file_put_contents($path, $this->app->make(BusinessExcelExporter::class)->generate($folder, $source->fresh()));
        $sheet = IOFactory::load($path)->getSheet(0);

        $this->assertSame($long->name, trim((string) $sheet->getCell('D26')->getValue()));
        $this->assertGreaterThan($sheet->getRowDimension(25)->getRowHeight(), $sheet->getRowDimension(26)->getRowHeight());
        $this->assertEqualsWithDelta(13.5, $sheet->getRowDimension(25)->getRowHeight(), 0.001);
        $this->assertTrue($sheet->getStyle('D26')->getAlignment()->getWrapText());
        @unlink($path);
    }

    /** A Co-Maker's report keeps its own resolved person under the official applicant label. */
    public function test_a_co_maker_report_prints_its_own_person(): void
    {
        [$ci, $folder] = $this->business();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Pedro Santos']);
        $source = $this->incomeSource($folder, $ci, $coMaker);

        $html = $this->render($folder, $source);

        $this->assertStringContainsString('NAME OF APPLICANT:', $html);
        $this->assertStringContainsString('Pedro Santos', $html);
        $this->assertStringNotContainsString('NAME OF CO-MAKER:', $html);

        $path = tempnam(sys_get_temp_dir(), 'other-business-comaker-').'.xlsx';
        file_put_contents($path, $this->app->make(BusinessExcelExporter::class)->generate($folder, $source));
        $sheet = IOFactory::load($path)->getSheet(0);
        $this->assertSame('NAME OF APPLICANT:', trim((string) $sheet->getCell('C6')->getValue()));
        $this->assertSame('Pedro Santos', trim((string) $sheet->getCell('G6')->getValue()));
        @unlink($path);
    }

    /** TESTS 28, 29, 30 — the old ranking instruction is gone from every source of the output. */
    public function test_the_old_ranking_heading_is_gone_from_the_output_templates(): void
    {
        $partial = file_get_contents(resource_path('views/reports/official/_business-report-other-income-source.blade.php'));

        $this->assertStringNotContainsString('CONRTIBUTION', $partial);
        $this->assertStringNotContainsString('RANK ALL INCOME SOURCES', $partial);
        // The bar reads the same shared key every other template's section bar uses.
        $this->assertStringContainsString("business-section-bar\"><th colspan=\"25\">{{ \$business['section_title'] }}", $partial);

        // section_title is the template's own name, which is exactly the required wording.
        $template = IncomeSourceTemplate::query()->where('template_type', 'other_business_source_of_income')->firstOrFail();
        $this->assertSame(self::NEW_HEADING, strtoupper($template->name));
    }

    /** The Excel writer uses the shared payload without shifting the original two-row block. */
    public function test_the_excel_writer_keeps_the_original_compact_header_structure(): void
    {
        $exporter = file_get_contents(app_path('Services/Reports/BusinessExcelExporter.php'));

        foreach (['NAME OF APPLICANT:', 'AMOUNT APPLIED:', 'BRANCH:', 'ACCOUNT OFFICER:'] as $label) {
            $this->assertStringContainsString($label, $exporter, $label.' must be written into the sheet.');
        }

        // The complete header payload comes from the same builder used by Web/PDF.
        $this->assertStringContainsString("\$header = (array) (\$document['business'] ?? []);", $exporter);
        // The section bar is rewritten from the template name rather than left as the reference
        // workbook's old ranking instruction.
        $this->assertStringContainsString('strtoupper($source->template->name)', $exporter);
        $this->assertStringNotContainsString('OTHER_INCOME_SOURCE_ROW_OFFSET', $exporter);
    }

    /**
     * Every vertical rule in the section is declared exactly once and runs the full catalog.
     *
     * Guards the three border artifacts this section used to ship with: the reference workbook's
     * leftover strip past the report's AA edge (a second, broken rule beside the frame), the
     * applicant box's per-cell allBorders (interior verticals inside one merged block, and a
     * right edge one column short of the amount box below it), and the first Business column's
     * divider stopping early whenever another group renders further down than it does.
     */
    public function test_the_excel_catalog_borders_are_declared_once_and_run_unbroken(): void
    {
        [$ci, $folder, $source] = $this->business();
        // The first Business column stops at row 25 while Agriculture reaches row 26, so the
        // catalog bottom is set by another group and the first column has a blank final row.
        $source->businessReport->update([
            'template_data' => ['fields' => ['income_sources' => ['still_lotto_outlet', 'corn_production']]],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'other-business-borders-').'.xlsx';
        file_put_contents($path, $this->app->make(BusinessExcelExporter::class)->generate($folder, $source->fresh()));
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);

        $catalogBottomRow = 26;
        $this->assertSame('STL/Lotto Outlet', trim((string) $sheet->getCell('D25')->getValue()));
        $this->assertSame('', trim((string) $sheet->getCell('D'.$catalogBottomRow)->getValue()));
        $this->assertSame('OTHER REMARKS:', trim((string) $sheet->getCell('B'.($catalogBottomRow + 1))->getValue()));

        // Nothing is drawn or merged past the report's own right edge.
        $rightEdge = Coordinate::columnIndexFromString('AA');
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($columnIndex = $rightEdge + 1; $columnIndex <= $lastColumn; $columnIndex++) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            foreach (range(1, $sheet->getHighestRow()) as $row) {
                $borders = $sheet->getStyle($column.$row)->getBorders();
                foreach (['getLeft', 'getTop', 'getRight', 'getBottom'] as $edge) {
                    $this->assertSame(Border::BORDER_NONE, $borders->{$edge}()->getBorderStyle(), $column.$row.' sits outside the report frame.');
                }
            }
        }
        foreach ($sheet->getMergeCells() as $mergeRange) {
            [$mergeColumn] = Coordinate::coordinateFromString(explode(':', $mergeRange)[0]);
            $this->assertLessThanOrEqual($rightEdge, Coordinate::columnIndexFromString($mergeColumn), $mergeRange.' sits outside the report frame.');
        }
        for ($columnIndex = $rightEdge + 1; $columnIndex <= $lastColumn; $columnIndex++) {
            $this->assertSame('', trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex).'1')->getValue()));
        }

        // Column and row formats, not only cell styles. Excel applies a column's own format to
        // every cell in it that carries no format of its own, so a bordered column format paints a
        // full-height rule that getStyle() on those (non-existent) cells never reports — which is
        // exactly how the reference workbook's bordered AB column kept drawing a second vertical
        // line beside the finished report.
        $formatBorders = function (?int $styleIndex) use ($book) {
            return $book->getCellXfByIndexOrNull($styleIndex)?->getBorders();
        };
        foreach ($sheet->getColumnDimensions() as $columnDimension) {
            $column = (string) $columnDimension->getColumnIndex();
            if ($column === '' || Coordinate::columnIndexFromString($column) <= $rightEdge) {
                continue;
            }
            $borders = $formatBorders($columnDimension->getXfIndex());
            foreach (['getLeft', 'getTop', 'getRight', 'getBottom'] as $edge) {
                $this->assertSame(
                    Border::BORDER_NONE,
                    $borders?->{$edge}()->getBorderStyle() ?? Border::BORDER_NONE,
                    'Column '.$column.' has a column format that draws a rule outside the report frame.',
                );
            }
        }
        // A bordered row format would leak the same way, straight across and past the right edge.
        foreach ($sheet->getRowDimensions() as $rowDimension) {
            $borders = $formatBorders($rowDimension->getXfIndex());
            foreach (['getLeft', 'getTop', 'getRight', 'getBottom'] as $edge) {
                $this->assertSame(
                    Border::BORDER_NONE,
                    $borders?->{$edge}()->getBorderStyle() ?? Border::BORDER_NONE,
                    'Row '.$rowDimension->getRowIndex().' has a row format that draws a rule outside the report frame.',
                );
            }
        }

        // No vertical boundary is declared twice (a cell's right edge plus its neighbour's left).
        foreach (range(2, $sheet->getHighestRow()) as $row) {
            for ($columnIndex = 2; $columnIndex < $rightEdge; $columnIndex++) {
                $left = Coordinate::stringFromColumnIndex($columnIndex).$row;
                $right = Coordinate::stringFromColumnIndex($columnIndex + 1).$row;
                $this->assertFalse(
                    $sheet->getStyle($left)->getBorders()->getRight()->getBorderStyle() !== Border::BORDER_NONE
                        && $sheet->getStyle($right)->getBorders()->getLeft()->getBorderStyle() !== Border::BORDER_NONE,
                    $left.'/'.$right.' declare the same vertical rule twice.',
                );
            }
        }

        // The frame and all four group dividers reach the shared bottom rule without a gap.
        foreach (['B', 'C', 'I', 'O', 'U'] as $boundaryColumn) {
            foreach (range(10, $catalogBottomRow) as $row) {
                $this->assertSame(Border::BORDER_THIN, $sheet->getStyle($boundaryColumn.$row)->getBorders()->getLeft()->getBorderStyle(), $boundaryColumn.$row.' breaks a vertical rule.');
            }
        }
        foreach (range(10, $catalogBottomRow) as $row) {
            $this->assertSame(Border::BORDER_THIN, $sheet->getStyle('AA'.$row)->getBorders()->getRight()->getBorderStyle(), 'AA'.$row.' breaks the right frame.');
        }

        // Both header value blocks share one right edge instead of jogging by a column.
        $this->assertContains('G6:N6', $sheet->getMergeCells());
        $this->assertContains('G7:N7', $sheet->getMergeCells());
        @unlink($path);
    }

    /** The generated workbook carries only the official four fields in the original two rows. */
    public function test_the_generated_excel_workbook_carries_the_official_compact_header(): void
    {
        [$ci, $folder, $source] = $this->business();
        $second = User::factory()->create(['full_name' => 'Anthony B. Yong']);
        $third = User::factory()->create(['full_name' => 'Reasan Mark Gura']);
        $source->update(['account_officer_name' => 'Maria Account Officer']);
        $this->app->make(CiParticipantService::class)->syncCompanions($source, [$second->id, $third->id]);

        // generate() returns the workbook's bytes, so they are written out before being reopened.
        $path = tempnam(sys_get_temp_dir(), 'other-business-').'.xlsx';
        file_put_contents($path, $this->app->make(BusinessExcelExporter::class)->generate($folder, $source->fresh()));
        $sheet = IOFactory::load($path)->getSheet(0);

        $cell = fn (string $reference): string => trim((string) $sheet->getCell($reference)->getValue());

        $this->assertSame('NAME OF APPLICANT:', $cell('C6'));
        $this->assertSame('JUAN DELA CRUZ', $cell('G6'));
        $this->assertSame('BRANCH:', $cell('O6'));
        $this->assertSame('MAIN BRANCH', $cell('T6'));
        $this->assertSame('AMOUNT APPLIED:', $cell('C7'));
        $this->assertSame('340,000.00', $cell('G7'));
        $this->assertSame('ACCOUNT OFFICER:', $cell('O7'));
        $this->assertSame('Maria Account Officer', $cell('T7'));
        foreach (['CI-IN CHARGE:', 'START DATE OF CI:', 'DATE SUBMITTED TO CA:'] as $excludedLabel) {
            $this->assertStringNotContainsString($excludedLabel, $this->excelText($folder, $source->fresh()));
        }
        $this->assertSame('CREDIT INVESTIGATION REPORT', $cell('C3'));
        $this->assertSame('(SOURCE OF INCOME VALIDATION)', $cell('C4'));

        $this->assertSame(self::NEW_HEADING, $cell('C8'));
        $this->assertSame('Business:', $cell('C9'));
        $this->assertSame('Leasing Real Estate (Agri)', $cell('D10'));
        $this->assertSame('STL/Lotto Outlet', $cell('D25'));
        $this->assertSame('✓', $cell('C25'));
        $this->assertSame('OTHER REMARKS:', $cell('B27'));

        @unlink($path);
    }

    /** The shared builder is the single data source; nothing is hard-coded per output. */
    public function test_every_header_value_comes_from_the_shared_builder(): void
    {
        [$ci, $folder, $source] = $this->business();

        $data = $this->app->make(OfficialReportDataBuilder::class)->build($folder, OfficialReportType::BusinessIncomeSource, $source);
        $business = $data['business'];

        $this->assertSame(strtoupper($ci->full_name), $business['ci_in_charge']);
        $this->assertSame('MAIN BRANCH', strtoupper($business['branch']));
        $this->assertSame('NAME OF APPLICANT', $business['name_label']);
        $this->assertSame(self::NEW_HEADING, $business['section_title']);
        $this->assertNotSame('', (string) $business['start_date']);
        $this->assertNotSame('', (string) $business['submitted_date']);
        $this->assertNotSame('', (string) $business['amount_applied']);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource} */
    private function business(): array
    {
        $ci = User::factory()->create(['full_name' => 'Rey C. Maghilom']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'JUAN DELA CRUZ']);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id,
            'branch_name' => 'MAIN BRANCH', 'amount_applied' => 340000,
        ]);

        return [$ci, $folder, $this->incomeSource($folder, $ci)];
    }

    private function incomeSource(ClientFolder $folder, User $ci, ?CoMaker $person = null): IncomeSource
    {
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $person?->id,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'other_business_source_of_income')->value('id'),
            'template_type' => 'other_business_source_of_income',
            'source_name' => 'OTHER BUSINESS/SOURCE OF INCOME',
            'business_name' => 'OTHER BUSINESS/SOURCE OF INCOME',
            'branch_name' => 'MAIN BRANCH',
            'applicant_name_snapshot' => $person?->full_name ?: $folder->display_name,
            'created_by' => $ci->id,
        ]);

        BusinessReport::factory()->create([
            'income_source_id' => $source->id,
            'business_name' => 'OTHER BUSINESS/SOURCE OF INCOME',
            'start_date' => '2026-01-05',
            'submitted_date' => '2026-01-20',
            'template_data' => ['fields' => ['income_sources' => ['still_lotto_outlet']]],
        ]);

        return $source->fresh();
    }

    /** The shared official output, rendered exactly as the web preview and the PDF both render it. */
    private function render(ClientFolder $folder, IncomeSource $source, bool $pdfMode = false): string
    {
        $data = $this->app->make(OfficialReportDataBuilder::class)->build($folder, OfficialReportType::BusinessIncomeSource, $source);

        return view('reports.official.business', [
            'business' => $data['business'],
            'document' => $data,
            'mark' => fn (bool $selected): string => $selected ? '( ✓ )' : '(   )',
            'showCommonHeader' => true,
            // The shared output partial is rendered for screen here; the PDF path passes true.
            'pdfMode' => $pdfMode,
        ])->render();
    }

    private function customCategory(User $ci, string $name): CustomBusinessCategory
    {
        return CustomBusinessCategory::query()->create([
            'name' => $name,
            'is_active' => true,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);
    }

    private function excelText(ClientFolder $folder, IncomeSource $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'other-business-custom-').'.xlsx';
        file_put_contents($path, $this->app->make(BusinessExcelExporter::class)->generate($folder, $source));
        $sheet = IOFactory::load($path)->getSheet(0);
        $values = [];
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $values[] = (string) $sheet->getCell($coordinate)->getValue();
        }
        @unlink($path);

        return implode("\n", $values);
    }
}
