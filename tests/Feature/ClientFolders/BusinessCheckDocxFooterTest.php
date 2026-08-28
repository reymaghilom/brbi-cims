<?php

namespace Tests\Feature\ClientFolders;

use App\Services\Reports\ResidenceBusinessCheckBatchDocxExporter;
use Tests\TestCase;
use ZipArchive;

class BusinessCheckDocxFooterTest extends TestCase
{
    public function test_business_check_docx_omits_the_official_report_page_label_and_keeps_report_order(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'business-check-image-');
        $docx = tempnam(sys_get_temp_dir(), 'business-check-docx-');
        file_put_contents($image, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));

        $photo = fn (string $name) => [
            'file_name' => $name,
            'media_type' => 'photo',
            'image_path' => $image,
            'web_url' => null,
            'cloud' => null,
        ];
        $sections = [[
            'category' => 'Business',
            'party_label' => 'Applicant Name',
            'subject' => 'Test Applicant',
            'location' => 'Test Location',
            'heading' => 'Business Check',
            'business_name' => 'Test Store',
            'remarks' => 'Test Remarks',
            'ci_date' => 'August 28, 2026',
            'ci' => 'Rey',
            'photo_pages' => [
                ['caption' => null, 'photos' => [$photo('business.jpg')]],
                ['caption' => 'Additional Photo Group', 'photos' => [$photo('additional.jpg')]],
            ],
            'competitor_photo_pages' => [
                ['caption' => 'Competitors', 'photos' => [$photo('competitor.jpg')]],
            ],
            'google_map' => [
                'image_path' => $image,
                'web_url' => null,
                'cloud' => null,
            ],
        ]];

        try {
            $bytes = app(ResidenceBusinessCheckBatchDocxExporter::class)->generate($sections, 'BUSINESS CHECK');
            file_put_contents($docx, $bytes);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($docx));
            $documentXml = $zip->getFromName('word/document.xml');
            $packageXml = '';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name !== false && str_ends_with($name, '.xml')) {
                    $packageXml .= (string) $zip->getFromIndex($index);
                }
            }
            $zip->close();

            $this->assertStringNotContainsString('BRBI Official Report', $packageXml);
            $documentText = html_entity_decode(strip_tags((string) $documentXml));
            $this->assertStringContainsString('Applicant Name: Test Applicant', $documentText);
            $this->assertStringContainsString('Location: Test Location', $documentText);
            $this->assertStringContainsString('Remarks: Test Remarks', $documentText);
            $this->assertLessThan(strpos($documentText, 'Additional Photo Group'), strpos($documentText, 'Test Store'));
            $this->assertLessThan(strpos($documentText, 'Competitors'), strpos($documentText, 'Additional Photo Group'));
            $this->assertLessThan(strpos($documentText, 'Google Map'), strpos($documentText, 'Competitors'));
        } finally {
            @unlink($image);
            @unlink($docx);
        }
    }
}
