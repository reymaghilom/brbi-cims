<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CI Activity Supporting Proof allows up to 10 photos per activity (cumulative with saved proof),
 * identically for Local (the per-test temporary CI Team root TestCase configures) and Cloudinary
 * (mocked, never contacted), and for Applicant and each Co-Maker independently.
 */
class CiActivityProofPhotoLimitTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'You can attach up to 10 supporting photos per activity.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_local_applicant_proof_accepts_one_through_ten_and_rejects_the_eleventh(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);
        $url = route('client-folders.activities.proof.store', [$folder, $activity]);

        // Running totals 1, 2, 4, 5, 6, 10 are all accepted.
        foreach ([1 => 1, 2 => 1, 4 => 2, 5 => 1, 6 => 1, 10 => 4] as $expectedTotal => $batch) {
            $this->actingAs($ci)->postJson($url, ['photos' => $this->photos($batch, "t{$expectedTotal}")])->assertOk();
            $this->assertSame($expectedTotal, $activity->mediaReferences()->count());
        }

        $this->postJson($url, ['photos' => $this->photos(1, 'eleventh')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos' => self::MESSAGE]);
        $this->assertSame(10, $activity->mediaReferences()->count());

        $directory = app(CiTeamDocumentStorage::class)->ciActivityProofDirectory($folder).'/';
        $activity->mediaReferences()->get()->each(function (MediaReference $media) use ($directory): void {
            $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $media->storage_provider);
            $this->assertNull($media->co_maker_id);
            $this->assertStringStartsWith($directory, $media->temporary_local_path);
        });
    }

    public function test_local_direct_request_with_eleven_photos_at_once_is_rejected_without_storing_anything(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), ['photos' => $this->photos(11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos' => self::MESSAGE]);

        $this->assertSame(0, $activity->mediaReferences()->count());
        $this->assertSame(0, MediaReference::query()->count());
    }

    public function test_local_co_maker_limit_is_counted_per_person_and_never_leaks_across_people(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $makerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $makerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $applicantActivity = $this->activity($folder, $ci);
        $activityA = $this->activity($folder, $ci, $makerA->id);
        $activityB = $this->activity($folder, $ci, $makerB->id);
        $paramsA = ['person' => 'co-maker', 'co_maker_id' => $makerA->id];
        $paramsB = ['person' => 'co-maker', 'co_maker_id' => $makerB->id];

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activityA] + $paramsA), ['photos' => $this->photos(10, 'a')])->assertOk();
        $this->postJson(route('client-folders.activities.proof.store', [$folder, $activityA] + $paramsA), ['photos' => $this->photos(1, 'a-extra')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos' => self::MESSAGE]);

        // Co-Maker A being full does not count against Co-Maker B or the Applicant.
        $this->postJson(route('client-folders.activities.proof.store', [$folder, $activityB] + $paramsB), ['photos' => $this->photos(6, 'b')])->assertOk();
        $this->postJson(route('client-folders.activities.proof.store', [$folder, $applicantActivity]), ['photos' => $this->photos(2, 'applicant')])->assertOk();

        $this->assertSame(10, $activityA->mediaReferences()->count());
        $this->assertSame(6, $activityB->mediaReferences()->count());
        $this->assertSame(2, $applicantActivity->mediaReferences()->count());

        $documents = app(CiTeamDocumentStorage::class);
        foreach ([[$activityA, $makerA], [$activityB, $makerB], [$applicantActivity, null]] as [$activity, $maker]) {
            $directory = $documents->ciActivityProofDirectory($folder, $maker).'/';
            $activity->mediaReferences()->get()->each(function (MediaReference $media) use ($maker, $directory): void {
                $this->assertSame($maker?->id, $media->co_maker_id);
                $this->assertStringStartsWith($directory, $media->temporary_local_path);
            });
        }
    }

    public function test_cloudinary_co_maker_proof_accepts_ten_and_rejects_the_eleventh_using_the_mocked_uploader(): void
    {
        $this->useCloudEvidenceStorage();
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $maker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Cloud Co-Maker']);
        $activity = $this->activity($folder, $ci, $maker->id);
        $url = route('client-folders.activities.proof.store', [$folder, $activity, 'person' => 'co-maker', 'co_maker_id' => $maker->id]);

        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->times(10)->andReturn(
            ...collect(range(1, 10))->map(fn (int $i): array => $this->cloudinaryStored("cloud-{$i}.jpg", "brbi-cims/cloud-{$i}"))->all(),
        );

        $this->actingAs($ci)->postJson($url, ['photos' => $this->photos(6, 'first')])->assertOk();
        $this->postJson($url, ['photos' => $this->photos(4, 'second')])->assertOk();
        $this->assertSame(10, $activity->mediaReferences()->count());

        $this->postJson($url, ['photos' => $this->photos(1, 'eleventh')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos' => self::MESSAGE]);

        $this->assertSame(10, $activity->mediaReferences()->count());
        $activity->mediaReferences()->get()->each(function (MediaReference $media) use ($maker): void {
            $this->assertSame(MediaReference::STORAGE_PROVIDER_CLOUDINARY, $media->storage_provider);
            $this->assertSame($maker->id, $media->co_maker_id);
            $this->assertNotNull($media->cloudinary_public_id);
        });
    }

    public function test_cloudinary_direct_request_with_eleven_photos_is_rejected_before_any_upload(): void
    {
        $this->useCloudEvidenceStorage();
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), ['photos' => $this->photos(11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photos' => self::MESSAGE]);
        $this->assertSame(0, $activity->mediaReferences()->count());
    }

    /** @return list<UploadedFile> */
    private function photos(int $count, string $prefix = 'photo'): array
    {
        return collect(range(1, $count))->map(fn (int $i): UploadedFile => UploadedFile::fake()->image("{$prefix}-{$i}.jpg", 120, 90))->all();
    }

    private function cloudinaryStored(string $fileName, string $publicId): array
    {
        return [
            'media_type' => 'photo',
            'file_name' => $fileName,
            'mime_type' => 'image/jpeg',
            'byte_size' => 1024,
            'checksum' => str_repeat('a', 64),
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'temporary_local_path' => null,
            'thumbnail_path' => null,
            'cloudinary_public_id' => $publicId,
            'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/'.basename($publicId).'.jpg',
            'suggested_label' => 'Test proof',
        ];
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function activity(ClientFolder $folder, User $ci, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => ActivityStatus::Completed, 'completed_at' => now(), 'creator_id' => $ci->id,
        ]);
    }
}
