<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/vendor/autoload.php';

$source = $argv[1] ?? null;
$destination = $argv[2] ?? dirname(__DIR__).'/resources/report-templates/cibi-report.xlsx';
if (! is_string($source) || ! is_file($source)) {
    fwrite(STDERR, "Usage: php scripts/build_cibi_excel_template.php <source.xlsx> [destination.xlsx]\n");
    exit(1);
}

$book = IOFactory::load($source);
$sheet = $book->getSheetByName('CI REPORT - CIBI');
if (! $sheet) {
    fwrite(STDERR, "The source workbook does not contain the CI REPORT - CIBI worksheet.\n");
    exit(1);
}

for ($index = $book->getSheetCount() - 1; $index >= 0; $index--) {
    if ($book->getSheet($index)->getTitle() !== 'CI REPORT - CIBI') {
        $book->removeSheetByIndex($index);
    }
}
$book->setActiveSheetIndex(0);

// Remove draft annotations and alternate-form content outside the official print area.
$clearRange = function (string $range) use ($sheet): void {
    [[$startColumn, $startRow], [$endColumn, $endRow]] = Coordinate::rangeBoundaries($range);
    for ($row = $startRow; $row <= $endRow; $row++) {
        for ($column = $startColumn; $column <= $endColumn; $column++) {
            $sheet->getCell([$column, $row])->setValue(null);
        }
    }
};
$clearRange('AD1:AP92');
$clearRange('A65:AC92');
$sheet->getCell('B1')->setValue(null);

// Clear every sample-value cell while preserving the source workbook's formatting.
$cells = [
    'G6', 'T6', 'G7', 'T7', 'G8', 'T8', 'G11', 'Y11', 'G12', 'Y12', 'G13', 'Y13', 'L14', 'Y14',
    'R15', 'Y16', 'G18', 'Y18', 'G19', 'Y19', 'Y20', 'P22', 'P23', 'T24', 'J25', 'E26', 'E32',
    'E42', 'H52', 'H53', 'H54', 'J53', 'J54', 'E56', 'G63', 'S63',
];
foreach (range(36, 40) as $row) {
    foreach (['C', 'G', 'J', 'R', 'U'] as $column) {
        $cells[] = $column.$row;
    }
}
foreach (range(45, 49) as $row) {
    foreach (['C', 'G', 'J', 'M', 'P', 'S', 'T', 'V'] as $column) {
        $cells[] = $column.$row;
    }
}
foreach (range(60, 62) as $row) {
    foreach (['C', 'P'] as $column) {
        $cells[] = $column.$row;
    }
}
foreach ($cells as $coordinate) {
    $sheet->getCell($coordinate)->setValue(null);
}

// Choice text is part of the official structure, but no sample choice remains selected.
$sheet->setCellValue('C9', '(   ) BORROWER  (   ) CO-MAKER');
$sheet->setCellValue('T9', '(   ) VERY LOW  (   ) LOW  (   ) MID  (   ) HIGH  (   ) VERY HIGH');
$sheet->setCellValue('C14', '(   ) OWNED  (   ) MORTGAGED FROM   (   ) RENTED FROM:');
$sheet->setCellValue('C15', '(   ) LIVING W PARENTS:  (   ) OWNED  (   ) MORTGAGED   (   ) RENTED');
$sheet->setCellValue('G16', '(   ) NEW (   ) SLIGHTLY NEW (   ) ANCESTRAL (   ) APARTMENT (   ) DORM (   ) SHANTY');
$sheet->setCellValue('G17', '(   ) EXPENSIVE  (   ) MEDIUM (   ) LOW');
$sheet->setCellValue('Q17', '(   ) EXCELLENT (   ) GOOD (   ) POOR');
$sheet->setCellValue('G20', '(   ) SINGLE   (   ) MARRIED  (   ) SEPARATED / DIVORCED  (   ) COMMON LAW  (   ) WIDOWED');
$sheet->setCellValue('G21', '(   ) UNKNOWN  (   ) GOOD (   ) RICH (   ) DRUNKARD (   ) ADULTEROUS (   ) GAMBLER (   ) HEAVILY INDEBTED/FREQUENT COLLECTOR VISITS');
$sheet->setCellValue('G22', '(   ) NO LEGAL CASES  (   ) WITH LEGAL CASE:');
$sheet->setCellValue('G23', '(   ) N/A      (   ) NO LEGAL CASES  (   ) WITH LEGAL CASE:');
$sheet->setCellValue('G24', '(   ) MODEST  (   ) EXTRAVAGANT  (   ) BELOW AVERAGE');
$sheet->setCellValue('C28', '(   ) WORKING CAPITAL (INVENTORY/RECEIVABLES)');
$sheet->setCellValue('N28', '(   ) BUSINESS EXPANSION: RENOVATION / START UP INVENTORY');
$sheet->setCellValue('C29', '(   ) BUYOUT/DEBT CONSOLIDATION');
$sheet->setCellValue('N29', '(   ) CHATTEL PROPERTY ACQUISITION');
$sheet->setCellValue('C30', '(   ) BUILDING CONSTRUCTION / HOME RENOVATION');
$sheet->setCellValue('N30', '(   ) REAL ESTATE PROPERTY ACQUISITION');
$sheet->setCellValue('C31', '(   ) PERSONAL:  MEDICAL EXPENSES / EDUCATION / TRAVEL / ESTATE MGT');
$sheet->setCellValue('N31', '(   ) OTHERS');
foreach (range(36, 40) as $row) {
    $sheet->setCellValue('L'.$row, '(   ) LOW (   ) MID (   ) HIGH / FIGURES:');
}
foreach (range(60, 62) as $row) {
    $sheet->setCellValue('G'.$row, '(   ) EXISTING WITH STRONG CAPACITY (   ) EXISTING BUT WEAK CAPACITY / (   ) WAS NOT/CANNOT BE VALIDATED');
}

// Keep the exact source formulas and enforce the approved 8.5 x 13 Folio setup.
$sheet->setCellValue('G50', '=SUM(G45:I49)');
$sheet->setCellValue('J50', '=SUM(J45:L49)');
$sheet->setCellValue('M50', '=SUM(M45:O49)');
$sheet->getPageSetup()
    ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
    ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
    ->setPrintArea('B2:AB64');

$directory = dirname($destination);
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}
(new Xlsx($book))->save($destination);

echo "Sanitized CI/BI template written to {$destination}\n";
