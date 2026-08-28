<?php

namespace Tests\Feature\ClientFolders;

use App\Services\Reports\Data\ReportRenderOptions;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class BusinessCheckReportPageFlowTest extends TestCase
{
    public function test_competitors_flow_into_the_final_google_map_without_a_forced_page_wrapper(): void
    {
        $html = view('reports.official.residence-business-check-batch', [
            'photoSections' => [[
                'category' => 'Business',
                'party_label' => 'Applicant Name',
                'subject' => 'Test Applicant',
                'location' => 'Test Location',
                'heading' => 'Business Check',
                'business_name' => 'Test Store',
                'remarks' => null,
                'ci_date' => 'August 28, 2026',
                'ci' => 'Rey',
                'photo_pages' => [],
                'competitor_photo_pages' => [[
                    'caption' => 'Competitors',
                    'photos' => [[
                        'file_name' => 'competitor.jpg',
                        'media_type' => 'photo',
                        'image_path' => '/competitor.jpg',
                        'web_url' => '/competitor.jpg',
                    ]],
                ]],
                'google_map' => [
                    'image_path' => '/map.jpg',
                    'web_url' => '/map.jpg',
                ],
            ]],
            'pdfMode' => true,
            'title' => 'Business Check',
            'clientFolder' => null,
            'personParams' => [],
        ])->render();

        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $competitorImage = $xpath->query('//img[@src="/competitor.jpg"]')->item(0);
        $this->assertNotNull($competitorImage);

        $competitorPage = $xpath->query('ancestor::section[contains(concat(" ", normalize-space(@class), " "), " photo-page ")][1]', $competitorImage)->item(0);
        $this->assertNotNull($competitorPage);
        $mapSection = $xpath->query('.//section[contains(concat(" ", normalize-space(@class), " "), " google-map-page ")]', $competitorPage)->item(0);

        $this->assertNotNull($mapSection, 'Google Map must remain last while sharing the final Competitors printable flow.');
        $this->assertNotContains('official-report-page', preg_split('/\s+/', trim($mapSection->getAttribute('class'))));
        $this->assertLessThan(strpos($html, 'Google Map'), strpos($html, '/competitor.jpg'));
        $this->assertStringContainsString('@page { size: 8.5in 13in; margin: .45in; }', $html);

        $paper = ReportRenderOptions::brbiDefault();
        $this->assertSame(8.5, $paper->widthInches);
        $this->assertSame(13.0, $paper->heightInches);
    }
}
