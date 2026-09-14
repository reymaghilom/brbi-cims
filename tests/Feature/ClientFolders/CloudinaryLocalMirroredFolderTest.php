<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use App\Services\Media\CloudinaryMediaStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * NEW Cloudinary uploads mirror the Local CI Team hierarchy below the client, rooted at the stable
 * NUMBERED client directory ("CI-2026-001 - NAME") that Local official reports already use.
 *
 * Every path here is asserted literally. Nothing reaches Cloudinary: folder resolution is pure string
 * building, and the one upload flow uses a mocked CloudinaryMediaStorage. Existing assets keep their
 * stored public_id, which stays the only delete/replace target.
 */
class CloudinaryLocalMirroredFolderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        config()->set('cloudinary.root_folder', 'BRBI-CIMS');
    }

    public function test_applicant_folders_mirror_the_local_module_names_under_the_numbered_client_directory(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'BRBI-CI-2026-00001', 'DELA CRUZ, JUAN');
        $uploader = app(ClientMediaUploader::class);
        $root = 'BRBI-CIMS/CI-2026-001 - DELA CRUZ, JUAN';

        $this->assertSame($root.'/Residence Check Report/Pictures', $uploader->rootedPersonCloudFolder($folder, 'residence/photos'));
        $this->assertSame($root.'/Residence Check Report/Google Map', $uploader->rootedPersonCloudFolder($folder, 'residence/map-screenshots'));
        $this->assertSame($root.'/Business Check Report/Pictures', $uploader->rootedPersonCloudFolder($folder, 'business/photos'));
        $this->assertSame($root.'/Business Check Report/Google Map', $uploader->rootedPersonCloudFolder($folder, 'business/map-screenshots'));
        $this->assertSame($root.'/CI Activities/Supporting Proof', app(CloudinaryCiActivityProofStorage::class)->folderFor($folder, $this->activity($folder, $ci)));
    }

    public function test_co_makers_same_names_and_separate_client_folders_never_collide(): void
    {
        $ci = User::factory()->create();
        $first = $this->folder($ci, 'BRBI-CI-2026-00001', 'DELA CRUZ, JUAN');
        $second = $this->folder($ci, 'BRBI-CI-2026-00002', 'DELA CRUZ, JUAN');
        $coMakerA = CoMaker::create(['client_folder_id' => $first->id, 'full_name' => 'MARIA SANTOS']);
        $coMakerB = CoMaker::create(['client_folder_id' => $first->id, 'full_name' => 'MARIA SANTOS']);
        $uploader = app(ClientMediaUploader::class);
        $proof = app(CloudinaryCiActivityProofStorage::class);

        $pad = fn (CoMaker $coMaker): string => str_pad((string) $coMaker->id, 6, '0', STR_PAD_LEFT);
        $firstRoot = 'BRBI-CIMS/CI-2026-001 - DELA CRUZ, JUAN';

        $applicant = $uploader->rootedPersonCloudFolder($first, 'residence/photos');
        $a = $uploader->rootedPersonCloudFolder($first, 'residence/photos', $coMakerA);
        $b = $uploader->rootedPersonCloudFolder($first, 'residence/photos', $coMakerB);
        $otherClient = $uploader->rootedPersonCloudFolder($second, 'residence/photos');

        $this->assertSame($firstRoot.'/Co-Makers/CM-'.$pad($coMakerA).' - MARIA SANTOS/Residence Check Report/Pictures', $a);
        $this->assertSame($firstRoot.'/Co-Makers/CM-'.$pad($coMakerB).' - MARIA SANTOS/Residence Check Report/Pictures', $b);
        $this->assertSame('BRBI-CIMS/CI-2026-002 - DELA CRUZ, JUAN/Residence Check Report/Pictures', $otherClient);
        $this->assertCount(4, array_unique([$applicant, $a, $b, $otherClient]), 'Applicant, each Co-Maker and each same-name client stay separate.');

        $this->assertSame(
            $firstRoot.'/Co-Makers/CM-'.$pad($coMakerB).' - MARIA SANTOS/CI Activities/Supporting Proof',
            $proof->folderFor($first, $this->activity($first, $ci, $coMakerB)),
        );
        $this->assertSame(
            $firstRoot.'/Co-Makers/CM-'.$pad($coMakerA).' - MARIA SANTOS/Business Check Report/Google Map',
            $uploader->rootedPersonCloudFolder($first, 'business/map-screenshots', $coMakerA),
        );
    }

    public function test_characters_cloudinary_rejects_are_normalized_while_names_stay_readable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'BRBI-CI-2026-00003', 'SANTOS & SONS #1 50% <A+B>? Trading');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'JOSE R&D + CO']);

        $path = app(ClientMediaUploader::class)->rootedPersonCloudFolder($folder, 'business/photos', $coMaker);

        $this->assertSame(
            'BRBI-CIMS/CI-2026-003 - SANTOS and SONS 1 50 A B Trading/Co-Makers/CM-'.str_pad((string) $coMaker->id, 6, '0', STR_PAD_LEFT).' - JOSE R and D CO/Business Check Report/Pictures',
            $path,
        );
        $this->assertDoesNotMatchRegularExpression('/[?&#\\\\%<>+]/', $path);
    }

    public function test_a_real_upload_uses_the_new_folder_while_replace_retires_the_exact_stored_legacy_public_id(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'BRBI-CI-2026-00004', 'REYES, ANA');
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $newFolder = 'CI-2026-004 - REYES, ANA/Residence Check Report/Pictures';
        $legacyPublicId = 'BRBI-CIMS/clients/CF-'.$folder->id.'-reyes-ana/applicant/residence/photos/legacy-uuid';

        $cloud = $this->mockCloud();
        // The photo that exists today carries an old-layout public_id.
        $cloud->shouldReceive('store')->once()->with(\Mockery::type(UploadedFile::class), $newFolder, 'photo')->andReturn($this->asset($legacyPublicId));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Old.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();
        $legacyPhoto = $check->photos()->sole();
        $this->assertSame($legacyPublicId, $legacyPhoto->cloud_public_id);

        // Replacing it uploads into the new Local-style folder, and retires exactly the STORED id —
        // never one reconstructed from the new folder layout.
        $cloud->shouldReceive('store')->once()->with(\Mockery::type(UploadedFile::class), $newFolder, 'photo')
            ->andReturn($this->asset('BRBI-CIMS/'.$newFolder.'/new-uuid'));
        $cloud->shouldReceive('destroy')->once()->with($legacyPublicId, 'image', 'authenticated');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'expected_revision' => $check->revision,
            'removed_photo_ids' => [$legacyPhoto->id],
            'photos' => [UploadedFile::fake()->image('New.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['BRBI-CIMS/'.$newFolder.'/new-uuid'], $check->photos()->pluck('cloud_public_id')->all());
    }

    private function mockCloud(): MockInterface
    {
        $this->useCloudEvidenceStorage();

        return $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldReceive('enabled')->andReturn(true);
        });
    }

    /** @return array<string, mixed> */
    private function asset(string $publicId): array
    {
        return [
            'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 1234, 'checksum' => hash('sha256', $publicId),
            'cloud_public_id' => $publicId, 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated',
            'cloud_format' => 'jpg', 'cloud_width' => 1600, 'cloud_height' => 1200,
        ];
    }

    private function folder(User $ci, string $number, string $name): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'folder_number' => $number, 'display_name' => $name]);
    }

    private function activity(ClientFolder $folder, User $ci, ?CoMaker $coMaker = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMaker?->id,
            'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => ActivityStatus::Pending, 'creator_id' => $ci->id,
        ]);
    }
}
