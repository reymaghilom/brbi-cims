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

/** Applicant Residence Location is editable report data; address sources only prefill new forms. */
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

    public function test_add_residence_check_prefills_cibi_present_address_in_an_editable_location_field(): void
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
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $location = (new \DOMXPath($document))->query("//*[@id='residence-location']")->item(0);
        $this->assertSame('location', $location->getAttribute('name'));
        $this->assertSame('Natumolan Tagoloan, Misamis Oriental', $location->getAttribute('value'));
        $this->assertFalse($location->hasAttribute('readonly'));
        $this->assertTrue($location->hasAttribute('required'));
    }

    public function test_new_applicant_residence_saves_the_cis_edited_prefilled_location(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Natumolan Tagoloan, Misamis Oriental'],
        ]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Residence CI Corrected Address',
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Residence CI Corrected Address', $check->location);
        $this->assertSame('Natumolan Tagoloan, Misamis Oriental', $folder->cibiReport->personal_snapshot['present_address']);
    }

    public function test_updating_cibi_present_address_does_not_overwrite_saved_residence_location(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Natumolan Tagoloan, Misamis Oriental'],
        ]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Saved Residence Location',
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Saved Residence Location', $check->location);

        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'Puerto, Cagayan de Oro City');

        $this->assertSame('Saved Residence Location', $check->fresh()->location);
        $this->assertSame('Puerto, Cagayan de Oro City', $cibi->fresh()->personal_snapshot['present_address']);
    }

    public function test_existing_applicant_residence_location_is_editable_and_updates_without_rewriting_cibi(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'personal_snapshot' => ['present_address' => 'Original Address At Creation'],
        ]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Original Address At Creation',
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'residence_check.created',
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk();
        $response->assertDontSee('Created by:')
            ->assertDontSee('Last updated by:');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $location = (new \DOMXPath($document))->query("//*[@id='residence-location']")->item(0);
        $this->assertFalse($location->hasAttribute('readonly'));
        $this->assertSame('Original Address At Creation', $location->getAttribute('value'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Residence Edit Corrected Address',
            'remarks' => 'updated with location',
        ])->assertRedirect();

        $check->refresh();
        $this->assertSame('updated with location', $check->remarks);
        $this->assertSame('Residence Edit Corrected Address', $check->location);
        $this->assertSame('Original Address At Creation', $cibi->fresh()->personal_snapshot['present_address']);
        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'residence_check.updated',
        ]);
    }

    public function test_client_present_address_prefills_new_residence_but_does_not_overwrite_it_later(): void
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
            'ci_date' => now()->toDateString(), 'location' => 'Fallback Address',
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Fallback Address', $check->location);

        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $this->clientInformationPayload('Corrected Fallback Address'))
            ->assertRedirect();

        $this->assertSame('Fallback Address', $check->fresh()->location);
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
            'ci_date' => now()->toDateString(), 'location' => 'Natumolan Tagoloan, Misamis Oriental',
        ]))->assertRedirect();

        $this->assertSame('Natumolan Tagoloan, Misamis Oriental', $folder->residenceChecks()->firstOrFail()->location);
    }

    public function test_applicant_with_no_present_address_can_enter_and_save_the_initial_location(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();
        $response->assertSee('name="location"', false)
            ->assertSee('required maxlength="2000"', false)
            ->assertDontSee("Please update the applicant's address before creating a Residence Check.");

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertSessionHasErrors('location');
        $this->assertDatabaseCount('residence_checks', 0);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
            'location' => 'Manually Verified Applicant Residence',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Manually Verified Applicant Residence', $folder->residenceChecks()->firstOrFail()->location);
    }

    public function test_co_maker_with_no_address_remains_blocked_and_cannot_forge_a_manual_location(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co Maker Without Address']);
        $folder->cibiReports()->create([
            'co_maker_id' => $coMaker->id,
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
        ]);

        $this->actingAs($ci)->get(route('client-folders.residence-checks.create', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk()
            ->assertSee("Please update the co-maker's address before creating a Residence Check.")
            ->assertDontSee('name="location"', false);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Forged Co-Maker Location',
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

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]))->assertRedirect();
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

    public function test_cibi_address_update_does_not_touch_saved_residence_or_its_media(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'personal_snapshot' => ['present_address' => 'Original Address']]);
        $photo = UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500);
        $screenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(400);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Original Address', 'remarks' => 'Keep me', 'photos' => [$photo], 'map_screenshot' => $screenshot,
        ])->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $storedPhotoPath = $check->photos()->firstOrFail()->path;
        $storedScreenshotPath = $check->map_screenshot_path;

        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'Synchronized New Address');

        $check->refresh();
        $this->assertSame('Original Address', $check->location);
        $this->assertSame('Keep me', $check->remarks);
        $this->assertSame(1, $check->photos()->count());
        $this->assertSame($storedPhotoPath, $check->photos()->firstOrFail()->path);
        $this->assertSame($storedScreenshotPath, $check->map_screenshot_path);
        Storage::disk('local')->assertExists($storedPhotoPath);
        Storage::disk('local')->assertExists($storedScreenshotPath);
    }

    public function test_batch_preview_keeps_the_saved_residence_location_after_cibi_changes(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $cibi = $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'personal_snapshot' => ['present_address' => 'Original Preview Address']]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Original Preview Address',
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->saveCibiPresentAddress($ci, $folder, $cibi, 'Updated Preview Address');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()
            ->assertSee('Original Preview Address')
            ->assertDontSee('Updated Preview Address');
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
