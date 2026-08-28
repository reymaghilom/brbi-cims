<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResidenceBusinessReportTest extends TestCase
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

    public function test_access_is_shared_across_ci_and_administrator_but_deleted_folders_are_unavailable(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $this->actingAs($admin)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $this->actingAs($other)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $folder->delete();
        $this->actingAs($admin)->get(route('client-folders.residence-business.edit', $folder->id))->assertNotFound();
    }

    public function test_the_old_documentation_section_workflow_is_gone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()
            ->assertSee('Residence Check')
            ->assertSee('Business Checks')
            ->assertDontSee('Documentation Sections')
            ->assertDontSee('Report Header')
            ->assertDontSee('Overall Findings')
            ->assertDontSee('Save and Mark Complete');
    }

    public function test_residence_check_can_be_saved_with_a_photo_and_then_updated_in_place(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $photo = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(),
            'location' => 'Applicant Address',
            'remarks' => 'Residence confirmed',
            'photos' => [$photo],
        ])->assertRedirect();

        $this->assertDatabaseCount('residence_checks', 1);
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame($ci->id, $check->ci_user_id);
        $this->assertSame(1, $check->photos()->count());

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Applicant Address',
            'remarks' => 'Updated remarks',
        ])->assertRedirect();

        $this->assertDatabaseCount('residence_checks', 1);
        $this->assertSame('Updated remarks', $check->fresh()->remarks);
    }

    public function test_residence_check_tracks_updated_by_on_every_save_distinct_from_the_fixed_creator(): void
    {
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        $folder = $this->folderFor($creator);

        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]))->assertRedirect();
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame($creator->id, $check->ci_user_id);
        $this->assertSame($creator->id, $check->updated_by);

        $this->actingAs($editor)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remarks' => 'Updated by another CI',
        ])->assertRedirect();
        $check->refresh();
        $this->assertSame($creator->id, $check->ci_user_id);
        $this->assertSame($editor->id, $check->updated_by);
    }

    public function test_residence_check_concurrent_save_with_stale_updated_at_is_rejected(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]));
        $check = $folder->residenceChecks()->firstOrFail();
        $staleTimestamp = $check->updated_at->toISOString();

        $this->travel(1)->minutes();
        $this->actingAs($other)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'remarks' => 'First save wins', 'expected_updated_at' => $staleTimestamp,
        ])->assertRedirect();

        $this->actingAs($ci)->postJson(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'remarks' => 'Stale save should be rejected', 'expected_updated_at' => $staleTimestamp,
        ])->assertStatus(422)->assertJsonValidationErrors('expected_updated_at');
    }

    public function test_residence_photo_stores_the_actual_authenticated_uploader(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $photo = UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$photo],
        ])->assertRedirect();

        $stored = $folder->residenceChecks()->firstOrFail()->photos()->firstOrFail();
        $this->assertSame($ci->id, $stored->uploaded_by);
        $this->assertNotNull($stored->created_at);
    }

    public function test_business_photo_stores_the_actual_authenticated_uploader(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $photo = UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan', 'business_photos' => [$photo],
        ])->assertRedirect();

        $stored = $folder->businessChecks()->firstOrFail()->photos()->firstOrFail();
        $this->assertSame($ci->id, $stored->uploaded_by);
        $this->assertNotNull($stored->created_at);
    }

    public function test_another_ci_uploading_a_second_photo_records_their_own_identity_separately(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $folder = $this->folderFor($first);
        $firstPhoto = UploadedFile::fake()->image('First.jpg', 900, 700)->size(500);
        $secondPhoto = UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500);

        $this->actingAs($first)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$firstPhoto],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($second)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$secondPhoto],
        ]);

        $photos = $check->photos()->orderBy('id')->get();
        $this->assertCount(2, $photos);
        $this->assertSame($first->id, $photos[0]->uploaded_by);
        $this->assertSame($second->id, $photos[1]->uploaded_by);
    }

    public function test_photo_upload_does_not_alter_cibi_signatory(): void
    {
        $signatory = User::factory()->create();
        $photographer = User::factory()->create();
        $folder = $this->folderFor($signatory);
        $this->actingAs($signatory)->put(route('client-folders.cibi-report.update', $folder), $this->cibiPayload());
        $report = $folder->cibiReport()->firstOrFail();
        $this->assertSame($signatory->id, $report->ci_in_charge_id);

        $photo = UploadedFile::fake()->image('Evidence.jpg', 900, 700)->size(500);
        $this->actingAs($photographer)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$photo],
        ]);

        $this->assertSame($signatory->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_residence_check_supports_multiple_ci_contributors(): void
    {
        $ci = User::factory()->create();
        $contributorA = User::factory()->create();
        $contributorB = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]));
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->put(route('client-folders.residence-checks.contributors.update', [$folder, $check]), [
            'contributor_ids' => [$contributorA->id, $contributorB->id],
        ])->assertRedirect();

        $this->assertSame([$contributorA->id, $contributorB->id], $check->contributors()->orderBy('users.id')->pluck('users.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'residence_check.contributor_added', 'client_folder_id' => $folder->id]);
    }

    public function test_business_check_supports_multiple_ci_contributors(): void
    {
        $ci = User::factory()->create();
        $contributorA = User::factory()->create();
        $contributorB = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ]);
        $check = $folder->businessChecks()->firstOrFail();

        $this->actingAs($ci)->put(route('client-folders.business-checks.contributors.update', [$folder, $check]), [
            'contributor_ids' => [$contributorA->id, $contributorB->id],
        ])->assertRedirect();

        $this->assertSame([$contributorA->id, $contributorB->id], $check->contributors()->orderBy('users.id')->pluck('users.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_check.contributor_added', 'client_folder_id' => $folder->id]);
    }

    public function test_contributor_assignment_does_not_hide_the_check_from_another_ci(): void
    {
        $ci = User::factory()->create();
        $contributor = User::factory()->create();
        $other = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]));
        $check = $folder->residenceChecks()->firstOrFail();
        $this->actingAs($ci)->put(route('client-folders.residence-checks.contributors.update', [$folder, $check]), [
            'contributor_ids' => [$contributor->id],
        ]);

        $this->actingAs($other)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk();
    }

    public function test_residence_and_business_checks_are_isolated_between_applicant_and_each_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerOne = $folder->coMakers()->create(['full_name' => 'Co Maker One', 'address' => 'Co-Maker One Address']);
        $coMakerTwo = $folder->coMakers()->create(['full_name' => 'Co Maker Two', 'address' => 'Co-Maker Two Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMakerOne->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $folder->cibiReports()->create(['co_maker_id' => $coMakerTwo->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ]))->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMakerOne->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker One Address',
        ]))->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMakerTwo->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Two Address',
        ]))->assertRedirect();

        $this->assertSame(1, $folder->residenceChecks()->where('co_maker_id', null)->count());
        $this->assertSame(1, $folder->residenceChecks()->where('co_maker_id', $coMakerOne->id)->count());
        $this->assertSame(1, $folder->residenceChecks()->where('co_maker_id', $coMakerTwo->id)->count());

        $applicantResponse = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $applicantResponse->assertSee('Applicant Address')->assertDontSee('Co-Maker One Address')->assertDontSee('Co-Maker Two Address');

        $coMakerOneResponse = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder).'?person=co-maker&co_maker_id='.$coMakerOne->id)->assertOk();
        $coMakerOneResponse->assertSee('Co-Maker One Address')->assertDontSee('Applicant Address')->assertDontSee('Co-Maker Two Address');
    }

    public function test_a_check_from_another_client_folder_cannot_be_edited_or_deleted(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $foreignCheck = $otherFolder->residenceChecks()->create(['ci_date' => now(), 'location' => 'Foreign Address', 'ci_user_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $foreignCheck]))->assertNotFound();
        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $foreignCheck]))->assertNotFound();
        $this->assertDatabaseHas('residence_checks', ['id' => $foreignCheck->id]);
    }

    public function test_business_check_requires_a_dedicated_saved_business_owned_by_the_active_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $otherFolder = $this->folderFor($ci);
        $foreignSource = $this->businessSource($otherFolder, 'Foreign Store', 'Elsewhere');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $foreignSource->id,
            'ci_date' => now()->toDateString(),
        ])->assertSessionHasErrors('income_source_id');

        $photo = UploadedFile::fake()->image('Store Front.jpg', 900, 700)->size(500);
        $competitor = UploadedFile::fake()->image('Competitor.jpg', 900, 700)->size(500);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [$photo],
            'competitor_photos' => [$competitor],
            'competitor_remarks' => 'Two nearby competitors observed.',
        ])->assertRedirect(route('client-folders.residence-business.edit', $folder));

        $this->assertDatabaseCount('business_checks', 1);
        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame(1, $check->businessPhotos()->count());
        $this->assertSame(1, $check->competitorPhotos()->count());
    }

    public function test_deleting_a_check_removes_its_stored_photos(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $photo = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$photo],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $storedPath = $check->photos()->firstOrFail()->path;
        Storage::disk('local')->assertExists($storedPath);

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $check]))->assertRedirect();

        $this->assertDatabaseMissing('residence_checks', ['id' => $check->id]);
        $this->assertDatabaseCount('residence_check_photos', 0);
        Storage::disk('local')->assertMissing($storedPath);
    }

    public function test_residence_check_location_is_resolved_server_side_and_a_manipulated_value_is_ignored(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Attacker-Controlled Fake Address',
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('Applicant Address', $check->location);
    }

    public function test_residence_check_creation_is_blocked_when_the_person_has_no_saved_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(),
        ]))->assertSessionHasErrors('location');

        $this->assertDatabaseCount('residence_checks', 0);

        $coMaker = $folder->coMakers()->create(['full_name' => 'No Address Co-Maker']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(),
        ]))->assertSessionHasErrors('location');

        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_residence_check_map_screenshot_can_be_uploaded_replaced_and_removed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $screenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(400);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'map_screenshot' => $screenshot,
        ]))->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertTrue($check->hasMapScreenshot());
        $this->assertSame($ci->id, $check->map_screenshot_uploaded_by);
        $firstPath = $check->map_screenshot_path;
        Storage::disk('local')->assertExists($firstPath);

        $this->actingAs($ci)->get(route('client-folders.residence-checks.map-screenshot', [$folder, $check]))->assertOk();

        $replacement = UploadedFile::fake()->image('Map2.png', 800, 600)->size(400);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'map_screenshot' => $replacement,
        ])->assertRedirect();

        $check->refresh();
        $this->assertNotSame($firstPath, $check->map_screenshot_path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($check->map_screenshot_path);

        $secondPath = $check->map_screenshot_path;
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remove_map_screenshot' => '1',
        ])->assertRedirect();

        $check->refresh();
        $this->assertFalse($check->hasMapScreenshot());
        Storage::disk('local')->assertMissing($secondPath);
    }

    public function test_business_check_map_screenshot_can_be_uploaded_replaced_and_removed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $screenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(400);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan', 'map_screenshot' => $screenshot,
        ])->assertRedirect();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertTrue($check->hasMapScreenshot());
        $this->assertSame($ci->id, $check->map_screenshot_uploaded_by);
        $firstPath = $check->map_screenshot_path;
        Storage::disk('local')->assertExists($firstPath);

        $this->actingAs($ci)->get(route('client-folders.business-checks.map-screenshot', [$folder, $check]))->assertOk();

        $replacement = UploadedFile::fake()->image('Map2.png', 800, 600)->size(400);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id, 'income_source_id' => $source->id, 'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan', 'map_screenshot' => $replacement,
        ])->assertRedirect();

        $check->refresh();
        $this->assertNotSame($firstPath, $check->map_screenshot_path);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($check->map_screenshot_path);

        $secondPath = $check->map_screenshot_path;
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id, 'income_source_id' => $source->id, 'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan', 'remove_map_screenshot' => '1',
        ])->assertRedirect();

        $check->refresh();
        $this->assertFalse($check->hasMapScreenshot());
        Storage::disk('local')->assertMissing($secondPath);
    }

    public function test_business_check_official_output_shows_the_map_screenshot_never_the_raw_filename(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $photo = UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500);
        $screenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(400);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan', 'business_photos' => [$photo], 'map_screenshot' => $screenshot,
        ])->assertRedirect();
        $businessCheck = $folder->businessChecks()->firstOrFail();
        $this->assertTrue($businessCheck->hasMapScreenshot());

        $batchPreview = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'business_check_ids' => [$businessCheck->id],
        ])->assertOk();
        $batchPreview->assertSee('Residence & Business Checks - '.$folder->display_name)
            ->assertDontSee('Created by:')
            ->assertDontSee('Last updated by:');

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('Sari-Sari Store')->assertSee('Google Map')
            ->assertSee(route('client-folders.business-checks.map-screenshot', [$folder, $businessCheck]), false)
            ->assertDontSee($businessCheck->map_screenshot_file_name);

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'business_check_ids' => [$businessCheck->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$businessCheck->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_business_check_official_output_omits_the_google_map_page_when_no_screenshot_is_saved(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ]);
        $businessCheck = $folder->businessChecks()->firstOrFail();
        $this->assertFalse($businessCheck->hasMapScreenshot());

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('Sari-Sari Store')->assertDontSee('Google Map');
    }

    public function test_residence_checks_listing_is_table_based_and_matches_the_business_check_table_design(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        // Both tables share the exact same sortable-table/row/actions-menu markup pattern —
        // two independent instances of it, not a bespoke Residence layout.
        $this->assertSame(2, substr_count($content, 'data-check-sort-table'));
        $this->assertStringNotContainsString('data-residence-check-select]:checked]:border-brand-primary', $content);
        $this->assertStringContainsString('has-[[data-residence-check-select]:checked]:bg-brand-soft/40', $content);
        $this->assertStringContainsString('Residence Check actions', $content);
        $this->assertStringContainsString('Business Check actions', $content);
    }

    public function test_multiple_residence_checks_for_the_same_applicant_appear_as_separate_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remarks' => 'First residence check',
            'photos' => [UploadedFile::fake()->image('First.jpg', 900, 700)->size(500)],
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remarks' => 'Second residence check',
            'photos' => [UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500)],
        ])->assertRedirect();

        // Two independent rows, never merged/overwritten into one.
        $checks = $folder->residenceChecks()->whereNull('co_maker_id')->get();
        $this->assertCount(2, $checks);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();
        $this->assertSame(2, substr_count($content, 'data-residence-check-select value='));
        foreach ($checks as $check) {
            $this->assertStringContainsString('data-residence-check-select value="'.$check->id.'"', $content);
        }
    }

    public function test_multiple_residence_checks_for_the_same_co_maker_appear_as_separate_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address', 'remarks' => 'Co-maker check one',
            'photos' => [UploadedFile::fake()->image('First.jpg', 900, 700)->size(500)],
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address', 'remarks' => 'Co-maker check two',
            'photos' => [UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500)],
        ])->assertRedirect();

        $checks = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->get();
        $this->assertCount(2, $checks);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-business.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();
        $this->assertSame(2, substr_count($content, 'data-residence-check-select value='));
        foreach ($checks as $check) {
            $this->assertStringContainsString('data-residence-check-select value="'.$check->id.'"', $content);
        }
    }

    public function test_residence_table_sortable_headers_have_up_and_down_arrow_controls(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        foreach (['ci_date', 'location', 'ci'] as $column) {
            $this->assertStringContainsString('data-sort-key="'.$column.'" data-sort-type=', $content);
            $this->assertMatchesRegularExpression('/data-sort-btn[^>]*data-sort-key="'.$column.'"[^>]*data-sort-dir="asc"/', $content);
            $this->assertMatchesRegularExpression('/data-sort-btn[^>]*data-sort-key="'.$column.'"[^>]*data-sort-dir="desc"/', $content);
        }
        // No visible Person column — intentionally removed, matching Business Checks.
        $this->assertStringNotContainsString('>Person<', $content);
    }

    public function test_residence_report_ci_shows_first_name_only(): void
    {
        $ci = User::factory()->create(['full_name' => 'REASAN MAGHILOM']);
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $residenceCheck = $folder->residenceChecks()->firstOrFail();

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('REASAN')->assertDontSee('REASAN MAGHILOM');
    }

    public function test_residence_report_shows_remarks_only_when_present_and_omits_it_entirely_when_blank(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remarks' => 'Gate was locked, neighbor confirmed residency.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $withRemarks = $folder->residenceChecks()->firstOrFail();

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('Gate was locked, neighbor confirmed residency.');
        $this->assertMatchesRegularExpression('/Subject:.*Residence Check.*Remarks:/s', $preview->getContent());

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $withRemarks]));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front2.jpg', 900, 700)->size(500)],
        ]);

        $noRemarksPreview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $noRemarksPreview->assertDontSee('Remarks:');
    }

    public function test_each_residence_check_header_renders_once_while_continuation_pages_only_render_remaining_media(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $photo = fn (string $name) => [
            'caption' => null, 'media_type' => 'photo', 'image_path' => '/'.$name, 'web_url' => '/'.$name,
        ];
        $sections = [
            [
                'category' => 'Residence', 'party_label' => 'Applicant Name', 'subject' => 'TEST APPLICANT',
                'location' => 'FIRST LOCATION', 'heading' => 'Residence Check', 'remarks' => 'UNIQUE FIRST REMARKS',
                'ci_date' => 'August 28, 2026', 'ci' => 'Rey',
                'media' => [$photo('first.jpg'), $photo('second.jpg'), $photo('third.jpg')],
                'google_map' => ['image_path' => '/map.jpg', 'web_url' => '/map.jpg'],
            ],
            [
                'category' => 'Residence', 'party_label' => 'Applicant Name', 'subject' => 'TEST APPLICANT',
                'location' => 'SECOND LOCATION', 'heading' => 'Residence Check', 'remarks' => 'UNIQUE SECOND REMARKS',
                'ci_date' => 'August 29, 2026', 'ci' => 'Rey',
                'media' => [$photo('fourth.jpg')], 'google_map' => null,
            ],
        ];

        foreach ([false, true] as $pdfMode) {
            $html = view('reports.official.residence-business-check-batch', [
                'photoSections' => $sections, 'pdfMode' => $pdfMode, 'title' => 'Residence - TEST APPLICANT',
                'clientFolder' => $folder, 'personParams' => [],
            ])->render();

            $this->assertSame(1, substr_count($html, 'UNIQUE FIRST REMARKS'));
            $this->assertSame(1, substr_count($html, 'UNIQUE SECOND REMARKS'));
            $this->assertSame(2, substr_count($html, '<table class="residence-header">'));
            foreach (['first.jpg', 'second.jpg', 'third.jpg', 'fourth.jpg', 'map.jpg'] as $name) {
                $this->assertSame(1, substr_count($html, '/'.$name));
            }
        }
    }

    public function test_google_map_page_is_always_the_last_section_for_a_residence_check_with_a_screenshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [
                UploadedFile::fake()->image('First.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Third.jpg', 900, 700)->size(500),
            ],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $content = $preview->getContent();

        // The LAST "photo-report-page" section in the whole document must be the Google Map
        // one — proving nothing (no continuation page, no other section) was rendered after it.
        preg_match_all('/<section class="photo-page official-report-page photo-report-page">/', $content, $matches, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($matches[0]);
        $lastSectionStart = end($matches[0])[1];
        $this->assertStringContainsString('Google Map', substr($content, $lastSectionStart));
    }

    public function test_residence_pictures_and_map_screenshot_render_without_a_frame_border(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('photo-frame-plain', false);
    }

    public function test_residence_check_download_pdf_button_appears_before_download_word(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        // Per-row Residence Check actions menu: PDF before Word.
        $rowPdfPos = strpos($content, 'data-check-row-pdf-submit data-check-kind="residence"');
        $rowDocxPos = strpos($content, 'data-check-row-docx-submit data-check-kind="residence"');
        $this->assertNotFalse($rowPdfPos);
        $this->assertNotFalse($rowDocxPos);
        $this->assertLessThan($rowDocxPos, $rowPdfPos);

        // Batch "Download Selected" toolbar: PDF before Word too.
        $batchPdfPos = strpos($content, 'data-check-batch-pdf-submit');
        $batchDocxPos = strpos($content, 'data-check-batch-docx-submit');
        $this->assertNotFalse($batchPdfPos);
        $this->assertNotFalse($batchDocxPos);
        $this->assertLessThan($batchDocxPos, $batchPdfPos);
    }

    public function test_open_google_maps_action_is_visible_near_the_map_screenshot_section(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('No map screenshot yet?', $content);
        $this->assertMatchesRegularExpression('/Open Google Maps/', $content);
        $expectedUrl = htmlspecialchars('https://www.google.com/maps/search/?api=1&query='.urlencode('Applicant Address'));
        $this->assertStringContainsString('href="'.$expectedUrl.'"', $content);
        $this->assertStringContainsString('target="_blank"', $content);
        $this->assertStringContainsString('rel="noopener noreferrer"', $content);
        $this->assertStringNotContainsString('key=', $content);
    }

    public function test_web_preview_shows_page_indicators_and_pdf_docx_do_not(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('official-report-page', $preview);
        $this->assertStringContainsString('counter(brbi-page)', $preview);
        $this->assertStringContainsString('"Page "', $preview);

        // The page-number CSS is a Web Preview-only concern — it must never leak into PDF/DOCX.
        $pdf = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();
        $this->assertStringNotContainsString('counter(brbi-page)', $pdf->streamedContent());

        $docx = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();
        $this->assertStringNotContainsString('brbi-page', $docx->streamedContent());
    }

    /**
     * The date/time, page title, and URL a CI sometimes sees on a printed page are Chrome's own
     * "Headers and footers" print option — this app never renders any timestamp, page-title, or
     * URL text into the report itself (the <title> tag is invisible content, never printed). The
     * only actual application chrome on this page is the web-preview toolbar (brand/back-link/
     * Print button) and the new print-settings tip, both already hidden under @media print.
     */
    public function test_web_print_output_has_no_app_generated_chrome_and_hides_the_preview_toolbar_and_print_tip_from_the_printed_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $content = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->getContent();

        // The web-only print-settings tip is present for the CI to read on screen...
        $this->assertStringContainsString('For a clean printout, turn off "Headers and footers" in the browser print settings.', $content);
        // ...but both it and the preview toolbar are hidden the moment the page actually prints —
        // no application chrome, timestamp, page title, or URL is ever part of the printed output.
        $this->assertStringContainsString('@media print { .preview-toolbar, .print-help-tip { display:none; }', $content);
        // No literal date/time or URL text is ever rendered as visible report content (as opposed
        // to, e.g., a legitimate navigation link's own href attribute, which is fine and itself
        // hidden from print via the same rule).
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}\/\d{1,2}\/\d{2,4},?\s+\d{1,2}:\d{2}\s*[AP]M/i', $content);
    }

    public function test_google_map_section_uses_page_break_inside_avoid_instead_of_a_forced_break(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('google-map-page', $preview);
        $this->assertStringContainsString('.google-map-page { page-break-inside: avoid; }', $preview);

        // The Google Map section itself must not carry the unconditional "photo-page" class that
        // forces page-break-before: always — that would defeat the whole smart-placement point.
        $this->assertMatchesRegularExpression('/<section class="official-report-page photo-report-page google-map-page">/', $preview);
    }

    public function test_completion_rule_and_folder_module_status_reflect_saved_checks(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $photo = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$photo],
        ]);

        $result = $folder->completionResults()->whereHas('rule', fn ($query) => $query->where('code', 'residence_business_report'))->firstOrFail();
        $this->assertTrue($result->is_satisfied);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee('1 Residence, 0 Business Check');
    }

    public function test_official_report_and_batch_outputs_use_saved_checks(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->update(['display_name' => 'MICABALO, RONILO CABIGAS']);
        $residencePhotos = [
            UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500),
            UploadedFile::fake()->image('Residence Side.jpg', 900, 700)->size(500),
            UploadedFile::fake()->image('Residence Back.jpg', 900, 700)->size(500),
        ];
        $mapScreenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(300);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'google_maps_link' => 'https://maps.google.com/example', 'remarks' => 'All good', 'photos' => $residencePhotos, 'map_screenshot' => $mapScreenshot,
        ]);
        $residenceCheck = $folder->residenceChecks()->firstOrFail();
        $this->assertTrue($residenceCheck->hasMapScreenshot());

        $this->actingAs($ci)->get(route('client-folders.generated-reports.index', $folder))->assertOk()->assertSee('Residence & Business Photo Report');

        // The official output shows the saved Map Screenshot on its own "Google Map" page, never
        // the raw google_maps_link text/URL, never the live Google Map, and never a redundant
        // "Residence Check"/"Residence DOCUMENTATION" heading (Subject: Residence Check inside the
        // info block already identifies the page).
        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('Applicant Address')->assertSee('Applicant Name')->assertSee('Google Map')
            ->assertDontSee('https://maps.google.com/example')->assertDontSee('Residence DOCUMENTATION');

        // The Residence Picture is served through the authorized photo route, not a raw
        // Windows/private-storage filesystem path, and its saved filename never appears as a
        // visible caption.
        $preview->assertSee(route('client-folders.residence-checks.photo', [$folder, $residenceCheck, $residenceCheck->photos->first()]), false);
        $preview->assertDontSee($residenceCheck->photos->first()->file_name);
        $preview->assertDontSee($residenceCheck->map_screenshot_file_name);

        $batchPreview = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'residence_check_ids' => [$residenceCheck->id],
        ])->assertOk()->assertSee('Applicant Address')->assertSee('Google Map');
        $batchPreview->assertSee('Residence - MICABALO, RONILO CABIGAS')
            ->assertDontSee('Continuation 2')
            ->assertDontSee('Created by:')
            ->assertDontSee('Last updated by:');

        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'user_id' => $ci->id,
            'action' => 'residence_check.created',
        ]);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Residence Check saved');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$residenceCheck->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$residenceCheck->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_official_report_omits_the_google_map_page_when_no_map_screenshot_is_saved(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $residencePhoto = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'remarks' => 'All good', 'photos' => [$residencePhoto],
        ]);
        $residenceCheck = $folder->residenceChecks()->firstOrFail();
        $this->assertFalse($residenceCheck->hasMapScreenshot());

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('Applicant Address')->assertDontSee('Google Map');
    }

    public function test_residence_and_business_check_encoding_pages_render(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->assertSee('Residence Check')->assertSee('Map Screenshot');

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()->assertSee('Business Check')->assertSee('Sari-Sari Store')->assertSee('Competitors');

        $photo = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [$photo],
        ]);
        $residenceCheck = $folder->residenceChecks()->firstOrFail();
        $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $residenceCheck]))
            ->assertOk()->assertSee('Update Residence Check');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ]);
        $businessCheck = $folder->businessChecks()->firstOrFail();
        $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $businessCheck]))
            ->assertOk()->assertSee('Update Business Check');

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('Applicant Address')->assertSee('Sari-Sari Store');
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // A Residence Check's Location is now resolved server-side from the Applicant's saved
        // Present address rather than trusted from client input, so every test folder needs one
        // seeded — matching the literal 'Applicant Address' string these tests already post as
        // the (now-ignored) location payload, so existing assertions against that string still hold.
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        // Likewise, a Residence Check's CI Date is now resolved server-side from the Applicant's
        // own CI/BI Report Start Date, so every test folder needs one of those seeded too.
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }

    private function cibiPayload(): array
    {
        return [
            'intent' => 'complete', 'start_date' => '2026-07-03', 'submitted_date' => '2026-07-04', 'party_type' => 'borrower',
            'branch_name' => 'Main', 'account_officer_name' => 'AO Name', 'amount_applied' => '100000.00',
            'ci_risk_level' => 'low', 'purpose_codes' => ['working_capital'], 'prepared_by_name' => 'Investigator', 'noted_by_name' => 'CA1',
            'personal_snapshot' => [
                'name' => 'VALIDATED CLIENT NAME', 'age' => 40, 'present_address' => 'Validated present address', 'residence_status' => 'Owned',
                'home_condition' => 'New', 'number_of_storeys' => 2, 'material_cost_level' => 'Medium', 'living_condition' => 'Good',
                'parents_address' => 'Validated parents address', 'civil_status' => 'Married', 'reputation' => 'Good',
                'barangay_findings' => 'No Legal Cases', 'lifestyle' => 'Modest',
            ],
        ];
    }
}
