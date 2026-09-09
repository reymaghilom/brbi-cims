<?php

namespace Tests\Feature\ClientFolders;

use Tests\TestCase;

class BusinessCheckActionsMenuTest extends TestCase
{
    public function test_business_view_photos_is_removed_while_other_actions_remain(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/partials/checks-listing.blade.php'));
        $menuStart = strpos($template, "route('client-folders.business-checks.edit'");
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

    /**
     * TASKS 9-12 — the same icon pair on the Business Check delete confirmation. The exact-person
     * $personParams in the action URL, the wording, the route and the method are all untouched.
     */
    public function test_the_business_check_delete_confirmation_leads_its_buttons_with_icons(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/partials/checks-listing.blade.php'));
        $start = strpos($template, 'id="delete-business-check-');
        $this->assertNotFalse($start);
        $dialog = substr($template, $start, strpos($template, '</x-ui.confirmation-dialog>', $start) - $start);

        $this->assertStringContainsString('cancel-icon="close"', $dialog);
        $this->assertStringContainsString('confirm-icon="trash"', $dialog);

        // Unchanged: wording, destructive treatment, route (with the exact person) and method.
        $this->assertStringContainsString('confirm-label="Delete Permanently"', $dialog);
        $this->assertStringContainsString('title="Permanently Delete Business Check?"', $dialog);
        $this->assertStringContainsString('destructive', $dialog);
        $this->assertStringContainsString('client-folders.business-checks.destroy', $dialog);
        // The exact person still rides along in the delete URL.
        $this->assertStringContainsString('personParams', $dialog);
        $this->assertStringContainsString('method="DELETE"', $dialog);
    }
}
