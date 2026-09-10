<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecentActivityScopingTest extends TestCase
{
    use RefreshDatabase;

    // --- 1: Applicant CI/BI create/update ---------------------------------------------------

    public function test_applicant_recent_activity_shows_applicant_cibi_create_and_update(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'cibi_report.created', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);
        $this->log($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('CI/BI created')
            ->assertSee('CI/BI updated');
    }

    // --- 2: Applicant Business add/update/remove with name snapshot -------------------------

    public function test_applicant_shows_business_add_update_remove_with_name_snapshot(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'income_source.created', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => null, 'display_name' => 'ABC STORE ADDED']);
        $this->log($ci, $folder, 'general_income_source_report.updated', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => null, 'display_name' => 'ABC STORE']);
        $this->log($ci, $folder, 'income_source.deleted', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => null, 'display_name' => 'ABC STORE']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Business added', $content);
        $this->assertStringContainsString('Business updated', $content);
        $this->assertStringContainsString('Business removed', $content);
        // 6, not 3: each of the 3 events' name detail renders once in the compact panel and once
        // more in the always-rendered (initially hidden) "View more" modal — see the hide/show
        // and modal-content tests for markup that isolates the compact panel specifically.
        $this->assertSame(6, substr_count($content, 'ABC STORE'));
    }

    // --- 3: Applicant Residence & Business Report create/update/remove ----------------------

    public function test_applicant_shows_residence_and_business_report_create_update_remove(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'residence_check.created', 'residence_business_report', ['residence_check_id' => 1, 'co_maker_id' => null]);
        $this->log($ci, $folder, 'business_check.updated', 'residence_business_report', ['business_check_id' => 1, 'co_maker_id' => null]);
        $this->log($ci, $folder, 'residence_check.deleted', 'residence_business_report', ['residence_check_id' => 1, 'co_maker_id' => null, 'location' => 'APPLICANT HOME']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Residence Check added', $content);
        $this->assertStringContainsString('Business Check updated', $content);
        $this->assertStringContainsString('Residence Check removed', $content);
        $this->assertStringContainsString('APPLICANT HOME', $content);
    }

    // --- 4: Applicant CI Activity add/update/remove ------------------------------------------

    public function test_applicant_shows_ci_activity_update_with_title(): void
    {
        // CI Activities are a fixed, auto-seeded checklist — the system has no per-activity
        // "added"/"removed" user action to audit, only status/detail updates. This exercises
        // what is genuinely supported: ci_activity.updated / .completed, with the activity's
        // title preserved in metadata so it stays readable independent of the definition row.
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'ci_activity.updated', 'ci_activities', ['activity_id' => 1, 'co_maker_id' => null, 'activity_definition_id' => 1, 'activity_title' => 'Neighborhood Check', 'status' => 'in_progress']);
        $this->log($ci, $folder, 'ci_activity.completed', 'ci_activities', ['activity_id' => 1, 'co_maker_id' => null, 'activity_definition_id' => 1, 'activity_title' => 'Neighborhood Check', 'status' => 'completed']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('CI Activity updated', $content);
        $this->assertStringContainsString('CI Activity completed', $content);
        // 4, not 2: each event's title renders once in the compact panel and once more in the
        // always-rendered "View more" modal.
        $this->assertSame(4, substr_count($content, 'Neighborhood Check'));
    }

    // --- 5: Applicant Residence/Business photo upload ----------------------------------------

    public function test_applicant_shows_residence_and_business_photo_upload_distinctly(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'media.uploaded', 'media', ['media_reference_id' => 1, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'residence', 'byte_size' => 100]);
        $this->log($ci, $folder, 'media.uploaded', 'media', ['media_reference_id' => 2, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'business', 'byte_size' => 100]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Residence photo uploaded', $content);
        $this->assertStringContainsString('Business photo uploaded', $content);
    }

    // --- 6/7: Co-Maker management actions only, and Co-Maker module activity excluded -------

    public function test_applicant_shows_co_maker_lifecycle_but_not_that_co_makers_module_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'JUAN DELA CRUZ']);
        $this->log($ci, $folder, 'co_maker.added', 'client_folders', ['co_maker_id' => $coMaker->id, 'full_name' => 'JUAN DELA CRUZ']);
        $this->log($ci, $folder, 'co_maker.updated', 'client_folders', ['co_maker_id' => $coMaker->id, 'full_name' => 'JUAN DELA CRUZ']);
        $this->log($ci, $folder, 'co_maker.removed', 'client_folders', ['co_maker_id' => $coMaker->id, 'full_name' => 'JUAN DELA CRUZ']);
        // The excluded Co-Maker-specific module activity:
        $this->log($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 9, 'co_maker_id' => $coMaker->id]);
        $this->log($ci, $folder, 'business_report.updated', 'income_sources', ['income_source_id' => 9, 'co_maker_id' => $coMaker->id, 'display_name' => 'COMAKER BUSINESS']);
        $this->log($ci, $folder, 'residence_check.updated', 'residence_business_report', ['residence_check_id' => 9, 'co_maker_id' => $coMaker->id]);
        $this->log($ci, $folder, 'ci_activity.updated', 'ci_activities', ['activity_id' => 9, 'co_maker_id' => $coMaker->id, 'activity_definition_id' => 1, 'activity_title' => 'Comaker Activity', 'status' => 'in_progress']);
        $this->log($ci, $folder, 'media.uploaded', 'media', ['media_reference_id' => 9, 'co_maker_id' => $coMaker->id, 'media_type' => 'photo', 'category' => 'residence']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Co-Maker added', $content);
        $this->assertStringContainsString('Co-Maker updated', $content);
        $this->assertStringContainsString('Co-Maker removed', $content);
        $this->assertStringContainsString('JUAN DELA CRUZ', $content);
        // Excluded module activity must not leak into Applicant's view:
        $this->assertStringNotContainsString('COMAKER BUSINESS', $content);
        $this->assertStringNotContainsString('Comaker Activity', $content);
    }

    // --- 8: Folder created / renamed ----------------------------------------------------------

    public function test_applicant_shows_folder_created_and_renamed(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'BEFORE']);
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'AFTER']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Folder renamed', $content);
        $this->assertStringContainsString('REY C. MAGHILOM', $content);
    }

    // --- 9/10: Co-Maker tab isolation ----------------------------------------------------------

    public function test_selected_co_maker_shows_only_that_co_makers_module_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET PERSON']);
        $other = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OTHER PERSON']);
        $this->log($ci, $folder, 'business_report.updated', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => $target->id, 'display_name' => 'TARGET BUSINESS']);
        $this->log($ci, $folder, 'business_report.updated', 'income_sources', ['income_source_id' => 2, 'co_maker_id' => $other->id, 'display_name' => 'OTHER BUSINESS']);
        $this->log($ci, $folder, 'business_report.updated', 'income_sources', ['income_source_id' => 3, 'co_maker_id' => null, 'display_name' => 'APPLICANT BUSINESS']);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$target->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('TARGET BUSINESS', $content);
        $this->assertStringNotContainsString('OTHER BUSINESS', $content);
        $this->assertStringNotContainsString('APPLICANT BUSINESS', $content);
    }

    public function test_one_co_maker_never_sees_another_co_makers_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $a = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $b = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $this->log($ci, $folder, 'residence_check.updated', 'residence_business_report', ['residence_check_id' => 1, 'co_maker_id' => $b->id]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$a->id)
            ->assertOk()->getContent();
        $asideStart = strpos($content, 'id="recent-activity-title"');
        $asideEnd = strpos($content, '</aside>', $asideStart);
        $aside = substr($content, $asideStart, $asideEnd - $asideStart);

        $this->assertStringNotContainsString('Residence Check updated', $aside);
    }

    // --- 11: deleted names remain readable -----------------------------------------------------

    public function test_deleted_business_and_co_maker_names_remain_readable_via_metadata(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'income_source.deleted', 'income_sources', ['income_source_id' => 999, 'co_maker_id' => null, 'display_name' => 'GONE STORE']);
        $this->log($ci, $folder, 'co_maker.removed', 'client_folders', ['co_maker_id' => 999, 'full_name' => 'GONE PERSON']);
        $this->assertDatabaseMissing('income_sources', ['id' => 999]);
        $this->assertDatabaseMissing('co_makers', ['id' => 999]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('GONE STORE', $content);
        $this->assertStringContainsString('GONE PERSON', $content);
    }

    // --- 12: actor + timezone correctness -------------------------------------------------------

    public function test_actor_name_and_asia_manila_timestamp_are_correct(): void
    {
        $ci = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $log = $this->log($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);
        // audit_logs.created_at is a DB-level CURRENT_TIMESTAMP default (AuditLog has no PHP-side
        // timestamps), now forced to a UTC session via config/database.php — the raw value is
        // genuine UTC, matching every other timestamp in this app. 15:05 UTC -> 23:05 Manila
        // (+8, no day rollover) proves AuditLog::createdAt() converts it correctly exactly once.
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => '2026-08-23 15:05:00']);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('REASAN MARK Q. GURA')
            ->assertSee('Aug 23, 2026')
            ->assertSee('11:05 PM')
            ->assertDontSee('3:05 PM');
    }

    public function test_folder_created_timestamp_converts_the_raw_stored_utc_value_to_manila_exactly_once(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $log = $this->log($ci, $folder, 'client_folder.created', 'client_folders', ['folder_number' => $folder->folder_number]);
        // 19:21 UTC on Aug 23 -> 03:21 Manila on Aug 24 (+8, rolling into the next calendar day) —
        // deliberately chosen so a leftover "treat raw value as already Manila" bug (no shift) or
        // a double-conversion bug (+16 hours) would both produce a visibly wrong date/time here.
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => '2026-08-23 19:21:00']);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Folder created')
            ->assertSee('REY C. MAGHILOM')
            ->assertSee('Aug 24, 2026')
            ->assertSee('3:21 AM')
            ->assertDontSee('7:21 PM')
            ->assertDontSee('Aug 23, 2026');
    }

    public function test_media_upload_activity_is_attributed_with_uploaded_by_wording(): void
    {
        $ci = User::factory()->create(['full_name' => 'ANTHONY B. YONG']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->log($ci, $folder, 'media.uploaded', 'media', ['media_reference_id' => 1, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'residence']);
        $this->log($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Uploaded by ANTHONY B. YONG', $content);
        // Every other activity type keeps the plain "by NAME" wording — only uploads change.
        $this->assertStringContainsString('>by ANTHONY B. YONG<', $content);
    }

    // --- 13: Hide/Show has been fully removed; Recent Activity is permanently visible -----------

    public function test_recent_activity_hide_show_control_has_been_completely_removed(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-recent-activity-toggle', $content);
        $this->assertStringNotContainsString('Hide Recent Activity', $content);
        $this->assertStringNotContainsString('Show Recent Activity', $content);
        $this->assertStringNotContainsString('data-recent-activity-collapsed', $content);
        $this->assertStringNotContainsString('data-recent-activity-layout', $content);
        $this->assertStringNotContainsString('data-recent-activity-panel', $content);

        // Recent Activity itself remains permanently visible — no collapse state, no wrapper.
        $this->assertStringContainsString('Recent Activity', $content);
        $this->assertStringContainsString('data-recent-activity-body', $content);
        // Responsive: no fixed non-collapsing column reservation without the xl: breakpoint guard.
        $this->assertStringContainsString('xl:grid-cols-[minmax(0,1fr)_minmax(15rem,23%)]', $content);
    }

    public function test_recent_activity_remains_visible_without_co_makers(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        // No Co-Makers on this folder — Recent Activity must still render unconditionally.
        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('<h2 id="recent-activity-title"', $content);
        $this->assertStringNotContainsString('data-recent-activity-toggle', $content);
    }

    public function test_zero_co_makers_shows_no_empty_co_maker_box_or_tab(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-co-maker-tab', $content);
        $this->assertStringNotContainsString('data-co-maker-menu-trigger', $content);
        $this->assertStringNotContainsString('Switch Person', $content);
        $this->assertStringNotContainsString('Current View:', $content);
        // The existing Add Co-Maker action must remain available regardless.
        $this->assertStringContainsString('Add Co-Maker', $content);
    }

    public function test_first_added_co_maker_makes_the_co_maker_tab_appear(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $before = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-co-maker-tab', $before);

        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'FIRST CO MAKER']);

        $after = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-co-maker-tab', $after);
        $this->assertStringContainsString('Switch Person', $after);
    }

    public function test_applicant_and_co_maker_tabs_stay_on_one_line_with_multiple_co_makers(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER ONE']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER TWO']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER THREE']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $tabsStart = strpos($content, 'Switch active person');
        $tabsWrapperStart = strpos($content, '<div class="flex min-w-0 flex-nowrap items-center gap-2 overflow-x-auto">', $tabsStart);
        $this->assertNotFalse($tabsWrapperStart, 'Tabs container must use flex-nowrap + overflow-x-auto, never flex-wrap, so tabs scroll horizontally instead of wrapping to a new line.');

        $tabsWrapperEnd = strpos($content, 'Switch Person', $tabsWrapperStart);
        $tabsMarkup = substr($content, $tabsWrapperStart, $tabsWrapperEnd - $tabsWrapperStart);

        // Applicant + all 3 Co-Maker tabs (and their per-tab wrapper divs) must each carry
        // shrink-0 so the flex container is forced to overflow (and scroll) rather than squeeze
        // any tab down to fit — the actual mechanism that keeps them on one row.
        $this->assertStringContainsString('class="flex shrink-0 cursor-pointer items-center gap-2.5 rounded-control', $tabsMarkup);
        $this->assertSame(3, substr_count($tabsMarkup, 'class="flex shrink-0 items-center rounded-control transition'));
        $this->assertStringNotContainsString('flex-wrap items-center gap-2 overflow-x-auto', $tabsMarkup);
    }

    // --- 14: Recent Activity data/View All unaffected by the Hide/Show removal -------------------

    public function test_recent_activity_still_caps_at_five_compact_items_with_view_all_available(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        foreach (range(1, 8) as $index) {
            $this->log($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => $index, 'co_maker_id' => null]);
        }

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('id="recent-activity-dialog"', $content);
        $this->assertStringContainsString('View All', $content);

        $bodyStart = strpos($content, 'data-recent-activity-body');
        $bodyEnd = strpos($content, '</aside>', $bodyStart);
        $compactBody = substr($content, $bodyStart, $bodyEnd - $bodyStart);
        $this->assertSame(5, substr_count($compactBody, 'CI/BI updated'));
    }

    // --- 15/16: CI/BI update UI cleanup ---------------------------------------------------------

    public function test_applicant_cibi_update_does_not_render_created_by_or_view_history(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))
            ->assertOk()
            ->assertDontSee('Created by')
            ->assertDontSee('View History');
    }

    public function test_co_maker_cibi_update_does_not_render_created_by_or_view_history(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER X']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()
            ->assertDontSee('Created by')
            ->assertDontSee('View History');
    }

    // --- 17: creator/signatory/audit data unchanged internally ------------------------------------

    public function test_creator_signatory_and_audit_data_remain_unchanged_internally(): void
    {
        $ci = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id, 'state' => RecordState::Draft]);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();

        $report->refresh();
        $this->assertSame($ci->id, $report->created_by);
        $this->assertSame($ci->id, $report->ci_in_charge_id);
        $this->assertDatabaseCount('audit_logs', 0);

        $newSignatory = User::factory()->create();
        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Administrative reassignment for testing.',
        ])->assertRedirect();

        $report->refresh();
        $this->assertSame($newSignatory->id, $report->ci_in_charge_id);
        $this->assertSame($ci->id, $report->created_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cibi_report.signatory_reassigned', 'client_folder_id' => $folder->id]);
    }

    // --- 18: existing CI/BI behaviors are unchanged (spot check; full suites run separately) -----

    public function test_cibi_save_and_preview_routes_remain_functional_after_ui_cleanup(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))
            ->assertOk()
            ->assertSee('Save');
    }

    private function log(User $ci, ClientFolder $folder, string $action, string $module, array $metadata): AuditLog
    {
        return AuditLog::create([
            'user_id' => $ci->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => $module,
            'description' => 'Test activity event.',
            'metadata' => $metadata,
        ]);
    }
}
