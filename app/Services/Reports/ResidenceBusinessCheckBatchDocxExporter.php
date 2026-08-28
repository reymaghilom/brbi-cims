<?php

namespace App\Services\Reports;

use App\Services\Media\ReportMediaResolver;
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

    public function __construct(private readonly ReportMediaResolver $mediaResolver) {}

    /**
     * $title is only ever used as invisible OOXML document metadata (Word's own "Properties"
     * panel) — never rendered onto the page. The matching Web/PDF template
     * (reports.official.residence-business-check-batch) already starts directly with the report's
     * own content the same way; this used to also addText() an app-chrome-looking page title/
     * subtitle ("RESIDENCE & BUSINESS CHECKS" / "BRBI Credit Investigation Management System")
     * that had no equivalent there, making the two outputs look like different reports.
     *
     * @param  array<int, array<string, mixed>>  $photoSections
     */
    public function generate(array $photoSections, string $title): string
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
        // No title page here (see this method's own docblock above) — the first photo section's
        // own leading page break must be skipped so the report opens directly on real content.
        $this->skipNextPageBreak = true;

        // Downloads any Cloudinary-backed item's bytes into a temp file right here, only now that
        // a DOCX genuinely needs them — see ReportMediaResolver's own docblock.
        $photoSections = $this->mediaResolver->resolve($photoSections);
        foreach ($photoSections as $photoSection) {
            $this->photoSection($section, $photoSection);
        }

        // Suppressed: on some server environments sys_get_temp_dir() itself isn't writable by the
        // PHP process (e.g. resolves to a protected system directory), and tempnam() falls back to
        // its own OS-level temp location automatically — it still returns a real, writable path
        // either way. Without the @, that fallback's own informational E_WARNING gets escalated
        // into an uncaught ErrorException by Laravel's error handler, crashing this export outright
        // even though nothing was actually broken.
        $temporary = @tempnam(sys_get_temp_dir(), 'brbi-batch-docx-');
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
            // Only safe to delete embedImage()'s own WebP-conversion temp files, and the
            // mediaResolver's own Cloudinary-download temp files, now that save() has fully
            // finished reading them into the package — see embedImage()'s own docblock.
            $this->cleanupTemporaryEmbeddedImages();
            $this->mediaResolver->cleanup();
        }
    }
}
