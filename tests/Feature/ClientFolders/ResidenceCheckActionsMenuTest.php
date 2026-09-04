<?php

namespace Tests\Feature\ClientFolders;

use Tests\TestCase;

class ResidenceCheckActionsMenuTest extends TestCase
{
    public function test_only_residence_view_photos_is_removed_from_the_check_actions(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));
        $residenceStart = strpos($template, "route('client-folders.residence-checks.edit'");
        $businessStart = strpos($template, "route('client-folders.business-checks.edit'");

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

    public function test_residence_and_business_check_rows_show_a_direct_edit_icon_next_to_the_dots_trigger(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));

        // Same layout as Businesses / Income Sources (saved-businesses-panel-body.blade.php):
        // a direct compact Edit icon button immediately followed by the 3-dot Actions menu, both
        // wrapped in the same "flex items-center justify-center gap-1" row-actions container.
        $this->assertSame(2, substr_count($template, 'class="ui-dots-trigger" title="Actions"'));
        $this->assertStringNotContainsString('ui-action-icon-button-neutral', $template);
        $this->assertStringContainsString('aria-label="Edit Residence Check" title="Edit"', $template);
        $this->assertStringContainsString('aria-label="Edit Business Check" title="Edit"', $template);
        $this->assertSame(2, substr_count($template, 'ui-button-secondary-compact !size-8 !min-h-8 !px-0'));
    }

    public function test_residence_and_business_check_menus_no_longer_contain_a_duplicate_edit_menuitem(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));
        $residenceMenuStart = strpos($template, '<x-ui.context-menu label="Residence Check actions">');
        $residenceMenuEnd = strpos($template, '</x-ui.context-menu>', $residenceMenuStart);
        $businessMenuStart = strpos($template, '<x-ui.context-menu label="Business Check actions">');
        $businessMenuEnd = strpos($template, '</x-ui.context-menu>', $businessMenuStart);

        $residenceMenu = substr($template, $residenceMenuStart, $residenceMenuEnd - $residenceMenuStart);
        $businessMenu = substr($template, $businessMenuStart, $businessMenuEnd - $businessMenuStart);

        foreach ([$residenceMenu, $businessMenu] as $menu) {
            $this->assertStringNotContainsString('>Edit</a>', $menu, 'Edit must not be duplicated inside the 3-dot menu.');
            $this->assertStringContainsString('role="menuitem"', $menu);
        }
    }

    public function test_edit_icon_precedes_the_dots_trigger_inside_the_same_actions_cell(): void
    {
        $template = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));

        foreach (['residence', 'business'] as $kind) {
            $editPosition = strpos($template, "route('client-folders.{$kind}-checks.edit'");
            $dotsPosition = strpos($template, 'ui-dots-trigger', $editPosition);
            $this->assertNotFalse($editPosition);
            $this->assertNotFalse($dotsPosition);
            $this->assertLessThan($dotsPosition, $editPosition, "Edit icon should appear before the 3-dot trigger for {$kind} checks.");
        }
    }
}
