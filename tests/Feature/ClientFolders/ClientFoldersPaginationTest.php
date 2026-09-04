<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ClientFolderStatus;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFoldersPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_folders_page_uses_the_authoritative_twelve_item_paginator_on_every_valid_page(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(25)->create();

        $pageOne = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk();
        $pageTwo = $this->get(route('client-folders.index', ['page' => 2]))->assertOk();
        $pageThree = $this->get(route('client-folders.index', ['page' => 3]))->assertOk();

        $this->assertCount(12, $pageOne->viewData('clientFolders')->items());
        $this->assertCount(12, $pageTwo->viewData('clientFolders')->items());
        $this->assertCount(1, $pageThree->viewData('clientFolders')->items());
        $this->assertSame(25, $pageThree->viewData('clientFolders')->total());
        $pageOne->assertSee('Showing 1&ndash;12 of 25 authorized folders', false);
        $pageThree->assertSee('Showing 25&ndash;25 of 25 authorized folders', false);
        $this->assertPaginationArrowDisabled($pageOne->getContent(), 'Previous page');
        $this->assertPaginationArrowDisabled($pageThree->getContent(), 'Next page');
    }

    public function test_out_of_range_client_folders_page_clamps_to_the_real_final_page(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $response = $this->actingAs($administrator)
            ->get(route('client-folders.index', ['page' => 99]))
            ->assertOk();

        $this->assertSame(2, $response->viewData('clientFolders')->currentPage());
        $this->assertCount(1, $response->viewData('clientFolders')->items());
        $response->assertDontSee('No client folders yet');
    }

    public function test_client_folders_fragment_is_results_only_and_uses_its_own_fallback_urls(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $content = $this->actingAs($administrator)
            ->get(route('client-folders.live-search', ['context' => 'client_folders', 'page' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Showing 13&ndash;13 of 13 authorized folders', $content);
        $this->assertStringContainsString('data-folder-browser-layout', $content);
        $this->assertStringContainsString('data-folder-browser-artifacts', $content);
        $this->assertStringContainsString(route('client-folders.index'), $content);
        $this->assertStringNotContainsString('<html', $content);
        $this->assertStringNotContainsString('id="app-content-wrapper"', $content);
        $this->assertPaginationArrowDisabled($content, 'Next page');
    }

    public function test_search_status_sort_and_shared_ci_team_authorization_remain_authoritative_in_client_folder_fragments(): void
    {
        $investigator = User::factory()->create();
        $otherInvestigator = User::factory()->create();
        ClientFolder::factory()->count(13)->create([
            'assigned_ci_id' => $investigator->id,
            'display_name' => 'MATCHING CLIENT',
            'status' => ClientFolderStatus::Completed,
        ]);
        ClientFolder::factory()->create([
            'assigned_ci_id' => $investigator->id,
            'display_name' => 'WRONG STATUS',
            'status' => ClientFolderStatus::OnProgress,
        ]);
        ClientFolder::factory()->create([
            'assigned_ci_id' => $otherInvestigator->id,
            'display_name' => 'MATCHING UNAUTHORIZED',
            'status' => ClientFolderStatus::Completed,
        ]);

        $content = $this->actingAs($investigator)
            ->get(route('client-folders.live-search', [
                'context' => 'client_folders',
                'search' => 'MATCHING',
                'status' => 'completed',
                'sort' => 'client_name',
                'page' => 2,
            ]))
            ->assertOk()
            ->getContent();

        // Active folders intentionally remain a shared CI-team workspace, so the matching folder
        // assigned to the other investigator contributes to the authoritative 14-item total while
        // the wrong status is excluded. Which page it lands on is governed by the name sort.
        $this->assertStringContainsString('Showing 13&ndash;14 of 14 authorized folders', $content);
        $this->assertStringContainsString('search=MATCHING', $content);
        $this->assertStringContainsString('status=completed', $content);
        $this->assertStringContainsString('sort=client_name', $content);
        $this->assertStringNotContainsString('WRONG STATUS', $content);
    }

    public function test_non_ajax_client_folders_pagination_remains_a_full_page_fallback(): void
    {
        $administrator = User::factory()->administrator()->create();
        ClientFolder::factory()->count(13)->create();

        $content = $this->actingAs($administrator)
            ->get(route('client-folders.index', ['page' => 2]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<html', $content);
        $this->assertStringContainsString('id="app-content-wrapper"', $content);
        $this->assertStringContainsString('data-folder-pagination', $content);
    }

    public function test_client_folders_reuses_the_delegated_ajax_history_and_stale_request_pipeline(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $start = strpos($javascript, 'function initializeClientSearch');
        $end = strpos($javascript, 'document.querySelectorAll', $start);
        $pipeline = substr($javascript, $start, $end - $start);

        $this->assertStringContainsString('const browserContext = search.dataset.browserContext;', $pipeline);
        $this->assertStringContainsString("['status', 'sort'].forEach", $pipeline);
        $this->assertStringContainsString('liveRequest?.abort()', $pipeline);
        $this->assertStringContainsString('new AbortController()', $pipeline);
        $this->assertStringContainsString("window.history.pushState({ folderBrowserPage: page }, '', historyUrl)", $pipeline);
        $this->assertStringContainsString("window.addEventListener('popstate'", $pipeline);
        $this->assertStringContainsString("history: 'none'", $pipeline);
        $this->assertStringContainsString("showToast('Client folders could not be refreshed. Please retry.', 'error')", $pipeline);
        $this->assertStringContainsString("browser.setAttribute('data-refreshing', 'true')", $pipeline);

        $pagination = substr($javascript, strpos($javascript, "const link = event.target.closest('[data-folder-pagination] a[href]')") - 500, 1300);
        $this->assertStringContainsString('event.preventDefault()', $pagination);
        $this->assertStringContainsString('closeFolderMenus()', $pagination);
        $this->assertStringContainsString("new CustomEvent('folder-browser:refresh'", $pagination);
        $this->assertStringNotContainsString('window.location.assign', $pagination);
        $this->assertStringNotContainsString('window.location.reload', $pagination);
        $this->assertStringNotContainsString('location.href =', $pagination);

        $this->assertStringContainsString("document.addEventListener('contextmenu'", $javascript);
        $this->assertStringContainsString("event.target.closest('[data-folder-tile]')", $javascript);
    }

    private function assertPaginationArrowDisabled(string $content, string $label): void
    {
        $position = strpos($content, 'aria-label="'.$label.'"');
        $this->assertNotFalse($position, "Expected a pagination arrow labelled {$label}.");
        $tagStart = strrpos(substr($content, 0, $position), '<');
        $tag = substr($content, $tagStart, $position - $tagStart + strlen($label) + 20);
        $this->assertStringStartsWith('<span', $tag);
        $this->assertStringContainsString('aria-disabled="true"', $tag);
    }
}
