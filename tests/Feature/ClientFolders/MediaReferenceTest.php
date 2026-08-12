<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\MediaCategory;
use App\Enums\MediaType;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_media_pages_are_scoped_to_the_administrator_or_assigned_investigator(): void
    {
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = $this->folderFor($assigned);
        $otherFolder = $this->folderFor($other);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $assigned->id, 'label' => 'Authorized Evidence']);
        MediaReference::factory()->create(['client_folder_id' => $otherFolder->id, 'uploaded_by' => $other->id, 'label' => 'Private Foreign Evidence']);

        $this->actingAs($assigned)->get(route('client-folders.media.index', $folder))
            ->assertOk()->assertSee('Authorized Evidence')->assertDontSee('Private Foreign Evidence');
        $this->actingAs($assigned)->get(route('media.index'))
            ->assertOk()->assertSee('Authorized Evidence')->assertDontSee('Private Foreign Evidence');
        $this->actingAs($assigned)->get(route('client-folders.media.index', $otherFolder))->assertForbidden();
        $this->actingAs($admin)->get(route('media.index'))
            ->assertOk()->assertSee('Authorized Evidence')->assertSee('Private Foreign Evidence');
    }

    public function test_image_upload_uses_private_safe_storage_thumbnail_and_audit_log(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $image = UploadedFile::fake()->image('Residence Front.JPG', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.media.store', $folder), [
            'files' => [$image],
            'category' => MediaCategory::Residence->value,
            'label' => 'Residence frontage',
            'remarks' => 'Verified during the site visit.',
            'captured_at' => '2026-08-01',
        ])->assertRedirect(route('client-folders.media.index', $folder))->assertSessionHas('status');

        $media = MediaReference::sole();
        $this->assertSame(MediaType::Photo, $media->media_type);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.jpg$/', $media->file_name);
        $this->assertStringNotContainsString('Residence Front', $media->temporary_local_path);
        $this->assertNotNull($media->checksum);
        Storage::disk('local')->assertExists($media->temporary_local_path);
        Storage::disk('local')->assertExists($media->thumbnail_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.uploaded', 'client_folder_id' => $folder->id, 'user_id' => $ci->id]);
    }

    public function test_multiple_images_can_be_uploaded_as_independent_records(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.store', $folder), [
            'files' => [UploadedFile::fake()->image('front.png'), UploadedFile::fake()->image('side.webp')],
            'category' => MediaCategory::Residence->value,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(2, MediaReference::count());
        $this->assertSame(2, AuditLog::where('action', 'media.uploaded')->count());
    }

    public function test_mp4_video_upload_is_detected_from_actual_content(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $video = UploadedFile::fake()->createWithContent('field-visit.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");

        $this->actingAs($ci)->post(route('client-folders.media.store', $folder), [
            'files' => [$video],
            'category' => MediaCategory::Business->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $media = MediaReference::sole();
        $this->assertSame(MediaType::Video, $media->media_type);
        $this->assertSame('video/mp4', $media->mime_type);
        $this->assertNull($media->thumbnail_path);
    }

    public function test_invalid_extension_content_and_oversized_photos_are_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->from(route('client-folders.media.index', $folder))->post(route('client-folders.media.store', $folder), [
            'files' => [UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "unsafe";')],
            'category' => MediaCategory::Other->value,
        ])->assertRedirect()->assertSessionHasErrors('files.0');

        $this->actingAs($ci)->from(route('client-folders.media.index', $folder))->post(route('client-folders.media.store', $folder), [
            'files' => [UploadedFile::fake()->image('large.jpg')->size(10 * 1024 + 1)],
            'category' => MediaCategory::Residence->value,
        ])->assertRedirect()->assertSessionHasErrors('files.0');

        $this->assertDatabaseCount('media_references', 0);
    }

    public function test_metadata_activity_and_income_source_links_are_folder_scoped(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder);
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'income_source_template_id' => IncomeSourceTemplate::query()->firstOrFail()->id,
        ]);
        $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $ci->id]);

        $this->actingAs($ci)->patch(route('client-folders.media.update', [$folder, $media]), [
            'media_form' => 'edit-'.$media->id,
            'category' => MediaCategory::Business->value,
            'label' => 'Updated business frontage',
            'remarks' => 'Linked to field verification.',
            'captured_at' => '2026-08-02',
            'ci_activity_id' => $activity->id,
            'income_source_id' => $source->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $media->refresh();
        $this->assertSame(MediaCategory::Business, $media->category);
        $this->assertSame($source->id, $media->income_source_id);
        $this->assertTrue($media->activities()->whereKey($activity->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.updated', 'client_folder_id' => $folder->id]);

        $foreignFolder = $this->folderFor($ci);
        $foreignActivity = $this->activityFor($foreignFolder);
        $this->actingAs($ci)->patch(route('client-folders.media.update', [$folder, $media]), [
            'category' => MediaCategory::Business->value,
            'ci_activity_id' => $foreignActivity->id,
        ])->assertSessionHasErrors('ci_activity_id');
    }

    public function test_new_media_is_available_to_activity_and_residence_business_workflows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activityFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.store', $folder), [
            'files' => [UploadedFile::fake()->image('site.jpg')],
            'category' => MediaCategory::Residence->value,
            'label' => 'New site evidence',
            'ci_activity_id' => $activity->id,
        ])->assertRedirect();

        $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertOk()->assertSee('New site evidence');
        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('New site evidence');
    }

    public function test_preview_download_update_and_remove_reject_forged_folder_or_foreign_ci_access(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstFolder = $this->folderFor($first);
        $secondFolder = $this->folderFor($second);
        $media = MediaReference::factory()->create([
            'client_folder_id' => $firstFolder->id,
            'uploaded_by' => $first->id,
            'temporary_local_path' => 'client-media/file.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('client-media/file.jpg', 'protected');

        $this->get(route('client-folders.media.content', [$firstFolder, $media]))->assertRedirect(route('login'));
        $this->actingAs($second)->get(route('client-folders.media.content', [$firstFolder, $media]))->assertForbidden();
        $this->actingAs($first)->get(route('client-folders.media.content', [$secondFolder, $media]))->assertNotFound();
        $this->actingAs($first)->get(route('client-folders.media.download', [$secondFolder, $media]))->assertNotFound();
        $this->actingAs($first)->patch(route('client-folders.media.update', [$secondFolder, $media]), ['category' => 'other'])->assertNotFound();
        $this->actingAs($first)->delete(route('client-folders.media.destroy', [$secondFolder, $media]))->assertNotFound();
        $this->actingAs($first)->get(route('client-folders.media.content', [$firstFolder, $media]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($first)->get(route('client-folders.media.download', [$firstFolder, $media]))->assertOk();
    }

    public function test_remove_is_soft_delete_keeps_file_and_does_not_change_folder_progress(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->update(['progress_percent' => 33.33]);
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'uploaded_by' => $ci->id,
            'temporary_local_path' => 'client-media/retained.jpg',
        ]);
        Storage::disk('local')->put('client-media/retained.jpg', 'retained evidence');

        $this->actingAs($ci)->delete(route('client-folders.media.destroy', [$folder, $media]))
            ->assertRedirect(route('client-folders.media.index', $folder))->assertSessionHas('status');

        $this->assertSoftDeleted('media_references', ['id' => $media->id]);
        Storage::disk('local')->assertExists('client-media/retained.jpg');
        $this->assertEquals(33.33, $folder->fresh()->progress_percent);
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.removed', 'client_folder_id' => $folder->id, 'user_id' => $ci->id]);
    }

    public function test_gallery_uses_responsive_lazy_media_and_centered_dialog_markup(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $ci->id, 'label' => 'Responsive evidence']);

        $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk()
            ->assertSee('sm:grid-cols-2', false)
            ->assertSee('lg:grid-cols-3', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('media-upload-dialog', false)
            ->assertSee('fixed inset-0 m-auto', false);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function activityFor(ClientFolder $folder): CiActivity
    {
        $definition = ActivityDefinition::query()->orderBy('sort_order')->firstOrFail();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
        ]);
    }
}
