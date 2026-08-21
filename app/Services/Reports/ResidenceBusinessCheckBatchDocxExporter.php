<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\BuildsOfficialReportDocx;
use App\Services\Reports\Data\ReportRenderOptions;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

/**
 * Renders one combined "Download Selected → Word" DOCX for a batch of selected Residence Checks
 * / Business Checks, mirroring ResidenceBusinessCheckBatchPdfExporter's non-versioned, direct-
 * bytes pattern. Reuses PhpWordOfficialReportGenerator's own building blocks via
 * BuildsOfficialReportDocx instead of duplicating the Word-generation logic.
 */
class ResidenceBusinessCheckBatchDocxExporter
{
    use BuildsOfficialReportDocx;

    /** @param  array<int, array<string, mixed>>  $photoSections */
    public function generate(array $photoSections, string $title, string $subtitle): string
    {
        $phpWord = new PhpWord;
        $this->registerOfficialReportStyles($phpWord);
        $phpWord->getDocInfo()->setCreator('BRBI Credit Investigation Management System')->setTitle($title);

        $render = ReportRenderOptions::brbiDefault();
        $section = $phpWord->addSection([
            'paperSize' => null,
            'pageSizeW' => Converter::inchToTwip($render->widthInches),
            'pageSizeH' => Converter::inchToTwip($render->heightInches),
            'marginTop' => Converter::inchToTwip($render->marginsInches['top'] ?? .45),
            'marginRight' => Converter::inchToTwip($render->marginsInches['right'] ?? .45),
            'marginBottom' => Converter::inchToTwip($render->marginsInches['bottom'] ?? .45),
            'marginLeft' => Converter::inchToTwip($render->marginsInches['left'] ?? .45),
            'headerHeight' => Converter::inchToTwip(.2), 'footerHeight' => Converter::inchToTwip(.25),
        ]);
        $this->footer($section);
        $this->title($section, $title, $subtitle);

        foreach ($photoSections as $photoSection) {
            $this->photoSection($section, $photoSection);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'brbi-batch-docx-');
        if ($temporary === false) {
            throw new \RuntimeException('A temporary report file could not be created.');
        }
        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($temporary);
            $bytes = file_get_contents($temporary);
            if ($bytes === false) {
                throw new \RuntimeException('The DOCX report could not be generated.');
            }

            return $bytes;
        } finally {
            @unlink($temporary);
        }
    }
}
