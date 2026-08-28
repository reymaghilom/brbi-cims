<?php

namespace Tests\Feature\ClientFolders;

use Tests\TestCase;

class BusinessCheckActionsMenuTest extends TestCase
{
    public function test_business_view_photos_is_removed_while_other_actions_remain(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));
        $menuStart = strpos($template, '<x-ui.context-menu label="Business Check actions">');
        $menuEnd = strpos($template, '</x-ui.context-menu>', $menuStart);

        $this->assertNotFalse($menuStart);
        $this->assertNotFalse($menuEnd);
        $menu = substr($template, $menuStart, $menuEnd - $menuStart);

        $this->assertStringNotContainsString('View Photos', $menu);
        $this->assertStringNotContainsString('business-check-photos-', $menu);
        $this->assertStringContainsString('data-check-report-title="Business Checks"', $menu);
        $this->assertStringContainsString('data-check-row-print', $menu);
        $this->assertStringContainsString('data-check-row-pdf-submit', $menu);
        $this->assertStringContainsString('data-check-row-docx-submit', $menu);
        $this->assertStringContainsString('delete-business-check-', $menu);
    }
}
