<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\Media\UploadCiActivityProof;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CiActivityAttachmentAvailabilityTest extends TestCase
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

    public static function nonCompletedStatuses(): array
    {
        return [
            'pending' => [ActivityStatus::Pending->value],
            'scheduled' => [ActivityStatus::Scheduled->value],
            'follow-up' => [ActivityStatus::FollowUp->value],
        ];
    }

    #[DataProvider('nonCompletedStatuses')]
    public function test_add_activity_ignores_any_posted_attachments_field_for_non_completed_status(string $status): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $response = $this->actingAs($ci)->from(route('client-folders.activities.index', $folder))
            ->post(route('client-folders.activities.store', $folder), $this->activityPayload($status) + [
                'attachments' => [UploadedFile::fake()->image('proof.jpg')],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('ci_activities', 1);
        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_completed_activity_can_be_saved_without_proof_and_is_not_submitted(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $response = $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $this->activityPayload(ActivityStatus::Completed->value), [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $response->assertOk()
            ->assertJson(['activity_created' => true])
            ->assertJsonPath('redirect', route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertSessionHas('status', 'Activity added successfully.')
            ->assertSessionHasNoErrors();
        $this->get($response->json('redirect'))
            ->assertOk()
            ->assertSee('data-toast', false)
            ->assertSee('Activity added successfully.')
            ->assertDontSee('open data-ci-activity-initial-open', false);

        $activity = CiActivity::sole();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNull($activity->submitted_at);
        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_completed_activity_ignores_any_posted_attachments_field_and_stores_no_proof(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $this->activityPayload(ActivityStatus::Completed->value) + [
            'attachments' => [UploadedFile::fake()->image('completed-proof.jpg')],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk()
            ->assertJson(['activity_created' => true])
            ->assertSessionHas('status', 'Activity added successfully.')
            ->assertSessionHasNoErrors();

        $activity = CiActivity::sole();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNull($activity->submitted_at);
        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_cloudinary_folder_paths_are_exact_for_applicant_and_co_maker(): void
    {
        config()->set('cloudinary.root_folder', 'BRBI-CIMS');
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'PRIVATE NAME MUST NOT APPEAR',
            'first_name' => 'Private',
            'last_name' => 'Name',
        ]);
        $applicantActivity = $this->activityFor($folder, $ci, 'APPLICANT CLOUDINARY PATH');
        $coMakerActivity = $this->activityFor($folder, $ci, 'CO-MAKER CLOUDINARY PATH', $coMaker->id);
        $storage = app(CloudinaryCiActivityProofStorage::class);
        $clientMediaUploader = app(ClientMediaUploader::class);
        $clientSlug = Str::slug((string) $folder->display_name) ?: 'client';
        $coMakerSlug = Str::slug((string) $coMaker->full_name) ?: 'co-maker';
        $clientRoot = 'BRBI-CIMS/clients/CF-'.$folder->id.'-'.$clientSlug;

        $this->assertSame(
            $clientRoot.'/applicant/ci-activities/attachments',
            $storage->folderFor($folder, $applicantActivity),
        );
        $this->assertSame(
            $clientRoot.'/co-makers/CM-'.$coMaker->id.'-'.$coMakerSlug.'/ci-activities/attachments',
            $storage->folderFor($folder, $coMakerActivity),
        );
        $this->assertSame(
            $clientRoot.'/applicant/residence/photos',
            $clientMediaUploader->rootedPersonCloudFolder($folder, 'residence/photos'),
        );
        $this->assertSame(
            $clientRoot.'/applicant/business/photos',
            $clientMediaUploader->rootedPersonCloudFolder($folder, 'business/photos'),
        );
    }

    public function test_cloudinary_proof_retirement_reuses_the_shared_media_retirement_service(): void
    {
        $mediaUploader = $this->mock(ClientMediaUploader::class);
        $mediaUploader->shouldReceive('retireCloudAsset')
            ->once()
            ->with('brbi-cims/legacy/exact-proof', 'video', 'upload');

        (new CloudinaryCiActivityProofStorage($mediaUploader))->delete(
            'brbi-cims/legacy/exact-proof',
            'video',
        );
    }

    public function test_removing_one_historical_cloudinary_proof_retires_its_stored_asset_and_preserves_other_proof(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'REMOVE EXACT HISTORICAL PROOF');
        $historical = $this->cloudinaryMedia($folder, $ci, 'historical-proof.jpg', 'brbi-cims/client-folders/'.$folder->id.'/applicant/ci-activities/'.$activity->id.'/proof/historical-id');
        $other = $this->cloudinaryMedia($folder, $ci, 'keep-proof.jpg', 'BRBI-CIMS/clients/current/keep-id');
        $activity->mediaReferences()->attach([$historical->id, $other->id]);

        $this->mock(CloudinaryCiActivityProofStorage::class)
            ->shouldReceive('delete')
            ->once()
            ->with($historical->cloudinary_public_id, 'image');

        $this->actingAs($ci)
            ->delete(route('client-folders.activities.proof.destroy', [$folder, $activity, $historical]))
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertSessionHas('status', 'Proof attachment removed successfully.');

        $this->assertSoftDeleted('media_references', ['id' => $historical->id]);
        $this->assertDatabaseMissing('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $historical->id]);
        $this->assertNotSoftDeleted('media_references', ['id' => $other->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $other->id]);
    }

    public function test_removing_a_shared_proof_detaches_only_the_exact_activity_without_retiring_the_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'DETACH SHARED PROOF');
        $otherActivity = $this->activityFor($folder, $ci, 'KEEP SHARED PROOF', null, [], 1);
        $shared = $this->cloudinaryMedia($folder, $ci, 'shared-proof.jpg', 'brbi-cims/legacy/shared-proof');
        $activity->mediaReferences()->attach($shared);
        $otherActivity->mediaReferences()->attach($shared);

        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('delete');

        $this->actingAs($ci)
            ->delete(route('client-folders.activities.proof.destroy', [$folder, $activity, $shared]))
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]));

        $this->assertNotSoftDeleted('media_references', ['id' => $shared->id]);
        $this->assertDatabaseMissing('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $shared->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $otherActivity->id, 'media_reference_id' => $shared->id]);
    }

    public function test_successful_replacement_persists_new_proof_before_retiring_only_the_old_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'REPLACE EXACT PROOF');
        $old = $this->cloudinaryMedia($folder, $ci, 'old-proof.jpg', 'brbi-cims/legacy/old-proof');
        $other = $this->cloudinaryMedia($folder, $ci, 'other-proof.jpg', 'BRBI-CIMS/clients/current/other-proof');
        $activity->mediaReferences()->attach([$old->id, $other->id]);

        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')
            ->once()
            ->ordered()
            ->andReturn($this->cloudinaryStored('replacement-proof.jpg', 'BRBI-CIMS/clients/current/replacement-proof'));
        $storage->shouldReceive('delete')
            ->once()
            ->ordered()
            ->with('brbi-cims/legacy/old-proof', 'image');

        $this->actingAs($ci)
            ->put(route('client-folders.activities.proof.replace', [$folder, $activity, $old]), [
                'attachment' => UploadedFile::fake()->image('replacement-proof.jpg'),
            ])
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertSessionHas('status', 'Supporting Proof replaced successfully. New file saved to Cloud Storage (Cloudinary).');

        $replacement = MediaReference::query()->where('cloudinary_public_id', 'BRBI-CIMS/clients/current/replacement-proof')->sole();
        $this->assertSoftDeleted('media_references', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $replacement->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $other->id]);
        $this->assertDatabaseMissing('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $old->id]);
    }

    public function test_failed_replacement_upload_preserves_the_old_reference_and_cloudinary_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'FAILED REPLACEMENT');
        $old = $this->cloudinaryMedia($folder, $ci, 'old-proof.jpg', 'brbi-cims/legacy/preserved-old-proof');
        $activity->mediaReferences()->attach($old);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andThrow(new \RuntimeException('Simulated replacement upload failure.'));
        $storage->shouldNotReceive('delete');
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($ci)->put(route('client-folders.activities.proof.replace', [$folder, $activity, $old]), [
                'attachment' => UploadedFile::fake()->image('failed-replacement.jpg'),
            ]);
            $this->fail('The simulated replacement upload failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated replacement upload failure.', $exception->getMessage());
        }

        $this->assertNotSoftDeleted('media_references', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $old->id]);
        $this->assertDatabaseCount('media_references', 1);
    }

    public function test_non_completed_activity_cannot_replace_existing_proof(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NON COMPLETED REPLACEMENT', null, [
            'status' => ActivityStatus::FollowUp,
            'completed_at' => null,
        ]);
        $old = $this->cloudinaryMedia($folder, $ci, 'old-proof.jpg', 'brbi-cims/legacy/non-completed-proof');
        $activity->mediaReferences()->attach($old);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldNotReceive('store');
        $storage->shouldNotReceive('delete');

        $this->actingAs($ci)
            ->from(route('client-folders.activities.edit', [$folder, $activity]))
            ->put(route('client-folders.activities.proof.replace', [$folder, $activity, $old]), [
                'attachment' => UploadedFile::fake()->image('forged-replacement.jpg'),
            ])
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertSessionHasErrors('attachment');

        $this->assertNotSoftDeleted('media_references', ['id' => $old->id]);
    }

    public function test_cross_activity_and_cross_folder_proof_removal_are_not_found(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'EXACT ACTIVITY');
        $otherActivity = $this->activityFor($folder, $ci, 'OTHER ACTIVITY', null, [], 1);
        $otherFolderActivity = $this->activityFor($otherFolder, $ci, 'OTHER FOLDER ACTIVITY');
        $media = $this->cloudinaryMedia($folder, $ci, 'exact-proof.jpg', 'BRBI-CIMS/clients/current/exact-proof');
        $activity->mediaReferences()->attach($media);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('delete');

        $this->actingAs($ci)
            ->delete(route('client-folders.activities.proof.destroy', [$folder, $otherActivity, $media]))
            ->assertNotFound();
        $this->delete(route('client-folders.activities.proof.destroy', [$otherFolder, $otherFolderActivity, $media]))
            ->assertNotFound();

        $this->assertNotSoftDeleted('media_references', ['id' => $media->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $media->id]);
    }

    public function test_edit_page_shows_preview_replace_and_confirmed_remove_controls_for_exact_proof(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'EDIT PROOF CONTROLS');
        $media = $this->cloudinaryMedia($folder, $ci, 'editable-proof.jpg', 'BRBI-CIMS/clients/current/editable-proof');
        $activity->mediaReferences()->attach($media);

        $this->actingAs($ci)
            ->get(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertOk()
            ->assertSee('View')
            ->assertSee('Replace')
            ->assertSee('Remove')
            ->assertSee(route('client-folders.activities.proof.content', [$folder, $activity, $media]), false)
            ->assertSee(route('client-folders.activities.proof.replace', [$folder, $activity, $media]), false)
            ->assertSee('data-modal-open="remove-proof-'.$media->id.'"', false)
            ->assertSee('id="remove-proof-'.$media->id.'"', false)
            ->assertSee('Cloud Storage')
            ->assertSee('Photos will be securely uploaded to cloud storage. Maximum 5 photos per activity.')
            ->assertSee('data-ci-add-photos-form', false)
            ->assertSee('data-ci-add-photos-submit', false);
    }

    public function test_non_completed_activity_edit_page_does_not_show_cloud_storage_replace_notice(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NON COMPLETED EDIT CLOUD NOTICE', null, [
            'status' => ActivityStatus::FollowUp,
            'completed_at' => null,
        ]);
        $media = $this->cloudinaryMedia($folder, $ci, 'non-completed-proof.jpg', 'BRBI-CIMS/clients/current/non-completed-proof');
        $activity->mediaReferences()->attach($media);

        $this->actingAs($ci)
            ->get(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertOk()
            ->assertDontSee('Photos will be securely uploaded to cloud storage')
            ->assertDontSee('Add Photos');
    }

    public function test_attachment_indicator_is_not_clickable_without_proof(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NO PROOF ACTIVITY');

        $response = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $response->assertOk()
            ->assertSee('No Attachment')
            ->assertDontSee('ci-proof-list-'.$activity->id, false)
            ->assertDontSee('ci-proof-preview-'.$activity->id.'-', false);
        $this->assertMatchesRegularExpression(
            '/data-submission-cell="'.$activity->id.'".*?<span[^>]*>.*?No Attachment<\/span>/s',
            $response->getContent(),
        );
    }

    public function test_single_image_attachment_opens_the_exact_protected_preview_read_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $submittedAt = now()->subHour()->startOfSecond();
        $activity = $this->activityFor($folder, $ci, 'SINGLE IMAGE PROOF', null, [
            'submitted_at' => $submittedAt,
            'submitted_by' => $ci->id,
            'submitted_to' => 'Credit Analyst',
        ]);
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'exact-field-photo.jpg',
            'mime_type' => 'image/jpeg',
            'temporary_local_path' => 'proof/exact-field-photo.jpg',
        ]);
        Storage::disk('local')->put($media->temporary_local_path, 'image-proof');
        $activity->mediaReferences()->attach($media, ['label' => 'Exact image proof']);
        $contentUrl = route('client-folders.activities.proof.content', [$folder, $activity, $media]);
        $previewId = 'ci-proof-preview-'.$activity->id.'-'.$media->id;

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('1 Attachment')
            ->assertSee('data-modal-open="'.$previewId.'"', false)
            ->assertSee('id="'.$previewId.'"', false)
            ->assertSee('src="'.$contentUrl.'"', false)
            ->assertSee('max-h-[65vh]', false);
        $this->get($contentUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('content-disposition', 'inline');
        $this->assertSame(MediaReference::STORAGE_PROVIDER_LOCAL, $media->fresh()->storage_provider);

        $activity->refresh();
        $this->assertTrue($activity->submitted_at->equalTo($submittedAt));
        $this->assertSame($ci->id, $activity->submitted_by);
        $this->assertSame(ActivityStatus::Completed, $activity->status);
    }

    public function test_single_document_attachment_uses_the_protected_inline_content_url(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'SINGLE DOCUMENT PROOF');
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'asset-result.pdf',
            'mime_type' => 'application/pdf',
            'temporary_local_path' => 'proof/asset-result.pdf',
        ]);
        Storage::disk('local')->put($media->temporary_local_path, '%PDF proof');
        $activity->mediaReferences()->attach($media, ['label' => 'Exact document proof']);
        $contentUrl = route('client-folders.activities.proof.content', [$folder, $activity, $media]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('1 Attachment')
            ->assertSee('href="'.$contentUrl.'" target="_blank" rel="noopener"', false)
            ->assertDontSee('ci-proof-preview-'.$activity->id.'-'.$media->id, false);
        $this->get($contentUrl)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline');
        $this->assertNull($activity->fresh()->submitted_at);
    }

    public function test_multiple_attachment_list_is_exact_to_activity_folder_and_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'EXACT SCOPE MAKER',
            'first_name' => 'Exact',
            'last_name' => 'Maker',
        ]);
        $activity = $this->activityFor($folder, $ci, 'MULTIPLE EXACT PROOF');
        $otherActivity = $this->activityFor($folder, $ci, 'OTHER APPLICANT ACTIVITY', null, [], 1);
        $coMakerActivity = $this->activityFor($folder, $ci, 'CO-MAKER MULTIPLE EXACT PROOF', $coMaker->id);
        $exactPhoto = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'field-photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $exactDocument = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'asset-result.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $otherActivityMedia = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'another-activity.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $coMakerMedia = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker->id,
            'uploaded_by' => $ci->id,
            'file_name' => 'co-maker-proof.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $otherFolderMedia = MediaReference::factory()->create([
            'client_folder_id' => $otherFolder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'other-folder-proof.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $coMakerDocument = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker->id,
            'uploaded_by' => $ci->id,
            'file_name' => 'co-maker-result.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $activity->mediaReferences()->attach([$exactPhoto->id, $exactDocument->id, $coMakerMedia->id, $otherFolderMedia->id]);
        $otherActivity->mediaReferences()->attach($otherActivityMedia);
        $coMakerActivity->mediaReferences()->attach([$coMakerMedia->id, $coMakerDocument->id, $exactPhoto->id]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $renderedActivity = $page->viewData('activities')->firstWhere('id', $activity->id);

        $page->assertOk()
            ->assertSee('data-modal-open="ci-proof-list-'.$activity->id.'"', false)
            ->assertSee('Attachments (2)');
        $this->assertSame([$exactPhoto->id, $exactDocument->id], $renderedActivity->mediaReferences->pluck('id')->all());
        $this->assertSame(2, $renderedActivity->media_references_count);

        $content = $page->getContent();
        $listStart = strpos($content, '<dialog id="ci-proof-list-'.$activity->id.'"');
        $this->assertNotFalse($listStart);
        $listEnd = strpos($content, '</dialog>', $listStart);
        $this->assertNotFalse($listEnd);
        $list = substr($content, $listStart, $listEnd - $listStart);
        $this->assertStringContainsString('field-photo.jpg', $list);
        $this->assertStringContainsString('asset-result.pdf', $list);
        $this->assertStringContainsString('data-modal-open="ci-proof-preview-'.$activity->id.'-'.$exactPhoto->id.'"', $list);
        $this->assertStringContainsString(route('client-folders.activities.proof.content', [$folder, $activity, $exactDocument]), $list);
        $this->assertStringContainsString(route('client-folders.activities.proof.content', [$folder, $activity, $exactPhoto]), $content);
        $this->assertStringNotContainsString('another-activity.jpg', $list);
        $this->assertStringNotContainsString('co-maker-proof.jpg', $list);
        $this->assertStringNotContainsString('other-folder-proof.jpg', $list);
        $this->assertNull($activity->fresh()->submitted_at);

        $coMakerPage = $this->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
            'status' => 'all',
        ]));
        $renderedCoMakerActivity = $coMakerPage->viewData('activities')->firstWhere('id', $coMakerActivity->id);
        $coMakerPage->assertOk()
            ->assertSee('data-modal-open="ci-proof-list-'.$coMakerActivity->id.'"', false)
            ->assertSee('Attachments (2)');
        $this->assertSame([$coMakerMedia->id, $coMakerDocument->id], $renderedCoMakerActivity->mediaReferences->pluck('id')->all());
        $this->assertSame(2, $renderedCoMakerActivity->media_references_count);
        $coMakerContent = $coMakerPage->getContent();
        $coMakerListStart = strpos($coMakerContent, '<dialog id="ci-proof-list-'.$coMakerActivity->id.'"');
        $this->assertNotFalse($coMakerListStart);
        $coMakerListEnd = strpos($coMakerContent, '</dialog>', $coMakerListStart);
        $this->assertNotFalse($coMakerListEnd);
        $coMakerList = substr($coMakerContent, $coMakerListStart, $coMakerListEnd - $coMakerListStart);
        $this->assertStringContainsString('co-maker-proof.jpg', $coMakerList);
        $this->assertStringContainsString('co-maker-result.pdf', $coMakerList);
        $this->assertStringNotContainsString('field-photo.jpg', $coMakerList);
        $this->assertStringNotContainsString('asset-result.pdf', $coMakerList);
        $this->assertNull($coMakerActivity->fresh()->submitted_at);
    }

    public function test_database_failure_after_mocked_cloudinary_upload_invokes_cleanup(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'DATABASE FAILURE CLEANUP');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($this->cloudinaryStored('cleanup-proof.jpg'));
        $storage->shouldReceive('delete')->once()->with('brbi-cims/test-proof', 'image');
        DB::statement("CREATE TRIGGER fail_cloudinary_media BEFORE INSERT ON media_references WHEN NEW.storage_provider = 'cloudinary' BEGIN SELECT RAISE(FAIL, 'simulated media insert failure'); END");

        try {
            app(UploadCiActivityProof::class)->execute($ci, $folder, $activity, UploadedFile::fake()->image('cleanup-proof.jpg'));
            $this->fail('The simulated media insert failure was not raised.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('simulated media insert failure', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_cloudinary_media');
        }

        $this->assertDatabaseCount('media_references', 0);
        $this->assertDatabaseCount('activity_media', 0);
    }

    public function test_cloudinary_proof_redirect_requires_authorization_and_exact_activity_linkage(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'AUTHORIZED CLOUDINARY PROOF');
        $otherActivity = $this->activityFor($folder, $ci, 'OTHER CLOUDINARY ACTIVITY', null, [], 1);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'CLOUD SCOPE MAKER',
            'first_name' => 'Cloud',
            'last_name' => 'Maker',
        ]);
        $coMakerActivity = $this->activityFor($folder, $ci, 'CO-MAKER CLOUDINARY ACTIVITY', $coMaker->id);
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'cloud-proof.jpg',
            'mime_type' => 'image/jpeg',
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'temporary_local_path' => null,
            'thumbnail_path' => null,
            'cloudinary_public_id' => 'brbi-cims/exact-cloud-proof',
            'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/exact-cloud-proof.jpg',
        ]);
        $activity->mediaReferences()->attach($media);
        $exactUrl = route('client-folders.activities.proof.content', [$folder, $activity, $media]);

        $this->get($exactUrl)->assertRedirect(route('login'));
        $this->actingAs($ci)->get($exactUrl)->assertRedirect('https://res.cloudinary.test/exact-cloud-proof.jpg');
        $this->get(route('client-folders.activities.proof.content', [$folder, $otherActivity, $media]))->assertNotFound();
        $coMakerActivity->mediaReferences()->attach($media);
        $this->get(route('client-folders.activities.proof.content', [$folder, $coMakerActivity, $media]))->assertNotFound();

        $activity->mediaReferences()->detach($media);
        $this->get($exactUrl)->assertNotFound();
    }

    public function test_custom_activity_type_json_success_keeps_the_add_modal_workflow_open(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Focused Verification',
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $response->assertOk()
            ->assertJson(['activity_created' => false])
            ->assertSessionHas('ci_activity_modal_open', true)
            ->assertSessionHas('status', 'Activity type created successfully.');
        $this->get($response->json('redirect'))
            ->assertOk()
            ->assertSee('open data-ci-activity-initial-open', false)
            ->assertSee('Focused Verification')
            ->assertDontSee('Activity added successfully.');

        $this->assertDatabaseCount('ci_activities', 0);
    }

    private function activityPayload(string $status): array
    {
        return [
            'activity_definition_id' => ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail()->id,
            'create_new_activity_type' => false,
            'status' => $status,
            'scheduled_at' => in_array($status, [ActivityStatus::Scheduled->value, ActivityStatus::FollowUp->value], true)
                ? now()->addDay()->toDateString()
                : null,
        ];
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
        $definition = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->skip($definitionOffset)->firstOrFail();

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
