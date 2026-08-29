<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientCompletionResult;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\CoMaker;
use App\Models\CompletionRule;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClientFolderContentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_and_any_ci_can_open_any_active_folder(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $own = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $other = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id]);

        $this->actingAs($administrator)->get(route('client-folders.show', $own))->assertOk();
        $this->actingAs($administrator)->get(route('client-folders.show', $other))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.show', $own))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.show', $other))->assertOk();
    }

    public function test_soft_deleted_folder_is_not_available_through_contents_or_module_routes(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->delete();

        $this->actingAs($administrator)->get(route('client-folders.show', $folder->id))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.show', $folder->id))->assertNotFound();
        $this->actingAs($administrator)->get(route('client-folders.client-information.edit', $folder->id))->assertNotFound();
    }

    public function test_all_module_cards_link_to_authorized_folder_scoped_destinations(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $modules = [
            'google-drive' => 'Google Drive',
            'telegram-history' => 'Telegram History',
            'attachments' => 'Attachments / Documents',
        ];

        $contents = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk();
        $contents->assertDontSee(route('client-folders.client-information.edit', $folder), false);
        $contents->assertSee('CI Activities')->assertSee(route('client-folders.activities.index', $folder), false);
        $contents->assertSee('CI / BI Report')->assertSee(route('client-folders.cibi-report.edit', $folder), false)->assertSee('data-modal-open="cibi-report-dialog"', false);
        $contents->assertSee('Business / Income Sources')
            ->assertSee(route('client-folders.income-sources.manage', $folder), false)
            ->assertSee('id="open-business-report"', false)
            ->assertDontSee('data-modal-open="business-report-dialog"', false)
            ->assertDontSee('data-business-report-frame', false);
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->assertSee('Add Business')->assertDontSee('Add Another Business')->assertDontSee('Add New Business / Income Source');
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->assertSee('Please choose Business Template')->assertDontSee('Add Income Source')->assertDontSee('Business / Income Sources')->assertDontSee('income sources available.');
        $contents->assertSee('Residence & Business Report')->assertSee(route('client-folders.residence-business.edit', $folder), false);
        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->assertSee('Residence & Business Report');
        $contents->assertSee('Generated Reports')->assertSee(route('client-folders.generated-reports.index', $folder), false);
        $this->actingAs($ci)->get(route('client-folders.generated-reports.index', $folder))->assertOk()->assertSee('Protected official artifacts');
        $contents->assertSee('Photos &amp; Videos', false)->assertSee(route('client-folders.media.index', $folder), false);
        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk()->assertSee('Protected field evidence');

        foreach ($modules as $key => $title) {
            $contents->assertSee($title)->assertSee(route('client-folders.modules.show', [$folder, $key]), false);
            $this->actingAs($ci)->get(route('client-folders.modules.show', [$folder, $key]))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('No later-phase business workflow has been implemented.');
        }
    }

    public function test_other_ci_can_open_folder_module_placeholder_for_a_folder_assigned_to_another_ci(): void
    {
        $assignedCi = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);

        $this->actingAs($otherCi)
            ->get(route('client-folders.client-information.edit', $folder))
            ->assertOk();
    }

    public function test_header_progress_matches_shared_cached_folder_progress_and_results_explain_it(): void
    {
        $ci = User::factory()->create(['full_name' => 'Assigned CI Name']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'progress_percent' => 50]);
        $completeRule = $this->rule('complete-rule', 'Completed Requirement', 1);
        $missingRule = $this->rule('missing-rule', 'Missing Required Report', 2);
        $this->completionResult($folder, $completeRule, true);
        $this->completionResult($folder, $missingRule, false);

        $contents = $this->actingAs($ci)->get(route('client-folders.show', $folder));

        $contents->assertOk()
            ->assertSee('aria-valuenow="50"', false)
            ->assertSee('1 of 2 applicable required items completed.')
            ->assertSee('Missing Required Report')
            ->assertSee('Assigned CI Name');
        $this->actingAs($ci)->get(route('home'))->assertSee('aria-valuenow="50"', false);
        $this->actingAs($ci)->get(route('client-folders.index'))->assertSee('aria-valuenow="50"', false);
    }

    public function test_only_active_required_evaluated_results_appear_as_missing_items(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $activeRequired = $this->rule('required', 'Visible Required Item', 1);
        $optional = $this->rule('optional', 'Hidden Optional Item', 2, false);
        $inactive = $this->rule('inactive', 'Hidden Inactive Item', 3, true, false);
        $this->completionResult($folder, $activeRequired, false);
        $this->completionResult($folder, $optional, false);
        $this->completionResult($folder, $inactive, false);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertSee('Visible Required Item')
            ->assertDontSee('Hidden Optional Item')
            ->assertDontSee('Hidden Inactive Item');
    }

    public function test_folder_without_evaluated_completion_results_has_neutral_pending_state(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('No applicable completion results have been evaluated for this folder yet.')
            ->assertDontSee('No missing applicable items.');
    }

    public function test_module_summaries_use_real_counts_and_record_states(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        ClientInformation::factory()->create(['client_folder_id' => $folder->id, 'completion_state' => RecordState::Complete]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);
        $template = IncomeSourceTemplate::factory()->create();
        IncomeSource::factory()->count(2)->create(['client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'state' => RecordState::Draft]);

        $response = $this->actingAs($ci)->get(route('client-folders.show', $folder));
        $modules = collect($response->viewData('modules'))->keyBy('key');

        $response->assertOk()->assertDontSee('2 income sources available.');
        $response->assertSee('Business / Income Sources')->assertSee(route('client-folders.income-sources.manage', $folder), false);
        $this->assertSame('complete', $modules['client-information']['state']);
        $this->assertSame('draft', $modules['cibi-report']['state']);
        $this->assertSame('in_progress', $modules['income-sources']['state']);
        $this->assertNull($modules['income-sources']['description']);
        $this->assertSame('not_started', $modules['media']['state']);
    }

    public function test_income_source_count_summary_is_never_rendered_for_any_business_count(): void
    {
        $ci = User::factory()->create();
        $template = IncomeSourceTemplate::factory()->create();

        foreach ([0, 1, 3] as $count) {
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
            IncomeSource::factory()->count($count)->create([
                'client_folder_id' => $folder->id,
                'income_source_template_id' => $template->id,
            ]);

            $this->actingAs($ci)->get(route('client-folders.show', $folder))
                ->assertOk()
                ->assertSee('Business / Income Sources')
                ->assertSee(route('client-folders.income-sources.manage', $folder), false)
                ->assertDontSee($count.' income source'.($count === 1 ? '' : 's').' available.')
                ->assertDontSee('No income sources available.');
        }
    }

    public function test_recent_history_uses_safe_audit_fields_without_metadata_payload(): void
    {
        $ci = User::factory()->create(['full_name' => 'History Actor']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        AuditLog::create([
            'user_id' => $ci->id,
            'client_folder_id' => $folder->id,
            'action' => 'client_folder.renamed',
            'module' => 'client_folders',
            'description' => 'A client folder was renamed.',
            'metadata' => ['sensitive_internal_value' => 'DO NOT DISPLAY THIS VALUE'],
        ]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertSee('A client folder was renamed.')
            ->assertSee('History Actor')
            ->assertDontSee('DO NOT DISPLAY THIS VALUE')
            ->assertDontSee('sensitive_internal_value');
    }

    public function test_folder_contents_use_responsive_semantic_module_navigation(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('aria-label="Client folder modules"', false)
            ->assertSee('md:grid-cols-2', false)
            ->assertSee('xl:grid-cols-[minmax(0,3fr)_minmax(17rem,1fr)]', false)
            ->assertSee('Folder Summary')
            ->assertSee('Recent Activity')
            ->assertSee('Overall client folder completion')
            ->assertSee('Assigned Credit Investigator')
            ->assertDontSee('Digital case folder')
            ->assertDontSee('Required-item checklist')
            ->assertDontSee('Open module')
            ->assertDontSee('Safe folder history')
            ->assertDontSee('Move to Recycle Bin')
            ->assertDontSee('Rename Folder')
            ->assertDontSee(route('client-folders.edit-name', $folder), false)
            ->assertDontSee('recycle-detail-dialog', false);
    }

    public function test_client_information_module_is_hidden_and_cibi_links_to_authorized_full_page(): void
    {
        $ci = User::factory()->create(['full_name' => 'Assigned Modal Investigator']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MODAL CLIENT']);
        ClientInformation::factory()->create(['client_folder_id' => $folder->id, 'spouse_name' => 'PREFILLED SPOUSE']);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'branch_name' => 'PREFILLED BRANCH',
            'account_officer_name' => 'PREFILLED OFFICER',
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk();

        $response->assertDontSee(route('client-folders.client-information.edit', $folder), false)
            ->assertSee('id="open-cibi-report"', false)
            ->assertSee('href="'.route('client-folders.cibi-report.edit', $folder).'"', false)
            ->assertSee('data-modal-open="cibi-report-dialog"', false)
            ->assertSee('data-cibi-report-url="'.route('client-folders.cibi-report.edit', $folder).'"', false)
            ->assertSee('data-cibi-report-frame', false)
            ->assertSee('id="open-business-report"', false)
            ->assertSee('href="'.route('client-folders.income-sources.manage', $folder).'"', false)
            ->assertDontSee('data-modal-open="business-report-dialog"', false)
            ->assertDontSee('data-business-report-frame', false)
            ->assertDontSee('data-cibi-modal', false)
            ->assertDontSee('cibi-modal-dialog', false)
            ->assertDontSee('PREFILLED BRANCH')
            ->assertDontSee('PREFILLED OFFICER')
            ->assertDontSee('PREFILLED SPOUSE');

        $response = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()
            ->assertSee('PREFILLED BRANCH')
            ->assertSee('PREFILLED OFFICER')
            ->assertSee('PREFILLED SPOUSE')
            ->assertSeeInOrder([
                'Validated Personal Information',
                'Validated Purpose for Loan Application',
                'Bank / Financial Institution',
                'Summary on Credit / Loan Information',
                'Income Sources Validation',
                'Prepared By',
                'Noted By',
            ]);
    }

    public function test_other_ci_can_load_and_save_cibi_page_data_for_another_ci_folder(): void
    {
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($other)->get(route('client-folders.show', $folder))->assertOk();
        $this->actingAs($other)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        // Empty payload is still authorized (not 403) — it fails validation instead.
        $this->actingAs($other)->putJson(route('client-folders.cibi-report.update', $folder), [])->assertStatus(422);
    }

    public function test_cibi_full_page_has_official_paper_sticky_actions_and_unsaved_change_protection(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()
            ->assertSee('data-cibi-standalone-layout', false)
            ->assertSee('data-cibi-form', false)
            ->assertSee('cibi-encoding-paper', false)
            ->assertSee('data-cibi-scroll-region', false)
            ->assertDontSee('id="primary-sidebar"', false)
            ->assertDontSee('data-drawer-toggle', false)
            ->assertDontSee('Open account menu')
            ->assertDontSee('aria-label="Breadcrumb"', false)
            ->assertDontSee('Back to Client Folder')
            ->assertDontSee('CI / BI Encoding')
            // Print Preview / Download PDF / Download Excel belong on the Folder Contents CI/BI
            // card after completion, never inside the encoding form itself.
            ->assertDontSee('Print Preview')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Download Excel')
            ->assertDontSee('>Preview<', false)
            ->assertSee('Save')
            ->assertDontSee('Mark Complete')
            ->assertSee('data-repeater-remove-dialog', false)
            ->assertDontSee('data-cibi-modal', false);

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("dialog.querySelector('[data-cibi-report-frame]')", $javascript);
        $this->assertStringContainsString('event.preventDefault()', $javascript);
        $this->assertStringContainsString('cibiFrame.src = requestedUrl', $javascript);
        $this->assertStringContainsString("dialog.querySelector('[data-business-report-frame]')", $javascript);
        $this->assertStringContainsString('modalTrigger.dataset.businessReportUrl', $javascript);
        $this->assertStringContainsString("document.querySelectorAll('[data-cibi-form]')", $javascript);
        $this->assertStringContainsString('fetch(form.action', $javascript);
        $this->assertStringContainsString('data-cibi-field-error', $javascript);
        $this->assertStringContainsString("payload.report.state === 'complete'", $javascript);
        $this->assertStringContainsString('outputActions.hidden = false', $javascript);
        $this->assertStringContainsString("payload.report.submit_label || 'Update'", $javascript);
        $this->assertStringContainsString("event.data?.type !== 'brbi:cibi-saved'", $javascript);
        $this->assertStringContainsString('window.parent.postMessage({', $javascript);
        $this->assertStringContainsString('window.location.assign(returnUrl.href)', $javascript);
        $this->assertStringContainsString("status.replaceChildren('Completed')", $javascript);
        $this->assertStringContainsString('dialog.dataset.cibiSavedReturnUrl = returnUrl.href', $javascript);
        $this->assertStringContainsString('duration = 2500', $javascript);
        $this->assertStringContainsString('window.setTimeout(() => toast.remove(), duration)', $javascript);
        $this->assertStringContainsString("showToast(payload.message, 'info', 3500)", $javascript);
        $this->assertStringContainsString('showToast(payload.message);', $javascript);
        $this->assertStringNotContainsString('if (dialog.open && dialog.dataset.cibiSavedReturnUrl === returnUrl.href) dialog.close()', $javascript);
        $this->assertStringNotContainsString('window.setTimeout(() => window.location.assign(payload.return_url), 2500)', $javascript);
        $this->assertStringContainsString('window.location.reload()', $javascript);
        $this->assertStringContainsString("dialog.addEventListener('close'", $javascript);
        $this->assertStringContainsString('refreshSavedCibiFolder(returnUrl, folder)', $javascript);
        $this->assertStringNotContainsString("stateLabel?.replaceChildren('Completed')", $javascript);
        $this->assertStringContainsString('payload.report.child_ids || {}', $javascript);
        $this->assertStringContainsString('idField.value = String(id)', $javascript);

        $modal = file_get_contents(resource_path('views/components/ui/cibi-report-modal.blade.php'));
        $this->assertStringContainsString('w-[95vw]', $modal);
        $this->assertStringContainsString('h-[94dvh]', $modal);
        $this->assertStringContainsString('data-cibi-report-frame', $modal);

        $businessModal = file_get_contents(resource_path('views/components/ui/business-report-modal.blade.php'));
        $this->assertStringContainsString('w-[95vw]', $businessModal);
        $this->assertStringContainsString('h-[94dvh]', $businessModal);
        $this->assertStringContainsString('data-business-report-frame', $businessModal);
        $this->assertStringContainsString('data-modal-close', $businessModal);
    }

    public function test_client_header_does_not_duplicate_folder_summary_metadata(): void
    {
        $ci = User::factory()->create(['full_name' => 'Summary Investigator']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'progress_percent' => 40]);

        $response = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'Assigned Credit Investigator'));
        $this->assertSame(1, substr_count($response->getContent(), 'On Progress'));
        $response->assertDontSee('Completion Progress')
            ->assertSee('Overall Progress')
            ->assertSee('aria-valuenow="40"', false);
    }

    public function test_folder_modules_use_one_restrained_icon_treatment(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/module-card.blade.php'));

        $this->assertStringContainsString('bg-brand-soft text-brand-primary', $component);
        $this->assertStringNotContainsString('match($icon)', $component);
        $this->assertStringNotContainsString('bg-violet', $component);
        $this->assertStringNotContainsString('bg-orange', $component);
        $this->assertStringNotContainsString('bg-folder-soft text-progress', $component);
    }

    public function test_overview_query_count_is_constant_with_many_child_records(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::factory()->create();
        IncomeSource::factory()->count(15)->create(['client_folder_id' => $folder->id, 'income_source_template_id' => $template->id]);
        foreach (range(1, 8) as $index) {
            AuditLog::create(['user_id' => $ci->id, 'client_folder_id' => $folder->id, 'action' => "history.{$index}", 'module' => 'testing', 'description' => "History event {$index}."]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ClientFolderOverview::class)->for($folder);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 10: 8 base queries, +1 for the Co-Maker switcher's constant-cost coMakers eager load,
        // +1 for the Recent Activity panel's whitelisted-action AuditLog fetch (its user eager
        // load only fires when a whitelisted row actually matches — not exercised by the
        // non-whitelisted "history.N" actions used here). Still flat regardless of child-record
        // volume; see test_recent_activity_query_count_is_flat_regardless_of_activity_volume for
        // the worst case where the eager load does fire.
        $this->assertLessThanOrEqual(10, $queryCount);
    }

    public function test_rename_records_actor_old_name_and_new_name(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'REYES, JUAN']);

        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'REYES, JUAN JR.'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'client_folder.renamed',
            'module' => 'client_folders',
            'user_id' => $ci->id,
        ]);
        $event = AuditLog::where('client_folder_id', $folder->id)->where('action', 'client_folder.renamed')->sole();
        $this->assertSame('REYES, JUAN', $event->metadata['previous_name']);
        $this->assertSame('REYES, JUAN JR.', $event->metadata['new_name']);
    }

    public function test_multiple_sequential_renames_preserve_every_rename_event(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'NAME A']);

        foreach (['NAME B', 'NAME C', 'NAME D'] as $newName) {
            $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => $newName]);
        }

        $renames = AuditLog::where('client_folder_id', $folder->id)->where('action', 'client_folder.renamed')->orderBy('id')->get();
        $this->assertCount(3, $renames);
        $this->assertSame(['NAME A', 'NAME B', 'NAME C'], $renames->pluck('metadata.previous_name')->all());
        $this->assertSame(['NAME B', 'NAME C', 'NAME D'], $renames->pluck('metadata.new_name')->all());
    }

    public function test_client_folder_contents_no_longer_shows_a_folder_activity_or_history_card(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'RENAMED']);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertDontSee('Folder Activity')
            ->assertDontSee('View History')
            ->assertDontSee('Folder History')
            ->assertDontSee('Last Renamed');
    }

    public function test_applicant_view_shows_applicant_scoped_recent_activity(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->activityLog($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Recent Activity')
            ->assertSee('CI/BI updated')
            ->assertSee('REY C. MAGHILOM');
    }

    public function test_selected_co_maker_view_shows_only_that_co_makers_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER TARGET']);
        $this->activityLog($ci, $folder, 'residence_check.updated', 'residence_business_report', ['residence_check_id' => 1, 'co_maker_id' => $coMaker->id]);
        $this->activityLog($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);

        $response = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk();

        $response->assertSee('Residence Check saved')->assertDontSee('CI/BI updated');
    }

    public function test_another_co_makers_activity_never_leaks_into_the_selected_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $selected = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER SELECTED']);
        $other = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER OTHER']);
        $this->activityLog($ci, $folder, 'business_report.updated', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => $other->id]);

        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$selected->id)
            ->assertOk()
            ->assertDontSee('Business updated');
    }

    public function test_folder_level_lifecycle_activity_appears_for_either_person_context(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'BEFORE RENAME']);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER X']);
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'AFTER RENAME']);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee('Folder renamed');
        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()
            ->assertSee('Folder renamed');
    }

    public function test_recent_activity_is_newest_first(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $older = $this->activityLog($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);
        $older->update(['created_at' => now()->subHour()]);
        $newer = $this->activityLog($ci, $folder, 'income_source.created', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => null]);
        $newer->update(['created_at' => now()]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSeeInOrder(['Business added', 'CI/BI updated']);
    }

    public function test_recent_activity_panel_stays_compact_at_a_maximum_of_five_items(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        foreach (range(1, 8) as $index) {
            $this->activityLog($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => $index, 'co_maker_id' => null]);
        }

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $asideStart = strpos($content, 'id="recent-activity-title"');
        $asideEnd = strpos($content, '</aside>', $asideStart);
        $asideHtml = substr($content, $asideStart, $asideEnd - $asideStart);

        $this->assertSame(5, substr_count($asideHtml, 'CI/BI updated'));
        $this->assertStringContainsString('View All', $asideHtml);
    }

    public function test_recent_activity_panel_uses_responsive_stacking_markup(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->activityLog($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => 1, 'co_maker_id' => null]);
        $this->activityLog($ci, $folder, 'income_source.created', 'income_sources', ['income_source_id' => 1, 'co_maker_id' => null]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('xl:grid-cols-[minmax(0,1fr)_minmax(15rem,23%)]', false)
            ->assertSee('aria-labelledby="recent-activity-title"', false)
            ->getContent();
        $asideStart = strrpos(substr($content, 0, strpos($content, 'id="recent-activity-title"')), '<aside');
        $asideEnd = strpos($content, '</aside>', $asideStart);
        $asideHtml = substr($content, $asideStart, $asideEnd - $asideStart);

        $this->assertStringContainsString('class="ui-panel min-w-0 p-5"', $asideHtml);
        $this->assertStringContainsString('text-base font-bold text-brand-sidebar', $asideHtml);
        $this->assertStringContainsString('grid-cols-[1rem_1fr] gap-3 pb-6', $asideHtml);
        $this->assertStringContainsString('border-l border-dashed border-ui-border-strong', $asideHtml);
        $this->assertStringContainsString('w-full text-center text-sm font-bold text-brand-primary hover:underline', $asideHtml);
        $this->assertStringNotContainsString('overflow-y-auto', $asideHtml);
        $this->assertStringNotContainsString('overflow-y-scroll', $asideHtml);
        $this->assertStringNotContainsString('max-h-', $asideHtml);
        $this->assertStringNotContainsString('xl:sticky', $asideHtml);
        $this->assertStringContainsString('max-w-2xl', $content);
        $this->assertStringContainsString('border-b border-ui-border px-1 py-4 first:pt-0 last:border-b-0 last:pb-0', $content);
    }

    public function test_recent_activity_query_count_is_flat_regardless_of_activity_volume(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        foreach (range(1, 30) as $index) {
            $this->activityLog($ci, $folder, 'cibi_report.updated', 'cibi_report', ['report_id' => $index, 'co_maker_id' => null]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ClientFolderOverview::class)->for($folder);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $queryCount);
    }

    public function test_existing_dashboard_folder_history_remains_unchanged(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'BEFORE'])
            ->fresh();
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'AFTER']);

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee('Folder History', false)
            ->assertSee('folder-history-dialog', false);
    }

    public function test_applicant_without_cibi_report_shows_add_with_plus_icon(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $card = $this->cibiCardHtml($ci, $folder);

        $this->assertStringContainsString('Add</a>', $card);
        $this->assertStringContainsString('d="M12 5v14M5 12h14"', $card);
        $this->assertStringNotContainsString('Open</a>', $card);
    }

    public function test_applicant_with_cibi_report_shows_open_with_edit_icon(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);

        $card = $this->cibiCardHtml($ci, $folder);

        $this->assertStringContainsString('Open</a>', $card);
        $this->assertStringContainsString('d="m4 20 4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"', $card);
        $this->assertStringNotContainsString('Add</a>', $card);
    }

    public function test_co_maker_without_cibi_report_shows_add(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER X']);

        $card = $this->cibiCardHtml($ci, $folder, $coMaker);

        $this->assertStringContainsString('Add</a>', $card);
        $this->assertStringNotContainsString('Open</a>', $card);
    }

    public function test_co_maker_with_cibi_report_shows_open(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER X']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);

        $card = $this->cibiCardHtml($ci, $folder, $coMaker);

        $this->assertStringContainsString('Open</a>', $card);
        $this->assertStringNotContainsString('Add</a>', $card);
    }

    public function test_one_persons_cibi_state_never_affects_another_persons_action_button(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $withReport = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'HAS REPORT']);
        $withoutReport = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'NO REPORT']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $withReport->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);
        // Applicant itself has no report — must not borrow either Co-Maker's state.

        $this->assertStringContainsString('Add</a>', $this->cibiCardHtml($ci, $folder));
        $this->assertStringContainsString('Open</a>', $this->cibiCardHtml($ci, $folder, $withReport));
        $this->assertStringContainsString('Add</a>', $this->cibiCardHtml($ci, $folder, $withoutReport));
    }

    public function test_cibi_preview_uses_eye_icon_with_correct_accessible_label(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete]);

        $card = $this->cibiCardHtml($ci, $folder);

        $this->assertStringContainsString('aria-label="Preview CI / BI Report"', $card);
        $this->assertStringContainsString('title="Preview CI / BI Report"', $card);
        $this->assertStringContainsString('d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7S2.5 12 2.5 12Z"', $card);
    }

    public function test_cibi_download_uses_download_icon_and_preserves_dropdown_behavior(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete]);

        $card = $this->cibiCardHtml($ci, $folder);

        $this->assertStringContainsString('aria-label="Download CI / BI Report"', $card);
        $this->assertStringContainsString('title="Download CI / BI Report"', $card);
        $this->assertStringContainsString('d="M12 4v11m0 0-3.5-3.5M12 15l3.5-3.5"', $card);
        $this->assertStringContainsString('d="m5 9 7 7 7-7"', $card);
        $this->assertStringContainsString('Download PDF', $card);
        $this->assertStringContainsString('Download Excel', $card);
        $this->assertStringContainsString('form="dashboard-cibi-export-pdf-form"', $card);
        $this->assertStringContainsString('form="dashboard-cibi-export-excel-form"', $card);
    }

    public function test_folder_contents_responsive_markup_has_no_horizontal_overflow_classes(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('overflow-x-scroll', $content);
        $this->assertStringContainsString('grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3', $content);
        $this->assertStringContainsString('flex flex-wrap items-center gap-1 border-t border-ui-border pt-3', $content);
    }

    private function cibiCardHtml(User $ci, ClientFolder $folder, ?CoMaker $activePerson = null): string
    {
        $url = $activePerson
            ? route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$activePerson->id
            : route('client-folders.show', $folder);
        $content = $this->actingAs($ci)->get($url)->assertOk()->getContent();
        $start = strpos($content, 'id="open-cibi-report"');
        $end = strpos($content, '</article>', $start);

        return substr($content, $start, $end - $start);
    }

    private function activityLog(User $ci, ClientFolder $folder, string $action, string $module, array $metadata): AuditLog
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

    private function rule(string $code, string $label, int $sortOrder, bool $required = true, bool $active = true): CompletionRule
    {
        return CompletionRule::create([
            'code' => $code,
            'label' => $label,
            'source_type' => 'testing',
            'is_required' => $required,
            'is_active' => $active,
            'sort_order' => $sortOrder,
        ]);
    }

    private function completionResult(ClientFolder $folder, CompletionRule $rule, bool $satisfied): void
    {
        ClientCompletionResult::create([
            'client_folder_id' => $folder->id,
            'completion_rule_id' => $rule->id,
            'is_satisfied' => $satisfied,
            'evaluated_at' => now(),
        ]);
    }
}
