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
use App\Services\Settings\EvidenceStorageSetting;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Proves that clicking a CI Activity Supporting Proof attachment actually returns the saved image,
 * for both storage providers, and that the ownership rules around that route hold. These assert the
 * delivered bytes and headers rather than "the page rendered", because the reported bug was an image
 * that never appeared even though every surrounding page looked correct.
 *
 * Local proof lands in the per-test temporary CI Team root TestCase configures; Cloudinary is mocked
 * and never contacted.
 */
class CiActivityProofViewingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_a_local_applicant_proof_image_is_delivered_with_its_real_bytes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);
        $upload = UploadedFile::fake()->image('Proof.jpg', 400, 300);
        $bytes = $upload->get();

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [$upload],
        ])->assertSessionHasNoErrors();

        $media = $activity->mediaReferences()->sole();
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $media->storage_provider);

        $response = $this->actingAs($ci)
            ->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(strlen($bytes), strlen($response->streamedContent()), 'The delivered body must be the stored image itself.');
        $this->assertStringStartsWith("\xFF\xD8", $response->streamedContent(), 'Delivered bytes are not a JPEG.');
    }

    public function test_a_co_maker_proof_resolves_from_that_co_makers_own_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Proof Co-Maker']);
        $activity = $this->activity($folder, $ci, $coMaker->id);

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('CoMakerProof.jpg', 400, 300)],
        ])->assertSessionHasNoErrors();

        $media = $activity->mediaReferences()->sole();
        $documents = app(CiTeamDocumentStorage::class);

        $this->assertSame($coMaker->id, $media->co_maker_id);
        $this->assertStringStartsWith($documents->ciActivityProofDirectory($folder, $coMaker).'/', $media->temporary_local_path);
        $this->assertStringNotContainsString($documents->ciActivityProofDirectory($folder).'/', $media->temporary_local_path);

        $this->actingAs($ci)
            ->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_cloudinary_proof_redirects_to_its_signed_delivery_url(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);

        $media = MediaReference::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'other',
            'file_name' => 'Cloud.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 2048, 'uploaded_by' => $ci->id,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'cloudinary_public_id' => 'BRBI-CIMS/clients/current/proof', 'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/proof.jpg',
        ]);
        $activity->mediaReferences()->attach($media->id, ['label' => 'Cloud proof']);

        $this->actingAs($ci)
            ->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))
            ->assertRedirect('https://res.cloudinary.test/proof.jpg');
    }

    public function test_one_activity_can_hold_both_local_and_cloud_proof_and_each_still_opens(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Local.jpg', 400, 300)],
        ])->assertSessionHasNoErrors();
        $local = $activity->mediaReferences()->sole();

        $cloud = MediaReference::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'other',
            'file_name' => 'Cloud.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 2048, 'uploaded_by' => $ci->id,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'cloudinary_public_id' => 'BRBI-CIMS/clients/current/mixed', 'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/mixed.jpg',
        ]);
        $activity->mediaReferences()->attach($cloud->id, ['label' => 'Cloud proof']);

        // Switching the global setting must not change how either historical record is read.
        app(EvidenceStorageSetting::class)->update($ci, EvidenceStorageSetting::CLOUDINARY);

        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $local]))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $cloud]))
            ->assertRedirect('https://res.cloudinary.test/mixed.jpg');
    }

    public function test_proof_cannot_be_reached_through_another_person_or_another_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $first = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $second = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicantActivity = $this->activity($folder, $ci);
        $firstActivity = $this->activity($folder, $ci, $first->id);
        $secondActivity = $this->activity($folder, $ci, $second->id);

        foreach ([$applicantActivity, $firstActivity, $secondActivity] as $activity) {
            $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
                'photos' => [UploadedFile::fake()->image('Proof.jpg', 300, 200)],
            ])->assertSessionHasNoErrors();
        }

        $applicantProof = $applicantActivity->mediaReferences()->sole();
        $firstProof = $firstActivity->mediaReferences()->sole();
        $secondProof = $secondActivity->mediaReferences()->sole();

        // Applicant context cannot open a Co-Maker's proof, and neither Co-Maker can open the other's.
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $applicantActivity, $firstProof]))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $firstActivity, $applicantProof]))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $firstActivity, $secondProof]))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $secondActivity, $firstProof]))->assertNotFound();

        // A proof from another folder entirely is equally unreachable.
        $otherFolder = $this->folder($ci);
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$otherFolder, $applicantActivity, $applicantProof]))->assertNotFound();
    }

    public function test_a_missing_physical_file_returns_404_without_leaking_the_path(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Proof.jpg', 300, 200)],
        ])->assertSessionHasNoErrors();
        $media = $activity->mediaReferences()->sole();

        // The row survives, the file does not — the exact shape of a manually moved/deleted file.
        app(CiTeamDocumentStorage::class)->disk()->delete($media->temporary_local_path);

        $response = $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))->assertNotFound();
        $this->assertStringNotContainsString(app(CiTeamDocumentStorage::class)->root(), $response->getContent());
        $this->assertDatabaseHas('media_references', ['id' => $media->id, 'deleted_at' => null], connection: null);
    }

    public function test_viewing_proof_does_not_alter_the_record_and_the_page_never_leaks_a_local_path(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Proof.jpg', 300, 200)],
        ])->assertSessionHasNoErrors();
        $media = $activity->mediaReferences()->sole();
        $snapshot = fn (MediaReference $record): array => [
            $record->storage_provider, $record->temporary_local_path, $record->file_name,
            $record->byte_size, (string) $record->updated_at,
        ];
        $before = $snapshot($media);

        $html = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $contentUrl = route('client-folders.activities.proof.content', [$folder, $activity, $media]);

        // The page offers the authorized route, and the preview dialog that shows it really exists.
        $this->assertStringContainsString($contentUrl, $html);
        $this->assertStringContainsString('data-modal-open="ci-proof-preview-'.$activity->id.'-'.$media->id.'"', $html);
        $this->assertStringContainsString('id="ci-proof-preview-'.$activity->id.'-'.$media->id.'"', $html);

        // ...and never the real filesystem location.
        $this->assertStringNotContainsString(app(CiTeamDocumentStorage::class)->root(), $html);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]:\\\\Users\\\\/', $html);
        $this->assertStringNotContainsString('CI Activities/Supporting Proof', $html);

        $this->actingAs($ci)->get($contentUrl)->assertOk();
        $this->assertSame($before, $snapshot($media->fresh()), 'Viewing proof must not touch the media record.');
    }

    public function test_upload_replace_and_remove_still_work_end_to_end(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci);
        $documents = app(CiTeamDocumentStorage::class);

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('First.jpg', 300, 200)],
        ])->assertSessionHasNoErrors();
        $original = $activity->mediaReferences()->sole();
        $originalPath = $original->temporary_local_path;
        $this->assertTrue($documents->disk()->exists($originalPath));

        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');
        $this->actingAs($ci)->put(route('client-folders.activities.proof.replace', [$folder, $activity, $original]), [
            'attachment' => UploadedFile::fake()->image('Second.jpg', 300, 200),
        ])->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]));

        $replacement = $activity->mediaReferences()->sole();
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertFalse($documents->disk()->exists($originalPath), 'The replaced file is removed from local storage.');
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $replacement]))->assertOk();

        $replacementPath = $replacement->temporary_local_path;
        $this->actingAs($ci)->delete(route('client-folders.activities.proof.destroy', [$folder, $activity, $replacement]))
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]));
        $this->assertSame(0, $activity->mediaReferences()->count());
        $this->assertFalse($documents->disk()->exists($replacementPath));
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
