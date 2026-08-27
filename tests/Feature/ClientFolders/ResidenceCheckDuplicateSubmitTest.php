<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\CloudinaryMediaStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Duplicate Residence Check submit/upload bug: reported as "two Cloudinary uploads created, only
 * one Residence Check saved". The client-side [data-residence-check-form] 'submit' handler in
 * app.js already guards against a double-click (form.dataset.submitting set synchronously before
 * any async work, button disabled) — but PHPUnit can't drive a browser to prove that guard, and the
 * bug kept recurring anyway, which meant the JS guard alone was never the real fix. The actual root
 * cause: SaveResidenceCheck::execute() had no idempotency guard for a CREATE at all (unlike an
 * edit, which is already scoped to a specific $checkId) — two requests for what was really one Save
 * action each created their own row and their own upload. The fix (see
 * SaveResidenceCheck::execute()) keys a short-lived Cache::lock() + result cache on request_token, a
 * fresh UUID the form embeds once per page load, so a second request carrying the same token is
 * answered with the first request's own record instead of making another one. Also covers the
 * pre-existing "upload succeeded, something else then failed" orphan-cleanup behavior
 * (SaveResidenceCheck's catch(\Throwable)), which no prior test isolated.
 */
class ResidenceCheckDuplicateSubmitTest extends TestCase
{
    use RefreshDatabase;

    private CloudinaryMediaStorage $mockedCloud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_a_single_ajax_submit_persists_exactly_one_check_and_one_photo(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));

        $this->ajaxPost($ci, $folder, [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertOk()->assertJson(['result' => 'success']);

        $this->assertDatabaseCount('residence_checks', 1);
        $this->assertDatabaseCount('residence_check_photos', 1);
    }

    public function test_a_photo_that_uploads_successfully_is_deleted_from_cloudinary_when_the_save_fails_afterward(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-orphan-check'));
        // The upload itself succeeds; a later, unrelated step in the same transaction fails —
        // exactly the "asset landed in Cloudinary but nothing was ever saved" shape.
        $this->mockedCloud->shouldReceive('destroy')->once()->with('residence-photo-orphan-check', 'image', 'authenticated');
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andThrow(new \RuntimeException('Simulated failure after the upload succeeded.'));
        });

        $response = $this->ajaxPost($ci, $folder, [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('residence_check_photos', 0);
    }

    public function test_a_new_map_screenshot_upload_is_deleted_from_cloudinary_when_the_save_fails_afterward(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-1'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/map-screenshots', 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('residence-map-orphan-check'));
        $this->mockedCloud->shouldReceive('destroy')->once()->with('residence-photo-1', 'image', 'authenticated');
        $this->mockedCloud->shouldReceive('destroy')->once()->with('residence-map-orphan-check', 'image', 'authenticated');
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andThrow(new \RuntimeException('Simulated failure after both uploads succeeded.'));
        });

        $response = $this->ajaxPost($ci, $folder, [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ]);

        $response->assertStatus(500);
        $this->assertDatabaseCount('residence_checks', 0);
    }

    /**
     * The actual root cause: SaveResidenceCheck::execute() had no idempotency guard at all for a
     * CREATE (no $checkId to scope a repeat request against, unlike an edit) — two requests reaching
     * the backend for what was really one Save action each created their own row and their own
     * upload. The fix keys a short-lived Cache::lock() + result cache on request_token (a fresh UUID
     * the form embeds once per page load — see the hidden input in residence-checks/form.blade.php),
     * so a second request carrying the *same* token (a genuine duplicate) is answered with the first
     * request's own record instead of creating another one.
     */
    public function test_two_requests_sharing_the_same_request_token_persist_only_one_check_and_photo(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-dup'));

        $first = $this->ajaxPost($ci, $folder, [
            'request_token' => 'duplicate-token-123',
            'photos' => [UploadedFile::fake()->image('First.jpg', 900, 700)->size(500)],
        ]);
        $second = $this->ajaxPost($ci, $folder, [
            'request_token' => 'duplicate-token-123',
            'photos' => [UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500)],
        ]);

        $first->assertOk()->assertJson(['result' => 'success']);
        $second->assertOk()->assertJson(['result' => 'success']);
        $this->assertSame($first->json('return_url'), $second->json('return_url'));
        $this->assertDatabaseCount('residence_checks', 1);
        $this->assertDatabaseCount('residence_check_photos', 1);
    }

    /** Two genuinely separate Add Residence Check actions (each its own page load, so each its own request_token) must never be treated as duplicates of each other — this is the already-supported multiple-checks-per-person case (see ResidenceBusinessReportTest::test_multiple_residence_checks_for_the_same_applicant_appear_as_separate_rows), just re-verified through the AJAX path this fix touches. */
    public function test_two_different_request_tokens_persist_two_separate_checks(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->twice()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-a'), $this->fakeCloudAsset('residence-photo-b'));

        $this->ajaxPost($ci, $folder, [
            'request_token' => 'token-a',
            'photos' => [UploadedFile::fake()->image('First.jpg', 900, 700)->size(500)],
        ])->assertOk()->assertJson(['result' => 'success']);
        $this->ajaxPost($ci, $folder, [
            'request_token' => 'token-b',
            'photos' => [UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500)],
        ])->assertOk()->assertJson(['result' => 'success']);

        $this->assertDatabaseCount('residence_checks', 2);
    }

    public function test_a_retry_with_the_same_request_token_after_a_failed_attempt_saves_normally(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-retry-failed'));
        $this->mockedCloud->shouldReceive('destroy')->once()->with('residence-photo-retry-failed', 'image', 'authenticated');
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class, function ($mock) {
            // Fails only the first attempt — a genuine retry (same request_token, exactly as the
            // still-open form would resend after the CI sees the error) must not be permanently
            // treated as "already handled" just because a prior attempt with that same token existed.
            $mock->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Simulated failure on first attempt.'));
            $mock->shouldReceive('evaluate')->once()->andReturn(true);
        });

        $failed = $this->ajaxPost($ci, $folder, [
            'request_token' => 'retry-token-456',
            'photos' => [UploadedFile::fake()->image('First.jpg', 900, 700)->size(500)],
        ]);
        $failed->assertStatus(500);
        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('residence_check_photos', 0);

        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), 'residence/photos', 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo-retry-succeeded'));

        $retry = $this->ajaxPost($ci, $folder, [
            'request_token' => 'retry-token-456',
            'photos' => [UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500)],
        ]);

        $retry->assertOk()->assertJson(['result' => 'success']);
        $this->assertDatabaseCount('residence_checks', 1);
        $this->assertDatabaseCount('residence_check_photos', 1);
    }

    /** Binds a mock CloudinaryMediaStorage (enabled() => true by default) and remembers it on $this->mockedCloud for further expectations — same convention as CloudinaryMediaTest/ResidenceCheckCloudUploadFeedbackTest. */
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

    /** Mirrors exactly what [data-residence-check-form]'s XHR submission sends — a multipart POST with an explicit Accept: application/json header. */
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
