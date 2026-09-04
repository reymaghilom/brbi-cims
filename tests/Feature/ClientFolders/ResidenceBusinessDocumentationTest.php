<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\Media\UploadMedia;
use App\Enums\MediaType;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Media\DocumentationCaptionBuilder;
use App\Services\Media\DocumentationStorageException;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class ResidenceBusinessDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_a_draft_documentation_set_can_be_created_and_updated_for_the_applicant(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Bugo, Cagayan de Oro',
        ])->assertRedirect();

        $documentation = ResidenceBusinessDocumentation::sole();
        $this->assertNull($documentation->co_maker_id);
        $this->assertSame(ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, $documentation->category);
        $this->assertDatabaseHas('audit_logs', ['action' => 'residence_business_documentation.created', 'client_folder_id' => $folder->id]);

        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'location' => 'Carmen, Cagayan de Oro',
        ])->assertRedirect()->assertSessionHasErrors('category');

        $documentation->refresh();
        $this->assertSame(ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, $documentation->category);
        $this->assertSame('Bugo, Cagayan de Oro', $documentation->location);
        $this->assertSame(1, ResidenceBusinessDocumentation::count());
    }

    public function test_documentation_sets_are_scoped_to_the_exact_active_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Applicant location',
        ])->assertRedirect();

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'business_name' => 'CO-MAKER BUSINESS',
            'location' => 'Co-Maker location',
            'co_maker_id' => $coMaker->id,
        ])->assertRedirect();

        $applicantDoc = ResidenceBusinessDocumentation::whereNull('co_maker_id')->sole();
        $coMakerDoc = ResidenceBusinessDocumentation::whereNotNull('co_maker_id')->sole();
        $this->assertSame($coMaker->id, $coMakerDoc->co_maker_id);

        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))
            ->assertOk()->assertSee('Applicant location')->assertDontSee('Co-Maker location');
        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id, 'tab' => 'business']))
            ->assertOk()->assertSee('Co-Maker location')->assertDontSee('Applicant location');
    }

    public function test_map_screenshot_can_be_uploaded_replaced_and_removed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
        ])->assertRedirect();

        $documentation->refresh();
        $this->assertNotNull($documentation->map_screenshot_media_id);
        $firstMediaId = $documentation->map_screenshot_media_id;

        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map-2.png', 600, 400),
        ])->assertRedirect();

        $documentation->refresh();
        $this->assertNotSame($firstMediaId, $documentation->map_screenshot_media_id);
        $this->assertSoftDeleted('media_references', ['id' => $firstMediaId]);

        $secondMediaId = $documentation->map_screenshot_media_id;
        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'remove_map_screenshot' => '1',
        ])->assertRedirect();

        $documentation->refresh();
        $this->assertNull($documentation->map_screenshot_media_id);
        $this->assertSoftDeleted('media_references', ['id' => $secondMediaId]);
    }

    public function test_pictures_and_videos_can_be_uploaded_and_removed_from_a_documentation_set(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();

        $video = UploadedFile::fake()->createWithContent('house.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'video',
            'files' => [$video],
        ])->assertRedirect();

        $documentation->refresh();
        $this->assertSame(1, $documentation->pictures()->count());
        $this->assertSame(1, $documentation->videos()->count());

        $picture = $documentation->pictures()->sole();
        $this->actingAs($ci)->delete(route('client-folders.media.documentation.destroy-media', [$folder, $documentation, $picture]))
            ->assertRedirect();
        $this->assertSoftDeleted('media_references', ['id' => $picture->id]);
    }

    public function test_a_video_kind_upload_rejects_an_image_file(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->from(route('client-folders.media.index', $folder))
            ->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
                'kind' => 'video',
                'files' => [UploadedFile::fake()->image('not-a-video.jpg')],
            ])->assertSessionHasErrors('files.0');

        $this->assertSame(0, $documentation->videos()->count());
    }

    public function test_php_upload_limit_failure_has_a_friendly_message_and_preserves_saved_documentation(): void
    {
        config(['cims.media.php_upload_max_filesize' => '2M']);
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Saved Residence Address');
        $documentation->update(['remarks' => 'Saved remarks']);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();
        $documentation->refresh();
        $mapId = $documentation->map_screenshot_media_id;

        $phpRejectedVideo = new UploadedFile(__FILE__, 'too-large.mp4', 'video/mp4', UPLOAD_ERR_INI_SIZE, true);
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $phpRejectedVideo->getError());
        $response = $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Changed only if validation incorrectly succeeds',
            'remarks' => 'Changed only if validation incorrectly succeeds',
            'videos' => [$phpRejectedVideo],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        $response->assertUnprocessable()->assertJsonValidationErrors('videos.0');
        $this->assertContains(
            'The selected video is too large for the PHP server to upload. Current server file limit is 2M.',
            $response->json('errors')['videos.0'],
        );
        $documentation->refresh();
        $this->assertSame('Saved Residence Address', $documentation->location);
        $this->assertSame('Saved remarks', $documentation->remarks);
        $this->assertSame($mapId, $documentation->map_screenshot_media_id);
        $this->assertSame(1, $documentation->pictures()->count());
        $this->assertSame(0, $documentation->videos()->count());
    }

    public function test_unsupported_and_application_over_limit_videos_have_friendly_messages(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        $unsupported = UploadedFile::fake()->createWithContent('clip.webm', 'not-a-supported-video');
        $response = $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => $documentation->location,
            'videos' => [$unsupported],
        ], $headers);
        $response->assertUnprocessable()->assertJsonValidationErrors('videos.0');
        $this->assertContains('The selected video format is not supported. Upload an MP4 video.', $response->json('errors')['videos.0']);

        $tooLarge = UploadedFile::fake()->create('clip.mp4', config('cims.media.video_max_kilobytes') + 1, 'video/mp4');
        $response = $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => $documentation->location,
            'videos' => [$tooLarge],
        ], $headers);
        $response->assertUnprocessable()->assertJsonValidationErrors('videos.0');
        $this->assertContains('The selected video is too large to upload. Maximum allowed size is 50 MB.', $response->json('errors')['videos.0']);
        $this->assertSame(0, $documentation->videos()->count());
    }

    public function test_save_locally_stores_valid_residence_and_business_videos_in_their_ci_team_folders(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $residence = $this->documentationFor($folder);
        $business = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Business Location');
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        $mp4Bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat";

        foreach ([$residence, $business] as $documentation) {
            $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
                '_method' => 'PATCH',
                'category' => $documentation->category,
                'location' => $documentation->location,
                'videos' => [UploadedFile::fake()->createWithContent('walkthrough.mp4', $mp4Bytes)],
            ], $headers)->assertOk()->assertJson(['result' => 'success']);
        }

        $residencePath = str_replace('\\', '/', $residence->videos()->sole()->temporary_local_path);
        $businessPath = str_replace('\\', '/', $business->videos()->sole()->temporary_local_path);
        $this->assertStringContainsString('/Residence Pictures/Videos/', '/'.$residencePath);
        $this->assertStringContainsString('/Business Pictures/BD-'.str_pad((string) $business->id, 6, '0', STR_PAD_LEFT).'/Videos/', '/'.$businessPath);
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $residence->videos()->sole()->storage_provider);
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $business->videos()->sole()->storage_provider);
    }

    public function test_storage_failure_returns_a_safe_backend_message_and_preserves_existing_saved_media(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Saved Address');
        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();
        $documentation->refresh();
        $mapId = $documentation->map_screenshot_media_id;
        $pictureId = $documentation->pictures()->sole()->id;

        $this->mock(UploadMedia::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(
                new DocumentationStorageException('Unable to save the video to local CI Team storage.'),
            );
        });
        $mp4 = UploadedFile::fake()->createWithContent('walkthrough.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");
        $response = $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Saved Address',
            'videos' => [$mp4],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        $response->assertStatus(500)->assertJson([
            'result' => 'storage_failure',
            'message' => 'Unable to save the video to local CI Team storage.',
        ]);
        $documentation->refresh();
        $this->assertSame($mapId, $documentation->map_screenshot_media_id);
        $this->assertDatabaseHas('media_references', ['id' => $pictureId, 'deleted_at' => null]);
        $this->assertSame(0, $documentation->videos()->count());

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('if (payload?.message)', $javascript);
        $this->assertStringContainsString("showToast(payload.message, 'error')", $javascript);
        $this->assertStringContainsString("xhr.addEventListener('error'", $javascript);
        $this->assertStringContainsString('clearStagedUploads();', $javascript);
    }

    public function test_a_late_video_failure_rolls_back_earlier_staged_media_before_an_exact_retry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Original address');
        $realStorage = app(PrivateMediaStorage::class);
        $failNextVideo = true;

        $this->mock(PrivateMediaStorage::class, function (MockInterface $mock) use ($realStorage, &$failNextVideo): void {
            $mock->shouldReceive('storeDocumentation')->andReturnUsing(
                function (ResidenceBusinessDocumentation $documentation, UploadedFile $file, string $kind) use ($realStorage, &$failNextVideo): array {
                    if ($kind === 'video' && $failNextVideo) {
                        $failNextVideo = false;

                        throw new DocumentationStorageException('Unable to save the video to local CI Team storage.');
                    }

                    return $realStorage->storeDocumentation($documentation, $file, $kind);
                },
            );
            $mock->shouldReceive('deleteStoredFiles')->andReturnUsing(
                fn (array $paths, string $provider) => $realStorage->deleteStoredFiles($paths, $provider),
            );
        });

        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        $payload = fn (): array => [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Address submitted with media',
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
            'pictures' => [
                UploadedFile::fake()->image('front.jpg'),
                UploadedFile::fake()->image('side.jpg'),
            ],
            'videos' => [UploadedFile::fake()->createWithContent('walkthrough.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat")],
        ];

        $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), $payload(), $headers)
            ->assertStatus(500)
            ->assertJson(['result' => 'storage_failure']);

        $documentation->refresh();
        $this->assertSame('Original address', $documentation->location);
        $this->assertNull($documentation->map_screenshot_media_id);
        $this->assertSame(0, $documentation->pictures()->count());
        $this->assertSame(0, $documentation->videos()->count());
        $this->assertSame(0, MediaReference::withTrashed()->where('residence_business_documentation_id', $documentation->id)->count());
        $this->assertSame(0, AuditLog::where('client_folder_id', $folder->id)->count(), 'Rolled-back Save Locally must roll back its activity too.');
        $this->assertSame([], app(CiTeamDocumentStorage::class)->disk()->allFiles());

        $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), $payload(), $headers)
            ->assertOk()
            ->assertJson(['result' => 'success']);

        $documentation->refresh();
        $this->assertSame('Address submitted with media', $documentation->location);
        $this->assertNotNull($documentation->map_screenshot_media_id);
        $this->assertSame(2, $documentation->pictures()->count());
        $this->assertSame(1, $documentation->videos()->count());
        $this->assertSame(4, MediaReference::withTrashed()->where('residence_business_documentation_id', $documentation->id)->count());
        $disk = app(CiTeamDocumentStorage::class)->disk();
        $map = $documentation->mapScreenshot()->sole();
        $mapFiles = $disk->allFiles(dirname($map->temporary_local_path));
        $this->assertSame(1, count($mapFiles), 'The map has exactly one original and no generated thumbnail.');
        $this->assertNull($map->thumbnail_path);
        $pictureFiles = $disk->allFiles(dirname($documentation->pictures()->first()->temporary_local_path));
        $videoFiles = $disk->allFiles(dirname($documentation->videos()->sole()->temporary_local_path));
        $this->assertSame(2, count($pictureFiles), 'Two uploaded pictures produce exactly two physical files.');
        $this->assertSame([], array_values(array_filter($pictureFiles, fn (string $path): bool => str_contains($path, '.thumb.'))));
        $this->assertSame(1, count($videoFiles));
        foreach ($documentation->mediaReferences as $media) {
            $this->assertStringNotContainsString('Clients/', str_replace('\\', '/', (string) $media->temporary_local_path));
            $this->assertNull($media->thumbnail_path);
        }
        $savedFileCount = count($disk->allFiles());

        $reloaded = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $xpath = $this->xpath($reloaded->getContent());
        $this->assertSame(1, $xpath->query("//*[@id='residence-documentation-panel']//*[@data-map-screenshot-preview-img]")->length);
        $this->assertSame(2, $xpath->query("//*[@id='residence-documentation-panel']//*[@data-photo-upload-existing-tile]")->length);
        $this->assertSame(0, $xpath->query("//*[@id='residence-documentation-panel']//*[@data-photo-upload-grid]/*[@data-photo-upload-new-tile]")->length);
        $this->assertStringNotContainsString('thumbnail=1', $reloaded->getContent());

        $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Address submitted with media',
        ], $headers)->assertOk()->assertJson(['result' => 'success']);

        $this->assertSame(4, MediaReference::withTrashed()->where('residence_business_documentation_id', $documentation->id)->count());
        $this->assertSame($savedFileCount, count(app(CiTeamDocumentStorage::class)->disk()->allFiles()));
    }

    public function test_saved_videos_render_as_authorized_native_previews_and_keep_exact_remove_controls(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $business = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Business location');
        $videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat";

        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'video',
            'files' => [UploadedFile::fake()->createWithContent('walkthrough.mp4', $videoBytes)],
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $business]), [
            'kind' => 'video',
            'files' => [UploadedFile::fake()->createWithContent('business.mp4', $videoBytes)],
        ])->assertRedirect();

        $video = $documentation->videos()->sole();
        $contentUrl = route('client-folders.media.content', [$folder, $video]);
        $removeUrl = route('client-folders.media.documentation.destroy-media', [$folder, $documentation, $video]);
        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $preview = $xpath->query('//video[@data-saved-video-preview]')->item(0);

        $this->assertNotNull($preview);
        $this->assertSame(1, $xpath->query("//*[@id='business-documentation-panel']//video[@data-saved-video-preview]")->length);
        $this->assertTrue($preview->hasAttribute('controls'));
        $this->assertSame('metadata', $preview->getAttribute('preload'));
        $this->assertTrue($preview->hasAttribute('playsinline'));
        $this->assertFalse($preview->hasAttribute('autoplay'));
        $this->assertFalse($preview->hasAttribute('loop'));
        $this->assertSame($contentUrl, $xpath->query('./source', $preview)->item(0)->getAttribute('src'));
        $this->assertSame($video->mime_type, $xpath->query('./source', $preview)->item(0)->getAttribute('type'));
        $this->assertSame(1, $xpath->query("//div[@data-video-id='{$video->id}']//form[@action='{$removeUrl}']")->length);
        $this->assertStringNotContainsString((string) config('cims.documents_root'), $response->getContent());

        $this->actingAs($ci)->get($contentUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4');
        $otherFolder = $this->folderFor($ci);
        $this->actingAs($ci)->get(route('client-folders.media.content', [$otherFolder, $video]))->assertNotFound();
    }

    public function test_media_from_one_documentation_set_cannot_be_removed_through_another(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentationOne = $this->documentationFor($folder);
        $documentationTwo = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS);
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'residence_business_documentation_id' => $documentationOne->id,
            'media_type' => MediaType::Photo,
            'uploaded_by' => $ci->id,
        ]);

        $this->actingAs($ci)->delete(route('client-folders.media.documentation.destroy-media', [$folder, $documentationTwo, $media]))
            ->assertNotFound();
    }

    public function test_caption_builder_produces_the_expected_text_for_residence_and_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->update(['display_name' => 'Reynaldo Obasa']);
        $residence = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Bugo, Cagayan de Oro');
        $business = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Carmen, Cagayan de Oro');

        $builder = app(DocumentationCaptionBuilder::class);
        $this->assertSame(
            "Client Name: Reynaldo Obasa\nResidence pictures and videos with Google Map located at Bugo, Cagayan de Oro",
            $builder->build($residence->fresh()),
        );
        $this->assertSame(
            "Client Name: Reynaldo Obasa\nBusiness pictures and videos with Google Map located at Carmen, Cagayan de Oro",
            $builder->build($business->fresh()),
        );
    }

    public function test_send_to_telegram_and_drive_backup_actions_are_not_offered_since_no_integration_exists(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->documentationFor($folder);

        $this->assertFalse(Route::has('client-folders.media.documentation.send-telegram'));
        $this->assertFalse(Route::has('client-folders.media.documentation.backup-drive'));

        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))
            ->assertOk()
            ->assertSee('not yet connected', false);

        $this->assertDatabaseCount('telegram_messages', 0);
        $this->assertDatabaseCount('google_drive_references', 0);
    }

    public function test_legacy_media_without_a_documentation_set_still_renders_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $legacy = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'uploaded_by' => $ci->id,
            'label' => 'Barangay Check proof',
        ]);
        $this->documentationFor($folder);

        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))
            ->assertOk()->assertSee('Barangay Check proof');

        $this->assertNull($legacy->fresh()->residence_business_documentation_id);
        $this->assertDatabaseHas('media_references', ['id' => $legacy->id, 'residence_business_documentation_id' => null]);
    }

    public function test_documentation_map_screenshot_picture_and_video_are_always_stored_locally(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
        ])->assertRedirect();

        $video = UploadedFile::fake()->createWithContent('house.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'video',
            'files' => [$video],
        ])->assertRedirect();

        $documentation->refresh();
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $documentation->mapScreenshot->storage_provider);
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $documentation->pictures()->sole()->storage_provider);
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $documentation->videos()->sole()->storage_provider);
    }

    public function test_documentation_media_stays_local_even_when_cloudinary_is_configured(): void
    {
        config(['cims.cloudinary.url' => 'cloudinary://fake-key:fake-secret@fake-cloud']);
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();

        $picture = $documentation->pictures()->sole();
        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $picture->storage_provider);
        $this->assertNull($picture->cloudinary_public_id);
    }

    public function test_ci_supporting_proof_remains_cloudinary_backed_and_unaffected_by_documentation_storage(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $proof = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'uploaded_by' => $ci->id,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'cloudinary_public_id' => 'brbi-cims/existing-proof',
            'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.com/demo/image/upload/existing-proof.jpg',
        ]);

        $this->assertSame(MediaReference::STORAGE_PROVIDER_CLOUDINARY, $proof->fresh()->storage_provider);
        $this->assertNull($proof->fresh()->residence_business_documentation_id);
    }

    public function test_a_documentation_set_from_another_client_folder_is_not_accessible(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $foreignDocumentation = $this->documentationFor($otherFolder);

        $this->actingAs($ci)->get(route('client-folders.media.documentation.preview', [$folder, $foreignDocumentation]))
            ->assertNotFound();
        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $foreignDocumentation]), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'location' => 'Attempted cross-folder edit',
        ])->assertNotFound();
    }

    public function test_applicant_cannot_access_a_co_makers_documentation_set(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $coMakerDoc = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Co-Maker only location',
            'created_by' => $ci->id,
        ]);

        // As the Applicant (no co_maker_id supplied), attempting to act on the Co-Maker's set must 404.
        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $coMakerDoc]), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Overwritten by applicant',
        ])->assertNotFound();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $coMakerDoc]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('sneak.jpg')],
        ])->assertNotFound();

        $this->assertSame('Co-Maker only location', $coMakerDoc->fresh()->location);
        $this->assertSame(0, $coMakerDoc->fresh()->pictures()->count());
    }

    public function test_one_co_makers_documentation_set_cannot_be_accessed_by_another_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $docForA = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerA->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Co-Maker A location',
            'created_by' => $ci->id,
        ]);

        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $docForA]), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Overwritten by Co-Maker B',
            'co_maker_id' => $coMakerB->id,
        ])->assertNotFound();

        $this->assertSame('Co-Maker A location', $docForA->fresh()->location);
    }

    public function test_preview_only_renders_and_performs_no_send_or_backup(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->get(route('client-folders.media.documentation.preview', [$folder, $documentation]))
            ->assertOk()
            ->assertSee('Client Name:', false)
            ->assertSee('Media Send Order');

        $this->assertDatabaseCount('telegram_messages', 0);
        $this->assertDatabaseCount('google_drive_references', 0);
        $this->assertSame('draft', $documentation->fresh()->state);
    }

    public function test_map_screenshot_is_excluded_from_picture_counts_including_withcount_queries(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();

        $documentation->refresh();
        $this->assertSame(1, $documentation->pictures()->count());

        $withCounted = ResidenceBusinessDocumentation::withCount('pictures')->findOrFail($documentation->id);
        $this->assertSame(1, $withCounted->pictures_count);
    }

    public function test_new_residence_documentation_prefills_location_from_saved_applicant_cibi(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-07-14',
            'personal_snapshot' => ['present_address' => 'CIBI Verified Applicant Address'],
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $location = $this->xpath($response->getContent())->query("//*[@id='residence-documentation-location']")->item(0);
        $this->assertSame('CIBI Verified Applicant Address', $location->getAttribute('value'));
        $this->assertFalse($location->hasAttribute('readonly'));
    }

    public function test_new_residence_documentation_prefills_from_the_exact_co_makers_saved_cibi_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $folder->cibiReports()->create([
            'co_maker_id' => $coMakerA->id,
            'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-07-14',
            'personal_snapshot' => ['present_address' => 'Co-Maker A CIBI Address'],
        ]);

        $responseA = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]))->assertOk();
        $locationA = $this->xpath($responseA->getContent())->query("//*[@id='residence-documentation-location']")->item(0);
        $this->assertSame('Co-Maker A CIBI Address', $locationA->getAttribute('value'));

        $responseB = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id]))->assertOk();
        $responseB->assertDontSee('Co-Maker A CIBI Address');
        $locationB = $this->xpath($responseB->getContent())->query("//*[@id='residence-documentation-location']")->item(0);
        $this->assertSame('', $locationB->getAttribute('value'));
    }

    public function test_new_residence_documentation_location_is_blank_without_a_saved_cibi_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $location = $this->xpath($response->getContent())->query("//*[@id='residence-documentation-location']")->item(0);
        $this->assertSame('', $location->getAttribute('value'));
        $this->assertTrue($location->hasAttribute('required'));
    }

    public function test_saved_residence_documentation_location_stays_independent_after_cibi_is_edited(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $cibi = $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-07-14',
            'personal_snapshot' => ['present_address' => 'Original CIBI Address'],
        ]);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Original CIBI Address',
        ])->assertRedirect();
        $documentation = ResidenceBusinessDocumentation::sole();

        $cibi->update(['personal_snapshot' => ['present_address' => 'Edited CIBI Address']]);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'residence_documentation' => $documentation->id]))->assertOk();
        $response->assertDontSee('Edited CIBI Address');
        $location = $this->xpath($response->getContent())->query("//*[@id='residence-documentation-location']")->item(0);
        $this->assertSame('Original CIBI Address', $location->getAttribute('value'));
        $this->assertSame('Original CIBI Address', $documentation->fresh()->location);

        $cibi->delete();
        $this->assertSame('Original CIBI Address', $documentation->fresh()->location);
    }

    public function test_business_documentation_location_is_never_derived_from_cibi(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => '2026-07-14',
            'personal_snapshot' => ['present_address' => 'CIBI Residence Address'],
        ]);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'business_name' => 'MANUAL BUSINESS',
            'location' => 'Manual Business Address',
        ])->assertRedirect();

        $documentation = ResidenceBusinessDocumentation::sole();
        $this->assertSame('Manual Business Address', $documentation->location);
    }

    public function test_remarks_are_optional_and_saved_for_both_residence_and_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'business_name' => 'BUSINESS WITH REMARKS',
            'location' => 'Bugo, Cagayan de Oro',
            'remarks' => 'Store is well maintained.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $withRemarks = ResidenceBusinessDocumentation::sole();
        $this->assertSame('Store is well maintained.', $withRemarks->remarks);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'business_name' => 'BUSINESS WITHOUT REMARKS',
            'location' => 'Carmen, Cagayan de Oro',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $withoutRemarks = ResidenceBusinessDocumentation::where('location', 'Carmen, Cagayan de Oro')->sole();
        $this->assertNull($withoutRemarks->remarks);

        // Residence Documentation now owns its own optional Remarks too.
        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Zone 1, Opol',
            'remarks' => 'House is accessible through a narrow road.',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $residence = ResidenceBusinessDocumentation::where('location', 'Zone 1, Opol')->sole();
        $this->assertSame('House is accessible through a narrow road.', $residence->remarks);

        // Residence Remarks belong only to the documentation set - never written anywhere else.
        $this->assertDatabaseCount('cibi_reports', 0);
        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('ci_activities', 0);
    }

    public function test_remarks_appear_in_the_telegram_caption_only_when_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->update(['display_name' => 'Reynaldo Obasa']);
        $builder = app(DocumentationCaptionBuilder::class);

        $businessWithRemarks = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'location' => 'Carmen, Cagayan de Oro',
            'remarks' => "Store is well maintained.\nStaff are accommodating.",
            'created_by' => $ci->id,
        ]);
        $this->assertSame(
            "Client Name: Reynaldo Obasa\nBusiness pictures and videos with Google Map located at Carmen, Cagayan de Oro\n\nRemarks:\nStore is well maintained.\nStaff are accommodating.",
            $builder->build($businessWithRemarks),
        );

        $businessWithoutRemarks = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'location' => 'Bugo, Cagayan de Oro',
            'created_by' => $ci->id,
        ]);
        $this->assertStringNotContainsString('Remarks:', $builder->build($businessWithoutRemarks));

        $residenceWithRemarks = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Zone 1, Opol',
            'remarks' => 'House is accessible through a narrow road.',
            'created_by' => $ci->id,
        ]);
        $this->assertSame(
            "Client Name: Reynaldo Obasa\nResidence pictures and videos with Google Map located at Zone 1, Opol\n\nRemarks:\nHouse is accessible through a narrow road.",
            $builder->build($residenceWithRemarks),
        );

        $residenceWithoutRemarks = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Bugo, Cagayan de Oro',
            'created_by' => $ci->id,
        ]);
        $this->assertStringNotContainsString('Remarks:', $builder->build($residenceWithoutRemarks));
    }

    public function test_residence_documentation_saves_without_a_video_and_reports_it_as_optional(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Bugo, Cagayan de Oro',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $documentation = ResidenceBusinessDocumentation::sole();
        $this->assertNull($documentation->remarks);
        $this->assertSame(0, $documentation->videos()->count());

        // A set with no video raises no validation error on update either.
        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Bugo, Cagayan de Oro',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Preview renders with no video and no empty placeholder for the optional items.
        $preview = $this->actingAs($ci)->get(route('client-folders.media.documentation.preview', [$folder, $documentation]))->assertOk();
        $preview->assertDontSee('No videos uploaded.');
        $preview->assertDontSee('Remarks');
    }

    public function test_residence_ready_state_requires_only_location_and_pictures(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Bugo, Cagayan de Oro',
        ])->assertRedirect();
        $documentation = ResidenceBusinessDocumentation::sole();

        $indexUrl = route('client-folders.media.index', [$folder, 'residence_documentation' => $documentation->id]);
        $this->actingAs($ci)->get($indexUrl)->assertOk()->assertSee('Draft')->assertDontSee('Ready to Send');

        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('house.jpg')],
        ])->assertRedirect();

        // Still no map screenshot, Remarks or Video - none is part of Residence's minimum evidence.
        $this->assertNull($documentation->fresh()->map_screenshot_media_id);
        $this->assertNull($documentation->fresh()->remarks);
        $this->assertSame(0, $documentation->fresh()->videos()->count());
        $this->actingAs($ci)->get($indexUrl)->assertOk()->assertSee('Ready to Send');
    }

    public function test_photos_and_videos_page_does_not_show_ci_date_field_documentation_or_person_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->documentationFor($folder);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $response->assertDontSee('CI Date');
        $response->assertDontSee('Field documentation');
        $response->assertDontSee('Field Documentation');
        $response->assertDontSee('Applicant / Co-Maker');
        $response->assertDontSee('Folder Code');
        $response->assertDontSee('Date Created');
        $this->assertStringNotContainsString('ci_date', $response->getContent());
        $this->assertStringNotContainsString('documentation-ci-date', $response->getContent());
    }

    public function test_photos_and_videos_page_does_not_show_basic_information_heading_a_location_placeholder_or_the_main_column_caption_preview(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->documentationFor($folder);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $response->assertDontSee('Basic Information');
        $response->assertDontSee('Telegram Caption Preview');
        $response->assertDontSee('Save a documentation set to preview its caption.');
        $response->assertDontSee('Prefilled from the saved CI/BI Report');
        $this->assertStringNotContainsString('e.g. Bugo, Cagayan de Oro City', $response->getContent());
        $response->assertSee('MP4 up to 50 MB (application limit). Current PHP server file limit: '.config('cims.media.php_upload_max_filesize').'.');

        $xpath = $this->xpath($response->getContent());
        $location = $xpath->query("//*[@id='residence-documentation-location']")->item(0);
        $this->assertFalse($location->hasAttribute('placeholder'));
        $this->assertFalse($xpath->query("//*[@id='residence-documentation-remarks']")->item(0)->hasAttribute('placeholder'));
        $this->assertSame('e.g. Store is well maintained.', $xpath->query("//*[@id='business-documentation-remarks']")->item(0)->getAttribute('placeholder'));
        $this->assertStringContainsString('h-[170px]', $xpath->query("//*[@id='residence-documentation-map-screenshot-input']/following::*[@data-map-screenshot-dropzone][1]")->item(0)->getAttribute('class'));
        $this->assertStringContainsString('sm:h-[200px]', $xpath->query("//*[@id='residence-documentation-map-screenshot-input']/following::*[@data-map-screenshot-dropzone][1]")->item(0)->getAttribute('class'));
    }

    public function test_client_name_is_read_only_and_location_is_visually_editable_for_both_workflows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Saved Residence Location');
        $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Saved Business Location');

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $xpath = $this->xpath($response->getContent());

        foreach (['residence', 'business'] as $category) {
            $panel = $xpath->query("//*[@id='{$category}-documentation-panel']")->item(0);
            $clientDisplay = $xpath->query('.//*[@data-documentation-client-display]', $panel)->item(0);
            $locationLabel = $xpath->query('.//*[@data-documentation-location-field]', $panel)->item(0);
            $location = $xpath->query(".//input[@id='{$category}-documentation-location']", $panel)->item(0);

            $this->assertSame(0, $xpath->query(".//input[@name='client_name']", $panel)->length);
            $this->assertStringContainsString('cursor-default', $xpath->query('.//p', $clientDisplay)->item(0)->getAttribute('class'));
            $this->assertStringContainsString('bg-surface-muted', $xpath->query('.//p', $clientDisplay)->item(0)->getAttribute('class'));
            $this->assertStringContainsString('cursor-text', $locationLabel->getAttribute('class'));
            $this->assertStringContainsString('cursor-text', $location->getAttribute('class'));
            $this->assertStringContainsString('!bg-white', $location->getAttribute('class'));
            $this->assertStringContainsString('duration-200', $location->getAttribute('class'));
            $this->assertStringContainsString('focus:!border-brand-primary', $location->getAttribute('class'));
            $this->assertFalse($location->hasAttribute('placeholder'));
        }

        $grid = $xpath->query("//*[@id='residence-documentation-panel']//*[@data-documentation-client-display]/parent::*")->item(0);
        $this->assertStringContainsString('lg:grid-cols-[minmax(0,9fr)_minmax(0,11fr)]', $grid->getAttribute('class'));
        $this->assertSame('Residence Address', trim($xpath->query("//*[@id='residence-documentation-location']/preceding::span[contains(@class, 'media-field-label')][1]")->item(0)->textContent));
        $this->assertSame('Business Location', trim($xpath->query("//*[@id='business-documentation-location']/preceding::span[contains(@class, 'media-field-label')][1]")->item(0)->textContent));
        $this->assertStringNotContainsString('Residence Address / Location', $response->getContent());
        $this->assertSame('Saved Residence Location', $xpath->query("//*[@id='residence-documentation-location']")->item(0)->getAttribute('value'));
        $this->assertSame('Saved Business Location', $xpath->query("//*[@id='business-documentation-location']")->item(0)->getAttribute('value'));
    }

    public function test_saved_workflows_stage_media_on_the_save_locally_form_and_cleanup_only_after_success(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $xpath = $this->xpath($response->getContent());
        foreach (['map-screenshot' => 'map_screenshot', 'pictures' => 'pictures[]', 'videos' => 'videos[]'] as $suffix => $name) {
            $input = $xpath->query("//*[@id='residence-documentation-{$suffix}-input']")->item(0);
            $this->assertSame('residence-documentation-form', $input->getAttribute('form'));
            $this->assertSame($name, $input->getAttribute('name'));
        }

        $saved = $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Updated Residence Address',
            'pictures' => [UploadedFile::fake()->image('saved-house.jpg')],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        $saved->assertOk()->assertJson(['result' => 'success', 'message' => 'Documentation updated.']);
        $this->assertSame(1, $documentation->pictures()->count());

        $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Updated Residence Address',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJson(['result' => 'success']);
        $this->assertSame(1, $documentation->pictures()->count(), 'Saving again without new staged files must not duplicate the saved picture.');

        $failed = $this->actingAs($ci)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => '',
            'pictures' => [UploadedFile::fake()->image('retry-house.jpg')],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        $failed->assertUnprocessable();
        $this->assertSame(1, $documentation->pictures()->count());

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("if (xhr.status === 200 && payload?.result === 'success'", $javascript);
        $this->assertStringContainsString('clearStagedUploads();', $javascript);
        $this->assertStringContainsString('if (xhr.status === 422 && payload?.errors)', $javascript);
        $this->assertStringContainsString('resetBusyState();', $javascript);
        $this->assertStringContainsString('field.clearStagedPhotoFiles', $javascript);
        $this->assertStringContainsString('field.clearStagedVideoFiles', $javascript);
        $this->assertStringContainsString('field.clearStagedMapScreenshot', $javascript);

        $handlerStart = strpos($javascript, '// Residence/Business Documentation Save Locally');
        $handlerEnd = strpos($javascript, '// Residence Check Save/Update', $handlerStart);
        $this->assertNotFalse($handlerStart);
        $this->assertNotFalse($handlerEnd);
        $handler = substr($javascript, $handlerStart, $handlerEnd - $handlerStart);
        $this->assertSame(1, substr_count($handler, "form.addEventListener('submit'"));
        $this->assertSame(1, substr_count($handler, 'xhr.send(new FormData(form));'));
        $this->assertStringNotContainsString("formData.append('map_screenshot'", $handler);
        $this->assertStringNotContainsString("formData.append('pictures[]'", $handler);
        $this->assertStringNotContainsString('map-screenshot', substr($handler, strpos($handler, 'xhr.open('), 100));
        $this->assertStringNotContainsString('upload-media', substr($handler, strpos($handler, 'xhr.open('), 100));
    }

    public function test_photos_and_videos_page_has_no_residence_business_both_tab_selector(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $response->assertDontSee('Documentation Type');
        $this->assertStringNotContainsString('role="radiogroup"', $response->getContent());
        $this->assertStringNotContainsString('data-documentation-type-radio', $response->getContent());
        $this->assertSame(0, $this->xpath($response->getContent())->query("//input[@type='radio']")->length);
    }

    public function test_documentation_history_selector_is_hidden_and_latest_scoped_records_open_directly(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $oldResidence = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Old Residence History');
        $oldResidence->update(['updated_at' => now()->subDay()]);
        $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, 'Current Residence Workflow');
        $oldBusiness = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Old Business History');
        $oldBusiness->update(['updated_at' => now()->subDay()]);
        $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Current Business Workflow');

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $html = $response->getContent();
        $xpath = $this->xpath($html);

        $this->assertSame('Current Residence Workflow', $xpath->query("//*[@id='residence-documentation-location']")->item(0)->getAttribute('value'));
        $this->assertSame('Current Business Workflow', $xpath->query("//*[@id='business-documentation-location']")->item(0)->getAttribute('value'));
        $this->assertStringNotContainsString('Old Residence History', $html);
        $this->assertStringNotContainsString('Old Business History', $html);
        $this->assertSame(0, $xpath->query("//*[@id='residence-documentation-panel']//a[normalize-space()='New']")->length);
        $this->assertSame(0, $xpath->query("//*[@id='business-documentation-panel']//a[normalize-space()='New']")->length);
        $this->assertSame(['Residence Documentation', 'Business Documentation'], array_map(
            fn ($tab): string => trim($tab->textContent),
            iterator_to_array($xpath->query("//*[@role='tablist']//*[@role='tab']")),
        ));
        $this->assertSame(4, ResidenceBusinessDocumentation::where('client_folder_id', $folder->id)->count());
    }

    public function test_residence_is_the_default_flow_with_no_active_documentation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $response->assertSee('Residence Documentation');
        $category = $this->xpath($response->getContent())->query("//form[@id='residence-documentation-form']//input[@name='category']")->item(0);
        $this->assertSame('residence', $category->getAttribute('value'));
    }

    public function test_the_workspace_offers_exactly_a_residence_and_a_business_tab(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $tabs = $this->xpath($response->getContent())->query("//*[@role='tablist']//*[@role='tab']");

        $labels = [];
        foreach ($tabs as $tab) {
            $labels[] = trim($tab->textContent);
        }

        $this->assertSame(['Residence Documentation', 'Business Documentation'], $labels);

        // The tab already names the active workflow, so the panel must not repeat that label as
        // a heading of its own.
        $this->assertSame(0, $this->xpath($response->getContent())->query(
            "//*[@id='residence-documentation-panel']//*[self::h1 or self::h2 or self::h3 or self::h4][normalize-space()='Residence Documentation']"
        )->length);
        $this->assertSame(0, $this->xpath($response->getContent())->query(
            "//*[@id='business-documentation-panel']//*[self::h1 or self::h2 or self::h3 or self::h4][normalize-space()='Business Documentation']"
        )->length);
        $response->assertDontSee('>Both<', false);

        // Residence is the default tab. Both workflows are rendered so switching needs no
        // request, but only the selected one is visible.
        $this->assertSame('true', $tabs->item(0)->getAttribute('aria-selected'));
        $this->assertSame('false', $tabs->item(1)->getAttribute('aria-selected'));
        $this->assertNotNull($this->xpath($response->getContent())->query("//*[@id='residence-documentation-location']")->item(0));
        $this->assertNotNull($this->xpath($response->getContent())->query("//*[@id='business-documentation-location']")->item(0));
        $this->assertFalse($this->tabContentFor($response->getContent(), 'residence')->hasAttribute('hidden'));
        $this->assertTrue($this->tabContentFor($response->getContent(), 'business')->hasAttribute('hidden'));

        // Tabs must be buttons, not links: clicking one may not navigate anywhere.
        foreach ($tabs as $tab) {
            $this->assertSame('button', $tab->nodeName);
            $this->assertFalse($tab->hasAttribute('href'));
        }
    }

    public function test_the_business_tab_shows_the_business_workflow_and_keeps_its_own_category(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $business = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'Carmen, Cagayan de Oro');

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'tab' => 'business']))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $tabs = $xpath->query("//*[@role='tablist']//*[@role='tab']");
        $this->assertSame('false', $tabs->item(0)->getAttribute('aria-selected'));
        $this->assertSame('true', $tabs->item(1)->getAttribute('aria-selected'));

        // The Business workflow is the visible one, still carrying category=business, while
        // the Residence workflow stays in the DOM but hidden.
        $location = $xpath->query("//*[@id='business-documentation-location']")->item(0);
        $this->assertSame('Carmen, Cagayan de Oro', $location->getAttribute('value'));
        $this->assertFalse($this->tabContentFor($response->getContent(), 'business')->hasAttribute('hidden'));
        $this->assertTrue($this->tabContentFor($response->getContent(), 'residence')->hasAttribute('hidden'));
        $category = $xpath->query("//form[@id='business-documentation-form']//input[@name='category']")->item(0);
        $this->assertSame('business', $category->getAttribute('value'));
        $this->assertSame($business->id, ResidenceBusinessDocumentation::sole()->id);
    }

    public function test_a_saved_set_returns_the_encoder_to_its_own_tab(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'business_name' => 'SAVED BUSINESS',
            'location' => 'Carmen, Cagayan de Oro',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $documentation = ResidenceBusinessDocumentation::sole();
        $this->assertSame(ResidenceBusinessDocumentation::CATEGORY_BUSINESS, $documentation->category);

        $reopened = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'business_documentation' => $documentation->id, 'tab' => 'business']))->assertOk();
        $location = $this->xpath($reopened->getContent())->query("//*[@id='business-documentation-location']")->item(0);
        $this->assertSame('Carmen, Cagayan de Oro', $location->getAttribute('value'));
    }

    public function test_only_the_right_side_preview_panel_is_collapsible(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $hide = $xpath->query('//button[@data-media-support-hide]')->item(0);
        $show = $xpath->query('//button[@data-media-support-show]')->item(0);
        $this->assertNotNull($hide, 'Preview panel is missing its hide control.');
        $this->assertNotNull($show, 'Preview panel is missing its show control.');

        // Both controls share the toolbar row but remain outside the two-tab tablist.
        $this->assertSame('Hide panel', $hide->getAttribute('aria-label'));
        $this->assertSame('media-support-panel', $hide->getAttribute('aria-controls'));
        $this->assertSame('true', $hide->getAttribute('aria-expanded'));
        $this->assertStringContainsString('Hide Panel', $hide->textContent);

        $this->assertSame('Show panel', $show->getAttribute('aria-label'));
        $this->assertSame('media-support-panel', $show->getAttribute('aria-controls'));
        $this->assertSame('false', $show->getAttribute('aria-expanded'));
        $this->assertStringContainsString('Show Panel', $show->textContent);
        $this->assertTrue($show->hasAttribute('hidden'));
        $this->assertSame(0, $xpath->query("//*[@role='tablist']//*[@data-media-support-show or @data-media-support-hide]")->length);
        $this->assertTrue(
            $xpath->query("//*[@role='tablist']")->item(0)->parentNode->isSameNode($hide->parentNode->parentNode),
            'The tablist and Show/Hide control must share one toolbar row.',
        );

        // The documentation workflow must live OUTSIDE the collapsible shell, so hiding the
        // preview panel can never take the Residence/Business form with it.
        $this->assertNotNull($xpath->query("//*[@id='residence-documentation-panel']")->item(0));
        $this->assertSame(0, $xpath->query("//*[@data-media-support-shell]//*[@id='residence-documentation-panel']")->length);
        $this->assertSame(0, $xpath->query("//*[@data-media-support-shell]//*[@id='residence-documentation-location']")->length);
        $this->assertSame(0, $xpath->query("//*[@data-media-support-shell]//*[@role='tab']")->length);
    }

    public function test_new_residence_workflow_is_visible_before_the_first_save_and_accepts_staged_media(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();
        $response->assertDontSee('No documentation set open');
        $response->assertDontSee('save a location to preview it');
        $xpath = $this->xpath($response->getContent());
        $this->assertNotNull($xpath->query("//*[@id='residence-documentation-map-screenshot-input']")->item(0));
        $this->assertSame('pictures[]', $xpath->query("//*[@id='residence-documentation-pictures-input']")->item(0)->getAttribute('name'));
        $this->assertSame('videos[]', $xpath->query("//*[@id='residence-documentation-videos-input']")->item(0)->getAttribute('name'));

        $video = UploadedFile::fake()->createWithContent('walkthrough.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");
        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Zone 1, Opol',
            'remarks' => '',
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
            'pictures' => [UploadedFile::fake()->image('house.jpg')],
            'videos' => [$video],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $documentation = ResidenceBusinessDocumentation::sole();
        $this->assertNotNull($documentation->map_screenshot_media_id);
        $this->assertSame(1, $documentation->pictures()->count());
        $this->assertSame(1, $documentation->videos()->count());
        $this->assertNull($documentation->remarks);
    }

    public function test_legacy_media_block_is_not_rendered_when_the_person_has_none(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->documentationFor($folder);

        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))
            ->assertOk()->assertDontSee('Legacy Media');

        MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'uploaded_by' => $ci->id,
            'label' => 'Barangay Check proof',
        ]);

        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))
            ->assertOk()->assertSee('Legacy Media')->assertSee('Barangay Check proof');
    }

    /** The main-content wrapper for one documentation tab, whose `hidden` attribute says whether that tab is showing. */
    private function tabContentFor(string $html, string $category): \DOMElement
    {
        return $this->xpath($html)->query("//*[@data-doc-tab-content='".$category."'][.//*[@id='".$category."-documentation-panel']]")->item(0);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function documentationFor(ClientFolder $folder, string $category = ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, string $location = 'Bugo, Cagayan de Oro'): ResidenceBusinessDocumentation
    {
        return ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => $category,
            'location' => $location,
            'created_by' => $folder->created_by,
        ]);
    }
}
