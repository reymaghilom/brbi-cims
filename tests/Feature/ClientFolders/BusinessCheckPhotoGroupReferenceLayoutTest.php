<?php

namespace Tests\Feature\ClientFolders;

use App\Services\Reports\OfficialReportDataBuilder;
use App\Services\Reports\ResidenceBusinessCheckBatchDocxExporter;
use DOMDocument;
use DOMXPath;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

class BusinessCheckPhotoGroupReferenceLayoutTest extends TestCase
{
    public function test_photo_groups_are_paginated_independently_with_at_most_two_photos(): void
    {
        $method = new ReflectionMethod(OfficialReportDataBuilder::class, 'paginateBusinessPhotos');
        $builder = (new ReflectionClass(OfficialReportDataBuilder::class))->newInstanceWithoutConstructor();
        $groups = [
            ['caption' => 'First group remarks', 'photos' => [['id' => 1], ['id' => 2], ['id' => 3]]],
            ['caption' => 'Second group remarks', 'photos' => [['id' => 4], ['id' => 5]]],
        ];

        $pages = $method->invoke($builder, $groups);

        $this->assertSame([2, 1, 2], array_map(fn (array $page) => count($page['photos']), $pages));
        $this->assertSame('First group remarks', $pages[0]['caption']);
        $this->assertNull($pages[1]['caption'], 'A continuing group must not repeat its caption.');
        $this->assertSame('Second group remarks', $pages[2]['caption']);
    }

    public function test_html_and_docx_follow_the_authoritative_group_geometry_and_order(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'business-reference-image-');
        $docx = tempnam(sys_get_temp_dir(), 'business-reference-docx-');
        file_put_contents($image, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
        $photo = fn (string $name) => ['file_name' => $name, 'media_type' => 'photo', 'image_path' => $image, 'web_url' => '/'.$name, 'cloud' => null];
        $pages = [];
        foreach (['First group remarks', 'Second group remarks', 'Third group remarks', 'Fourth group remarks'] as $groupIndex => $caption) {
            $pages[] = ['caption' => $caption, 'photos' => [$photo("group-{$groupIndex}-1.jpg"), $photo("group-{$groupIndex}-2.jpg")]];
        }
        $sections = [[
            'category' => 'Business', 'party_label' => 'Applicant Name', 'subject' => 'Test Applicant',
            'location' => 'Test Location', 'heading' => 'Business Check', 'business_name' => 'Test Store',
            'remarks' => 'Test Remarks', 'ci_date' => 'August 28, 2026', 'ci' => 'Rey',
            'photo_pages' => $pages,
            'competitor_photo_pages' => [['caption' => 'Competitors', 'photos' => [$photo('competitor.jpg')]]],
            'google_map' => ['image_path' => $image, 'web_url' => '/map.jpg', 'cloud' => null],
        ]];

        try {
            $html = view('reports.official.residence-business-check-batch', [
                'photoSections' => $sections, 'pdfMode' => true, 'title' => 'Business Check',
                'clientFolder' => null, 'personParams' => [],
            ])->render();
            $document = new DOMDocument;
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);
            $businessPages = $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " business-check-page ")]');

            $this->assertCount(5, $businessPages, 'Four Photo Groups and Competitors must each have their own page-flow wrapper.');
            foreach ($businessPages as $page) {
                $this->assertLessThanOrEqual(2, $xpath->query('.//figure', $page)->length);
            }
            $captionUnits = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " caption-photo-unit ")]');
            $this->assertCount(5, $captionUnits, 'Every nonblank Business/Competitor caption must wrap its first related photo only.');
            foreach ($captionUnits as $captionUnit) {
                $this->assertSame(1, $xpath->query('./p[contains(concat(" ", normalize-space(@class), " "), " business-group-caption ")]', $captionUnit)->length);
                $this->assertSame(1, $xpath->query('./figure', $captionUnit)->length);
            }
            $this->assertStringContainsString('.business-check-page { padding: .55in;', $html);
            $this->assertStringContainsString('font-size: 12pt', $html);
            $this->assertStringContainsString('.business-check-page .photo { margin: .04in 0 0; }', $html);
            $this->assertStringContainsString('.caption-photo-unit { page-break-inside: avoid; break-inside: avoid-page; }', $html);
            $this->assertStringContainsString('First group remarks', $html);
            $this->assertLessThan(strpos($html, 'Competitors'), strpos($html, 'Fourth group remarks'));
            $this->assertLessThan(strpos($html, 'Google Map'), strpos($html, 'Competitors'));

            $bytes = app(ResidenceBusinessCheckBatchDocxExporter::class)->generate($sections, 'BUSINESS CHECK');
            file_put_contents($docx, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($docx));
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertStringContainsString('w:w="9360"', $xml);
            $this->assertStringContainsString('w:left="792"', $xml);
            $this->assertStringContainsString('width:468pt', $xml);
            $this->assertStringContainsString('<w:pgSz w:orient="portrait" w:w="12240" w:h="18720"/>', $xml);
            $this->assertStringContainsString('<w:pgMar w:top="648" w:right="648" w:bottom="648" w:left="648"', $xml);
            $this->assertStringNotContainsString('<w:br w:type="page"/>', $xml, 'Standalone page-break paragraphs can create a completely blank Word page after naturally paginated photos.');
            $this->assertSame(4, substr_count($xml, '<w:pageBreakBefore'), 'Business pages 2-4 and Competitors must start on real content paragraphs.');
            $this->assertMatchesRegularExpression('/<w:pPr>.*?<w:keepNext w:val="1"\/>.*?<w:keepLines w:val="1"\/>.*?<\/w:pPr>.*?First group remarks/s', $xml);
            $this->assertMatchesRegularExpression('/<w:pPr>.*?<w:keepNext w:val="1"\/>.*?<w:keepLines w:val="1"\/>.*?<w:pageBreakBefore w:val="1"\/>.*?<\/w:pPr>.*?Second group remarks/s', $xml);
            $this->assertStringNotContainsString('group-0-1.jpg', $xml);
            $this->assertLessThan(strpos($xml, 'Second group remarks'), strpos($xml, 'First group remarks'));
            $this->assertLessThan(strpos($xml, 'Third group remarks'), strpos($xml, 'Second group remarks'));
            $this->assertLessThan(strpos($xml, 'Fourth group remarks'), strpos($xml, 'Third group remarks'));
            $this->assertLessThan(strpos($xml, 'Competitors'), strpos($xml, 'Fourth group remarks'));
            $this->assertLessThan(strpos($xml, 'Google Map'), strpos($xml, 'Competitors'));
        } finally {
            @unlink($image);
            @unlink($docx);
        }
    }

    public function test_blank_captions_render_photos_without_an_empty_keep_together_wrapper(): void
    {
        $photo = fn (string $name) => [
            'file_name' => $name,
            'media_type' => 'photo',
            'image_path' => '/'.$name,
            'web_url' => '/'.$name,
            'cloud' => null,
        ];
        $html = view('reports.official.residence-business-check-batch', [
            'photoSections' => [[
                'category' => 'Business', 'party_label' => 'Applicant Name', 'subject' => 'Test Applicant',
                'location' => 'Test Location', 'heading' => 'Business Check', 'business_name' => 'Test Store',
                'remarks' => null, 'ci_date' => 'August 28, 2026', 'ci' => 'Rey',
                'photo_pages' => [['caption' => null, 'photos' => [$photo('first.jpg'), $photo('second.jpg')]]],
                'competitor_photo_pages' => [], 'google_map' => null,
            ]],
            'pdfMode' => true, 'title' => 'Business Check', 'clientFolder' => null, 'personParams' => [],
        ])->render();

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);

        $this->assertSame(0, $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " caption-photo-unit ")]')->length);
        $this->assertSame(2, $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " business-check-page ")]//figure')->length);
    }

    public function test_residence_caption_stays_with_its_photo_in_html_pdf_and_docx(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'residence-caption-image-');
        $docx = tempnam(sys_get_temp_dir(), 'residence-caption-docx-');
        file_put_contents($image, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
        $sections = [[
            'category' => 'Residence', 'party_label' => 'Applicant Name', 'subject' => 'Test Applicant',
            'location' => 'Test Location', 'heading' => 'Residence Check', 'business_name' => null,
            'remarks' => null, 'ci_date' => 'August 28, 2026', 'ci' => 'Rey', 'google_map' => null,
            'media' => [
                ['caption' => 'Front residence remarks', 'media_type' => 'photo', 'image_path' => $image, 'web_url' => '/front.jpg', 'cloud' => null],
                ['caption' => null, 'media_type' => 'photo', 'image_path' => $image, 'web_url' => '/back.jpg', 'cloud' => null],
            ],
        ]];

        try {
            $html = view('reports.official.residence-business-check-batch', [
                'photoSections' => $sections, 'pdfMode' => true, 'title' => 'Residence Check',
                'clientFolder' => null, 'personParams' => [],
            ])->render();
            $document = new DOMDocument;
            @$document->loadHTML($html);
            $xpath = new DOMXPath($document);

            $this->assertSame(1, $xpath->query('//figure[contains(concat(" ", normalize-space(@class), " "), " caption-photo-unit ")]')->length);
            $this->assertSame(2, $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " photo-report-page ")]//figure')->length);

            $bytes = app(ResidenceBusinessCheckBatchDocxExporter::class)->generate($sections, 'RESIDENCE CHECK');
            file_put_contents($docx, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($docx));
            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertMatchesRegularExpression('/<w:pPr>.*?<w:keepNext w:val="1"\/>.*?<w:keepLines w:val="1"\/>.*?<\/w:pPr>.*?Front residence remarks/s', $xml);
        } finally {
            @unlink($image);
            @unlink($docx);
        }
    }
}
