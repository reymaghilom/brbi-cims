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
                $this->assertLessThanOrEqual(2, $xpath->query('./figure', $page)->length);
            }
            $this->assertStringContainsString('.business-check-page { padding: .55in;', $html);
            $this->assertStringContainsString('font-size: 12pt', $html);
            $this->assertStringContainsString('.business-check-page .photo { margin: .04in 0 0; }', $html);
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
}
