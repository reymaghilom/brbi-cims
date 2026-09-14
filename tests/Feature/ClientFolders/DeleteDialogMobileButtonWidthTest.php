<?php

namespace Tests\Feature\ClientFolders;

use Tests\TestCase;

/**
 * On a phone the delete confirmation footer stacks (flex-col-reverse). Cancel is a direct flex
 * child so it already stretched to full width, but the confirm button sits inside the dialog's
 * <form>: the form stretched while the button itself stayed content-width, which is why Delete /
 * Delete Permanently looked narrower and unbalanced next to Cancel.
 *
 * Both are now `w-full sm:w-auto`, so they match exactly below `sm` and revert to the existing
 * auto width from `sm` up — the desktop footer is untouched. Asserted on the shared
 * x-ui.confirmation-dialog component, which is the single source for all four delete dialogs:
 * Barangay Check, Neighbor Check, Residence Check and Business Check.
 */
class DeleteDialogMobileButtonWidthTest extends TestCase
{
    public function test_cancel_and_confirm_share_the_same_mobile_width_rule(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/confirmation-dialog.blade.php'));

        // Cancel and the destructive/primary confirm carry the identical responsive width pair.
        $this->assertStringContainsString('class="ui-button-secondary w-full sm:w-auto"', $component);
        $this->assertStringContainsString("{{ \$destructive ? 'ui-button-danger' : 'ui-button-primary' }} w-full sm:w-auto", $component);
        // The wrapping form must stretch too, otherwise the button inside it cannot match Cancel.
        $this->assertStringContainsString('<form method="POST" action="{{ $action }}" class="w-full sm:w-auto">', $component);

        // Desktop behaviour is preserved: every width rule is mobile-first with an sm:auto reset,
        // so nothing is forced full-width at desktop widths.
        $this->assertSame(
            substr_count($component, 'w-full sm:w-auto'),
            substr_count($component, 'w-full'),
            'Every full-width rule must be paired with an sm:w-auto reset.'
        );
    }

    /** All four delete confirmations really do come from that one component. */
    public function test_every_delete_confirmation_uses_the_shared_component(): void
    {
        $activities = file_get_contents(resource_path('views/client-folders/activities/index.blade.php'));
        $checksListing = file_get_contents(resource_path('views/client-folders/residence-business/partials/checks-listing.blade.php'));

        // Barangay / Neighbor / any CI Activity delete.
        $this->assertMatchesRegularExpression(
            '/<x-ui\.confirmation-dialog id="delete-activity-\{\{ \$activity->id \}\}"/',
            $activities
        );
        // Residence Check "Delete Permanently" and Business Check delete.
        $this->assertStringContainsString('<x-ui.confirmation-dialog id="delete-residence-check-', $checksListing);
        $this->assertStringContainsString('<x-ui.confirmation-dialog id="delete-business-check-', $checksListing);
    }
}
