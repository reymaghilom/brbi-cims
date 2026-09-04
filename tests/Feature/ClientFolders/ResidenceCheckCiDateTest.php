<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveCibiReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PREFILL BEFORE SAVE, INDEPENDENT AFTER SAVE (same rule as Location — see
 * PersonAddressResolver/PersonCiDateResolver): a new Residence Check's CI Date field prefills
 * from the exact person's current CI/BI Start Date when one exists, but the field is always a
 * normal editable input the CI may change before saving. Once saved, the Residence Check's own
 * ci_date is its own authoritative snapshot — a later CI/BI Report save/update never rewrites it.
 */
class ResidenceCheckCiDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    /** A brand-new Residence Check always needs the required Residence Picture. */
    private function withPhoto(array $overrides = []): array
    {
        return array_merge(['photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)]], $overrides);
    }

    public function test_add_residence_check_prefills_the_cibi_start_date_with_no_warning(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-15']);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $response->assertSee('value="2026-01-15"', false);
        $response->assertDontSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
    }

    public function test_ci_date_field_is_always_rendered_as_an_editable_input(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-15']);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        // Unlike the old read-only display, the field is always a real, named, submittable input —
        // the same treatment Location already receives.
        $response->assertSee('name="ci_date"', false);
        $response->assertDontSee('readonly', false);
    }

    public function test_saving_a_new_residence_check_uses_the_explicitly_submitted_ci_date_over_cibi(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-02-10']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => '2026-02-01',
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('2026-02-01', $check->ci_date->toDateString());
    }

    public function test_a_residence_check_created_with_no_ci_date_input_falls_back_to_the_cibi_start_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-03-05']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('2026-03-05', $check->ci_date->toDateString());
    }

    public function test_residence_check_creation_requires_a_ci_date_when_no_cibi_start_date_exists_and_none_is_submitted(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())
            ->assertSessionHasErrors('ci_date');

        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_existing_applicant_cibi_without_its_start_date_still_requires_a_ci_date_input(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())
            ->assertSessionHasErrors('ci_date');
        $this->assertDatabaseCount('residence_checks', 0);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertRedirect();
        $this->assertDatabaseCount('residence_checks', 1);
    }

    /** Co-Maker Residence Check may now be created standalone with its own typed CI Date, exactly like Applicant — no CI/BI Report is required first. */
    public function test_co_maker_residence_check_can_be_created_with_its_own_ci_date_when_no_cibi_exists(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
        ]))->assertSessionHasErrors('ci_date');
        $this->assertDatabaseCount('residence_checks', 0);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
            'ci_date' => '2026-04-01',
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('2026-04-01', $check->ci_date->toDateString());
    }

    public function test_add_applicant_residence_without_cibi_shows_a_helper_hint_instead_of_a_blocking_warning(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $response->assertSee('name="ci_date"', false);
        $response->assertSee('No CI/BI Report exists yet for this person. Enter the CI Date directly.');
        $response->assertDontSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
    }

    public function test_add_co_maker_residence_without_cibi_shows_the_same_helper_hint(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);

        $response = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk();

        $response->assertSee('No CI/BI Report exists yet for this person. Enter the CI Date directly.');
        $response->assertDontSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
    }

    // ==================================================
    // Independence after save — the core reversal from the old sync behavior
    // ==================================================

    public function test_updating_the_applicant_start_date_via_cibi_report_does_not_change_the_saved_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('2026-01-10', $check->ci_date->toDateString());

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-04-20');

        $this->assertSame('2026-01-10', $check->fresh()->ci_date->toDateString());
    }

    public function test_updating_a_co_makers_cibi_start_date_does_not_change_that_co_makers_saved_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $cibi = $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-06-18', $coMaker->id);

        $this->assertSame('2026-01-10', $check->fresh()->ci_date->toDateString());
    }

    public function test_deleting_cibi_after_residence_save_does_not_alter_the_saved_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $location = $check->location;

        $cibi->delete();

        $this->assertSame('2026-01-10', $check->fresh()->ci_date->toDateString());
        $this->assertSame($location, $check->fresh()->location);
    }

    public function test_editing_remarks_on_an_existing_residence_check_never_resyncs_ci_date_to_a_changed_start_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-05-30');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'touch only remarks', 'location' => $check->location,
        ])->assertRedirect();

        $check->refresh();
        $this->assertSame('touch only remarks', $check->remarks);
        $this->assertSame('2026-01-10', $check->ci_date->toDateString());
    }

    public function test_ci_explicitly_editing_ci_date_on_an_existing_check_saves_the_new_value_independent_of_cibi(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'location' => $check->location, 'ci_date' => '2026-07-07',
        ])->assertRedirect();

        $this->assertSame('2026-07-07', $check->fresh()->ci_date->toDateString());
    }

    public function test_co_maker_ci_date_resolution_is_unaffected_by_the_applicant_cibi_lookup(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // An Applicant CI/BI Report with its own Start Date exists on the same folder — it must
        // never leak into a Co-Maker's resolved CI Date prefill.
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-01']);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Own Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-09-09']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('2026-09-09', $check->ci_date->toDateString());
    }

    public function test_saved_residence_check_survives_unchanged_photos_map_screenshot_remarks_and_location_after_a_cibi_update(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $photo = UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Keep me', 'photos' => [$photo],
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $storedPhotoPath = $check->photos()->firstOrFail()->path;
        $location = $check->location;
        $ciDate = $check->ci_date->toDateString();

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-10-15');

        $check->refresh();
        $this->assertSame($ciDate, $check->ci_date->toDateString());
        $this->assertSame('Keep me', $check->remarks);
        $this->assertSame($location, $check->location);
        $this->assertSame(1, $check->photos()->count());
        $this->assertSame($storedPhotoPath, $check->photos()->firstOrFail()->path);
    }

    public function test_residence_business_listing_keeps_showing_the_original_saved_ci_date_after_a_cibi_update(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('Jan 10, 2026');

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-11-25');

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('Jan 10, 2026')->assertDontSee('Nov 25, 2026');
    }

    public function test_completion_status_is_unaffected_by_an_unrelated_cibi_update(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $photo = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [$photo],
        ])->assertRedirect();

        $result = $folder->completionResults()->whereHas('rule', fn ($query) => $query->where('code', 'residence_business_report'))->firstOrFail();
        $this->assertTrue($result->is_satisfied);

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-12-01');

        $this->assertTrue($result->fresh()->is_satisfied);
    }

    public function test_a_second_residence_check_for_the_same_person_is_also_unaffected_by_a_cibi_update(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $checks = $folder->residenceChecks()->whereNull('co_maker_id')->get();
        $this->assertCount(2, $checks);

        $this->saveCibiStartDate($ci, $folder, $cibi, '2027-02-02');

        foreach ($checks as $check) {
            $this->assertSame('2026-01-10', $check->fresh()->ci_date->toDateString());
        }
    }

    /**
     * Saves a new start_date through the real SaveCibiReport action (never a raw model update).
     * $cibi must be freshly refreshed first so its `revision` reflects the DB, since Eloquent
     * doesn't backfill a column's DB default into an in-memory instance on create().
     */
    private function saveCibiStartDate(User $ci, ClientFolder $folder, CibiReport $cibi, string $startDate, ?int $coMakerId = null): void
    {
        $cibi->refresh();
        $this->app->make(SaveCibiReport::class)->execute($ci, $folder, [
            'co_maker_id' => $coMakerId,
            'start_date' => $startDate,
            'expected_revision' => $cibi->revision,
        ]);
    }
}
