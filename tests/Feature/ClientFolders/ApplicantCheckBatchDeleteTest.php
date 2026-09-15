<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Media\CloudinaryMediaStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantCheckBatchDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_applicant_listing_removes_the_summary_and_renders_disabled_batch_delete_controls(): void
    {
        [$ci, $folder] = $this->folderForApplicant();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Report Summary', $content);
        $this->assertStringContainsString('data-check-delete-selected disabled', $content);
        $this->assertStringContainsString('Delete Selected', $content);
        $this->assertStringContainsString('id="check-batch-delete-form"', $content);
    }

    public function test_mixed_batch_deletes_only_selected_applicant_checks_and_returns_to_the_listing(): void
    {
        [$ci, $folder] = $this->folderForApplicant();
        // A person holds at most one Residence Check, so the record left unselected is a second
        // Applicant Business Check for a different business.
        $selectedResidence = $this->createResidenceCheck($ci, $folder, 'Selected residence');
        $selectedBusiness = $this->createBusinessCheck($ci, $folder);
        $unselectedBusiness = $this->createBusinessCheck($ci, $folder, 'Keep Hardware');
        $this->markPhotoAsCloud($selectedResidence->photos()->firstOrFail(), 'selected-residence-photo');
        $this->markPhotoAsCloud($selectedBusiness->photos()->firstOrFail(), 'selected-business-photo');
        $cloud = $this->mock(CloudinaryMediaStorage::class);
        $cloud->shouldReceive('destroy')->once()->with('selected-residence-photo', 'image', 'authenticated');
        $cloud->shouldReceive('destroy')->once()->with('selected-business-photo', 'image', 'authenticated');

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-delete', $folder), [
            'residence_check_ids' => [$selectedResidence->id],
            'business_check_ids' => [$selectedBusiness->id],
        ]);

        $response->assertRedirect(route('client-folders.residence-business.edit', $folder));
        $response->assertSessionHas('status', 'Selected reports deleted successfully.');
        $this->assertDatabaseMissing('residence_checks', ['id' => $selectedResidence->id]);
        $this->assertDatabaseMissing('business_checks', ['id' => $selectedBusiness->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $unselectedBusiness->id, 'co_maker_id' => null]);
    }

    public function test_a_co_maker_context_cannot_use_the_applicant_batch_endpoint(): void
    {
        [$ci, $folder] = $this->folderForApplicant();
        $check = $this->createResidenceCheck($ci, $folder, 'Applicant residence');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Batch Context Co-Maker']);

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-delete', $folder), [
            'residence_check_ids' => [$check->id],
            'co_maker_id' => $coMaker->id,
        ])->assertNotFound();

        $this->assertDatabaseHas('residence_checks', ['id' => $check->id, 'co_maker_id' => null]);
    }

    public function test_failed_mixed_batch_rolls_back_records_and_never_retires_cloud_assets(): void
    {
        [$ci, $folder] = $this->folderForApplicant();
        $residence = $this->createResidenceCheck($ci, $folder, 'Residence to roll back');
        $business = $this->createBusinessCheck($ci, $folder);
        $this->markPhotoAsCloud($residence->photos()->firstOrFail(), 'residence-stays');
        $this->markPhotoAsCloud($business->photos()->firstOrFail(), 'business-stays');

        $this->mock(CloudinaryMediaStorage::class)->shouldNotReceive('destroy');
        BusinessCheck::deleted(function (): void {
            throw new \RuntimeException('Force the outer batch transaction to roll back.');
        });

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-delete', $folder), [
            'residence_check_ids' => [$residence->id],
            'business_check_ids' => [$business->id],
        ])->assertStatus(500);

        $this->assertDatabaseHas('residence_checks', ['id' => $residence->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $business->id]);
        $this->assertDatabaseHas('residence_check_photos', ['cloud_public_id' => 'residence-stays']);
        $this->assertDatabaseHas('business_check_photos', ['cloud_public_id' => 'business-stays']);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folderForApplicant(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return [$ci, $folder];
    }

    private function createResidenceCheck(User $ci, ClientFolder $folder, string $remarks)
    {
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => $remarks,
            'photos' => [UploadedFile::fake()->image($remarks.'.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->residenceChecks()->latest('id')->firstOrFail();
    }

    private function createBusinessCheck(User $ci, ClientFolder $folder, string $businessName = 'Sari-Sari Store')
    {
        $source = $this->businessSource($folder, $businessName);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->businessChecks()->latest('id')->firstOrFail();
    }

    private function businessSource(ClientFolder $folder, string $businessName = 'Sari-Sari Store'): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $businessName,
            'business_name' => $businessName,
        ]);
        $source->businessReport()->create([
            'business_name' => $businessName,
            'main_business_address' => 'Poblacion, San Miguel, Bulacan',
            'report_category' => 'retail_grocery_water_refilling',
        ]);

        return $source;
    }

    private function markPhotoAsCloud($photo, string $publicId): void
    {
        $photo->forceFill([
            'path' => null,
            'thumbnail_path' => null,
            'cloud_public_id' => $publicId,
            'cloud_resource_type' => 'image',
            'cloud_delivery_type' => 'authenticated',
            'cloud_format' => 'jpg',
        ])->save();
    }
}
