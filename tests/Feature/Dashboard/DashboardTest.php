<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ClientFolderStatus;
use App\Enums\GenerationStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_summary_counts_all_active_authorized_folders_and_completed_reports(): void
    {
        $administrator = User::factory()->administrator()->create();
        $firstCi = User::factory()->create();
        $secondCi = User::factory()->create();
        $first = ClientFolder::factory()->create(['assigned_ci_id' => $firstCi->id, 'status' => ClientFolderStatus::OnProgress]);
        $second = ClientFolder::factory()->create(['assigned_ci_id' => $firstCi->id, 'status' => ClientFolderStatus::Completed]);
        $third = ClientFolder::factory()->create(['assigned_ci_id' => $secondCi->id, 'status' => ClientFolderStatus::Completed]);
        $deleted = ClientFolder::factory()->create(['assigned_ci_id' => $secondCi->id]);
        $deleted->delete();

        GeneratedReport::factory()->create(['client_folder_id' => $first->id, 'generated_by' => $firstCi->id, 'status' => GenerationStatus::Completed]);
        GeneratedReport::factory()->create(['client_folder_id' => $second->id, 'generated_by' => $firstCi->id, 'status' => GenerationStatus::Completed]);
        GeneratedReport::factory()->create(['client_folder_id' => $third->id, 'generated_by' => $secondCi->id, 'status' => GenerationStatus::Processing]);

        $response = $this->actingAs($administrator)->get(route('home'));

        $response->assertOk();
        $this->assertSame(['total' => 3, 'on_progress' => 1, 'completed' => 2, 'reports_generated' => 2], $response->viewData('summary'));
    }

    public function test_credit_investigator_counts_are_scoped_to_assigned_folders(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $ownProgress = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'status' => ClientFolderStatus::OnProgress]);
        $ownCompleted = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'status' => ClientFolderStatus::Completed]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'status' => ClientFolderStatus::Completed]);

        GeneratedReport::factory()->create(['client_folder_id' => $ownProgress->id, 'generated_by' => $ci->id, 'status' => GenerationStatus::Completed]);
        GeneratedReport::factory()->create(['client_folder_id' => $otherFolder->id, 'generated_by' => $otherCi->id, 'status' => GenerationStatus::Completed]);

        $response = $this->actingAs($ci)->get(route('home'));

        $this->assertSame(['total' => 2, 'on_progress' => 1, 'completed' => 1, 'reports_generated' => 1], $response->viewData('summary'));
        $this->assertCount(2, $response->viewData('recentFolders'));
        $this->assertTrue($response->viewData('recentFolders')->contains($ownCompleted));
        $this->assertFalse($response->viewData('recentFolders')->contains($otherFolder));
    }

    public function test_recent_folders_do_not_leak_other_ci_names_or_numbers(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'AUTHORIZED RECENT CLIENT', 'folder_number' => 'BRBI-CI-2026-10001']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'PRIVATE OTHER CLIENT', 'folder_number' => 'BRBI-CI-2026-10002']);

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee('AUTHORIZED RECENT CLIENT')
            ->assertDontSee('BRBI-CI-2026-10001')
            ->assertDontSee('PRIVATE OTHER CLIENT')
            ->assertDontSee('BRBI-CI-2026-10002');
    }

    public function test_dashboard_omits_the_recent_activity_feed_requested_for_removal(): void
    {
        $ci = User::factory()->create(['full_name' => 'Assigned Investigator']);
        $ownFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'AUTHORIZED ACTIVITY CLIENT']);
        $ownDefinition = ActivityDefinition::factory()->create();

        CiActivity::create(['client_folder_id' => $ownFolder->id, 'activity_definition_id' => $ownDefinition->id, 'name' => 'Residence Check', 'visited_by' => 'Assigned Investigator', 'remarks' => 'Authorized activity remarks.', 'updated_by' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('home'));

        $response->assertSee('AUTHORIZED ACTIVITY CLIENT')
            ->assertDontSee('Recent CI Activities')
            ->assertDontSee('Authorized activity remarks.');
        $this->assertArrayNotHasKey('recentActivities', $response->original->getData());
    }

    public function test_dashboard_empty_states_and_create_modal_with_fallback_route_are_available(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee('No client folders yet')
            ->assertSee('No completed folders yet.')
            ->assertSee('No generated reports yet.')
            ->assertSee('data-modal-open="create-client-folder-dialog"', false)
            ->assertSee('id="create-client-folder-dialog"', false)
            ->assertSee('action="'.route('client-folders.store').'"', false)
            ->assertDontSee('href="'.route('client-folders.create').'"', false);

        $this->actingAs($ci)->get(route('client-folders.create'))
            ->assertOk()
            ->assertSee('Create Client Folder')
            ->assertSee('This folder will be assigned to your account.');
    }

    public function test_dashboard_uses_responsive_card_markup_and_excludes_unapproved_widgets(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $response = $this->actingAs($ci)->get(route('home'));

        $response->assertOk()
            ->assertSee('sm:grid-cols-2 xl:grid-cols-4', false)
            ->assertSeeInOrder(['Dashboard summary', 'data-folder-browser'], false)
            ->assertDontSee('Digital Filing Cabinet')
            ->assertSee('grid gap-2 sm:grid-cols-2 xl:grid-cols-4', false)
            ->assertSee('bg-surface-muted px-3 py-2.5', false)
            ->assertSee('size-8', false)
            ->assertSee('text-xl font-bold tabular-nums', false)
            ->assertSee('client-folder-browser-layout', false)
            ->assertSee('client-folder-grid', false)
            ->assertSee('data-folder-preview-panel', false)
            ->assertSee('aria-label="Folder completion"', false)
            ->assertDontSee('client-folder-number', false)
            ->assertDontSee('id="folder-status"', false)
            ->assertDontSee('id="folder-sort"', false)
            ->assertDontSee('Welcome back,')
            ->assertDontSee('Recent CI Activities')
            ->assertSee('Create Client Folder')
            ->assertDontSee('Approval Queue')
            ->assertDontSee('For Approval')
            ->assertDontSee('Upcoming Field Works')
            ->assertDontSee('Calendar')
            ->assertDontSee('Loan approval')
            ->assertDontSee('CRM')
            ->assertDontSee('Sales')
            ->assertDontSee('Inventory');
    }

    public function test_dashboard_query_count_remains_constant_with_multiple_records(): void
    {
        $ci = User::factory()->create();
        $definitions = ActivityDefinition::factory()->count(8)->create();

        foreach (range(1, 8) as $index) {
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
            CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definitions[$index - 1]->id, 'name' => "Activity $index", 'updated_by' => $ci->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DashboardData::class)->for($ci);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(9, $queryCount);
    }

    public function test_dashboard_folder_browser_preserves_policy_scoped_search_filters_sorting_and_pagination(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();

        ClientFolder::factory()->count(13)->create([
            'assigned_ci_id' => $ci->id,
            'display_name' => 'MATCHING CLIENT',
            'status' => ClientFolderStatus::OnProgress,
        ]);
        ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'display_name' => 'FINISHED CLIENT',
            'status' => ClientFolderStatus::Completed,
        ]);
        ClientFolder::factory()->create([
            'assigned_ci_id' => $otherCi->id,
            'display_name' => 'MATCHING PRIVATE CLIENT',
            'status' => ClientFolderStatus::OnProgress,
        ]);

        $response = $this->actingAs($ci)->get(route('home', [
            'search' => 'MATCHING',
            'status' => 'on_progress',
            'sort' => 'created',
        ]));

        $response->assertOk()
            ->assertSee('MATCHING CLIENT')
            ->assertDontSee('FINISHED CLIENT')
            ->assertDontSee('MATCHING PRIVATE CLIENT')
            ->assertSee('aria-label="Client folders pagination"', false)
            ->assertSee('search=MATCHING', false)
            ->assertSee('status=on_progress', false)
            ->assertSee('sort=created', false);

        $folders = $response->viewData('clientFolders');
        $this->assertSame(13, $folders->total());
        $this->assertCount(12, $folders->items());
    }

    public function test_dashboard_folder_tiles_and_compact_preview_expose_accessible_selection_and_folder_contents(): void
    {
        $ci = User::factory()->create(['full_name' => 'Assigned CI Name']);
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'display_name' => 'LONG CLIENT RECORD NAME',
            'folder_number' => 'BRBI-CI-2026-88001',
            'progress_percent' => 50,
        ]);
        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee('role="listbox"', false)
            ->assertSee('role="option"', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('aria-selected="false"', false)
            ->assertSee('data-folder-open-url', false)
            ->assertSee('Select a Client Folder')
            ->assertSee('client-folder-preview-'.$folder->id, false)
            ->assertSee('CI / BI Report')
            ->assertSee('Folder Contents')
            ->assertSee('Business / Income Sources')
            ->assertSee('Residence &amp; Business Report', false)
            ->assertSee('CI Activities')
            ->assertSee(route('client-folders.media.index', $folder), false)
            ->assertSee('Generated Reports')
            ->assertSee('Attachments / Documents')
            ->assertDontSee('View Client Info')
            ->assertDontSee('Client Information')
            ->assertDontSee(route('client-folders.client-information.edit', $folder), false)
            ->assertSee(route('client-folders.cibi-report.edit', $folder), false)
            ->assertSee(route('client-folders.income-sources.index', $folder), false)
            ->assertSee(route('client-folders.residence-business.edit', $folder), false)
            ->assertSee(route('client-folders.activities.index', $folder), false)
            ->assertSee(route('client-folders.generated-reports.index', $folder), false)
            ->assertDontSee('Overall Progress')
            ->assertDontSee('Completion details are not yet available.')
            ->assertSee('bg-brand-soft/55 p-3 sm:p-4', false)
            ->assertSee('mt-2.5 grid grid-cols-2 gap-2 text-xs', false)
            ->assertSee('aria-valuenow="50"', false)
            ->assertSee('Assigned CI Name')
            ->assertDontSee('Income sources')
            ->assertDontSee('Activities done')
            ->assertDontSee('Reports ready');
    }

    public function test_dashboard_context_actions_reuse_authorized_folder_routes_without_leaking_other_ci_folders(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $own = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'OWN BROWSER FOLDER']);
        $other = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'PRIVATE BROWSER FOLDER']);

        $response = $this->actingAs($ci)->get(route('home'));

        $response->assertOk()
            ->assertSee('OWN BROWSER FOLDER')
            ->assertDontSee('PRIVATE BROWSER FOLDER')
            ->assertSee(route('client-folders.show', $own), false)
            ->assertDontSee(route('client-folders.edit-name', $own), false)
            ->assertSee(route('client-folders.update-name', $own), false)
            ->assertSee(route('client-folders.destroy', $own), false)
            ->assertDontSee(route('client-folders.show', $other), false)
            ->assertSee('data-modal-open="folder-rename-dialog-'.$own->id.'"', false)
            ->assertSee('id="folder-rename-dialog-'.$own->id.'"', false)
            ->assertSee('data-modal-open="dashboard-recycle-dialog-'.$own->id.'"', false)
            ->assertSee('id="dashboard-recycle-dialog-'.$own->id.'"', false)
            ->assertSee('aria-haspopup="menu"', false)
            ->assertSeeText('Open')
            ->assertSee('Move to Recycle Bin');

        $this->assertGreaterThanOrEqual(3, substr_count($response->getContent(), route('client-folders.show', $own)));
    }

    public function test_dashboard_rejects_invalid_folder_browser_filters(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('home', ['status' => 'deleted', 'sort' => 'unknown']))
            ->assertSessionHasErrors(['status', 'sort']);
    }
}
