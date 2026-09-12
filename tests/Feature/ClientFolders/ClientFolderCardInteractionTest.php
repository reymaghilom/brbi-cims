<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Presentation-only guard for the Client Folder card's interaction affordance.
 *
 * It pins the two halves that have to stay in sync for the card to keep feeling clickable the way
 * the Dashboard KPI cards do: the Blade markup contract (openable target, listbox semantics,
 * right-click menu, inner controls kept outside the tile) and the single `.client-folder-tile`
 * CSS rule that carries the pointer cursor / hover lift / selected precedence. No business logic,
 * routing, selection logic or authorization is exercised here.
 */
class ClientFolderCardInteractionTest extends TestCase
{
    use RefreshDatabase;

    private function tileCss(): string
    {
        return file_get_contents(base_path('resources/css/app.css'));
    }

    private function tileRule(): string
    {
        $css = $this->tileCss();
        preg_match('/^\s*\.client-folder-tile \{.*$/m', $css, $matches);

        $this->assertNotEmpty($matches, 'The .client-folder-tile base rule is missing from app.css.');

        return $matches[0];
    }

    public function test_client_folder_card_stays_openable_with_listbox_semantics_and_a_keyboard_focusable_target(): void
    {
        $administrator = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['display_name' => 'SANTOS, MARIA CLARA']);

        $content = $this->actingAs($administrator)
            ->get(route('client-folders.index'))
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($content);
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        $tiles = $xpath->query('//*[@data-folder-tile]');
        $this->assertSame(1, $tiles->length);

        $tile = $tiles->item(0);
        $this->assertSame('client-folder-tile', $tile->getAttribute('class'));
        $this->assertSame('option', $tile->getAttribute('role'));
        $this->assertSame('0', $tile->getAttribute('tabindex'));
        $this->assertSame('false', $tile->getAttribute('aria-selected'));
        // The open destination the double-click / Enter handlers in app.js read.
        $this->assertSame(route('client-folders.show', $folder), $tile->getAttribute('data-folder-open-url'));
        // The right-click / ContextMenu-key handler resolves its menu through this pairing.
        $this->assertSame('client-folder-menu-'.$folder->id, $tile->getAttribute('data-folder-menu'));
        $this->assertSame(1, $xpath->query('//*[@role="listbox"]//*[@data-folder-tile]')->length);
    }

    public function test_inner_action_controls_stay_outside_the_card_target_and_keep_the_context_menu_entries(): void
    {
        $administrator = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create();

        $content = $this->actingAs($administrator)
            ->get(route('client-folders.index'))
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($content);
        libxml_clear_errors();
        $xpath = new \DOMXPath($document);

        // The three-dot trigger and the action menu are siblings of the tile inside the shell —
        // never descendants of it — so activating them can never be read as a card activation.
        $this->assertSame(1, $xpath->query('//*[@data-folder-shell]/*[@data-folder-menu-trigger]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-folder-tile]//*[@data-folder-menu-trigger]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-folder-shell]/*[@data-folder-action-menu]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-folder-tile]//*[@data-folder-action-menu]')->length);

        $menu = $xpath->query('//*[@data-folder-action-menu]')->item(0);
        $this->assertSame('menu', $menu->getAttribute('role'));
        $menuHtml = $document->saveHTML($menu);
        $this->assertStringContainsString('data-folder-open-action', $menuHtml);
        $this->assertStringContainsString('Open', $menuHtml);
        $this->assertStringContainsString('data-folder-edit-action', $menuHtml);
        $this->assertStringContainsString('Edit Folder', $menuHtml);
        $this->assertStringContainsString('dashboard-delete-dialog-'.$folder->id, $menuHtml);
    }

    public function test_card_rule_exposes_pointer_and_restrained_motion_safe_hover_interaction(): void
    {
        $rule = $this->tileRule();

        $this->assertStringContainsString('cursor-pointer', $rule);
        $this->assertStringNotContainsString('cursor-default', $rule);
        // Subtle elevation + border emphasis + a 150ms transition, matching the KPI cards.
        $this->assertStringContainsString('transition duration-150', $rule);
        $this->assertStringContainsString('hover:shadow-sm', $rule);
        $this->assertStringContainsString('ring-1 ring-transparent', $rule);
        $this->assertStringContainsString('hover:ring-brand-primary/20', $rule);
        // Movement only where motion is welcome, and only a single pixel of it.
        $this->assertStringContainsString('motion-safe:hover:-translate-y-px', $rule);
        $this->assertStringNotContainsString('translate-y-0.5', $rule);
        $this->assertStringNotContainsString('scale-', $rule);
        // Press feedback works on touch too, where `hover:` never applies.
        $this->assertStringContainsString('active:bg-brand-soft/60', $rule);
        $this->assertStringContainsString('motion-safe:active:translate-y-0', $rule);
    }

    public function test_card_keeps_the_app_wide_focus_visible_outline_and_a_stronger_selected_state(): void
    {
        $css = $this->tileCss();

        // The tile must not suppress the shared :focus-visible outline declared once for the app.
        $this->assertStringContainsString(':focus-visible { outline:', $css);
        $this->assertStringNotContainsString('focus-visible:outline-none', $this->tileRule());

        preg_match('/^\s*\.client-folder-tile\[aria-selected="true"\] \{.*$/m', $css, $selected);
        $this->assertNotEmpty($selected);

        // Selected outranks hover: a heavier ring plus the full card shadow, declared afterwards.
        $this->assertStringContainsString('ring-2 ring-brand-primary/45', $selected[0]);
        $this->assertStringContainsString('shadow-card', $selected[0]);
        $this->assertStringContainsString('bg-brand-soft/55', $selected[0]);
        $this->assertGreaterThan(strpos($css, $this->tileRule()), strpos($css, $selected[0]));
    }
}
