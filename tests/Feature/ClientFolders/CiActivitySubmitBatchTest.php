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
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CiActivitySubmitBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        // These cases assert Cloudinary-backed behavior, so they run with the administrator's
        // Evidence Storage setting in Cloud mode (the pilot default is Local).
        $this->useCloudEvidenceStorage();
    }

    public function test_centralized_submission_ui_is_no_longer_rendered_on_the_activities_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activityFor($folder, $ci, 'BATCH COMPLETED ACTIVITY');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertDontSee('Submit to Credit Analyst')
            ->assertDontSee('data-modal-open="submit-batch-activities"', false)
            ->assertDontSee('id="submit-batch-activities"', false)
            ->assertDontSee('data-ci-batch-submit-form', false)
            ->assertDontSee('data-ci-batch-submit-button', false)
            ->assertDontSee('data-ci-batch-submit-label', false)
            ->assertDontSee('data-ci-batch-proof-input', false);
    }

    public function test_non_completed_activity_cannot_be_submitted_in_batch(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NON COMPLETED BATCH', null, ['status' => ActivityStatus::FollowUp, 'completed_at' => null]);

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
        ])->assertSessionHasErrors('activity_ids.0', null, 'submission');

        $this->assertNull($activity->fresh()->submitted_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ci_activity.submitted']);
    }

    public function test_batch_submission_with_no_proof_succeeds(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NO PROOF BATCH SUBMIT');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
            'submitted_to' => 'Ana Credit Analyst',
            'submission_note' => 'No proof attached yet.',
        ])->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $activity->refresh();
        $this->assertNotNull($activity->submitted_at);
        $this->assertSame($ci->id, $activity->submitted_by);
        $this->assertSame('Ana Credit Analyst', $activity->submitted_to);
        $this->assertSame('No proof attached yet.', $activity->submission_note);
        $this->assertDatabaseCount('media_references', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.submitted',
            'user_id' => $ci->id,
            'metadata->activity_id' => $activity->id,
        ]);
    }

    public function test_batch_submission_with_one_optional_proof_succeeds_and_attaches_to_exact_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'SINGLE PROOF BATCH SUBMIT');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')
            ->once()
            ->withArgs(fn (ClientFolder $storedFolder, CiActivity $storedActivity, UploadedFile $file): bool => $storedFolder->is($folder)
                && $storedActivity->id === $activity->id
                && $file->getClientOriginalName() === 'batch-proof.jpg')
            ->andReturn($this->cloudinaryStored('batch-proof.jpg'));

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
            'proofs' => [$activity->id => [UploadedFile::fake()->image('batch-proof.jpg')]],
        ])->assertRedirect();

        $activity->refresh();
        $media = MediaReference::sole();
        $this->assertTrue($activity->mediaReferences()->whereKey($media->id)->exists());
        $this->assertSame($activity->co_maker_id, $media->co_maker_id);
        $this->assertNotNull($activity->submitted_at);
    }

    public function test_batch_submission_with_proof_for_some_activities_but_not_others_succeeds(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $withProof = $this->activityFor($folder, $ci, 'WITH PROOF BATCH', null, [], 0);
        $withoutProof = $this->activityFor($folder, $ci, 'WITHOUT PROOF BATCH', null, [], 1);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($this->cloudinaryStored('mixed-batch-proof.jpg'));

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$withProof->id, $withoutProof->id],
            'proofs' => [$withProof->id => [UploadedFile::fake()->image('mixed-batch-proof.jpg')]],
        ])->assertRedirect();

        $this->assertNotNull($withProof->fresh()->submitted_at);
        $this->assertNotNull($withoutProof->fresh()->submitted_at);
        $this->assertSame(1, $withProof->fresh()->mediaReferences()->count());
        $this->assertSame(0, $withoutProof->fresh()->mediaReferences()->count());
    }

    public function test_applicant_proof_remains_applicant_only_and_co_maker_cannot_submit_applicant_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $applicantActivity = $this->activityFor($folder, $ci, 'APPLICANT ONLY BATCH');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'co_maker_id' => $coMaker->id,
            'activity_ids' => [$applicantActivity->id],
        ])->assertSessionHasErrors('activity_ids.0', null, 'submission');

        $this->assertNull($applicantActivity->fresh()->submitted_at);
    }

    public function test_co_maker_a_cannot_submit_proof_for_co_maker_b(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MAKER A', 'first_name' => 'Maker', 'last_name' => 'A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MAKER B', 'first_name' => 'Maker', 'last_name' => 'B']);
        $activityForB = $this->activityFor($folder, $ci, 'CO-MAKER B BATCH', $coMakerB->id);

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_ids' => [$activityForB->id],
        ])->assertSessionHasErrors('activity_ids.0', null, 'submission');

        $this->assertNull($activityForB->fresh()->submitted_at);
    }

    public function test_cross_folder_activity_id_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $foreignActivity = $this->activityFor($otherFolder, $ci, 'FOREIGN FOLDER BATCH');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$foreignActivity->id],
        ])->assertSessionHasErrors('activity_ids.0', null, 'submission');

        $this->assertNull($foreignActivity->fresh()->submitted_at);
    }

    public function test_existing_proof_is_preserved_when_no_replacement_file_is_supplied(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'PRESERVE EXISTING PROOF');
        $existing = $this->cloudinaryMedia($folder, $ci, 'existing-proof.jpg', 'brbi-cims/existing-proof');
        $activity->mediaReferences()->attach($existing);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldNotReceive('store');
        $storage->shouldNotReceive('delete');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
        ])->assertRedirect();

        $this->assertNotSoftDeleted('media_references', ['id' => $existing->id]);
        $this->assertTrue($activity->mediaReferences()->whereKey($existing->id)->exists());
        $this->assertNotNull($activity->fresh()->submitted_at);
    }

    public function test_new_batch_photos_are_added_alongside_existing_proof_without_replacing_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'ADD ALONGSIDE EXISTING BATCH PROOF');
        $existing = $this->cloudinaryMedia($folder, $ci, 'existing-batch-proof.jpg', 'brbi-cims/existing-batch-proof');
        $activity->mediaReferences()->attach($existing);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($this->cloudinaryStored('new-batch-proof.jpg', 'brbi-cims/new-batch-proof'));
        $storage->shouldNotReceive('delete');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
            'proofs' => [$activity->id => [UploadedFile::fake()->image('new-batch-proof.jpg')]],
        ])->assertRedirect();

        $added = MediaReference::query()->where('cloudinary_public_id', 'brbi-cims/new-batch-proof')->sole();
        $this->assertNotSoftDeleted('media_references', ['id' => $existing->id]);
        $this->assertTrue($activity->mediaReferences()->whereKey($existing->id)->exists());
        $this->assertTrue($activity->mediaReferences()->whereKey($added->id)->exists());
        $this->assertSame(2, $activity->mediaReferences()->count());
        $this->assertNotNull($activity->fresh()->submitted_at);
    }

    public function test_batch_rejects_new_photos_that_would_exceed_five_total_for_one_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'BATCH FIVE LIMIT');
        $existing = collect(range(1, 4))->map(fn (int $i) => $this->cloudinaryMedia($folder, $ci, "existing-{$i}.jpg", "brbi-cims/existing-batch-{$i}"));
        $activity->mediaReferences()->attach($existing->pluck('id'));
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
            'proofs' => [$activity->id => [
                UploadedFile::fake()->image('extra-1.jpg'),
                UploadedFile::fake()->image('extra-2.jpg'),
            ]],
        ])->assertSessionHasErrors('proofs.'.$activity->id, null, 'submission');

        $this->assertSame(4, $activity->mediaReferences()->count());
        $this->assertNull($activity->fresh()->submitted_at);
    }

    public function test_failed_upload_does_not_destroy_existing_proof_or_submit_the_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'FAILED BATCH UPLOAD');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andThrow(new \RuntimeException('Simulated batch upload failure.'));
        $storage->shouldNotReceive('delete');
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
                'activity_ids' => [$activity->id],
                'proofs' => [$activity->id => [UploadedFile::fake()->image('failed-batch-proof.jpg')]],
            ]);
            $this->fail('The simulated batch upload failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated batch upload failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('media_references', 0);
        $this->assertNull($activity->fresh()->submitted_at);
    }

    public function test_unsupported_file_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'UNSUPPORTED BATCH FILE');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
            'proofs' => [$activity->id => [UploadedFile::fake()->create('not-supported.txt', 10)]],
        ])->assertSessionHasErrors('proofs.'.$activity->id.'.0', null, 'submission');

        $this->assertNull($activity->fresh()->submitted_at);
        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_submitted_by_actor_and_audit_log_are_recorded_for_batch_submission(): void
    {
        $creator = User::factory()->create();
        $submitter = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activityFor($folder, $creator, 'AUDIT BATCH SUBMIT');

        $this->actingAs($submitter)->patch(route('client-folders.activities.submit-batch', $folder), [
            'activity_ids' => [$activity->id],
            'submitted_to' => 'Ben Credit Analyst',
            'submission_note' => 'Batch submission audit check.',
        ])->assertRedirect();

        $activity->refresh();
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($submitter->id, $activity->submitted_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.submitted',
            'user_id' => $submitter->id,
            'client_folder_id' => $folder->id,
            'metadata->activity_id' => $activity->id,
            'metadata->submitted_to' => 'Ben Credit Analyst',
        ]);
    }

    public function test_existing_protected_proof_viewing_still_works_alongside_batch_submission(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'PROOF VIEW STILL WORKS');
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'still-works.jpg',
            'mime_type' => 'image/jpeg',
            'temporary_local_path' => 'proof/still-works.jpg',
        ]);
        Storage::disk('local')->put($media->temporary_local_path, 'proof-bytes');
        $activity->mediaReferences()->attach($media);

        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_per_activity_submission_dialog_still_renders_for_regression(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'REGRESSION MARK AS SUBMITTED');

        $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']))
            ->assertOk()
            ->assertSee('Mark as Submitted')
            ->assertSee('data-submission-action="create"', false);
    }

    private function cloudinaryStored(string $fileName, string $publicId = 'brbi-cims/test-proof'): array
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
            'cloudinary_secure_url' => 'https://res.cloudinary.test/test-proof.jpg',
            'suggested_label' => 'Test proof',
        ];
    }

    private function cloudinaryMedia(ClientFolder $folder, User $uploader, string $fileName, string $publicId): MediaReference
    {
        return MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $uploader->id,
            'file_name' => $fileName,
            'mime_type' => 'image/jpeg',
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'temporary_local_path' => null,
            'thumbnail_path' => null,
            'cloudinary_public_id' => $publicId,
            'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/'.basename($publicId).'.jpg',
        ]);
    }

    private function activityFor(
        ClientFolder $folder,
        User $ci,
        string $name,
        ?int $coMakerId = null,
        array $attributes = [],
        int $definitionOffset = 0,
    ): CiActivity {
        $definition = ActivityDefinition::query()
            ->where('is_active', true)
            ->whereNotIn('code', [ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE])
            ->orderBy('sort_order')
            ->skip($definitionOffset)
            ->firstOrFail();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $name,
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
            'creator_id' => $ci->id,
        ], $attributes));
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
