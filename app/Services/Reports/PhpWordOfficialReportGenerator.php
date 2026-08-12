<?php

namespace App\Services\Reports;

use App\Services\Reports\Contracts\DocxGenerator;
use App\Services\Reports\Data\GeneratedReportArtifact;
use App\Services\Reports\Data\ReportRenderOptions;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

class PhpWordOfficialReportGenerator implements DocxGenerator
{
    private const WIDTH_DXA = 10944;

    public function generate(string $template, array $data, ReportRenderOptions $options): GeneratedReportArtifact
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
        $phpWord->getDocInfo()->setCreator('BRBI Credit Investigation Management System')->setTitle($data['title']);
        $phpWord->addParagraphStyle('ReportTitle', ['alignment' => 'center', 'spaceBefore' => 0, 'spaceAfter' => 80, 'lineHeight' => 1.0, 'keepNext' => true]);
        $phpWord->addFontStyle('ReportTitleFont', ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => '000000']);
        $phpWord->addParagraphStyle('SectionHeading', ['spaceBefore' => 100, 'spaceAfter' => 50, 'keepNext' => true]);
        $phpWord->addFontStyle('SectionHeadingFont', ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '000000']);
        $phpWord->addTableStyle('OfficialGrid', ['borderSize' => 6, 'borderColor' => '000000', 'cellMarginTop' => 70, 'cellMarginRight' => 90, 'cellMarginBottom' => 70, 'cellMarginLeft' => 90, 'width' => self::WIDTH_DXA, 'unit' => 'dxa', 'layout' => 'fixed'], ['bgColor' => 'E7E7E7', 'tblHeader' => true]);

        $section = $phpWord->addSection([
            'paperSize' => null,
            'pageSizeW' => Converter::inchToTwip($options->widthInches),
            'pageSizeH' => Converter::inchToTwip($options->heightInches),
            'marginTop' => Converter::inchToTwip($options->marginsInches['top'] ?? .45),
            'marginRight' => Converter::inchToTwip($options->marginsInches['right'] ?? .45),
            'marginBottom' => Converter::inchToTwip($options->marginsInches['bottom'] ?? .45),
            'marginLeft' => Converter::inchToTwip($options->marginsInches['left'] ?? .45),
            'headerHeight' => Converter::inchToTwip(.2), 'footerHeight' => Converter::inchToTwip(.25),
        ]);
        $this->footer($section);
        $this->title($section, $data);
        $this->detailsTable($section, $data['header']);
        foreach ($data['sections'] as $reportSection) {
            $this->reportSection($section, $reportSection);
        }
        foreach ($data['photo_sections'] as $photoSection) {
            $this->photoSection($section, $photoSection);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'brbi-docx-');
        if ($temporary === false) {
            throw new \RuntimeException('A temporary report file could not be created.');
        }
        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($temporary);
            $bytes = file_get_contents($temporary);
            if ($bytes === false || ! Storage::disk(config('cims.report_disk'))->put($data['_artifact_path'], $bytes)) {
                throw new \RuntimeException('The DOCX artifact could not be stored.');
            }
        } finally {
            @unlink($temporary);
        }

        return new GeneratedReportArtifact($data['_artifact_path'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', hash('sha256', $bytes), strlen($bytes));
    }

    private function footer(Section $section): void
    {
        $section->addFooter()->addPreserveText('BRBI Official Report  |  Page {PAGE}', ['name' => 'Arial', 'size' => 8, 'color' => '666666'], ['alignment' => 'right', 'spaceBefore' => 0, 'spaceAfter' => 0]);
    }

    private function title(Section $section, array $data): void
    {
        $section->addText($data['title'], 'ReportTitleFont', 'ReportTitle');
        $section->addText($data['subtitle'], ['name' => 'Arial', 'size' => 9, 'bold' => true], ['alignment' => 'center', 'spaceAfter' => 70, 'keepNext' => true]);
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
