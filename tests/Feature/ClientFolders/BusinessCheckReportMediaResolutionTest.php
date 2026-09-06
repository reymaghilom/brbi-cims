<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Media\ReportMediaResolver;
use App\Services\Reports\OfficialReportDataBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Business Check PDF/DOCX showed "Image unavailable" / "Media reference: <uuid>.jpg (image content
 * unavailable)" for every cloud-backed photo because ReportMediaResolver::resolve() was still
 * written against the pre-pagination data shape (a flat `photo_groups` key, each group's own
 * `photos`) — OfficialReportDataBuilder::businessCheckSection() had since moved to pre-chunked
 * `photo_pages`/`competitor_photo_pages` (each entry {caption, photos}) for the strict 2-per-page
 * rule, so the resolver's array_key_exists('photo_groups', ...) check never matched anything and
 * every Business Photo, Photo Group photo, and Competitor Photo kept its null image_path all the
 * way to the PDF/DOCX renderers. Map Screenshot was never affected (its own `google_map` key never
 * changed shape) — these tests cover all four to prove the fix and guard against it drifting again.
 * Web Preview is untouched by any of this: it renders straight from `web_url`, never through this
 * resolver at all.
 */
class BusinessCheckReportMediaResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_business_check_actions_list_pdf_before_word(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan', 'ci_user_id' => $ci->id,
        ]);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()
            ->getContent();

        $printPosition = strpos($html, 'data-check-row-print data-check-kind="business" data-check-id="'.$check->id.'"');
        $pdfPosition = strpos($html, 'data-check-row-pdf-submit data-check-kind="business" data-check-id="'.$check->id.'"');
        $wordPosition = strpos($html, 'data-check-row-docx-submit data-check-kind="business" data-check-id="'.$check->id.'"');

        $this->assertNotFalse($printPosition);
        $this->assertNotFalse($pdfPosition);
        $this->assertNotFalse($wordPosition);
        $this->assertGreaterThan($printPosition, $pdfPosition);
        $this->assertGreaterThan($pdfPosition, $wordPosition);
    }

    /**
     * Direct test of the fixed method itself, using OfficialReportDataBuilder's own real output
     * shape (not a hand-built array) so this fails again if the two ever drift apart. Covers the
     * default Business Photos group, an additional Photo Group, Competitor Photos, and the Map
     * Screenshot in one pass.
     */
    public function test_resolver_downloads_every_cloud_backed_business_check_media_item_into_a_real_local_file(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan', 'ci_user_id' => $ci->id,
            'map_screenshot_file_name' => 'map.png', 'map_screenshot_cloud_public_id' => 'cloud-map', 'map_screenshot_cloud_resource_type' => 'image', 'map_screenshot_cloud_delivery_type' => 'authenticated',
        ]);
        $defaultGroup = $check->photoGroups()->create(['caption' => null, 'sort_order' => 0]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'business', 'cloud-default') + ['business_check_photo_group_id' => $defaultGroup->id]);
        $extraGroup = $check->photoGroups()->create(['caption' => 'Storeroom at the back', 'sort_order' => 1]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'business', 'cloud-extra-group') + ['business_check_photo_group_id' => $extraGroup->id]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'competitor', 'cloud-competitor'));

        $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldReceive('deliveryUrl')->andReturnUsing(fn (string $publicId) => "https://res.cloudinary.com/demo/image/authenticated/s--signed--/{$publicId}.jpg");
        });
        Http::fake(['res.cloudinary.com/*' => Http::response('fake-image-bytes', 200)]);

        $photoSection = app(OfficialReportDataBuilder::class)->businessCheckSection($check->fresh(['photoGroups.photos', 'photos', 'incomeSource']), 'Test Person');
        $resolver = app(ReportMediaResolver::class);
        [$resolved] = $resolver->resolve([$photoSection]);

        $businessPhotoPaths = collect($resolved['photo_pages'])->flatMap(fn ($page) => $page['photos'])->pluck('image_path')->all();
        $competitorPhotoPaths = collect($resolved['competitor_photo_pages'])->flatMap(fn ($page) => $page['photos'])->pluck('image_path')->all();
        $mapPath = $resolved['google_map']['image_path'];

        $this->assertCount(2, $businessPhotoPaths, 'Both the default group photo and the additional group photo must be present.');
        foreach ($businessPhotoPaths as $path) {
            $this->assertNotNull($path);
            $this->assertFileExists($path);
        }
        $this->assertCount(1, $competitorPhotoPaths);
        $this->assertNotNull($competitorPhotoPaths[0]);
        $this->assertFileExists($competitorPhotoPaths[0]);
        $this->assertNotNull($mapPath);
        $this->assertFileExists($mapPath);

        $resolver->cleanup();
        $this->assertFileDoesNotExist($businessPhotoPaths[0]);
    }

    public function test_the_docx_export_embeds_all_cloud_backed_business_media_instead_of_fallback_text(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan', 'ci_user_id' => $ci->id,
            'map_screenshot_file_name' => 'map.png', 'map_screenshot_cloud_public_id' => 'cloud-docx-map', 'map_screenshot_cloud_resource_type' => 'image', 'map_screenshot_cloud_delivery_type' => 'authenticated',
        ]);
        $defaultGroup = $check->photoGroups()->create(['caption' => null, 'sort_order' => 0]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'business', 'cloud-docx-photo') + ['business_check_photo_group_id' => $defaultGroup->id]);
        $extraGroup = $check->photoGroups()->create(['caption' => 'Storage area', 'sort_order' => 1]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'business', 'cloud-docx-extra') + ['business_check_photo_group_id' => $extraGroup->id]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'competitor', 'cloud-docx-competitor'));

        $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldReceive('deliveryUrl')->times(4)->andReturnUsing(
                fn (string $publicId) => "https://res.cloudinary.com/demo/image/authenticated/s--signed--/{$publicId}.jpg"
            );
        });
        // A tiny but genuinely valid 1x1 GIF — embedImage() needs real, decodable image bytes, not
        // just any 200 response, to actually add it to the DOCX rather than falling through to the
        // "image content unavailable" text.
        $pixelGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        Http::fake(['res.cloudinary.com/*' => Http::response($pixelGif, 200)]);

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk();

        $docxPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        file_put_contents($docxPath, $response->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($docxPath);
        $documentXml = $zip->getFromName('word/document.xml');
        $mediaEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_starts_with($name, 'word/media/')) {
                $mediaEntries[] = $name;
            }
        }
        $zip->close();
        unlink($docxPath);

        $this->assertCount(4, $mediaEntries, 'The default photo, additional-group photo, competitor photo, and map must all be embedded in the DOCX.');
        $this->assertStringNotContainsString('image content unavailable', $documentXml);
        $this->assertStringNotContainsString('Media reference', $documentXml);
        $this->assertStringNotContainsString('Map image unavailable', $documentXml);
    }

    public function test_the_pdf_export_succeeds_with_all_cloud_backed_business_media(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan', 'ci_user_id' => $ci->id,
            'map_screenshot_file_name' => 'map.png', 'map_screenshot_cloud_public_id' => 'cloud-pdf-map', 'map_screenshot_cloud_resource_type' => 'image', 'map_screenshot_cloud_delivery_type' => 'authenticated',
        ]);
        $defaultGroup = $check->photoGroups()->create(['caption' => null, 'sort_order' => 0]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'business', 'cloud-pdf-photo') + ['business_check_photo_group_id' => $defaultGroup->id]);
        $extraGroup = $check->photoGroups()->create(['caption' => 'Storage area', 'sort_order' => 1]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'business', 'cloud-pdf-extra') + ['business_check_photo_group_id' => $extraGroup->id]);
        $check->photos()->create($this->cloudPhotoRow($ci->id, 'competitor', 'cloud-pdf-competitor'));

        $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldReceive('deliveryUrl')->times(4)->andReturnUsing(
                fn (string $publicId) => "https://res.cloudinary.com/demo/image/authenticated/s--signed--/{$publicId}.jpg"
            );
        });
        $pixelGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        Http::fake(['res.cloudinary.com/*' => Http::response($pixelGif, 200)]);

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'business_check_ids' => [$check->id],
        ]);

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertGreaterThan(1000, strlen($response->streamedContent()), 'A PDF that actually embedded an image is meaningfully larger than an empty/error page.');
    }

    /** Regression guard: a historical (local-disk) photo never had a null image_path to begin with, so it must keep rendering exactly as before — untouched by this fix. */
    public function test_existing_local_business_photo_still_resolves_without_any_cloud_download(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan', 'ci_user_id' => $ci->id,
        ]);
        // The fixture goes through the faked disk like every other test here: safeMediaPath() now
        // resolves via CiTeamDocumentStorage::evidenceDisk() rather than reaching for a hard-coded
        // storage_path(), so a historical media-disk path is found wherever that disk actually is.
        Storage::disk(config('cims.media_disk'))->put('business/photos/local.jpg', UploadedFile::fake()->image('local.jpg', 200, 200)->get());
        $group = $check->photoGroups()->create(['caption' => null, 'sort_order' => 0]);
        $check->photos()->create([
            'category' => 'business', 'file_name' => 'local.jpg', 'path' => 'business/photos/local.jpg', 'thumbnail_path' => null,
            'mime_type' => 'image/jpeg', 'byte_size' => 500, 'checksum' => md5('local'), 'sort_order' => 0, 'uploaded_by' => $ci->id,
            'business_check_photo_group_id' => $group->id,
        ]);

        $this->mock(CloudinaryMediaStorage::class, function ($mock) {
            $mock->shouldNotReceive('deliveryUrl');
        });
        Http::fake();

        try {
            $photoSection = app(OfficialReportDataBuilder::class)->businessCheckSection($check->fresh(['photoGroups.photos', 'photos', 'incomeSource']), 'Test Person');
            [$resolved] = app(ReportMediaResolver::class)->resolve([$photoSection]);

            $path = $resolved['photo_pages'][0]['photos'][0]['image_path'];
            $this->assertNotNull($path);
            $this->assertFileExists($path);
            Http::assertNothingSent();
        } finally {
            Storage::disk(config('cims.media_disk'))->delete('business/photos/local.jpg');
        }
    }

    /** @param  'business'|'competitor'  $category */
    private function cloudPhotoRow(int $uploadedBy, string $category, string $publicId): array
    {
        return [
            'category' => $category, 'file_name' => $publicId.'.jpg', 'path' => null, 'thumbnail_path' => null,
            'mime_type' => 'image/jpeg', 'byte_size' => 500, 'checksum' => md5($publicId), 'sort_order' => 0, 'uploaded_by' => $uploadedBy,
            'cloud_public_id' => $publicId, 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated',
        ];
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource} */
    private function setUpBusiness(): array
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        return [$ci, $folder, $source];
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
