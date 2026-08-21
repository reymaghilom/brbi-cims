<?php

namespace App\Services\Reports;

use App\Enums\OfficialReportType;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the downloadable Business Report workbook.
 *
 * For template types listed in SECTIONS, the matching section of the official
 * reference workbook (resources/report-templates/business-report.xlsx, sheet
 * "BUSINESS REPORT") is used as-is — labels, borders, merged cells and column
 * widths are kept exactly as in the reference file, and every cell in that
 * range is explicitly overwritten (cleared, if there's nothing saved) so none
 * of the workbook's sample values can ever leak into a real download. Every
 * other dedicated-business template — anything without a verified, fully
 * cleared cell mapping — uses the generic layout below, built purely from the
 * same report data used by Print/PDF, which carries no sample content at all.
 */
class BusinessExcelExporter
{
    private const SHEET = 'BUSINESS REPORT';

    /**
     * "Other Business/Source of Income" has no section on the shared BUSINESS REPORT sheet —
     * its reference layout (income-source ranking checklist) lives on this separate sheet,
     * which the reference workbook already dedicates entirely to it (own logo, own header
     * shape, own column grid), so it's used whole rather than trimmed like the other
     * sections — see fromOtherIncomeSourceTemplate().
     */
    private const OTHER_INCOME_SOURCE_SHEET = 'BUS REPORT NO AVILABLE TEMPLATE';

    /** The reference workbook's row 1 is a draft note and row 2 anchors the BINHI logo — both are kept in place (never removed) so the logo's position/size survives untouched. */
    private const NOTE_ROW = 1;

    private const HEADER_END = 9;

    /**
     * template_type => [first row, last row] of its section in the reference workbook.
     *
     * Only templates with a verified, fully-cleared cell mapping (no risk of a
     * leftover sample value from the reference workbook) are listed here. Every
     * other dedicated-business template uses the safe generic writer below,
     * which is built purely from live data and can never leak sample content.
     */
    private const SECTIONS = [
        'retail_grocery_water_refilling' => [254, 284],
        'leasing_truck_equipment' => [10, 32],
        'leasing_non_agricultural' => [33, 50],
        'leasing_agricultural' => [51, 62],
        'leasing_poultry_farm' => [63, 74],
        'taxi_operator' => [75, 89],
        'puj_van_jeepney_operator' => [90, 105],
        'trucking_services' => [106, 129],
        'distributorship_wholesaler_b2b' => [130, 162],
        'pharmacy_drugstore' => [163, 189],
        'general_merchandise_hardware_parts' => [190, 221],
        'buy_sell_dry_goods' => [222, 253],
        'meatshop_store' => [285, 320],
        'contractor_subcontractor' => [321, 352],
        'restaurant_food_stall' => [353, 377],
        'farming_corn' => [378, 399],
        'farming_sugarcane' => [400, 421],
        'remittance_income' => [422, 435],
    ];

    /**
     * Per-template layout for the schema-driven "retail-shaped" sections (profile block,
     * optional store-type radio row, branch-count summary, branches table, a combined
     * products/observations grid, a suppliers table, then remarks). Row numbers are the
     * reference workbook's absolute row numbers for that template's section; every column
     * letter matches the sheet-wide grid shared by every dedicated-business section
     * (verified against the reference workbook, same columns as the retail section above).
     */
    private const SCHEMA_GRID_SECTIONS = [
        'pharmacy_drugstore' => [
            'title_row' => 163, 'counts_row' => 171, 'branches_header_row' => 173, 'branches_rows' => 3,
            'grid_header_row' => 178, 'grid_rows' => 4, 'products_rows' => 4,
            'suppliers_header_row' => 184, 'suppliers_rows' => 3, 'remarks_row' => 189,
        ],
        'general_merchandise_hardware_parts' => [
            'title_row' => 190, 'store_type_row' => 198, 'store_type_field' => 'store_type',
            'store_type_options' => ['General Merchandise', 'Hardware', 'Auto or Motor Parts', 'Paint Only'],
            'counts_row' => 199, 'branches_header_row' => 201, 'branches_rows' => 3,
            'grid_header_row' => 206, 'grid_rows' => 8, 'products_rows' => 6,
            'suppliers_header_row' => 216, 'suppliers_rows' => 3, 'remarks_row' => 221,
        ],
        'buy_sell_dry_goods' => [
            'title_row' => 222, 'store_type_row' => 230, 'store_type_field' => 'store_type',
            'store_type_options' => ['High End Display', 'Middle Tier Store', 'Ukay-Ukay', 'Stall Type Only'],
            'counts_row' => 231, 'branches_header_row' => 233, 'branches_rows' => 3,
            'grid_header_row' => 238, 'grid_rows' => 8, 'products_rows' => 6,
            // The reference workbook's row order for this section doesn't match the config schema's
            // "questions" order (the bank question is asked 3rd in the schema but printed last on the
            // sheet) — verified against both the reference workbook and the edit form's own reordering
            // in _business-pharmacy-products-observations.blade.php. Every other schema-grid section's
            // rows already line up with its schema order, so this mapping is only needed here.
            'question_order' => [0, 1, 2, 4, 5, 6, 7, 3],
            'suppliers_header_row' => 248, 'suppliers_rows' => 3, 'remarks_row' => 253,
        ],
    ];

    /** Schema `tables.branches.*` column keys (differ from the retail section's Eloquent attribute names) mapped to the shared branches-table columns. */
    private const SCHEMA_BRANCH_COLUMNS = ['C' => 'location', 'G' => 'frontage', 'H' => 'total_area', 'I' => 'air_conditioned', 'J' => 'operating_days_hours', 'M' => 'shifts', 'N' => 'employees_per_shift', 'P' => 'average_sales_per_shift', 'R' => 'inventory_level', 'T' => 'monthly_rent', 'V' => 'years_in_area', 'X' => 'nearby_brands'];

    public function __construct(private readonly OfficialReportDataBuilder $dataBuilder) {}

    public function generate(ClientFolder $folder, IncomeSource $source): string
    {
        [$book, $document] = $this->buildBook($folder, $source);
        $book->getProperties()
            ->setCreator('Binhi Rural Bank Inc.')
            ->setTitle($document['title'])
            ->setSubject('Saved Business Report data');

        return $this->save($book);
    }

    /**
     * ONE workbook, ONE worksheet — matching Web Print/PDF's single continuous report instead of
     * a tab per business. The first selected business becomes the master sheet outright (built
     * by the exact same per-template section logic as generate(): same template file, styles,
     * merges, borders, logo, hidden-row fix), keeping its full shared CI/client header exactly as
     * approved. Every business after it is built the same way with that header suppressed, then
     * only its own business-specific rows (skipping the shared header rows entirely, never just
     * blanking them) are copied onto the end of the master sheet via appendBusinessSection() —
     * so nothing is duplicated, no blank header rows are left, and the next business's title
     * starts immediately where the previous one's content ends. Businesses keep the order they
     * were selected in.
     *
     * @param  Collection<int, IncomeSource>  $sources
     */
    public function generateBatch(ClientFolder $folder, Collection $sources): string
    {
        $master = null;

        foreach ($sources as $index => $source) {
            $isFirst = $index === 0;
            [$book] = $this->buildBook($folder, $source, $isFirst);
            $sheet = $book->getSheet(0);

            if ($master === null) {
                $sheet->setTitle('Business Report');
                $master = $book;

                continue;
            }

            $this->appendBusinessSection($master->getSheet(0), $sheet, $this->businessContentStartRow($source));
            $book->disconnectWorksheets();
        }

        $masterSheet = $master->getSheet(0);
        // Every append grows the sheet past whatever print area the first business's own
        // buildBook() call originally set for itself alone — recomputed once here so the whole
        // combined batch, not just business 1, is what actually prints/exports.
        $masterSheet->getPageSetup()->setPrintArea('A1:'.$masterSheet->getHighestColumn().$masterSheet->getHighestRow());
        $master->setActiveSheetIndex(0);
        $master->getProperties()
            ->setCreator('Binhi Rural Bank Inc.')
            ->setTitle('Business Reports - '.$folder->display_name)
            ->setSubject('Saved Business Report data (batch)');

        return $this->save($master);
    }

    /**
     * Copies [$sourceStartRow, $source's highest row] from a business's own individually-built
     * sheet onto the end of $master (cell values, per-cell styles, row heights, and any merged
     * ranges fully inside that row range — all shifted by the same offset). Column widths and
     * drawings are left alone: every SECTIONS-mapped template trims from the exact same reference
     * "BUSINESS REPORT" sheet, so they already share master's column grid, and a business-
     * specific section never carries its own logo/header drawing (those live only in the header
     * rows this method is never asked to copy).
     */
    private function appendBusinessSection(Worksheet $master, Worksheet $source, int $sourceStartRow): void
    {
        $sourceHighestRow = $source->getHighestRow();
        if ($sourceStartRow > $sourceHighestRow) {
            return;
        }

        $highestColumnIndex = Coordinate::columnIndexFromString($source->getHighestColumn());
        $previousLastRow = $master->getHighestRow();
        $rowOffset = ($previousLastRow + 1) - $sourceStartRow;

        for ($row = $sourceStartRow; $row <= $sourceHighestRow; $row++) {
            $targetRow = $row + $rowOffset;
            $height = $source->getRowDimension($row)->getRowHeight();
            if ($height > 0) {
                $master->getRowDimension($targetRow)->setRowHeight($height);
            }
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $sourceCell = $source->getCell($columnLetter.$row);
                $targetCell = $master->getCell($columnLetter.$targetRow);
                $targetCell->setValue($sourceCell->getValue());
                $targetCell->getStyle()->applyFromArray($sourceCell->getStyle()->exportArray());
            }
        }

        // This appended business's very first row (its own title/template box) lands directly
        // beneath the previous business's own last row, so the shared boundary would otherwise
        // carry TWO borders — the previous business's own bottom edge (left as-is, from its own
        // reference-template styling) and whatever this title row's own top edge copied over —
        // stacking into exactly the doubled/overlapping line this fixes. Normalizing both sides
        // instead: the previous row's bottom edge is cleared first, then the title row's own top
        // edge is filled in only where a cell doesn't already carry one of its own (e.g. a merged
        // title whose style only lived on the anchor cell — the same kind of gap already fixed
        // elsewhere in this exporter via the B26:AA26 note in fromOtherIncomeSourceTemplate()).
        // A cell that already had a complete top border keeps exactly that border, never a second
        // one stacked on top of it.
        //
        // The two sides intentionally use different widths, not the same one. Clearing the
        // previous row's bottom border uses the sheet's overall widest column
        // ($master->getHighestColumn(), reflecting whichever business anywhere in the batch is
        // widest) — that business's own box (e.g. an "OTHER REMARKS" box) can be wider than THIS
        // title row, and clearing wide erases it completely with no leftover segment sticking
        // out. Filling this title row's own gaps, though, must stay within THIS title row's own
        // actual left/right bounds specifically — using the sheet-wide column count (or always
        // starting from column A) for that side would draw a brand new top border past the title
        // box's own natural width on either side, which is exactly what produced the reported
        // left/right protrusion (a bug in the previous version of this fix). Those bounds come
        // from the title row's own merge range when it's merged (the normal case for every
        // template's title bar), falling back to its own leftmost/rightmost populated cell when
        // it isn't.
        $targetFirstRow = $sourceStartRow + $rowOffset;
        $reportRightColumn = Coordinate::columnIndexFromString($master->getHighestColumn());
        [$titleLeftColumn, $titleRightColumn] = $this->rowColumnBounds($source, $sourceStartRow, $highestColumnIndex);

        if ($previousLastRow >= 1) {
            for ($col = 1; $col <= $reportRightColumn; $col++) {
                $columnLetter = Coordinate::stringFromColumnIndex($col);
                $master->getStyle($columnLetter.$previousLastRow)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_NONE);
            }
        }
        for ($col = $titleLeftColumn; $col <= $titleRightColumn; $col++) {
            $columnLetter = Coordinate::stringFromColumnIndex($col);
            $topBorder = $master->getStyle($columnLetter.$targetFirstRow)->getBorders()->getTop();
            if ($topBorder->getBorderStyle() === Border::BORDER_NONE) {
                $topBorder->setBorderStyle(Border::BORDER_THIN);
            }
        }

        foreach ($source->getMergeCells() as $mergeRange) {
            [$start, $end] = explode(':', $mergeRange);
            [$startColumn, $startRow] = Coordinate::coordinateFromString($start);
            [$endColumn, $endRow] = Coordinate::coordinateFromString($end);
            if ((int) $startRow < $sourceStartRow || (int) $startRow > $sourceHighestRow) {
                continue;
            }
            $master->mergeCells($startColumn.((int) $startRow + $rowOffset).':'.$endColumn.((int) $endRow + $rowOffset));
        }
    }

    /**
     * The left/right column bounds of a business's own title row within its individually-built
     * sheet — i.e. the box's own natural width, not the whole sheet's. Prefers the row's own
     * merge range (every template's title bar is normally one merged cell); falls back to the
     * row's leftmost/rightmost populated cell for the rare unmerged case, and to the full
     * $fallbackRightColumn width only if the row has no merge and no content at all.
     *
     * @return array{0: int, 1: int}
     */
    private function rowColumnBounds(Worksheet $sheet, int $row, int $fallbackRightColumn): array
    {
        foreach ($sheet->getMergeCells() as $mergeRange) {
            [$start, $end] = explode(':', $mergeRange);
            [$startColumn, $startRow] = Coordinate::coordinateFromString($start);
            [$endColumn, $endRow] = Coordinate::coordinateFromString($end);
            if ((int) $startRow === $row) {
                return [Coordinate::columnIndexFromString($startColumn), Coordinate::columnIndexFromString($endColumn)];
            }
        }

        $left = null;
        $right = null;
        for ($col = 1; $col <= $fallbackRightColumn; $col++) {
            if ($sheet->getCell([$col, $row])->getValue() !== null) {
                $left ??= $col;
                $right = $col;
            }
        }

        return [$left ?? 1, $right ?? $fallbackRightColumn];
    }

    /**
     * Where a business's own content starts within its individually-built sheet, i.e. everything
     * from here down is that business alone — never the shared client header — so
     * appendBusinessSection() can copy exactly this range with nothing left to blank.
     */
    private function businessContentStartRow(IncomeSource $source): int
    {
        if ($source->template_type === 'other_business_source_of_income') {
            // OTHER_INCOME_SOURCE_SHEET keeps its own (unTrimmed) native row numbers — the
            // client-info block injectHeader() equivalent occupies rows 6-7 there (see
            // fromOtherIncomeSourceTemplate()), so this business's own content starts at row 8.
            return 8;
        }

        if (isset(self::SECTIONS[$source->template_type])) {
            // fromTemplate() always trims every SECTIONS-mapped template down so its own content
            // starts at the same normalized row, regardless of that template's raw row range in
            // the reference workbook — see $sectionOffset there.
            return self::HEADER_END + 1;
        }

        // fromDocument()'s fallback layout, built with showCommonHeader already false, contains
        // nothing but this business's own title/subtitle/sections from row 1 down.
        return 1;
    }

    /** @return array{0: Spreadsheet, 1: array<string, mixed>} */
    private function buildBook(ClientFolder $folder, IncomeSource $source, bool $showCommonHeader = true): array
    {
        $source->loadMissing(['template', 'businessReport.properties.tenants', 'businessReport.branches', 'businessReport.products', 'businessReport.suppliers', 'businessReport.observations']);
        $document = $this->dataBuilder->build($folder, OfficialReportType::BusinessIncomeSource, $source);

        $templatePath = resource_path('report-templates/business-report.xlsx');
        if ($source->template_type === 'other_business_source_of_income') {
            $book = is_file($templatePath)
                ? $this->fromOtherIncomeSourceTemplate($templatePath, $folder, $source, $document, $showCommonHeader)
                : $this->fromDocument($document, $showCommonHeader);
        } else {
            $range = self::SECTIONS[$source->template_type] ?? null;
            $book = ($range !== null && is_file($templatePath))
                ? $this->fromTemplate($templatePath, $range, $folder, $source, $document, $showCommonHeader)
                : $this->fromDocument($document, $showCommonHeader);
        }

        return [$book, $document];
    }

    private function save(Spreadsheet $book): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'brbi-business-xlsx-');
        abort_if($temporary === false, 500, 'Unable to prepare the Excel report.');
        try {
            (new Xlsx($book))->save($temporary);
            $bytes = file_get_contents($temporary);
            abort_if($bytes === false, 500, 'Unable to read the generated Excel report.');

            return $bytes;
        } finally {
            $book->disconnectWorksheets();
            @unlink($temporary);
        }
    }

    /**
     * Trims the reference workbook down to the shared header rows plus the one
     * section that matches the saved template, keeping the original workbook's
     * borders, merged cells, column widths and BINHI logo intact — rows 1-2
     * (the logo's anchor rows) are never removed, only the unwanted rows below
     * them are, since PhpSpreadsheet does not re-anchor images when rows are
     * deleted above them.
     *
     * @param array{0: int, 1: int} $range
     */
    private function fromTemplate(string $templatePath, array $range, ClientFolder $folder, IncomeSource $source, array $document, bool $showCommonHeader = true): Spreadsheet
    {
        $book = IOFactory::load($templatePath);
        $sheet = $book->getSheetByName(self::SHEET);
        if (! $sheet instanceof Worksheet) {
            $book->disconnectWorksheets();

            return $this->fromDocument($document, $showCommonHeader);
        }

        for ($index = $book->getSheetCount() - 1; $index >= 0; $index--) {
            if ($book->getSheet($index) !== $sheet) {
                $book->removeSheetByIndex($index);
            }
        }
        $sheet->setTitle('Business Report');
        $sheet->setCellValue([2, self::NOTE_ROW], null);

        $highestRow = $sheet->getHighestRow();
        if ($highestRow > $range[1]) {
            $sheet->removeRow($range[1] + 1, $highestRow - $range[1]);
        }
        if ($range[0] > self::HEADER_END + 1) {
            $sheet->removeRow(self::HEADER_END + 1, $range[0] - self::HEADER_END - 1);
        }

        // The reference workbook keeps every dedicated-business section hidden except
        // whichever one was left open when it was last saved (currently "Leasing Operations
        // (Truck or Equipment)", the first section) — every other section's rows carry an
        // explicit hidden="true" row attribute. Once trimmed down to a single section for
        // download, that section must always be visible regardless of its hidden state in
        // the source file, so every row dimension the template defines is unhidden here.
        foreach ($sheet->getRowDimensions() as $rowDimension) {
            $rowDimension->setVisible(true);
        }

        $sectionOffset = $range[0] - (self::HEADER_END + 1);

        $this->injectHeader($sheet, $folder, $source, $showCommonHeader);
        if ($source->template_type === 'retail_grocery_water_refilling') {
            $this->injectRetailSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'leasing_truck_equipment') {
            $this->injectLeasingTruckEquipmentSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'leasing_non_agricultural') {
            $this->injectLeasingNonAgriculturalSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'leasing_agricultural') {
            $this->injectLeasingAgriculturalSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'leasing_poultry_farm') {
            $this->injectLeasingPoultryFarmSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'taxi_operator') {
            $this->injectTaxiOperatorSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'puj_van_jeepney_operator') {
            $this->injectPujVanJeepneySection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'trucking_services') {
            $this->injectTruckingServicesSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'distributorship_wholesaler_b2b') {
            $this->injectDistributorshipSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'meatshop_store') {
            $this->injectMeatshopStoreSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'contractor_subcontractor') {
            $this->injectContractorSubcontractorSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'restaurant_food_stall') {
            $this->injectRestaurantFoodStallSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'farming_corn') {
            $this->injectFarmingCornSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'farming_sugarcane') {
            $this->injectFarmingSugarcaneSection($sheet, $sectionOffset, $source);
        } elseif ($source->template_type === 'remittance_income') {
            $this->injectRemittanceIncomeSection($sheet, $sectionOffset, $source);
        } elseif (isset(self::SCHEMA_GRID_SECTIONS[$source->template_type])) {
            $this->injectSchemaGridSection($sheet, $sectionOffset, $source, self::SCHEMA_GRID_SECTIONS[$source->template_type]);
        }

        $sheet->getPageSetup()->setPrintArea('A1:'.$sheet->getHighestColumn().$sheet->getHighestRow());
        $sheet->setSelectedCells('A1');
        $sheet->setTopLeftCell('A1');
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Builds the workbook for "Other Business/Source of Income" from its dedicated
     * OTHER_INCOME_SOURCE_SHEET, which the reference workbook already scopes to exactly this
     * one section (own BINHI logo anchor, own print area, own column grid unrelated to the
     * shared BUSINESS REPORT sheet's) — so unlike fromTemplate(), nothing needs trimming;
     * the whole sheet is kept and only its live values are injected.
     *
     * Every one of the 58 income-source checklist cells is overwritten with a mark + label
     * combination (the same inline-choice technique used throughout this exporter, e.g.
     * ownershipChoiceText()) — the coordinates and label text below are verified against the
     * reference workbook and mirror config/business-report-templates.php's
     * $otherIncomeSourceGroups order exactly (Business split across 3 columns matching the
     * sheet's own 3-column layout, then Agriculture, Professional Services, Remittance, and
     * Employment), the same grouping/order used by the edit form's own catalog in
     * _business-other-income-source.blade.php.
     */
    private function fromOtherIncomeSourceTemplate(string $templatePath, ClientFolder $folder, IncomeSource $source, array $document, bool $showCommonHeader = true): Spreadsheet
    {
        $book = IOFactory::load($templatePath);
        $sheet = $book->getSheetByName(self::OTHER_INCOME_SOURCE_SHEET);
        if (! $sheet instanceof Worksheet) {
            $book->disconnectWorksheets();

            return $this->fromDocument($document, $showCommonHeader);
        }

        for ($index = $book->getSheetCount() - 1; $index >= 0; $index--) {
            if ($book->getSheet($index) !== $sheet) {
                $book->removeSheetByIndex($index);
            }
        }
        $sheet->setTitle('Business Report');

        foreach ($sheet->getRowDimensions() as $rowDimension) {
            $rowDimension->setVisible(true);
        }

        $report = $source->businessReport;
        $cibiReport = $folder->cibiReport()->where('co_maker_id', $source->co_maker_id)->first();

        // C6 is this sheet's own (differently-positioned) "NAME OF APPLICANT:" label cell —
        // same Co-Maker override as injectHeader()'s O7. Batch sheets after the first blank
        // these instead of repeating the same client's identifying info — see injectHeader()'s
        // matching note.
        if ($showCommonHeader) {
            $this->set($sheet, 'C', 6, $source->co_maker_id ? 'NAME OF CO-MAKER:' : 'NAME OF APPLICANT:');
            $this->set($sheet, 'G', 6, $source->applicant_name_snapshot ?: $folder->display_name);
            $this->set($sheet, 'T', 6, $source->branch_name ?: $cibiReport?->branch_name);
            $this->set($sheet, 'G', 7, $cibiReport?->amount_applied);
            $this->set($sheet, 'T', 7, $source->account_officer_name ?: $cibiReport?->account_officer_name);
        } else {
            $this->set($sheet, 'C', 6, '');
            $this->set($sheet, 'G', 6, '');
            $this->set($sheet, 'T', 6, '');
            $this->set($sheet, 'G', 7, '');
            $this->set($sheet, 'T', 7, '');
        }

        // Columns C, I, O and U are each already a fully-bordered single square cell in the
        // reference workbook — one immediately before each of the 4 group columns (D, J, P,
        // V) — verified cell-by-cell against the reference file. That box is the reference's
        // own dedicated checkbox cell, not a leftover artifact: C25's raw sample value is a
        // bare "/" (a hand-typed placeholder tick) sitting directly next to D25's "STL/Lotto
        // Outlet" label, confirming the box was always meant to hold the mark, separate from
        // the label. The mark itself is a bare checkmark, not the ☑/☐ "ballot box" characters
        // used elsewhere in this method's history: those glyphs are themselves a drawn box
        // shape, so placing one inside a cell that's *already* bordered as a box rendered as
        // two nested squares instead of one. With the cell's own border as the only square,
        // a selected choice is just a checkmark inside it and an unselected one is truly
        // empty — which still reads as an empty box, because the cell's border is the box.
        $schema = $source->template->businessReportSchema();
        $groups = (array) ($schema['income_source_groups'] ?? []);
        $selected = (array) data_get($report?->template_data, 'fields.income_sources', []);
        $mark = fn (bool $isSelected): string => $isSelected ? '✓' : '';
        // Every checkbox cell shares its row's height with 3 other groups' cells (the row is one
        // Excel row wide), so a per-row height driven by that row's own longest label made rows
        // — and so the checkbox squares sitting in them, since their column width is already
        // fixed and uniform — different heights depending on what else happened to share that
        // row. A single row height, sized once for the single longest label anywhere in the
        // whole checklist (so nothing ever wraps into a clipped line) and applied to every row,
        // keeps every checkbox the same square size throughout.
        $longestLabelLength = collect($groups)->flatten(1)->max(fn (array $choice): int => mb_strlen($choice['label']));
        $uniformRowHeight = max(1, (int) ceil($longestLabelLength / 26)) * 12;
        $writeChoices = function (string $boxColumn, string $labelColumn, string $labelEndColumn, array $rows, array $choices) use ($sheet, $mark, $selected, $uniformRowHeight): void {
            foreach ($rows as $index => $templateRow) {
                $choice = $choices[$index] ?? null;
                if ($choice === null) {
                    continue;
                }
                $this->set($sheet, $boxColumn, $templateRow, $mark(in_array($choice['key'], $selected, true)));
                $sheet->getStyle($boxColumn.$templateRow)->getFont()->setSize(12)->setBold(true);
                $sheet->getStyle($boxColumn.$templateRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

                $this->set($sheet, $labelColumn, $templateRow, $choice['label']);
                // D10:H10 through D14:H14 are already merged exactly like this in the reference
                // workbook — mergeCells() on an identical range is a safe no-op, so every row is
                // merged the same way here rather than special-casing those 5.
                $range = $labelColumn.$templateRow.':'.$labelEndColumn.$templateRow;
                $sheet->mergeCells($range);
                $sheet->getStyle($range)->getFont()->setSize(7);
                $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension($templateRow)->setRowHeight($uniformRowHeight);
            }
        };

        $business = array_values($groups['business'] ?? []);
        $writeChoices('C', 'D', 'H', range(10, 25), array_slice($business, 0, 16));
        $writeChoices('I', 'J', 'N', range(10, 24), array_slice($business, 16, 15));
        $writeChoices('O', 'P', 'T', range(10, 15), array_slice($business, 31));
        $writeChoices('O', 'P', 'T', range(17, 26), array_values($groups['agriculture'] ?? []));
        $writeChoices('U', 'V', 'Z', range(10, 14), array_values($groups['professional'] ?? []));
        $writeChoices('U', 'V', 'Z', range(16, 18), array_values($groups['remittance'] ?? []));
        $writeChoices('U', 'V', 'Z', range(21, 23), array_values($groups['employment'] ?? []));

        // J25 is a leftover sample-data cell in the reference workbook that sits just below
        // the J-group's own written range (J stops at row 24) — never overwritten by
        // writeChoices() above, so left alone it would leak the reference workbook's sample
        // text into every real download. C25 needs no such cleanup: it's the D-group's own
        // checkbox cell for row 25 ("Stl/Lotto Outlet"), already overwritten above.
        $sheet->getCellCollection()->delete('J25');

        // Row 26's bottom border is drawn explicitly across the checklist's whole B:AA width
        // (rather than trusting each cell's own pre-existing style to already agree), because
        // P26:T26 ("Fish Peddling"'s label) is a merge created here, not one that was already in
        // the reference workbook — every cell it merges already carried the same bottom border,
        // but this guarantees one uniform line with no risk of any cell rendering out of step
        // with its neighbors. B26's left and AA26's right are reasserted too, so both outer
        // sides still meet the bottom line cleanly at the corners.
        $sheet->getStyle('B26:AA26')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('B26')->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle('AA26')->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);

        $this->set($sheet, 'B', 27, 'OTHER REMARKS:');
        $sheet->getStyle('B27')->getFont()->setBold(true);
        $remarks = filled($report?->report_remarks) ? $report->report_remarks : 'N/A';
        $this->set($sheet, 'B', 28, $remarks);
        $sheet->getStyle('B28')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        // The reference workbook already merges the remarks value into a single B28:AA30 block
        // (the same left/right width as the checklist above it) but never draws a border around
        // it — unlike Print/PDF, which already boxes this section. Only the outline is drawn
        // (not every internal gridline) so it reads as one clean bordered box.
        $sheet->getStyle('B28:AA30')->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
        // The reference's own 3-row block (row 28's explicit 15.75pt height plus rows 29-30's
        // ~15pt defaults) already fits several wrapped lines, and Excel never auto-fits a merged
        // cell's row height regardless — so this only grows row 30 (the last of the 3), and only
        // once the remarks text actually needs more than that default block provides, estimated
        // from its length against the merge's ~110 character-per-line capacity at this width.
        $remarksLines = max(1, (int) ceil(mb_strlen($remarks) / 110));
        if ($remarksLines > 3) {
            $sheet->getRowDimension(30)->setRowHeight(($remarksLines - 2) * 15);
        }

        // The reference workbook defines column widths all the way out to BY even though this
        // section's real content and its own print area never go past AB — getHighestColumn()
        // picks up those far-right column definitions regardless of content, so it must not be
        // used here, or the exported print area balloons far past the report's intended right
        // boundary. AF9:AF26 is also a fully-bordered but entirely empty column left over from
        // an earlier version of this sheet, sitting outside the checklist and outside the
        // reference workbook's own print area — removed the same way this exporter already
        // removes other stray leftover cells (see the AD-column notes in
        // injectLeasingTruckEquipmentSection() and elsewhere).
        foreach (range(9, 26) as $templateRow) {
            $sheet->getCellCollection()->delete('AF'.$templateRow);
        }
        // Print area ends at row 30, the last row of the restored remarks box.
        $sheet->getPageSetup()->setPrintArea('B2:AB30');
        $sheet->setSelectedCells('A1');
        $sheet->setTopLeftCell('A1');
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function injectHeader(Worksheet $sheet, ClientFolder $folder, IncomeSource $source, bool $showCommonHeader = true): void
    {
        // In a batch workbook, every sheet after the first belongs to the same client/co-maker
        // — repeating CI in Charge / Branch / dates / name / officer / Borrower-Co-Maker marks /
        // Amount Applied on each one is redundant, so those cells are left blank instead of
        // carrying the reference template's own stale sample text. Individual downloads always
        // pass the default true and keep the full header exactly as before.
        if (! $showCommonHeader) {
            $this->set($sheet, 'G', 6, '');
            $this->set($sheet, 'T', 6, '');
            $this->set($sheet, 'O', 7, '');
            $this->set($sheet, 'G', 7, '');
            $this->set($sheet, 'T', 7, '');
            $this->set($sheet, 'G', 8, '');
            $this->set($sheet, 'T', 8, '');
            $this->set($sheet, 'C', 9, '');
            $this->set($sheet, 'G', 9, '');
            $this->set($sheet, 'T', 9, '');

            return;
        }

        $report = $source->businessReport;
        $cibiReport = $folder->cibiReport()->where('co_maker_id', $source->co_maker_id)->first();
        $folder->loadMissing('assignedInvestigator:id,full_name');

        $this->setOrNa($sheet, 'G', 6, mb_strtoupper((string) $folder->assignedInvestigator?->full_name));
        $this->setOrNa($sheet, 'T', 6, $source->branch_name ?: $cibiReport?->branch_name);
        $this->setDate($sheet, 'G', 7, $report?->start_date);
        // O7 is a single (unmerged) label cell reading "NAME OF APPLICANT:" in the reference
        // workbook — overwritten here only when the source belongs to a Co-Maker, so the
        // Applicant case keeps the template's own text untouched.
        $this->set($sheet, 'O', 7, $source->co_maker_id ? 'NAME OF CO-MAKER:' : 'NAME OF APPLICANT:');
        $this->set($sheet, 'T', 7, $source->applicant_name_snapshot ?: $folder->display_name);
        $this->setDate($sheet, 'G', 8, $report?->submitted_date);
        $this->setOrNa($sheet, 'T', 8, $source->account_officer_name ?: $cibiReport?->account_officer_name);
        // Derived from the business report's own owner (co_maker_id), not the linked CI/BI
        // report's stored party_type column — see the matching note in
        // OfficialReportDataBuilder::cibi().
        $partyType = $source->co_maker_id ? 'co_maker' : 'borrower';
        $this->set($sheet, 'C', 9, '('.($partyType === 'borrower' ? $this->check() : '   ').') BORROWER');
        $this->set($sheet, 'G', 9, '('.($partyType === 'co_maker' ? $this->check() : '   ').') CO-MAKER');
        $this->setOrNa($sheet, 'T', 9, $cibiReport?->amount_applied);
    }

    /** Exact, verified cell coordinates for the Retail Grocery/Sari-Sari/Water Refilling section. */
    private function injectRetailSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;

        $this->setOrNa($sheet, 'G', $row(255), $report->business_name);
        $this->setOrNa($sheet, 'Y', $row(255), $report->year_established);
        $this->setOrNa($sheet, 'G', $row(256), $report->main_business_address);
        $this->setOrNa($sheet, 'Y', $row(256), $report->length_of_stay_months);
        $sheet->getStyle('Y'.$row(256))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->set($sheet, 'C', $row(257), $this->ownershipChoiceText($report->ownership_type));
        $this->setOrNa($sheet, 'O', $row(257), $report->rented_from);
        $this->setOrNa($sheet, 'Y', $row(257), $report->monthly_rent);
        $this->setOrNa($sheet, 'G', $row(258), $report->previous_business_address);
        $this->setOrNa($sheet, 'Y', $row(258), $report->previous_business_address_length_of_stay);
        $sheet->getStyle('Y'.$row(258))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->setOrNa($sheet, 'G', $row(259), $report->reason_for_transfer);
        $this->setOrNa($sheet, 'Y', $row(259), $report->informant);
        $this->setOrNa($sheet, 'G', $row(260), $report->registered_owner);
        $this->setOrNa($sheet, 'W', $row(260), $report->relationship_to_borrower);
        $this->set($sheet, 'G', $row(262), $this->scaleChoiceText($report->scale));
        $this->setOrNa($sheet, 'G', $row(263), $report->branches_declared);
        $this->setOrNa($sheet, 'M', $row(263), $report->branches_inspected);
        $this->setOrNa($sheet, 'S', $row(263), $report->branches_not_inspected);
        $this->setOrNa($sheet, 'V', $row(263), $report->branches_reason_not_inspected);

        $branches = $report->branches->sortBy('sort_order')->values();
        $branchColumns = ['C' => 'location', 'G' => 'frontage_meters', 'H' => 'total_area_square_meters', 'I' => 'is_air_conditioned', 'J' => 'operating_days_hours', 'M' => 'shifts_count', 'N' => 'employees_per_shift', 'P' => 'average_sales_per_shift', 'R' => 'inventory_level', 'T' => 'monthly_rent', 'V' => 'years_in_area', 'X' => 'nearby_brands'];
        foreach ([266, 267, 268] as $index => $templateRow) {
            $branch = $branches->get($index);
            foreach ($branchColumns as $column => $field) {
                $value = $branch === null ? null : ($field === 'is_air_conditioned' ? $this->yesNo($branch->$field) : $branch->$field);
                $this->set($sheet, $column, $row($templateRow), $value);
            }
        }

        $products = $report->products->sortBy('sort_order')->values();
        foreach ([271, 272, 273, 274] as $index => $templateRow) {
            $product = $products->get($index);
            $this->set($sheet, 'C', $row($templateRow), $product?->product_name);
            $this->set($sheet, 'G', $row($templateRow), $product?->selling_price);
            $sheet->getStyle('G'.$row($templateRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $observations = $report->observations->sortBy('sort_order')->values();
        foreach (range(271, 277) as $index => $templateRow) {
            $this->set($sheet, 'R', $row($templateRow), $observations->get($index)?->answer);
        }

        $supplier = $report->suppliers->sortBy('sort_order')->first();
        $this->set($sheet, 'C', $row(280), $supplier?->supplier_name);
        $this->set($sheet, 'G', $row(280), $supplier?->office_location);
        $this->set($sheet, 'L', $row(280), $supplier ? $this->yesNo($supplier->is_confirmed) : null);
        $this->set($sheet, 'M', $row(280), $supplier ? trim(($supplier->payment_performance ?? '').' '.($supplier->contact_information ?? '').' '.($supplier->years_transacting ? $supplier->years_transacting.' yrs' : '').' '.($supplier->remarks ?? '')) : null);

        $this->setRemarks($sheet, 'F', $row(284), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Leasing Operations (Truck or Equipment)
     * section. The reference workbook has no separate column for the "# of Operators /
     * Drivers / Helpers" counts on their shared row with the supplier category labels, so
     * each count is written as "# of Operators: 5" alongside the existing label text in
     * column C — the same technique used elsewhere for combined label/choice cells.
     */
    private function injectLeasingTruckEquipmentSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $data = (array) $report->template_data;

        // Row 20 carries an internal reviewer note ("<---check the ORCR during inspection of
        // unit") in a stray cell (AD) well outside this section's C:AA grid — outside the
        // columns every set()/setOrNa() call below ever touches, and outside what Print/PDF
        // reads at all, so it's never overwritten by normal data injection. Left in place it
        // still counts toward this row's (and so the whole trimmed sheet's) highest column,
        // widening the exported print area enough to expose it. setCellValue(..., null) only
        // clears the value, not the cell's presence in that calculation, so the cell itself is
        // removed from the sheet instead — a no-op for genuine data cells, since none of this
        // template's fields are ever mapped to column AD.
        $sheet->getCellCollection()->delete('AD'.$row(20));

        $this->injectProfileBlock($sheet, $row, 10, $report);
        $sheet->getStyle('Y'.$row(11))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $this->setOrNa($sheet, 'G', $row(18), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $row(18), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $row(18), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $row(18), data_get($data, 'fields.reason_not_inspected'));

        $units = (array) data_get($data, 'tables.units', []);
        $unitColumns = ['C' => 'truck_equipment_type', 'G' => 'rented_by', 'M' => 'current_location', 'R' => 'units_declared', 'T' => 'units_validated', 'V' => 'rental_rate', 'Z' => 'has_contract'];
        foreach (range(21, 26) as $index => $templateRow) {
            $unit = $units[$index] ?? null;
            foreach ($unitColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $unit[$field] ?? null);
            }
        }

        $suppliers = (array) data_get($data, 'tables.suppliers', []);
        $supplierCategories = ['Main Fuel Supplier:', 'Repair & Maintenance', 'Main Supplier/Lender:'];
        $employeeCountFields = ['operators_count', 'drivers_count', 'helpers_count'];
        foreach ([28, 29, 30] as $index => $templateRow) {
            $supplier = $suppliers[$index] ?? null;
            $this->set($sheet, 'E', $row($templateRow), data_get($data, "fields.{$employeeCountFields[$index]}"));
            $this->set($sheet, 'G', $row($templateRow), $supplier['supplier_category'] ?? $supplierCategories[$index]);
            $this->set($sheet, 'K', $row($templateRow), $supplier['supplier_name'] ?? null);
            $this->set($sheet, 'P', $row($templateRow), $supplier['office_location'] ?? null);
            $this->set($sheet, 'U', $row($templateRow), $supplier['years_transacting'] ?? null);
            $this->set($sheet, 'W', $row($templateRow), $supplier['payment_performance'] ?? null);
        }

        $this->setRemarks($sheet, 'F', $row(32), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Leasing Operations: Non-Agricultural Real
     * Estate section. This template's profile block matches every other template's rows
     * 1-5, but row 6 replaces the usual "relationship to borrower" cell with a "Leasing
     * Business Registered / Not Registered" choice, so the shared injectProfileBlock() is
     * not reused here — rows 1-5 are duplicated instead of risking a change to the shared
     * helper that every other mapped template already relies on.
     */
    private function injectLeasingNonAgriculturalSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(33 + $lineOffset);

        $this->setOrNa($sheet, 'G', $r(1), $report->business_name);
        $this->setOrNa($sheet, 'Y', $r(1), $report->year_established);
        $this->setOrNa($sheet, 'G', $r(2), $report->main_business_address);
        $this->setOrNa($sheet, 'Y', $r(2), $report->length_of_stay_months);
        $sheet->getStyle('Y'.$r(2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->set($sheet, 'C', $r(3), $this->ownershipChoiceText($report->ownership_type));
        $this->setOrNa($sheet, 'O', $r(3), $report->rented_from);
        $this->setOrNa($sheet, 'Y', $r(3), $report->monthly_rent);
        $this->setOrNa($sheet, 'G', $r(4), $report->previous_business_address);
        $this->setOrNa($sheet, 'Y', $r(4), $report->previous_business_address_length_of_stay);
        $sheet->getStyle('Y'.$r(4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->setOrNa($sheet, 'G', $r(5), $report->reason_for_transfer);
        $this->setOrNa($sheet, 'Y', $r(5), $report->informant);
        $this->setOrNa($sheet, 'G', $r(6), $report->registered_owner);
        $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(   )';
        $this->set($sheet, 'O', $r(6), $mark(strcasecmp((string) $report->business_type, 'Registered') === 0).' LEASING BUSINESS REGISTERED   '.$mark(strcasecmp((string) $report->business_type, 'Not Registered') === 0).' LEASING BUSINESS NOT REGISTERED');

        $properties = $report->properties->sortBy('sort_order')->values();
        $this->setOrNa($sheet, 'G', $r(8), $report->properties_declared);
        $this->setOrNa($sheet, 'M', $r(8), $report->properties_inspected);
        $this->setOrNa($sheet, 'S', $r(8), max(0, (int) $report->properties_declared - (int) $report->properties_inspected));
        $this->setOrNa($sheet, 'V', $r(8), $properties->where('is_inspected', false)->pluck('reason_not_inspected')->filter()->implode('; '));

        $propertyTypeText = function (?string $type): string {
            $normalized = mb_strtolower((string) $type);
            $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(   )';

            return $mark(str_contains($normalized, 'warehouse')).' WAREHOUSE   '.$mark(str_contains($normalized, 'comm')).' COMM\'L   '.$mark(str_contains($normalized, 'res')).' RES\'L';
        };
        foreach (range(44, 48) as $index => $templateRow) {
            $property = $properties->get($index);
            $location = $property === null ? null : trim($property->location.(filled($property->area_square_meters) ? ' ('.$property->area_square_meters.' sqm)' : ''));
            $tenantInformation = $property === null ? null : (filled($property->remarks) ? $property->remarks : $property->tenants->map(fn ($tenant) => collect([
                $tenant->tenant_name, filled($tenant->monthly_rent) ? 'PHP '.$tenant->monthly_rent : null, filled($tenant->years_renting) ? $tenant->years_renting.' years' : null,
            ])->filter()->implode(' / '))->filter()->implode('; '));
            $this->set($sheet, 'C', $row($templateRow), $property === null ? null : $propertyTypeText($property->property_type));
            $this->set($sheet, 'H', $row($templateRow), $property === null ? null : $this->yesNo($property->is_inspected));
            $this->set($sheet, 'J', $row($templateRow), $property?->units_available);
            $this->set($sheet, 'L', $row($templateRow), $property?->units_with_tenants);
            $this->set($sheet, 'N', $row($templateRow), $location);
            $this->set($sheet, 'S', $row($templateRow), $tenantInformation);
            $this->set($sheet, 'Z', $row($templateRow), $property === null ? null : $this->yesNo($property->has_contract));
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // cell (AD) outside this section's C:AA grid, never written to by setRemarks() below
        // — the same leftover-note pattern as Leasing (Truck/Equipment)'s AD20 (see
        // injectLeasingTruckEquipmentSection()). setCellValue(..., null) only clears the
        // value, not the cell's presence in the sheet's highest-column calculation, so the
        // cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(17));

        $this->setRemarks($sheet, 'F', $r(17), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Leasing Operations: Agricultural Real Estate
     * section. This template has no profile block ("profile" is false in its schema — no
     * Business Name / Main Address rows exist in the reference workbook for this section),
     * so it goes straight from the section title to the properties-declared summary.
     */
    private function injectLeasingAgriculturalSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(51 + $lineOffset);
        $data = (array) $report->template_data;

        $this->setOrNa($sheet, 'G', $r(2), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(2), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(2), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(2), data_get($data, 'fields.reason_not_inspected'));

        $properties = (array) data_get($data, 'tables.properties', []);
        $propertyColumns = ['C' => 'location_area', 'H' => 'tenant', 'N' => 'annual_rent', 'P' => 'has_contract', 'R' => 'planted_crop', 'T' => 'tenant_contact', 'W' => 'relevant_information'];
        foreach (range(56, 60) as $index => $templateRow) {
            $property = $properties[$index] ?? null;
            foreach ($propertyColumns as $column => $field) {
                $value = $property[$field] ?? null;
                $this->set($sheet, $column, $row($templateRow), $value);
                if ($column === 'T') {
                    // Contact numbers are legitimately text (a leading 0, as in a PH mobile
                    // number, would be lost if stored as a real number), but Excel's "Number
                    // Stored as Text" check still flags any all-digit text value regardless —
                    // a well-known false positive for this exact kind of field. Suppressed only
                    // on this column, and only when the value would actually trigger it.
                    $this->suppressNumberStoredAsTextWarning($sheet, 'T'.$row($templateRow), $value);
                }
            }
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // cell (AD) outside this section's C:AA grid, never written to by setRemarks() below
        // — the same leftover-note pattern as Leasing (Truck/Equipment)'s AD20 and Leasing:
        // Non-Agricultural Real Estate's AD50. setCellValue(..., null) only clears the value,
        // not the cell's presence in the sheet's highest-column calculation, so the cell
        // itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(11));

        $this->setRemarks($sheet, 'F', $r(11), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Leasing of Poultry Farm Operations section.
     * Structurally identical to the Agricultural Real Estate section above (no profile
     * block), but its table is keyed "farms" in template_data, not "properties".
     */
    private function injectLeasingPoultryFarmSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(63 + $lineOffset);
        $data = (array) $report->template_data;

        $this->setOrNa($sheet, 'G', $r(2), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(2), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(2), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(2), data_get($data, 'fields.reason_not_inspected'));

        $farms = (array) data_get($data, 'tables.farms', []);
        $farmColumns = ['C' => 'location_area', 'H' => 'lessor', 'N' => 'has_contract', 'P' => 'farm_buildings', 'R' => 'heads_per_building', 'T' => 'production_type', 'W' => 'relevant_information'];
        foreach (range(68, 72) as $index => $templateRow) {
            $farm = $farms[$index] ?? null;
            foreach ($farmColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $farm[$field] ?? null);
            }
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // cell (AD) outside this section's C:AA grid, never written to by setRemarks() below
        // — the same leftover-note pattern as Leasing (Truck/Equipment)'s AD20, Leasing:
        // Non-Agricultural Real Estate's AD50, and Leasing: Agricultural Real Estate's AD62.
        // setCellValue(..., null) only clears the value, not the cell's presence in the
        // sheet's highest-column calculation, so the cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(11));

        $this->setRemarks($sheet, 'F', $r(11), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Taxi Operator section. This template's
     * profile block is shorter than most (its schema hides previous address, reason for
     * transfer, informant and registered owner), so only rows 1-3 of the usual profile
     * shape apply before the LTFRB-specific row — the shared injectProfileBlock() is not
     * reused here since it always writes all 6 profile rows.
     */
    private function injectTaxiOperatorSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(75 + $lineOffset);
        $data = (array) $report->template_data;

        $this->setOrNa($sheet, 'G', $r(1), $report->business_name);
        $this->setOrNa($sheet, 'Y', $r(1), $report->year_established);
        $sheet->getStyle('Y'.$r(1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->setOrNa($sheet, 'G', $r(2), $report->main_business_address);
        $this->setOrNa($sheet, 'Y', $r(2), $report->length_of_stay_months);
        $sheet->getStyle('Y'.$r(2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->set($sheet, 'C', $r(3), $this->ownershipChoiceText($report->ownership_type));
        $this->setOrNa($sheet, 'O', $r(3), $report->rented_from);
        $this->setOrNa($sheet, 'Y', $r(3), $report->monthly_rent);
        // The ownership/mortgage row carries an internal reviewer note ("<----LTFRB
        // FRANCHISE DATA AND DOCUMENTS CARE OF CREDIT ANALYST (REQUIRED TO BE SUBMITTED BY
        // CLIENT)") in a stray cell (AD) outside this section's C:AA grid, never written to
        // above — verified against the reference workbook to sit here (row 78), not on the
        // LTFRB fields row below it despite the note's own wording. Removed the same way as
        // every other section's leftover note (see AD20 in
        // injectLeasingTruckEquipmentSection()) — setCellValue(..., null) only clears the
        // value, not the cell's presence in the sheet's highest-column calculation, so the
        // cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(3));

        $ltfrbDate = data_get($data, 'fields.ltfrb_research_date');
        $this->set($sheet, 'C', $r(4), filled($ltfrbDate) ? 'LTFRB RESEARCH (date): '.$ltfrbDate : null);
        $this->set($sheet, 'L', $r(4), data_get($data, 'fields.franchise_fee'));
        $this->set($sheet, 'R', $r(4), data_get($data, 'fields.minimum_year_model'));
        $this->set($sheet, 'Y', $r(4), data_get($data, 'fields.minimum_units'));

        $this->setOrNa($sheet, 'G', $r(6), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(6), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(6), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(6), data_get($data, 'fields.reason_not_inspected'));

        $units = (array) data_get($data, 'tables.units', []);
        $unitColumns = ['C' => 'brand_model', 'G' => 'year_model', 'I' => 'plate_number', 'K' => 'with_driver', 'M' => 'operating_since', 'O' => 'daily_boundary', 'R' => 'franchise_operator', 'Y' => 'area_parked'];
        foreach (range(84, 87) as $index => $templateRow) {
            $unit = $units[$index] ?? null;
            foreach ($unitColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $unit[$field] ?? null);
            }
        }

        // The remarks row carries the same kind of internal reviewer note ("<---SAMPLE:
        // CONTRACT WON'T BE RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER
        // PROPERTY") in a stray AD cell — see the AD'.$r(4) removal above.
        $sheet->getCellCollection()->delete('AD'.$r(14));

        $this->setRemarks($sheet, 'F', $r(14), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the PUJ/Van/Jeepney Replacement Operator
     * section. Structurally identical to the Taxi Operator section above (same shortened
     * profile block, same LTFRB row, same counts row), but its units table has 9 columns
     * (adds "route" and "average fare", has no "area parked" column) across 5 data rows
     * instead of 4.
     */
    private function injectPujVanJeepneySection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(90 + $lineOffset);
        $data = (array) $report->template_data;

        $this->setOrNa($sheet, 'G', $r(1), $report->business_name);
        $this->setOrNa($sheet, 'Y', $r(1), $report->year_established);
        $sheet->getStyle('Y'.$r(1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->setOrNa($sheet, 'G', $r(2), $report->main_business_address);
        $this->setOrNa($sheet, 'Y', $r(2), $report->length_of_stay_months);
        $sheet->getStyle('Y'.$r(2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->set($sheet, 'C', $r(3), $this->ownershipChoiceText($report->ownership_type));
        $this->setOrNa($sheet, 'O', $r(3), $report->rented_from);
        $this->setOrNa($sheet, 'Y', $r(3), $report->monthly_rent);
        // The ownership/mortgage row carries an internal reviewer note ("<----LTFRB
        // FRANCHISE DATA AND DOCUMENTS CARE OF CREDIT ANALYST (REQUIRED TO BE SUBMITTED BY
        // CLIENT)") in a stray cell (AD) outside this section's C:AA grid, never written to
        // above — same row and same fix as Taxi Operator's equivalent note (see
        // injectTaxiOperatorSection()). setCellValue(..., null) only clears the value, not
        // the cell's presence in the sheet's highest-column calculation, so the cell itself
        // is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(3));

        $ltfrbDate = data_get($data, 'fields.ltfrb_research_date');
        $this->set($sheet, 'C', $r(4), filled($ltfrbDate) ? 'LTFRB RESEARCH (date): '.$ltfrbDate : null);
        $this->set($sheet, 'L', $r(4), data_get($data, 'fields.franchise_fee'));
        $this->set($sheet, 'R', $r(4), data_get($data, 'fields.minimum_year_model'));
        $this->set($sheet, 'Y', $r(4), data_get($data, 'fields.minimum_units'));

        $this->setOrNa($sheet, 'G', $r(6), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(6), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(6), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(6), data_get($data, 'fields.reason_not_inspected'));

        $units = (array) data_get($data, 'tables.units', []);
        $unitColumns = ['C' => 'brand_model', 'G' => 'year_model', 'I' => 'plate_number', 'K' => 'with_driver', 'M' => 'operating_since', 'O' => 'daily_boundary', 'Q' => 'route', 'T' => 'average_fare', 'V' => 'franchise_operator'];
        foreach (range(99, 103) as $index => $templateRow) {
            $unit = $units[$index] ?? null;
            foreach ($unitColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $unit[$field] ?? null);
            }
        }

        // The remarks row carries the same kind of internal reviewer note ("<---SAMPLE:
        // CONTRACT WON'T BE RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER
        // PROPERTY") in a stray AD cell — see the AD'.$r(3) removal above.
        $sheet->getCellCollection()->delete('AD'.$r(15));

        $this->setRemarks($sheet, 'F', $r(15), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Trucking Services section. Unlike Taxi and
     * PUJ/Van/Jeepney, this template's profile block has no hidden fields (matches the
     * shared injectProfileBlock() exactly, verified against this section's own merges), so
     * it's reused directly. The units table has a two-row grouped header ("Interview with
     * Driver" / "Actual OR/CR Checking"); the employees/suppliers block mirrors Leasing
     * (Truck or Equipment)'s, including its dedicated E:F count-value cell (not combined
     * into the label cell — see injectLeasingTruckEquipmentSection()).
     */
    private function injectTruckingServicesSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(106 + $lineOffset);
        $data = (array) $report->template_data;

        $this->injectProfileBlock($sheet, $row, 106, $report);
        $sheet->getStyle('Y'.$r(1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $this->setOrNa($sheet, 'G', $r(8), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(8), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(8), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(8), data_get($data, 'fields.reason_not_inspected'));

        $units = (array) data_get($data, 'tables.units', []);
        $unitColumns = ['C' => 'brand_model', 'G' => 'year_model', 'I' => 'plate_number', 'K' => 'years_employed', 'M' => 'goods_transported', 'R' => 'pickup_delivery_areas', 'V' => 'registered_owner', 'Y' => 'encumbrances'];
        foreach (range(118, 122) as $index => $templateRow) {
            $unit = $units[$index] ?? null;
            foreach ($unitColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $unit[$field] ?? null);
            }
        }

        $suppliers = (array) data_get($data, 'tables.suppliers', []);
        $supplierCategories = ['Main Fuel Supplier:', 'Repair & Maintenance', 'Main Supplier/Lender:'];
        $employeeCountFields = ['operators_count', 'drivers_count', 'helpers_count'];
        foreach ([125, 126, 127] as $index => $templateRow) {
            $supplier = $suppliers[$index] ?? null;
            $this->set($sheet, 'E', $row($templateRow), data_get($data, "fields.{$employeeCountFields[$index]}"));
            $this->set($sheet, 'G', $row($templateRow), $supplier['supplier_category'] ?? $supplierCategories[$index]);
            $this->set($sheet, 'K', $row($templateRow), $supplier['supplier_name'] ?? null);
            $this->set($sheet, 'P', $row($templateRow), $supplier['office_location'] ?? null);
            $this->set($sheet, 'U', $row($templateRow), $supplier['years_transacting'] ?? null);
            $this->set($sheet, 'W', $row($templateRow), $supplier['payment_performance'] ?? null);
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // cell (AD) outside this section's C:AA grid, never written to by setRemarks() below
        // — the same leftover-note pattern as every other section's (see AD20 in
        // injectLeasingTruckEquipmentSection()). setCellValue(..., null) only clears the
        // value, not the cell's presence in the sheet's highest-column calculation, so the
        // cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(23));

        $this->setRemarks($sheet, 'F', $r(23), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Distributorship/Panel/Wholesaler/B2B
     * Retailer section — the most complex mapped section: a shared profile block, a 4-row
     * office/warehouse/stockroom grid (employee counts, a single inventory-level choice,
     * two product checkboxes per row, and four parallel top-brand/selling-price rows), the
     * units-inspected summary, a two-row grouped units table (matching Trucking Services'
     * shape), and finally six rows that combine an interview question/answer on the left
     * with a Top Sellable Products table row on the right.
     */
    private function injectDistributorshipSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(130 + $lineOffset);
        $data = (array) $report->template_data;
        $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(   )';
        $normalize = fn (string $value): string => preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper($value));

        $this->injectProfileBlock($sheet, $row, 130, $report);
        $sheet->getStyle('Y'.$r(1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $selectedProducts = collect(preg_split('/\s*,\s*/', (string) data_get($data, 'fields.products_seen'), -1, PREG_SPLIT_NO_EMPTY))->map($normalize);
        $inventoryLevel = mb_strtoupper((string) data_get($data, 'fields.inventory_level'));
        $splitStockroomValues = function (?string $value): array {
            $rows = preg_split('/\R/', (string) $value);
            if (count($rows) <= 1 && str_contains((string) $value, ',')) {
                $rows = preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
            }

            return array_pad(array_slice(array_map('trim', $rows ?: []), 0, 4), 4, '');
        };
        $brandRows = $splitStockroomValues(data_get($data, 'fields.top_brands'));
        $priceRows = $splitStockroomValues(data_get($data, 'fields.top_brand_prices'));
        $stockroomRows = [
            139 => ['employee_key' => 'office_staff', 'inventory' => 'HIGH', 'products' => ['PHARMACEUTICALS', 'FRESH/REFRIGERATED GOODS']],
            140 => ['employee_key' => 'agents', 'inventory' => 'MODERATE', 'products' => ['BEVERAGES', 'EQUIPMENT/DEVICES/MACHINES']],
            141 => ['employee_key' => 'drivers', 'inventory' => 'LOW', 'products' => ['CONSTRUCTION MATS.', 'HOME/CONSUMER GOODS']],
            142 => ['employee_key' => 'helpers', 'inventory' => 'NONE', 'products' => ['PACKAGED DRY FOOD', 'AGRI FEEDS/FERTILIZER/SEEDS']],
        ];
        $index = 0;
        foreach ($stockroomRows as $templateRow => $stockroomRow) {
            $this->set($sheet, 'F', $row($templateRow), data_get($data, "fields.{$stockroomRow['employee_key']}"));
            $this->set($sheet, 'I', $row($templateRow), $mark($inventoryLevel === $stockroomRow['inventory']).' '.$stockroomRow['inventory']);
            $this->set($sheet, 'L', $row($templateRow), $mark($selectedProducts->contains($normalize($stockroomRow['products'][0]))).' '.$stockroomRow['products'][0]);
            $this->set($sheet, 'P', $row($templateRow), $mark($selectedProducts->contains($normalize($stockroomRow['products'][1]))).' '.$stockroomRow['products'][1]);
            $this->set($sheet, 'T', $row($templateRow), $brandRows[$index]);
            $this->set($sheet, 'X', $row($templateRow), $priceRows[$index]);
            $index++;
        }

        $this->setOrNa($sheet, 'G', $r(14), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(14), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(14), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(14), data_get($data, 'fields.reason_not_inspected'));

        $units = (array) data_get($data, 'tables.units', []);
        $unitColumns = ['C' => 'brand_model', 'G' => 'year_model', 'I' => 'plate_number', 'K' => 'years_employed', 'M' => 'goods_transported', 'R' => 'areas_territories', 'V' => 'registered_owner', 'Y' => 'encumbrances'];
        foreach (range(148, 152) as $index => $templateRow) {
            $unit = $units[$index] ?? null;
            foreach ($unitColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $unit[$field] ?? null);
            }
        }

        $questionAnswer = fn (int $index) => (string) data_get($data, "questions.$index");
        $this->set($sheet, 'K', $r(25), $questionAnswer(0));
        $this->set($sheet, 'K', $r(26), $questionAnswer(1));
        $answer2 = mb_strtoupper($questionAnswer(2));
        $this->set($sheet, 'K', $r(27), collect(['1X', '2X', '3X', '4X'])->map(fn ($option) => $mark($answer2 === $option).' '.$option)->implode('  '));
        $this->set($sheet, 'K', $r(28), $questionAnswer(3));
        $answer4 = mb_strtoupper($questionAnswer(4));
        $this->set($sheet, 'K', $r(29), collect(['BANK DEPOSIT', 'REMITTANCE AGENT', 'OFFICE'])->map(fn ($option) => $mark($answer4 === $option).' '.$option)->implode('  '));
        $answer5Raw = $questionAnswer(5);
        $answer5 = mb_strtoupper($answer5Raw);
        $isTerm = str_starts_with($answer5, 'TERM:');
        $term = $isTerm ? trim(substr($answer5Raw, 5)) : '';
        $this->set($sheet, 'K', $r(30), $mark($answer5 === 'COD').' COD  '.$mark($answer5 === 'COLLECT ON NEXT DELIVERY').' COLLECT ON NEXT DELIVERY  '.$mark($isTerm).' TERM:'.($term !== '' ? ' '.$term : ' _____'));

        $products = (array) data_get($data, 'tables.products', []);
        foreach (range(155, 160) as $index => $templateRow) {
            $product = $products[$index] ?? null;
            $this->set($sheet, 'R', $row($templateRow), $product['product'] ?? null);
            $this->set($sheet, 'V', $row($templateRow), $product['selling_price_per_unit'] ?? null);
            $this->set($sheet, 'Y', $row($templateRow), $product['sales_per_month'] ?? null);
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // cell (AD) outside this section's C:AA grid, never written to by setRemarks() below
        // — the same leftover-note pattern as every other section's (see AD20 in
        // injectLeasingTruckEquipmentSection()). setCellValue(..., null) only clears the
        // value, not the cell's presence in the sheet's highest-column calculation, so the
        // cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$r(32));

        $this->setRemarks($sheet, 'F', $r(32), $report->report_remarks);
    }

    /**
     * Populates a schema-driven "retail-shaped" section (see SCHEMA_GRID_SECTIONS): the
     * shared profile block, an optional store-type radio row, the branch-count summary, a
     * branches table, a combined products/observations grid, a suppliers table, then
     * remarks. Profile fields and remarks come from the saved BusinessReport columns;
     * everything else comes from the report's saved template_data (schema fields, table
     * rows, question answers) — never from the reference workbook's own sample content.
     */
    private function injectSchemaGridSection(Worksheet $sheet, int $offset, IncomeSource $source, array $layout): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $data = (array) $report->template_data;
        $schema = $source->template->businessReportSchema();

        $this->injectProfileBlock($sheet, $row, $layout['title_row'], $report);
        if (in_array($source->template_type, ['pharmacy_drugstore', 'general_merchandise_hardware_parts', 'buy_sell_dry_goods'], true)) {
            $sheet->getStyle('Y'.$row($layout['title_row'] + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        if (isset($layout['store_type_row'])) {
            $selected = (string) data_get($data, "fields.{$layout['store_type_field']}");
            $mark = fn (bool $isSelected): string => $isSelected ? '('.$this->check().')' : '(  )';
            $text = collect($layout['store_type_options'])->map(fn (string $option): string => $mark(strcasecmp($selected, $option) === 0).' '.mb_strtoupper($option))->implode('   ');
            $this->set($sheet, 'G', $row($layout['store_type_row']), $text);
        }

        $this->setOrNa($sheet, 'G', $row($layout['counts_row']), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $row($layout['counts_row']), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $row($layout['counts_row']), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $row($layout['counts_row']), data_get($data, 'fields.reason_not_inspected'));

        $branchRows = (array) data_get($data, 'tables.branches', []);
        for ($index = 0; $index < $layout['branches_rows']; $index++) {
            $templateRow = $layout['branches_header_row'] + 1 + $index;
            $branch = $branchRows[$index] ?? null;
            foreach (self::SCHEMA_BRANCH_COLUMNS as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $branch[$field] ?? null);
            }
        }

        $productRows = (array) data_get($data, 'tables.products', []);
        for ($index = 0; $index < $layout['grid_rows']; $index++) {
            $templateRow = $layout['grid_header_row'] + 1 + $index;
            if ($index < $layout['products_rows']) {
                $product = $productRows[$index] ?? null;
                $this->set($sheet, 'C', $row($templateRow), $product['product'] ?? null);
                $this->set($sheet, 'G', $row($templateRow), $product['selling_price'] ?? null);
                $sheet->getStyle('G'.$row($templateRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $answerIndex = $layout['question_order'][$index] ?? $index;
            $this->set($sheet, 'R', $row($templateRow), data_get($schema, "questions.$answerIndex") !== null ? data_get($data, "questions.$answerIndex") : null);
            if ($source->template_type === 'pharmacy_drugstore' && $answerIndex === 0) {
                $sheet->getStyle('R'.$row($templateRow))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);
            }
        }

        $supplierRows = (array) data_get($data, 'tables.suppliers', []);
        for ($index = 0; $index < $layout['suppliers_rows']; $index++) {
            $templateRow = $layout['suppliers_header_row'] + 1 + $index;
            $supplier = $supplierRows[$index] ?? null;
            $this->set($sheet, 'C', $row($templateRow), $supplier['supplier_name'] ?? null);
            $this->set($sheet, 'G', $row($templateRow), $supplier['office_location'] ?? null);
            $this->set($sheet, 'L', $row($templateRow), $supplier['confirmed'] ?? null);
            $this->set($sheet, 'M', $row($templateRow), $supplier['payment_performance'] ?? null);
        }

        $this->setRemarks($sheet, 'F', $row($layout['remarks_row']), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Meatshop Store section. Unlike the
     * SCHEMA_GRID_SECTIONS shape (a combined products/observations grid plus a separate
     * suppliers table), this section's "Products / Goods Seen in Inventory" table is a
     * single checkbox + fixed-product-type + supplier/location/price grid (schema table
     * "inventory"), and its 9 validation questions each get their own full-width
     * question/answer row instead of being paired with a products column.
     *
     * The edit form's checkbox (_business-meatshop-inventory-observations.blade.php) submits
     * the category name itself as "product_type" when checked and nothing at all when
     * unchecked, and unfilled rows are dropped and the table re-indexed on save — so rows
     * can't be matched by position, only by the saved product_type text.
     */
    private function injectMeatshopStoreSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(285 + $lineOffset);
        $data = (array) $report->template_data;
        // "Check all that apply" (product type) uses a clean checkmark only — no
        // parenthesis-style formatting — and stays blank when unselected.
        $mark = fn (bool $selected): string => $selected ? "\u{2713}" : '';

        $this->injectProfileBlock($sheet, $row, 285, $report);

        $this->setOrNa($sheet, 'G', $r(8), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(8), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(8), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(8), data_get($data, 'fields.reason_not_inspected'));

        $branchRows = (array) data_get($data, 'tables.branches', []);
        foreach ([296, 297, 298] as $index => $templateRow) {
            $branch = $branchRows[$index] ?? null;
            foreach (self::SCHEMA_BRANCH_COLUMNS as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $branch[$field] ?? null);
            }
        }

        $inventoryRows = collect((array) data_get($data, 'tables.inventory', []));
        $productTypeValues = ['Poultry: Chicken', 'Pork', 'Beef', 'Fish', 'Packaged/Canned Food', 'Other Products'];
        $inventoryColumns = ['G' => 'main_supplier', 'J' => 'location', 'N' => 'contact_number', 'R' => 'daily_kilos_sold', 'U' => 'price_range', 'X' => 'remarks'];
        foreach (range(302, 307) as $index => $templateRow) {
            $inventoryRow = $inventoryRows->first(fn ($candidate) => strcasecmp((string) data_get($candidate, 'product_type'), $productTypeValues[$index]) === 0) ?? [];
            $this->set($sheet, 'C', $row($templateRow), $mark(filled($inventoryRow['product_type'] ?? null)));
            foreach ($inventoryColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $inventoryRow[$field] ?? null);
            }
        }

        foreach (range(310, 318) as $index => $templateRow) {
            $this->set($sheet, 'O', $row($templateRow), data_get($data, "questions.$index"));
        }

        $this->setRemarks($sheet, 'F', $r(35), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Contractor / Subcontractor section. Its
     * project-count summary row uses the same G/M/S/V column shape as every other section's
     * branch-count row, but the projects table itself (7 columns) and the two separate
     * question blocks — "Observations During Business Inspection" (schema questions 0-4)
     * and "For Additional Validation" (schema questions 5-8), split by the suppliers table
     * in between — are unique to this section.
     */
    private function injectContractorSubcontractorSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(321 + $lineOffset);
        $data = (array) $report->template_data;

        $this->injectProfileBlock($sheet, $row, 321, $report);
        $sheet->getStyle('Y'.$r(1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $this->setOrNa($sheet, 'G', $r(8), data_get($data, 'fields.projects_declared'));
        $this->setOrNa($sheet, 'M', $r(8), data_get($data, 'fields.projects_inspected'));
        $this->setOrNa($sheet, 'S', $r(8), data_get($data, 'fields.projects_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(8), data_get($data, 'fields.reason_not_inspected'));

        $projects = (array) data_get($data, 'tables.projects', []);
        $projectColumns = ['C' => 'project_owner', 'I' => 'location', 'N' => 'government', 'O' => 'scope_of_work', 'T' => 'start_date', 'W' => 'target_completion_date', 'Z' => 'percent_completed'];
        foreach ([332, 333, 334] as $index => $templateRow) {
            $project = $projects[$index] ?? null;
            foreach ($projectColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $project[$field] ?? null);
            }
        }

        foreach (range(336, 340) as $index => $templateRow) {
            $this->set($sheet, 'O', $row($templateRow), data_get($data, "questions.$index"));
        }

        $suppliers = (array) data_get($data, 'tables.suppliers', []);
        foreach ([343, 344, 345] as $index => $templateRow) {
            $supplier = $suppliers[$index] ?? null;
            $this->set($sheet, 'C', $row($templateRow), $supplier['supplier_name'] ?? null);
            $this->set($sheet, 'G', $row($templateRow), $supplier['office_location'] ?? null);
            $this->set($sheet, 'L', $row($templateRow), $supplier['confirmed'] ?? null);
            $this->set($sheet, 'M', $row($templateRow), $supplier['payment_performance'] ?? null);
        }

        foreach (range(347, 350) as $index => $templateRow) {
            $this->set($sheet, 'O', $row($templateRow), data_get($data, 'questions.'.($index + 5)));
        }

        $this->setRemarks($sheet, 'F', $r(31), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Restaurant / Cafeteria / Carenderia / Food
     * Stall section. Its branches table shares the same column shape as
     * SCHEMA_BRANCH_COLUMNS (branch-counts row included), except the last column is "IN-
     * STORE DINING CAPACITY" (schema field "dining_capacity") instead of "nearby_brands" —
     * so it's mapped locally rather than reusing the shared constant. The "Scale of
     * Business" row has two options sharing the label "RESTAURANT" (a standalone one and a
     * "/ MALL OPERATIONS:" one), so each is matched by its schema option value, not label.
     * Its 8 validation questions each get their own full-width question/answer row (no
     * products/suppliers table), the same shape as the Meatshop and Contractor sections.
     */
    private function injectRestaurantFoodStallSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $r = fn (int $lineOffset): int => $row(353 + $lineOffset);
        $data = (array) $report->template_data;
        $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(  )';

        $this->injectProfileBlock($sheet, $row, 353, $report);
        $sheet->getStyle('Y'.$r(1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $scale = (string) data_get($data, 'fields.scale_of_business');
        $scaleOptions = ['Restaurant' => 'RESTAURANT', 'Carenderia' => 'CARENDERIA', 'Cafeteria' => 'CAFETERIA', 'Stall' => 'STALL'];
        $mallOptions = ['Mall - Restaurant' => 'RESTAURANT', 'Mall - Stall Only' => 'STALL ONLY'];
        $text = collect($scaleOptions)->map(fn (string $label, string $value): string => $mark($scale === $value).' '.$label)->implode('   ')
            .'   / MALL OPERATIONS:   '
            .collect($mallOptions)->map(fn (string $label, string $value): string => $mark($scale === $value).' '.$label)->implode('   ');
        $this->set($sheet, 'G', $r(7), $text);

        $this->setOrNa($sheet, 'G', $r(8), data_get($data, 'fields.total_declared'));
        $this->setOrNa($sheet, 'M', $r(8), data_get($data, 'fields.total_inspected'));
        $this->setOrNa($sheet, 'S', $r(8), data_get($data, 'fields.total_not_inspected'));
        $this->setOrNa($sheet, 'V', $r(8), data_get($data, 'fields.reason_not_inspected'));

        $branchRows = (array) data_get($data, 'tables.branches', []);
        $branchColumns = ['C' => 'location', 'G' => 'frontage', 'H' => 'total_area', 'I' => 'air_conditioned', 'J' => 'operating_days_hours', 'M' => 'shifts', 'N' => 'employees_per_shift', 'P' => 'average_sales_per_shift', 'R' => 'inventory_level', 'T' => 'monthly_rent', 'V' => 'years_in_area', 'X' => 'dining_capacity'];
        foreach ([364, 365, 366] as $index => $templateRow) {
            $branch = $branchRows[$index] ?? null;
            foreach ($branchColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $branch[$field] ?? null);
            }
        }

        foreach (range(368, 375) as $index => $templateRow) {
            $this->set($sheet, 'O', $row($templateRow), data_get($data, "questions.$index"));
        }

        $this->setRemarks($sheet, 'F', $r(24), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Farming: Corn Production section. This
     * template's schema has "profile" false (no Business Name / Main Address rows exist for
     * it in the reference workbook, same as Leasing: Agricultural / Poultry Farm above), so
     * it goes straight from the section title into its production-figures block. The
     * "Industry Research/Survey" cell has no separate value slot in the reference workbook
     * (a rowspan-2 label with a blank date line), so its value is appended into the label
     * text — the same technique used for Taxi/PUJ's "LTFRB Research (date)" row.
     *
     * The suppliers table's 3 rows are matched by their fixed "supplier_category" text
     * (SEEDS SUPPLIER / FERTILIZER SUPPLIER / TRUCKING PROVIDER), not position — rows with
     * only that label and no other data are dropped and the table re-indexed on save (see
     * prepareForValidation() in UpdateBusinessIncomeSourceRequest), the same instability as
     * Meatshop's inventory rows. Their category-label cells (column C) already carry the
     * correct static text in the reference workbook, so only the other columns are written.
     */
    private function injectFarmingCornSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $data = (array) $report->template_data;

        $researchDate = data_get($data, 'fields.research_date');
        $this->set($sheet, 'C', $row(379), "INDUSTRY RESEARCH/SURVEY:\nAS OF DATE: ".($researchDate ?: 'N/A'));
        $this->setOrNa($sheet, 'K', $row(379), data_get($data, 'fields.average_selling_price'));
        $this->setOrNa($sheet, 'Q', $row(379), data_get($data, 'fields.seed_cost_per_ha'));
        $this->setOrNa($sheet, 'X', $row(379), data_get($data, 'fields.fertilizer_cost_per_ha'));
        $this->setOrNa($sheet, 'K', $row(380), data_get($data, 'fields.average_yield_per_ha'));
        $this->setOrNa($sheet, 'Q', $row(380), data_get($data, 'fields.crop_cycle_months'));
        $this->setOrNa($sheet, 'X', $row(380), data_get($data, 'fields.peak_harvest_months'));
        $this->setOrNa($sheet, 'F', $row(381), data_get($data, 'fields.total_ha_planted'));
        $this->setOrNa($sheet, 'J', $row(381), data_get($data, 'fields.total_sites'));
        $this->setOrNa($sheet, 'N', $row(381), data_get($data, 'fields.sites_validated'));
        $this->setOrNa($sheet, 'S', $row(381), data_get($data, 'fields.sites_not_inspected'));
        $this->setOrNa($sheet, 'V', $row(381), data_get($data, 'fields.reason_not_inspected'));

        $farms = (array) data_get($data, 'tables.farms', []);
        $farmColumns = ['C' => 'location_size', 'I' => 'total_ha', 'K' => 'tenure', 'N' => 'annual_rent', 'P' => 'prenda_amount', 'R' => 'expiry_date', 'T' => 'target_harvest_month', 'W' => 'relevant_information'];
        foreach ([384, 385, 386, 387, 388] as $index => $templateRow) {
            $farm = $farms[$index] ?? null;
            foreach ($farmColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $farm[$field] ?? null);
            }
        }

        $supplierCategories = ['SEEDS SUPPLIER', 'FERTILIZER SUPPLIER', 'TRUCKING PROVIDER (IF ANY)'];
        $savedSuppliers = collect((array) data_get($data, 'tables.suppliers', []));
        foreach ([391, 392, 393] as $index => $templateRow) {
            $supplier = $savedSuppliers->first(fn ($candidate) => strcasecmp((string) data_get($candidate, 'supplier_category'), $supplierCategories[$index]) === 0) ?? [];
            $this->set($sheet, 'G', $row($templateRow), $supplier['supplier_name'] ?? null);
            $this->set($sheet, 'L', $row($templateRow), $supplier['office_location'] ?? null);
            $this->set($sheet, 'Q', $row($templateRow), $supplier['years_transacting'] ?? null);
            $this->set($sheet, 'S', $row($templateRow), $supplier['payment_performance'] ?? null);
        }

        $buyers = (array) data_get($data, 'tables.buyers', []);
        $buyerColumns = ['C' => 'buyer', 'L' => 'address', 'Q' => 'years_transacting', 'S' => 'production_performance'];
        foreach ([396, 397] as $index => $templateRow) {
            $buyer = $buyers[$index] ?? null;
            foreach ($buyerColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $buyer[$field] ?? null);
            }
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // AD cell outside this section's C:W grid, never written to by setRemarks() below —
        // the same leftover-note pattern as every other section's. setCellValue(..., null)
        // only clears the value, not the cell's presence in the sheet's highest-column
        // calculation, so the cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$row(399));

        $this->setRemarks($sheet, 'F', $row(399), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Farming: Sugarcane Production section.
     * Structurally identical to Farming: Corn Production above (same "profile" false shape,
     * same combined research-date label technique, same category-matched suppliers table),
     * except its farms table has an extra single-cell "Ratoon Cycle" column (schema key
     * "ratoon_cycle", column K) between Total HA and the tenure column, and its final table
     * is "Sugarmill Validation" (2 rows, plain position-based — no default_row_key) instead
     * of Corn Buyers: each row's BUSCO/CRYSTAL radio choice is written as inline mark text
     * into column C, the same technique used for every other inline checkbox/radio choice
     * list in this exporter (e.g. ownershipChoiceText()).
     */
    private function injectFarmingSugarcaneSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $data = (array) $report->template_data;
        $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(   )';

        $researchDate = data_get($data, 'fields.research_date');
        $this->set($sheet, 'C', $row(401), "INDUSTRY RESEARCH/SURVEY:\nAS OF DATE: ".($researchDate ?: 'N/A'));
        $this->setOrNa($sheet, 'K', $row(401), data_get($data, 'fields.average_selling_price'));
        $this->setOrNa($sheet, 'Q', $row(401), data_get($data, 'fields.seed_cost_per_ha'));
        $this->setOrNa($sheet, 'X', $row(401), data_get($data, 'fields.fertilizer_cost_per_ha'));
        $this->setOrNa($sheet, 'K', $row(402), data_get($data, 'fields.average_yield_per_ha'));
        $this->setOrNa($sheet, 'Q', $row(402), data_get($data, 'fields.crop_cycle_months'));
        $this->setOrNa($sheet, 'X', $row(402), data_get($data, 'fields.peak_harvest_months'));
        $this->setOrNa($sheet, 'F', $row(403), data_get($data, 'fields.total_ha_planted'));
        $this->setOrNa($sheet, 'J', $row(403), data_get($data, 'fields.total_sites'));
        $this->setOrNa($sheet, 'N', $row(403), data_get($data, 'fields.sites_validated'));
        $this->setOrNa($sheet, 'S', $row(403), data_get($data, 'fields.sites_not_inspected'));
        $this->setOrNa($sheet, 'V', $row(403), data_get($data, 'fields.reason_not_inspected'));

        $farms = (array) data_get($data, 'tables.farms', []);
        $farmColumns = ['C' => 'location_size', 'I' => 'total_ha', 'K' => 'ratoon_cycle', 'L' => 'tenure', 'N' => 'annual_rent', 'P' => 'prenda_amount', 'R' => 'expiry_date', 'T' => 'target_harvest_month', 'W' => 'relevant_information'];
        foreach ([406, 407, 408, 409, 410] as $index => $templateRow) {
            $farm = $farms[$index] ?? null;
            foreach ($farmColumns as $column => $field) {
                $this->set($sheet, $column, $row($templateRow), $farm[$field] ?? null);
            }
        }

        $supplierCategories = ['SEEDS SUPPLIER', 'FERTILIZER SUPPLIER', 'TRUCKING PROVIDER (IF ANY)'];
        $savedSuppliers = collect((array) data_get($data, 'tables.suppliers', []));
        foreach ([413, 414, 415] as $index => $templateRow) {
            $supplier = $savedSuppliers->first(fn ($candidate) => strcasecmp((string) data_get($candidate, 'supplier_category'), $supplierCategories[$index]) === 0) ?? [];
            $this->set($sheet, 'G', $row($templateRow), $supplier['supplier_name'] ?? null);
            $this->set($sheet, 'L', $row($templateRow), $supplier['office_location'] ?? null);
            $this->set($sheet, 'Q', $row($templateRow), $supplier['years_transacting'] ?? null);
            $this->set($sheet, 'S', $row($templateRow), $supplier['payment_performance'] ?? null);
        }

        $sugarmills = (array) data_get($data, 'tables.sugarmills', []);
        foreach ([418, 419] as $index => $templateRow) {
            $sugarmill = $sugarmills[$index] ?? null;
            $mill = (string) ($sugarmill['sugarmill'] ?? '');
            $this->set($sheet, 'C', $row($templateRow), $mark(strcasecmp($mill, 'BUSCO') === 0).' BUSCO  '.$mark(strcasecmp($mill, 'CRYSTAL') === 0).' CRYSTAL');
            $this->set($sheet, 'G', $row($templateRow), $sugarmill['association'] ?? null);
            $this->set($sheet, 'L', $row($templateRow), $sugarmill['address'] ?? null);
            $this->set($sheet, 'Q', $row($templateRow), $sugarmill['years_transacting'] ?? null);
            $this->set($sheet, 'S', $row($templateRow), $sugarmill['production_data'] ?? null);
        }

        // The remarks row carries an internal reviewer note ("<---SAMPLE: CONTRACT WON'T BE
        // RENEWED / PROPERTIES SOLD ALREADY / SOMEONE HAS CLAIMS OVER PROPERTY") in a stray
        // AD cell outside this section's C:W grid, never written to by setRemarks() below —
        // the same leftover-note pattern as every other section's. setCellValue(..., null)
        // only clears the value, not the cell's presence in the sheet's highest-column
        // calculation, so the cell itself is removed instead.
        $sheet->getCellCollection()->delete('AD'.$row(421));

        $this->setRemarks($sheet, 'F', $row(421), $report->report_remarks);
    }

    /**
     * Exact, verified cell coordinates for the Remittance section. This template's schema
     * has "profile" false and no fields/tables at all — just its 10 validation questions,
     * each its own full-width question/answer row (same C:N/O:AA shape as Meatshop's
     * Observations block), directly under the section title with no branches/profile block
     * in between. The reference workbook's "OTHER REMARKS:" label (present in every other
     * mapped section) is missing from this one row (C435 is blank) — verified as a gap in
     * the reference file itself, not a deliberate omission, since the remarks value box
     * (F435:AA435) is still there — so the label is written here to match every other
     * section's remarks area instead of leaving it unlabeled.
     */
    private function injectRemittanceIncomeSection(Worksheet $sheet, int $offset, IncomeSource $source): void
    {
        $report = $source->businessReport;
        $row = fn (int $templateRow): int => $templateRow - $offset;
        $data = (array) $report->template_data;

        foreach (range(424, 433) as $index => $templateRow) {
            $this->set($sheet, 'O', $row($templateRow), data_get($data, "questions.$index"));
        }

        $this->set($sheet, 'C', $row(435), 'OTHER REMARKS:');
        $this->setRemarks($sheet, 'F', $row(435), $report->report_remarks);
    }

    /** Shared "Business Name / Main Address / Ownership / Previous Address / Registered Owner" block — identical cell layout across every profile-bearing template section. */
    private function injectProfileBlock(Worksheet $sheet, \Closure $row, int $titleRow, BusinessReport $report): void
    {
        $r = fn (int $lineOffset): int => $row($titleRow + $lineOffset);

        $this->setOrNa($sheet, 'G', $r(1), $report->business_name);
        $this->setOrNa($sheet, 'Y', $r(1), $report->year_established);
        $this->setOrNa($sheet, 'G', $r(2), $report->main_business_address);
        $this->setOrNa($sheet, 'Y', $r(2), $report->length_of_stay_months);
        $sheet->getStyle('Y'.$r(2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->set($sheet, 'C', $r(3), $this->ownershipChoiceText($report->ownership_type));
        $this->setOrNa($sheet, 'O', $r(3), $report->rented_from);
        $this->setOrNa($sheet, 'Y', $r(3), $report->monthly_rent);
        $this->setOrNa($sheet, 'G', $r(4), $report->previous_business_address);
        $this->setOrNa($sheet, 'Y', $r(4), $report->previous_business_address_length_of_stay);
        $sheet->getStyle('Y'.$r(4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $this->setOrNa($sheet, 'G', $r(5), $report->reason_for_transfer);
        $this->setOrNa($sheet, 'Y', $r(5), $report->informant);
        $this->setOrNa($sheet, 'G', $r(6), $report->registered_owner);
        $this->setOrNa($sheet, 'W', $r(6), $report->relationship_to_borrower);
    }

    private function ownershipChoiceText(?string $ownershipType): string
    {
        $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(   )';
        $selected = fn (string $value): bool => strcasecmp((string) $ownershipType, $value) === 0;

        return $mark($selected('Residence Only')).' RESIDENCE ONLY   '.$mark($selected('Owned')).' OWNED   '.$mark($selected('Mortgaged')).' MORTGAGED FROM/   '.$mark($selected('Rented')).' RENTED FROM:';
    }

    private function scaleChoiceText(?string $scale): string
    {
        $mark = fn (bool $selected): string => $selected ? '('.$this->check().')' : '(  )';
        $options = ['Sari-Sari Store' => 'SARI-SARI STORE', 'Grocery Store' => 'GROCERY STORE', 'Convenience Store' => 'CONVENIENCE STORE', 'Supermarket' => 'SUPERMARKET', 'Water Refilling' => 'WATER REFILLING'];

        return collect($options)->map(fn (string $label, string $value): string => $mark(strcasecmp((string) $scale, $value) === 0).' '.$label)->implode('   ');
    }

    private function check(): string
    {
        return ' '."\u{2713}".' ';
    }

    private function yesNo(mixed $value): string
    {
        return $value === null ? '' : ($value ? 'Y' : 'N');
    }

    /**
     * Marks a cell to ignore Excel's "Number Stored as Text" check — only when the written
     * value is the kind that actually triggers it (an all-digit string; a leading zero, as in
     * a mobile number, is exactly why it was written as text in the first place, not a real
     * mismatch). Leaves the cell's own data type — already correctly text, via PhpSpreadsheet's
     * own value binder — untouched; this only suppresses the workbook-side warning indicator.
     */
    private function suppressNumberStoredAsTextWarning(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $sheet->getCell($coordinate)->getIgnoredErrors()->setNumberStoredAsText(true);
        }
    }

    /** Always overwrites the cell — including clearing it — so the reference workbook's sample values never leak into the output. */
    private function set(Worksheet $sheet, string $column, int $row, mixed $value): void
    {
        if ($row < 1) {
            return;
        }
        $sheet->setCellValue($column.$row, filled($value) ? $value : null);
    }

    /** Same as set(), but shows "N/A" instead of a blank cell when there's no saved value — for single-value data fields only, never for structural/table cells. */
    private function setOrNa(Worksheet $sheet, string $column, int $row, mixed $value): void
    {
        if ($row < 1) {
            return;
        }
        $sheet->setCellValue($column.$row, filled($value) ? $value : 'N/A');
    }

    /**
     * Writes the "Other Remarks" value with wrap text enabled so long remarks wrap onto
     * multiple lines instead of overflowing the cell. The reference workbook stores every
     * "Other Remarks" row as hidden with a fixed height (sized for its own sample text), so
     * the row is also unhidden here and given a generous fixed height to comfortably fit
     * several wrapped lines regardless of how long the saved remarks are.
     */
    private function setRemarks(Worksheet $sheet, string $column, int $row, ?string $value): void
    {
        if ($row < 1) {
            return;
        }
        $sheet->setCellValue($column.$row, filled($value) ? $value : 'N/A');
        $sheet->getStyle($column.$row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $dimension = $sheet->getRowDimension($row);
        $dimension->setVisible(true);
        $dimension->setRowHeight(max(60, $dimension->getRowHeight()));
    }

    private function setDate(Worksheet $sheet, string $column, int $row, mixed $date): void
    {
        if ($row < 1) {
            return;
        }
        $sheet->setCellValue($column.$row, $date ? $date->format('n/j/Y') : 'N/A');
    }

    /** Fallback for templates with no mapped section in the reference workbook (e.g. "Other Business/Source of Income"). */
    private function fromDocument(array $document, bool $showCommonHeader = true): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Business Report');

        $width = max(2, collect($document['sections'])->where('kind', 'table')->map(fn (array $section): int => count($section['columns']))->max() ?? 2);
        $sheet->getColumnDimension('A')->setWidth(28);
        for ($index = 2; $index <= $width; $index++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(24);
        }

        $row = 1;
        $row = $this->title($sheet, $row, $width, $document['title'], 13);
        $row = $this->title($sheet, $row, $width, $document['subtitle'], 10);
        $row++;

        // Batch sheets after the first belong to the same client/co-maker as the first, so the
        // shared CI/client header rows (CI in Charge, Branch, Applicant/Co-Maker name, etc. —
        // see $document['header'] in OfficialReportDataBuilder::business()) are skipped rather
        // than repeated; every business-specific section below is unaffected.
        if ($showCommonHeader) {
            foreach ($document['header'] as [$label, $value]) {
                $sheet->setCellValue([1, $row], $label);
                $sheet->setCellValue([2, $row], filled($value) ? $value : '—');
                $sheet->getStyle([1, $row])->getFont()->setBold(true);
                $row++;
            }
            $row++;
        }

        foreach ($document['sections'] as $section) {
            $row = $this->title($sheet, $row, $width, $section['title'], 11, 'E7E7E7');

            if ($section['kind'] === 'narrative') {
                $sheet->setCellValue([1, $row], $section['text']);
                $sheet->mergeCells([1, $row, $width, $row]);
                $sheet->getStyle([1, $row])->getAlignment()->setWrapText(true);
                $row++;
            } elseif ($section['kind'] === 'details') {
                foreach ($section['rows'] as [$label, $value]) {
                    $sheet->setCellValue([1, $row], $label);
                    $sheet->setCellValue([2, $row], $value);
                    $sheet->getStyle([1, $row])->getFont()->setBold(true);
                    $row++;
                }
            } else {
                foreach ($section['columns'] as $index => $column) {
                    $sheet->setCellValue([$index + 1, $row], $column);
                }
                $sheet->getStyle([1, $row, count($section['columns']), $row])->getFont()->setBold(true);
                $sheet->getStyle([1, $row, count($section['columns']), $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F3F3');
                $row++;
                foreach ($section['rows'] as $values) {
                    foreach ($values as $index => $value) {
                        $sheet->setCellValue([$index + 1, $row], $value);
                    }
                    $row++;
                }
            }
            $row++;
        }

        return $book;
    }

    private function title(Worksheet $sheet, int $row, int $width, string $text, int $size, ?string $fillRgb = null): int
    {
        $sheet->setCellValue([1, $row], $text);
        $sheet->mergeCells([1, $row, $width, $row]);
        $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize($size);
        if ($fillRgb !== null) {
            $sheet->getStyle([1, $row])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fillRgb);
        }

        return $row + 1;
    }
}
