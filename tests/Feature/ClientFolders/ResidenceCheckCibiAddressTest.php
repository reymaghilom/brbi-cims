<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveCibiReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Business rule: a Residence Report's Location always reflects the person's CURRENT authoritative
 * address (PersonAddressResolver) — never a frozen snapshot from when the check was first
 * encoded. Whenever that authoritative source changes (a CI/BI Report's present_address, Client
 * Information's fallback "present" address, or a Co-Maker's own address), every Residence Check
 * belonging to that exact person must pick up the new value automatically — no reopening or
 * re-saving the Residence Check required.
 */
class ResidenceCheckCibiAddressTest extends TestCase
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

    public function test_add_residence_check_shows_the_cibi_reports_present_address_with_no_warning(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Natumolan Tagoloan, Misamis Oriental'],
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $response->assertSee('Natumolan Tagoloan, Misamis Oriental');
        // Not "No address available" alone — that same phrase is also the Location field's
        // placeholder attribute, present in the raw markup regardless of whether it actually
        // renders (it only shows when the field has no value). The warning banner is the thing
        // that must not exist at all, so match its full sentence instead.
        $response->assertDontSee("Please update the applicant's address before creating a Residence Check.");
    }

    public function test_saving_a_new_residence_check_stores_the_cibi_present_address_and_ignores_a_forged_location(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Natumolan Tagoloan, Misamis Oriental'],
        ]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Forged Browser Address',
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Natumolan Tagoloan, Misamis Oriental', $check->location);
    }

    public function test_updating_applicant_present_address_via_cibi_report_syncs_the_existing_residence_check_without_reopening_it(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Natumolan Tagoloan, Misamis Oriental'],
        ]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Natumolan Tagoloan, Misamis Oriental', $check->location);

        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'Puerto, Cagayan de Oro City');

        // The Residence Check itself was never touched — its Location changed purely as a
        // consequence of the CI/BI Report save.
        $this->assertSame('Puerto, Cagayan de Oro City', $check->fresh()->location);
    }

    public function test_updating_remarks_on_an_existing_residence_check_still_syncs_location_to_the_current_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Original Address At Creation'],
        ]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        // The address changes out from under the Residence Check first...
        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'A Different Later Address');

        // ...then the CI edits only Remarks on the Residence Check itself.
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'remarks' => 'touch only remarks',
        ])->assertRedirect();

        $check->refresh();
        $this->assertSame('touch only remarks', $check->remarks);
        $this->assertSame('A Different Later Address', $check->location);
    }

    public function test_client_addresses_present_fallback_is_used_and_kept_synced_when_no_cibi_present_address_exists(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Fallback Address']);
        // CI Date resolution is independent of address resolution — a Start Date must exist
        // regardless of which address source is in play, so a CI/BI Report row is still needed
        // here even though it deliberately carries no present_address (that's the fallback under
        // test).
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Fallback Address', $check->location);

        // Correcting it through Client Information (the page that actually owns this fallback
        // source) must sync the existing Residence Check too — no CI/BI Report is involved here.
        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $this->clientInformationPayload('Corrected Fallback Address'))
            ->assertRedirect();

        $this->assertSame('Corrected Fallback Address', $check->fresh()->location);
    }

    public function test_cibi_present_address_wins_over_a_blank_client_addresses_present_row(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // The structured "present" address is explicitly blank here — this is the exact bug
        // scenario: client_addresses has nothing usable, but the CI/BI Report does.
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => '']);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Natumolan Tagoloan, Misamis Oriental'],
        ]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertRedirect();

        $this->assertSame('Natumolan Tagoloan, Misamis Oriental', $folder->residenceChecks()->firstOrFail()->location);
    }

    public function test_applicant_with_genuinely_no_present_address_anywhere_is_still_blocked(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // A CI/BI Report exists but its present_address was left as the literal "N/A" placeholder
        // — CibiReportFormData::for() treats that as blank for display, so the resolver must too.
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'personal_snapshot' => ['present_address' => 'N/A']]);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();
        $response->assertSee('No address available');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertSessionHasErrors('location');
        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_updating_a_co_makers_address_syncs_that_co_makers_existing_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'first_name' => 'Co', 'last_name' => 'Maker', 'address' => 'Tagoloan, Misamis Oriental']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(),
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('Tagoloan, Misamis Oriental', $check->location);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Co', 'last_name' => 'Maker', 'address' => 'Villanueva, Misamis Oriental',
        ])->assertRedirect();

        $this->assertSame('Villanueva, Misamis Oriental', $check->fresh()->location);
    }

    public function test_updating_one_co_makers_address_does_not_change_another_co_makers_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co Maker A', 'first_name' => 'A', 'last_name' => 'Maker', 'address' => 'Address A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co Maker B', 'first_name' => 'B', 'last_name' => 'Maker', 'address' => 'Address B']);
        $folder->cibiReports()->create(['co_maker_id' => $coMakerA->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $folder->cibiReports()->create(['co_maker_id' => $coMakerB->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMakerA->id, 'ci_date' => now()->toDateString()]))->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMakerB->id, 'ci_date' => now()->toDateString()]))->assertRedirect();
        $checkA = $folder->residenceChecks()->where('co_maker_id', $coMakerA->id)->firstOrFail();
        $checkB = $folder->residenceChecks()->where('co_maker_id', $coMakerB->id)->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMakerA->id, 'first_name' => 'A', 'last_name' => 'Maker', 'address' => 'Address A Updated',
        ])->assertRedirect();

        $this->assertSame('Address A Updated', $checkA->fresh()->location);
        $this->assertSame('Address B', $checkB->fresh()->location);
    }

    public function test_updating_the_applicant_address_does_not_affect_a_co_makers_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'personal_snapshot' => ['present_address' => 'Applicant Address']]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'first_name' => 'Co', 'last_name' => 'Maker', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['ci_date' => now()->toDateString()]))->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString()]))->assertRedirect();
        $applicantCheck = $folder->residenceChecks()->whereNull('co_maker_id')->firstOrFail();
        $coMakerCheck = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();

        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $this->clientInformationPayload('Some Other Applicant Address'))
            ->assertRedirect();

        $this->assertSame('Co-Maker Address', $coMakerCheck->fresh()->location);
        // The Applicant's own check may or may not move depending on whether a CI/BI Report
        // exists (it does here, so the fallback update alone shouldn't matter) — the point of
        // this test is strictly that the Co-Maker's check is untouched.
        $this->assertSame('Applicant Address', $applicantCheck->fresh()->location);
    }

    public function test_address_synchronization_does_not_touch_photos_map_screenshot_or_remarks(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'personal_snapshot' => ['present_address' => 'Original Address']]);
        $photo = UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500);
        $screenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(400);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'remarks' => 'Keep me', 'photos' => [$photo], 'map_screenshot' => $screenshot,
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $storedPhotoPath = $check->photos()->firstOrFail()->path;
        $storedScreenshotPath = $check->map_screenshot_path;

        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'Synchronized New Address');

        $check->refresh();
        $this->assertSame('Synchronized New Address', $check->location);
        $this->assertSame('Keep me', $check->remarks);
        $this->assertSame(1, $check->photos()->count());
        $this->assertSame($storedPhotoPath, $check->photos()->firstOrFail()->path);
        $this->assertSame($storedScreenshotPath, $check->map_screenshot_path);
        Storage::disk('local')->assertExists($storedPhotoPath);
        Storage::disk('local')->assertExists($storedScreenshotPath);
    }

    public function test_batch_preview_reflects_the_synchronized_current_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'personal_snapshot' => ['present_address' => 'Original Preview Address']]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['ci_date' => now()->toDateString()]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'Updated Preview Address');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()
            ->assertSee('Updated Preview Address')
            ->assertDontSee('Original Preview Address');
    }

    public function test_co_maker_address_resolution_is_unaffected_by_the_applicant_cibi_lookup(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // An Applicant CI/BI Report with its own present_address exists on the same folder —
        // it must never leak into a Co-Maker's resolved address.
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'personal_snapshot' => ['present_address' => 'Applicant CIBI Address']]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co Maker Person', 'address' => 'Co-Maker Own Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(),
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('Co-Maker Own Address', $check->location);
    }

    /**
     * Saves a new present_address through the real SaveCibiReport action (never a raw model
     * update, which would bypass the sync side effect this whole test class is verifying).
     * $cibi must be freshly created/fetched so its `revision` reflects the DB, since Eloquent
     * doesn't backfill a column's DB default into an in-memory instance on create().
     */
    private function saveCibiPresentAddress(User $ci, ClientFolder $folder, CibiReport $cibi, string $presentAddress): void
    {
        $cibi->refresh();
        $this->app->make(SaveCibiReport::class)->execute($ci, $folder, [
            'co_maker_id' => $cibi->co_maker_id,
            'personal_snapshot' => array_merge($cibi->personal_snapshot ?? [], ['present_address' => $presentAddress]),
            'expected_revision' => $cibi->revision,
        ]);
    }

    /** Minimal valid Client Information payload — only the "present" address content varies per test. */
    private function clientInformationPayload(string $presentAddressLine1): array
    {
        return [
            'first_name' => 'JUAN', 'last_name' => 'DELA CRUZ',
            'addresses' => ['present' => [
                'enabled' => '1', 'address_line_1' => $presentAddressLine1, 'is_primary' => '1',
            ]],
        ];
    }
}
