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
 * Business rule: a Residence Check's CI Date always reflects whichever "Start Date of CI" is
 * CURRENTLY authoritative on its person's own CI/BI Report (PersonCiDateResolver, scoped by the
 * same co_maker_id convention used everywhere else) — never a frozen snapshot from when the
 * check was first encoded. Whenever that Start Date changes, every Residence Check belonging to
 * that exact person must pick up the new value automatically — no reopening or re-saving the
 * Residence Check required. Mirrors ResidenceCheckCibiAddressTest's architecture and helper
 * pattern exactly, for the CI Date rule instead of the Location rule.
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

    public function test_add_residence_check_shows_the_cibi_start_date_with_no_warning(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-15']);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $response->assertSee('January 15, 2026');
        $response->assertDontSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
    }

    public function test_ci_date_field_is_rendered_read_only(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-15']);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        // The old editable version rendered a real form field named ci_date; the read-only
        // display intentionally carries no name attribute since its value is never submitted or
        // trusted — the server resolves it fresh regardless of what the client sends.
        $response->assertDontSee('name="ci_date"', false);
    }

    public function test_saving_a_new_residence_check_ignores_a_forged_ci_date_and_stores_the_authoritative_start_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-02-10']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => '1999-01-01',
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('2026-02-10', $check->ci_date->toDateString());
    }

    public function test_a_residence_check_can_be_created_with_no_ci_date_input_at_all(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-03-05']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('2026-03-05', $check->ci_date->toDateString());
    }

    public function test_residence_check_creation_is_blocked_when_the_applicant_has_no_cibi_start_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        // A CI/BI Report exists (so the address resolves fine) but has no Start Date yet.
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())
            ->assertSessionHasErrors('ci_date');

        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_residence_check_creation_is_blocked_when_the_co_maker_has_no_cibi_start_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
        ]))->assertSessionHasErrors('ci_date');

        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_add_residence_check_shows_missing_start_date_warning_and_management_link_for_applicant(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $response->assertSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
        $response->assertSee(route('client-folders.cibi-report.edit', $folder), false);
    }

    public function test_add_residence_check_shows_missing_start_date_management_link_scoped_to_the_specific_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);

        $response = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk();

        $response->assertSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
        $expectedLink = route('client-folders.cibi-report.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $response->assertSee(e($expectedLink), false);
    }

    public function test_updating_applicant_start_date_via_cibi_report_syncs_the_existing_residence_check_without_reopening_it(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('2026-01-10', $check->ci_date->toDateString());

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-04-20');

        // The Residence Check itself was never touched — its CI Date changed purely as a
        // consequence of the CI/BI Report save.
        $this->assertSame('2026-04-20', $check->fresh()->ci_date->toDateString());
    }

    public function test_updating_remarks_on_an_existing_residence_check_still_syncs_ci_date_to_the_current_start_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        // The Start Date changes out from under the Residence Check first...
        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-05-30');

        // ...then the CI edits only Remarks on the Residence Check itself.
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'touch only remarks',
        ])->assertRedirect();

        $check->refresh();
        $this->assertSame('touch only remarks', $check->remarks);
        $this->assertSame('2026-05-30', $check->ci_date->toDateString());
    }

    public function test_updating_a_co_makers_cibi_start_date_syncs_that_co_makers_existing_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $cibi = $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('2026-01-10', $check->ci_date->toDateString());

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-06-18', $coMaker->id);

        $this->assertSame('2026-06-18', $check->fresh()->ci_date->toDateString());
    }

    public function test_updating_one_co_makers_start_date_does_not_change_another_co_makers_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co Maker A', 'address' => 'Address A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co Maker B', 'address' => 'Address B']);
        $cibiA = $folder->cibiReports()->create(['co_maker_id' => $coMakerA->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $folder->cibiReports()->create(['co_maker_id' => $coMakerB->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-11']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMakerA->id]))->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMakerB->id]))->assertRedirect();
        $checkA = $folder->residenceChecks()->where('co_maker_id', $coMakerA->id)->firstOrFail();
        $checkB = $folder->residenceChecks()->where('co_maker_id', $coMakerB->id)->firstOrFail();

        $this->saveCibiStartDate($ci, $folder, $cibiA, '2026-07-01', $coMakerA->id);

        $this->assertSame('2026-07-01', $checkA->fresh()->ci_date->toDateString());
        $this->assertSame('2026-01-11', $checkB->fresh()->ci_date->toDateString());
    }

    public function test_updating_the_applicant_start_date_does_not_affect_a_co_makers_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $applicantCibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-11']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto())->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMaker->id]))->assertRedirect();
        $applicantCheck = $folder->residenceChecks()->whereNull('co_maker_id')->firstOrFail();
        $coMakerCheck = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();

        $this->saveCibiStartDate($ci, $folder, $applicantCibi, '2026-08-08');

        $this->assertSame('2026-08-08', $applicantCheck->fresh()->ci_date->toDateString());
        $this->assertSame('2026-01-11', $coMakerCheck->fresh()->ci_date->toDateString());
    }

    public function test_co_maker_ci_date_resolution_is_unaffected_by_the_applicant_cibi_lookup(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // An Applicant CI/BI Report with its own Start Date exists on the same folder — it must
        // never leak into a Co-Maker's resolved CI Date.
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-01']);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Own Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => '2026-09-09']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('2026-09-09', $check->ci_date->toDateString());
    }

    public function test_ci_date_synchronization_does_not_touch_photos_map_screenshot_remarks_or_location(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $photo = UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500);
        Storage::fake('local');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Keep me', 'photos' => [$photo],
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $storedPhotoPath = $check->photos()->firstOrFail()->path;
        $location = $check->location;

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-10-15');

        $check->refresh();
        $this->assertSame('2026-10-15', $check->ci_date->toDateString());
        $this->assertSame('Keep me', $check->remarks);
        $this->assertSame($location, $check->location);
        $this->assertSame(1, $check->photos()->count());
        $this->assertSame($storedPhotoPath, $check->photos()->firstOrFail()->path);
    }

    public function test_residence_business_listing_reflects_the_synchronized_current_ci_date(): void
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
            ->assertOk()->assertSee('Nov 25, 2026')->assertDontSee('Jan 10, 2026');
    }

    public function test_completion_status_is_unaffected_by_ci_date_synchronization(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-10']);
        $photo = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);
        Storage::fake('local');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [$photo],
        ])->assertRedirect();

        $result = $folder->completionResults()->whereHas('rule', fn ($query) => $query->where('code', 'residence_business_report'))->firstOrFail();
        $this->assertTrue($result->is_satisfied);

        $this->saveCibiStartDate($ci, $folder, $cibi, '2026-12-01');

        $this->assertTrue($result->fresh()->is_satisfied);
    }

    public function test_a_second_residence_check_for_the_same_person_is_also_synchronized(): void
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
            $this->assertSame('2027-02-02', $check->fresh()->ci_date->toDateString());
        }
    }

    /**
     * Saves a new start_date through the real SaveCibiReport action (never a raw model update,
     * which would bypass the sync side effect this whole test class is verifying). $cibi must be
     * freshly created/fetched so its `revision` reflects the DB, since Eloquent doesn't backfill a
     * column's DB default into an in-memory instance on create().
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
