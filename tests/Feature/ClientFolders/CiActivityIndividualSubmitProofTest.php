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

class CiActivityIndividualSubmitProofTest extends TestCase
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

    public function test_non_completed_activity_renders_hidden_mark_as_submitted_link_and_modal_ready_for_async_reveal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'ASYNC READY PENDING', null, [
            'status' => ActivityStatus::Pending,
            'completed_at' => null,
        ]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('Not Submitted')
            ->assertSee('data-ci-submission-mark="'.$activity->id.'"', false)
            ->assertSee('id="submit-activity-'.$activity->id.'"', false);
        $this->assertMatchesRegularExpression(
            '/data-ci-submission-mark="'.$activity->id.'"[^>]*\bhidden\b/s',
            $page->getContent(),
        );
    }

    public function test_completed_unsubmitted_activity_renders_visible_mark_as_submitted_link(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'ASYNC READY COMPLETED');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()->assertSee('Not Submitted')->assertSee('Mark as Submitted');
        $this->assertDoesNotMatchRegularExpression(
            '/data-ci-submission-mark="'.$activity->id.'"[^>]*\bhidden\b/s',
            $page->getContent(),
        );
    }

    public function test_submission_summary_block_is_absent_but_form_fields_and_proof_remain(): void
    {
        $creator = User::factory()->create();
        $submitter = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activityFor($folder, $creator, 'SUMMARY REMOVED SUBMIT', null, [
            'submitted_at' => now(),
            'submitted_by' => $submitter->id,
            'submitted_to' => 'jennycel',
        ]);
        $media = $this->cloudinaryMedia($folder, $creator, 'summary-proof.jpg', 'brbi-cims/summary-proof');
        $activity->mediaReferences()->attach($media);

        $page = $this->actingAs($creator)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertDontSee('data-submission-summary', false)
            ->assertDontSee('Submitted By')
            ->assertDontSee('Submitted At')
            ->assertSee('Submitted To')
            ->assertSee('name="submitted_to"', false)
            ->assertSee('name="submission_note"', false)
            ->assertSee('Supporting Proof')
            ->assertSee('View')
            ->assertSee('View / Update Submission');

        $activity->refresh();
        $this->assertSame($submitter->id, $activity->submitted_by);
        $this->assertSame('jennycel', $activity->submitted_to);
        $this->assertNotNull($activity->submitted_at);
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertTrue($activity->mediaReferences()->whereKey($media->id)->exists());
    }

    public function test_existing_proof_shows_remove_control_with_exact_destroy_url(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'SHOW REMOVE PROOF CONTROL');
        $media = $this->cloudinaryMedia($folder, $ci, 'removable-proof.jpg', 'brbi-cims/removable-proof');
        $activity->mediaReferences()->attach($media);
        $destroyUrl = route('client-folders.activities.proof.destroy', [$folder, $activity, $media]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('1 / 5 attachments')
            ->assertSee('removable-proof.jpg')
            ->assertSee('data-ci-submission-remove-proof="'.$activity->id.'"', false)
            ->assertSee('data-ci-submission-remove-proof-url="'.$destroyUrl.'"', false)
            ->assertSee('id="remove-submission-proof-modal"', false)
            ->assertSee('Remove Supporting Proof?');
    }

    public function test_removing_proof_via_json_request_returns_success_and_detaches_exact_media(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'REMOVE PROOF ASYNC');
        $media = $this->cloudinaryMedia($folder, $ci, 'to-remove-proof.jpg', 'brbi-cims/to-remove-proof');
        $activity->mediaReferences()->attach($media);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('delete')->once()->with('brbi-cims/to-remove-proof', 'image');

        $this->actingAs($ci)->deleteJson(route('client-folders.activities.proof.destroy', [$folder, $activity, $media]))
            ->assertOk()
            ->assertJson(['removed' => true]);

        $this->assertSoftDeleted('media_references', ['id' => $media->id]);
        $this->assertFalse($activity->mediaReferences()->whereKey($media->id)->exists());
    }

    public function test_removing_one_of_five_allows_another_upload(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'REMOVE ONE OF FIVE');
        $media = collect(range(1, 5))->map(fn (int $i) => $this->cloudinaryMedia($folder, $ci, "photo-{$i}.jpg", "brbi-cims/photo-{$i}"));
        $activity->mediaReferences()->attach($media->pluck('id'));
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldReceive('delete')->once();

        $this->actingAs($ci)->deleteJson(route('client-folders.activities.proof.destroy', [$folder, $activity, $media->first()]))
            ->assertOk();

        $this->assertSame(4, $activity->mediaReferences()->count());

        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($this->cloudinaryStored('sixth-slot.jpg', 'brbi-cims/sixth-slot'));

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('sixth-slot.jpg')],
        ])->assertOk()->assertJson(['added' => true]);

        $this->assertSame(5, $activity->mediaReferences()->count());
    }

    public function test_submitted_activity_remains_submitted_after_proof_removal(): void
    {
        $creator = User::factory()->create();
        $submitter = User::factory()->create();
        $folder = $this->folderFor($creator);
        $submittedAt = now()->startOfSecond();
        $activity = $this->activityFor($folder, $creator, 'REMOVE PROOF KEEP SUBMISSION', null, [
            'submitted_at' => $submittedAt,
            'submitted_by' => $submitter->id,
            'submitted_to' => 'jennycel',
            'submission_note' => 'Verified in the field.',
        ]);
        $media = $this->cloudinaryMedia($folder, $creator, 'submitted-proof.jpg', 'brbi-cims/submitted-proof');
        $activity->mediaReferences()->attach($media);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldReceive('delete')->once();

        $this->actingAs($creator)->deleteJson(route('client-folders.activities.proof.destroy', [$folder, $activity, $media]))
            ->assertOk();

        $activity->refresh();
        $this->assertTrue($activity->submitted_at->equalTo($submittedAt));
        $this->assertSame($submitter->id, $activity->submitted_by);
        $this->assertSame('jennycel', $activity->submitted_to);
        $this->assertSame('Verified in the field.', $activity->submission_note);
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertFalse($activity->mediaReferences()->whereKey($media->id)->exists());
    }

    public function test_removing_proof_for_a_co_maker_activity_rejects_cross_person_media(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MAKER A', 'first_name' => 'Maker', 'last_name' => 'A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MAKER B', 'first_name' => 'Maker', 'last_name' => 'B']);
        $activityForA = $this->activityFor($folder, $ci, 'REMOVE PROOF MAKER A', $coMakerA->id);
        $activityForB = $this->activityFor($folder, $ci, 'REMOVE PROOF MAKER B', $coMakerB->id);
        $mediaForB = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerB->id,
            'uploaded_by' => $ci->id,
            'file_name' => 'maker-b-proof.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $activityForB->mediaReferences()->attach($mediaForB);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('delete');

        $this->actingAs($ci)->deleteJson(route('client-folders.activities.proof.destroy', [$folder, $activityForA, $mediaForB]))
            ->assertNotFound();

        $this->assertNotSoftDeleted('media_references', ['id' => $mediaForB->id]);
        $this->assertTrue($activityForB->mediaReferences()->whereKey($mediaForB->id)->exists());
    }

    public function test_mark_as_submitted_modal_shows_supporting_proof_section(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'MODAL PROOF SECTION');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('Supporting Proof')
            ->assertSee('(optional)')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('data-ci-submission-add-proof="'.$activity->id.'"', false)
            ->assertSee('data-ci-submission-add-proof-url="'.route('client-folders.activities.proof.store', [$folder, $activity]).'"', false);
    }

    public function test_modal_carries_cloud_storage_saving_state_marker_for_the_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'SAVING STATE MARKER');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('data-ci-submission-proof-status="'.$activity->id.'"', false)
            ->assertSee('data-ci-submission-proof-status-text', false);
    }

    public function test_activity_without_existing_proof_shows_zero_of_five_and_add_photos(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NO EXISTING PROOF MODAL');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('Supporting Proof')
            ->assertSee('0 / 5 attachments')
            ->assertSee('Add Photos');
    }

    public function test_activity_with_existing_proof_shows_view_and_replace_and_remove(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'EXISTING PROOF MODAL');
        $media = $this->cloudinaryMedia($folder, $ci, 'existing-modal-proof.jpg', 'brbi-cims/existing-modal-proof');
        $activity->mediaReferences()->attach($media);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('existing-modal-proof.jpg')
            ->assertSee('View')
            ->assertSee('Replace')
            ->assertSee('Remove')
            ->assertSee(route('client-folders.activities.proof.content', [$folder, $activity, $media]), false)
            ->assertSee('data-ci-submission-replace-proof="'.$activity->id.'"', false)
            ->assertSee('data-ci-submission-replace-proof-url="'.route('client-folders.activities.proof.replace', [$folder, $activity, $media]).'"', false);
    }

    public function test_five_of_five_hides_add_photos_and_shows_maximum_reached(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'MODAL FIVE OF FIVE');
        $media = collect(range(1, 5))->map(fn (int $i) => $this->cloudinaryMedia($folder, $ci, "modal-photo-{$i}.jpg", "brbi-cims/modal-photo-{$i}"));
        $activity->mediaReferences()->attach($media->pluck('id'));

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('5 / 5 attachments')
            ->assertSee('Maximum reached');
        $this->assertStringNotContainsString('data-ci-submission-add-proof="'.$activity->id.'"', $page->getContent());
    }

    public function test_cloud_storage_notice_remains_in_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'MODAL CLOUD NOTICE');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertSee('Cloud Storage')
            ->assertSee('Photos will be securely uploaded to cloud storage. Maximum 5 photos per activity.');
    }

    public function test_redundant_submitted_by_helper_sentence_is_absent(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NO REDUNDANT HELPER SENTENCE');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $page->assertOk()
            ->assertDontSee('records the authenticated user completing this handoff');
    }

    public function test_submission_with_no_proof_still_succeeds(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NO PROOF INDIVIDUAL SUBMIT');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'submitted_to' => 'Ana Credit Analyst',
        ])->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $activity->refresh();
        $this->assertNotNull($activity->submitted_at);
        $this->assertSame($ci->id, $activity->submitted_by);
        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_adding_multiple_photos_via_dedicated_endpoint_attaches_to_exact_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'MULTI PHOTO ADD');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->twice()->andReturn(
            $this->cloudinaryStored('photo-a.jpg', 'brbi-cims/photo-a'),
            $this->cloudinaryStored('photo-b.jpg', 'brbi-cims/photo-b'),
        );

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [
                UploadedFile::fake()->image('photo-a.jpg'),
                UploadedFile::fake()->image('photo-b.jpg'),
            ],
        ])->assertOk()->assertJson(['added' => true]);

        $this->assertSame(2, $activity->mediaReferences()->count());
        foreach ($activity->mediaReferences()->pluck('media_references.id') as $mediaId) {
            $this->assertSame($activity->co_maker_id, MediaReference::find($mediaId)->co_maker_id);
        }
    }

    public function test_five_total_photos_accepted_sixth_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'FIVE ACCEPTED SIXTH REJECTED');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->times(5)->andReturn(
            $this->cloudinaryStored('p1.jpg', 'brbi-cims/p1'),
            $this->cloudinaryStored('p2.jpg', 'brbi-cims/p2'),
            $this->cloudinaryStored('p3.jpg', 'brbi-cims/p3'),
            $this->cloudinaryStored('p4.jpg', 'brbi-cims/p4'),
            $this->cloudinaryStored('p5.jpg', 'brbi-cims/p5'),
        );

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => collect(range(1, 5))->map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"))->all(),
        ])->assertOk();
        $this->assertSame(5, $activity->mediaReferences()->count());

        $storage->shouldNotReceive('store');
        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('sixth.jpg')],
        ])->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->assertSame(5, $activity->mediaReferences()->count());
    }

    public function test_existing_three_plus_new_two_succeeds_existing_three_plus_new_three_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'THREE PLUS TWO OR THREE');
        $existing = collect(range(1, 3))->map(fn (int $i) => $this->cloudinaryMedia($folder, $ci, "existing-{$i}.jpg", "brbi-cims/existing-{$i}"));
        $activity->mediaReferences()->attach($existing->pluck('id'));

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('extra.jpg'), UploadedFile::fake()->image('extra2.jpg'), UploadedFile::fake()->image('extra3.jpg')],
        ])->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->assertSame(3, $activity->mediaReferences()->count());

        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->twice()->andReturn(
            $this->cloudinaryStored('extra.jpg', 'brbi-cims/extra'),
            $this->cloudinaryStored('extra2.jpg', 'brbi-cims/extra2'),
        );
        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('extra.jpg'), UploadedFile::fake()->image('extra2.jpg')],
        ])->assertOk();
        $this->assertSame(5, $activity->mediaReferences()->count());
    }

    public function test_jpg_jpeg_png_webp_are_accepted_via_dedicated_endpoint(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'FORMATS ACCEPTED');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->times(3)->andReturn(
            $this->cloudinaryStored('a.jpg', 'brbi-cims/a'),
            $this->cloudinaryStored('b.png', 'brbi-cims/b'),
            $this->cloudinaryStored('c.webp', 'brbi-cims/c'),
        );

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.webp'),
            ],
        ])->assertOk();

        $this->assertSame(3, $activity->mediaReferences()->count());
    }

    public function test_mp4_video_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'MP4 REJECTED');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4')],
        ])->assertStatus(422)->assertJsonValidationErrors('photos.0');

        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_mov_video_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'MOV REJECTED');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->create('clip.mov', 500, 'video/quicktime')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_webm_video_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'WEBM REJECTED');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->create('clip.webm', 500, 'video/webm')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_pdf_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'PDF REJECTED');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->create('document.pdf', 500, 'application/pdf')],
        ])->assertStatus(422);

        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_individual_view_works_for_exact_proof(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'INDIVIDUAL VIEW');
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'uploaded_by' => $ci->id,
            'file_name' => 'viewable.jpg',
            'mime_type' => 'image/jpeg',
            'temporary_local_path' => 'proof/viewable.jpg',
        ]);
        Storage::disk('local')->put($media->temporary_local_path, 'image-bytes');
        $activity->mediaReferences()->attach($media);

        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_individual_replace_changes_only_selected_proof_and_count_stays_the_same(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'REPLACE ONLY SELECTED');
        $photoA = $this->cloudinaryMedia($folder, $ci, 'photo-a.jpg', 'brbi-cims/photo-a-orig');
        $photoB = $this->cloudinaryMedia($folder, $ci, 'photo-b.jpg', 'brbi-cims/photo-b-orig');
        $photoC = $this->cloudinaryMedia($folder, $ci, 'photo-c.jpg', 'brbi-cims/photo-c-orig');
        $activity->mediaReferences()->attach([$photoA->id, $photoB->id, $photoC->id]);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($this->cloudinaryStored('photo-b-new.jpg', 'brbi-cims/photo-b-new'));
        $storage->shouldReceive('delete')->once()->with('brbi-cims/photo-b-orig', 'image');

        $this->actingAs($ci)->put(route('client-folders.activities.proof.replace', [$folder, $activity, $photoB]), [
            'attachment' => UploadedFile::fake()->image('photo-b-new.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJson(['replaced' => true]);

        $this->assertSame(3, $activity->mediaReferences()->count());
        $this->assertTrue($activity->mediaReferences()->whereKey($photoA->id)->exists());
        $this->assertTrue($activity->mediaReferences()->whereKey($photoC->id)->exists());
        $this->assertSoftDeleted('media_references', ['id' => $photoB->id]);
    }

    public function test_applicant_and_co_maker_isolation_is_enforced_for_individual_submission(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MAKER A', 'first_name' => 'Maker', 'last_name' => 'A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MAKER B', 'first_name' => 'Maker', 'last_name' => 'B']);
        $activityForB = $this->activityFor($folder, $ci, 'CO-MAKER B INDIVIDUAL', $coMakerB->id);

        $this->actingAs($ci)->patch(route('client-folders.activities.submit', [$folder, $activityForB]), [
            'submission_activity_id' => $activityForB->id,
            'co_maker_id' => $coMakerA->id,
        ])->assertForbidden();

        $this->assertNull($activityForB->fresh()->submitted_at);
    }

    public function test_proof_store_rejects_cross_folder_activity_substitution(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $foreignActivity = $this->activityFor($otherFolder, $ci, 'PROOF STORE FOREIGN FOLDER');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $foreignActivity]), [
            'photos' => [UploadedFile::fake()->image('foreign.jpg')],
        ])->assertNotFound();

        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_cross_folder_activity_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $foreignActivity = $this->activityFor($otherFolder, $ci, 'FOREIGN FOLDER INDIVIDUAL');

        $this->patch(route('client-folders.activities.submit', [$folder, $foreignActivity]), [
            'submission_activity_id' => $foreignActivity->id,
        ])->assertRedirect(route('login'));

        $this->actingAs($ci)->patch(route('client-folders.activities.submit', [$folder, $foreignActivity]), [
            'submission_activity_id' => $foreignActivity->id,
        ])->assertNotFound();

        $this->assertNull($foreignActivity->fresh()->submitted_at);
    }

    public function test_non_completed_activity_cannot_be_submitted(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder, $ci, 'NON COMPLETED INDIVIDUAL', null, ['status' => ActivityStatus::FollowUp, 'completed_at' => null]);

        $this->actingAs($ci)->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
        ])->assertSessionHasErrors('submission_activity_id', null, 'submission');

        $this->assertNull($activity->fresh()->submitted_at);
    }

    public function test_submission_fields_and_audit_log_remain_correct_alongside_proof_upload(): void
    {
        $creator = User::factory()->create();
        $submitter = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activityFor($folder, $creator, 'AUDIT INDIVIDUAL SUBMIT');
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($this->cloudinaryStored('audit-proof.jpg'));

        $this->actingAs($submitter)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('audit-proof.jpg')],
        ])->assertOk();

        $this->actingAs($submitter)->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'submitted_to' => 'Ben Credit Analyst',
            'submission_note' => 'Individual submission audit check.',
        ])->assertRedirect();

        $activity->refresh();
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($submitter->id, $activity->submitted_by);
        $this->assertSame('Ben Credit Analyst', $activity->submitted_to);
        $this->assertSame('Individual submission audit check.', $activity->submission_note);
        $this->assertSame(1, $activity->mediaReferences()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.submitted',
            'user_id' => $submitter->id,
            'client_folder_id' => $folder->id,
            'metadata->activity_id' => $activity->id,
            'metadata->submitted_to' => 'Ben Credit Analyst',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'media.uploaded',
            'user_id' => $submitter->id,
            'client_folder_id' => $folder->id,
        ]);
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
