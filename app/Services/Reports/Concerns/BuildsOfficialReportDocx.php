<?php

namespace App\Services\Reports\Concerns;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;

/**
 * Shared PhpWord building blocks for official-report DOCX output — used by both the single-record
 * generator (PhpWordOfficialReportGenerator) and the batch exporters (e.g.
 * ResidenceBusinessCheckBatchDocxExporter) so the styling/table/photo-section logic isn't
 * duplicated between them.
 */
trait BuildsOfficialReportDocx
{
    private const WIDTH_DXA = 10944;

    private function registerOfficialReportStyles(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
        $phpWord->addParagraphStyle('ReportTitle', ['alignment' => 'center', 'spaceBefore' => 0, 'spaceAfter' => 80, 'lineHeight' => 1.0, 'keepNext' => true]);
        $phpWord->addFontStyle('ReportTitleFont', ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '000000']);
        $phpWord->addParagraphStyle('SectionHeading', ['spaceBefore' => 100, 'spaceAfter' => 50, 'keepNext' => true]);
        $phpWord->addFontStyle('SectionHeadingFont', ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '000000']);
        $phpWord->addTableStyle('OfficialGrid', ['borderSize' => 6, 'borderColor' => '000000', 'cellMarginTop' => 70, 'cellMarginRight' => 90, 'cellMarginBottom' => 70, 'cellMarginLeft' => 90, 'width' => self::WIDTH_DXA, 'unit' => 'dxa', 'layout' => 'fixed'], ['bgColor' => 'E7E7E7', 'tblHeader' => true]);
    }

    private function footer(Section $section): void
    {
        $section->addFooter()->addPreserveText('BRBI Official Report  |  Page {PAGE}', ['name' => 'Arial', 'size' => 8, 'color' => '666666'], ['alignment' => 'right', 'spaceBefore' => 0, 'spaceAfter' => 0]);
    }

    private function title(Section $section, string $title, string $subtitle): void
    {
        $section->addText($title, 'ReportTitleFont', 'ReportTitle');
        $section->addText($subtitle, ['name' => 'Arial', 'size' => 9, 'bold' => true], ['alignment' => 'center', 'spaceAfter' => 70, 'keepNext' => true]);
    }

    private function detailsTable(Section $section, array $rows): void
    {
        $table = $section->addTable('OfficialGrid');
        foreach (array_chunk($rows, 2) as $pair) {
            $table->addRow();
            foreach ([0, 1] as $index) {
                $item = $pair[$index] ?? ['', ''];
                $table->addCell(1650)->addText((string) $item[0], ['name' => 'Arial', 'size' => 8.5, 'bold' => true], ['spaceAfter' => 0]);
                $table->addCell(3822)->addText(filled($item[1]) ? (string) $item[1] : '—', ['name' => 'Arial', 'size' => 8.5], ['spaceAfter' => 0]);
            }
        }
    }

    private function reportSection(Section $section, array $reportSection): void
    {
        $section->addText($reportSection['title'], 'SectionHeadingFont', 'SectionHeading');
        if ($reportSection['kind'] === 'narrative') {
            $section->addText($reportSection['text'], ['name' => 'Arial', 'size' => 9], ['spaceAfter' => 60, 'lineHeight' => 1.1]);

            return;
        }
        if ($reportSection['kind'] === 'details') {
            $this->detailsTable($section, $reportSection['rows']);

            return;
        }
        $table = $section->addTable('OfficialGrid');
        $columnWidth = intdiv(self::WIDTH_DXA, max(1, count($reportSection['columns'])));
        $table->addRow(null, ['tblHeader' => true]);
        foreach ($reportSection['columns'] as $column) {
            $table->addCell($columnWidth, ['bgColor' => 'E7E7E7'])->addText($column, ['name' => 'Arial', 'size' => 7.5, 'bold' => true], ['spaceAfter' => 0]);
        }
        if ($reportSection['rows'] === []) {
            $table->addRow();
            $table->addCell(self::WIDTH_DXA, ['gridSpan' => count($reportSection['columns'])])->addText('No saved entries.', ['name' => 'Arial', 'size' => 8, 'italic' => true], ['spaceAfter' => 0]);
        }
        foreach ($reportSection['rows'] as $row) {
            $table->addRow();
            foreach ($row as $value) {
                $table->addCell($columnWidth)->addText((string) $value, ['name' => 'Arial', 'size' => 7.5], ['spaceAfter' => 0, 'lineHeight' => 1.0]);
            }
        }
    }

    private function photoSection(Section $section, array $photoSection): void
    {
        $chunks = array_chunk($photoSection['media'], 2);
        if ($chunks === []) {
            $chunks = [[]];
        }
        foreach ($chunks as $index => $media) {
            $section->addPageBreak();
            $section->addText($photoSection['heading'].' '.($index ? '- Continuation '.($index + 1) : ''), 'SectionHeadingFont', ['alignment' => 'center', 'spaceAfter' => 60]);
            $this->detailsTable($section, [['Category', $photoSection['category']], ['Subject', $photoSection['subject']], ['Location', $photoSection['location']], ['Business', $photoSection['business_name']], ['Income Source', $photoSection['income_source']], ['Map', $photoSection['map']]]);
            if ($index === 0 && filled($photoSection['remarks'])) {
                $section->addText($photoSection['remarks'], ['name' => 'Arial', 'size' => 9], ['spaceBefore' => 50, 'spaceAfter' => 50]);
            }
            foreach ($media as $item) {
                $section->addText(trim($item['label'].' '.($item['caption'] ?? '')), ['name' => 'Arial', 'size' => 9, 'bold' => true], ['spaceBefore' => 60, 'spaceAfter' => 30, 'keepNext' => true]);
                if ($item['image_path'] && $item['media_type'] === 'photo') {
                    $section->addImage($item['image_path'], ['width' => 510, 'height' => 300, 'ratio' => true, 'alignment' => 'center']);
                } else {
                    $section->addText('Media reference: '.$item['file_name'].' (image content unavailable)', ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => '555555'], ['alignment' => 'center', 'spaceAfter' => 50]);
                }
            }
        }
    }
}
