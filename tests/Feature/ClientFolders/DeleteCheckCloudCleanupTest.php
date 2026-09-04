<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\CloudinaryMediaStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Both Residence Check and Business Check deletion are permanent — no Recycle Bin, no restore —
 * so both retire every Cloudinary asset they own after their transaction commits. Business Check
 * deletion additionally leaves its linked Business Report and IncomeSource untouched (see
 * BusinessReportBusinessCheckIndependenceTest for the full independence coverage).
 */
class DeleteCheckCloudCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_residence_check_delete_retires_its_cloud_photo_and_cloud_map_screenshot_after_commit(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $check = $folder->residenceChecks()->create([
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'ci_user_id' => $ci->id,
            'map_screenshot_file_name' => 'map.jpg', 'map_screenshot_cloud_public_id' => 'BRBI-CIMS/residence/map-screenshots/map-1',
            'map_screenshot_cloud_resource_type' => 'image', 'map_screenshot_cloud_delivery_type' => 'authenticated',
        ]);
        $check->photos()->create([
            'file_name' => 'front.jpg', 'uploaded_by' => $ci->id,
            'cloud_public_id' => 'BRBI-CIMS/residence/photos/photo-1', 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated',
        ]);

        $cloud = $this->mock(CloudinaryMediaStorage::class);
        $cloud->shouldReceive('destroy')->once()->with('BRBI-CIMS/residence/photos/photo-1', 'image', 'authenticated');
        $cloud->shouldReceive('destroy')->once()->with('BRBI-CIMS/residence/map-screenshots/map-1', 'image', 'authenticated');

        app(DeleteResidenceCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseMissing('residence_checks', ['id' => $check->id]);
        $this->assertDatabaseCount('residence_check_photos', 0);
    }

    public function test_business_check_hard_delete_retires_every_photo_group_competitor_and_map_asset_and_preserves_the_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan', 'ci_user_id' => $ci->id,
            'map_screenshot_file_name' => 'map.jpg', 'map_screenshot_cloud_public_id' => 'BRBI-CIMS/business/map-screenshots/map-1',
            'map_screenshot_cloud_resource_type' => 'image', 'map_screenshot_cloud_delivery_type' => 'authenticated',
        ]);
        $defaultGroup = $check->photoGroups()->create(['sort_order' => 0]);
        $secondGroup = $check->photoGroups()->create(['caption' => 'Second group', 'sort_order' => 1]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/business/photos/default-1') + ['category' => 'business', 'business_check_photo_group_id' => $defaultGroup->id]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/business/photos/group2-1') + ['category' => 'business', 'business_check_photo_group_id' => $secondGroup->id]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/business/photos/competitor-1') + ['category' => 'competitor']);

        $cloud = $this->mock(CloudinaryMediaStorage::class);
        foreach (['BRBI-CIMS/business/photos/default-1', 'BRBI-CIMS/business/photos/group2-1', 'BRBI-CIMS/business/photos/competitor-1'] as $publicId) {
            $cloud->shouldReceive('destroy')->once()->with($publicId, 'image', 'authenticated');
        }
        $cloud->shouldReceive('destroy')->once()->with('BRBI-CIMS/business/map-screenshots/map-1', 'image', 'authenticated');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseMissing('business_checks', ['id' => $check->id]);
        $this->assertDatabaseCount('business_check_photos', 0);
        $this->assertDatabaseCount('business_check_photo_groups', 0);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'main_business_address' => 'Poblacion, San Miguel, Bulacan']);
    }

    public function test_hard_deleting_one_business_check_never_touches_another_businesss_media_or_records(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $sourceA = $this->businessSource($folder, 'Business A', 'Address A');
        $sourceB = $this->businessSource($folder, 'Business B', 'Address B');
        $checkA = $folder->businessChecks()->create(['income_source_id' => $sourceA->id, 'ci_date' => now()->toDateString(), 'location' => 'Address A', 'ci_user_id' => $ci->id]);
        $checkB = $folder->businessChecks()->create(['income_source_id' => $sourceB->id, 'ci_date' => now()->toDateString(), 'location' => 'Address B', 'ci_user_id' => $ci->id]);
        $checkA->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/business/photos/check-a-1') + ['category' => 'business']);
        $photoB = $checkB->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/business/photos/check-b-1') + ['category' => 'business']);

        $cloud = $this->mock(CloudinaryMediaStorage::class);
        $cloud->shouldReceive('destroy')->once()->with('BRBI-CIMS/business/photos/check-a-1', 'image', 'authenticated');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $checkA);

        $this->assertDatabaseMissing('business_checks', ['id' => $checkA->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $sourceA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceA->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $sourceB->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceB->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $checkB->id, 'income_source_id' => $sourceB->id]);
        $this->assertDatabaseHas('business_check_photos', ['id' => $photoB->id, 'cloud_public_id' => 'BRBI-CIMS/business/photos/check-b-1']);
    }

    public function test_a_rolled_back_delete_never_retires_any_cloud_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $check = $folder->residenceChecks()->create(['ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'ci_user_id' => $ci->id]);
        $photo = $check->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/residence/photos/photo-1'));

        $cloud = $this->mock(CloudinaryMediaStorage::class);
        $cloud->shouldNotReceive('destroy');
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andThrow(new \RuntimeException('Simulated failure inside the transaction.'));
        });

        try {
            app(DeleteResidenceCheck::class)->execute($ci, $folder, $check);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure inside the transaction.', $exception->getMessage());
        }

        $this->assertDatabaseHas('residence_checks', ['id' => $check->id]);
        $this->assertDatabaseHas('residence_check_photos', ['id' => $photo->id]);
    }

    public function test_an_outer_batch_transaction_rollback_never_retires_a_deleted_checks_cloud_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $check = $folder->residenceChecks()->create(['ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'ci_user_id' => $ci->id]);
        $photo = $check->photos()->create($this->cloudPhotoRow($ci->id, 'BRBI-CIMS/residence/photos/outer-batch-photo'));

        $cloud = $this->mock(CloudinaryMediaStorage::class);
        $cloud->shouldNotReceive('destroy');

        try {
            DB::transaction(function () use ($ci, $folder, $check): void {
                app(DeleteResidenceCheck::class)->execute($ci, $folder, $check);

                throw new \RuntimeException('Simulated failure after one batch item was deleted.');
            });
            $this->fail('Expected the simulated outer batch failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure after one batch item was deleted.', $exception->getMessage());
        }

        $this->assertDatabaseHas('residence_checks', ['id' => $check->id]);
        $this->assertDatabaseHas('residence_check_photos', ['id' => $photo->id]);
    }

    /** Common cloud-photo fields shared by both business_check_photos and residence_check_photos — `category` only exists on the former, so it's added separately at each business call site instead of baked in here. */
    private function cloudPhotoRow(int $uploadedBy, string $publicId): array
    {
        return [
            'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
            'byte_size' => 500, 'checksum' => md5(uniqid('', true)), 'sort_order' => 0, 'uploaded_by' => $uploadedBy,
            'cloud_public_id' => $publicId, 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated',
        ];
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);
        // Represents a genuinely, explicitly saved Business Report (revision > 1) rather than a
        // Check-first draft shell — these tests are about Business Check hard-delete leaving the
        // Report untouched, not about orphan-vs-draft-shell cleanup semantics (see
        // DeleteIncomeSourceIfOrphaned / BusinessReportBusinessCheckIndependenceTest).
        $source->forceFill(['revision' => 2])->save();

        return $source;
    }
}
