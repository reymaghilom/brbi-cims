<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\BuildsOfficialReportDocx;
use App\Services\Reports\Contracts\DocxGenerator;
use App\Services\Reports\Data\GeneratedReportArtifact;
use App\Services\Reports\Data\ReportRenderOptions;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

class PhpWordOfficialReportGenerator implements DocxGenerator
{
    use BuildsOfficialReportDocx;

    public function generate(string $template, array $data, ReportRenderOptions $options): GeneratedReportArtifact
    {
        $phpWord = new PhpWord;
        $this->registerOfficialReportStyles($phpWord);
        $phpWord->getDocInfo()->setCreator('BRBI Credit Investigation Management System')->setTitle($data['title']);

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
        $this->title($section, $data['title'], $data['subtitle']);
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
}
