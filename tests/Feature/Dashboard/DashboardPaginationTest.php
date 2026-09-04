<?php

namespace Tests\Feature\Dashboard;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_one_of_thirteen_folders_shows_twelve_and_reports_paginator_values(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $response = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk();

        $paginator = $response->viewData('clientFolders');
        $this->assertCount(12, $paginator->items());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertSame(2, $paginator->lastPage());
        $this->assertSame(13, $paginator->total());
        $this->assertSame(1, $paginator->firstItem());
        $this->assertSame(12, $paginator->lastItem());
        $this->assertTrue($paginator->hasMorePages());

        // The "Showing X–Y of Z" line is rendered from those same paginator values.
        $response->assertSee('Showing 1&ndash;12 of 13 authorized folders', false);
    }

    public function test_page_two_of_thirteen_folders_shows_the_remaining_folder_and_not_the_empty_state(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $response = $this->actingAs($administrator)->get(route('client-folders.index', ['page' => 2]))->assertOk();

        $paginator = $response->viewData('clientFolders');
        $this->assertCount(1, $paginator->items());
        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame(13, $paginator->firstItem());
        $this->assertSame(13, $paginator->lastItem());
        $this->assertFalse($paginator->hasMorePages());

        $response->assertSee('Showing 13&ndash;13 of 13 authorized folders', false);
        $response->assertDontSee('No client folders yet');
    }

    public function test_page_three_of_twenty_five_folders_is_a_valid_one_folder_final_page(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(25)->create();

        $response = $this->actingAs($administrator)->get(route('client-folders.index', ['page' => 3]))->assertOk();
        $paginator = $response->viewData('clientFolders');

        $this->assertSame(3, $paginator->currentPage());
        $this->assertSame(3, $paginator->lastPage());
        $this->assertSame(25, $paginator->total());
        $this->assertCount(1, $paginator->items());
        $response->assertSee('Showing 25&ndash;25 of 25 authorized folders', false);
        $this->assertPaginationArrowLinked($response->getContent(), 'Previous page');
        $this->assertPaginationArrowDisabled($response->getContent(), 'Next page');
    }

    public function test_out_of_range_page_falls_back_to_the_last_valid_page_instead_of_a_false_empty_state(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $response = $this->actingAs($administrator)->get(route('client-folders.index', ['page' => 99]))->assertOk();

        $paginator = $response->viewData('clientFolders');
        $this->assertSame(2, $paginator->currentPage(), 'An out-of-range page must normalise to the last valid page.');
        $this->assertCount(1, $paginator->items());
        $response->assertDontSee('No client folders yet');
    }

    public function test_previous_is_disabled_on_the_first_page_and_next_is_disabled_on_the_last_page(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        // Unavailable arrows render as inert aria-disabled spans rather than links, so no
        // clickable URL beyond the valid page range is ever produced.
        $firstPage = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk();
        $this->assertPaginationArrowDisabled($firstPage->getContent(), 'Previous page');
        $this->assertPaginationArrowLinked($firstPage->getContent(), 'Next page');

        $lastPage = $this->actingAs($administrator)->get(route('client-folders.index', ['page' => 2]))->assertOk();
        $this->assertPaginationArrowDisabled($lastPage->getContent(), 'Next page');
        $this->assertPaginationArrowLinked($lastPage->getContent(), 'Previous page');
    }

    public function test_genuine_zero_folder_state_still_shows_the_empty_state(): void
    {
        $administrator = User::factory()->administrator()->create();

        $response = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk();

        $this->assertSame(0, $response->viewData('clientFolders')->total());
        $response->assertSee('No client folders yet');
    }

    public function test_search_filter_and_scope_are_preserved_across_pages(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create(['display_name' => 'MATCHING CLIENT']);
        ClientFolder::factory()->count(5)->create(['display_name' => 'EXCLUDED CLIENT']);

        $firstPage = $this->actingAs($administrator)
            ->get(route('client-folders.index', ['search' => 'MATCHING']))->assertOk();
        $secondPage = $this->actingAs($administrator)
            ->get(route('client-folders.index', ['search' => 'MATCHING', 'page' => 2]))->assertOk();

        $this->assertSame(13, $firstPage->viewData('clientFolders')->total());
        $this->assertSame(13, $secondPage->viewData('clientFolders')->total(), 'The total must not change between pages.');
        $this->assertCount(1, $secondPage->viewData('clientFolders')->items());
        // Pagination links keep the active search so page 2 stays inside the same filtered set.
        $secondPage->assertSee('search=MATCHING', false);
    }

    public function test_pagination_does_not_duplicate_the_listing_count_line_or_ship_stock_palette_classes(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $content = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk()->getContent();

        // The listing renders its own authoritative count line; the paginator must not print a
        // second, differently-worded one underneath it.
        $this->assertStringContainsString('of 13 authorized folders', $content);
        $this->assertStringNotContainsString('of <span class="font-medium">13</span> results', $content);

        // Pagination uses BRBI-CIMS tokens, not the stock view's gray-*/dark: palette.
        $navStart = strpos($content, 'aria-label="Pagination Navigation"');
        $this->assertNotFalse($navStart);
        $nav = substr($content, $navStart, strpos($content, '</nav>', $navStart) - $navStart);
        $this->assertStringNotContainsString('dark:', $nav);
        $this->assertStringNotContainsString('text-gray-', $nav);
        $this->assertStringContainsString('rounded-control', $nav);
    }

    public function test_live_search_endpoint_serves_page_two_as_a_fragment_for_ajax_pagination(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $response = $this->actingAs($administrator)
            ->get(route('client-folders.live-search', ['context' => 'dashboard', 'page' => 2]))
            ->assertOk();

        $content = $response->getContent();

        // Authoritative page 2 from the same paginator, carrying the remaining folder.
        $this->assertStringContainsString('Showing 13&ndash;13 of 13 authorized folders', $content);
        $this->assertStringContainsString('data-folder-browser-layout', $content);
        $this->assertStringContainsString('data-folder-browser-artifacts', $content);

        // Next stays disabled on the final page even over AJAX, so the fragment can never offer a
        // clickable page beyond the last.
        $this->assertPaginationArrowDisabled($content, 'Next page');
        $this->assertPaginationArrowLinked($content, 'Previous page');

        // A fragment, not a whole page: no app shell comes back with it.
        $this->assertStringNotContainsString('<html', $content);
        $this->assertStringNotContainsString('id="app-content-wrapper"', $content);
        $this->assertStringNotContainsString('data-dashboard-stat', $content);
    }

    public function test_ajax_pagination_fragment_preserves_the_active_search_across_pages(): void
    {
        $investigator = User::factory()->create();
        ClientFolder::factory()->count(13)->create(['assigned_ci_id' => $investigator->id, 'display_name' => 'MATCHING CLIENT']);
        ClientFolder::factory()->count(3)->create(['assigned_ci_id' => $investigator->id, 'display_name' => 'EXCLUDED CLIENT']);

        $firstPage = $this->actingAs($investigator)
            ->get(route('client-folders.live-search', ['context' => 'dashboard', 'search' => 'MATCHING']))
            ->assertOk()->getContent();
        $secondPage = $this->actingAs($investigator)
            ->get(route('client-folders.live-search', ['context' => 'dashboard', 'search' => 'MATCHING', 'page' => 2]))
            ->assertOk()->getContent();

        // The filter — and therefore the total — is identical on both AJAX pages; only the slice
        // moves. Folders outside the search never leak onto either page.
        $this->assertStringContainsString('Showing 1&ndash;12 of 13 authorized folders', $firstPage);
        $this->assertStringContainsString('Showing 13&ndash;13 of 13 authorized folders', $secondPage);
        $this->assertStringContainsString('search=MATCHING', $secondPage);
        $this->assertStringNotContainsString('EXCLUDED CLIENT', $firstPage);
        $this->assertStringNotContainsString('EXCLUDED CLIENT', $secondPage);
    }

    public function test_dashboard_pagination_auto_updates_without_a_full_page_navigation(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $start = strpos($javascript, "const link = event.target.closest('[data-folder-pagination] a[href]')");
        $this->assertNotFalse($start, 'Expected a delegated pagination click handler.');
        $handler = substr($javascript, $start - 600, 1400);

        // Ordinary left-clicks are intercepted and routed through the existing authoritative
        // folder-browser refresh rather than navigating the page.
        $this->assertStringContainsString('event.preventDefault()', $handler);
        $this->assertStringContainsString("new CustomEvent('folder-browser:refresh'", $handler);
        $this->assertStringContainsString("history: 'push'", $handler);
        // ...and an open folder menu is closed so it can't outlive the cards being replaced.
        $this->assertStringContainsString('closeFolderMenus()', $handler);

        // Modified clicks stay real links (new tab/window/download).
        foreach (['event.metaKey', 'event.ctrlKey', 'event.shiftKey', 'event.altKey', 'event.button !== 0'] as $modifier) {
            $this->assertStringContainsString($modifier, $handler);
        }

        // No hard navigation is used for ordinary pagination.
        $this->assertStringNotContainsString('window.location.assign', $handler);
        $this->assertStringNotContainsString('location.href =', $handler);
        $this->assertStringNotContainsString('location.reload', $handler);
    }

    public function test_pagination_history_and_stale_request_handling_reuse_the_live_search_pipeline(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $start = strpos($javascript, 'const refreshFolderGrid = (delay = 275');
        $end = strpos($javascript, 'input.addEventListener', $start);
        $pipeline = substr($javascript, $start, $end - $start);

        // One shared request path: page number, abort-based stale protection, and the same
        // failure toast that leaves the current list intact.
        $this->assertStringContainsString("url.searchParams.set('page', String(page))", $pipeline);
        $this->assertStringContainsString('liveRequest?.abort()', $pipeline);
        $this->assertStringContainsString('new AbortController()', $pipeline);
        $this->assertStringContainsString("window.history.pushState({ folderBrowserPage: page }, '', historyUrl)", $pipeline);

        // Panel collapsed/expanded state survives the fragment swap.
        $this->assertStringContainsString('applyFolderPreviewCollapsedState(browser, true)', $pipeline);

        // Back/Forward re-render from the restored URL instead of hard-reloading.
        $this->assertStringContainsString("window.addEventListener('popstate'", $javascript);
        $this->assertStringContainsString("history: 'none'", $javascript);

        // Pagination must not scroll the viewport to the top.
        $this->assertStringNotContainsString('window.scrollTo(0, 0)', $javascript);
    }

    /** The arrow for this label renders as an inert, aria-disabled span rather than a link. */
    private function assertPaginationArrowDisabled(string $content, string $label): void
    {
        $position = strpos($content, 'aria-label="'.$label.'"');
        $this->assertNotFalse($position, "Expected a pagination arrow labelled {$label}.");
        $tagStart = strrpos(substr($content, 0, $position), '<');
        $tag = substr($content, $tagStart, $position - $tagStart + strlen($label) + 20);
        $this->assertStringStartsWith('<span', $tag, "The {$label} arrow must not be a link here.");
        $this->assertStringContainsString('aria-disabled="true"', $tag);
    }

    /** The arrow for this label renders as a real link to a valid page. */
    private function assertPaginationArrowLinked(string $content, string $label): void
    {
        $position = strpos($content, 'aria-label="'.$label.'"');
        $this->assertNotFalse($position, "Expected a pagination arrow labelled {$label}.");
        $tagStart = strrpos(substr($content, 0, $position), '<');
        $tag = substr($content, $tagStart, $position - $tagStart + strlen($label) + 20);
        $this->assertStringStartsWith('<a', $tag, "The {$label} arrow must be a link here.");
        $this->assertStringNotContainsString('aria-disabled', $tag);
    }
}
