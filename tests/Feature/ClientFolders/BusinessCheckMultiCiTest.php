<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4: Business Check multi-CI. Primary CI = ci_user_id (locked, first, never a companion),
 * companions live in business_check_contributors (existing Phase 1 table), independent from
 * Business Report / Residence Check / folder assigned_ci_id / the latest editor.
 */
class BusinessCheckMultiCiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_new_business_check_creator_is_the_primary_ci(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();

        $this->assertSame($ci->id, $check->ci_user_id);
        $this->assertSame([$ci->id], app(CiParticipantService::class)->orderedParticipantIds($check));
    }

    public function test_primary_plus_one_companion(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);

        app(CiParticipantService::class)->syncCompanions($check, [$mark->id]);

        $this->assertSame([$ci->id, $mark->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
    }

    public function test_primary_plus_multiple_companions_preserve_saved_order(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();
        $yong = User::factory()->create();

        // Submitted out of id order on purpose — saved order must be preserved.
        app(CiParticipantService::class)->syncCompanions($check, [$yong->id, $mark->id]);

        $this->assertSame([$ci->id, $yong->id, $mark->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
    }

    public function test_saved_companion_order_is_preserved_on_reload(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $yong = User::factory()->create(['full_name' => 'YONG SANTOS']);
        app(CiParticipantService::class)->syncCompanions($check, [$yong->id, $mark->id]);

        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSeeInOrder(['YONG SANTOS', 'MARK DELA CRUZ']);
    }

    public function test_primary_cannot_be_submitted_as_companion(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();

        $payload = $this->businessCheckPayload($check) + ['contributor_ids_present' => '1', 'contributor_ids' => [$ci->id, $mark->id]];
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$mark->id], $check->fresh()->contributors()->pluck('users.id')->all());
    }

    public function test_duplicate_companion_ids_are_deduplicated(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();

        $payload = $this->businessCheckPayload($check) + ['contributor_ids_present' => '1', 'contributor_ids' => [$mark->id, $mark->id]];
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$mark->id], $check->fresh()->contributors()->pluck('users.id')->all());
    }

    public function test_removing_a_companion_works(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();
        $yong = User::factory()->create();
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id, $yong->id]);

        $payload = $this->businessCheckPayload($check) + ['contributor_ids_present' => '1', 'contributor_ids' => [$yong->id]];
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$yong->id], $check->fresh()->contributors()->pluck('users.id')->all());
    }

    public function test_primary_has_no_remove_control_and_cannot_be_removed(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id]);

        $page = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk();
        // Only one remove control exists — the companion's, never the primary's.
        $this->assertSame(1, substr_count($page->getContent(), 'data-companion-remove'));

        // Even an explicit attempt to sync away the primary can't remove it — it was never a
        // companion pivot row to begin with.
        app(CiParticipantService::class)->syncCompanions($check, []);
        $this->assertSame([$ci->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
    }

    public function test_another_editor_is_not_automatically_added_as_participant(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $editor = User::factory()->create();

        // Editor saves without ever touching the companion picker (no contributor_ids submitted).
        $this->actingAs($editor)->post(route('client-folders.business-checks.store', $folder), $this->businessCheckPayload($check, ['remarks' => 'Edited remarks']))
            ->assertSessionHasNoErrors();

        $this->assertSame([$ci->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
        $this->assertSame($ci->id, $check->fresh()->ci_user_id);
    }

    public function test_explicitly_added_editor_becomes_a_participant(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $editor = User::factory()->create();

        $payload = $this->businessCheckPayload($check) + ['contributor_ids_present' => '1', 'contributor_ids' => [$editor->id]];
        $this->actingAs($editor)->post(route('client-folders.business-checks.store', $folder), $payload)->assertSessionHasNoErrors();

        $this->assertSame([$ci->id, $editor->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
        $this->assertSame($ci->id, $check->fresh()->ci_user_id, 'ci_user_id must not change because a companion was added.');
    }

    public function test_business_report_participants_do_not_affect_business_check(): void
    {
        [$ci, $folder, $source, $check] = $this->createCheck();
        $reportCompanion = User::factory()->create(['full_name' => 'REPORT COMPANION']);
        app(CiParticipantService::class)->syncCompanions($source, [$reportCompanion->id]);

        $output = app(CiParticipantService::class)->firstNames($check->fresh());
        $this->assertStringNotContainsString('REPORT', $output);
        $this->assertSame([$ci->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
    }

    public function test_folder_assigned_ci_does_not_override_business_check_primary(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY CREATOR']);
        $assignedCi = User::factory()->create(['full_name' => 'MARK ASSIGNED']);
        $folder = $this->folderFor($creator);
        $folder->update(['assigned_ci_id' => $assignedCi->id]);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($creator)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertRedirect();
        $check = $folder->businessChecks()->firstOrFail();

        $this->assertSame($creator->id, $check->ci_user_id);
        $this->assertNotSame($assignedCi->id, $check->ci_user_id);
        $this->assertSame([$creator->id], app(CiParticipantService::class)->orderedParticipantIds($check));
    }

    public function test_applicant_and_co_maker_business_checks_remain_isolated(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $applicantSource = $this->businessSource($folder, 'Applicant Store', 'Applicant Address');
        $coMakerSource = $this->businessSource($folder, 'Co-Maker Store', 'Co-Maker Address', $coMaker->id);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $applicantSource->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $coMakerSource->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address', 'co_maker_id' => $coMaker->id,
        ]);
        $applicantCheck = $folder->businessChecks()->where('co_maker_id', null)->firstOrFail();
        $coMakerCheck = $folder->businessChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();

        $applicantCompanion = User::factory()->create();
        $coMakerCompanion = User::factory()->create();
        app(CiParticipantService::class)->syncCompanions($applicantCheck, [$applicantCompanion->id]);
        app(CiParticipantService::class)->syncCompanions($coMakerCheck, [$coMakerCompanion->id]);

        $this->assertSame([$ci->id, $applicantCompanion->id], app(CiParticipantService::class)->orderedParticipantIds($applicantCheck->fresh()));
        $this->assertSame([$ci->id, $coMakerCompanion->id], app(CiParticipantService::class)->orderedParticipantIds($coMakerCheck->fresh()));
    }

    public function test_no_change_update_remains_no_change_when_participants_unchanged(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();
        $payload = $this->businessCheckPayload($check) + ['contributor_ids_present' => '1', 'contributor_ids' => [$mark->id]];

        // First save actually establishes this exact state (companion) — only the second,
        // identical save is the genuine no-change probe.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload)->assertSessionHas('statusType', 'success');
        $updatedAtBefore = $check->fresh()->updated_at;

        $this->actingAs($ci)
            ->post(route('client-folders.business-checks.store', $folder), $payload)
            ->assertRedirect()
            ->assertSessionHas('statusType', 'info')
            ->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');

        $this->assertTrue($updatedAtBefore->equalTo($check->fresh()->updated_at));
    }

    public function test_save_and_update_flash_distinct_success_messages(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        // A brand new record flashes the "saved" wording.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertRedirect()->assertSessionHas('status', 'Business Check saved successfully.');

        $check = $folder->businessChecks()->firstOrFail();

        // Editing that exact same record flashes the distinct "updated" wording instead.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->businessCheckPayload($check, [
            'remarks' => 'A genuine change to force past no-change detection',
        ]))->assertRedirect()->assertSessionHas('status', 'Business Check updated successfully.');
    }

    public function test_participant_only_change_counts_as_a_genuine_update(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();

        // Every field identical to how createCheck() already saved this record — only
        // contributor_ids is new, so this must still count as a genuine, non-no-change update.
        $payload = $this->businessCheckPayload($check) + ['contributor_ids_present' => '1', 'contributor_ids' => [$mark->id]];
        $this->actingAs($ci)
            ->post(route('client-folders.business-checks.store', $folder), $payload)
            ->assertRedirect()
            ->assertSessionHas('statusType', 'success')
            ->assertSessionHas('status', 'Business Check updated successfully.');

        $this->assertSame([$ci->id, $mark->id], app(CiParticipantService::class)->orderedParticipantIds($check->fresh()));
    }

    public function test_validation_failure_does_not_partially_sync_contributor_rows(): void
    {
        [$ci, $folder, $source, $check] = $this->createCheck();
        $mark = User::factory()->create();

        // ci_date is required — omitting it fails validation before the save Action ever runs.
        $payload = ['income_source_id' => $source->id, 'location' => 'Poblacion, San Miguel, Bulacan', 'check_id' => $check->id, 'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id]];
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload)->assertSessionHasErrors('ci_date');

        $this->assertSame(0, $check->fresh()->contributors()->count());
    }

    public function test_ui_loads_existing_companion_list_and_add_ci_label(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id]);

        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('CI In-Charge')
            ->assertSee('MARK DELA CRUZ')
            ->assertSee('data-companion-participant', false)
            ->assertSee('Add Companion CI');
    }

    public function test_add_ci_modal_excludes_the_primary(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $eligible = User::factory()->create(['full_name' => 'ELIGIBLE CI']);

        $page = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('ELIGIBLE CI');

        $modalMarkup = substr($page->getContent(), (int) strpos($page->getContent(), '<dialog id="business-check-companion-ci-dialog"'));
        $this->assertStringNotContainsString($ci->full_name, $modalMarkup);
        $this->assertStringNotContainsString('data-user-id="'.$ci->id.'"', $modalMarkup);
    }

    public function test_official_web_output_uses_first_names_in_saved_order(): void
    {
        [$ci, $folder, , $check] = $this->createCheck('REY MAGHILOM');
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $yong = User::factory()->create(['full_name' => 'YONG SANTOS']);
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id, $yong->id]);

        $this->assertSame('REY / MARK / YONG', app(CiParticipantService::class)->firstNames($check->fresh()));

        $this->actingAs($ci)
            ->get(route('client-folders.residence-business.preview', $folder))
            ->assertOk()
            ->assertSee('REY / MARK / YONG')
            ->assertDontSee('REY MAGHILOM / MARK DELA CRUZ / YONG SANTOS');
    }

    public function test_pdf_and_docx_batch_output_succeed_with_multi_ci_participants(): void
    {
        [$ci, $folder, , $check] = $this->createCheck('REY MAGHILOM');
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id]);

        // PDF/print output shares the exact same reports.official partial as the web preview
        // (same pattern already relied on elsewhere in this suite for CI text assertions).
        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_residence_check_output_remains_single_ci_and_unaffected(): void
    {
        [$ci, $folder, , $check] = $this->createCheck('REY MAGHILOM');
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id]);

        $residenceCi = User::factory()->create(['full_name' => 'RESIDENCE INVESTIGATOR']);
        ResidenceCheck::query()->create([
            'client_folder_id' => $folder->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'ci_user_id' => $residenceCi->id,
        ]);

        // Residence Check's own CI display is untouched by this phase — still first-name-only,
        // derived solely from its own investigator, never combined with Business Check's list.
        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('<strong>CI:</strong> RESIDENCE', false)
            ->assertDontSee('RESIDENCE INVESTIGATOR')
            ->assertDontSee('RESIDENCE / REY')
            ->assertDontSee('REY / MARK / RESIDENCE');
    }

    public function test_cibi_remains_untouched_by_business_check_participants(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();
        $mark = User::factory()->create();
        app(CiParticipantService::class)->syncCompanions($check, [$mark->id]);

        $this->assertSame($ci->id, $folder->fresh()->cibiReport->ci_in_charge_id);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource, 3: BusinessCheck} */
    private function createCheck(string $ciName = 'REY MAGHILOM'): array
    {
        $ci = User::factory()->create(['full_name' => $ciName]);
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertRedirect();
        $check = $folder->businessChecks()->firstOrFail();

        return [$ci, $folder, $source, $check];
    }

    private function businessCheckPayload(BusinessCheck $check, array $overrides = []): array
    {
        return array_merge([
            'check_id' => $check->id,
            'income_source_id' => $check->income_source_id,
            'ci_date' => $check->ci_date->toDateString(),
            'location' => $check->location,
        ], $overrides);
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['co_maker_id' => $coMakerId, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
