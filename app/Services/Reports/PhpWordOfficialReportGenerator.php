<?php

namespace App\Services\Reports;

use App\Services\Media\ReportMediaResolver;
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

    public function __construct(private readonly ReportMediaResolver $mediaResolver) {}

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
        // Downloads any Cloudinary-backed item's bytes into a temp file right here, only now that
        // a DOCX genuinely needs them — see ReportMediaResolver's own docblock.
        $photoSections = $this->mediaResolver->resolve($data['photo_sections']);
        foreach ($photoSections as $photoSection) {
            $this->photoSection($section, $photoSection);
        }

        // Suppressed — see ResidenceBusinessCheckBatchDocxExporter's identical call for why: an
        // unwritable sys_get_temp_dir() still leaves tempnam() returning a real, usable fallback
        // path, but its own informational warning must not be allowed to crash generation.
        $temporary = @tempnam(sys_get_temp_dir(), 'brbi-docx-');
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
            // Only safe to delete embedImage()'s own WebP-conversion temp files, and the
            // mediaResolver's own Cloudinary-download temp files, now that save() has fully
            // finished reading them into the package — see embedImage()'s own docblock.
            $this->cleanupTemporaryEmbeddedImages();
            $this->mediaResolver->cleanup();
        }

        return new GeneratedReportArtifact($data['_artifact_path'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', hash('sha256', $bytes), strlen($bytes));
    }
}
