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
use Tests\TestCase;

/**
 * The AJAX/XHR contract [data-residence-check-form] in app.js submits against: real upload
 * progress needs XHR (not a full-page redirect) to have anything to report, so
 * ResidenceCheckController::store() now returns structured JSON instead of a redirect whenever the
 * request declares Accept: application/json (Request::expectsJson()) — the exact same signal
 * Laravel's own ValidationException JSON rendering already keys off, so a validation failure needs
 * no bespoke handling here at all. A plain (non-JS) browser submission never sends that header, so
 * the original redirect-with-flash behavior (covered by ResidenceCheckCloudUploadFeedbackTest and
 * ResidencePictureRequiredTest) is completely untouched and still the only path exercised there.
 */
class ResidenceCheckAjaxSaveTest extends TestCase
{
    use RefreshDatabase;

    private CloudinaryMediaStorage $mockedCloud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_ajax_create_with_a_new_photo_returns_a_success_payload_with_the_cloud_photo_message(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));

        $response = $this->ajaxPost($ci, $folder, [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $response->assertOk()->assertJson([
            'result' => 'success',
            'message' => 'Residence Check saved successfully. Photos uploaded to cloud storage.',
            'status_type' => 'success',
        ]);
        $response->assertJsonStructure(['return_url']);
        $this->assertSame(
            route('client-folders.residence-business.edit', $folder),
            $response->json('return_url'),
        );
        $this->assertDatabaseCount('residence_checks', 1);

        // The success message travels straight through this JSON payload (app.js relays it via
        // sessionStorage for the parent's reload to pick up) rather than a session flash — flashing
        // it here too was previously how this worked, but an unrelated request (e.g. the
        // editing-presence heartbeat) landing before that reload could age the flash out first,
        // intermittently losing the toast. No flash is set for this outcome any more.
        $this->assertNull(session('status'));
        $this->assertNull(session('statusType'));
    }

    public function test_ajax_update_with_a_new_photo_returns_the_updated_variant_of_the_cloud_photo_message(): void
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
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-2'));

        $response = $this->ajaxPost($ci, $folder, [
            'check_id' => $check->id,
            'photos' => [UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500)],
        ]);

        // status_type is what app.js's postMessage now forwards to the parent's toast relay for an
        // update, exactly like it already did for a create — this is the fix for the update toast
        // never appearing (the message previously only reached the parent via a session flash that
        // an unrelated request could age out before the reload read it).
        $response->assertOk()->assertJson([
            'result' => 'success',
            'message' => 'Residence Check updated successfully. Photos uploaded to cloud storage.',
            'status_type' => 'success',
        ]);
        $response->assertJsonStructure(['return_url']);
        $this->assertNull(session('status'));
    }

    public function test_ajax_map_screenshot_only_upload_returns_the_media_message(): void
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

        $response = $this->ajaxPost($ci, $folder, [
            'check_id' => $check->id,
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);

        $response->assertOk()->assertJson([
            'result' => 'success',
            'message' => 'Residence Check updated successfully. Media uploaded to cloud storage.',
        ]);
    }

    public function test_ajax_save_with_no_new_cloud_media_returns_the_plain_success_message(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        // No mockCloud() — Cloud Storage genuinely isn't configured for this test.

        $response = $this->ajaxPost($ci, $folder, [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $response->assertOk()->assertJson([
            'result' => 'success',
            'message' => 'Residence Check saved successfully.',
        ]);
    }

    public function test_ajax_validation_failure_returns_the_exact_required_photo_message(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $response = $this->ajaxPost($ci, $folder, ['remarks' => 'Missing a photo.']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('photos');
        $this->assertSame('At least one residence picture is required.', $response->json('errors.photos.0'));
        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_ajax_cloud_upload_failure_returns_a_502_with_the_safe_retry_message_and_saves_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()->andThrow(new CloudMediaUploadException());

        $response = $this->ajaxPost($ci, $folder, [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $response->assertStatus(502)->assertJson([
            'result' => 'cloud_failure',
            'message' => 'Residence Check was not saved because one or more photos could not be uploaded to cloud storage. Please check your connection and try again.',
            'status_type' => 'error',
        ]);
        $this->assertDatabaseCount('residence_checks', 0);
        // No parent-reload-carrying session flash for this outcome — the modal stays open and the
        // AJAX handler shows this message itself.
        $this->assertNull(session('status'));
    }

    public function test_ajax_no_change_returns_the_exact_no_change_message_without_a_success_flash(): void
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

        $response = $this->ajaxPost($ci, $folder, ['check_id' => $check->id, 'remarks' => 'Residence verified.']);

        $response->assertOk()->assertJson([
            'result' => 'no_change',
            'message' => 'Nothing changed. No updates were saved to the database.',
            'status_type' => 'info',
        ]);
        $this->assertNull(session('status'));
    }

    public function test_non_ajax_submission_still_falls_back_to_the_original_redirect_and_flash_behavior(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        // No Accept: application/json header at all — the exact original plain form submission.

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $check = $folder->residenceChecks()->firstOrFail();
        $response->assertRedirect(route('client-folders.residence-checks.edit', [$folder, $check]));
        $response->assertSessionHas('status', 'Residence Check saved successfully.');
    }

    /** Binds a mock CloudinaryMediaStorage (enabled() => true by default) and remembers it on $this->mockedCloud for further expectations — same convention as CloudinaryMediaTest. */
    private function mockCloud(): \Mockery\MockInterface
    {
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

    /** Mirrors exactly what [data-residence-check-form]'s XHR submission sends — a multipart POST with an explicit Accept: application/json header, never a JSON-encoded body (that can't carry files). */
    private function ajaxPost(User $ci, ClientFolder $folder, array $data)
    {
        return $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $data, [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
