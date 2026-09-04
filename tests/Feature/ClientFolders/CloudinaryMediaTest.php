<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Focused coverage for the Cloudinary integration on the four migrated media kinds (Residence/
 * Business Pictures and their Map Screenshots). CloudinaryMediaStorage is always mocked — no real
 * Cloudinary API calls, no real credentials, no real photo bytes ever leave this process. Existing
 * local-storage behavior (no Cloudinary configured) is already covered elsewhere (e.g.
 * ResidencePictureRequiredTest, ResidenceBusinessReportTest) and is intentionally not re-tested
 * here beyond the one fallback check below.
 */
class CloudinaryMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_new_residence_picture_upload_is_stored_on_cloudinary_when_enabled(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('residence-public-id-1'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $photo = $folder->residenceChecks()->firstOrFail()->photos()->firstOrFail();
        $this->assertSame('residence-public-id-1', $photo->cloud_public_id);
        $this->assertNull($photo->path);
        $this->assertTrue($photo->isCloud());
    }

    public function test_new_residence_map_screenshot_upload_is_stored_on_cloudinary_when_enabled(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/map-screenshots'), 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('residence-map-1'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ])->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('residence-map-1', $check->map_screenshot_cloud_public_id);
        $this->assertNull($check->map_screenshot_path);
        $this->assertTrue($check->hasCloudMapScreenshot());
        $this->assertTrue($check->hasMapScreenshot());
    }

    public function test_residence_create_uploads_and_persists_every_selected_cloud_photo_at_one_three_and_ten(): void
    {
        $ci = User::factory()->create();

        foreach ([1, 3, 10] as $count) {
            $folder = $this->residenceFolder($ci);
            $path = $this->applicantCloudFolder($folder, 'residence/photos');
            $assets = collect(range(1, $count))
                ->map(fn (int $index) => $this->fakeCloudAsset("create-{$count}-{$index}"))
                ->all();
            $this->mockCloud()->shouldReceive('store')->times($count)
                ->with(\Mockery::type(UploadedFile::class), $path, 'photo')
                ->andReturn(...$assets);

            $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
                'photos' => $this->fakePhotos($count, "Create{$count}"),
            ])->assertSessionHasNoErrors();

            $check = $folder->residenceChecks()->firstOrFail();
            $this->assertSame($count, $check->photos()->count());
            $this->assertSame(
                collect($assets)->pluck('cloud_public_id')->all(),
                $check->photos()->orderBy('sort_order')->pluck('cloud_public_id')->all(),
            );
        }
    }

    public function test_residence_update_from_seven_persists_all_three_new_cloud_photos_in_the_organized_applicant_path(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $path = $this->applicantCloudFolder($folder, 'residence/photos');
        $originalAssets = collect(range(1, 7))->map(fn (int $index) => $this->fakeCloudAsset("original-photo-{$index}"))->all();
        $this->mockCloud()->shouldReceive('store')->times(7)
            ->with(\Mockery::type(UploadedFile::class), $path, 'photo')
            ->andReturn(...$originalAssets);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => $this->fakePhotos(7, 'Original'),
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $newAssets = collect(range(1, 3))->map(fn (int $index) => $this->fakeCloudAsset("update-photo-{$index}"))->all();
        $this->mockedCloud->shouldReceive('store')->times(3)
            ->with(\Mockery::type(UploadedFile::class), $path, 'photo')
            ->andReturn(...$newAssets);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'photos' => $this->fakePhotos(3, 'Update'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $check->photos()->count());
        $this->assertSame(
            collect($newAssets)->pluck('cloud_public_id')->all(),
            $check->photos()->whereIn('cloud_public_id', collect($newAssets)->pluck('cloud_public_id'))->orderBy('sort_order')->pluck('cloud_public_id')->all(),
        );
    }

    public function test_residence_update_over_the_final_ten_photo_limit_never_uploads_or_retires_cloud_assets(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $path = $this->applicantCloudFolder($folder, 'residence/photos');
        $assets = collect(range(1, 8))->map(fn (int $index) => $this->fakeCloudAsset("kept-photo-{$index}"))->all();
        $this->mockCloud()->shouldReceive('store')->times(8)
            ->with(\Mockery::type(UploadedFile::class), $path, 'photo')
            ->andReturn(...$assets);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => $this->fakePhotos(8, 'Existing'),
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->mockedCloud->shouldNotReceive('store');
        $this->mockedCloud->shouldNotReceive('destroy');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'photos' => $this->fakePhotos(3, 'Rejected'),
        ])->assertSessionHasErrors(['photos' => 'A maximum of 10 residence pictures is allowed.']);

        $this->assertSame(
            collect($assets)->pluck('cloud_public_id')->all(),
            $check->photos()->orderBy('sort_order')->pluck('cloud_public_id')->all(),
        );
    }

    public function test_residence_update_replaces_a_removed_cloud_photo_and_retires_it_only_after_commit(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $path = $this->applicantCloudFolder($folder, 'residence/photos');
        $oldAssets = collect(range(1, 10))->map(fn (int $index) => $this->fakeCloudAsset("old-photo-{$index}"))->all();
        $this->mockCloud()->shouldReceive('store')->times(10)
            ->with(\Mockery::type(UploadedFile::class), $path, 'photo')
            ->andReturn(...$oldAssets);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => $this->fakePhotos(10, 'Old'),
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();
        $oldPhoto = $check->photos()->firstOrFail();

        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $path, 'photo')
            ->andReturn($this->fakeCloudAsset('replacement-photo'));
        $this->mockedCloud->shouldReceive('destroy')->once()
            ->with('old-photo-1', 'image', 'authenticated')
            ->andReturnUsing(function () use ($oldPhoto): void {
                $this->assertDatabaseMissing('residence_check_photos', ['id' => $oldPhoto->id]);
                $this->assertDatabaseHas('residence_check_photos', ['cloud_public_id' => 'replacement-photo']);
            });

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'removed_photo_ids' => [$oldPhoto->id],
            'photos' => [UploadedFile::fake()->image('Replacement.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('residence_check_photos', ['id' => $oldPhoto->id]);
        $this->assertDatabaseHas('residence_check_photos', ['residence_check_id' => $check->id, 'cloud_public_id' => 'replacement-photo']);
        $this->assertSame(10, $check->photos()->count());
    }

    public function test_new_business_picture_and_map_screenshot_upload_are_stored_on_cloudinary_when_enabled(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'business/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('business-photo-1'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'business/map-screenshots'), 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('business-map-1'));

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame('business-map-1', $check->map_screenshot_cloud_public_id);
        $this->assertSame('business-photo-1', $check->photos()->firstOrFail()->cloud_public_id);
    }

    public function test_new_co_maker_business_picture_uses_the_exact_co_maker_cloudinary_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        $source = $this->businessSource($folder, 'Maria Store', 'San Miguel, Bulacan');
        $source->update(['co_maker_id' => $coMaker->id]);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->coMakerCloudFolder($folder, $coMaker, 'business/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('co-maker-business-photo'));

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertSame('co-maker-business-photo', $folder->businessChecks()->where('co_maker_id', $coMaker->id)->firstOrFail()->photos()->firstOrFail()->cloud_public_id);
    }

    public function test_residence_picture_web_delivery_redirects_to_the_cloudinary_secure_url(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->andReturn($this->fakeCloudAsset('residence-public-id-2'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();
        $photo = $check->photos()->firstOrFail();
        $this->mockedCloud->shouldReceive('deliveryUrl')->once()
            ->with('residence-public-id-2', 'authenticated')
            ->andReturn('https://res.cloudinary.com/demo/image/authenticated/s--signed--/residence-public-id-2.jpg');

        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $photo]))
            ->assertRedirect('https://res.cloudinary.com/demo/image/authenticated/s--signed--/residence-public-id-2.jpg');
    }

    public function test_residence_picture_web_delivery_requests_the_thumbnail_variant_when_asked(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->andReturn($this->fakeCloudAsset('residence-public-id-3'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $photo = $check->photos()->firstOrFail();
        $this->mockedCloud->shouldReceive('thumbnailUrl')->once()
            ->with('residence-public-id-3', 'authenticated')
            ->andReturn('https://res.cloudinary.com/demo/image/authenticated/s--thumb--/residence-public-id-3.jpg');
        $this->mockedCloud->shouldNotReceive('deliveryUrl');

        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $photo, 'thumbnail' => 1]))
            ->assertRedirect('https://res.cloudinary.com/demo/image/authenticated/s--thumb--/residence-public-id-3.jpg');
    }

    public function test_existing_local_residence_picture_still_streams_from_local_disk_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        // Cloudinary is not configured for this test (enabled() defaults to false — no cloud
        // config set), so this exercises the exact untouched pre-Cloudinary local code path.
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $photo = $check->photos()->firstOrFail();
        $this->assertFalse($photo->isCloud());

        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $photo]))
            ->assertOk()
            ->assertHeader('Content-Type', $photo->mime_type);
    }

    public function test_failed_cloudinary_upload_does_not_create_a_broken_photo_reference(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->andThrow(new \RuntimeException('Cloudinary upload failed.'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertStatus(500);

        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('residence_check_photos', 0);
    }

    public function test_a_mid_batch_upload_failure_rolls_back_and_cleans_up_the_orphaned_cloudinary_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/photos'), 'photo')
            ->ordered()
            ->andReturn($this->fakeCloudAsset('orphan-to-clean-up'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->ordered()
            ->andThrow(new \RuntimeException('Cloudinary upload failed for the second file.'));
        $this->mockedCloud->shouldReceive('destroy')->once()
            ->with('orphan-to-clean-up', 'image', 'authenticated');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [
                UploadedFile::fake()->image('First.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Second.jpg', 900, 700)->size(500),
            ],
        ])->assertStatus(500);

        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('residence_check_photos', 0);
    }

    public function test_removing_a_cloud_backed_photo_destroys_only_that_photos_cloudinary_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()->shouldReceive('store')->twice()
            ->andReturn($this->fakeCloudAsset('keep-me'), $this->fakeCloudAsset('remove-me'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [
                UploadedFile::fake()->image('Keep.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Remove.jpg', 900, 700)->size(500),
            ],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $toRemove = $check->photos()->where('cloud_public_id', 'remove-me')->firstOrFail();
        $this->mockedCloud->shouldReceive('destroy')->once()->with('remove-me', 'image', 'authenticated');
        $this->mockedCloud->shouldNotReceive('destroy')->with('keep-me', \Mockery::any(), \Mockery::any());

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'removed_photo_ids' => [$toRemove->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $check->photos()->count());
    }

    public function test_removing_multiple_cloud_backed_photos_in_one_update_destroys_every_one_of_them(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()->shouldReceive('store')->times(3)->andReturn(
            $this->fakeCloudAsset('keep-me'),
            $this->fakeCloudAsset('remove-me-1'),
            $this->fakeCloudAsset('remove-me-2'),
        );
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [
                UploadedFile::fake()->image('Keep.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Remove1.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Remove2.jpg', 900, 700)->size(500),
            ],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $toRemove1 = $check->photos()->where('cloud_public_id', 'remove-me-1')->firstOrFail();
        $toRemove2 = $check->photos()->where('cloud_public_id', 'remove-me-2')->firstOrFail();
        $this->mockedCloud->shouldReceive('destroy')->once()->with('remove-me-1', 'image', 'authenticated');
        $this->mockedCloud->shouldReceive('destroy')->once()->with('remove-me-2', 'image', 'authenticated');
        $this->mockedCloud->shouldNotReceive('destroy')->with('keep-me', \Mockery::any(), \Mockery::any());

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'removed_photo_ids' => [$toRemove1->id, $toRemove2->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $check->photos()->count());
    }

    /**
     * Blocked "remove the last photo without a replacement" saves must never reach any cloud
     * deletion at all — SaveResidenceCheckRequest's own required-picture validation rejects the
     * request before SaveResidenceCheck (where the removal loop and the retire-after-commit call
     * both live) ever runs.
     */
    public function test_removing_the_last_residence_picture_without_a_replacement_is_blocked_and_never_touches_cloudinary(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()->shouldReceive('store')->once()
            ->andReturn($this->fakeCloudAsset('only-photo'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Only.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $onlyPhoto = $check->photos()->firstOrFail();
        $this->mockedCloud->shouldNotReceive('destroy');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'removed_photo_ids' => [$onlyPhoto->id],
        ])->assertSessionHasErrors('photos');

        $this->assertSame('At least one residence picture is required.', session('errors')->first('photos'));
        $this->assertSame(1, $check->photos()->count());
        $this->assertDatabaseHas('residence_check_photos', ['id' => $onlyPhoto->id]);
    }

    /**
     * A save that fails AFTER the removal loop has already run inside the transaction (a stale
     * expected_updated_at conflict — checked at the very top of SaveResidenceCheck's transaction
     * closure, so this specific save never even reaches the removal loop, but exercises the same
     * "removal collected, then the whole transaction throws" shape a real mid-save failure would)
     * must roll back the DB delete and must never call destroy() — retireCloudAsset() only ever
     * runs after DB::transaction() itself returns successfully.
     */
    public function test_a_failed_save_never_deletes_the_cloud_asset_of_a_photo_that_was_about_to_be_removed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $assets = collect(range(1, 10))->map(fn (int $index) => $this->fakeCloudAsset("still-here-{$index}"))->all();
        $this->mockCloud()->shouldReceive('store')->times(10)
            ->andReturn(...$assets);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => $this->fakePhotos(10, 'Existing'),
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $photo = $check->photos()->firstOrFail();
        $this->mockedCloud->shouldNotReceive('destroy');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'removed_photo_ids' => [$photo->id],
            'photos' => [UploadedFile::fake()->image('Replacement.jpg', 900, 700)->size(500)],
            'expected_updated_at' => now()->subDay()->toISOString(),
        ])->assertSessionHasErrors('expected_updated_at');

        $this->assertDatabaseHas('residence_check_photos', ['id' => $photo->id]);
        $this->assertSame(10, $check->photos()->count());
    }

    /**
     * A check with one local-only historical photo (no cloud_public_id — uploaded before Cloudinary
     * was configured) and one Cloudinary-backed photo, both removed in the same update: the local
     * one goes through the existing local-file cleanup, the cloud one through destroy() — neither
     * path is skipped or misapplied to the other.
     */
    public function test_removing_a_mix_of_a_local_only_and_a_cloud_backed_photo_cleans_up_each_correctly(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        // Cloudinary disabled for this first save — a genuine historical local-only photo.
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Local.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $localPhoto = $check->photos()->firstOrFail();
        $localPath = $localPhoto->path;
        // Local mode writes into the CI Team document tree (see EvidenceStorageSetting).
        $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($localPath));

        $this->mockCloud()->shouldReceive('store')->once()
            ->andReturn($this->fakeCloudAsset('cloud-photo'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'photos' => [UploadedFile::fake()->image('Cloud.jpg', 900, 700)->size(500)],
        ]);
        $cloudPhoto = $check->photos()->where('cloud_public_id', 'cloud-photo')->firstOrFail();
        $this->assertSame(2, $check->photos()->count());

        $this->mockedCloud->shouldReceive('destroy')->once()->with('cloud-photo', 'image', 'authenticated');
        $this->mockedCloud->shouldReceive('store')->once()
            ->andReturn($this->fakeCloudAsset('replacement-photo'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'removed_photo_ids' => [$localPhoto->id, $cloudPhoto->id],
            'photos' => [UploadedFile::fake()->image('Replacement.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertFalse(app(CiTeamDocumentStorage::class)->disk()->exists($localPath));
        $this->assertSame(1, $check->photos()->count());
    }

    public function test_a_failed_map_screenshot_replacement_preserves_the_old_cloudinary_asset_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceFolder($ci);
        $this->mockCloud()
            ->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('residence-photo'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/map-screenshots'), 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('original-map'));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame('original-map', $check->map_screenshot_cloud_public_id);

        // The replacement upload itself fails — the old asset must never be destroyed (it is only
        // ever retired AFTER a successful commit) and the check must keep pointing at it.
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->applicantCloudFolder($folder, 'residence/map-screenshots'), 'map_screenshot')
            ->andThrow(new \RuntimeException('Cloudinary upload failed for the replacement map screenshot.'));
        $this->mockedCloud->shouldNotReceive('destroy');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'map_screenshot' => UploadedFile::fake()->image('Replacement.png', 1000, 800)->size(600),
        ])->assertStatus(500);

        $check->refresh();
        $this->assertSame('original-map', $check->map_screenshot_cloud_public_id);
    }

    public function test_business_update_retires_each_exact_removed_media_kind_only_after_the_save_commits(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->mockCloud()->shouldReceive('store')->times(5)->andReturn(
            $this->fakeCloudAsset('group-photo'),
            $this->fakeCloudAsset('business-map'),
            $this->fakeCloudAsset('keep-business-photo'),
            $this->fakeCloudAsset('remove-business-photo'),
            $this->fakeCloudAsset('competitor-photo'),
        );

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [
                UploadedFile::fake()->image('Keep.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Remove.jpg', 900, 700)->size(500),
            ],
            'photo_groups' => [[
                'caption' => 'Rear area',
                'photos' => [UploadedFile::fake()->image('Group.jpg', 900, 700)->size(500)],
            ]],
            'competitor_photos' => [UploadedFile::fake()->image('Competitor.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 900, 700)->size(500),
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $group = $check->photoGroups()->firstOrFail();
        $groupPhoto = $check->photos()->where('cloud_public_id', 'group-photo')->firstOrFail();
        $businessPhoto = $check->photos()->where('cloud_public_id', 'remove-business-photo')->firstOrFail();
        $competitorPhoto = $check->photos()->where('cloud_public_id', 'competitor-photo')->firstOrFail();

        foreach (['group-photo', 'business-map', 'remove-business-photo', 'competitor-photo'] as $publicId) {
            $this->mockedCloud->shouldReceive('destroy')->once()->with($publicId, 'image', 'authenticated');
        }
        $this->mockedCloud->shouldNotReceive('destroy')->with('keep-business-photo', \Mockery::any(), \Mockery::any());

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'income_source_id' => $source->id,
            'ci_date' => $check->ci_date->toDateString(),
            'location' => $check->location,
            'removed_photo_ids' => [$businessPhoto->id, $competitorPhoto->id],
            'photo_groups' => [[
                'id' => $group->id,
                'caption' => $group->caption,
                'removed_photo_ids' => [$groupPhoto->id],
            ]],
            'remove_map_screenshot' => '1',
        ])->assertSessionHasNoErrors();

        $check->refresh();
        $this->assertSame(['keep-business-photo'], $check->photos()->pluck('cloud_public_id')->all());
        $this->assertFalse($check->hasMapScreenshot());
    }

    public function test_failed_business_update_does_not_retire_the_photo_marked_for_removal(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->mockCloud()->shouldReceive('store')->twice()->andReturn(
            $this->fakeCloudAsset('keep-business-photo'),
            $this->fakeCloudAsset('still-here'),
        );
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [
                UploadedFile::fake()->image('Keep.jpg', 900, 700)->size(500),
                UploadedFile::fake()->image('Remove.jpg', 900, 700)->size(500),
            ],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();
        $photo = $check->photos()->where('cloud_public_id', 'still-here')->firstOrFail();
        $this->mockedCloud->shouldNotReceive('destroy');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'income_source_id' => $source->id,
            'ci_date' => $check->ci_date->toDateString(),
            'location' => $check->location,
            'removed_photo_ids' => [$photo->id],
            'expected_updated_at' => now()->subDay()->toISOString(),
        ])->assertSessionHasErrors('expected_updated_at');

        $this->assertDatabaseHas('business_check_photos', ['id' => $photo->id, 'cloud_public_id' => 'still-here']);
    }

    public function test_future_co_maker_residence_media_uses_the_exact_co_maker_namespace(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'address' => 'Maria Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->coMakerCloudFolder($folder, $coMaker, 'residence/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('co-maker-residence-photo'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->coMakerCloudFolder($folder, $coMaker, 'residence/map-screenshots'), 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('co-maker-residence-map'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'location' => 'Verified Maria Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ])->assertSessionHasNoErrors();
    }

    public function test_future_co_maker_business_media_uses_the_exact_co_maker_namespace(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'address' => 'Maria Address']);
        $source = $this->businessSource($folder, 'Maria Store', 'Maria Store Address', $coMaker->id);
        $this->mockCloud()->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->coMakerCloudFolder($folder, $coMaker, 'business/photos'), 'photo')
            ->andReturn($this->fakeCloudAsset('co-maker-business-photo'));
        $this->mockedCloud->shouldReceive('store')->once()
            ->with(\Mockery::type(UploadedFile::class), $this->coMakerCloudFolder($folder, $coMaker, 'business/map-screenshots'), 'map_screenshot')
            ->andReturn($this->fakeCloudAsset('co-maker-business-map'));

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Maria Store Address',
            'photo_groups' => [[
                'photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
            ]],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ])->assertSessionHasNoErrors();
    }

    /** Binds a mock CloudinaryMediaStorage (enabled() => true by default) and remembers it on $this->mockedCloud for further expectations. */
    private function mockCloud(): MockInterface
    {
        $this->useCloudEvidenceStorage();
        $this->mockedCloud = $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
        });

        return $this->mockedCloud;
    }

    private CloudinaryMediaStorage $mockedCloud;

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

    private function residenceFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    /** @return array<int, UploadedFile> */
    private function fakePhotos(int $count, string $prefix): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index) => UploadedFile::fake()->image("{$prefix}-{$index}.jpg", 900, 700)->size(500))
            ->all();
    }

    private function applicantCloudFolder(ClientFolder $folder, string $mediaFolder): string
    {
        $slug = Str::slug((string) $folder->display_name) ?: 'client';

        return "clients/CF-{$folder->id}-{$slug}/applicant/{$mediaFolder}";
    }

    private function coMakerCloudFolder(ClientFolder $folder, CoMaker $coMaker, string $mediaFolder): string
    {
        $folderSlug = Str::slug((string) $folder->display_name) ?: 'client';
        $coMakerSlug = Str::slug((string) $coMaker->full_name) ?: 'co-maker';

        return "clients/CF-{$folder->id}-{$folderSlug}/co-makers/CM-{$coMaker->id}-{$coMakerSlug}/{$mediaFolder}";
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['co_maker_id' => $coMakerId, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
