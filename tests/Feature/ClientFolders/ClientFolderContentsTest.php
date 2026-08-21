<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientCompletionResult;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
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

    public function test_administrator_can_open_any_active_folder_and_ci_only_assigned_folder(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $own = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $other = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id]);

        $this->actingAs($administrator)->get(route('client-folders.show', $own))->assertOk();
        $this->actingAs($administrator)->get(route('client-folders.show', $other))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.show', $own))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.show', $other))->assertForbidden();
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
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->assertSee('Business / Income Sources')->assertSee('Add Business')->assertDontSee('Add Another Business')->assertDontSee('Add New Business / Income Source');
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

    public function test_other_ci_cannot_open_folder_module_placeholder_directly(): void
    {
        $assignedCi = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);

        $this->actingAs($otherCi)
            ->get(route('client-folders.client-information.edit', $folder))
            ->assertForbidden();
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

    public function test_other_ci_cannot_load_or_save_cibi_page_data_for_another_folder(): void
    {
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($other)->get(route('client-folders.show', $folder))->assertForbidden();
        $this->actingAs($other)->get(route('client-folders.cibi-report.edit', $folder))->assertForbidden();
        $this->actingAs($other)->putJson(route('client-folders.cibi-report.update', $folder), [])->assertForbidden();
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
        $this->assertStringContainsString('window.setTimeout(() => toast.remove(), 2500)', $javascript);
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

        // 9, not 8: the Co-Maker switcher added one more constant-cost eager load (the folder's
        // coMakers list) on top of the original 8 — still flat regardless of child-record volume.
        $this->assertLessThanOrEqual(9, $queryCount);
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
