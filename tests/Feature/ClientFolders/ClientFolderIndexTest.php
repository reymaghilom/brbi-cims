<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ClientFolderStatus;
use App\Enums\UserStatus;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientFolderIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_sees_all_active_folders_but_not_deleted_folders(): void
    {
        $administrator = User::factory()->administrator()->create();
        $firstCi = User::factory()->create();
        $secondCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $firstCi->id, 'display_name' => 'FIRST ACTIVE CLIENT']);
        ClientFolder::factory()->create(['assigned_ci_id' => $secondCi->id, 'display_name' => 'SECOND ACTIVE CLIENT']);
        $deleted = ClientFolder::factory()->create(['assigned_ci_id' => $secondCi->id, 'display_name' => 'DELETED PRIVATE CLIENT']);
        $deleted->delete();

        $this->actingAs($administrator)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('FIRST ACTIVE CLIENT')
            ->assertSee('SECOND ACTIVE CLIENT')
            ->assertDontSee('DELETED PRIVATE CLIENT');
    }

    public function test_credit_investigator_sees_all_active_folders_regardless_of_assignment(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'AUTHORIZED CLIENT']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'SHARED WORKSPACE CLIENT']);

        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertOk()
            ->assertSee('AUTHORIZED CLIENT')
            ->assertSee('SHARED WORKSPACE CLIENT')
            ->assertSee('Showing 1&ndash;2 of 2', false);
        $this->assertSame(2, $response->viewData('clientFolders')->total());
    }

    public function test_search_matches_client_name_across_all_active_folders(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'SANTOS, MARIA', 'folder_number' => 'BRBI-CI-2026-71001']);
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'REYES, JUAN', 'folder_number' => 'BRBI-CI-2026-71002']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'SANTOS, SHARED', 'folder_number' => 'BRBI-CI-2026-71999']);

        $this->actingAs($ci)->get(route('client-folders.index', ['search' => 'SANTOS']))
            ->assertOk()
            ->assertSee('SANTOS, MARIA')
            ->assertDontSee('REYES, JUAN')
            ->assertSee('SANTOS, SHARED');

        $this->actingAs($ci)->get(route('client-folders.index', ['search' => '71002']))
            ->assertOk()
            ->assertDontSee('REYES, JUAN')
            ->assertDontSee('SANTOS, MARIA')
            ->assertDontSee('BRBI-CI-2026-71999');
    }

    public function test_status_filters_return_only_the_selected_folder_status(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'WORKING CLIENT', 'status' => ClientFolderStatus::OnProgress]);
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'FINISHED CLIENT', 'status' => ClientFolderStatus::Completed]);

        $this->actingAs($ci)->get(route('client-folders.index', ['status' => 'on_progress']))
            ->assertSee('WORKING CLIENT')->assertDontSee('FINISHED CLIENT');
        $this->actingAs($ci)->get(route('client-folders.index', ['status' => 'completed']))
            ->assertSee('FINISHED CLIENT')->assertDontSee('WORKING CLIENT');
    }

    public function test_folders_can_be_sorted_by_updated_created_and_client_name(): void
    {
        $ci = User::factory()->create();
        $alpha = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'ALPHA, ANA', 'last_name' => 'ALPHA', 'first_name' => 'ANA', 'created_at' => Carbon::parse('2026-01-01'), 'updated_at' => Carbon::parse('2026-03-01')]);
        $beta = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'BETA, BEN', 'last_name' => 'BETA', 'first_name' => 'BEN', 'created_at' => Carbon::parse('2026-02-01'), 'updated_at' => Carbon::parse('2026-01-01')]);

        $updated = $this->actingAs($ci)->get(route('client-folders.index'))->viewData('clientFolders');
        $created = $this->actingAs($ci)->get(route('client-folders.index', ['sort' => 'created']))->viewData('clientFolders');
        $named = $this->actingAs($ci)->get(route('client-folders.index', ['sort' => 'client_name']))->viewData('clientFolders');

        $this->assertSame([$alpha->id, $beta->id], $updated->pluck('id')->all());
        $this->assertSame([$beta->id, $alpha->id], $created->pluck('id')->all());
        $this->assertSame([$alpha->id, $beta->id], $named->pluck('id')->all());
    }

    public function test_pagination_preserves_search_status_and_sort_query_string(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->count(13)->create([
            'assigned_ci_id' => $ci->id,
            'display_name' => 'MATCHING CLIENT',
            'status' => ClientFolderStatus::OnProgress,
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.index', [
            'search' => 'MATCHING',
            'status' => 'on_progress',
            'sort' => 'created',
        ]));
        $folders = $response->viewData('clientFolders');

        $response->assertOk()
            ->assertSee('aria-label="Client folders pagination"', false)
            ->assertSee('search=MATCHING', false)
            ->assertSee('status=on_progress', false)
            ->assertSee('sort=created', false);
        $this->assertSame(13, $folders->total());
        $this->assertCount(12, $folders->items());
    }

    public function test_contextual_empty_states_distinguish_no_data_search_and_status_results(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('client-folders.index'))->assertSee('No client folders yet');
        $this->actingAs($ci)->get(route('client-folders.index', ['search' => 'unknown']))->assertSee('No folders match your search');
        $this->actingAs($ci)->get(route('client-folders.index', ['status' => 'on_progress']))->assertSee('No On Progress folders');
        $this->actingAs($ci)->get(route('client-folders.index', ['status' => 'completed']))->assertSee('No Completed folders');
    }

    public function test_page_has_accessible_responsive_card_grid_and_labeled_controls(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'progress_percent' => 65]);

        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertOk()
            ->assertSee('client-folder-browser-layout', false)
            ->assertSee('client-folder-grid', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('role="option"', false)
            ->assertSee('data-folder-preview-panel', false)
            ->assertSee('data-folder-menu-trigger', false)
            ->assertSee('data-folder-action-menu', false)
            ->assertSee('for="folder-search"', false)
            ->assertSee('placeholder="Search client name..."', false)
            ->assertSee('data-client-search-clear', false)
            ->assertSee('px-4 py-3 sm:px-5 sm:py-4', false)
            ->assertSee('flex flex-col gap-3 sm:flex-row sm:items-center', false)
            ->assertSee('min-w-0 w-full flex-1', false)
            ->assertSee('min-h-10 w-full shrink-0 px-4 py-2 sm:w-auto', false)
            ->assertDontSee('role="combobox"', false)
            ->assertDontSee('aria-autocomplete="list"', false)
            ->assertDontSee('data-client-search-list', false)
            ->assertDontSee('data-suggestions-url', false)
            ->assertDontSee('client-folder-browser-title', false)
            ->assertDontSee('Digital Filing Cabinet')
            ->assertDontSee('id="folder-status"', false)
            ->assertDontSee('id="folder-sort"', false)
            ->assertDontSee('>Apply<', false)
            ->assertDontSee('client-folder-number', false)
            ->assertSee('aria-label="Folder completion"', false)
            ->assertSee('aria-valuenow="65"', false)
            ->assertSee('Open Folder')
            ->assertDontSee('View Client Info')
            ->assertSee('Last updated')
            ->assertSee('data-modal-open="create-client-folder-dialog"', false)
            ->assertDontSee('href="'.route('client-folders.create').'"', false);
    }

    public function test_shared_folder_browser_uses_the_compact_responsive_tile_dimensions(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('minmax(9.25rem, 1fr)', $css);
        $this->assertStringContainsString('@apply relative flex min-h-36', $css);
        $this->assertStringContainsString('width: 3.2rem; height: 2.4rem', $css);
        $this->assertStringContainsString('@media (max-width: 639px)', $css);
        $this->assertStringContainsString('width: 3rem; height: 2.25rem', $css);
        $this->assertStringContainsString('height: clamp(34rem, calc(100vh - 10.5rem), 46rem)', $css);
        $this->assertStringContainsString('min-height: 0; height: 100%; max-height: none', $css);
        $this->assertStringContainsString('overflow-x: hidden; overflow-y: auto', $css);
        $this->assertStringContainsString('scrollbar-width: thin', $css);
    }

    public function test_page_reuses_dashboard_compact_preview_without_the_full_progress_section(): void
    {
        $ci = User::factory()->create(['full_name' => 'Assigned Investigator']);
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'display_name' => 'PREVIEW CLIENT',
            'progress_percent' => 40,
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('PREVIEW CLIENT')
            ->assertSee('Assigned Investigator')
            ->assertDontSee('Overall Progress')
            ->assertDontSee('Completion details are not yet available.')
            ->assertSee('aria-valuenow="40"', false)
            ->assertSee('Folder Contents')
            ->assertSee('min-h-10 w-full px-3.5 py-2 text-sm sm:w-auto', false)
            ->assertDontSee('Client Information')
            ->assertDontSee(route('client-folders.client-information.edit', $folder), false)
            ->assertSee(route('client-folders.cibi-report.edit', $folder), false)
            ->assertSee(route('client-folders.income-sources.manage', $folder), false)
            ->assertDontSee('data-business-report-url', false)
            ->assertSee('data-business-report-frame', false)
            ->assertSee(route('client-folders.residence-business.edit', $folder), false)
            ->assertSee(route('client-folders.activities.index', $folder), false)
            ->assertDontSee('Generated Reports')
            ->assertDontSee(route('client-folders.generated-reports.index', $folder), false)
            ->assertDontSee('/client-folders/'.$folder->id.'/media', false);

        $html = $response->getContent();
        $templateStart = strpos($html, 'id="client-folder-preview-'.$folder->id.'"');
        $navStart = strpos($html, '<nav class="folder-contents-nav', $templateStart);
        $navEnd = strpos($html, '</nav>', $navStart);
        $sidePanelNavigation = substr($html, $navStart, $navEnd + 6 - $navStart);
        $this->assertSame(4, substr_count($sidePanelNavigation, 'class="folder-content-link"'));
        $this->assertStringContainsString('CIBI Report', $sidePanelNavigation);
        $this->assertStringContainsString('Business / Income Sources', $sidePanelNavigation);
        $this->assertStringContainsString('href="'.route('client-folders.income-sources.manage', $folder).'" class="folder-content-link"', $sidePanelNavigation);
        $this->assertStringNotContainsString('data-modal-open="business-report-dialog"', $sidePanelNavigation);
        $this->assertStringNotContainsString('data-business-report-url', $sidePanelNavigation);
        $this->assertStringContainsString('Residence &amp; Business Report', $sidePanelNavigation);
        $this->assertStringContainsString('CI Activities', $sidePanelNavigation);
        $this->assertStringNotContainsString('Generated Reports', $sidePanelNavigation);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Business / Income Sources')
            ->assertSee('data-business-activities-layout', false)
            ->assertSee('data-add-business-template-select', false)
            ->assertDontSee('data-folder-browser', false);
    }

    public function test_side_panel_cibi_row_always_uses_navigation_chevron_regardless_of_saved_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $sidePanelCibi = function (string $html) use ($folder): string {
            $marker = 'data-cibi-report-url="'.route('client-folders.cibi-report.edit', $folder).'"';
            $markerPosition = strpos($html, $marker);
            $start = strrpos(substr($html, 0, $markerPosition), '<a ');
            $end = strpos($html, '</a>', $markerPosition);

            return substr($html, $start, $end + 4 - $start);
        };

        $withoutReport = $this->actingAs($ci)
            ->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('data-cibi-report-stay', false)
            ->getContent();
        $unsavedRow = $sidePanelCibi($withoutReport);
        $this->assertStringContainsString('CIBI Report', $unsavedRow);
        $this->assertStringContainsString('data-modal-open="cibi-report-dialog"', $unsavedRow);
        $this->assertStringContainsString('d="m9 5 7 7-7 7"', $unsavedRow);
        $this->assertStringNotContainsString('>Add<', $unsavedRow);
        $this->assertStringNotContainsString('>Open<', $unsavedRow);
        $this->assertStringNotContainsString('d="M12 5v14M5 12h14"', $unsavedRow);
        $this->assertStringNotContainsString('d="m4 20 4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"', $unsavedRow);

        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'ci_in_charge_id' => $ci->id,
        ]);

        $withReport = $this->get(route('client-folders.index'))->assertOk()->getContent();
        $savedRow = $sidePanelCibi($withReport);
        $this->assertStringContainsString('CIBI Report', $savedRow);
        $this->assertStringContainsString('data-modal-open="cibi-report-dialog"', $savedRow);
        $this->assertStringContainsString('d="m9 5 7 7-7 7"', $savedRow);
        $this->assertStringNotContainsString('>Add<', $savedRow);
        $this->assertStringNotContainsString('>Open<', $savedRow);
        $this->assertStringNotContainsString('d="M12 5v14M5 12h14"', $savedRow);
        $this->assertStringNotContainsString('d="m4 20 4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"', $savedRow);

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('dialog.close();', $javascript);
        $this->assertStringNotContainsString('folderBrowserCibiLinkHtml', $javascript);
    }

    public function test_delete_menu_is_visible_to_both_ci_grades_and_administrator(): void
    {
        $assignedCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        $actors = [
            $assignedCi,
            User::factory()->seniorCreditInvestigator()->create(),
            User::factory()->administrator()->create(),
        ];

        foreach ($actors as $actor) {
            $html = $this->actingAs($actor)->get(route('client-folders.index'))->assertOk()->getContent();
            $start = strpos($html, 'id="client-folder-menu-'.$folder->id.'"');
            $end = strpos($html, '</div>', $start);
            $menu = substr($html, $start, $end + 6 - $start);

            $this->assertStringContainsString('data-modal-open="dashboard-delete-dialog-'.$folder->id.'"', $menu);
            $this->assertStringContainsString('d="M4.5 7h15M9 3.5h6L16 7H8l1-3.5ZM7 7l1 13h8l1-13M10 10v7M14 10v7"', $menu);
            $this->assertMatchesRegularExpression('/Delete Permanently\s*<\/button>/', $menu);
            $this->assertStringNotContainsString('Recycle Bin', $html);
            $this->assertStringNotContainsString('Move to Recycle Bin', $html);
        }
    }

    public function test_permanent_delete_modal_distinguishes_empty_and_data_containing_folders_with_icon_actions(): void
    {
        $administrator = User::factory()->administrator()->create();
        $assignedCi = User::factory()->create();
        $emptyFolder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        $dataFolder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        CibiReport::factory()->create([
            'client_folder_id' => $dataFolder->id,
            'ci_in_charge_id' => $assignedCi->id,
        ]);

        $html = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk()->getContent();
        $deleteModal = static function (ClientFolder $folder) use ($html): string {
            $start = strpos($html, 'id="dashboard-delete-dialog-'.$folder->id.'"');
            $end = strpos($html, '</dialog>', $start);

            return substr($html, $start, $end + 9 - $start);
        };

        $emptyModal = $deleteModal($emptyFolder);
        $this->assertStringContainsString('Delete Client Folder Permanently?', $emptyModal);
        $this->assertStringContainsString('data-folder-delete-empty-warning', $emptyModal);
        $this->assertStringContainsString('Are you sure you want to permanently delete this client folder?', $emptyModal);
        $this->assertStringContainsString('This action cannot be undone.', $emptyModal);
        $this->assertStringNotContainsString('data-folder-delete-data-warning', $emptyModal);

        $dataModal = $deleteModal($dataFolder);
        $this->assertStringContainsString('data-folder-delete-data-warning', $dataModal);
        $this->assertStringContainsString('already contains saved data', $dataModal);
        $this->assertStringContainsString('permanently remove the folder and its related records', $dataModal);
        $this->assertStringContainsString('cannot be recovered', $dataModal);
        $this->assertStringContainsString('Are you sure you want to continue?', $dataModal);
        $this->assertStringContainsString('d="M12 3 2.8 20h18.4L12 3Z"', $dataModal);

        foreach ([$emptyModal, $dataModal] as $modal) {
            $this->assertStringContainsString('data-modal-close class="ui-button-secondary shrink-0 whitespace-nowrap"', $modal);
            $this->assertStringContainsString('d="m6 6 12 12M18 6 6 18"', $modal);
            $this->assertMatchesRegularExpression('/Cancel\s*<\/button>/', $modal);
            $this->assertStringContainsString('class="ui-button-danger shrink-0 whitespace-nowrap"', $modal);
            $this->assertStringContainsString('d="M4.5 7h15M9 3.5h6L16 7H8l1-3.5ZM7 7l1 13h8l1-13M10 10v7M14 10v7"', $modal);
            $this->assertMatchesRegularExpression('/Delete Permanently\s*<\/button>/', $modal);
        }

        $this->assertNotNull(ClientFolder::find($emptyFolder->id));
        $this->assertNotNull(ClientFolder::find($dataFolder->id));
        $this->assertStringNotContainsString('Move to Recycle Bin', $html);
    }

    public function test_rename_action_uses_an_accessible_modal_and_the_existing_backend_route(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MODAL CLIENT']);

        $response = $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('data-modal-open="folder-rename-dialog-'.$folder->id.'"', false)
            ->assertSee('id="folder-rename-dialog-'.$folder->id.'"', false)
            ->assertSee('action="'.route('client-folders.update-name', $folder).'"', false)
            ->assertSee('data-folder-rename-form', false)
            ->assertSee('data-folder-edit-action', false)
            ->assertSeeText('Edit Folder')
            ->assertSee('name="last_name"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="middle_name"', false)
            ->assertSee('name="suffix"', false)
            ->assertDontSee('name="display_name"', false)
            ->assertSee('autofocus', false)
            ->assertSeeText('Cancel')
            ->assertSee('data-folder-update-action', false)
            ->assertSee('data-folder-edit-notice', false)
            ->assertSee('role="status" aria-live="polite" data-folder-edit-notice hidden', false)
            ->assertSee('border-progress/30 bg-progress-soft', false)
            ->assertSeeText('Edit Folder')
            ->assertDontSeeText('Update Folder')
            ->assertDontSee('>Rename<', false)
            ->assertDontSee('data-modal-open="dashboard-recycle-dialog-'.$folder->id.'"', false)
            ->assertDontSee('data-folder-recycle-form', false);

        $html = $response->getContent();
        $modalStart = strpos($html, 'id="folder-rename-dialog-'.$folder->id.'"');
        $modalEnd = strpos($html, '</dialog>', $modalStart);
        $notice = strpos($html, 'No changes detected.');
        $this->assertGreaterThan($modalStart, $notice);
        $this->assertLessThan($modalEnd, $notice);
        $footer = strpos($html, '<footer', $modalStart);
        $this->assertGreaterThan($footer, $notice);
        $this->assertSame(1, substr_count($html, 'No changes detected.'));

        $source = file_get_contents(resource_path('views/dashboard/_folder-browser.blade.php'));
        $this->assertStringContainsString('title="Edit Folder"', $source);
        $this->assertStringContainsString("description=\"Edit the client's name details. All related folder records will remain unchanged.\"", $source);
        $this->assertStringContainsString('data-folder-edit-action><x-ui.icon name="edit" size="size-4" />Edit Folder</button>', $source);
        $this->assertStringContainsString('class="flex w-full flex-col gap-2 md:flex-row md:items-center md:gap-3"', $source);
        $this->assertStringContainsString('md:flex-1 md:whitespace-nowrap" role="status"', $source);
        $this->assertStringContainsString('class="flex flex-col gap-2 sm:flex-row sm:justify-end md:ml-auto md:shrink-0"', $source);
        $this->assertStringContainsString('data-modal-close class="ui-button-secondary w-full shrink-0 whitespace-nowrap sm:w-auto"><x-ui.icon name="close"', $source);
        $this->assertStringContainsString('data-folder-edit-notice hidden><x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" aria-hidden="true" />No changes detected.</p>', $source);
        $this->assertStringContainsString('class="ui-button-primary w-full shrink-0 whitespace-nowrap sm:w-auto" data-folder-update-action><x-ui.icon name="edit" size="size-4" />Edit Folder</button>', $source);

        $this->actingAs($ci)->get(route('client-folders.edit-name', $folder))
            ->assertOk()
            ->assertSeeText('Edit Folder')
            ->assertDontSeeText('Update Folder')
            ->assertDontSeeText('Rename Client Folder');
    }

    public function test_create_uses_the_shared_centered_modal_and_open_actions_share_the_folder_contents_route(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertOk()
            ->assertSee('data-modal-open="create-client-folder-dialog"', false)
            ->assertSee('id="create-client-folder-dialog"', false)
            ->assertSee('data-create-folder-modal', false)
            ->assertSee('data-folder-create-form', false)
            ->assertSee('max-w-2xl', false)
            ->assertSee('data-folder-open-action', false)
            ->assertSeeText('Open')
            ->assertSeeText('Open Folder')
            ->assertDontSeeText('View Client Info')
            ->assertSee('min-h-10 w-full px-3.5 py-2 text-sm sm:w-auto', false)
            ->assertDontSee('>Folder Options<', false);

        $this->assertGreaterThanOrEqual(3, substr_count($response->getContent(), route('client-folders.show', $folder)));
    }

    public function test_create_modal_preserves_role_based_credit_investigator_assignment(): void
    {
        $administrator = User::factory()->administrator()->create();
        $activeCi = User::factory()->create(['full_name' => 'VISIBLE MODAL CI']);
        User::factory()->create(['full_name' => 'HIDDEN MODAL CI', 'status' => UserStatus::Disabled]);

        $this->actingAs($administrator)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('name="assigned_ci_id"', false)
            ->assertSee('VISIBLE MODAL CI')
            ->assertDontSee('HIDDEN MODAL CI');

        $this->actingAs($activeCi)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee("You'll be listed as the creator of this folder.", false)
            ->assertDontSee("You'll be recorded as the creator of this folder.", false)
            // The supporting line names the signed-in CI dynamically — never a hard-coded name.
            ->assertSee($activeCi->full_name.' · All Credit Investigators can still access and work on this folder.', false)
            ->assertDontSee('every Credit Investigator can still open and work on it.', false)
            ->assertDontSee('name="assigned_ci_id"', false);
    }

    public function test_preview_width_prioritizes_the_folder_grid_on_desktop(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) minmax(17.5rem, 24%)', $css);
        $this->assertStringContainsString('w-[min(28rem,calc(100%-1rem))]', $css);
    }

    public function test_search_uses_one_custom_clear_control_and_hides_native_clear_controls(): void
    {
        $ci = User::factory()->create();
        $response = $this->actingAs($ci)->get(route('client-folders.index', ['search' => 'REY']));
        $css = file_get_contents(resource_path('css/app.css'));

        $response->assertOk()->assertSee('aria-label="Clear client search"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-client-search-clear'));
        $this->assertStringContainsString('[data-client-search-input]::-webkit-search-cancel-button', $css);
        $this->assertStringContainsString('[data-client-search-input]::-ms-clear', $css);
    }

    public function test_rename_validation_returns_to_and_reopens_the_originating_modal(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)
            ->from(route('client-folders.index'))
            ->patch(route('client-folders.update-name', $folder), [
                'rename_folder_id' => $folder->id,
                'last_name' => '',
                'first_name' => $folder->first_name,
            ])
            ->assertRedirect(route('client-folders.index'))
            ->assertSessionHasErrors('last_name')
            ->assertSessionHasInput('rename_folder_id', (string) $folder->id);

        $this->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('id="folder-rename-dialog-'.$folder->id.'"', false)
            ->assertSee('data-open-on-error="true"', false)
            ->assertSee('id="folder-last-name-error-'.$folder->id.'"', false);
    }

    public function test_invalid_filters_are_rejected_without_executing_an_unbounded_query(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('client-folders.index', ['status' => 'deleted', 'sort' => 'anything']))
            ->assertSessionHasErrors(['status', 'sort']);
    }

    public function test_browser_eager_loads_investigators_with_a_constant_query_count(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->count(12)->create(['assigned_ci_id' => $ci->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $folders = app(ClientFolderBrowser::class)->browse($ci, ['sort' => 'updated']);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queryCount);
        $this->assertTrue($folders->getCollection()->every(
            fn (ClientFolder $folder): bool => $folder->relationLoaded('assignedInvestigator') && $folder->relationLoaded('creator'),
        ));
    }
}
