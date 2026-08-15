<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ClientFolderStatus;
use App\Enums\UserStatus;
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

    public function test_credit_investigator_sees_only_assigned_folders_and_no_cross_ci_statistics(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'AUTHORIZED CLIENT']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'PRIVATE OTHER CLIENT']);

        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertOk()
            ->assertSee('AUTHORIZED CLIENT')
            ->assertDontSee('PRIVATE OTHER CLIENT')
            ->assertSee('Showing 1&ndash;1 of 1', false);
        $this->assertSame(1, $response->viewData('clientFolders')->total());
    }

    public function test_search_matches_client_name_only_inside_authorized_scope(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'SANTOS, MARIA', 'folder_number' => 'BRBI-CI-2026-71001']);
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'REYES, JUAN', 'folder_number' => 'BRBI-CI-2026-71002']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'SANTOS, PRIVATE', 'folder_number' => 'BRBI-CI-2026-71999']);

        $this->actingAs($ci)->get(route('client-folders.index', ['search' => 'SANTOS']))
            ->assertOk()
            ->assertSee('SANTOS, MARIA')
            ->assertDontSee('REYES, JUAN')
            ->assertDontSee('SANTOS, PRIVATE');

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

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
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

        $this->actingAs($ci)->get(route('client-folders.index'))
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
            ->assertSee(route('client-folders.income-sources.index', $folder), false)
            ->assertSee('data-modal-open="business-report-dialog"', false)
            ->assertSee('data-business-report-url="'.route('client-folders.income-sources.index', $folder).'"', false)
            ->assertSee('data-business-report-frame', false)
            ->assertSee(route('client-folders.generated-reports.index', $folder), false)
            ->assertSee(route('client-folders.media.index', $folder), false);
    }

    public function test_rename_and_recycle_actions_use_accessible_modals_and_existing_backend_routes(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MODAL CLIENT']);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('data-modal-open="folder-rename-dialog-'.$folder->id.'"', false)
            ->assertSee('id="folder-rename-dialog-'.$folder->id.'"', false)
            ->assertSee('action="'.route('client-folders.update-name', $folder).'"', false)
            ->assertSee('data-folder-rename-form', false)
            ->assertSee('name="display_name"', false)
            ->assertSee('autofocus', false)
            ->assertSee('>Cancel<', false)
            ->assertSee('>Rename<', false)
            ->assertSee('data-modal-open="dashboard-recycle-dialog-'.$folder->id.'"', false)
            ->assertSee('action="'.route('client-folders.destroy', $folder).'"', false)
            ->assertSee('data-folder-recycle-form', false)
            ->assertSee('restored according to the existing authorization rules');
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
            ->assertSee('This folder will be assigned to your account.')
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
                'display_name' => '',
            ])
            ->assertRedirect(route('client-folders.index'))
            ->assertSessionHasErrors('display_name')
            ->assertSessionHasInput('rename_folder_id', (string) $folder->id);

        $this->withSession([
            '_old_input' => ['rename_folder_id' => (string) $folder->id, 'display_name' => ''],
        ])->withViewErrors([
            'display_name' => 'The display name field is required.',
        ])->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('id="folder-rename-dialog-'.$folder->id.'"', false)
            ->assertSee('data-open-on-error="true"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="folder-display-name-error-'.$folder->id.'"', false)
            ->assertSee('role="alert"', false);
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

        $this->assertLessThanOrEqual(3, $queryCount);
        $this->assertTrue($folders->getCollection()->every(
            fn (ClientFolder $folder): bool => $folder->relationLoaded('assignedInvestigator'),
        ));
    }
}
