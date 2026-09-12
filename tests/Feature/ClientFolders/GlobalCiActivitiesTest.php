<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlobalCiActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', config('cims.display_timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ==================================================
    // Rendering / placeholder removal
    // ==================================================

    public function test_page_renders_for_authorized_ci_user(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('ci-activities.index'))
            ->assertOk()
            ->assertSee('CI Activities')
            ->assertSee('Manage and monitor your investigation activities across all clients.');
    }

    public function test_placeholder_text_is_removed(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('is not implemented yet', $content);
        $this->assertStringNotContainsString('Phase 5 provides', $content);
        $this->assertStringNotContainsString('Workspace module', $content);
    }

    public function test_guest_cannot_view_the_page(): void
    {
        $this->get(route('ci-activities.index'))->assertRedirect(route('login'));
    }

    // ==================================================
    // Cross-folder rendering + person labeling + isolation
    // ==================================================

    public function test_activities_across_multiple_client_folders_render_together(): void
    {
        $ci = User::factory()->create();
        $folderOne = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $folderTwo = $this->folderFor($ci, ['display_name' => 'Maria Santos']);
        $this->simpleActivity($folderOne, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($folderTwo, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('RONILO MICABALO', $content);
        $this->assertStringContainsString('MARIA SANTOS', $content);
    }

    public function test_applicant_activity_displays_as_applicant(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Applicant', $content);
        $this->assertStringContainsString('Ronilo Micabalo', $content);
    }

    public function test_co_maker_activity_displays_as_co_maker_with_correct_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $coMaker = $this->coMakerFor($folder, 'Juan Dela Cruz');
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Co-Maker', $content);
        $this->assertStringContainsString('Juan Dela Cruz', $content);
    }

    public function test_person_column_no_longer_renders_an_initial_avatar_circle(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $coMaker = $this->coMakerFor($folder, 'Maria Santos');
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        // The single-letter avatar circle is gone, but the actual person information remains.
        $this->assertStringNotContainsString('rounded-full bg-brand-soft text-xs font-bold text-brand-primary', $content);
        $this->assertStringContainsString('Applicant', $content);
        $this->assertStringContainsString('Ronilo Micabalo', $content);
        $this->assertStringContainsString('Co-Maker', $content);
        $this->assertStringContainsString('Maria Santos', $content);
    }

    public function test_applicant_and_co_maker_rows_never_mix_person_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $coMaker = $this->coMakerFor($folder, 'Juan Dela Cruz');
        $applicantActivity = $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $coMakerActivity = $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        // Open always lands on the exact Client Folder's own CI Activities table, in the exact
        // Applicant/Co-Maker context — never a type-specific tracker/modal URL.
        $applicantOpenUrl = route('client-folders.activities.index', [$folder]).'#activity-'.$applicantActivity->id;
        $coMakerOpenUrl = route('client-folders.activities.index', [$folder], false).'?person=co-maker&co_maker_id='.$coMaker->id.'#activity-'.$coMakerActivity->id;
        $this->assertStringContainsString($applicantOpenUrl, $content);
        $this->assertStringContainsString(htmlspecialchars($coMakerOpenUrl), $content);
    }

    public function test_client_folder_isolation_open_links_never_cross_folders(): void
    {
        $ciOne = User::factory()->create();
        $ciTwo = User::factory()->create();
        $folderOne = $this->folderFor($ciOne, ['display_name' => 'Folder One Client']);
        $folderTwo = $this->folderFor($ciTwo, ['display_name' => 'Folder Two Client']);
        $activityOne = $this->simpleActivity($folderOne, $ciOne, ActivityDefinition::BARANGAY_CHECK_CODE);
        $activityTwo = $this->simpleActivity($folderTwo, $ciTwo, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ciOne)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('client-folders.activities.index', [$folderOne]).'#activity-'.$activityOne->id, $content);
        $this->assertStringContainsString(route('client-folders.activities.index', [$folderTwo]).'#activity-'.$activityTwo->id, $content);
    }

    // ==================================================
    // Bank / Asset target-derived schedule + NEXT label
    // ==================================================

    public function test_bank_target_derived_schedule_displays(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'BPI',
            'branch_location' => 'Carmen',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 20:45:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);

        $this->assertNull($bank->fresh()->scheduled_at);
        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('BPI – Carmen', $content);
        $this->assertStringContainsString('Sep 1, 2026', $content);
        $this->assertStringContainsString('8:45 PM', $content);
    }

    public function test_asset_target_derived_schedule_displays(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->assetActivity($folder, $ci);
        $this->assetTarget($asset, $ci, [
            'office_location' => 'Cagayan de Oro City',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 20:46:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => true,
        ]);

        $this->assertNull($asset->fresh()->scheduled_at);
        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('City Assessor — Cagayan de Oro City', $content);
        $this->assertStringContainsString('8:46 PM', $content);
    }

    public function test_date_only_target_schedule_does_not_show_fabricated_eight_am(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'FICCO',
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-02 08:00:00', config('cims.display_timezone'))->utc(),
            'scheduled_has_time' => false,
        ]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Sep 2, 2026', $content);
        $this->assertStringNotContainsString('8:00 AM', $content);
        $this->assertStringContainsString('No specific time', $content);
    }

    public function test_multiple_bank_schedules_show_earliest_primary_with_additional_count(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 20:45:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BPI', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-01 20:30:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Metrobank', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => Carbon::parse('2026-09-02 09:00:00', config('cims.display_timezone'))->utc(), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('BPI', $content);
        $this->assertStringContainsString('+2 more scheduled', $content);
    }

    public function test_no_next_label_is_ever_shown(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BPI', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDays(2), 'scheduled_has_time' => true]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>Next<', $content);
        $this->assertStringNotContainsString('NEXT', $content);
    }

    // ==================================================
    // Status filters
    // ==================================================

    public function test_status_filter_pending(): void
    {
        $ci = User::factory()->create();
        $pendingFolder = $this->folderFor($ci, ['display_name' => 'Pending Folder Client']);
        $scheduledFolder = $this->folderFor($ci, ['display_name' => 'Scheduled Folder Client']);
        $followUpFolder = $this->folderFor($ci, ['display_name' => 'Follow Up Folder Client']);
        $completedFolder = $this->folderFor($ci, ['display_name' => 'Completed Folder Client']);
        $this->simpleActivity($pendingFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Pending]);
        $this->simpleActivity($scheduledFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $this->simpleActivity($followUpFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::FollowUp]);
        $this->simpleActivity($completedFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $response = $this->actingAs($ci)->get(route('ci-activities.index', ['status' => 'pending']))->assertOk();
        $content = $response->getContent();

        $this->assertSame('pending', $response->viewData('filters')['status']);
        $this->assertStringContainsString('<option value="pending" selected>Pending</option>', $content);
        $this->assertStringContainsString('PENDING FOLDER CLIENT', $content);
        $this->assertStringNotContainsString('SCHEDULED FOLDER CLIENT', $content);
        $this->assertStringNotContainsString('FOLLOW UP FOLDER CLIENT', $content);
        $this->assertStringNotContainsString('COMPLETED FOLDER CLIENT', $content);
    }

    public function test_pending_status_filter_paginates_only_pending_results(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        foreach (range(1, 13) as $index) {
            $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
                'name' => 'Pending Activity '.$index,
                'status' => ActivityStatus::Pending,
            ]);
        }
        foreach ([ActivityStatus::Scheduled, ActivityStatus::FollowUp, ActivityStatus::Completed] as $index => $status) {
            $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
                'name' => 'Excluded Activity '.$index,
                'status' => $status,
                'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            ]);
        }

        $response = $this->actingAs($ci)->get(route('ci-activities.index', [
            'status' => 'pending',
            'per_page' => 5,
            'page' => 2,
        ]))->assertOk();
        $rows = $response->viewData('rows');

        $this->assertSame(13, $rows->total());
        $this->assertSame(2, $rows->currentPage());
        $this->assertCount(5, $rows->items());
        $this->assertTrue(collect($rows->items())->every(fn ($row) => $row->statusValue === ActivityStatus::Pending->value));
        $this->assertStringContainsString('status=pending', $response->getContent());
    }

    public function test_direct_access_keeps_the_default_unfiltered_status(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Pending]);
        $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $response = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk();

        $this->assertSame('', $response->viewData('filters')['status']);
        $this->assertCount(2, $response->viewData('rows')->items());
        $this->assertStringContainsString('<option value="" selected>All Statuses</option>', $response->getContent());
    }

    public function test_status_filter_scheduled(): void
    {
        $ci = User::factory()->create();
        $scheduledFolder = $this->folderFor($ci, ['display_name' => 'Scheduled Folder Client']);
        $pendingFolder = $this->folderFor($ci, ['display_name' => 'Pending Folder Client']);
        $this->simpleActivity($scheduledFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $this->simpleActivity($pendingFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::Pending]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['status' => 'scheduled']))->assertOk()->getContent();

        $this->assertStringContainsString('SCHEDULED FOLDER CLIENT', $content);
        $this->assertStringNotContainsString('PENDING FOLDER CLIENT', $content);
    }

    public function test_status_filter_follow_up(): void
    {
        $ci = User::factory()->create();
        $followUpFolder = $this->folderFor($ci, ['display_name' => 'Follow Up Folder Client']);
        $pendingFolder = $this->folderFor($ci, ['display_name' => 'Pending Folder Client']);
        $this->simpleActivity($followUpFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::FollowUp]);
        $this->simpleActivity($pendingFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::Pending]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['status' => 'follow_up']))->assertOk()->getContent();

        $this->assertStringContainsString('FOLLOW UP FOLDER CLIENT', $content);
        $this->assertStringNotContainsString('PENDING FOLDER CLIENT', $content);
    }

    public function test_status_filter_completed(): void
    {
        $ci = User::factory()->create();
        $completedFolder = $this->folderFor($ci, ['display_name' => 'Completed Folder Client']);
        $pendingFolder = $this->folderFor($ci, ['display_name' => 'Pending Folder Client']);
        $this->simpleActivity($completedFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed, 'completed_at' => now()]);
        $this->simpleActivity($pendingFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::Pending]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['status' => 'completed']))->assertOk()->getContent();

        $this->assertStringContainsString('COMPLETED FOLDER CLIENT', $content);
        $this->assertStringNotContainsString('PENDING FOLDER CLIENT', $content);
    }

    // ==================================================
    // Due Today / Overdue derived
    // ==================================================

    public function test_due_today_tab_shows_only_items_scheduled_today(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 15:00:00', config('cims.display_timezone'))->utc(),
            'name' => 'Due Today Check',
        ]);
        $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-05 15:00:00', config('cims.display_timezone'))->utc(),
            'name' => 'Future Check',
        ]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['tab' => 'due_today']))->assertOk()->getContent();

        $this->assertStringContainsString('Due Today Check', $content);
        $this->assertStringNotContainsString('Future Check', $content);
    }

    public function test_overdue_display_never_mutates_persisted_status(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-08-30 09:00:00', config('cims.display_timezone'))->utc(),
        ]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Overdue by', $content);
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
    }

    // ==================================================
    // Search
    // ==================================================

    public function test_search_by_client_name(): void
    {
        $ci = User::factory()->create();
        $folderMatch = $this->folderFor($ci, ['display_name' => 'Pedro Garcia']);
        $folderOther = $this->folderFor($ci, ['display_name' => 'Ana Lou Dela Cruz']);
        $this->simpleActivity($folderMatch, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($folderOther, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['search' => 'Pedro Garcia']))->assertOk()->getContent();

        $this->assertStringContainsString('PEDRO GARCIA', $content);
        $this->assertStringNotContainsString('ANA LOU DELA CRUZ', $content);
    }

    public function test_search_by_activity_name(): void
    {
        $ci = User::factory()->create();
        $barangayFolder = $this->folderFor($ci, ['display_name' => 'Barangay Row Client']);
        $neighborFolder = $this->folderFor($ci, ['display_name' => 'Neighbor Row Client']);
        $this->simpleActivity($barangayFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($neighborFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['search' => 'Barangay Check']))->assertOk()->getContent();

        $this->assertStringContainsString('BARANGAY ROW CLIENT', $content);
        $this->assertStringNotContainsString('NEIGHBOR ROW CLIENT', $content);
    }

    public function test_search_renders_the_shared_client_autosuggest_combobox_and_async_contract(): void
    {
        $ci = User::factory()->create();
        $this->folderFor($ci, ['display_name' => 'Pedro Garcia']);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', [
            'tab' => 'scheduled',
            'status' => 'scheduled',
            'person' => 'applicant',
        ]))->assertOk()->getContent();

        foreach (['data-ci-client-search', 'data-ci-client-input', 'data-ci-client-suggestions', 'role="combobox"', 'role="listbox"', 'aria-autocomplete="list"'] as $hook) {
            $this->assertStringContainsString($hook, $content);
        }
        $this->assertStringContainsString(e(route('reports.client-suggestions')), $content);
        $this->assertStringContainsString('name="tab" value="scheduled"', $content);
        $this->assertStringContainsString('name="person"', $content);

        $suggestions = $this->actingAs($ci)
            ->getJson(route('reports.client-suggestions', ['q' => 'Pedro']))
            ->assertOk()
            ->json('suggestions');
        $this->assertSame('PEDRO GARCIA', strtoupper($suggestions[0]['name']));

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("document.querySelectorAll('[data-reports-client-search], [data-ci-client-search]').forEach(initReportsClientSearch);", $javascript);
        $this->assertStringContainsString("const region = document.querySelector(isCiActivities ? '[data-ci-activities-listing]' : '[data-reports-listing]');", $javascript);
        $this->assertStringContainsString('new FormData(form).forEach', $javascript);
        $this->assertStringContainsString('activeRequest?.abort();', $javascript);
        $this->assertStringContainsString('if (activeRequest !== request) return;', $javascript);
        $this->assertStringContainsString("runSearch('replace');", $javascript);
        $this->assertStringContainsString("runSearch('push');", $javascript);
        $this->assertStringContainsString("event.key === 'ArrowDown' || event.key === 'ArrowUp'", $javascript);
        $this->assertStringContainsString("if (event.key === 'Enter')", $javascript);
        $this->assertStringContainsString("if (event.key === 'Escape')", $javascript);
        $this->assertStringContainsString('if (!container.contains(event.target)) close();', $javascript);
    }

    public function test_client_loan_column_hides_the_folder_number_without_changing_its_identity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, [
            'display_name' => 'Juan Dela Cruz',
            'folder_number' => 'BRBI-CI-2026-00026',
        ]);
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('JUAN DELA CRUZ', $content);
        $this->assertStringNotContainsString('#BRBI-CI-2026-00026', $content);
        $this->assertSame('BRBI-CI-2026-00026', $folder->fresh()->folder_number);
    }

    // ==================================================
    // Person / activity type / schedule filters
    // ==================================================

    public function test_person_filter_applicant(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $coMaker = $this->coMakerFor($folder, 'Juan Dela Cruz');
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['person' => 'applicant']))->assertOk()->getContent();

        $this->assertStringContainsString('Applicant', $content);
        $this->assertStringNotContainsString('Juan Dela Cruz', $content);
    }

    public function test_person_filter_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Ronilo Micabalo']);
        $coMaker = $this->coMakerFor($folder, 'Juan Dela Cruz');
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['person' => 'co_maker']))->assertOk()->getContent();

        $this->assertStringContainsString('Juan Dela Cruz', $content);
    }

    public function test_activity_type_filter(): void
    {
        $ci = User::factory()->create();
        $barangayFolder = $this->folderFor($ci, ['display_name' => 'Barangay Row Client']);
        $neighborFolder = $this->folderFor($ci, ['display_name' => 'Neighbor Row Client']);
        $this->simpleActivity($barangayFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->simpleActivity($neighborFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['activity_type' => ActivityDefinition::BARANGAY_CHECK_CODE]))->assertOk()->getContent();

        $this->assertStringContainsString('BARANGAY ROW CLIENT', $content);
        $this->assertStringNotContainsString('NEIGHBOR ROW CLIENT', $content);
    }

    public function test_activity_type_dropdown_offers_the_canonical_operational_types(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('>Barangay Check</option>', $content);
        $this->assertStringContainsString('>Neighbor Check</option>', $content);
        $this->assertStringContainsString('>Bank / Coop Check</option>', $content);
        $this->assertStringContainsString('>Asset Check</option>', $content);
        // Residence Check / Business Check are dedicated modules, never surfaced through this
        // generic Activity Type filter - is_active = false for both in ReferenceDataSeeder.
        $this->assertStringNotContainsString('>Residence Check</option>', $content);
        $this->assertStringNotContainsString('>Business Check</option>', $content);
    }

    public function test_activity_type_dropdown_includes_a_newly_created_custom_definition(): void
    {
        $ci = User::factory()->create();
        $custom = $this->customDefinition('Credit Verification');

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="'.$custom->code.'"', $content);
        $this->assertStringContainsString('>Credit Verification</option>', $content);
        $this->assertStringContainsString('>Barangay Check</option>', $content);
    }

    public function test_activity_type_dropdown_lists_every_custom_definition_exactly_once(): void
    {
        $ci = User::factory()->create();
        $credit = $this->customDefinition('Credit Verification');
        $employment = $this->customDefinition('Employment Verification');
        $folder = $this->folderFor($ci, ['display_name' => 'Duplicate Guard Client']);
        // Several activities of the same type must never multiply that type's dropdown option.
        foreach ([$credit, $credit, $employment] as $definition) {
            $this->customActivity($folder, $ci, $definition);
        }

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, '>Credit Verification</option>'));
        $this->assertSame(1, substr_count($content, '>Employment Verification</option>'));
        $this->assertSame(1, substr_count($content, '<option value="'.$credit->code.'"'));
        $this->assertSame(1, substr_count($content, '<option value="'.$employment->code.'"'));
    }

    public function test_custom_activity_row_displays_the_actual_definition_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Custom Name Row Client']);
        $this->customActivity($folder, $ci, $this->customDefinition('Credit Verification'));

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $this->assertStringContainsString('CUSTOM NAME ROW CLIENT', $content);
        $this->assertStringContainsString('>Credit Verification</span>', $content);
        $this->assertStringNotContainsString('>Custom Activity</span>', $content);
        $this->assertStringNotContainsString('>New Activity</span>', $content);
    }

    public function test_activity_type_filter_applies_to_the_exact_custom_definition(): void
    {
        $ci = User::factory()->create();
        $creditFolder = $this->folderFor($ci, ['display_name' => 'Credit Verification Client']);
        $employmentFolder = $this->folderFor($ci, ['display_name' => 'Employment Verification Client']);
        $credit = $this->customDefinition('Credit Verification');
        $employment = $this->customDefinition('Employment Verification');
        $this->customActivity($creditFolder, $ci, $credit);
        $this->customActivity($employmentFolder, $ci, $employment);

        $content = $this->actingAs($ci)
            ->get(route('ci-activities.index', ['activity_type' => $credit->code]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('CREDIT VERIFICATION CLIENT', $content);
        $this->assertStringNotContainsString('EMPLOYMENT VERIFICATION CLIENT', $content);
    }

    public function test_activity_type_filter_still_applies_to_every_canonical_type(): void
    {
        $ci = User::factory()->create();
        $codes = [
            ActivityDefinition::BARANGAY_CHECK_CODE => 'Canonical Barangay Client',
            ActivityDefinition::NEIGHBOR_CHECK_CODE => 'Canonical Neighbor Client',
            ActivityDefinition::ASSET_CHECK_CODE => 'Canonical Asset Client',
            ActivityDefinition::BANK_COOP_CHECK_CODE => 'Canonical Bank Client',
        ];
        foreach ($codes as $code => $displayName) {
            $this->simpleActivity($this->folderFor($ci, ['display_name' => $displayName]), $ci, $code);
        }

        foreach ($codes as $code => $displayName) {
            $content = $this->actingAs($ci)->get(route('ci-activities.index', ['activity_type' => $code]))->assertOk()->getContent();
            $this->assertStringContainsString(strtoupper($displayName), $content);
            foreach ($codes as $otherCode => $otherName) {
                if ($otherCode === $code) {
                    continue;
                }
                $this->assertStringNotContainsString(strtoupper($otherName), $content);
            }
        }
    }

    public function test_activity_type_dropdown_excludes_removed_custom_definitions(): void
    {
        $ci = User::factory()->create();
        $removed = ActivityDefinition::create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'retired_type',
            'name' => 'Retired Ad Hoc Type',
            'sort_order' => 400,
            'is_required' => false,
            'is_active' => false,
        ]);
        $folder = $this->folderFor($ci, ['display_name' => 'Retired Type Row Client']);
        $this->customActivity($folder, $ci, $removed);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        // A removed Activity Type stops being selectable, but its historical rows stay visible.
        $this->assertStringNotContainsString('>Retired Ad Hoc Type</option>', $content);
        $this->assertStringContainsString('RETIRED TYPE ROW CLIENT', $content);
    }

    public function test_obsolete_activity_type_query_string_does_not_crash_and_is_ignored(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Untouched By Obsolete Filter']);
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['activity_type' => 'test']))->assertOk()->getContent();

        // An obsolete/unknown activity_type value falls back exactly like any other invalid filter
        // on this page — silently ignored (no crash), not applied as an active constraint.
        $this->assertStringContainsString('UNTOUCHED BY OBSOLETE FILTER', $content);
    }

    public function test_custom_activity_definitions_scope_row_visibility_and_are_filterable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'Custom Type Row Client']);
        $custom = ActivityDefinition::create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'legit_custom',
            'name' => 'Employer Verification',
            'sort_order' => 300,
            'is_required' => false,
            'is_active' => true,
        ]);
        CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $custom->id,
            'name' => $custom->name,
            'creator_id' => $ci->id,
            'status' => ActivityStatus::Pending,
        ]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        // Custom-type activities are listed in the worklist and their definition is selectable
        // in the Activity Type filter, from the same authoritative ActivityDefinition rows.
        $this->assertStringContainsString('CUSTOM TYPE ROW CLIENT', $content);
        $this->assertStringContainsString('>Employer Verification</option>', $content);
    }

    public function test_schedule_filter_today(): void
    {
        $ci = User::factory()->create();
        $todayFolder = $this->folderFor($ci, ['display_name' => 'Today Row Client']);
        $laterFolder = $this->folderFor($ci, ['display_name' => 'Later Row Client']);
        $this->simpleActivity($todayFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 15:00:00', config('cims.display_timezone'))->utc(),
        ]);
        $this->simpleActivity($laterFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-10 15:00:00', config('cims.display_timezone'))->utc(),
        ]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['schedule' => 'today']))->assertOk()->getContent();

        $this->assertStringContainsString('TODAY ROW CLIENT', $content);
        $this->assertStringNotContainsString('LATER ROW CLIENT', $content);
    }

    // ==================================================
    // Sorting
    // ==================================================

    public function test_sort_by_earliest_schedule(): void
    {
        $ci = User::factory()->create();
        $laterFolder = $this->folderFor($ci, ['display_name' => 'Later Scheduled Client']);
        $earlierFolder = $this->folderFor($ci, ['display_name' => 'Earlier Scheduled Client']);
        $this->simpleActivity($laterFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-05 08:00:00', config('cims.display_timezone'))->utc(),
        ]);
        $this->simpleActivity($earlierFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-02 08:00:00', config('cims.display_timezone'))->utc(),
        ]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index', ['sort' => 'earliest_schedule', 'per_page' => 50]))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($content, 'LATER SCHEDULED CLIENT'),
            strpos($content, 'EARLIER SCHEDULED CLIENT'),
        );
    }

    // ==================================================
    // Open navigation
    // ==================================================

    public function test_open_action_routes_to_the_exact_client_folder_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        // Open lands on the CI Activities table itself, not the Barangay/Neighbor tracker.
        $this->assertStringContainsString(route('client-folders.activities.index', [$folder]).'#activity-'.$activity->id, $content);
        $this->assertStringNotContainsString(route('client-folders.activities.default-check.show', [$folder, $activity]), $content);
    }

    public function test_open_actions_render_existing_icons_with_text_on_desktop_and_mobile(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $response = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()
            ->assertSee('data-ci-action-icon', false)
            ->assertSee('Open')
            ->assertSee('Open Activity');
        $content = $response->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($content, 'data-ci-action-icon'));
        $this->assertSame(2, substr_count($content, 'href="'.route('client-folders.activities.index', [$folder]).'#activity-'.$activity->id.'"'));
    }

    public function test_target_progress_and_same_institution_inquiry_types_remain_distinct(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);
        $asset = $this->assetActivity($folder, $ci);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'BPI',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'status' => ActivityStatus::Completed,
        ]);
        $this->bankTarget($bank, $ci, [
            'institution_name' => 'BPI',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            'status' => ActivityStatus::Pending,
        ]);
        foreach ([ActivityStatus::Completed, ActivityStatus::Completed, ActivityStatus::Pending] as $index => $status) {
            $this->assetTarget($asset, $ci, [
                'office_location' => 'Assessor Office '.($index + 1),
                'status' => $status,
            ]);
        }

        $rows = collect($this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->viewData('rows')->items())
            ->keyBy(fn ($row) => $row->activity->id);

        $this->assertSame(1, $rows[$bank->id]->progressNumerator);
        $this->assertSame(2, $rows[$bank->id]->progressDenominator);
        $this->assertSame(2, $rows[$asset->id]->progressNumerator);
        $this->assertSame(3, $rows[$asset->id]->progressDenominator);
        $this->assertSame(
            [CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY],
            $bank->bankTargets()->orderBy('id')->pluck('inquiry_type')->all(),
        );
    }

    public function test_open_action_for_bank_check_routes_to_the_ci_activities_table_not_the_bank_tracker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->bankActivity($folder, $ci);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        // Open must land on the CI Activities table first — it must never auto-launch the
        // Bank/Coop tracker modal on its own.
        $this->assertStringContainsString(route('client-folders.activities.index', [$folder]).'#activity-'.$bank->id, $content);
        $this->assertStringNotContainsString(route('client-folders.activities.bank-coop.show', [$folder, $bank]), $content);
    }

    public function test_open_action_preserves_exact_co_maker_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $this->coMakerFor($folder, 'Juan Dela Cruz');
        $activity = $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $content = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        $expectedUrl = route('client-folders.activities.index', [$folder], false).'?person=co-maker&amp;co_maker_id='.$coMaker->id.'#activity-'.$activity->id;
        $this->assertStringContainsString($expectedUrl, $content);
    }

    // ==================================================
    // Pagination
    // ==================================================

    public function test_pagination_shows_correct_slice_and_totals(): void
    {
        $ci = User::factory()->create();
        foreach (range(1, 7) as $index) {
            $this->simpleActivity($this->folderFor($ci), $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        }

        $page = $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 5]));
        $page->assertOk()->assertSee('Showing 1 to 5 of', false);

        $pageTwo = $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 5, 'page' => 2]));
        $pageTwo->assertOk();
    }

    // ==================================================
    // Notification bell destination
    // ==================================================

    public function test_notification_view_ci_activities_link_points_to_the_real_worklist(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now(),
        ]);

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString(route('ci-activities.index'), $content);
    }

    // ==================================================
    // Performance: no obvious N+1 regression
    // ==================================================

    public function test_no_obvious_n_plus_one_query_regression(): void
    {
        $ci = User::factory()->create();
        foreach (range(1, 6) as $index) {
            $folder = $this->folderFor($ci);
            $bank = $this->bankActivity($folder, $ci);
            $this->bankTarget($bank, $ci, ['institution_name' => 'Bank '.$index, 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);
            $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 50]))->assertOk();

        $this->assertLessThan(30, $queryCount, 'Global CI Activities page issued '.$queryCount.' queries, suggesting an N+1 regression.');
    }

    // ==================================================
    // Helpers
    // ==================================================

    // ==================================================
    // Pagination
    // ==================================================

    public function test_pagination_preserves_every_filter_and_the_active_sort(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'ALPHA, CLIENT']);
        foreach (range(1, 12) as $index) {
            $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['name' => 'Barangay Check '.$index]);
        }

        $query = [
            'tab' => 'all', 'search' => 'ALPHA', 'person' => 'applicant',
            'schedule' => 'all', 'sort' => 'client_name', 'per_page' => 5,
        ];
        $response = $this->actingAs($ci)->get(route('ci-activities.index', $query))->assertOk();
        $html = str_replace('&amp;', '&', $response->getContent());

        foreach (['search=ALPHA', 'person=applicant', 'sort=client_name', 'per_page=5', 'page=2'] as $carried) {
            $this->assertStringContainsString($carried, $html, $carried.' survives pagination.');
        }

        // The results region and pagination hook the async handler looks for, with real hrefs.
        $this->assertStringContainsString('data-ci-activities-listing', $html);
        $this->assertStringContainsString('data-ci-activities-pagination', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function test_changing_the_sort_returns_to_the_first_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'ALPHA, CLIENT']);
        foreach (range(1, 12) as $index) {
            $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['name' => 'Barangay Check '.$index]);
        }

        // The sort/per-page form deliberately omits `page`, so switching either starts over at 1.
        $html = $this->actingAs($ci)
            ->get(route('ci-activities.index', ['sort' => 'client_name', 'per_page' => 5, 'page' => 3]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('name="page"', $html, 'The sort form must not carry the current page.');
    }

    public function test_page_two_returns_the_correct_server_side_activity_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'ALPHA, CLIENT']);
        foreach (range(1, 12) as $index) {
            $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['name' => 'Barangay Check '.$index]);
        }

        $pageOne = $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 5, 'page' => 1]))->assertOk()->viewData('rows');
        $pageTwo = $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 5, 'page' => 2]))->assertOk()->viewData('rows');

        $this->assertCount(5, $pageOne->items());
        $this->assertCount(5, $pageTwo->items());
        $this->assertSame(12, $pageOne->total());
        $this->assertSame(2, $pageTwo->currentPage());

        $idsOne = collect($pageOne->items())->map(fn ($row) => $row->activity->id)->all();
        $idsTwo = collect($pageTwo->items())->map(fn ($row) => $row->activity->id)->all();
        $this->assertEmpty(array_intersect($idsOne, $idsTwo), 'The two pages never overlap.');
    }

    public function test_a_pagination_click_can_fetch_the_worklist_alone_without_mutating_anything(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, ['display_name' => 'ALPHA, CLIENT']);
        foreach (range(1, 12) as $index) {
            $this->simpleActivity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['name' => 'Barangay Check '.$index]);
        }
        $before = CiActivity::query()->count();

        $html = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('ci-activities.index', ['per_page' => 5, 'page' => 2]))
            ->assertOk()->getContent();

        // Only the worklist comes back — no layout, no tabs, no filter toolbar.
        $this->assertStringNotContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('CI Activity tabs', $html);
        // The replacement content still carries working pagination for the next click.
        $this->assertStringContainsString('data-ci-activities-pagination', $html);
        $this->assertStringContainsString('Showing 6 to 10 of 12 activities', $html);

        $this->assertSame($before, CiActivity::query()->count(), 'Paginating mutates nothing.');
    }

    private function folderFor(User $ci, array $overrides = []): ClientFolder
    {
        return ClientFolder::factory()->create(array_merge(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id], $overrides));
    }

    private function coMakerFor(ClientFolder $folder, string $fullName): CoMaker
    {
        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $fullName, 'first_name' => explode(' ', $fullName)[0], 'last_name' => explode(' ', $fullName)[array_key_last(explode(' ', $fullName))]]);
    }

    private function customDefinition(string $name): ActivityDefinition
    {
        return ActivityDefinition::create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.str()->uuid(),
            'name' => $name,
            'sort_order' => 500,
            'is_required' => false,
            'is_active' => true,
        ]);
    }

    private function customActivity(ClientFolder $folder, User $creator, ActivityDefinition $definition, array $overrides = []): CiActivity
    {
        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'status' => ActivityStatus::Pending,
        ], $overrides));
    }

    private function simpleActivity(ClientFolder $folder, User $creator, string $code, array $overrides = []): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'status' => ActivityStatus::Pending,
        ], $overrides));
    }

    private function bankActivity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'status' => ActivityStatus::Scheduled,
        ]);
    }

    private function assetActivity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::ASSET_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'status' => ActivityStatus::Scheduled,
        ]);
    }

    private function bankTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityBankTarget
    {
        return $activity->bankTargets()->create(array_merge([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    private function assetTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create(array_merge([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Land',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }
}
