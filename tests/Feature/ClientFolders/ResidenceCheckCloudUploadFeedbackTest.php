<?php

namespace Tests\Feature\ClientFolders;

use App\Exceptions\CloudMediaUploadException;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\Media\CloudinaryMediaStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Save/Update progress feedback for cloud-backed Residence media: the loading-state markup the JS
 * hooks into, the cloud-aware success-message suffix, and safe (non-leaking) handling when a
 * Cloudinary upload itself fails mid-save. The Residence Photo required rule itself is already
 * covered by ResidencePictureRequiredTest and is only re-touched here where a cloud failure
 * interacts with it (a rolled-back save must never look like a saved one, and existing valid photos
 * must never be lost to a failed replacement).
 */
class ResidenceCheckCloudUploadFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private CloudinaryMediaStorage $mockedCloud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_residence_form_carries_the_data_hooks_the_save_button_loading_state_depends_on(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-residence-check-form', $content);
        $this->assertStringContainsString('data-cloud-storage-enabled="0"', $content);
        $this->assertStringContainsString('data-residence-check-submit', $content);
        $this->assertStringContainsString('data-residence-check-submit-spinner', $content);
        $this->assertStringContainsString('data-residence-check-submit-text', $content);
        // No upload percentage / progress bar — a disabled button + spinner + loading text is the
        // whole of the loading state.
        $this->assertStringNotContainsString('data-residence-check-progress', $content);
    }

    public function test_residence_form_carries_the_same_separate_save_status_region_as_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-residence-check-save-status', $content);
        $this->assertStringContainsString('data-residence-check-save-status-text', $content);
        $this->assertStringContainsString('data-residence-check-save-status-helper', $content);
        $this->assertStringContainsString('Upload time may vary depending on your internet connection.', $content);
        // No progress bar / fake percentage introduced alongside the status region either.
        $this->assertStringNotContainsString('data-residence-check-progress', $content);
    }

    public function test_ajax_submit_uses_every_staged_residence_photo_and_preserves_upload_feedback(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('field.getStagedPhotoFiles = () => [...files];', $script);
        $this->assertStringContainsString('payload.delete(photoInput.name);', $script);
        $this->assertStringContainsString('stagedPhotos.forEach((file) => payload.append(photoInput.name, file, file.name));', $script);
        $this->assertStringContainsString("statusText.textContent = 'Uploading media to cloud storage…';", $script);
        $this->assertStringContainsString("statusText.textContent = 'Saving Residence Check…';", $script);
    }

    public function test_cloud_storage_enabled_flag_reflects_whether_cloudinary_is_actually_configured(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->never();

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-cloud-storage-enabled="1"', $content);
    }

    public function test_success_message_mentions_cloud_storage_only_when_new_photos_were_actually_uploaded_to_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('status', 'Residence Check saved successfully. Files saved to Cloud Storage (Cloudinary).');
    }

    public function test_success_message_mentions_media_when_only_a_map_screenshot_was_newly_uploaded_to_cloud_storage(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/map-screenshots', 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('residence-map-1'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ])->assertSessionHas('status', 'Residence Check updated successfully. Files saved to Cloud Storage (Cloudinary).');
    }

    public function test_success_message_stays_plain_when_no_new_cloud_media_was_uploaded(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Genuinely different remark.',
        ])->assertSessionHas('status', 'Residence Check updated successfully.');
    }

    public function test_success_message_stays_plain_when_cloud_storage_is_not_configured_even_with_new_photos(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        // No mockCloud() here — this save runs in the pilot default (Local) Evidence Storage mode,
        // so the message must name Local Storage rather than cloud storage.

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('status', 'Residence Check saved successfully. Files saved to Local Storage.');
    }

    public function test_no_change_status_message_is_unaffected_by_cloud_wording(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.',
        ])->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.')
            ->assertSessionHas('statusType', 'info');
    }

    public function test_a_new_check_whose_only_photo_fails_to_upload_to_cloud_storage_shows_a_safe_retry_message_and_saves_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()->andThrow(new CloudMediaUploadException);

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Keep this remark.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $response->assertRedirect(route('client-folders.residence-checks.create', $folder));
        $response->assertSessionHas('status', 'Residence Check was not saved because one or more photos could not be uploaded to cloud storage. Please check your connection and try again.');
        $response->assertSessionHas('statusType', 'error');
        $response->assertSessionHasInput('remarks', 'Keep this remark.');

        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('residence_check_photos', 0);
    }

    public function test_the_error_message_never_leaks_cloudinary_sdk_details_or_credentials(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->andThrow(new CloudMediaUploadException);

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $message = $response->getSession()->get('status');
        foreach (['api_secret', 'cloudinary.com', 'public_id', 'Stack trace', 'Exception', 'RuntimeException'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $message);
        }
    }

    public function test_a_failed_map_screenshot_replacement_on_an_edit_preserves_the_existing_valid_photo_and_does_not_destroy_the_old_screenshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/map-screenshots', 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('original-map'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('original-map', $check->map_screenshot_cloud_public_id);
        $this->assertSame(1, $check->photos()->count());

        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/map-screenshots', 'map_screenshot')
            ->andThrow(new CloudMediaUploadException);
        $this->mockedCloud->shouldNotReceive('destroy');

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'map_screenshot' => UploadedFile::fake()->image('Replacement.png', 800, 600)->size(400),
        ]);

        $response->assertRedirect(route('client-folders.residence-checks.edit', [$folder, $check]));
        $response->assertSessionHas('statusType', 'error');
        $check->refresh();
        $this->assertSame('original-map', $check->map_screenshot_cloud_public_id);
        $this->assertSame(1, $check->photos()->count());
    }

    /** Binds a mock CloudinaryMediaStorage (enabled() => true by default) and remembers it on $this->mockedCloud for further expectations — same convention as CloudinaryMediaTest. */
    private function mockCloud(): MockInterface
    {
        $this->useCloudEvidenceStorage();
        $this->mockedCloud = $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
        });

        return $this->mockedCloud;
    }

    /** @return array{file_name:string, mime_type:string, byte_size:int, checksum:string, cloud_public_id:string, cloud_resource_type:string, cloud_delivery_type:string, cloud_format:string, cloud_width:?int, cloud_height:?int} */
    private function fakeCloudAsset(string $publicId): array
    {
        return [
            'file_name' => $publicId.'.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 123456,
            'checksum' => hash('sha256', $publicId),
            'cloud_public_id' => $publicId,
            'cloud_resource_type' => 'image',
            'cloud_delivery_type' => 'authenticated',
            'cloud_format' => 'jpg',
            'cloud_width' => 1600,
            'cloud_height' => 1200,
        ];
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
