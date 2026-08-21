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

    public function test_access_is_limited_to_administrator_and_assigned_ci_and_deleted_folders_are_unavailable(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $this->actingAs($admin)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $this->actingAs($other)->get(route('client-folders.residence-business.edit', $folder))->assertForbidden();
        $folder->delete();
        $this->actingAs($admin)->get(route('client-folders.residence-business.edit', $folder->id))->assertNotFound();
    }

    public function test_the_old_documentation_section_workflow_is_gone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()
            ->assertSee('Residence Checks')
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
        ])->assertRedirect(route('client-folders.residence-business.edit', $folder));

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

    public function test_residence_and_business_checks_are_isolated_between_applicant_and_each_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerOne = $folder->coMakers()->create(['full_name' => 'Co Maker One']);
        $coMakerTwo = $folder->coMakers()->create(['full_name' => 'Co Maker Two']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMakerOne->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker One Address',
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMakerTwo->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Two Address',
        ])->assertRedirect();

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
        $residencePhoto = UploadedFile::fake()->image('Residence Front.jpg', 900, 700)->size(500);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'google_maps_link' => 'https://maps.google.com/example', 'remarks' => 'All good', 'photos' => [$residencePhoto],
        ]);
        $residenceCheck = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->get(route('client-folders.generated-reports.index', $folder))->assertOk()->assertSee('Residence & Business Photo Report');

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertSee('https://maps.google.com/example')->assertSee('Applicant Address');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'residence_check_ids' => [$residenceCheck->id],
        ])->assertOk()->assertSee('Applicant Address');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$residenceCheck->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$residenceCheck->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_residence_and_business_check_encoding_pages_render(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->assertSee('Residence Check')->assertSee('Google Map');

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
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
    }

    private function businessSource(ClientFolder $folder, string $name, string $address): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
