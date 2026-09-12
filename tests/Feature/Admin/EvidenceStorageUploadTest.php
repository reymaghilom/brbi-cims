<?php

namespace Tests\Feature\Admin;

use App\Enums\ActivityStatus;
use App\Enums\MediaType;
use App\Enums\UserRole;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Settings\EvidenceStorageSetting;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Focused coverage for the administrator-controlled Evidence Storage provider as it applies to the
 * three covered features (Residence Check pictures, Business Check pictures, CI Activity Supporting
 * Proof). Cloudinary is always mocked — no real API call, no credentials, no bytes leave this
 * process. The recurring assertion throughout is that the SETTING only ever decides where a NEW
 * upload goes, while every read/remove follows the individual record's own stored provider.
 */
class EvidenceStorageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------- Residence Check

    public function test_residence_check_picture_is_stored_locally_in_the_ci_team_tree_in_local_mode(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ])->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->firstOrFail();
        $photo = $check->photos()->firstOrFail();
        $documents = app(CiTeamDocumentStorage::class);

        $this->assertFalse($photo->isCloud());
        $this->assertNull($photo->cloud_public_id);
        $this->assertStringStartsWith($documents->residenceCheckPicturesDirectory($folder).'/', $photo->path);
        $this->assertStringStartsWith($documents->residenceCheckMapDirectory($folder).'/', $check->map_screenshot_path);
        $this->assertTrue($documents->disk()->exists($photo->path));
        $this->assertTrue($documents->disk()->exists($check->map_screenshot_path));
    }

    public function test_residence_check_picture_is_stored_on_cloudinary_in_cloud_mode(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->useCloudEvidenceStorage();
        $this->mockCloud()->shouldReceive('store')->once()->andReturn($this->fakeCloudAsset('residence-cloud-1'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $photo = $folder->residenceChecks()->firstOrFail()->photos()->firstOrFail();
        $this->assertTrue($photo->isCloud());
        $this->assertSame('residence-cloud-1', $photo->cloud_public_id);
        $this->assertNull($photo->path);
    }

    public function test_co_maker_residence_pictures_are_stored_under_that_exact_co_maker_directory(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $first = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        $second = $folder->coMakers()->create(['full_name' => 'Jose Cruz', 'first_name' => 'Jose', 'last_name' => 'Cruz']);
        $documents = app(CiTeamDocumentStorage::class);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $first->id,
            'location' => 'Co-Maker Residence, San Miguel, Bulacan',
            'ci_date' => now()->toDateString(),
            'photos' => [UploadedFile::fake()->image('CoMaker.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $photo = $folder->residenceChecks()->where('co_maker_id', $first->id)->firstOrFail()->photos()->firstOrFail();
        $this->assertStringStartsWith($documents->residenceCheckPicturesDirectory($folder, $first).'/', $photo->path);
        $this->assertStringNotContainsString($documents->personDirectory($folder, $second), $photo->path);
    }

    // ---------------------------------------------------------------- Business Check

    public function test_business_check_picture_is_stored_locally_in_the_ci_team_tree_in_local_mode(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 1000, 800)->size(600),
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $photo = $check->photos()->firstOrFail();
        $documents = app(CiTeamDocumentStorage::class);

        $this->assertNull($photo->cloud_public_id);
        $this->assertStringStartsWith($documents->businessCheckPicturesDirectory($folder).'/', $photo->path);
        $this->assertStringStartsWith($documents->businessCheckMapDirectory($folder).'/', $check->map_screenshot_path);
        $this->assertTrue($documents->disk()->exists($photo->path));
    }

    public function test_business_check_picture_is_stored_on_cloudinary_in_cloud_mode_for_the_exact_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $target = $this->businessSource($folder, 'Target Store', 'San Miguel, Bulacan');
        $other = $this->businessSource($folder, 'Other Store', 'San Rafael, Bulacan');
        $this->useCloudEvidenceStorage();
        $this->mockCloud()->shouldReceive('store')->once()->andReturn($this->fakeCloudAsset('business-cloud-1'));

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $target->id, 'ci_date' => now()->toDateString(), 'location' => 'San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame($target->id, $check->income_source_id);
        $this->assertSame('business-cloud-1', $check->photos()->firstOrFail()->cloud_public_id);
        $this->assertSame(0, $folder->businessChecks()->where('income_source_id', $other->id)->count());
    }

    // ---------------------------------------------------------------- CI Activity Supporting Proof

    public function test_ci_activity_proof_is_stored_locally_in_the_ci_team_tree_in_local_mode(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci, 'LOCAL PROOF ACTIVITY');
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Proof.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $media = $activity->mediaReferences()->sole();
        $documents = app(CiTeamDocumentStorage::class);

        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $media->storage_provider);
        $this->assertNull($media->cloudinary_public_id);
        $this->assertStringStartsWith($documents->ciActivityProofDirectory($folder).'/', $media->temporary_local_path);
        $this->assertTrue($documents->disk()->exists($media->temporary_local_path));

        $this->actingAs($ci)
            ->get(route('client-folders.activities.proof.content', [$folder, $activity, $media]))
            ->assertOk();
    }

    public function test_ci_activity_proof_is_stored_on_cloudinary_in_cloud_mode(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci, 'CLOUD PROOF ACTIVITY');
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryCiActivityProofStorage::class)
            ->shouldReceive('store')->once()
            ->andReturn($this->cloudProofStored('Proof.jpg', 'BRBI-CIMS/clients/current/cloud-proof'));

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Proof.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $media = $activity->mediaReferences()->sole();
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CLOUDINARY, $media->storage_provider);
        $this->assertSame('BRBI-CIMS/clients/current/cloud-proof', $media->cloudinary_public_id);
        $this->assertNull($media->temporary_local_path);
    }

    public function test_co_maker_activity_proof_is_stored_under_that_exact_co_maker_directory(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        $otherCoMaker = $folder->coMakers()->create(['full_name' => 'Pedro Santos', 'first_name' => 'Pedro', 'last_name' => 'Santos']);
        $activity = $this->activity($folder, $ci, 'CO-MAKER PROOF', $coMaker->id);
        $documents = app(CiTeamDocumentStorage::class);
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('store');

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [
            $folder,
            $activity,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
        ]), [
            'photos' => [UploadedFile::fake()->image('Proof.jpg', 900, 700)->size(400)],
        ])->assertRedirect();

        $media = $activity->mediaReferences()->sole();
        $this->assertSame($media->id, $activity->mediaReferences()->sole()->id);
        $this->assertSame($coMaker->id, $media->co_maker_id);
        $this->assertNotSame($otherCoMaker->id, $media->co_maker_id);
        $this->assertStringStartsWith($documents->ciActivityProofDirectory($folder, $coMaker).'/', $media->temporary_local_path);
        $this->assertStringNotContainsString($documents->personDirectory($folder).'/CI Activities', $media->temporary_local_path);

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [
            $folder,
            $activity,
            'person' => 'co-maker',
            'co_maker_id' => $otherCoMaker->id,
        ]), [
            'photos' => [UploadedFile::fake()->image('Forged.jpg', 900, 700)->size(400)],
        ])->assertNotFound();
        $this->assertSame(1, $activity->mediaReferences()->count());
    }

    // ---------------------------------------------------------------- Switching and mixed history

    public function test_existing_local_and_cloud_pictures_both_stay_readable_across_a_storage_switch(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        // Saved while the pilot default (Local) is active.
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Local.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();
        $localPhoto = $check->photos()->firstOrFail();

        // The administrator switches to Cloud Storage; the historical local file must not move.
        $this->useCloudEvidenceStorage();
        $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($localPhoto->path));
        $this->assertNull($localPhoto->fresh()->cloud_public_id);

        $cloudPhoto = $check->photos()->create([
            'file_name' => 'Cloud.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 1024,
            'cloud_public_id' => 'BRBI-CIMS/clients/current/mixed-cloud', 'cloud_resource_type' => 'image',
            'cloud_delivery_type' => 'authenticated', 'uploaded_by' => $ci->id, 'sort_order' => 2,
        ]);
        $this->mockCloud()->shouldReceive('deliveryUrl')->andReturn('https://res.cloudinary.test/mixed-cloud.jpg');

        // One check now legitimately holds media from both providers; each resolves through its own.
        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $localPhoto]))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $cloudPhoto]))
            ->assertRedirect('https://res.cloudinary.test/mixed-cloud.jpg');

        // And back again: the Cloudinary record is still resolved through Cloudinary in Local mode.
        app(EvidenceStorageSetting::class)->update($this->administrator(), EvidenceStorageSetting::LOCAL);
        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $localPhoto]))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.residence-checks.photo', [$folder, $check, $cloudPhoto]))
            ->assertRedirect('https://res.cloudinary.test/mixed-cloud.jpg');
    }

    public function test_proof_removal_uses_each_records_own_provider_regardless_of_the_current_mode(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci, 'MIXED PROOF REMOVAL');

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Local.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();
        $localMedia = $activity->mediaReferences()->sole();
        $localPath = $localMedia->temporary_local_path;

        $cloudMedia = MediaReference::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'other',
            'file_name' => 'Cloud.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 2048, 'uploaded_by' => $ci->id,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'cloudinary_public_id' => 'BRBI-CIMS/clients/current/removable', 'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/removable.jpg',
        ]);
        $activity->mediaReferences()->attach($cloudMedia->id, ['label' => 'Cloud proof']);

        // Current mode is Cloud, yet the LOCAL record must still be removed from local storage.
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryCiActivityProofStorage::class)->shouldNotReceive('delete');
        $this->actingAs($ci)->delete(route('client-folders.activities.proof.destroy', [$folder, $activity, $localMedia]))
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]));
        $this->assertFalse(app(CiTeamDocumentStorage::class)->disk()->exists($localPath));

        // Back in Local mode, the CLOUD record must still be retired through Cloudinary.
        app(EvidenceStorageSetting::class)->update($this->administrator(), EvidenceStorageSetting::LOCAL);
        $this->mock(CloudinaryCiActivityProofStorage::class)
            ->shouldReceive('delete')->once()->with('BRBI-CIMS/clients/current/removable', 'image');
        $this->actingAs($ci)->delete(route('client-folders.activities.proof.destroy', [$folder, $activity, $cloudMedia]))
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]));
    }

    public function test_replacing_a_cloud_proof_while_local_is_active_saves_locally_and_retires_the_old_asset(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci, 'REPLACE ACROSS PROVIDERS');
        $old = MediaReference::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'media_type' => 'photo', 'category' => 'other',
            'file_name' => 'Old.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 2048, 'uploaded_by' => $ci->id,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'cloudinary_public_id' => 'BRBI-CIMS/clients/current/old-proof', 'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/old-proof.jpg',
        ]);
        $activity->mediaReferences()->attach($old->id, ['label' => 'Old proof']);

        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldNotReceive('store');
        $storage->shouldReceive('delete')->once()->with('BRBI-CIMS/clients/current/old-proof', 'image');

        $this->actingAs($ci)->put(route('client-folders.activities.proof.replace', [$folder, $activity, $old]), [
            'attachment' => UploadedFile::fake()->image('New.jpg', 900, 700)->size(400),
        ])->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]));

        $replacement = $activity->mediaReferences()->sole();
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $replacement->storage_provider);
        $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($replacement->temporary_local_path));
        $this->assertSoftDeleted('media_references', ['id' => $old->id]);
    }

    // ---------------------------------------------------------------- No silent fallback

    public function test_cloud_mode_without_a_configured_account_fails_instead_of_silently_saving_locally(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryMediaStorage::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enabled')->andReturn(false);
            $mock->shouldNotReceive('store');
        });

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('statusType', 'error');

        $this->assertSame(0, $folder->residenceChecks()->count());
        $this->assertSame([], app(CiTeamDocumentStorage::class)->disk()->allFiles());
    }

    public function test_local_mode_never_reaches_cloudinary_even_when_an_account_is_configured(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->mock(CloudinaryMediaStorage::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldNotReceive('store');
        });

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $photo = $folder->residenceChecks()->firstOrFail()->photos()->firstOrFail();
        $this->assertNull($photo->cloud_public_id);
        $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($photo->path));
    }

    // ---------------------------------------------------------------- Storage feedback

    public function test_local_residence_and_business_saves_report_local_storage_in_their_feedback(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('status', 'Residence Check saved successfully. Files saved to Local Storage.');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('status', 'Business Check saved successfully. Files saved to Local Storage.');
    }

    public function test_cloud_residence_and_business_saves_report_cloud_storage_in_their_feedback(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->useCloudEvidenceStorage();
        $this->mockCloud()->shouldReceive('store')->twice()->andReturn($this->fakeCloudAsset('feedback-cloud'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('status', 'Residence Check saved successfully. Files saved to Cloud Storage (Cloudinary).');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
        ])->assertSessionHas('status', 'Business Check saved successfully. Files saved to Cloud Storage (Cloudinary).');
    }

    public function test_a_text_only_update_never_claims_that_a_file_was_stored(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified with barangay confirmation.',
        ])->assertSessionHas('status', 'Residence Check updated successfully.');
    }

    public function test_ajax_check_saves_return_the_authoritative_provider_to_the_ui(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->actingAs($ci)
            ->postJson(route('client-folders.residence-checks.store', $folder), [
                'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            ])
            ->assertOk()
            ->assertJson([
                'result' => 'success',
                'storage_provider' => EvidenceStorageSetting::LOCAL,
                'storage_label' => 'Local Storage',
                'message' => 'Residence Check saved successfully. Files saved to Local Storage.',
            ]);
    }

    public function test_ci_activity_proof_upload_and_replacement_report_the_actual_new_provider(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $activity = $this->activity($folder, $ci, 'PROOF FEEDBACK');

        $this->actingAs($ci)->post(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('Proof.jpg', 900, 700)->size(400)],
        ])->assertSessionHas('status', 'Supporting Proof uploaded successfully. Files saved to Local Storage.');

        // A replacement follows the CURRENT mode, so its message names Cloud even though the file
        // it replaces was stored locally.
        $existing = $activity->mediaReferences()->sole();
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryCiActivityProofStorage::class)
            ->shouldReceive('store')->once()
            ->andReturn($this->cloudProofStored('New.jpg', 'BRBI-CIMS/clients/current/replacement'));

        $this->actingAs($ci)->put(route('client-folders.activities.proof.replace', [$folder, $activity, $existing]), [
            'attachment' => UploadedFile::fake()->image('New.jpg', 900, 700)->size(400),
        ])->assertSessionHas('status', 'Supporting Proof replaced successfully. New file saved to Cloud Storage (Cloudinary).');
    }

    // ---------------------------------------------------------------- Local directory safety

    public function test_local_evidence_uses_a_client_name_only_top_level_directory(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FF, FFF FF');
        $documents = app(CiTeamDocumentStorage::class);

        $this->assertSame('FF, FFF FF', $documents->evidenceClientDirectory($folder));
        $this->assertSame('FF, FFF FF/Residence Check Report/Pictures', $documents->residenceCheckPicturesDirectory($folder));
        $this->assertSame('FF, FFF FF/Residence Check Report/Google Map', $documents->residenceCheckMapDirectory($folder));
        $this->assertSame('FF, FFF FF/Business Check Report/Pictures', $documents->businessCheckPicturesDirectory($folder));
        $this->assertSame('FF, FFF FF/Business Check Report/Google Map', $documents->businessCheckMapDirectory($folder));
        $this->assertSame('FF, FFF FF/CI Activities/Supporting Proof', $documents->ciActivityProofDirectory($folder));
        $this->assertStringNotContainsString('CI-', $documents->evidenceClientDirectory($folder));
        $this->assertStringNotContainsString('Clients/', $documents->residenceCheckPicturesDirectory($folder));
    }

    public function test_co_maker_evidence_stays_in_its_own_branch_of_the_client_name_directory(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FF, FFF FF');
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        $documents = app(CiTeamDocumentStorage::class);
        $branch = 'FF, FFF FF/Co-Makers/CM-'.str_pad((string) $coMaker->id, 6, '0', STR_PAD_LEFT).' - Maria Santos';

        $this->assertSame($branch.'/Residence Check Report/Pictures', $documents->residenceCheckPicturesDirectory($folder, $coMaker));
        $this->assertSame($branch.'/Business Check Report/Pictures', $documents->businessCheckPicturesDirectory($folder, $coMaker));
        $this->assertSame($branch.'/CI Activities/Supporting Proof', $documents->ciActivityProofDirectory($folder, $coMaker));
        $this->assertStringNotContainsString('/Co-Makers/', $documents->residenceCheckPicturesDirectory($folder));
    }

    public function test_a_local_save_creates_only_the_active_clients_directory_and_no_clients_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FF, FFF FF');
        $otherFolder = $this->folder($ci, 'ZZ, OTHER CLIENT');
        $documents = app(CiTeamDocumentStorage::class);

        // Merely resolving another client's evidence paths must not put anything on disk.
        $documents->residenceCheckPicturesDirectory($otherFolder);
        $documents->businessCheckPicturesDirectory($otherFolder);
        $documents->ciActivityProofDirectory($otherFolder);
        $this->assertSame([], $documents->disk()->directories());

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['FF, FFF FF'], $documents->disk()->directories());
        $this->assertDirectoryDoesNotExist($documents->root().DIRECTORY_SEPARATOR.'Clients');
    }

    public function test_reading_a_missing_legacy_file_never_creates_the_legacy_clients_directory(): void
    {
        $documents = app(CiTeamDocumentStorage::class);

        $documents->evidenceDisk('NO SUCH CLIENT/Residence Check Report/Pictures/missing.jpg')->exists('x');

        $this->assertDirectoryDoesNotExist($documents->root().DIRECTORY_SEPARATOR.'Clients');
    }

    public function test_two_client_folders_sharing_a_name_never_share_an_evidence_directory(): void
    {
        $ci = User::factory()->create();
        $first = $this->folder($ci, 'DELA CRUZ, JUAN');
        $second = $this->folder($ci, 'DELA CRUZ, JUAN');
        $documents = app(CiTeamDocumentStorage::class);

        $this->assertNotSame(
            $documents->evidenceClientDirectory($first),
            $documents->evidenceClientDirectory($second),
        );
        $this->assertStringContainsString('DELA CRUZ, JUAN', $documents->evidenceClientDirectory($second));
        $this->assertStringContainsString('CI-', $documents->evidenceClientDirectory($second));
    }

    public function test_the_focused_test_suite_uses_a_temporary_ci_team_root(): void
    {
        $root = app(CiTeamDocumentStorage::class)->root();

        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '\\/'), $root);
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'CI Team', $root);
    }

    // ---------------------------------------------------------------- Helpers

    private CloudinaryMediaStorage $mockedCloud;

    /** Overrides the base helper so these cases switch modes through the real service, exercising the audited administrator path. */
    protected function useCloudEvidenceStorage(): void
    {
        app(EvidenceStorageSetting::class)->update($this->administrator(), EvidenceStorageSetting::CLOUDINARY);
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function mockCloud(): MockInterface
    {
        $this->mockedCloud = $this->mock(CloudinaryMediaStorage::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
        });

        return $this->mockedCloud;
    }

    /** @return array<string, mixed> */
    private function fakeCloudAsset(string $publicId): array
    {
        return [
            'file_name' => $publicId.'.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 123456,
            'checksum' => hash('sha256', $publicId), 'cloud_public_id' => $publicId,
            'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated',
            'cloud_format' => 'jpg', 'cloud_width' => 1600, 'cloud_height' => 1200,
        ];
    }

    /** @return array<string, mixed> */
    private function cloudProofStored(string $fileName, string $publicId): array
    {
        return [
            'media_type' => MediaType::Photo, 'file_name' => $fileName, 'mime_type' => 'image/jpeg',
            'byte_size' => 4096, 'checksum' => hash('sha256', $publicId),
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'temporary_local_path' => null, 'thumbnail_path' => null,
            'cloudinary_public_id' => $publicId, 'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/'.$publicId.'.jpg',
            'suggested_label' => 'Proof',
        ];
    }

    private function folder(User $ci, ?string $displayName = null): ClientFolder
    {
        $folder = ClientFolder::factory()->create(array_filter([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $displayName,
        ]));
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => null, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type,
            'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name,
        ]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }

    private function activity(ClientFolder $folder, User $ci, string $name, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id, 'name' => $name,
            'status' => ActivityStatus::Completed, 'completed_at' => now(), 'creator_id' => $ci->id,
        ]);
    }
}
