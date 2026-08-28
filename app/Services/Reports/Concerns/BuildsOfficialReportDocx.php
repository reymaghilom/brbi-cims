<?php

namespace App\Services\Reports\Concerns;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use Throwable;

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
        // PhpWord defaults to NOT escaping addText()/addTextRun() content (Settings::$outputEscapingEnabled
        // starts false — it writes text raw into word/document.xml instead of via XMLWriter::text()) — so
        // any text containing a bare &, <, or > (e.g. this very report's own "RESIDENCE & BUSINESS CHECKS"
        // title, or a saved Location/business name with an ampersand in it) produces invalid XML that Word
        // refuses to open ("Word experienced an error trying to open the file."), even though the .docx's
        // ZIP/OPC structure itself is perfectly valid. This must be enabled before any addText() call below.
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

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

    /**
     * Full usable page width for an image (in points — PhpWord's Image style unit), derived from
     * WIDTH_DXA (20 dxa = 1pt) with a small safety margin. Matches the reference residence.docx's
     * own approach: every embedded picture is exactly the page's usable content width, natural
     * aspect-ratio height (no crop, no forced square, no fixed box) — see residencePhotoPages().
     */
    private const RESIDENCE_IMAGE_WIDTH_PT = 540;

    /** Maximum Business Check image width from the authoritative business.docx reference. */
    private const BUSINESS_CHECK_IMAGE_WIDTH_PT = 468;

    private const BUSINESS_CHECK_WIDTH_DXA = 9360;

    /**
     * Set by a caller that renders no title page of its own (currently only
     * ResidenceBusinessCheckBatchDocxExporter) so the very first photo section's own leading
     * addPageBreak() below is skipped — matching the Web/PDF template's own
     * `.photo-page:first-child { page-break-before: avoid; }` rule, which exists for exactly the
     * same reason: without a title page absorbing it, that forced break would otherwise leave an
     * empty first page before the report's actual content. PhpWordOfficialReportGenerator (CIBI/
     * Business's versioned reports) still renders its own title() page first and never sets this,
     * so its behavior is completely unchanged.
     */
    private bool $skipNextPageBreak = false;

    private function pageBreakBeforeSection(Section $section): void
    {
        if ($this->skipNextPageBreak) {
            $this->skipNextPageBreak = false;

            return;
        }
        $section->addPageBreak();
    }

    private function photoSection(Section $section, array $photoSection): void
    {
        if (($photoSection['category'] ?? null) === 'Residence') {
            $this->residencePhotoPages($section, $photoSection);

            return;
        }
        $this->businessPhotoPages($section, $photoSection);
    }

    /**
     * Business Check photo pages, matching business1.docx/business1.pdf (the visual reference for
     * this layout): one page break before the whole section, the same compact 2-column header
     * Residence Check's own photo pages use (residenceHeaderTable()) — no large report title, no
     * "DOCUMENTATION" subtitle — then Business Photos (default group first, then every additional
     * Photo Group in saved order) pre-chunked to a strict maximum of 2 photos per page by
     * OfficialReportDataBuilder::paginateBusinessPhotos() — the single source of truth Web/PDF/DOCX
     * all share, so this method only ever renders whatever page list it's given rather than
     * deciding pagination itself. A group's own caption (if any) rides on that group's own first
     * page only, never repeated on its continuation page(s). Competitors renders next (its own
     * pre-chunked pages, same 2-per-page rule, never mixed with Business Photos), then Google Map —
     * this report's own final section, always last, never before Competitors and never mixed with
     * either photo section.
     */
    private function businessPhotoPages(Section $section, array $photoSection): void
    {
        $this->pageBreakBeforeSection($section);
        $this->residenceHeaderTable($section, $photoSection, self::BUSINESS_CHECK_WIDTH_DXA);

        $businessPages = $photoSection['photo_pages'] ?? [];
        $competitorPages = $photoSection['competitor_photo_pages'] ?? [];
        if ($businessPages === [] && $competitorPages === []) {
            $section->addText('No media is linked to this section.', ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => '555555'], ['spaceBefore' => 80]);
        } elseif ($businessPages !== []) {
            // The very first page is left to flow naturally right after the header table above (no
            // forced break) — only page 2+ within Business Photos forces one.
            $this->renderBusinessPhotoPages($section, $businessPages);
        }

        if ($competitorPages !== []) {
            // Competitors starts on a fresh page, but the break belongs to its first real content
            // paragraph so Word cannot strand a standalone break on an otherwise empty sheet.
            $this->renderBusinessPhotoPages($section, $competitorPages, true);
        }

        if ($photoSection['google_map'] ?? null) {
            // Smart placement, same as Residence Check's own Google Map: no forced page break here —
            // keepNext/keepLines inside googleMapPage() is what actually decides pagination, letting
            // it flow right after Competitors (or Business Photos, if there were no Competitors) when
            // it fits, and pushed to its own fresh page, heading and screenshot together, when it
            // doesn't. Either way it never renders before Competitors and never shares a page with
            // Business/Competitor Photos, since those always end with their own forced break.
            $this->googleMapPage($section, $photoSection['google_map'], ['width' => self::BUSINESS_CHECK_IMAGE_WIDTH_PT, 'alignment' => 'center']);
        }
    }

    /**
     * Renders a pre-chunked (<=2 photos each) page list. Page 2+ starts by applying pageBreakBefore
     * to its first real caption/photo paragraph; unlike a standalone addPageBreak(), this cannot be
     * carried onto a new physical page and then create another, completely blank page. A caption
     * remains attached to its first photo through keepNext/keepLines.
     */
    private function renderBusinessPhotoPages(Section $section, array $pages, bool $breakBeforeFirstPage = false): void
    {
        foreach ($pages as $index => $page) {
            $startsNewPage = $index > 0 || ($index === 0 && $breakBeforeFirstPage);
            if (filled($page['caption'])) {
                $section->addText($page['caption'], ['name' => 'Calibri', 'size' => 12], [
                    'indentation' => ['left' => intdiv(self::WIDTH_DXA - self::BUSINESS_CHECK_WIDTH_DXA, 2)],
                    'spaceBefore' => 0,
                    'spaceAfter' => 40,
                    'keepNext' => true,
                    'keepLines' => true,
                    'pageBreakBefore' => $startsNewPage,
                ]);
            }
            foreach ($page['photos'] as $photoIndex => $item) {
                if ($photoIndex > 0) {
                    $section->addTextBreak(1);
                }
                $imageStyle = ['width' => self::BUSINESS_CHECK_IMAGE_WIDTH_PT, 'alignment' => 'center'];
                $firstPhotoCarriesBreak = $photoIndex === 0 && $startsNewPage && blank($page['caption']);
                if ($firstPhotoCarriesBreak) {
                    $run = $section->addTextRun(['alignment' => 'center', 'pageBreakBefore' => true]);
                    $embedded = $item['image_path'] && $item['media_type'] === 'photo'
                        && $this->embedImage($run, $item['image_path'], $imageStyle);
                    if (! $embedded) {
                        $run->addText('Media reference: '.$item['file_name'].' (image content unavailable)', ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => '555555']);
                    }

                    continue;
                }
                $embedded = $item['image_path'] && $item['media_type'] === 'photo'
                    && $this->embedImage($section, $item['image_path'], $imageStyle);
                if (! $embedded) {
                    $section->addText('Media reference: '.$item['file_name'].' (image content unavailable)', ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => '555555'], ['alignment' => 'center', 'spaceAfter' => 50]);
                }
            }
        }
    }

    /**
     * Residence Check photo pages, redesigned to match the reference residence.docx (design
     * source of truth — see the Residence/Business report work item for its path) instead of
     * Business's own chunked 2-per-page grid: one page break before the whole block, the
     * Applicant/Co-Maker info line, then every Residence Picture stacked vertically at (nearly)
     * the full usable page width with its own natural aspect-ratio height — never a fixed box,
     * never forced 2-per-page — so Word's native reflow decides where a large image actually
     * breaks across pages, exactly like the reference document's own natural flow. Google Map (if
     * any) always follows every picture, never mixed in between.
     */
    private function residencePhotoPages(Section $section, array $photoSection): void
    {
        $this->pageBreakBeforeSection($section);
        $this->residenceHeaderTable($section, $photoSection);
        $media = $photoSection['media'];
        if ($media === []) {
            $section->addText('No media is linked to this section.', ['name' => 'Calibri', 'size' => 11, 'italic' => true, 'color' => '555555'], ['spaceBefore' => 120]);
        }
        foreach ($media as $index => $item) {
            // A full addTextBreak(1) before every image (including the first) left a large,
            // report-card-like gap between the information block and the first Residence Picture —
            // only the space between successive photos actually needs that full blank line.
            if ($index > 0) {
                $section->addTextBreak(1);
            }
            if (filled($item['caption'] ?? null)) {
                $section->addText($item['caption'], ['name' => 'Calibri', 'size' => 11, 'bold' => true], ['spaceBefore' => $index === 0 ? 80 : 0, 'spaceAfter' => 40, 'keepNext' => true, 'keepLines' => true]);
            } elseif ($index === 0) {
                // No caption on the first photo: a small paragraph spacing still needs to separate
                // it from the information block above — a tiny (4pt) near-invisible spacer line
                // gives modest, controlled breathing room without the old full blank line's height.
                $section->addText('', ['size' => 4], ['spaceBefore' => 0, 'spaceAfter' => 0]);
            }
            $embedded = $item['image_path'] && $item['media_type'] === 'photo'
                && $this->embedImage($section, $item['image_path'], ['width' => self::RESIDENCE_IMAGE_WIDTH_PT, 'alignment' => 'center']);
            if (! $embedded) {
                $section->addText('Image unavailable', ['name' => 'Calibri', 'size' => 11, 'italic' => true, 'color' => '555555'], ['alignment' => 'center', 'spaceAfter' => 80]);
            }
        }
        if ($photoSection['google_map'] ?? null) {
            $this->googleMapPage($section, $photoSection['google_map'], ['width' => self::RESIDENCE_IMAGE_WIDTH_PT, 'alignment' => 'center']);
        }
    }

    /**
     * Two-column, borderless "Applicant/Co-Maker Name, Location, Subject, [Remarks] | Date, CI"
     * header — originally built for Residence Check's own photo pages, now shared by Business
     * Check's compact header too (see business1.docx/business1.pdf, the visual reference for that
     * layout: no large report title, no "DOCUMENTATION" subtitle). A borderless table rather than
     * the reference's literal tab-stopped paragraphs, deliberately: the reference's own raw tab
     * characters only line up for that one sample's specific text lengths, and would misalign for
     * a longer/shorter real Applicant name or Location, whereas this table keeps the same
     * side-by-side visual result while staying correct for any real saved data. Font/size/weight/
     * spacing (Calibri 12pt, not bold, no space after each line) are read directly from the
     * reference's word/styles.xml and word/document.xml. Remarks is entirely omitted (not even a
     * blank "Remarks:" line) when the check has none — "Subject" becomes the left column's last
     * line instead. Business Check's own income source/business name (when it has one — Residence
     * never sets this key) rides in parentheses right after the Subject line's "Business Check",
     * matching the reference's "Business Check (Retail Store)".
     */
    private function residenceHeaderTable(Section $section, array $photoSection, int $widthDxa = self::WIDTH_DXA): void
    {
        // borderSize alone is NOT enough to make a PhpWord table genuinely borderless: its Word2007
        // writer has no way to emit OOXML's own w:val="none" for table borders (only Border, used
        // by cells/images/paragraphs, supports that — Table's own border writer always defaults to
        // w:val="single"), so a 0-width single-style border is still what gets written, and Word is
        // known to silently render that at a visible minimum hairline width regardless. Matching the
        // border color to the page's own white background is what actually guarantees it stays
        // invisible either way — the standard workaround for this specific PhpWord Table limitation.
        $tableStyle = ['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMarginTop' => 0, 'cellMarginBottom' => 0, 'cellMarginLeft' => 0, 'cellMarginRight' => 0, 'width' => $widthDxa, 'unit' => 'dxa'];
        if ($widthDxa !== self::WIDTH_DXA) {
            $tableStyle['alignment'] = 'center';
        }
        $table = $section->addTable($tableStyle);
        $table->addRow();
        $left = $table->addCell(intdiv($widthDxa * 2, 3));
        $hasRemarks = filled($photoSection['remarks'] ?? null);
        $subjectLine = $photoSection['heading'].(filled($photoSection['business_name'] ?? null) ? ' ('.$photoSection['business_name'].')' : '');
        $this->residenceInfoLine($left, ($photoSection['party_label'] ?? 'Applicant Name'), $photoSection['subject']);
        $this->residenceInfoLine($left, 'Location', $photoSection['location'] ?: '—');
        $this->residenceInfoLine($left, 'Subject', $subjectLine);
        if ($hasRemarks) {
            $this->residenceInfoLine($left, 'Remarks', $photoSection['remarks']);
        }
        $right = $table->addCell($widthDxa - intdiv($widthDxa * 2, 3));
        $this->residenceInfoLine($right, 'Date', $photoSection['ci_date']);
        $this->residenceInfoLine($right, 'CI', $photoSection['ci'] ?: '—');
    }

    private function residenceInfoLine(AbstractContainer $container, string $label, string $value): void
    {
        $run = $container->addTextRun(['spaceAfter' => 0]);
        $run->addText($label.': ', ['name' => 'Calibri', 'size' => 12]);
        $run->addText($value, ['name' => 'Calibri', 'size' => 12]);
    }

    /**
     * "Google Map" block rendered after a Residence or Business Check's photo page(s), only when a
     * Map Screenshot was actually saved for it — never the live Google Map, never generated from
     * coordinates, and never a raw filename. Smart placement: no forced page break before this —
     * `keepNext`/`keepLines` (rather than an explicit break) is what actually decides pagination,
     * letting Word's own live reflow keep the heading and screenshot flowing right after the last
     * Residence Picture when it fits there, and pushed to the next page together, never split,
     * when it doesn't. $imageStyle defaults to Business's own existing sizing; residencePhotoPages()
     * passes the same full-width sizing used for Residence Pictures.
     */
    private function googleMapPage(Section $section, array $googleMap, ?array $imageStyle = null): void
    {
        $section->addText('Google Map', 'SectionHeadingFont', ['alignment' => 'center', 'spaceAfter' => 100, 'keepNext' => true, 'keepLines' => true]);
        $embedded = ! empty($googleMap['image_path']) && $this->embedImage($section, $googleMap['image_path'], $imageStyle ?? ['width' => 520, 'height' => 620, 'ratio' => true, 'alignment' => 'center']);
        if (! $embedded) {
            $section->addText('Map image unavailable', ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => '555555'], ['alignment' => 'center']);
        }
    }

    /**
     * Paths of WebP→PNG conversion temp files created by embedImage() below, kept alive until
     * cleanupTemporaryEmbeddedImages() runs. PhpWord's Word2007 writer does not read an image's
     * bytes at addImage() time — Writer\AbstractWriter::addFilesToPackage() re-opens the *original
     * file path* and hands it to ZipArchive::addFile(), which itself defers the actual disk read
     * until ZipArchive::close() inside save(). Deleting a converted temp file any earlier (e.g.
     * right after addImage() returns, as a naive try/finally would) leaves a dangling
     * word/_rels/document.xml.rels relationship pointing at a media part that was never actually
     * written into the package — exactly the kind of corruption that makes Word refuse to open the
     * file with no more explanation than "Word experienced an error trying to open the file."
     */
    private array $temporaryEmbeddedImages = [];

    /**
     * Embeds a local image file into the DOCX, returning whether it actually succeeded — a
     * corrupt/unreadable file, or one in a format PhpWord's Word2007 writer doesn't natively
     * support (its `addImage()` only accepts JPEG/GIF/PNG/BMP/TIFF — notably NOT WebP, which this
     * app's own upload validation otherwise allows everywhere), must never crash the whole
     * document. A WebP source is transparently re-encoded to PNG first (GD already decodes WebP
     * for this app's own thumbnail generation) so the CI's actual saved photo still ends up in the
     * DOCX rather than a placeholder; only a genuinely undecodable file falls through to false.
     */
    private function embedImage(AbstractContainer $section, string $path, array $style): bool
    {
        try {
            $section->addImage($path, $style);

            return true;
        } catch (Throwable) {
            $converted = $this->convertToEmbeddableImage($path);
            if ($converted === null) {
                return false;
            }
            try {
                $section->addImage($converted, $style);
                // Must survive until after the writer's save() has fully finished — see the
                // property docblock above. Only cleaned up by cleanupTemporaryEmbeddedImages().
                $this->temporaryEmbeddedImages[] = $converted;

                return true;
            } catch (Throwable) {
                @unlink($converted);

                return false;
            }
        }
    }

    /** Re-encodes an image PhpWord's Word2007 writer can't embed directly (e.g. WebP) into a temporary PNG file. Returns null if the source can't be decoded at all (corrupt/unreadable). */
    private function convertToEmbeddableImage(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        $source = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        // PhpWord detects image type from the file's actual content (getimagesize()), never from
        // its filename extension, so the plain tempnam() path is used as-is — no ".png" suffix
        // needed, and no separate throwaway file left behind. @ suppressed for the same reason as
        // every other tempnam(sys_get_temp_dir(), ...) call in this trait/its callers: an
        // unwritable sys_get_temp_dir() still yields a real, usable fallback path, but PHP's own
        // informational warning about that fallback must not be allowed to crash generation.
        $temporary = @tempnam(sys_get_temp_dir(), 'brbi-docx-img-');
        if ($temporary === false) {
            imagedestroy($source);

            return null;
        }
        $saved = imagepng($source, $temporary);
        imagedestroy($source);

        return $saved ? $temporary : null;
    }

    /** Must be called once, after IOFactory::createWriter(...)->save() has fully completed (success or failure) — never any earlier, per embedImage()'s own docblock. */
    private function cleanupTemporaryEmbeddedImages(): void
    {
        foreach ($this->temporaryEmbeddedImages as $path) {
            @unlink($path);
        }
        $this->temporaryEmbeddedImages = [];
    }
}
