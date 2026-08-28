<?php

namespace Tests\Feature\ClientFolders;

use Tests\TestCase;

class ResidenceCheckActionsMenuTest extends TestCase
{
    public function test_only_residence_view_photos_is_removed_from_the_check_actions(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));
        $residenceStart = strpos($template, '<x-ui.context-menu label="Residence Check actions">');
        $businessStart = strpos($template, '<x-ui.context-menu label="Business Check actions">');

        $this->assertNotFalse($residenceStart);
        $this->assertNotFalse($businessStart);

        $residenceMenu = substr($template, $residenceStart, $businessStart - $residenceStart);
        $this->assertStringNotContainsString('View Photos', $residenceMenu);
        $this->assertStringNotContainsString('residence-check-photos-', $residenceMenu);
        $this->assertStringContainsString('data-check-report-title="Residence Check"', $residenceMenu);
        $this->assertStringContainsString('data-check-row-print', $residenceMenu);
        $this->assertStringContainsString('data-check-row-pdf-submit', $residenceMenu);
        $this->assertStringContainsString('data-check-row-docx-submit', $residenceMenu);
        $this->assertStringContainsString('delete-residence-check-', $residenceMenu);
    }
}
