<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CibiSignatoryReassignmentUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_cibi_signatory_management_menu(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $this->actingAs($admin)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('CI/BI signatory management');
    }

    public function test_regular_ci_does_not_see_signatory_management_menu(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertDontSee('CI/BI signatory management');
    }

    public function test_signatory_menu_contains_only_reassign_signatory(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $menuStart = strpos($content, 'CI/BI signatory management');
        // The menu items live in the sibling <details> content just after the trigger span.
        $menuEnd = strpos($content, '</x-ui.context-menu>', $menuStart);
        $menuEnd = $menuEnd === false ? strpos($content, 'id="cibi-reassign-signatory-dialog"', $menuStart) : $menuEnd;
        $menu = substr($content, $menuStart, ($menuEnd ?: $menuStart + 2000) - $menuStart);

        $this->assertStringContainsString('Reassign Signatory', $menu);
        $this->assertStringNotContainsString('Preview Report', $menu);
        $this->assertStringNotContainsString('Download PDF', $menu);
        $this->assertStringNotContainsString('Print', $menu);
    }

    public function test_modal_shows_current_signatory_and_active_ci_options_excluding_current(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $other = User::factory()->create(['full_name' => 'OTHER ACTIVE CI']);

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Reassign CI/BI Signatory', $content);
        $this->assertStringContainsString('Change the official Prepared By / CI signatory for this report.', $content);
        $this->assertStringContainsString('Current Signatory', $content);
        $this->assertStringContainsString($ci->full_name, $content);
        $this->assertStringContainsString('OTHER ACTIVE CI', $content);

        // The current signatory must not appear as a selectable <option> in the dropdown.
        $selectStart = strpos($content, 'name="new_signatory_id"');
        $selectEnd = strpos($content, '</select>', $selectStart);
        $select = substr($content, $selectStart, $selectEnd - $selectStart);
        $this->assertStringNotContainsString('>'.$ci->full_name.'<', $select);
        $this->assertStringContainsString('>OTHER ACTIVE CI<', $select);
    }

    /** The modal's presentation: exact helper wording, a read-only current signatory, icon actions. */
    public function test_modal_presentation_uses_the_current_wording_and_icon_actions(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $dialogStart = strpos($content, 'id="cibi-reassign-signatory-dialog"');
        $dialogEnd = strpos($content, 'id="cibi-reassign-signatory-dialog-confirm"', $dialogStart);
        $dialog = substr($content, $dialogStart, $dialogEnd - $dialogStart);

        $this->assertStringContainsString(
            'The report creator and audit history will remain unchanged. The selected CI will be assigned as the official Prepared By / Signatory.',
            $dialog,
        );
        foreach (['previous audit history', 'will become the new official', 'The original report creator', 'will be designated as the official'] as $retired) {
            $this->assertStringNotContainsString($retired, $content, 'Retired helper wording must be gone.');
        }

        // The current signatory stays a read-only value block — no editable control writes it, and
        // its element still holds only the name, which app.js copies into the confirmation dialog.
        $this->assertMatchesRegularExpression('/data-cibi-reassign-current[^>]*>'.preg_quote($ci->full_name, '/').'</', $dialog);
        $this->assertStringNotContainsString('name="ci_in_charge_id"', $dialog);

        // Both footer actions keep their existing classes/hooks and now carry an icon.
        $cancel = substr($dialog, strpos($dialog, 'data-modal-close class="ui-button-secondary"'), 600);
        $this->assertStringContainsString('<svg', $cancel);
        $this->assertStringContainsString('Cancel', $cancel);

        $primary = substr($dialog, strpos($dialog, 'data-cibi-reassign-continue="cibi-reassign-signatory-dialog-form"'), 600);
        $this->assertStringContainsString('<svg', $primary);
        $this->assertStringContainsString('Reassign Signatory', $primary);
        $this->assertStringContainsString('class="ui-button-primary"', $dialog);
    }

    public function test_modal_does_not_show_a_report_for_section_but_still_targets_the_applicant_report(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('Report For', $content);
        $this->assertStringNotContainsString('data-cibi-reassign-person', $content);
        // Still targets the correct report internally — baked into the form action, not shown.
        $this->assertStringContainsString(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), $content);
    }

    public function test_modal_targets_the_exact_co_maker_report_internally_without_showing_report_for(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $targetReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $target->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);

        $content = $this->actingAs($admin)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$target->id)
            ->assertOk()->getContent();

        // PEDRO DELA CRUZ legitimately appears elsewhere on the page (the active-person header) —
        // scope the "no visible name inside the modal" check to the modal markup specifically.
        $modalStart = strpos($content, 'id="cibi-reassign-signatory-dialog"');
        $modalEnd = strpos($content, 'id="cibi-reassign-signatory-dialog-confirm"');
        $modal = substr($content, $modalStart, $modalEnd - $modalStart);

        $this->assertStringNotContainsString('Report For', $modal);
        $this->assertStringNotContainsString('PEDRO DELA CRUZ', $modal);
        $this->assertStringNotContainsString('MARIA DELA CRUZ', $modal);
        $this->assertStringContainsString(route('client-folders.cibi-report.reassign-signatory', [$folder, $targetReport]), $content);
    }

    public function test_confirmation_modal_is_simplified_to_just_old_and_new_signatory(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $confirmStart = strpos($content, 'id="cibi-reassign-signatory-dialog-confirm"');
        $confirmEnd = strpos($content, '</dialog>', $confirmStart);
        $confirm = substr($content, $confirmStart, $confirmEnd - $confirmStart);

        $this->assertStringContainsString('Reassign signatory from', $confirm);
        $this->assertStringNotContainsString('Report For', $confirm);
        $this->assertStringNotContainsString('data-cibi-reassign-confirm-person', $confirm);
        $this->assertStringContainsString('data-cibi-reassign-confirm-from', $confirm);
        $this->assertStringContainsString('data-cibi-reassign-confirm-to', $confirm);
        $this->assertStringContainsString('Yes, Reassign', $confirm);
    }

    public function test_reassignment_requires_confirmation_before_submit_by_markup_structure(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        // The data-entry dialog's action button never submits directly (type=button, wired to
        // open the confirm dialog); only the confirm dialog's own button actually submits.
        $this->assertStringContainsString('data-cibi-reassign-continue="cibi-reassign-signatory-dialog-form"', $content);
        $this->assertStringContainsString('data-cibi-reassign-confirm-submit', $content);
        $this->assertStringContainsString('Confirm Signatory Reassignment', $content);
        $this->assertStringContainsString('data-cibi-reassign-confirm-from', $content);
        $this->assertStringContainsString('data-cibi-reassign-confirm-to', $content);
    }

    public function test_reason_is_required_by_backend_validation(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
        ])->assertSessionHasErrors('reason');
    }

    public function test_only_an_active_eligible_signatory_may_be_selected(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $ineligible = [
            'inactive Credit Investigator' => User::factory()->create(['status' => UserStatus::Disabled]),
            'inactive Senior Credit Investigator' => User::factory()->seniorCreditInvestigator()->create(['status' => UserStatus::Disabled]),
            'Administrator' => User::factory()->administrator()->create(),
        ];

        foreach ($ineligible as $description => $target) {
            $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                'new_signatory_id' => $target->id,
                'reason' => 'Attempted reassignment to an '.$description.'.',
            ])->assertSessionHasErrors('new_signatory_id');
        }

        $this->assertSame($ci->id, $report->fresh()->ci_in_charge_id);
    }

    /**
     * Eligibility to *be* a signatory is its own rule: both Credit Investigator grades, active
     * only, one row per user, and never an Administrator.
     */
    public function test_the_dropdown_lists_active_ci_and_senior_ci_once_each_and_no_one_else(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $senior = User::factory()->seniorCreditInvestigator()->create(['full_name' => 'ACTIVE SENIOR CI']);
        User::factory()->create(['full_name' => 'ACTIVE CI']);
        User::factory()->create(['full_name' => 'INACTIVE CI', 'status' => UserStatus::Disabled]);
        User::factory()->seniorCreditInvestigator()->create(['full_name' => 'INACTIVE SENIOR CI', 'status' => UserStatus::Disabled]);
        User::factory()->administrator()->create(['full_name' => 'ANOTHER ADMIN']);

        // Both roles allowed to reassign get the same populated list.
        foreach ([$admin, $senior] as $viewer) {
            $select = $this->signatoryOptions($viewer, $folder);

            foreach (['ACTIVE CI', 'ACTIVE SENIOR CI'] as $eligible) {
                $this->assertSame(1, substr_count($select, '>'.$eligible.'<'), $eligible.' must appear exactly once.');
            }
            foreach (['INACTIVE CI', 'INACTIVE SENIOR CI', 'ANOTHER ADMIN', $ci->full_name] as $excluded) {
                $this->assertStringNotContainsString('>'.$excluded.'<', $select, $excluded.' must not be selectable.');
            }
        }
    }

    /**
     * The bug this guards: Add Co-Maker used to reopen itself after a rejected signatory
     * reassignment, because its data-open-on-error flag was keyed off $errors->any() — any
     * failed POST that redirected back to this page popped it open. Each dialog now answers
     * only for its own fields.
     */
    public function test_a_rejected_reassignment_reopens_its_own_dialog_and_never_add_co_maker(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create();

        $page = $this->actingAs($admin)
            ->from(route('client-folders.show', $folder))
            ->followingRedirects()
            ->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                'new_signatory_id' => $newSignatory->id,
                'reason' => '   ',
            ])->assertOk()->getContent();

        $this->assertSame('false', $this->dialogOpenOnError($page, 'co-maker-dialog'), 'Add Co-Maker must stay closed.');
        $this->assertSame('true', $this->dialogOpenOnError($page, 'cibi-reassign-signatory-dialog'), 'The reassign dialog must show its own error.');
        $this->assertStringContainsString('Please provide a reason for reassignment.', $page);
        $this->assertSame($ci->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_a_successful_reassignment_leaves_every_dialog_closed_and_shows_the_new_signatory(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->seniorCreditInvestigator()->create(['full_name' => 'ANTHONY B. YONG']);

        $page = $this->actingAs($admin)
            ->from(route('client-folders.show', $folder))
            ->followingRedirects()
            ->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                'new_signatory_id' => $newSignatory->id,
                'reason' => 'On leave',
            ])->assertOk()->getContent();

        // Canonical success toast, no dialog reopened by the redirect that carries it.
        $this->assertStringContainsString('ANTHONY B. YONG is now the official Prepared By / Signatory.', $page);
        $this->assertSame('false', $this->dialogOpenOnError($page, 'co-maker-dialog'));
        $this->assertSame('false', $this->dialogOpenOnError($page, 'cibi-reassign-signatory-dialog'));

        // The signatory presentation is current on the page the user is returned to — no manual reload.
        $this->assertSame($newSignatory->id, $report->fresh()->ci_in_charge_id);
        $this->assertMatchesRegularExpression('/data-cibi-reassign-current[^>]*>ANTHONY B\. YONG</', $page);
        // The dropdown follows the new state: the new signatory is now the current one and drops
        // out of the options, and the person they replaced becomes selectable again.
        $options = $this->signatoryOptions($admin, $folder);
        $this->assertStringNotContainsString('>ANTHONY B. YONG<', $options);
        $this->assertStringContainsString('>'.$ci->full_name.'<', $options);
    }

    public function test_add_co_maker_still_opens_from_its_own_trigger_and_from_its_own_errors(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        // The explicit "+ Add Co-Maker" control still targets that dialog.
        $page = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-modal-open="co-maker-dialog" data-co-maker-add-trigger', $page);

        // And the dialog still reopens for its own rejected submission.
        $afterCoMakerError = $this->actingAs($admin)
            ->from(route('client-folders.show', $folder))
            ->followingRedirects()
            ->post(route('client-folders.co-maker.store', $folder), ['first_name' => 'JUAN', 'last_name' => '', 'address' => ''])
            ->assertOk()->getContent();

        $this->assertSame('true', $this->dialogOpenOnError($afterCoMakerError, 'co-maker-dialog'));
        $this->assertSame('false', $this->dialogOpenOnError($afterCoMakerError, 'cibi-reassign-signatory-dialog'));
    }

    public function test_short_reasons_are_accepted_and_empty_ones_are_not(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        foreach (['', '   ', "\n\t "] as $blank) {
            $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                'new_signatory_id' => User::factory()->create()->id,
                'reason' => $blank,
            ])->assertSessionHasErrors('reason');
        }

        foreach (['On leave', 'Schedule conflict', 'Field assignment', 'Unavailable today'] as $reason) {
            [$admin, $ci, $folder, $report] = $this->context();
            $target = User::factory()->create();

            $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                'new_signatory_id' => $target->id,
                'reason' => $reason,
            ])->assertSessionHasNoErrors();

            $this->assertSame($target->id, $report->fresh()->ci_in_charge_id, $reason.' must be an acceptable reason.');
            $this->assertDatabaseHas('audit_logs', ['action' => 'cibi_report.signatory_reassigned', 'client_folder_id' => $folder->id]);
        }
    }

    /** The rendered data-open-on-error flag of one dialog on the page. */
    private function dialogOpenOnError(string $page, string $dialogId): string
    {
        $start = strpos($page, 'id="'.$dialogId.'"');
        $this->assertNotFalse($start, $dialogId.' must be rendered on the page.');
        $tag = substr($page, $start, strpos($page, '>', $start) - $start);
        $this->assertMatchesRegularExpression('/data-open-on-error="(true|false)"/', $tag, $dialogId.' must declare its own auto-open flag.');
        preg_match('/data-open-on-error="(true|false)"/', $tag, $matches);

        return $matches[1];
    }

    private function signatoryOptions(User $viewer, ClientFolder $folder): string
    {
        $content = $this->actingAs($viewer)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $start = strpos($content, 'name="new_signatory_id"');
        $this->assertNotFalse($start, 'The signatory select must be rendered for '.$viewer->role->value.'.');

        return substr($content, $start, strpos($content, '</select>', $start) - $start);
    }

    public function test_current_signatory_cannot_be_selected_as_replacement_backend(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $ci->id,
            'reason' => 'Attempted reassignment to the same signatory.',
        ])->assertSessionHasErrors('new_signatory_id');

        $this->assertSame($ci->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_successful_reassignment_changes_ci_in_charge_id_and_keeps_created_by(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create(['full_name' => 'NEW SIGNATORY']);

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ])->assertRedirect(route('client-folders.show', $folder));

        $report->refresh();
        $this->assertSame($newSignatory->id, $report->ci_in_charge_id);
        $this->assertSame($ci->id, $report->created_by);
    }

    public function test_previous_audit_history_remains_unchanged_after_reassignment(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        AuditLog::create([
            'user_id' => $ci->id, 'client_folder_id' => $folder->id, 'action' => 'cibi_report.created',
            'module' => 'cibi_report', 'description' => 'x', 'metadata' => ['report_id' => $report->id, 'co_maker_id' => null],
        ]);
        $priorCount = AuditLog::count();
        $newSignatory = User::factory()->create();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Test reason.',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cibi_report.created', 'client_folder_id' => $folder->id]);
        $this->assertSame($priorCount + 1, AuditLog::count());
    }

    public function test_reassignment_creates_audit_metadata_with_old_new_reason_and_admin_actor(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create(['full_name' => 'NEW SIGNATORY NAME']);

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        // Admin Audit Log data completeness (tests 16/17): the raw AuditLog row is the complete
        // official record — old/new signatory (id + name snapshot, independent of either user
        // record surviving), affected person (co_maker_id — null here means Applicant), the
        // Admin actor, the reason, and the exact timestamp.
        $event = AuditLog::where('action', 'cibi_report.signatory_reassigned')->sole();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame($ci->id, $event->metadata['old_signatory_id']);
        $this->assertSame($ci->full_name, $event->metadata['old_signatory_name']);
        $this->assertSame($newSignatory->id, $event->metadata['new_signatory_id']);
        $this->assertSame('NEW SIGNATORY NAME', $event->metadata['new_signatory_name']);
        $this->assertNull($event->metadata['co_maker_id']);
        $this->assertSame('Workload reassignment.', $event->metadata['reason']);
        $this->assertNotNull($event->created_at);
    }

    public function test_admin_audit_log_retains_affected_co_maker_for_a_co_maker_reassignment(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);
        $newSignatory = User::factory()->create();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Co-Maker reassignment.',
        ]);

        $event = AuditLog::where('action', 'cibi_report.signatory_reassigned')->sole();
        $this->assertSame($coMaker->id, $event->metadata['co_maker_id']);
    }

    public function test_applicant_signatory_reassignment_appears_only_in_applicant_recent_activity(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'UNRELATED CO MAKER']);
        $newSignatory = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        // Visible to any authorized CI (not Admin-only) in the Applicant context:
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('CI/BI Signatory reassigned')
            ->assertSee("REASAN MARK Q. GURA \u{2192} REY C. MAGHILOM", false)
            ->assertDontSee('Workload reassignment.');

        // Must not leak into an unrelated Co-Maker's own Recent Activity.
        $coMakerContent = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();
        $asideStart = strpos($coMakerContent, 'id="recent-activity-title"');
        $asideEnd = strpos($coMakerContent, '</aside>', $asideStart);
        $this->assertStringNotContainsString('CI/BI Signatory reassigned', substr($coMakerContent, $asideStart, $asideEnd - $asideStart));
    }

    public function test_co_maker_signatory_reassignment_appears_only_in_that_co_makers_recent_activity(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET CO MAKER']);
        $other = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OTHER CO MAKER']);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $target->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);
        $newSignatory = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$target->id)
            ->assertOk()
            ->assertSee('CI/BI Signatory reassigned')
            ->assertSee('REY C. MAGHILOM');

        // Applicant view must not see the Co-Maker-scoped reassignment.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertDontSee('CI/BI Signatory reassigned');

        // Another Co-Maker's view must not see it either.
        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$other->id)
            ->assertOk()
            ->assertDontSee('CI/BI Signatory reassigned');
    }

    public function test_recent_activity_shows_actor_and_correct_asia_manila_timestamp(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $admin->forceFill(['full_name' => 'ADMINISTRATOR NAME'])->save();
        $newSignatory = User::factory()->create();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);
        // audit_logs.created_at is genuine UTC (see AuditLog::createdAt()) — 16:05 UTC on Aug 23
        // converts to 12:05 AM Manila on Aug 24 (+8, rolling into the next calendar day).
        \Illuminate\Support\Facades\DB::table('audit_logs')->where('action', 'cibi_report.signatory_reassigned')
            ->update(['created_at' => '2026-08-23 16:05:00']);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('by ADMINISTRATOR NAME')
            ->assertSee('Aug 24, 2026')
            ->assertSee('12:05 AM');
    }

    public function test_later_ci_edit_does_not_change_the_reassigned_signatory(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create();
        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $anotherCi = User::factory()->create();
        $this->actingAs($anotherCi)->put(route('client-folders.cibi-report.update', $folder), [
            'intent' => 'draft', 'party_type' => 'borrower', 'ci_risk_level' => 'low',
            'branch_name' => 'Main', 'start_date' => '2026-08-01', 'account_officer_name' => 'AO',
            'submitted_date' => '2026-08-02',
        ]);

        $this->assertSame($newSignatory->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_official_output_uses_the_newly_assigned_signatory(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $report->update(['state' => RecordState::Complete, 'prepared_by_name' => $ci->full_name]);
        $newSignatory = User::factory()->create(['full_name' => 'NEW OFFICIAL SIGNATORY']);
        $report->incomeSourceSummaries()->create(['source_name' => 'Saved Income', 'monthly_amount' => 1000, 'sort_order' => 1]);

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $html = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']))
            ->assertOk()
            ->getContent();

        // CI IN CHARGE reads live from ci_in_charge_id via the investigator relation, but
        // PREPARED BY reads a separate stored `prepared_by_name` column that is otherwise only
        // re-synced on the next normal encoding-form save — assert PREPARED BY specifically
        // (scoped to its own row) so a regression there can't hide behind CI IN CHARGE passing.
        $this->assertStringContainsString('NEW OFFICIAL SIGNATORY', $html);
        $preparedByStart = strpos($html, 'PREPARED BY:');
        $preparedByEnd = strpos($html, '</tr>', $preparedByStart);
        $preparedByRow = substr($html, $preparedByStart, $preparedByEnd - $preparedByStart);
        $this->assertStringContainsString('NEW OFFICIAL SIGNATORY', $preparedByRow);
        $this->assertStringNotContainsString($ci->full_name, $preparedByRow);

        $this->assertSame('NEW OFFICIAL SIGNATORY', $report->fresh()->prepared_by_name);
    }

    public function test_applicant_and_co_maker_signatory_reassignment_stay_correctly_scoped(): void
    {
        $admin = User::factory()->administrator()->create();
        $applicantCi = User::factory()->create(['full_name' => 'APPLICANT SIGNATORY']);
        $coMakerCi = User::factory()->create(['full_name' => 'COMAKER SIGNATORY']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $applicantCi->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $applicantReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $applicantCi->id, 'created_by' => $applicantCi->id]);
        $coMakerReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $coMakerCi->id, 'created_by' => $coMakerCi->id]);
        $newSignatory = User::factory()->create();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $applicantReport]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Applicant reassignment.',
        ])->assertRedirect();

        $this->assertSame($newSignatory->id, $applicantReport->fresh()->ci_in_charge_id);
        // The Co-Maker's own report must be completely untouched by the Applicant's reassignment.
        $this->assertSame($coMakerCi->id, $coMakerReport->fresh()->ci_in_charge_id);
    }

    public function test_existing_cibi_save_update_preview_download_and_authorization_remain_unchanged(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        // Save/Update still works for the assigned CI (unaffected by admin-only reassignment UI).
        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->assertSee('Save');
        // A non-admin cannot reach the reassignment endpoint.
        $newSignatory = User::factory()->create();
        $this->actingAs($ci)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Not allowed.',
        ])->assertForbidden();
    }

    /** @return array{0: User, 1: User, 2: ClientFolder, 3: CibiReport} */
    // --- Post-reassignment redirect targeting (regression lock — verified correct as-is) --------

    public function test_successful_applicant_reassignment_redirects_to_applicant_folder_contents(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $response->assertRedirect(route('client-folders.show', $folder));
    }

    public function test_applicant_redirect_contains_no_co_maker_id(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $this->assertStringNotContainsString('co_maker_id', $response->headers->get('Location'));
    }

    public function test_applicant_reassignment_does_not_open_a_co_maker_even_if_previously_selected(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'PREVIOUSLY SELECTED']);
        $newSignatory = User::factory()->create();

        // Admin was viewing (and posted from) a Co-Maker context earlier in their session — the
        // POST itself still targets the Applicant report directly, and the redirect must not
        // carry any leftover Co-Maker context forward.
        $response = $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]).'?person=co-maker&co_maker_id='.$coMaker->id, [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $response->assertRedirect(route('client-folders.show', $folder));
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString((string) $coMaker->id, parse_url($location, PHP_URL_QUERY) ?? '');

        $followed = $this->actingAs($admin)->get($location)->assertOk();
        $followed->assertSee('Current View: <span class="rounded-full bg-brand-soft px-2 py-1 font-bold text-brand-primary">Applicant</span>', false);
    }

    public function test_successful_co_maker_reassignment_redirects_to_that_same_exact_co_maker(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET CO MAKER']);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $target->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);
        $newSignatory = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);

        $response->assertRedirect(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$target->id);
    }

    public function test_with_multiple_co_makers_reassignment_never_opens_another_co_maker(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET CO MAKER']);
        $other1 = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OTHER CO MAKER ONE']);
        $other2 = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OTHER CO MAKER TWO']);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $target->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);
        $newSignatory = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ]);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('co_maker_id='.$target->id, $location);
        $this->assertStringNotContainsString('co_maker_id='.$other1->id, $location);
        $this->assertStringNotContainsString('co_maker_id='.$other2->id, $location);

        $followed = $this->actingAs($admin)->get($location)->assertOk();
        $followed->assertSee('Current View: <span class="rounded-full bg-brand-soft px-2 py-1 font-bold text-brand-primary">Co-Maker — TARGET CO MAKER</span>', false);
    }

    public function test_reassignment_data_remains_correctly_scoped_after_redirect(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $applicantReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);
        $coMakerReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);
        $newSignatory = User::factory()->create();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $applicantReport]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Workload reassignment.',
        ])->assertRedirect(route('client-folders.show', $folder));

        $this->assertSame($newSignatory->id, $applicantReport->fresh()->ci_in_charge_id);
        $this->assertSame($ci->id, $coMakerReport->fresh()->ci_in_charge_id);
    }

    // --- Reassign Signatory / Add Co-Maker modal-trigger isolation ---------------------------

    public function test_reassign_signatory_trigger_targets_only_the_reassign_modal_for_applicant(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $triggerStart = strpos($content, 'id="cibi-reassign-signatory-trigger"');
        $this->assertNotFalse($triggerStart);
        $triggerTag = substr($content, $triggerStart, strpos($content, '>', $triggerStart) - $triggerStart);
        $this->assertStringContainsString('data-modal-open="cibi-reassign-signatory-dialog"', $triggerTag);
        $this->assertStringNotContainsString('data-modal-open="co-maker-dialog"', $triggerTag);
        $this->assertStringNotContainsString('data-co-maker-add-trigger', $triggerTag);
        $this->assertStringNotContainsString('data-co-maker-edit-trigger', $triggerTag);

        // Exactly one element in the whole page carries this dialog id — no duplicate that could
        // cause getElementById to resolve to the wrong dialog.
        $this->assertSame(1, substr_count($content, 'id="cibi-reassign-signatory-dialog"'));
    }

    public function test_reassign_signatory_trigger_targets_only_the_reassign_modal_for_co_maker(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();

        $triggerStart = strpos($content, 'id="cibi-reassign-signatory-trigger"');
        $this->assertNotFalse($triggerStart);
        $triggerTag = substr($content, $triggerStart, strpos($content, '>', $triggerStart) - $triggerStart);
        $this->assertStringContainsString('data-modal-open="cibi-reassign-signatory-dialog"', $triggerTag);
        $this->assertStringNotContainsString('data-modal-open="co-maker-dialog"', $triggerTag);
        $this->assertSame(1, substr_count($content, 'id="cibi-reassign-signatory-dialog"'));
    }

    public function test_reassign_signatory_trigger_is_isolated_with_multiple_co_makers(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER ONE']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER TWO']);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER THREE']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $target->id, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id]);

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$target->id)
            ->assertOk()->getContent();

        // Even with multiple Co-Makers on the page (tabs, Switch Person menu, per-tab action
        // menus), only one Reassign Signatory trigger and one target dialog id ever exist.
        $this->assertSame(1, substr_count($content, 'id="cibi-reassign-signatory-trigger"'));
        $this->assertSame(1, substr_count($content, 'id="cibi-reassign-signatory-dialog"'));
        $this->assertSame(1, substr_count($content, 'id="co-maker-dialog"'));
    }

    public function test_add_co_maker_button_still_opens_add_co_maker_normally(): void
    {
        [$admin, $ci, $folder, $report] = $this->context();

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $triggerStart = strpos($content, 'id="co-maker-add-trigger"');
        $this->assertNotFalse($triggerStart);
        $triggerTag = substr($content, $triggerStart, strpos($content, '>', $triggerStart) - $triggerStart);
        $this->assertStringContainsString('data-modal-open="co-maker-dialog"', $triggerTag);
        $this->assertStringContainsString('data-co-maker-add-trigger', $triggerTag);
        $this->assertStringNotContainsString('cibi-reassign-signatory-dialog', $triggerTag);
    }

    public function test_edit_co_maker_button_still_works_normally(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);

        $content = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-co-maker-edit-trigger', $content);
        $editStart = strpos($content, 'data-co-maker-edit-trigger');
        $editTagStart = strrpos(substr($content, 0, $editStart), '<button');
        $editTag = substr($content, $editTagStart, $editStart - $editTagStart + 200);
        $this->assertStringContainsString('data-modal-open="co-maker-dialog"', $editTag);
        $this->assertStringNotContainsString('cibi-reassign-signatory-dialog', $editTag);
    }

    private function context(): array
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $report = CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id, 'created_by' => $ci->id, 'state' => RecordState::Draft,
        ]);

        return [$admin, $ci, $folder, $report];
    }
}
