<?php

namespace Tests\Feature\ClientFolders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSearchAndPanelUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_control_uses_the_compact_control_height_alongside_the_rest_of_the_toolbar(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        // Same compact 40px (min-h-10) height as the neighboring Create Client Folder button and
        // Preview Panel toggle — one intentional toolbar, not "search tall, filter compact".
        $this->assertStringContainsString('id="folder-search"', $content);
        $searchStart = strpos($content, 'id="folder-search"');
        $searchTag = substr($content, $searchStart, strpos($content, '>', $searchStart) - $searchStart);
        $this->assertStringContainsString('min-h-10', $searchTag);
        $this->assertStringNotContainsString('min-h-11', $searchTag);
        $this->assertStringNotContainsString('min-h-12', $searchTag);

        $this->assertStringContainsString('id="create-client-folder-trigger"', $content);
        $createStart = strpos($content, 'id="create-client-folder-trigger"');
        $createTag = substr($content, $createStart, strpos($content, '>', $createStart) - $createStart);
        $this->assertStringContainsString('min-h-10', $createTag);

        $toggleStart = strpos($content, 'data-folder-preview-hide', strpos($content, '</style>'));
        $toggleTag = substr($content, $toggleStart - 200, 200);
        $this->assertStringContainsString('min-h-10', $toggleTag);
    }

    public function test_search_fills_all_remaining_toolbar_width_and_is_stable_because_it_sits_outside_the_animated_layout(): void
    {
        $ci = User::factory()->create();

        foreach ([route('home'), route('client-folders.index')] as $url) {
            $content = $this->actingAs($ci)->get($url)->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML($content);
            $xpath = new \DOMXPath($document);

            // Search grows to fill remaining toolbar width (flex-1) rather than a fixed pixel
            // width — starts flush left, stops naturally before Create Client Folder. min-w-0 on
            // both the wrapper and the input keep it overflow-safe when the row is tight.
            $searchWrapper = $xpath->query('//*[@data-folder-toolbar-search]')->item(0);
            $this->assertNotNull($searchWrapper);
            $searchClasses = $searchWrapper->getAttribute('class');
            $this->assertStringContainsString('md:flex-1', $searchClasses);
            $this->assertStringContainsString('min-w-0', $searchClasses);
            $this->assertStringContainsString('w-full', $searchClasses);
            $this->assertStringNotContainsString('w-[320px]', $searchClasses);
            $this->assertStringNotContainsString('md:w-96', $searchClasses);

            $input = $xpath->query('//input[@data-client-search-input]')->item(0);
            $this->assertNotNull($input);
            $inputClasses = $input->getAttribute('class');
            $this->assertStringContainsString('w-full', $inputClasses);
            $this->assertStringContainsString('max-w-full', $inputClasses);

            // Search, Create, and the panel toggle are direct children of one flex toolbar row
            // (no absolute positioning, no intermediate right-aligning wrapper needed now that
            // Search itself fills the space) with the panel toggle last so it lands far right.
            $toolbar = $xpath->query('//*[@data-dashboard-toolbar]')->item(0);
            $this->assertNotNull($toolbar);
            $this->assertStringContainsString('min-w-0', $toolbar->getAttribute('class'));
            foreach (['data-dashboard-search', 'data-dashboard-create', 'data-dashboard-preview-toggle'] as $control) {
                $this->assertSame(1, $xpath->query("//*[@data-dashboard-toolbar]/*[@{$control}]")->length);
            }
            $toolbarChildren = $xpath->query('//*[@data-dashboard-toolbar]/*');
            $this->assertTrue($toolbarChildren->item(0)->hasAttribute('data-dashboard-search'));
            $this->assertTrue($toolbarChildren->item($toolbarChildren->length - 1)->hasAttribute('data-dashboard-preview-toggle'));

            // Its width stability comes from structure, not from width utilities: the search is
            // never a descendant of the grid whose columns animate.
            $this->assertSame(0, $xpath->query('//*[@data-folder-browser-layout]//*[@data-folder-toolbar-search]')->length);
        }
    }

    public function test_toolbar_has_three_independent_wrappers_separate_from_the_animated_preview_grid_on_dashboard_and_client_folders(): void
    {
        $ci = User::factory()->create();

        foreach ([route('home'), route('client-folders.index')] as $url) {
            $content = $this->actingAs($ci)->get($url)->assertOk()->getContent();

            // Search, Create Client Folder, and the Preview toggle each sit in their own
            // structurally independent wrapper, in that order, all as direct children of one
            // stable [data-folder-toolbar] row.
            $toolbarStart = strpos($content, 'data-folder-toolbar ');
            $searchWrapperPos = strpos($content, 'data-folder-toolbar-search', $toolbarStart);
            $createWrapperPos = strpos($content, 'data-folder-toolbar-create', $toolbarStart);
            $toggleWrapperPos = strpos($content, 'data-folder-toolbar-preview-toggle', $toolbarStart);
            $this->assertNotFalse($searchWrapperPos);
            $this->assertNotFalse($createWrapperPos);
            $this->assertNotFalse($toggleWrapperPos);
            $this->assertLessThan($createWrapperPos, $searchWrapperPos);
            $this->assertLessThan($toggleWrapperPos, $createWrapperPos);

            // The whole toolbar renders, and closes, before the animated grid begins — it is that
            // grid's sibling, never its parent or child, so the grid's collapse/expand transition
            // cannot resize or reposition it.
            $toolbarCloseTag = strpos($content, '</div>', $toggleWrapperPos);
            $gridStart = strpos($content, 'data-folder-browser-layout', $toolbarCloseTag);
            $this->assertNotFalse($gridStart);
            $this->assertGreaterThan($toolbarCloseTag, $gridStart);
        }
    }

    public function test_dashboard_toolbar_main_and_preview_are_three_independent_containers(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($content);
        $xpath = new \DOMXPath($document);

        $toolbar = $xpath->query('//*[@data-dashboard-toolbar]')->item(0);
        $layout = $xpath->query('//*[@data-dashboard-preview-layout]')->item(0);
        $mainContainer = $xpath->query('//*[@data-dashboard-main-container]')->item(0);
        $rightContainer = $xpath->query('//*[@data-dashboard-right-panel-container]')->item(0);
        $this->assertNotNull($toolbar);
        $this->assertNotNull($layout);
        $this->assertNotNull($mainContainer);
        $this->assertNotNull($rightContainer);

        // The toolbar must not live inside the animated layout, the main container, or the preview
        // container — this is the whole point: nothing in it can be resized by the grid.
        foreach (['data-dashboard-preview-layout', 'data-dashboard-main-container', 'data-dashboard-right-panel-container'] as $forbiddenAncestor) {
            $this->assertSame(
                0,
                $xpath->query("//*[@{$forbiddenAncestor}]//*[@data-dashboard-toolbar]")->length,
                "The toolbar must not be nested inside [{$forbiddenAncestor}].",
            );
        }

        // Each control genuinely lives inside the toolbar...
        foreach (['data-dashboard-search', 'data-dashboard-create', 'data-dashboard-preview-toggle'] as $control) {
            $this->assertSame(1, $xpath->query("//*[@data-dashboard-toolbar]//*[@{$control}]")->length);
            // ...and none of them is inside the main container.
            $this->assertSame(0, $xpath->query("//*[@data-dashboard-main-container]//*[@{$control}]")->length);
        }

        // Toolbar and animated layout are direct siblings.
        $this->assertSame($toolbar->parentNode, $layout->parentNode);

        // Main and right containers are siblings of each other inside the animated layout, and the
        // preview is never nested inside the main container.
        $this->assertSame($layout, $mainContainer->parentNode);
        $this->assertSame($layout, $rightContainer->parentNode);
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-main-container]//*[@data-dashboard-right-panel-container]')->length);

        // Two independent cards, not one merged card: each container carries its own surface, and
        // the animated layout wrapper itself is not a card.
        $this->assertStringContainsString('ui-panel', $mainContainer->getAttribute('class'));
        $this->assertStringContainsString('client-folder-preview-panel', $rightContainer->getAttribute('class'));
        $this->assertStringNotContainsString('ui-panel', $layout->getAttribute('class'));
    }

    public function test_preview_toggle_uses_the_same_two_button_pre_paint_pattern_as_photos_and_videos(): void
    {
        $ci = User::factory()->create();

        foreach ([route('home'), route('client-folders.index')] as $url) {
            $content = $this->actingAs($ci)->get($url)->assertOk()->getContent();

            // Two separate Show/Hide buttons (one [hidden] by default), not one button with a
            // dynamically swapped label/icon — the same technique _documentation-panel's Photos &
            // Videos support panel uses so the pre-paint CSS can pick the right one with no JS.
            $this->assertStringContainsString('data-folder-preview-show', $content);
            $this->assertStringContainsString('data-folder-preview-hide', $content);
            $afterPrePaintStyles = strpos($content, '</style>');
            $showStart = strpos($content, 'data-folder-preview-show', $afterPrePaintStyles);
            $showTagStart = strrpos(substr($content, 0, $showStart), '<button');
            $showTag = substr($content, $showTagStart, $showStart - $showTagStart + 40);
            $this->assertStringContainsString('hidden', $showTag);

            // Pre-paint guard: reads localStorage before first paint and stamps <html> so the
            // matching CSS can suppress the wrong initial state, exactly like the Photos & Videos
            // support panel's own flash-prevention script.
            $this->assertStringContainsString("localStorage.getItem('brbi-folder-preview-collapsed')", $content);
            $this->assertStringContainsString('data-folder-preview-collapsed', $content);
        }
    }

    public function test_preview_toggle_state_persists_via_localstorage_and_survives_live_search_replace(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('localStorage.setItem(FOLDER_PREVIEW_COLLAPSED_STORAGE_KEY', $javascript);
        $this->assertStringContainsString('localStorage.getItem(FOLDER_PREVIEW_COLLAPSED_STORAGE_KEY)', $javascript);

        // Live search replaces [data-folder-browser-layout] wholesale with a server-rendered
        // fragment that has no knowledge of the client's persisted choice — this carries the
        // collapsed state across that replace so a live search keystroke can never silently
        // re-expand a Preview Panel the user just hid.
        $start = strpos($javascript, 'const refreshFolderGrid');
        $end = strpos($javascript, 'input.addEventListener', $start);
        $refreshSource = substr($javascript, $start, $end - $start);
        $this->assertStringContainsString('wasCollapsed', $refreshSource);
        // Re-applied through the shared helper so the freshly rendered panel is also taken back
        // out of the layout, not merely re-flagged as collapsed.
        $this->assertStringContainsString('applyFolderPreviewCollapsedState(browser, true)', $refreshSource);
    }

    public function test_search_icon_and_placeholder_are_preserved(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('placeholder="Search client name..."', $content);
        $this->assertStringContainsString('data-client-search-input', $content);
        $this->assertStringContainsString('data-client-search-clear', $content);
    }

    public function test_mobile_preview_panel_backdrop_uses_a_coordinated_fade_transition_instead_of_an_instant_hidden_toggle(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        // The backdrop no longer relies on the [hidden] boolean (display:none with no transition)
        // — both the panel's slide and the backdrop's fade are driven by the same data-open
        // attribute so closing never cuts the animation short.
        $this->assertStringNotContainsString('backdrop.hidden = false', $javascript);
        $this->assertStringNotContainsString('backdrop.hidden = true', $javascript);
        $this->assertStringContainsString("backdrop?.setAttribute('data-open', 'true')", $javascript);
        $this->assertStringContainsString("querySelector('[data-folder-preview-backdrop]')?.removeAttribute('data-open')", $javascript);

        $this->assertStringContainsString('.client-folder-preview-backdrop[data-open="true"]', $css);
        $this->assertStringContainsString('transition-opacity', $css);
    }

    public function test_desktop_preview_panel_collapse_already_uses_a_smooth_restrained_transition_with_reduced_motion_support(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // The column gap animates with the tracks so the space between the two separate cards
        // closes along with the preview column instead of leaving a blank gutter behind.
        $this->assertStringContainsString('.client-folder-browser-layout { transition: grid-template-columns 200ms ease-in-out, gap 200ms ease-in-out; }', $css);
        $this->assertStringContainsString('.client-folder-preview-panel { min-width: 0; opacity: 1; transition: opacity 180ms ease-in-out; }', $css);
        $this->assertStringContainsString('.client-folder-browser-layout[data-panel-collapsed="true"] { grid-template-columns: minmax(0, 1fr) 0px; gap: 0; }', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function test_browser_grid_height_budget_accounts_for_the_full_page_chrome_without_global_overflow_hiding(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        // The page is the only scrolling region. The desktop grid must carry no viewport-derived
        // height at all — any such clamp forces its columns to either clip or grow their own
        // second scrollbar beside the browser's.
        $this->assertSame(1, preg_match('#@media \(min-width: 1280px\) \{\s*/\* ONE scrolling region only.*?\n\}#s', $css, $matches), 'Expected the desktop folder-browser block.');
        // Assert against declarations only — the explanatory comments legitimately name the very
        // properties being ruled out.
        $desktopBlock = preg_replace('#/\*.*?\*/#s', '', $matches[0]);

        $this->assertStringNotContainsString('100dvh', $desktopBlock);
        $this->assertStringNotContainsString('100vh', $desktopBlock);
        $this->assertStringNotContainsString('clamp(', $desktopBlock);
        $this->assertStringNotContainsString('max-height', $desktopBlock);

        // Neither column may become an independently scrolling area, and the shorter one must not
        // be stretched to the taller one (stretching is what created the need to scroll inside).
        $this->assertStringNotContainsString('overflow-y: auto', $desktopBlock);
        $this->assertStringNotContainsString('overflow-y-auto', $desktopBlock);
        $this->assertStringContainsString('align-items: start', $desktopBlock);

        // The fix must come from honest height math, never from hiding the page's overflow.
        $this->assertStringNotContainsString('overflow-y-hidden', $layout);
        $this->assertDoesNotMatchRegularExpression('/\b(html|body)\s*\{[^}]*overflow(-y)?:\s*hidden/', $css);
    }

    public function test_hidden_preview_panel_is_taken_out_of_the_layout_instead_of_only_being_zeroed(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $javascript = file_get_contents(resource_path('js/app.js'));
        $ci = User::factory()->create();
        $blade = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        // Zeroing the column leaves the panel a grid item, and a grid row is as tall as its
        // tallest item — so the collapsed panel must additionally be removed from layout.
        $this->assertStringContainsString('.client-folder-preview-panel[data-panel-hidden="true"] { display: none; }', $css);

        // ...and it must be out of layout before first paint too, when the collapsed choice is
        // restored from localStorage.
        $this->assertStringContainsString('html[data-folder-preview-collapsed] [data-folder-preview-panel] { display: none; }', $blade);

        // The removal is deferred until the closing transition has finished (so the hide
        // animation still plays), and reversed before re-opening (so the open animation does).
        $this->assertStringContainsString("panel.setAttribute('data-panel-hidden', 'true')", $javascript);
        $this->assertStringContainsString("panel.removeAttribute('data-panel-hidden')", $javascript);
        $this->assertMatchesRegularExpression('/setFolderPreviewPanelInLayout\(browser, false\);\s*\n\s*\}, 220\)/', $javascript);

        // The collapse/expand animation hooks themselves are untouched.
        $this->assertStringContainsString('.client-folder-browser-layout { transition: grid-template-columns 200ms ease-in-out, gap 200ms ease-in-out; }', $css);
        $this->assertStringContainsString('.client-folder-browser-layout[data-panel-collapsed="true"] .client-folder-preview-panel { opacity: 0; pointer-events: none; }', $css);
    }

    public function test_desktop_panel_animation_keeps_folder_rows_and_panel_height_stable_without_a_forced_layout_flush(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('.client-folder-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }', $css);
        $this->assertStringContainsString('@media (min-width: 1536px)', $css);
        $this->assertStringContainsString('.client-folder-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }', $css);
        $this->assertStringContainsString('.client-folder-preview-content { min-width: 17.5rem; }', $css);

        $start = strpos($javascript, 'function applyFolderPreviewCollapsedState');
        $end = strpos($javascript, 'function toggleFolderPreviewPanel', $start);
        $toggleSource = substr($javascript, $start, $end - $start);
        $this->assertStringNotContainsString('offsetHeight', $toggleSource);
        $this->assertStringContainsString('window.requestAnimationFrame(() => {', $toggleSource);
        $this->assertStringContainsString("window.requestAnimationFrame(() => layout.setAttribute('data-panel-collapsed', 'false'))", $toggleSource);
        $this->assertStringNotContainsString('fetch(', $toggleSource);
        $this->assertStringNotContainsString('replaceWith(', $toggleSource);
        $this->assertStringNotContainsString('folder-browser:refresh', $toggleSource);
    }

    public function test_folder_browser_pages_reserve_a_stable_desktop_scrollbar_gutter_outside_the_header_toolbar_and_panel_layout(): void
    {
        $ci = User::factory()->create();
        $css = file_get_contents(resource_path('css/app.css'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $folderBrowser = file_get_contents(resource_path('views/dashboard/_folder-browser.blade.php'));

        foreach ([route('home'), route('client-folders.index')] as $url) {
            $content = $this->actingAs($ci)->get($url)->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML($content);
            $xpath = new \DOMXPath($document);

            $this->assertSame('folder-browser-page', $xpath->query('/html')->item(0)->getAttribute('class'));

            $header = strpos($content, '<header class="sticky');
            $profile = strpos($content, 'aria-label="Open account menu"');
            $toolbar = strpos($content, 'data-dashboard-toolbar');
            $animatedLayout = strpos($content, '<div class="client-folder-browser-layout');
            $this->assertNotFalse($header);
            $this->assertNotFalse($profile);
            $this->assertLessThan($toolbar, $header);
            $this->assertLessThan($animatedLayout, $profile);
            $this->assertLessThan($animatedLayout, $toolbar);
        }

        $this->assertStringContainsString('html.folder-browser-page { scrollbar-gutter: stable; }', $css);
        $this->assertStringNotContainsString('overflow-y: scroll', $css);
        $this->assertStringNotContainsString('overflow-y-hidden', $layout);
        $this->assertDoesNotMatchRegularExpression('/(?:100vw|w-screen)/', $layout.$folderBrowser);
    }

    public function test_no_page_reload_was_introduced_for_the_preview_panel(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        // Just the closeFolderPreview/openFolderPreview functions — narrowly scoped so this
        // doesn't false-positive on unrelated reload() calls elsewhere in the file.
        $start = strpos($javascript, 'function closeFolderPreview');
        $end = strpos($javascript, 'function showToast');
        $panelSource = substr($javascript, $start, $end - $start);
        $this->assertStringNotContainsString('location.reload', $panelSource);
    }
}
