<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FolderContentsAutoUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // Applicant CIBI save AUTO-UPDATEs Folder Contents
    // ==================================================

    public function test_applicant_cibi_save_returns_authoritative_module_card_reflecting_completed_state(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)
            ->putJson(route('client-folders.cibi-report.update', $folder), $this->cibiPayload())
            ->assertOk();

        $moduleHtml = $response->json('cibi_module_html');
        $this->assertIsString($moduleHtml);
        $this->assertStringContainsString('id="open-cibi-report"', $moduleHtml);
        // The saved report is always state=complete on a JSON save (SaveCibiReport), so the
        // module card must show the real "Completed" badge/state, not a hardcoded guess.
        $this->assertStringContainsString('Completed', $moduleHtml);
        $this->assertStringContainsString('Official CI / BI report record available.', $moduleHtml);
        $this->assertStringNotContainsString('No CI / BI report has been started.', $moduleHtml);

        // No Co-Maker content leaks into the Applicant's own module fragment.
        $this->assertStringNotContainsString('Co-Maker', $moduleHtml);
    }

    public function test_applicant_cibi_save_returns_authoritative_recent_activity_and_person_switch_is_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)
            ->putJson(route('client-folders.cibi-report.update', $folder), $this->cibiPayload())
            ->assertOk();

        $html = $response->json('recent_activity_html');
        $this->assertIsString($html);
        $this->assertStringContainsString('CI/BI created', $html);

        // The full authoritative render (visiting the page directly) shows the identical module
        // and activity state — never a response-only fabrication.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('CI/BI created')
            ->assertSee('id="open-cibi-report"', false);
    }

    // ==================================================
    // Co-Maker CIBI save AUTO-UPDATEs only that Co-Maker's context
    // ==================================================

    public function test_co_maker_cibi_save_returns_module_card_and_recent_activity_scoped_to_the_exact_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A', 'first_name' => 'Co-Maker', 'last_name' => 'A']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B', 'first_name' => 'Co-Maker', 'last_name' => 'B']);

        $payload = $this->cibiPayload();
        $payload['co_maker_id'] = $coMakerA->id;

        $response = $this->actingAs($ci)
            ->putJson(route('client-folders.cibi-report.update', $folder).'?person=co-maker&co_maker_id='.$coMakerA->id, $payload)
            ->assertOk();

        $moduleHtml = $response->json('cibi_module_html');
        $this->assertStringContainsString('Completed', $moduleHtml);

        $activityHtml = $response->json('recent_activity_html');
        $this->assertStringContainsString('Co-Maker: CO-MAKER A', $activityHtml);
        $this->assertStringNotContainsString('Co-Maker B', $activityHtml);

        // Applicant's own CIBI stays untouched by the Co-Maker's save.
        $this->assertDatabaseMissing('cibi_reports', ['client_folder_id' => $folder->id, 'co_maker_id' => null]);
    }

    // ==================================================
    // Co-Maker Add / Edit / Remove AUTO-UPDATE Folder Contents
    // ==================================================

    public function test_adding_a_co_maker_returns_authoritative_person_switch_and_recent_activity_with_no_reload(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'address' => 'Zone 3, Barangay Puerto, Cagayan de Oro City, Misamis Oriental',
        ])->assertOk();

        $personSwitchHtml = $response->json('person_switch_html');
        $this->assertIsString($personSwitchHtml);
        $this->assertStringContainsString('DELA CRUZ', mb_strtoupper($personSwitchHtml));
        $this->assertStringContainsString('data-co-maker-tab=', $personSwitchHtml);

        $activityHtml = $response->json('recent_activity_html');
        $this->assertStringContainsString('Co-Maker added', $activityHtml);
    }

    public function test_editing_a_co_maker_while_applicant_is_active_updates_tabs_without_leaking_into_applicant_context(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Original Name', 'first_name' => 'Original', 'last_name' => 'Name']);

        // Applicant is the active view (no ?person=co-maker) while a different Co-Maker's tab is edited.
        $response = $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'first_name' => 'Updated', 'last_name' => 'Name',
            'address' => $coMaker->address ?? 'Some Address',
        ])->assertOk();

        $personSwitchHtml = $response->json('person_switch_html');
        $this->assertStringContainsString('UPDATED NAME', mb_strtoupper($personSwitchHtml));
        // Applicant tab still shows as Active, not the edited Co-Maker.
        $this->assertMatchesRegularExpression('/Applicant\s*\(<span[^>]*>Active/', $personSwitchHtml);
    }

    public function test_removing_a_different_co_maker_auto_updates_without_disturbing_the_active_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $activeCoMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Active Co-Maker', 'first_name' => 'Active', 'last_name' => 'Co-Maker']);
        $toRemove = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Removable Co-Maker', 'first_name' => 'Removable', 'last_name' => 'Co-Maker']);

        $response = $this->actingAs($ci)
            ->deleteJson(route('client-folders.co-maker.destroy', [$folder, $toRemove]).'?person=co-maker&co_maker_id='.$activeCoMaker->id)
            ->assertOk();

        $this->assertDatabaseMissing('co_makers', ['id' => $toRemove->id]);
        $personSwitchHtml = $response->json('person_switch_html');
        $this->assertStringNotContainsString('REMOVABLE CO-MAKER', mb_strtoupper($personSwitchHtml));
        $this->assertStringContainsString('ACTIVE CO-MAKER', mb_strtoupper($personSwitchHtml));

        // The removed co-maker's own lifecycle event is exact-person-scoped (per
        // ClientFolderOverview::personActivity) — it belongs to the Applicant's view and the
        // removed co-maker's own (now-gone) view, never a *different* active Co-Maker's feed.
        $activityHtml = $response->json('recent_activity_html');
        $this->assertStringNotContainsString('Co-Maker removed', $activityHtml);
    }

    public function test_removing_the_active_co_maker_does_not_crash_the_response(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Self Removed', 'first_name' => 'Self', 'last_name' => 'Removed']);

        // The removed co-maker's own id is still on the query string, mirroring the moment the
        // request leaves the browser before any client-side navigation happens.
        $this->actingAs($ci)
            ->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker]).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()
            ->assertJsonStructure(['message', 'person_switch_html', 'recent_activity_html']);
    }

    // ==================================================
    // Failure / no-change behavior
    // ==================================================

    public function test_cibi_validation_failure_does_not_return_folder_contents_fragments(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->cibiPayload();
        $payload['branch_name'] = null;

        $response = $this->actingAs($ci)
            ->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertStatus(422);

        $this->assertNull($response->json('cibi_module_html'));
        $this->assertNull($response->json('recent_activity_html'));
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    // ==================================================
    // Static JS checks
    // ==================================================

    public function test_co_maker_add_edit_and_remove_no_longer_reload_the_page(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('submitUrl.search = window.location.search', $javascript);
        $this->assertStringContainsString('personSwitchRegion.innerHTML = payload.person_switch_html', $javascript);
        $this->assertStringContainsString('recentActivityBody.innerHTML = payload.recent_activity_html', $javascript);
        $this->assertStringNotContainsString('window.setTimeout(() => window.location.reload(), 900)', $javascript);
        $this->assertStringNotContainsString('window.location.href = removedId && removedId === activeId', $javascript);
        // The one remaining active-person-removed navigation is a genuine context change (see
        // removingActivePerson below), not a disguised refresh of an otherwise-valid page.
        $this->assertStringContainsString('removingActivePerson', $javascript);
        $this->assertStringContainsString('window.location.assign(window.location.pathname)', $javascript);
    }

    public function test_cibi_module_and_recent_activity_swap_uses_the_same_authoritative_response_no_second_get(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("document.getElementById('open-cibi-report')", $javascript);
        $this->assertStringContainsString('cibiModuleCard.outerHTML = event.data.cibiModuleHtml', $javascript);
        $this->assertStringContainsString('recentActivityBody.innerHTML = event.data.recentActivityHtml', $javascript);
    }

    private function cibiPayload(): array
    {
        return [
            'intent' => 'complete', 'start_date' => '2026-07-03', 'submitted_date' => '2026-07-04', 'party_type' => 'borrower',
            'branch_name' => 'BLU TIN-AO', 'account_officer_name' => 'IRISH JANE ALBOR', 'amount_applied' => '340000.00',
            'ci_risk_level' => 'mid', 'purpose_codes' => ['building_construction_home_renovation'], 'purpose_other' => null,
            'summary_totals' => ['institutions_checked' => 0, 'institutions_declared' => 0, 'loan_records_found' => 0],
            'personal_snapshot' => [
                'name' => 'VALIDATED CLIENT NAME', 'age' => 42, 'spouse_name' => null, 'spouse_age' => null,
                'present_address' => 'Validated present address', 'length_of_stay_months' => 36, 'residence_status' => 'Owned', 'residence_status_from' => null,
                'monthly_rent' => null, 'living_with_parents' => false, 'other_residences' => null,
                'home_condition' => 'New', 'number_of_storeys' => 2, 'material_cost_level' => 'Medium', 'living_condition' => 'Good',
                'previous_address' => null, 'previous_address_length_of_stay_months' => null,
                'parents_address' => 'Validated parents address', 'dependents_count' => 2, 'civil_status' => 'Married',
                'separated_year' => null, 'reputation' => 'Good', 'barangay_findings' => 'Validated barangay findings',
                'court_background_status' => 'No Legal Cases', 'court_background' => null,
                'lifestyle' => 'Modest', 'vehicles_owned' => null, 'contact_details' => null,
                'other_remarks' => null,
            ],
            'purpose_remarks' => null, 'negative_credit_findings' => null,
            'other_remarks' => null, 'prepared_by_name' => 'Assigned Investigator', 'noted_by_name' => null,
            'bank_accounts' => [], 'loan_records' => [], 'credit_checks' => [], 'income_summaries' => [], 'legal_findings' => [],
        ];
    }
}
