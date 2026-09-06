<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use Tests\TestCase;

/**
 * Residence Check DOCX/PDF downloads used to crash with an uncaught 500 whenever a Residence
 * Picture or Map Screenshot was uploaded as WebP (a format this app's own upload validation
 * allows everywhere): PhpWord's Word2007 writer only embeds JPEG/GIF/PNG/BMP/TIFF, so
 * Section::addImage() threw UnsupportedImageTypeException, uncaught, straight out of
 * ResidenceBusinessCheckBatchDocxExporter — reproducible only against real files on disk, so these
 * deliberately do not fake storage and clean up after themselves. Local evidence lands in the
 * per-test temporary CI Team root that TestCase configures, and safeMediaPath() finds it through
 * CiTeamDocumentStorage::evidenceDisk() rather than any hard-coded root.
 */
class ResidenceReportDocxPdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    private ?ClientFolder $cleanupFolder = null;

    protected function tearDown(): void
    {
        if ($this->cleanupFolder) {
            Storage::disk('local')->deleteDirectory('client-media/'.$this->cleanupFolder->id);
        }
        parent::tearDown();
    }

    public function test_docx_download_succeeds_for_a_residence_check_with_webp_photo_and_map_screenshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->cleanupFolder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.webp', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.webp', 800, 600)->size(400),
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    /**
     * The actually-corrupt-DOCX bug: PhpWord defaults Settings::$outputEscapingEnabled to false,
     * so Section::addText() writes content straight into word/document.xml with no XML escaping —
     * any bare &, <, or > (a saved Location or Remarks containing one, exercised below, is enough)
     * produced invalid XML that a ZIP tool still opens fine, but Word rejects with "Word
     * experienced an error trying to open the file." This asserts the .docx package is genuinely
     * well-formed: a
     * valid ZIP, every XML/rels part inside it parses, every relationship this document actually
     * declares points at a real embedded part (the webp→PNG conversion's own bug: its temp file
     * used to get deleted — inside embedImage()'s own finally — before the writer ever read it
     * during save(), leaving rIds that pointed at nothing), and PhpWord's own reader can reload it.
     */
    public function test_docx_package_is_well_formed_and_reopenable_with_ampersands_and_webp_images(): void
    {
        $ci = User::factory()->create();
        $folder = $this->cleanupFolder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Blk 5 M & M Subdivision, Brgy. San Jose',
            'photos' => [
                UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Back.webp', 900, 700)->size(500),
            ],
            'map_screenshot' => UploadedFile::fake()->image('Map.webp', 800, 600)->size(400),
            'remarks' => 'Confirmed via "Dela Cruz & Sons" store next door.',
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();

        $bytes = $response->streamedContent();
        $this->assertNotSame(0, strlen($bytes));
        $this->assertStringStartsWith("PK\x03\x04", $bytes, 'Response body is not a real ZIP package (looks like HTML/error content instead).');

        $path = tempnam(sys_get_temp_dir(), 'brbi-docx-assert-');
        file_put_contents($path, $bytes);

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path, \ZipArchive::CHECKCONS) === true, 'The .docx is not a structurally valid ZIP package.');

            foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/_rels/document.xml.rels'] as $required) {
                $this->assertNotFalse($zip->locateName($required), "Required OPC part missing: {$required}");
            }

            $declaredMedia = [];
            $relsXml = new \SimpleXMLElement($zip->getFromName('word/_rels/document.xml.rels'));
            foreach ($relsXml->Relationship as $relationship) {
                $target = (string) $relationship['Target'];
                if (str_starts_with($target, 'media/')) {
                    $declaredMedia[] = 'word/'.$target;
                }
            }
            $this->assertNotEmpty($declaredMedia, 'No embedded images were declared at all.');
            foreach ($declaredMedia as $mediaPart) {
                $this->assertNotFalse($zip->locateName($mediaPart), "word/_rels/document.xml.rels declares {$mediaPart} but it is not actually in the package (dangling relationship).");
                $this->assertGreaterThan(0, $zip->statName($mediaPart)['size'], "Embedded media part {$mediaPart} exists but is empty.");
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! str_ends_with($name, '.xml') && ! str_ends_with($name, '.rels')) {
                    continue;
                }
                libxml_use_internal_errors(true);
                $doc = new \DOMDocument;
                $valid = $doc->loadXML($zip->getFromName($name));
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $this->assertTrue($valid, "{$name} is not well-formed XML: ".($errors[0]->message ?? 'unknown error'));
            }
            $zip->close();

            // The strongest sanity check available short of Word itself: PhpWord's own reader must
            // be able to load the file back without throwing.
            $reopened = IOFactory::createReader('Word2007')->load($path);
            $this->assertNotEmpty($reopened->getSections());
        } finally {
            @unlink($path);
        }
    }

    /**
     * "RESIDENCE & BUSINESS CHECKS" / "BRBI Credit Investigation Management System" used to be
     * rendered as a page-1 title/subtitle — application/page chrome with no equivalent in the
     * matching Web/PDF template, which starts directly on the report's own content. The DOCX must
     * match that: the very first text run in the document is real report content (this person's own
     * saved data), never that old heading pair, and $title is only ever used as the file's own
     * invisible OOXML document-properties metadata (see
     * ResidenceBusinessCheckBatchDocxExporter::generate()'s own docblock).
     */
    public function test_docx_no_longer_contains_the_application_page_headings_and_opens_directly_on_report_content(): void
    {
        $ci = User::factory()->create();
        $folder = $this->cleanupFolder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remarks' => 'Gate was locked; neighbor confirmed residency.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'brbi-docx-heading-assert-');
        file_put_contents($path, $response->streamedContent());

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $documentXml = $zip->getFromName('word/document.xml');
            $this->assertFalse($zip->getFromName('word/footer1.xml'), 'Residence/Business DOCX must not contain the BRBI Official Report page footer.');
            $zip->close();

            $this->assertStringNotContainsString('RESIDENCE &amp; BUSINESS CHECKS', $documentXml);
            $this->assertStringNotContainsString('BRBI Credit Investigation Management System', $documentXml);
            $this->assertStringNotContainsString('BRBI Official Report', $documentXml);

            preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $documentXml, $matches);
            $textRuns = array_map('html_entity_decode', $matches[1]);
            $this->assertNotEmpty($textRuns);
            // The very first visible text in the whole document is real, dynamic report content —
            // never a static application heading.
            $this->assertStringNotContainsString('RESIDENCE', $textRuns[0]);
            $this->assertStringNotContainsString('BRBI', $textRuns[0]);
            $this->assertStringContainsString('Applicant Name', implode('', array_slice($textRuns, 0, 3)));
        } finally {
            @unlink($path);
        }
    }

    /**
     * The top information block ("Applicant Name / Location / Subject / Remarks | Date / CI") is
     * still built as a table for Word alignment (see residenceHeaderTable()'s own docblock), but it
     * must never visually read as a bordered form/card. PhpWord's Word2007 writer has no way to
     * emit OOXML's own w:val="none" for a table border — it always writes w:val="single", and Word
     * is known to still render that at a visible minimum width even at w:sz="0" — so the only
     * reliable way to guarantee it's actually invisible is matching the border color to the page's
     * own white background. This asserts every one of the six border sides (top/left/right/bottom/
     * insideH/insideV — insideV being the one that would otherwise show as a vertical line between
     * the left and right information columns) carries that white color.
     */
    public function test_docx_information_table_has_no_visible_borders(): void
    {
        $ci = User::factory()->create();
        $folder = $this->cleanupFolder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'brbi-docx-border-assert-');
        file_put_contents($path, $response->streamedContent());

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $documentXml = $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertMatchesRegularExpression('/<w:tbl>.*?<w:tblBorders>.*?<\/w:tblBorders>/s', $documentXml);
            preg_match('/<w:tbl>.*?<w:tblBorders>(.*?)<\/w:tblBorders>/s', $documentXml, $borderMatch);
            $tblBorders = $borderMatch[1];
            foreach (['top', 'left', 'right', 'bottom', 'insideH', 'insideV'] as $side) {
                $this->assertMatchesRegularExpression(
                    '/<w:'.$side.'[^>]*w:color="FFFFFF"/',
                    $tblBorders,
                    "The information table's {$side} border is not set to white — it may still render as a visible line."
                );
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * The information block used to be followed by a full addTextBreak(1) (one blank default-size
     * line) before EVERY Residence Picture, including the very first one — producing the same
     * report-card-like gap the reference design explicitly avoids. This asserts the first photo's
     * own paragraph carries only the small (4pt) spacer, never that larger default line height.
     */
    public function test_docx_has_only_compact_spacing_before_the_first_residence_picture(): void
    {
        $ci = User::factory()->create();
        $folder = $this->cleanupFolder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [
                UploadedFile::fake()->image('First.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500),
            ],
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'brbi-docx-spacing-assert-');
        file_put_contents($path, $response->streamedContent());

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $documentXml = $zip->getFromName('word/document.xml');
            $zip->close();

            // The information table is immediately followed by the tiny 4pt spacer paragraph (sz
            // is in half-points, so 4pt => "8"), not a full-size blank line.
            $tableEnd = strpos($documentXml, '</w:tbl>');
            $this->assertNotFalse($tableEnd);
            $afterTable = substr($documentXml, $tableEnd, 400);
            $this->assertStringContainsString('w:sz w:val="8"', $afterTable, 'Expected the small 4pt spacer paragraph directly after the information table.');
        } finally {
            @unlink($path);
        }
    }

    public function test_pdf_download_succeeds_for_a_residence_check_with_webp_photo_and_map_screenshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->cleanupFolder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.webp', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.webp', 800, 600)->size(400),
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
