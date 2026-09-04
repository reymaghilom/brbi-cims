<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ClientFolderStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_folders_show_the_shared_workspace_without_folder_numbers(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'AUTHORIZED RECENT CLIENT', 'folder_number' => 'BRBI-CI-2026-10001']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'SHARED WORKSPACE CLIENT', 'folder_number' => 'BRBI-CI-2026-10002']);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('AUTHORIZED RECENT CLIENT')
            ->assertDontSee('BRBI-CI-2026-10001')
            ->assertSee('SHARED WORKSPACE CLIENT')
            ->assertDontSee('BRBI-CI-2026-10002');
    }

    public function test_dashboard_omits_the_recent_activity_feed_requested_for_removal(): void
    {
        $ci = User::factory()->create(['full_name' => 'Assigned Investigator']);
        $ownFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'AUTHORIZED ACTIVITY CLIENT']);
        $ownDefinition = ActivityDefinition::factory()->create();

        CiActivity::create(['client_folder_id' => $ownFolder->id, 'activity_definition_id' => $ownDefinition->id, 'name' => 'Residence Check', 'visited_by' => 'Assigned Investigator', 'remarks' => 'Authorized activity remarks.', 'updated_by' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertSee('AUTHORIZED ACTIVITY CLIENT')
            ->assertDontSee('Recent CI Activities')
            ->assertDontSee('Authorized activity remarks.');
        $this->assertArrayNotHasKey('recentActivities', $response->original->getData());
    }

    public function test_dashboard_empty_states_and_create_modal_with_fallback_route_are_available(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('No client folders yet')
            ->assertSee('data-modal-open="create-client-folder-dialog"', false)
            ->assertSee('id="create-client-folder-dialog"', false)
            ->assertSee('action="'.route('client-folders.store').'"', false)
            ->assertDontSee('href="'.route('client-folders.create').'"', false);

        $this->actingAs($ci)->get(route('client-folders.create'))
            ->assertOk()
            ->assertSee('Create Client Folder')
            ->assertSee("You'll be recorded as the creator of this folder.", false);
    }

    public function test_dashboard_uses_responsive_card_markup_and_excludes_unapproved_widgets(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertOk()
            ->assertDontSee('Digital Filing Cabinet')
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

        $response = $this->actingAs($ci)->get(route('client-folders.index', [
            'search' => 'MATCHING',
            'status' => 'on_progress',
            'sort' => 'created',
        ]));

        $response->assertOk()
            ->assertSee('MATCHING CLIENT')
            ->assertDontSee('FINISHED CLIENT')
            ->assertSee('MATCHING PRIVATE CLIENT')
            ->assertSee('aria-label="Client folders pagination"', false)
            ->assertSee('search=MATCHING', false)
            ->assertSee('status=on_progress', false)
            ->assertSee('sort=created', false);

        $folders = $response->viewData('clientFolders');
        $this->assertSame(14, $folders->total());
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
        $this->actingAs($ci)->get(route('client-folders.index'))
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
            ->assertDontSee('/client-folders/'.$folder->id.'/media', false)
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

    public function test_dashboard_preview_panel_shows_created_and_last_updated_date_time_and_names(): void
    {
        $creator = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $updater = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
            // Stored in UTC (app timezone); these are the UTC instants for
            // Aug 9, 2026 9:42 AM and Aug 21, 2026 3:18 PM in Asia/Manila (UTC+8).
            'created_at' => Carbon::parse('2026-08-09 01:42:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-08-21 07:18:00', 'UTC'),
        ]);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('client-folder-preview-'.$folder->id, false)
            ->assertSeeInOrder(['Created', 'Aug 9, 2026', '9:42 AM', 'by REASAN MARK Q. GURA'])
            ->assertSeeInOrder(['Last updated', 'Aug 21, 2026', '3:18 PM', 'by REY C. MAGHILOM'])
            ->assertDontSee('12:42 AM')
            ->assertDontSee('Assigned Credit Investigator');
    }

    public function test_dashboard_preview_panel_shows_muted_dash_when_updater_is_null(): void
    {
        $creator = User::factory()->create(['full_name' => 'ORIGINAL CREATOR']);
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $creator->id,
            'updated_by' => null,
        ]);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('client-folder-preview-'.$folder->id, false)
            ->assertSeeInOrder(['by ORIGINAL CREATOR', 'Last updated'])
            ->assertSeeInOrder(['Last updated', 'by —'], false);
    }

    public function test_dashboard_preview_panel_shows_the_folder_history_action_for_the_selected_folder(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'RENAMED']);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSeeInOrder(['client-folder-preview-'.$folder->id, 'Folder History'], false);
    }

    public function test_dashboard_preview_panel_omits_the_folder_history_action_without_history(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertDontSee('Folder History');
    }

    public function test_dashboard_folder_history_modal_shows_rename_events_newest_first(): void
    {
        $first = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $second = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $first->id, 'display_name' => 'JUAN REYES']);

        $this->actingAs($first)->patch(route('client-folders.update-name', $folder), ['display_name' => 'REYES, JUAN']);
        $this->actingAs($second)->patch(route('client-folders.update-name', $folder), ['display_name' => 'REYES, JUAN JR.']);

        // audit_logs.created_at uses a DB-level CURRENT_TIMESTAMP default (the model disables
        // Eloquent timestamps), so travelTo() can't fake it — set deterministic times directly.
        // The raw stored value is genuine UTC (the DB connection's session time_zone is forced to
        // UTC, matching APP_TIMEZONE) — AuditLog::createdAt() converts it to Manila on read, so
        // the UTC digits stored here are 8 hours behind the intended Manila display times below.
        AuditLog::where('client_folder_id', $folder->id)->where('metadata->new_name', 'REYES, JUAN')
            ->update(['created_at' => '2026-08-23 02:20:00']);
        AuditLog::where('client_folder_id', $folder->id)->where('metadata->new_name', 'REYES, JUAN JR.')
            ->update(['created_at' => '2026-08-23 03:45:00']);

        $this->actingAs($first)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSeeInOrder([
                '11:45 AM', 'Folder Updated', 'REYES, JUAN', 'REYES, JUAN JR.', 'REY C. MAGHILOM',
                '10:20 AM', 'Folder Updated', 'JUAN REYES', 'REYES, JUAN', 'REASAN MARK Q. GURA',
            ]);
    }

    public function test_folder_history_shows_creation_with_actor_and_asia_manila_timestamp(): void
    {
        $creator = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id, 'created_by' => $creator->id]);
        AuditLog::create([
            'user_id' => $creator->id, 'client_folder_id' => $folder->id, 'action' => 'client_folder.created',
            'module' => 'client_folders', 'description' => 'A client folder was created.', 'metadata' => [],
        ]);
        // 01:42 UTC converts to 9:42 AM Manila (+8).
        AuditLog::where('client_folder_id', $folder->id)->where('action', 'client_folder.created')
            ->update(['created_at' => '2026-08-23 01:42:00']);

        $this->actingAs($creator)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSeeInOrder(['Aug 23, 2026', '9:42 AM', 'Folder Created', 'by REASAN MARK Q. GURA']);
    }

    public function test_folder_history_excludes_recycle_restore_permanent_delete_and_module_activity(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'RENAMED']);
        foreach ([
            ['action' => 'client_folder.recycled', 'module' => 'client_folders', 'description' => 'x'],
            ['action' => 'client_folder.restored', 'module' => 'client_folders', 'description' => 'x'],
            ['action' => 'client_folder.permanently_deleted', 'module' => 'client_folders', 'description' => 'x'],
            ['action' => 'cibi_report.updated', 'module' => 'cibi_report', 'description' => 'x'],
            ['action' => 'business_report.updated', 'module' => 'income_sources', 'description' => 'x'],
            ['action' => 'residence_check.updated', 'module' => 'residence_business_report', 'description' => 'x'],
            ['action' => 'ci_activity.updated', 'module' => 'ci_activities', 'description' => 'x'],
            ['action' => 'media.uploaded', 'module' => 'media', 'description' => 'x'],
            ['action' => 'co_maker.added', 'module' => 'client_folders', 'description' => 'x'],
        ] as $event) {
            AuditLog::create($event + ['user_id' => $ci->id, 'client_folder_id' => $folder->id, 'metadata' => []]);
        }

        $content = $this->actingAs($ci)->get(route('client-folders.index'))->assertOk()->getContent();
        $modalStart = strpos($content, 'id="folder-history-dialog"');
        $modalEnd = strpos($content, '</dialog>', $modalStart);
        $modal = substr($content, $modalStart, $modalEnd - $modalStart);

        $this->assertStringNotContainsString('Recycle Bin', $modal);
        $this->assertStringNotContainsString('Restored', $modal);
        $this->assertStringNotContainsString('Permanently', $modal);
        $this->assertStringNotContainsString('CI/BI', $modal);
        $this->assertStringNotContainsString('Business', $modal);
        $this->assertStringNotContainsString('Residence', $modal);
        $this->assertStringNotContainsString('Activity', $modal);
        $this->assertStringNotContainsString('Photo', $modal);
        $this->assertStringNotContainsString('Co-Maker', $modal);
        $this->assertStringContainsString('Folder Updated', $modal);
    }

    public function test_one_ci_can_see_folder_history_for_an_active_folder_assigned_to_another_ci(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $owner->id]);
        $this->actingAs($owner)->patch(route('client-folders.update-name', $folder), ['display_name' => 'RENAMED']);

        $this->actingAs($other)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('Folder History')
            ->assertSee('RENAMED');
    }

    public function test_dashboard_context_actions_reuse_authorized_folder_routes_across_the_shared_workspace(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $own = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'OWN BROWSER FOLDER']);
        $other = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'SHARED BROWSER FOLDER']);

        $response = $this->actingAs($ci)->get(route('client-folders.index'));

        $response->assertOk()
            ->assertSee('OWN BROWSER FOLDER')
            ->assertSee('SHARED BROWSER FOLDER')
            ->assertSee(route('client-folders.show', $own), false)
            ->assertDontSee(route('client-folders.edit-name', $own), false)
            ->assertSee(route('client-folders.update-name', $own), false)
            ->assertSee(route('client-folders.destroy', $own), false)
            ->assertSee(route('client-folders.show', $other), false)
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

        $this->actingAs($ci)->get(route('client-folders.index', ['status' => 'deleted', 'sort' => 'unknown']))
            ->assertSessionHasErrors(['status', 'sort']);
    }
}
