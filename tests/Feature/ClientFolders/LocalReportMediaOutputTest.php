<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Reports\OfficialReportDataBuilder;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end proof that LOCALLY stored Residence and Business Check evidence actually reaches all
 * three outputs. It does not assert "the page rendered" — it opens the produced artifact and looks
 * for the media inside it: the PDF's own embedded image stream, the DOCX package's declared media
 * parts, and the real files behind the preview's authorized URLs.
 *
 * Local evidence lives under the CI Team document tree, which TestCase points at a per-test
 * temporary root, so nothing here can touch real evidence. Cloudinary is never contacted.
 */
class LocalReportMediaOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------------ Residence Check

    public function test_residence_preview_serves_local_pictures_and_map_through_authorized_urls_only(): void
    {
        [$ci, $folder, $check] = $this->residenceCheckWithLocalMedia();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk();
        $html = $response->getContent();

        // Every picture and the map screenshot are referenced by their authorized delivery route.
        foreach ($check->photos as $photo) {
            $url = route('client-folders.residence-checks.photo', [$folder->id, $check->id, $photo->id]);
            $this->assertStringContainsString($url, $html, 'Preview must reference the authorized photo URL.');
            $this->actingAs($ci)->get($url)->assertOk()->assertHeader('Content-Type', $photo->mime_type);
        }
        $mapUrl = route('client-folders.residence-checks.map-screenshot', [$folder->id, $check->id]);
        $this->assertStringContainsString($mapUrl, $html);
        $this->actingAs($ci)->get($mapUrl)->assertOk();

        // ...and the real filesystem location never leaks into the browser.
        $this->assertLocalPathsAreNotExposed($html);
    }

    public function test_residence_pdf_embeds_the_local_pictures_and_map(): void
    {
        [$ci, $folder, $check] = $this->residenceCheckWithLocalMedia();

        $pdf = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->streamedContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        // Three images went in (2 pictures + 1 map), so the PDF must carry three image XObjects.
        $this->assertSame(3, substr_count($pdf, '/Subtype /Image'), 'The PDF should embed one XObject per local image.');
    }

    public function test_residence_docx_embeds_the_local_pictures_and_map(): void
    {
        [$ci, $folder, $check] = $this->residenceCheckWithLocalMedia();

        $docx = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->streamedContent();

        $this->assertSame(3, count($this->docxMediaParts($docx)), 'The DOCX should package one media part per local image.');
    }

    public function test_residence_report_media_resolves_to_files_inside_the_ci_team_tree(): void
    {
        [, , $check] = $this->residenceCheckWithLocalMedia();

        $section = app(OfficialReportDataBuilder::class)->residenceCheckSection($check->fresh('photos'), 'Test Person');
        $root = app(CiTeamDocumentStorage::class)->root();

        foreach ($section['media'] as $item) {
            $this->assertNotNull($item['image_path'], 'A locally stored picture must resolve to a real path for PDF/DOCX.');
            $this->assertFileExists($item['image_path']);
            $this->assertStringStartsWith($root, $item['image_path'], 'Local evidence resolves inside the CI Team tree.');
        }
        $this->assertNotNull($section['google_map']['image_path']);
        $this->assertFileExists($section['google_map']['image_path']);
    }

    // ------------------------------------------------------------------ Business Check

    public function test_business_outputs_carry_only_that_businesss_own_local_media(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $first = $this->businessCheckWithLocalMedia($ci, $folder, 'Alpha Store', 'ALPHA-PICTURE', 2);
        $second = $this->businessCheckWithLocalMedia($ci, $folder, 'Bravo Store', 'BRAVO-PICTURE', 1);

        $section = app(OfficialReportDataBuilder::class)->businessCheckSection($first->fresh(['photoGroups.photos', 'photos', 'incomeSource']), 'Test Person');
        $paths = collect($section['photo_pages'])->flatMap(fn (array $page): array => $page['photos'])->pluck('image_path');

        $this->assertCount(2, $paths, 'Only this business\'s own pictures belong in its report.');
        $paths->each(function (?string $path) use ($second): void {
            $this->assertNotNull($path);
            $this->assertFileExists($path);
            foreach ($second->photos as $foreign) {
                $this->assertStringNotContainsString(basename($foreign->path), $path, 'One business must never carry another business\'s picture.');
            }
        });
        $this->assertSame($first->income_source_id, $first->fresh()->income_source_id);
        $this->assertNotNull($section['google_map']['image_path']);
        $this->assertFileExists($section['google_map']['image_path']);

        // The same media survives the real PDF and DOCX writers.
        $pdf = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'business_check_ids' => [$first->id],
        ])->assertOk()->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(3, substr_count($pdf, '/Subtype /Image'), '2 business pictures + 1 map screenshot.');

        $docx = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$first->id],
        ])->assertOk()->streamedContent();
        $this->assertSame(3, count($this->docxMediaParts($docx)));
    }

    public function test_business_preview_shows_the_local_pictures_without_leaking_paths(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->businessCheckWithLocalMedia($ci, $folder, 'Alpha Store', 'ALPHA-PICTURE', 2);

        $html = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk()->getContent();

        foreach ($check->photos as $photo) {
            $url = route('client-folders.business-checks.photo', [$folder->id, $check->id, $photo->id]);
            $this->assertStringContainsString($url, $html);
            $this->actingAs($ci)->get($url)->assertOk();
        }
        $mapUrl = route('client-folders.business-checks.map-screenshot', [$folder->id, $check->id]);
        $this->assertStringContainsString($mapUrl, $html);
        $this->actingAs($ci)->get($mapUrl)->assertOk();
        $this->assertLocalPathsAreNotExposed($html);
    }

    // ------------------------------------------------------------------ Isolation and mixed history

    public function test_applicant_and_co_maker_residence_media_never_cross(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Isolated Co-Maker']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Applicant.jpg', 400, 300)->size(120)],
        ])->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address',
            'photos' => [UploadedFile::fake()->image('CoMaker.jpg', 400, 300)->size(120)],
        ])->assertSessionHasNoErrors();

        $applicantCheck = $folder->residenceChecks()->whereNull('co_maker_id')->firstOrFail();
        $coMakerCheck = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $documents = app(CiTeamDocumentStorage::class);

        // Each person's picture is written into that person's own directory, and only that one.
        $this->assertStringStartsWith($documents->residenceCheckPicturesDirectory($folder).'/', $applicantCheck->photos->first()->path);
        $this->assertStringStartsWith($documents->residenceCheckPicturesDirectory($folder, $coMaker).'/', $coMakerCheck->photos->first()->path);

        // A batch export for the Applicant can never pull in the Co-Maker's check.
        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$coMakerCheck->id],
        ])->assertNotFound();
    }

    public function test_a_check_holding_both_local_and_cloud_pictures_resolves_each_through_its_own_provider(): void
    {
        [$ci, , $check] = $this->residenceCheckWithLocalMedia();

        $check->photos()->create([
            'file_name' => 'Cloud.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 2048, 'sort_order' => 9,
            'cloud_public_id' => 'BRBI-CIMS/clients/current/mixed', 'cloud_resource_type' => 'image',
            'cloud_delivery_type' => 'authenticated', 'uploaded_by' => $ci->id,
        ]);
        $this->mock(CloudinaryMediaStorage::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliveryUrl')->andReturn('https://res.cloudinary.test/mixed.jpg');
        });
        Http::fake(['res.cloudinary.test/*' => Http::response('fake-bytes', 200)]);

        $section = app(OfficialReportDataBuilder::class)->residenceCheckSection($check->fresh('photos'), 'Test Person');
        $local = collect($section['media'])->filter(fn (array $item): bool => $item['image_path'] !== null);
        $cloud = collect($section['media'])->filter(fn (array $item): bool => $item['cloud'] !== null);

        $this->assertCount(2, $local, 'The two local pictures still resolve from disk.');
        $this->assertCount(1, $cloud, 'The historical Cloudinary picture still resolves through Cloudinary.');
        $local->each(fn (array $item) => $this->assertFileExists($item['image_path']));
    }

    // ------------------------------------------------------------------ Helpers

    /** Asserts no absolute local filesystem location reached the browser. */
    private function assertLocalPathsAreNotExposed(string $html): void
    {
        $root = app(CiTeamDocumentStorage::class)->root();
        $this->assertStringNotContainsString($root, $html);
        $this->assertStringNotContainsString(str_replace('\\', '/', $root), $html);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]:\\\\Users\\\\/', $html, 'A Windows path must never be rendered.');
        $this->assertStringNotContainsString('Residence Check Report/Pictures', $html);
        $this->assertStringNotContainsString('Business Check Report/Pictures', $html);
    }

    /** @return list<string> the media parts word/_rels declares AND the package really contains */
    private function docxMediaParts(string $bytes): array
    {
        $this->assertStringStartsWith("PK\x03\x04", $bytes, 'Response is not a real DOCX package.');
        $path = tempnam(sys_get_temp_dir(), 'brbi-docx-media-');
        file_put_contents($path, $bytes);

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path, \ZipArchive::CHECKCONS) === true);

            $parts = [];
            $rels = new \SimpleXMLElement($zip->getFromName('word/_rels/document.xml.rels'));
            foreach ($rels->Relationship as $relationship) {
                $target = (string) $relationship['Target'];
                if (! str_starts_with($target, 'media/')) {
                    continue;
                }
                $part = 'word/'.$target;
                $this->assertNotFalse($zip->locateName($part), "Declared {$part} is missing from the package.");
                $this->assertGreaterThan(0, $zip->statName($part)['size'], "Embedded {$part} is empty.");
                $parts[] = $part;
            }
            $zip->close();

            return $parts;
        } finally {
            @unlink($path);
        }
    }

    /** @return array{0: User, 1: ClientFolder, 2: ResidenceCheck} */
    private function residenceCheckWithLocalMedia(): array
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Blk 5 M & M Subdivision',
            'photos' => [
                UploadedFile::fake()->image('Front.jpg', 600, 450)->size(150),
                UploadedFile::fake()->image('Back.jpg', 600, 450)->size(150),
            ],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 500, 400)->size(120),
        ])->assertSessionHasNoErrors();

        return [$ci, $folder, $folder->residenceChecks()->with('photos')->firstOrFail()];
    }

    private function businessCheckWithLocalMedia(User $ci, ClientFolder $folder, string $name, string $caption, int $photos): BusinessCheck
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => null, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type,
            'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name, 'revision' => 2,
        ]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => 'Poblacion', 'report_category' => 'retail_grocery_water_refilling']);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion',
            'business_photos' => collect(range(1, $photos))
                ->map(fn (int $index) => UploadedFile::fake()->image($caption.'-'.$index.'.jpg', 600, 450)->size(150))->all(),
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 500, 400)->size(120),
        ])->assertSessionHasNoErrors();

        return $folder->businessChecks()->where('income_source_id', $source->id)->with('photos')->firstOrFail();
    }

    private function folder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
