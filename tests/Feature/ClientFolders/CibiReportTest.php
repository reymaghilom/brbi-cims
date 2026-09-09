<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveCibiReport;
use App\Enums\AddressType;
use App\Enums\ClientFolderStatus;
use App\Enums\OfficialReportType;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\CibiBankAccount;
use App\Models\CibiReport;
use App\Models\ClientAddress;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Reports\OfficialReportDataBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CibiReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_access_is_shared_across_admin_and_any_ci_but_deleted_folders_are_unavailable(): void
    {
        $admin = User::factory()->administrator()->create();
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($admin)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $this->actingAs($assigned)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $this->actingAs($other)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $this->actingAs($other)->put(route('client-folders.cibi-report.update', $folder), $this->payload())->assertRedirect();
        $folder->delete();
        $this->actingAs($admin)->get(route('client-folders.cibi-report.edit', $folder->id))->assertNotFound();
    }

    public function test_signatory_is_the_first_saving_actor_and_stays_immutable_on_later_saves_by_others(): void
    {
        $folderCreator = User::factory()->create();
        $firstSaver = User::factory()->create();
        $secondSaver = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $folderCreator->id]);

        $this->actingAs($firstSaver)->put(route('client-folders.cibi-report.update', $folder), $this->payload())->assertRedirect();
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();
        $this->assertSame($firstSaver->id, $report->ci_in_charge_id);
        $this->assertSame($firstSaver->id, $report->created_by);

        $this->actingAs($secondSaver)->put(route('client-folders.cibi-report.update', $folder), $this->payload())->assertRedirect();
        $report->refresh();
        $this->assertSame($firstSaver->id, $report->ci_in_charge_id);
        $this->assertSame($firstSaver->id, $report->created_by);
    }

    public function test_non_admin_cannot_reassign_an_existing_signatory(): void
    {
        $ci = User::factory()->create();
        $newSignatory = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();

        $this->actingAs($ci)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Attempted reassignment by a non-admin.',
        ])->assertForbidden();

        $this->assertSame($ci->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_admin_can_reassign_signatory_with_required_reason_and_preserves_created_by(): void
    {
        $ci = User::factory()->create();
        $newSignatory = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'reason' => 'Original investigator resigned.',
        ])->assertSessionHasErrors('new_signatory_id');

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Original investigator resigned.',
        ])->assertRedirect();

        $report->refresh();
        $this->assertSame($newSignatory->id, $report->ci_in_charge_id);
        $this->assertSame($ci->id, $report->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cibi_report.signatory_reassigned',
            'client_folder_id' => $folder->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_reassignment_requires_a_reason_and_history_is_visible_to_any_folder_accessible_ci(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $newSignatory = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
        ])->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Transferred to another branch.',
        ])->assertRedirect();

        $this->actingAs($other)->get(route('client-folders.cibi-report.history', [$folder, $report]))
            ->assertOk()
            ->assertSee('Transferred to another branch.')
            ->assertSee($admin->full_name);
    }

    public function test_concurrent_save_with_stale_revision_is_rejected(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();
        $this->assertSame(1, $report->revision);

        // The other CI opened the form at revision 1 and saves first, advancing it to 2.
        $this->actingAs($other)->put(route('client-folders.cibi-report.update', $folder), $this->payload() + ['expected_revision' => 1])->assertRedirect();
        $this->assertSame(2, $report->fresh()->revision);

        // The original CI's stale form (still expecting revision 1) must be rejected, not silently overwritten.
        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload() + ['expected_revision' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_revision');
    }

    public function test_conflict_message_names_the_last_editor_when_available(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();

        $this->actingAs($other)->put(route('client-folders.cibi-report.update', $folder), $this->payload() + ['expected_revision' => 1])->assertRedirect();

        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload() + ['expected_revision' => 1])
            ->assertStatus(422);

        $this->assertSame(
            "{$other->full_name} updated this report while you were editing. Please review the latest version before saving again.",
            $response->json('errors.expected_revision.0'),
        );
    }

    public function test_unchanged_applicant_cibi_resubmission_reports_no_changes_without_bumping_revision_or_auditing(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();
        $originalRevision = $report->revision;
        $originalLastEditedBy = $report->last_edited_by;
        $originalCiInCharge = $report->ci_in_charge_id;
        $originalCreatedBy = $report->created_by;

        $other = User::factory()->create();
        $resubmission = $this->withChildIds($this->payload(), $report);
        $response = $this->actingAs($other)->putJson(route('client-folders.cibi-report.update', $folder), $resubmission + ['expected_revision' => $report->revision])
            ->assertOk();

        $this->assertTrue($response->json('no_change'));
        $this->assertStringContainsString('No changes detected', $response->json('message'));

        $report->refresh();
        $this->assertSame($originalRevision, $report->revision);
        $this->assertSame($originalLastEditedBy, $report->last_edited_by);
        $this->assertSame($originalCiInCharge, $report->ci_in_charge_id);
        $this->assertSame($originalCreatedBy, $report->created_by);
        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'cibi_report.updated']);
    }

    public function test_unchanged_co_maker_cibi_resubmission_reports_no_changes_without_bumping_revision_or_auditing(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $payload = $this->payload() + ['co_maker_id' => $coMaker->id];
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload);
        $report = CibiReport::where('client_folder_id', $folder->id)->where('co_maker_id', $coMaker->id)->sole();

        $resubmission = $this->withChildIds($payload, $report);
        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $resubmission + ['expected_revision' => $report->revision])
            ->assertOk();

        $this->assertTrue($response->json('no_change'));
        $this->assertSame(1, $report->fresh()->revision);
        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'cibi_report.updated']);

        // A truly different exact Co-Maker's own report must never be touched by this check.
        $otherCoMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ANOTHER CO MAKER']);
        $this->assertDatabaseMissing('cibi_reports', ['client_folder_id' => $folder->id, 'co_maker_id' => $otherCoMaker->id]);
    }

    public function test_real_cibi_change_still_updates_normally_after_no_change_detection_was_added(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();

        $payload = $this->payload();
        $payload['other_remarks'] = 'A genuinely different remark.';
        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload + ['expected_revision' => $report->revision])
            ->assertOk();

        $this->assertFalse((bool) $response->json('no_change'));
        $this->assertSame(2, $report->fresh()->revision);
        $this->assertSame('A genuinely different remark.', $report->fresh()->other_remarks);
        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'cibi_report.updated']);
    }

    public function test_stale_revision_conflict_still_wins_over_no_change_detection(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();

        // The other CI makes a real change first, advancing the revision.
        $advancedPayload = $this->payload();
        $advancedPayload['other_remarks'] = 'Advanced by another CI.';
        $this->actingAs($other)->put(route('client-folders.cibi-report.update', $folder), $advancedPayload + ['expected_revision' => 1])->assertRedirect();
        $this->assertSame(2, $report->fresh()->revision);

        // The original CI's form is still byte-for-byte identical to what THEY originally loaded
        // (unchanged from their perspective) but is now stale — the conflict must win, not a
        // false "no changes" pass-through that would silently discard the other CI's real edit.
        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload() + ['expected_revision' => 1])
            ->assertStatus(422);

        $this->assertNull($response->json('no_change'));
        $response->assertJsonValidationErrors('expected_revision');
        $this->assertSame('Advanced by another CI.', $report->fresh()->other_remarks);
    }

    public function test_two_cis_racing_to_create_the_same_brand_new_report_do_not_silently_overwrite_each_other(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $first->id]);

        // Both CIs opened the CI/BI form while no report existed yet, so both forms carry a blank
        // expected_revision — the second save must still be rejected, not silently overwrite the
        // report the first CI just created.
        $this->actingAs($first)->put(route('client-folders.cibi-report.update', $folder), $this->payload())->assertRedirect();
        $report = CibiReport::where('client_folder_id', $folder->id)->sole();
        $this->assertSame(1, $report->revision);

        $response = $this->actingAs($second)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_revision');

        $this->assertSame(
            "{$first->full_name} updated this report while you were editing. Please review the latest version before saving again.",
            $response->json('errors.expected_revision.0'),
        );
        $this->assertSame(1, $report->fresh()->revision);
    }

    public function test_client_information_is_reused_read_only_without_creating_report_on_get(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);
        ClientInformation::factory()->create(['client_folder_id' => $folder->id, 'spouse_name' => 'MARIA DELA CRUZ', 'contact_number' => '09170000000']);
        ClientAddress::create(['client_folder_id' => $folder->id, 'address_type' => AddressType::Present, 'address_line_1' => '123 Main Street', 'city_municipality' => 'San Pablo', 'country' => 'Philippines']);
        IncomeSource::factory()->create(['client_folder_id' => $folder->id, 'income_source_template_id' => IncomeSourceTemplate::query()->firstOrFail()->id, 'source_name' => 'Existing Retail Store']);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()
            ->assertSee('DELA CRUZ, JUAN')->assertSee('MARIA DELA CRUZ')->assertSee('123 Main Street')->assertDontSee('Existing Retail Store')->assertSee('Validated Personal Information');
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    public function test_applicant_cibi_shows_name_of_client_label_and_the_folders_own_last_first_middle_format(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN SANTOS']);

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('NAME OF CLIENT:', $content);
        $this->assertStringNotContainsString('NAME OF COMAKER:', $content);
        $this->assertStringContainsString('name="personal_snapshot[name]" type="text"  value="DELA CRUZ, JUAN SANTOS"', $content);
    }

    public function test_co_maker_cibi_shows_name_of_co_maker_label_and_last_first_middle_format_from_structured_fields(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // full_name deliberately stored in the older "First Middle Last" order used everywhere
        // else in the app — the CI/BI report must never display this raw column directly, only
        // the Last/First/Middle composition built from the structured name parts below.
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id, 'full_name' => 'JUAN SANTOS DELA CRUZ',
            'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz',
        ]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('NAME OF COMAKER:', $content);
        $this->assertStringNotContainsString('NAME OF CLIENT:', $content);
        $this->assertStringContainsString('name="personal_snapshot[name]" type="text"  value="Dela Cruz, Juan Santos"', $content);
        $this->assertStringNotContainsString('value="JUAN SANTOS DELA CRUZ"', $content);
    }

    public function test_co_maker_without_structured_name_parts_falls_back_to_full_name_instead_of_crashing(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'LEGACY CO MAKER']);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="personal_snapshot[name]" type="text"  value="LEGACY CO MAKER"', $content);
    }

    public function test_applicant_cibi_name_field_is_not_readonly_or_disabled(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        $nameFieldStart = strpos($content, 'id="personal_snapshot-name"');
        $this->assertNotFalse($nameFieldStart, 'Name of Client field was not found.');
        $nameFieldTag = substr($content, $nameFieldStart, strpos($content, '>', $nameFieldStart) - $nameFieldStart);
        $this->assertStringNotContainsString('readonly', $nameFieldTag);
        $this->assertStringNotContainsString('disabled', $nameFieldTag);
    }

    public function test_manually_edited_applicant_cibi_name_saves_to_the_report_snapshot_only_and_reopens_with_it(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);
        $payload = $this->payload();
        $payload['personal_snapshot']['name'] = 'DELA CRUZ, JUAN P.';

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)->assertOk();

        $saved = $folder->cibiReport()->sole();
        $this->assertSame('DELA CRUZ, JUAN P.', $saved->personal_snapshot['name']);
        // Editing the CIBI Name must affect only this report's own snapshot — never the Client
        // Folder's master display_name.
        $this->assertSame('DELA CRUZ, JUAN', $folder->fresh()->display_name);

        // Reopening the saved CIBI must show its own saved (manually edited) Name, not re-derive it
        // from the current Client Folder name every time the form loads.
        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('name="personal_snapshot[name]" type="text"  value="DELA CRUZ, JUAN P."', $content);

        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $this->assertSame('DELA CRUZ, JUAN P.', $document['cibi']['personal']['name']);
    }

    public function test_manually_edited_co_maker_cibi_name_saves_only_to_that_co_makers_report_and_leaves_master_and_others_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'APPLICANT NAME']);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id, 'full_name' => 'MARIA SANTOS',
            'first_name' => 'Maria', 'last_name' => 'Santos',
        ]);
        $otherCoMaker = CoMaker::create([
            'client_folder_id' => $folder->id, 'full_name' => 'PEDRO REYES',
            'first_name' => 'Pedro', 'last_name' => 'Reyes',
        ]);

        $payload = $this->payload();
        $payload['co_maker_id'] = $coMaker->id;
        $payload['personal_snapshot']['name'] = 'SANTOS, MARIA L.';

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder).'?person=co-maker&co_maker_id='.$coMaker->id, $payload)->assertOk();

        $saved = $folder->cibiReport()->where('co_maker_id', $coMaker->id)->sole();
        $this->assertSame('SANTOS, MARIA L.', $saved->personal_snapshot['name']);
        // Co-Maker master/profile record and the Applicant and other Co-Makers are never touched.
        $this->assertSame('MARIA SANTOS', $coMaker->fresh()->full_name);
        $this->assertSame('PEDRO REYES', $otherCoMaker->fresh()->full_name);
        $this->assertSame('APPLICANT NAME', $folder->fresh()->display_name);
        $this->assertSame(0, $folder->cibiReport()->whereNull('co_maker_id')->count());
        $this->assertSame(0, $folder->cibiReport()->where('co_maker_id', $otherCoMaker->id)->count());
    }

    public function test_new_co_maker_cibi_name_field_is_editable_and_prefills_from_the_exact_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id, 'full_name' => 'MARIA SANTOS',
            'first_name' => 'Maria', 'last_name' => 'Santos',
        ]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();

        $nameFieldStart = strpos($content, 'id="personal_snapshot-name"');
        $this->assertNotFalse($nameFieldStart, 'Name of Co-Maker field was not found.');
        $nameFieldTag = substr($content, $nameFieldStart, strpos($content, '>', $nameFieldStart) - $nameFieldStart);
        $this->assertStringNotContainsString('readonly', $nameFieldTag);
        $this->assertStringNotContainsString('disabled', $nameFieldTag);
        $this->assertStringContainsString('name="personal_snapshot[name]" type="text"  value="Santos, Maria"', $content);
    }

    public function test_new_applicant_cibi_starts_with_its_default_starter_rows_without_persisting_anything(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        $this->assertSame(3, $this->countRepeaterRows($content, 'bank_accounts'));
        // V. Income Sources Validation starts with just 1 blank starter row, not 3.
        $this->assertSame(1, $this->countRepeaterRows($content, 'income_summaries'));
        // IV. Summary on Credit / Loan Information pads NOTHING: a row there belongs to a real
        // Bank/Coop group, so a brand-new form offers its empty state and "Add Bank / Coop"
        // instead of blank institutions the encoder would have to clean up.
        $this->assertSame(0, $this->countRepeaterRows($content, 'loan_records'));
        $this->assertStringContainsString('No bank or cooperative added yet.', $content);

        // Opening the brand-new form must never persist a report or any child rows — the starter
        // rows are display-only defaults, dropped on save unless the encoder actually fills one in.
        $this->assertDatabaseCount('cibi_reports', 0);
        $this->assertDatabaseCount('cibi_bank_accounts', 0);
        $this->assertDatabaseCount('cibi_loan_records', 0);
        $this->assertDatabaseCount('cibi_income_sources', 0);
    }

    public function test_new_co_maker_cibi_starts_with_three_default_rows_and_stays_scoped_to_that_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $target = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET CO MAKER']);
        $other = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OTHER CO MAKER']);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$target->id)
            ->assertOk()->getContent();

        $this->assertSame(3, $this->countRepeaterRows($content, 'bank_accounts'));
        $this->assertSame(1, $this->countRepeaterRows($content, 'income_summaries'));
        $this->assertSame(0, $this->countRepeaterRows($content, 'loan_records'));
        $this->assertDatabaseCount('cibi_reports', 0);

        // A saved Applicant report with 1 real bank row must never bleed into, or be affected by,
        // the other Co-Maker's brand-new (unsaved) form — independent scoping stays intact.
        $applicantReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id]);
        $applicantReport->bankAccounts()->create(['institution' => 'Applicant Only Bank', 'sort_order' => 1]);

        $otherContent = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', $folder).'?person=co-maker&co_maker_id='.$other->id)
            ->assertOk()->getContent();

        $this->assertSame(3, $this->countRepeaterRows($otherContent, 'bank_accounts'));
        $this->assertStringNotContainsString('Applicant Only Bank', $otherContent);
        $this->assertDatabaseCount('cibi_reports', 1);
    }

    public function test_existing_saved_cibi_report_loads_its_real_rows_padded_only_up_to_three(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id]);
        $report->bankAccounts()->create(['institution' => 'Real Saved Bank', 'sort_order' => 1]);
        $report->bankAccounts()->create(['institution' => 'Second Saved Bank', 'sort_order' => 2]);
        $report->loanRecords()->create(['institution' => 'Loan One', 'sort_order' => 1]);
        $report->loanRecords()->create(['institution' => 'Loan Two', 'sort_order' => 2]);
        $report->loanRecords()->create(['institution' => 'Loan Three', 'sort_order' => 3]);
        $report->loanRecords()->create(['institution' => 'Loan Four', 'sort_order' => 4]);

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Real Saved Bank', $content);
        $this->assertStringContainsString('Second Saved Bank', $content);
        // 2 real rows padded up to the 3-row minimum — never truncated, never duplicated.
        $this->assertSame(3, $this->countRepeaterRows($content, 'bank_accounts'));
        // 4 real rows already exceed the minimum — no padding added, all 4 still render.
        $this->assertSame(4, $this->countRepeaterRows($content, 'loan_records'));
        $this->assertStringContainsString('Loan Four', $content);

        // Merely opening the edit page for an existing report must not create any new child rows.
        $this->assertSame(2, $report->bankAccounts()->count());
        $this->assertSame(4, $report->loanRecords()->count());
    }

    public function test_existing_saved_income_summaries_load_their_real_rows_without_forcing_a_single_default(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id]);
        $report->incomeSourceSummaries()->create(['source_name' => 'Saved Income One', 'sort_order' => 1]);
        $report->incomeSourceSummaries()->create(['source_name' => 'Saved Income Two', 'sort_order' => 2]);

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Saved Income One', $content);
        $this->assertStringContainsString('Saved Income Two', $content);
        // 2 real saved rows — never truncated back to 1, never padded with an extra blank row.
        $this->assertSame(2, $this->countRepeaterRows($content, 'income_summaries'));
        $this->assertSame(2, $report->incomeSourceSummaries()->count());
    }

    public function test_bank_loan_and_income_remarks_textareas_use_the_same_compact_row_height(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // IV renders only real Bank/Coop groups, so this section needs one saved institution
        // before it has a row whose findings textarea can be measured against the others.
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id])
            ->loanRecords()->create(['institution' => 'Compact Height Coop', 'sort_order' => 1]);

        $content = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        // A tall multi-row textarea forces every shorter sibling input in the same table row to
        // stretch to match it (native table row-height equalization) — keeping all three
        // remarks/findings textareas at the same compact height keeps rows in Sections II, IV,
        // and V uniformly compact instead of one section being visibly loose relative to another.
        $this->assertStringContainsString('aria-label="Relevant remarks" name="bank_accounts[0][relevant_remarks]" rows="2"', $content);
        $this->assertStringContainsString('aria-label="Payment performance and relevant findings" name="loan_records[0][combined_findings]" rows="2"', $content);
        $this->assertStringContainsString('aria-label="Key information" name="income_summaries[0][key_information]" rows="2"', $content);
    }

    private function countRepeaterRows(string $content, string $section): int
    {
        $start = strpos($content, 'id="'.$section.'-section"');
        $this->assertNotFalse($start, "Missing section: $section");
        $rowsStart = strpos($content, 'data-repeater-rows', $start);
        $rowsEnd = strpos($content, '</tbody>', $rowsStart);
        $body = substr($content, $rowsStart, $rowsEnd - $rowsStart);

        return substr_count($body, '<tr data-repeater-row');
    }

    public function test_create_saves_one_report_and_all_repeatable_sections(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload())
            ->assertRedirect(route('client-folders.cibi-report.edit', $folder))->assertSessionHas('status');

        $this->assertDatabaseCount('cibi_reports', 1);
        $report = CibiReport::whereBelongsTo($folder)->sole();
        $this->assertSame($ci->id, $report->ci_in_charge_id);
        $this->assertSame($ci->full_name, $report->prepared_by_name);
        $this->assertSame(RecordState::Complete, $report->state);
        foreach (['bankAccounts', 'loanRecords', 'creditChecks', 'incomeSourceSummaries', 'legalFindings'] as $relation) {
            $this->assertSame(1, $report->{$relation}()->count(), $relation);
        }
    }

    public function test_repeated_save_updates_existing_children_creates_new_and_only_explicitly_deletes(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $this->payload());
        $report = CibiReport::whereBelongsTo($folder)->sole();
        $bank = $report->bankAccounts()->sole();
        $loan = $report->loanRecords()->sole();
        $payload = $this->payload();
        $payload['bank_accounts'] = [
            ['id' => $bank->id, 'institution' => 'Updated Bank', 'branch' => 'Main'],
            ['institution' => 'Second Bank', 'branch' => 'North'],
        ];
        $payload['loan_records'] = [['id' => $loan->id, '_delete' => '1']];
        $payload['expected_revision'] = 1;

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $this->assertSame(1, CibiReport::whereBelongsTo($folder)->count());
        $this->assertSame(2, $report->bankAccounts()->count());
        $this->assertDatabaseHas('cibi_bank_accounts', ['id' => $bank->id, 'institution' => 'Updated Bank']);
        $this->assertDatabaseMissing('cibi_loan_records', ['id' => $loan->id]);
        $this->assertSame(2, $report->fresh()->revision);
        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'cibi_report.updated']);
    }

    public function test_update_removes_only_the_intentionally_deleted_bank_row(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload())->assertOk();
        $report = $folder->cibiReport()->sole();
        $bank = $report->bankAccounts()->sole();
        $payload = $this->payload();
        $payload['bank_accounts'] = [['id' => $bank->id, '_delete' => '1']];
        $payload['expected_revision'] = 1;

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonPath('message', 'CI/BI Report updated successfully.')
            ->assertJsonCount(0, 'report.child_ids.bank_accounts');

        $this->assertDatabaseMissing('cibi_bank_accounts', ['id' => $bank->id]);
        $this->assertSame(0, $report->bankAccounts()->count());
    }

    public function test_multiple_bank_rows_keep_ids_by_submitted_row_index_without_duplicates(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        $payload['bank_accounts'] = [
            ['institution' => 'First Bank', 'branch' => 'Main'],
            [],
            ['institution' => 'Second Bank', 'branch' => 'North'],
        ];

        $first = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonCount(2, 'report.child_ids.bank_accounts');
        $rowIds = $first->json('report.child_row_ids.bank_accounts');
        $this->assertArrayHasKey(0, $rowIds);
        $this->assertArrayHasKey(2, $rowIds);
        $this->assertArrayNotHasKey(1, $rowIds);

        $payload['bank_accounts'][0]['id'] = $rowIds[0];
        $payload['bank_accounts'][0]['institution'] = 'First Bank Updated';
        $payload['bank_accounts'][2]['id'] = $rowIds[2];
        $payload['bank_accounts'][2]['institution'] = 'Second Bank Updated';
        $payload['expected_revision'] = 1;
        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonPath('message', 'CI/BI Report updated successfully.')
            ->assertJsonCount(2, 'report.child_ids.bank_accounts');

        $report = $folder->cibiReport()->sole();
        $this->assertSame(2, $report->bankAccounts()->count());
        $this->assertDatabaseHas('cibi_bank_accounts', ['id' => $rowIds[0], 'institution' => 'First Bank Updated']);
        $this->assertDatabaseHas('cibi_bank_accounts', ['id' => $rowIds[2], 'institution' => 'Second Bank Updated']);
    }

    public function test_update_safely_recreates_a_bank_row_whose_stale_id_no_longer_exists(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload())->assertOk();
        $report = $folder->cibiReport()->sole();
        $deletedId = $report->bankAccounts()->sole()->id;
        $report->bankAccounts()->whereKey($deletedId)->delete();
        $payload = $this->payload();
        $payload['bank_accounts'][0]['id'] = $deletedId;
        $payload['bank_accounts'][0]['institution'] = 'Recovered Bank Row';
        $payload['expected_revision'] = 1;

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonPath('message', 'CI/BI Report updated successfully.')
            ->assertJsonCount(1, 'report.child_ids.bank_accounts');

        $replacement = $report->bankAccounts()->sole();
        $this->assertNotSame($deletedId, $replacement->id);
        $this->assertSame('Recovered Bank Row', $replacement->institution);
    }

    public function test_duplicate_child_ids_are_rejected_without_exposing_a_model_exception(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload())->assertOk();
        $report = $folder->cibiReport()->sole();
        $bank = $report->bankAccounts()->sole();
        $payload = $this->payload();
        $payload['bank_accounts'] = [
            ['id' => $bank->id, '_delete' => '1'],
            ['id' => $bank->id, 'institution' => 'Duplicate stale row'],
        ];

        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_accounts.0.id', 'bank_accounts.1.id']);

        $this->assertStringNotContainsString('No query results for model', $response->getContent());
        $this->assertDatabaseHas('cibi_bank_accounts', ['id' => $bank->id]);
        $this->assertSame(1, $report->fresh()->revision);
    }

    public function test_forged_child_and_income_source_ids_are_rejected_without_changes(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id]);
        $otherReport = CibiReport::factory()->create(['client_folder_id' => $otherFolder->id, 'ci_in_charge_id' => $otherCi->id]);
        $foreignBank = CibiBankAccount::create(['cibi_report_id' => $otherReport->id, 'institution' => 'Private Bank']);
        $template = IncomeSourceTemplate::query()->firstOrFail();
        $foreignSource = IncomeSource::factory()->create(['client_folder_id' => $otherFolder->id, 'income_source_template_id' => $template->id]);
        $payload = $this->payload();
        $payload['bank_accounts'][0]['id'] = $foreignBank->id;
        $payload['income_summaries'][0]['income_source_id'] = $foreignSource->id;

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertSessionHasErrors(['bank_accounts.0.id', 'income_summaries.0.income_source_id']);
        $this->assertDatabaseMissing('cibi_reports', ['client_folder_id' => $folder->id]);
        $this->assertDatabaseHas('cibi_bank_accounts', ['id' => $foreignBank->id, 'institution' => 'Private Bank']);
    }

    public function test_validation_covers_provided_dates_amounts_and_nested_rows(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload('complete');
        $payload['amount_applied'] = -1;
        $payload['start_date'] = now()->addDay()->toDateString();
        $payload['purpose_codes'] = ['others'];
        $payload['purpose_other'] = null;
        $payload['loan_records'][0]['granted_date'] = '2026-08-02';
        $payload['loan_records'][0]['maturity_date'] = '2026-08-01';
        $payload['income_summaries'] = [];
        $payload['legal_findings'] = [];

        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertSessionHasErrors(['amount_applied', 'start_date', 'loan_records.0.maturity_date'])
            ->assertSessionDoesntHaveErrors(['income_summaries', 'legal_findings']);
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    public function test_transaction_rolls_back_report_update_on_domain_failure(): void
    {
        $this->withoutExceptionHandling();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'branch_name' => 'Original Branch']);
        $otherFolder = ClientFolder::factory()->create();
        $otherReport = CibiReport::factory()->create(['client_folder_id' => $otherFolder->id, 'ci_in_charge_id' => $otherFolder->assigned_ci_id]);
        $foreignBank = CibiBankAccount::create(['cibi_report_id' => $otherReport->id, 'institution' => 'Foreign Bank']);

        try {
            app(SaveCibiReport::class)->execute($ci, $folder, array_replace($this->payload(), ['bank_accounts' => [['id' => $foreignBank->id, 'institution' => 'Forged']], 'expected_revision' => 1]));
            $this->fail('Expected child ownership failure.');
        } catch (ValidationException $exception) {
            $this->assertSame('This entry does not belong to this CI / BI report.', $exception->errors()['bank_accounts.0.id'][0]);
            $this->assertSame('Original Branch', $report->fresh()->branch_name);
            $this->assertSame(1, $report->fresh()->revision);
        }
    }

    public function test_successful_save_completes_report_and_updates_progress_overview(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload('complete');
        $this->actingAs($ci)->put(route('client-folders.cibi-report.update', $folder), $payload)->assertRedirect();

        $report = CibiReport::whereBelongsTo($folder)->sole();
        $this->assertSame(RecordState::Complete, $report->state);
        $this->assertDatabaseHas('client_completion_results', ['client_folder_id' => $folder->id, 'is_satisfied' => true, 'explanation_key' => 'cibi_report.complete']);
        $folder->refresh();
        $this->assertEquals(16.67, $folder->progress_percent);
        $this->assertSame(ClientFolderStatus::OnProgress, $folder->status);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('href="'.route('client-folders.cibi-report.edit', $folder).'"', false)
            ->assertSee('data-modal-open="cibi-report-dialog"', false)
            ->assertSee('Official CI / BI report record available.');

        $audit = AuditLog::where('action', 'cibi_report.created')->sole();
        $encoded = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Confidential narrative', $encoded);
        $this->assertStringNotContainsString('Primary Bank', $encoded);
        $this->assertStringNotContainsString('340000', $encoded);
    }

    public function test_form_has_official_sections_responsive_repeaters_and_no_generation_action(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $response = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();

        $response->assertSeeInOrder(['Validated Personal Information', 'Validated Purpose', 'Bank / Financial Institution', 'Summary on Credit / Loan Information', 'Income Sources Validation'])
            ->assertSee('data-cibi-standalone-layout', false)
            ->assertSee('data-cibi-form', false)
            ->assertDontSee('data-cibi-state-badge', false)
            ->assertDontSee('data-cibi-state-label', false)
            ->assertSee('novalidate', false)
            ->assertSee('cibi-encoding-paper', false)
            ->assertSee('cibi-section-heading cibi-personal-heading', false)
            ->assertSee('cibi-entry-table-wrap', false)
            ->assertSee('cibi-income-entry-table', false)
            ->assertSee('data-repeater=', false)->assertSee('sm:grid-cols-2', false)->assertSee('sticky bottom-4', false)
            ->assertDontSee('Back to Client Folder')
            ->assertDontSee('id="primary-sidebar"', false)
            ->assertDontSee('data-drawer-toggle', false)
            ->assertDontSee('Open account menu')
            ->assertDontSee('aria-label="Breadcrumb"', false)
            ->assertDontSee('CI / BI Encoding')
            ->assertSee('Save')
            // The encoding form's bottom action area shows only Save/Update — Print Preview and
            // Download PDF/Excel now live exclusively on the Folder Contents CI/BI card once the
            // report is complete, never inside the encoding form itself.
            ->assertDontSee('data-cibi-output-actions', false)
            ->assertDontSee('Print Preview')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Download Excel')
            ->assertDontSee('>Preview<', false)
            ->assertDontSee('Mark Complete')
            ->assertSee('name="party_type"', false)
            ->assertSee('name="ci_risk_level"', false)
            ->assertSee('class="cibi-party-risk-row"', false)
            ->assertSee('business-report-choice-group cibi-header-choice-group', false)
            ->assertSee('data-repeater-remove-dialog', false)
            ->assertSee('cibi-remove-entry-button', false)
            ->assertSee('size-5', false)
            ->assertSee('Remove this entry?')
            ->assertDontSee('data-cibi-modal', false)
            ->assertDontSee('Generate PDF')->assertDontSee('Generate Word')->assertDontSee('type="file"', false);

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-close-parent-dialog><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>.*?<button type="submit"[^>]*data-cibi-submit-mode="save"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*<span data-cibi-submit-text>Save CIBI Report<\/span><\/button>/s',
            $response->getContent(),
        );
    }

    public function test_completed_report_shows_only_the_update_action_without_preview_or_download_links(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete]);

        $response = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()
            // Print Preview / Download PDF / Download Excel belong on the Folder Contents CI/BI
            // card after completion, not inside the encoding form — see ClientFolderContentsTest
            // for that card's own assertions.
            ->assertDontSee(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi']), false)
            ->assertDontSee(route('client-folders.cibi-report.export-pdf', $folder), false)
            ->assertDontSee(route('client-folders.cibi-report.export-excel', $folder), false)
            ->assertDontSee('Print Preview')
            ->assertDontSee('Download PDF')
            ->assertDontSee('Download Excel')
            ->assertSee('data-cibi-submit data-cibi-submit-mode="update">', false)
            ->assertSee('Update CIBI Report')
            ->assertDontSee('Save CIBI Report')
            ->assertDontSee('data-cibi-submit-mode="save"', false)
            ->assertDontSee('data-cibi-state-badge', false)
            ->assertDontSee('data-cibi-state-label', false);

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-close-parent-dialog><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>.*?<button type="submit"[^>]*data-cibi-submit-mode="update"><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*<span data-cibi-submit-text>Update CIBI Report<\/span><\/button>/s',
            $response->getContent(),
        );
    }

    public function test_json_save_stays_on_encoding_page_and_automatically_completes(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload());

        $response->assertOk()
            ->assertJsonPath('message', 'CI/BI Report saved successfully.')
            ->assertJsonPath('return_url', route('client-folders.show', $folder))
            ->assertJsonPath('report.state', 'complete')
            ->assertJsonPath('report.was_completed', false)
            ->assertJsonPath('report.submit_label', 'Update CIBI Report')
            ->assertJsonPath('report.revision', 1)
            ->assertJsonPath('report.institutions_checked', 1)
            ->assertJsonPath('report.institutions_declared', 1)
            ->assertJsonPath('report.loan_records_found', 1)
            ->assertJsonCount(1, 'report.child_ids.bank_accounts')
            ->assertJsonCount(1, 'report.child_ids.loan_records')
            ->assertJsonCount(1, 'report.child_ids.income_summaries')
            ->assertJsonStructure(['folder' => ['status', 'progress_percentage']]);
        $this->assertDatabaseHas('cibi_reports', ['client_folder_id' => $folder->id, 'state' => 'complete']);
    }

    public function test_save_response_includes_authoritative_recent_activity_for_client_folder_contents_auto_update(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $this->payload())
            ->assertOk();

        // The same authoritative save response carries the freshly-rendered Recent Activity
        // fragment — no second GET needed for the Client Folder Contents page's own panel to
        // reflect the just-saved entry immediately.
        $html = $response->json('recent_activity_html');
        $this->assertIsString($html);
        $this->assertStringContainsString('CI/BI created', $html);
        $this->assertStringContainsString($ci->full_name, $html);
        $this->assertTrue(AuditLog::query()->where('action', 'cibi_report.created')->where('user_id', $ci->id)->exists());

        // Same canonical AuditLog-backed source as the page's own initial render — visiting Client
        // Folder Contents directly shows the identical entry, proving this isn't a fabricated
        // client-side or response-only entry.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('CI/BI created');
    }

    public function test_recent_activity_in_save_response_stays_scoped_to_the_exact_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A', 'first_name' => 'Co-Maker', 'last_name' => 'A']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B', 'first_name' => 'Co-Maker', 'last_name' => 'B']);

        $payload = $this->payload();
        $payload['co_maker_id'] = $coMakerA->id;

        $response = $this->actingAs($ci)
            ->putJson(route('client-folders.cibi-report.update', $folder).'?person=co-maker&co_maker_id='.$coMakerA->id, $payload)
            ->assertOk();

        $html = $response->json('recent_activity_html');
        $this->assertStringContainsString('Co-Maker: CO-MAKER A', $html);
        $this->assertStringNotContainsString('Co-Maker B', $html);
    }

    public function test_completed_report_update_keeps_the_existing_record_and_returns_update_feedback(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $report = CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete,
            'revision' => 4,
        ]);
        $payload = $this->payload();
        $payload['branch_name'] = 'UPDATED BRANCH';
        $payload['expected_revision'] = 4;

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonPath('message', 'CI/BI Report updated successfully.')
            ->assertJsonPath('return_url', route('client-folders.show', $folder))
            ->assertJsonPath('report.state', 'complete')
            ->assertJsonPath('report.was_completed', true)
            ->assertJsonPath('report.submit_label', 'Update CIBI Report')
            ->assertJsonPath('report.revision', 5);

        $this->assertDatabaseCount('cibi_reports', 1);
        $this->assertDatabaseHas('cibi_reports', [
            'id' => $report->id,
            'client_folder_id' => $folder->id,
            'state' => RecordState::Complete->value,
            'branch_name' => 'UPDATED BRANCH',
        ]);
    }

    public function test_returned_child_ids_prevent_repeatable_rows_from_duplicating_on_a_second_save(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();

        $first = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk();

        foreach (['bank_accounts', 'loan_records', 'credit_checks', 'income_summaries', 'legal_findings'] as $section) {
            foreach ($first->json("report.child_ids.$section") as $index => $id) {
                $payload[$section][$index]['id'] = $id;
            }
        }
        $payload['expected_revision'] = 1;
        // A genuine edit, not a byte-for-byte resubmission — no-change detection would otherwise
        // correctly reject an identical second save rather than bump the revision.
        $payload['other_remarks'] = 'Second save remark.';

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonPath('report.revision', 2);

        $report = $folder->cibiReport()->sole();
        foreach (['bankAccounts', 'loanRecords', 'creditChecks', 'incomeSourceSummaries', 'legalFindings'] as $relation) {
            $this->assertSame(1, $report->{$relation}()->count(), $relation);
        }
    }

    public function test_save_rejects_missing_required_fields_without_persisting(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        unset($payload['party_type']);
        foreach (['branch_name', 'account_officer_name', 'start_date', 'submitted_date', 'ci_risk_level'] as $field) {
            $payload[$field] = null;
        }
        $requiredPersonal = ['age', 'present_address', 'residence_status', 'home_condition', 'number_of_storeys', 'material_cost_level', 'living_condition', 'parents_address', 'civil_status', 'reputation', 'barangay_findings', 'lifestyle'];
        foreach ($requiredPersonal as $field) {
            $payload['personal_snapshot'][$field] = null;
        }

        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'branch_name', 'account_officer_name', 'start_date', 'submitted_date', 'party_type', 'ci_risk_level',
                ...array_map(fn ($field) => 'personal_snapshot.'.$field, $requiredPersonal),
            ]);

        $this->assertSame('This field is required.', $response->json('errors.branch_name.0'));
        $response->assertJsonMissingValidationErrors(['personal_snapshot.spouse_name', 'personal_snapshot.spouse_age']);

        $this->assertDatabaseMissing('cibi_reports', ['client_folder_id' => $folder->id]);
    }

    public function test_optional_sections_do_not_block_automatic_completion(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $invalid = $this->payload();
        $invalid['branch_name'] = null;
        $invalid['income_summaries'] = [];

        $response = $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_name'])
            ->assertJsonMissingValidationErrors(['income_summaries']);
        $this->assertSame('This field is required.', $response->json('errors.branch_name.0'));
        $this->assertDatabaseCount('cibi_reports', 0);

        $valid = $this->payload();
        $valid['amount_applied'] = null;
        $valid['purpose_codes'] = [];
        $valid['income_summaries'] = [];
        $valid['legal_findings'] = [];
        $valid['personal_snapshot']['spouse_name'] = null;
        $valid['personal_snapshot']['spouse_age'] = null;
        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $valid)
            ->assertOk()
            ->assertJsonPath('message', 'CI/BI Report saved successfully.')
            ->assertJsonPath('report.state', 'complete');
        $this->assertDatabaseHas('client_completion_results', ['client_folder_id' => $folder->id, 'is_satisfied' => true]);
        $this->assertNull($folder->cibiReport()->sole()->personal_snapshot['spouse_name']);
        $this->assertNull($folder->cibiReport()->sole()->personal_snapshot['spouse_age']);
    }

    public function test_section_one_validated_fields_including_name_save_as_snapshot_without_touching_master_records(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'display_name' => 'CANONICAL CLIENT NAME',
        ]);
        $information = ClientInformation::factory()->create([
            'client_folder_id' => $folder->id,
            'spouse_name' => 'CANONICAL SPOUSE',
            'civil_status' => 'Married',
            'contact_number' => '09171111111',
            'home_condition' => 'Good',
        ]);
        ClientAddress::create([
            'client_folder_id' => $folder->id,
            'address_type' => AddressType::Present,
            'address_line_1' => 'Canonical Present Address',
            'country' => 'Philippines',
        ]);

        $form = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        foreach (['name', 'present_address', 'spouse_name', 'contact_details', 'other_remarks'] as $field) {
            $form->assertSee('name="personal_snapshot['.$field.']"', false);
        }
        $form->assertSee('name="personal_snapshot[civil_status]"', false)
            ->assertSee('name="personal_snapshot[home_condition]"', false)
            ->assertSee('value="CANONICAL CLIENT NAME"', false)
            ->assertSee('Canonical Present Address')
            ->assertSee('CANONICAL SPOUSE')
            ->assertDontSee('data-cibi-personal-fields inert', false);

        $payload = $this->payload();
        $payload['personal_snapshot'] = array_replace($payload['personal_snapshot'], [
            'name' => 'REPORT SNAPSHOT NAME',
            'present_address' => 'Report-time validated address',
            'spouse_name' => 'REPORT SNAPSHOT SPOUSE',
            'contact_details' => '09999999999 / validated@example.test',
            'other_remarks' => 'Report-time validated remarks',
            'civil_status' => 'Separated',
            'home_condition' => 'Ancestral',
            'living_with_parents' => true,
        ]);

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertOk()
            ->assertJsonPath('report.state', 'complete');

        $snapshot = CibiReport::whereBelongsTo($folder)->sole()->personal_snapshot;
        // 'name' is manually editable in the CI/BI form, same as the other Section I fields here —
        // the submitted edit is what gets saved into this report's own snapshot.
        $this->assertSame('REPORT SNAPSHOT NAME', $snapshot['name']);
        $this->assertSame('Report-time validated address', $snapshot['present_address']);
        $this->assertSame('REPORT SNAPSHOT SPOUSE', $snapshot['spouse_name']);
        $this->assertSame('Separated', $snapshot['civil_status']);
        $this->assertSame('Ancestral', $snapshot['home_condition']);
        $this->assertTrue($snapshot['living_with_parents']);
        // The edit affects only this CIBI Report's own snapshot — never the Client Folder's own
        // display_name or the linked ClientInformation profile.
        $this->assertSame('CANONICAL CLIENT NAME', $folder->fresh()->display_name);
        $this->assertSame('CANONICAL SPOUSE', $information->fresh()->spouse_name);

        $reopened = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        // Reopening a saved CIBI shows its own saved Name — not re-derived from the Client Folder.
        $reopened->assertSee('value="REPORT SNAPSHOT NAME"', false)
            ->assertDontSee('value="CANONICAL CLIENT NAME"', false)
            ->assertSee('Report-time validated address')
            ->assertSee('value="Separated" selected', false)
            ->assertSee('value="Ancestral" selected', false)
            ->assertDontSee('id="personal-snapshot-living-with-parents"', false);
    }

    public function test_section_one_validation_returns_field_errors_without_making_the_form_readonly(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        $payload['personal_snapshot']['age'] = 151;
        $payload['personal_snapshot']['residence_status'] = 'Rented';
        $payload['personal_snapshot']['monthly_rent'] = str_repeat('1', 256);

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['personal_snapshot.age', 'personal_snapshot.monthly_rent']);
        $this->assertDatabaseCount('cibi_reports', 0);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()
            ->assertSee('data-cibi-personal-fields', false)
            ->assertDontSee('data-cibi-personal-fields inert', false);
    }

    public function test_official_form_controls_are_compact_conditional_and_remove_redundant_blocks(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'REYES, MARIA SANTOS']);

        $response = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $response->assertSee('id="personal_snapshot-name"', false)
            ->assertSee('readonly', false)
            ->assertSee('name="personal_snapshot[name]" type="text"  value="REYES, MARIA SANTOS"', false)
            ->assertSee('data-residence-status', false)
            ->assertSee('id="personal-residence-status"', false)
            ->assertSee('name="personal_snapshot[residence_status]"', false)
            ->assertDontSee('<option value="">Select Status</option>', false)
            ->assertSee('data-residence-from-display', false)
            ->assertSee('data-monthly-rent-display', false)
            ->assertSee('name="personal_snapshot[other_residences]"', false)
            ->assertSee('OTHER RESIDENCES (OWNED/MORTGAGED)')
            ->assertDontSee('OTHER RESIDENCES (OWNED/MORTGAGED):')
            ->assertSee('value="Owned"', false)
            ->assertSee('value="Mortgaged"', false)
            ->assertSee('value="Rented"', false)
            ->assertSee('value="Living with Parents"', false)
            ->assertSee('Mortgaged From')
            ->assertSee('Rented From')
            ->assertDontSee('class="ui-label">From</label>', false)
            ->assertSee('type="radio" name="personal_snapshot[residence_status]"', false)
            ->assertSee('business-report-choice-group cibi-residence-status-options', false)
            ->assertSee('business-report-choice-option', false)
            ->assertSee('>Year Opened</th>', false)
            ->assertDontSee('<option value="Other">Other</option>', false)
            ->assertDontSee('name="personal_snapshot[residence_status]" value="Other"', false)
            ->assertSee('Permanent Address')
            ->assertSee('data-copy-present-address="personal-previous-address"', false)
            ->assertSee('data-copy-present-address="personal-permanent-address"', false)
            ->assertSee('Use Present Address')
            ->assertSee('data-adb-choice', false)
            ->assertSee('data-adb-figures', false)
            ->assertSee('cibi-adb-controls', false)
            ->assertSee('cibi-adb-choice-line', false)
            ->assertSee('>ADB Level</th>', false)
            ->assertSee('/ FIGURES:')
            ->assertSee('/ FIGURES:</span><input', false)
            ->assertDontSee('ADB Level / Figures')
            ->assertSee('cibi-compact-financial-input', false)
            ->assertSee('class="cibi-prepared-by"', false)
            ->assertSee('class="cibi-encoding-signatory-name"', false)
            ->assertSee('name="prepared_by_name"', false)
            ->assertDontSee('type="text" name="prepared_by_name"', false)
            ->assertSee('cibi-bank-entry-table', false)
            ->assertSee('placeholder="Figures"', false)
            ->assertSee('cibi-loan-entry-table', false)
            ->assertSee('cibi-loan-date-controls', false)
            ->assertSee('cibi-loan-meta-controls', false)
            ->assertSee('cibi-loan-amount-input', false)
            ->assertSee('title="Granted Date"', false)
            ->assertSee('title="Maturity Date"', false)
            ->assertDontSee('Granted:')
            ->assertDontSee('Maturity:')
            ->assertDontSee('>Figures</label>', false)
            ->assertDontSee('value="figures"', false)
            ->assertSee('data-number-format', false)
            ->assertSee('+ Add Bank')
            ->assertSee('+ Add Loan')
            ->assertSee('+ Add Income Source')
            ->assertSee('class="cibi-remove-entry-button"', false)
            ->assertSee('aria-label="Remove entry"', false)
            ->assertSee('choice-group-columns', false)
            ->assertSeeInOrder(['Working Capital / Inventory / Receivables', 'Buyout / Debt Consolidation', 'Building Construction / Home Renovation', 'Personal: Medical / Education / Travel / Estate Management', 'Business Expansion / Renovation / Start-up Inventory', 'Chattel Property Acquisition', 'Real Estate Property Acquisition', 'Others'])
            ->assertSee('EXISTING WITH STRONG CAPACITY')
            ->assertSee('EXISTING BUT WEAK CAPACITY')
            ->assertSee('WAS NOT / CANNOT BE VALIDATED')
            ->assertDontSee('Linked Source')
            ->assertDontSee('Credit / Loan Summary')
            ->assertDontSee('Official outputs use the latest saved data.')
            ->assertSee('Payment performance and relevant findings')
            ->assertSee('name="noted_by_name"', false)
            ->assertSee('readonly aria-readonly="true"', false)
            ->assertSee('cibi-signatory-role', false)
            ->assertSee('CREDIT INVESTIGATOR')
            ->assertSee('CA1')
            ->assertSee('summary_totals[institutions_checked]', false)
            ->assertDontSee('Other Purpose')
            ->assertDontSee('Personal, Neighborhood &amp; Legal Findings', false)
            ->assertDontSee('Financial Institution Validation Checks')
            ->assertDontSee('id="personal-snapshot-court-background"', false)
            ->assertDontSee('personal-snapshot-living-with-parents');

        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="personal_snapshot-spouse_name"[^>]+required/', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+id="personal_snapshot-spouse_age"[^>]+required/', $response->getContent());

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('residenceStatuses.find((option) => option.checked)?.value', $javascript);
        $this->assertStringContainsString("residenceStatuses.forEach((option) => option.addEventListener('change', syncResidenceFields))", $javascript);
        $this->assertStringContainsString("['Mortgaged', 'Rented'].includes(status)", $javascript);
        $this->assertStringContainsString("status === 'Rented'", $javascript);
        $this->assertStringContainsString('[data-copy-present-address]', $javascript);
        $this->assertStringContainsString('const addressMatches = (typed, available)', $javascript);
        $this->assertStringContainsString('button.hidden = !matches', $javascript);
        $this->assertStringContainsString("addEventListener('input', syncAddressSuggestions)", $javascript);
        $this->assertStringContainsString('target.value = suggestion', $javascript);
        $this->assertStringContainsString('repeaterRowHasData', $javascript);
        $this->assertStringContainsString("replaceAll(',', '')", $javascript);
        $stylesheet = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.cibi-encoding-page { width: 100%; max-width: none; font-family: Inter, Arial, Helvetica, sans-serif;', $stylesheet);
        $this->assertStringContainsString('.cibi-encoding-page .ui-control,', $stylesheet);
        $this->assertStringContainsString('.cibi-encoding-paper .cibi-entry-table th', $stylesheet);
        $this->assertStringContainsString('.cibi-encoding-paper { width: 100%; max-width: none;', $stylesheet);
        $this->assertStringNotContainsString('width: min(100%, 76rem)', $stylesheet);
        $this->assertStringContainsString('.cibi-income-entry-table { width: 100%; min-width: 48rem;', $stylesheet);
        $this->assertStringContainsString('.cibi-bank-entry-table th:nth-child(6) { width: 25%; }', $stylesheet);
        $this->assertStringContainsString('.cibi-entry-table-wrap', $stylesheet);
        $this->assertStringContainsString('.cibi-signatory-role', $stylesheet);
        $this->assertStringContainsString('.cibi-excel-metadata > .cibi-party-risk-row { display: grid; grid-column: 1 / -1; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr);', $stylesheet);
        $this->assertStringNotContainsString('.cibi-encoding-signatory-name { min-height: 1.75rem; border-bottom:', $stylesheet);
        $this->assertStringContainsString('.cibi-signatories .ui-control { min-height: 1.3rem; border: 0;', $stylesheet);
        $this->assertStringContainsString('.cibi-section-note { margin: .55rem 1rem 0; color: var(--color-danger);', $stylesheet);
    }

    public function test_saved_residence_status_is_selected_in_the_single_choice_radio_group(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'personal_snapshot' => ['residence_status' => 'Rented'],
        ]);

        $html = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'type="radio" name="personal_snapshot[residence_status]"'));
        $this->assertMatchesRegularExpression('/name="personal_snapshot\[residence_status\]" value="Rented" checked/', $html);
        $this->assertSame(1, preg_match_all('/name="personal_snapshot\[residence_status\]" value="[^"]+" checked/', $html));
    }

    public function test_official_text_fields_totals_and_na_normalization_persist_without_deleting_legacy_rows(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'CANONICAL CLIENT']);
        $report = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'purpose_other' => 'Historical purpose']);
        $credit = $report->creditChecks()->create(['institution' => 'Historical Bank', 'sort_order' => 1]);
        $legal = $report->legalFindings()->create(['source_level' => 'barangay', 'result' => 'Clear', 'sort_order' => 1]);
        $payload = $this->payload();
        unset($payload['purpose_other'], $payload['credit_checks'], $payload['legal_findings']);
        $payload['personal_snapshot']['name'] = 'MANUALLY EDITED NAME';
        $payload['personal_snapshot']['length_of_stay_months'] = '5 years and 6 months';
        $payload['personal_snapshot']['previous_address'] = '';
        $payload['personal_snapshot']['previous_address_length_of_stay_months'] = 'Since 2021';
        $payload['personal_snapshot']['separated_year'] = '';
        $payload['personal_snapshot']['residence_status'] = 'Owned';
        $payload['personal_snapshot']['residence_status_from'] = 'STALE FROM';
        $payload['personal_snapshot']['monthly_rent'] = 'STALE RENT';
        $payload['personal_snapshot']['other_residences'] = 'STALE RESIDENCE';
        $payload['summary_totals'] = ['institutions_checked' => 8, 'institutions_declared' => 3, 'loan_records_found' => 4];
        $payload['bank_accounts'][0]['capital_share_text'] = 'Share capital only';
        $payload['loan_records'][0]['cycle_label'] = 'Renewal';
        $payload['loan_records'][0]['combined_findings'] = 'Prompt payer; no adverse findings';
        $payload['expected_revision'] = 1;

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)->assertOk()
            ->assertJsonPath('report.institutions_checked', 8)
            ->assertJsonPath('report.institutions_declared', 3)
            ->assertJsonPath('report.loan_records_found', 4);

        $saved = $report->fresh();
        // 'name' is manually editable — the submitted value is what persists into this report's
        // own snapshot, and the Client Folder's own display_name is never touched by it.
        $this->assertSame('MANUALLY EDITED NAME', $saved->personal_snapshot['name']);
        $this->assertSame('CANONICAL CLIENT', $folder->fresh()->display_name);
        $this->assertSame('5 years and 6 months', $saved->personal_snapshot['length_of_stay_months']);
        $this->assertNull($saved->personal_snapshot['previous_address']);
        $this->assertSame('Since 2021', $saved->personal_snapshot['previous_address_length_of_stay_months']);
        $this->assertNull($saved->personal_snapshot['separated_year']);
        $this->assertNull($saved->personal_snapshot['monthly_rent']);
        $this->assertNull($saved->personal_snapshot['residence_status_from']);
        $this->assertSame('STALE RESIDENCE', $saved->personal_snapshot['other_residences']);
        $this->assertSame('Historical purpose', $saved->purpose_other);
        $this->assertDatabaseHas('cibi_credit_checks', ['id' => $credit->id]);
        $this->assertDatabaseHas('cibi_legal_findings', ['id' => $legal->id]);
        $this->assertDatabaseHas('cibi_bank_accounts', ['cibi_report_id' => $saved->id, 'capital_share_text' => 'Share capital only']);
        $this->assertDatabaseHas('cibi_loan_records', ['cibi_report_id' => $saved->id, 'cycle_label' => 'Renewal', 'payment_performance' => 'Prompt payer; no adverse findings', 'remarks' => null]);
        $official = json_encode(app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('CANONICAL CLIENT', $official);
        $this->assertStringContainsString('N\/A', $official);
        $this->assertStringContainsString('Share capital only', $official);
        $this->assertStringContainsString('Renewal', $official);
    }

    public function test_residence_status_normalizes_from_and_rent_while_other_residences_remains_editable(): void
    {
        $ci = User::factory()->create();
        $mortgagedFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $mortgaged = $this->payload();
        $mortgaged['personal_snapshot']['residence_status'] = 'Mortgaged';
        $mortgaged['personal_snapshot']['residence_status_from'] = 'Community Bank since 2020';
        $mortgaged['personal_snapshot']['monthly_rent'] = 'STALE RENT';
        $mortgaged['personal_snapshot']['other_residences'] = 'Farm lot under mortgage';

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $mortgagedFolder), $mortgaged)->assertOk();
        $snapshot = $mortgagedFolder->cibiReport()->sole()->personal_snapshot;
        $this->assertSame('Community Bank since 2020', $snapshot['residence_status_from']);
        $this->assertNull($snapshot['monthly_rent']);
        $this->assertSame('Farm lot under mortgage', $snapshot['other_residences']);

        $rentedFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $rented = $this->payload();
        $rented['personal_snapshot']['residence_status'] = 'Rented';
        $rented['personal_snapshot']['residence_status_from'] = 'Landlord Reyes';
        $rented['personal_snapshot']['monthly_rent'] = '₱5,000 monthly';
        $rented['personal_snapshot']['other_residences'] = 'STALE RESIDENCE';

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $rentedFolder), $rented)->assertOk();
        $snapshot = $rentedFolder->cibiReport()->sole()->personal_snapshot;
        $this->assertSame('Landlord Reyes', $snapshot['residence_status_from']);
        $this->assertSame('₱5,000 monthly', $snapshot['monthly_rent']);
        $this->assertSame('STALE RESIDENCE', $snapshot['other_residences']);
    }

    public function test_living_with_parents_residence_status_disables_non_applicable_details(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        $payload['personal_snapshot']['residence_status'] = 'Living with Parents';
        $payload['personal_snapshot']['residence_status_from'] = 'STALE FROM';
        $payload['personal_snapshot']['monthly_rent'] = 'STALE RENT';
        $payload['personal_snapshot']['other_residences'] = '';

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)->assertOk();

        $snapshot = $folder->cibiReport()->sole()->personal_snapshot;
        $this->assertSame('Living with Parents', $snapshot['residence_status']);
        $this->assertNull($snapshot['residence_status_from']);
        $this->assertNull($snapshot['monthly_rent']);
        $this->assertNull($snapshot['other_residences']);
    }

    public function test_legacy_na_values_render_as_blank_encoding_controls(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $fields = ['spouse_name', 'present_address', 'residence_status_from', 'monthly_rent', 'length_of_stay_months', 'other_residences', 'previous_address', 'parents_address', 'previous_address_length_of_stay_months', 'separated_year', 'vehicles_owned', 'contact_details', 'other_remarks'];
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'personal_snapshot' => ['name' => $folder->display_name] + array_fill_keys($fields, 'N/A'),
        ]);

        $html = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()->getContent();
        foreach (['spouse_name', 'length_of_stay_months', 'other_residences', 'previous_address_length_of_stay_months', 'separated_year', 'contact_details'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="personal_snapshot\['.preg_quote($field, '/').'\]"[^>]*value=""[^>]*>/', $html);
        }
        foreach (['present_address', 'previous_address', 'parents_address', 'vehicles_owned'] as $field) {
            $this->assertMatchesRegularExpression('/<textarea[^>]*name="personal_snapshot\['.preg_quote($field, '/').'\]"[^>]*>\s*<\/textarea>/', $html);
        }
        $this->assertMatchesRegularExpression('/<input[^>]*name="personal_snapshot\[other_remarks\]"[^>]*value=""[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="purpose_remarks"[^>]*>\s*<\/textarea>/', $html);
        $this->assertSame(1, substr_count($html, 'cibi-inline-remarks'));
        $this->assertMatchesRegularExpression('/<input[^>]*id="personal-residence-from"[^>]*value=""[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="personal-monthly-rent"[^>]*value=""[^>]*>/', $html);
        $this->assertStringNotContainsString('placeholder="e.g. 2018 or N/A"', $html);
        $this->assertStringNotContainsString('data-present-address-suggestion>N/A', $html);
    }

    public function test_blank_optional_personal_fields_are_stored_as_null_not_na(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        $fields = ['residence_status_from', 'monthly_rent', 'length_of_stay_months', 'other_residences', 'previous_address', 'previous_address_length_of_stay_months', 'separated_year', 'court_background', 'vehicles_owned', 'contact_details', 'other_remarks'];
        $payload['personal_snapshot']['residence_status'] = 'Rented';
        $payload['amount_applied'] = null;
        $payload['purpose_codes'] = [];
        $payload['purpose_remarks'] = '';
        $payload['negative_credit_findings'] = '';
        $payload['other_remarks'] = '';
        $payload['noted_by_name'] = '';
        $payload['bank_accounts'][0]['adb_level_choice'] = null;
        $payload['bank_accounts'][0]['adb_level_figures'] = '';
        $payload['bank_accounts'][0]['capital_share_text'] = '';
        $payload['loan_records'][0]['cycle_label'] = '';
        $payload['loan_records'][0]['combined_findings'] = '';
        foreach ($fields as $field) {
            $payload['personal_snapshot'][$field] = '';
        }

        $this->actingAs($ci)->putJson(route('client-folders.cibi-report.update', $folder), $payload)->assertOk();

        $snapshot = $folder->cibiReport()->sole()->personal_snapshot;
        foreach ($fields as $field) {
            $this->assertNull($snapshot[$field], "$field should remain null in the encoding snapshot.");
        }
        $saved = $folder->cibiReport()->sole();
        $this->assertNull($saved->purpose_remarks);
        $this->assertNull($saved->negative_credit_findings);
        $this->assertNull($saved->other_remarks);
        $this->assertNull($saved->noted_by_name);
        $this->assertNull($saved->bankAccounts()->sole()->adb_level);
        $this->assertNull($saved->bankAccounts()->sole()->capital_share_text);
        $this->assertNull($saved->loanRecords()->sole()->cycle_label);
        $this->assertNull($saved->loanRecords()->sole()->payment_performance);
        $document = app(OfficialReportDataBuilder::class)->build($folder->fresh(), OfficialReportType::Cibi);
        $header = collect($document['header'])->mapWithKeys(fn ($row) => [$row[0] => $row[1]]);
        $this->assertSame('N/A', $header['Amount Applied']);
        $this->assertSame('N/A', $header['Purpose']);
        foreach ($fields as $field) {
            $this->assertSame('N/A', $document['cibi']['personal'][$field]);
        }
    }

    public function test_narrative_content_is_escaped_when_prefilled(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'other_remarks' => '<script>alert("x")</script>']);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("x")</script>', false);
    }

    /**
     * A payload() fixture has no idea which database ids its single-row sections were saved as —
     * without wiring them back in, resubmitting the exact same values would still look like a
     * brand-new row to create (no id), not an unchanged existing one.
     */
    private function withChildIds(array $payload, CibiReport $report): array
    {
        $ids = [
            'bank_accounts' => $report->bankAccounts()->orderBy('sort_order')->pluck('id')->all(),
            'loan_records' => $report->loanRecords()->orderBy('sort_order')->pluck('id')->all(),
            'credit_checks' => $report->creditChecks()->orderBy('sort_order')->pluck('id')->all(),
            'income_summaries' => $report->incomeSourceSummaries()->orderBy('sort_order')->pluck('id')->all(),
            'legal_findings' => $report->legalFindings()->orderBy('sort_order')->pluck('id')->all(),
        ];
        foreach ($ids as $section => $sectionIds) {
            foreach ($sectionIds as $index => $id) {
                $payload[$section][$index]['id'] = $id;
            }
        }

        return $payload;
    }

    private function payload(string $intent = 'complete'): array
    {
        return [
            'intent' => $intent, 'start_date' => '2026-07-03', 'submitted_date' => '2026-07-04', 'party_type' => 'borrower',
            'branch_name' => 'BLU TIN-AO', 'account_officer_name' => 'IRISH JANE ALBOR', 'amount_applied' => '340000.00',
            'ci_risk_level' => 'mid', 'purpose_codes' => ['building_construction_home_renovation'], 'purpose_other' => null,
            'summary_totals' => ['institutions_checked' => 1, 'institutions_declared' => 1, 'loan_records_found' => 1],
            'personal_snapshot' => [
                'name' => 'VALIDATED CLIENT NAME', 'age' => 42, 'spouse_name' => 'VALIDATED SPOUSE', 'spouse_age' => 40,
                'present_address' => 'Validated present address', 'length_of_stay_months' => 36, 'residence_status' => 'Owned', 'residence_status_from' => null,
                'monthly_rent' => null, 'living_with_parents' => false, 'other_residences' => 'One other residence',
                'home_condition' => 'New', 'number_of_storeys' => 2, 'material_cost_level' => 'Medium', 'living_condition' => 'Good',
                'previous_address' => 'Validated previous address', 'previous_address_length_of_stay_months' => 24,
                'parents_address' => 'Validated parents address', 'dependents_count' => 2, 'civil_status' => 'Married',
                'separated_year' => null, 'reputation' => 'Good', 'barangay_findings' => 'Validated barangay findings',
                'court_background_status' => 'No Legal Cases', 'court_background' => 'No adverse court record',
                'lifestyle' => 'Modest', 'vehicles_owned' => 'One vehicle', 'contact_details' => '09170000000 / client@example.test',
                'other_remarks' => 'Validated personal remarks',
            ],
            'purpose_remarks' => 'Confidential narrative about the purpose.', 'negative_credit_findings' => 'Confidential narrative findings.',
            'other_remarks' => 'Validated facts.', 'prepared_by_name' => 'Assigned Investigator', 'noted_by_name' => 'CA1',
            'bank_accounts' => [['institution' => 'Primary Bank', 'branch' => 'Main', 'year_opened' => 2020, 'adb_level_choice' => 'mid', 'adb_level_figures' => null, 'capital_share_amount' => '50000', 'capital_share_text' => 'CA 25,000 / SA 25,000', 'relevant_remarks' => 'Validated']],
            'loan_records' => [['institution' => 'Credit Cooperative', 'original_amount' => '100,000', 'remaining_balance' => '50,000', 'amortization_amount' => '5,000', 'granted_date' => '2026-01-01', 'maturity_date' => '2027-01-01', 'cycle_number' => 2, 'cycle_label' => '2nd Cycle', 'security_type' => 'Deposit', 'combined_findings' => 'Satisfactory / no adverse findings', 'payment_performance' => 'Satisfactory', 'remarks' => null]],
            'credit_checks' => [['institution' => 'Primary Bank', 'branch' => 'Main', 'is_declared' => '1', 'check_status' => 'Validated', 'checked_date' => '2026-07-03', 'key_information' => 'Account confirmed', 'remarks' => null]],
            'income_summaries' => [['source_name' => 'Retail Store', 'source_type' => 'Business', 'stability_result' => 'Strong capacity', 'validation_status' => 'Validated', 'key_information' => 'Business observed', 'monthly_amount' => '45000']],
            'legal_findings' => [['source_level' => 'Barangay', 'result' => 'No legal cases', 'details' => 'No adverse record', 'checked_at' => '2026-07-03']],
        ];
    }
}
