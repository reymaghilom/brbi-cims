<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\MediaCategory;
use App\Enums\MediaType;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BusinessDocumentationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $documentRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->documentRoot = storage_path('framework/testing/business-documentation-isolation');
        File::deleteDirectory($this->documentRoot);
        config(['cims.documents_root' => $this->documentRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->documentRoot);
        parent::tearDown();
    }

    public function test_business_selectors_are_documentation_owned_and_scoped_to_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $applicant = $this->documentationFor($folder, null, 'APPLICANT STORE', 'Applicant location');
        $coMakerDocumentation = $this->documentationFor($folder, $coMakerA, 'CO MAKER STORE', 'Co-maker location');
        $this->documentationFor($folder, $coMakerB, 'OTHER CO MAKER STORE', 'Other location');

        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'tab' => 'business']))
            ->assertOk()->assertSee('APPLICANT STORE')->assertDontSee('CO MAKER STORE');
        $this->get(route('client-folders.media.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'tab' => 'business']))
            ->assertOk()->assertSee('CO MAKER STORE')->assertDontSee('APPLICANT STORE')->assertDontSee('OTHER CO MAKER STORE');

        $this->patch(route('client-folders.media.documentation.update', [$folder, $coMakerDocumentation]), [
            'category' => 'business',
            'business_name' => 'MUST NOT UPDATE',
            'location' => 'Must not update',
        ])->assertNotFound();
        $this->assertSame('CO MAKER STORE', $coMakerDocumentation->fresh()->business_name);
        $this->assertSame($applicant->id, ResidenceBusinessDocumentation::whereNull('co_maker_id')->sole()->id);
    }

    public function test_three_business_selector_opens_only_the_exact_id_context_and_add_business_stays_a_draft(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $sariSari = $this->documentationFor($folder, null, 'SARI-SARI STORE', 'Sari-Sari location');
        $gasStation = $this->documentationFor($folder, null, 'GAS STATION', 'Gas Station location');
        $weldingShop = $this->documentationFor($folder, null, 'WELDING SHOP', 'Welding Shop location');
        $sariSariPicture = $this->mediaFor($sariSari, 'test/sari-sari.jpg', 'sari-sari.jpg');
        $gasStationPicture = $this->mediaFor($gasStation, 'test/gas-station.jpg', 'gas-station.jpg');
        $weldingShopPicture = $this->mediaFor($weldingShop, 'test/welding-shop.jpg', 'welding-shop.jpg');

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', [
            $folder,
            'tab' => 'business',
            'business_documentation' => $gasStation->id,
        ]));

        $response->assertOk()
            ->assertSee('SARI-SARI STORE')
            ->assertSee('GAS STATION')
            ->assertSee('WELDING SHOP')
            ->assertSee('BD-'.str_pad((string) $sariSari->id, 6, '0', STR_PAD_LEFT))
            ->assertSee('BD-'.str_pad((string) $gasStation->id, 6, '0', STR_PAD_LEFT))
            ->assertSee('BD-'.str_pad((string) $weldingShop->id, 6, '0', STR_PAD_LEFT))
            ->assertSee('value="Gas Station location"', false)
            ->assertDontSee('Sari-Sari location')
            ->assertDontSee('Welding Shop location')
            ->assertSee('data-photo-id="'.$gasStationPicture->id.'"', false)
            ->assertDontSee('data-photo-id="'.$sariSariPicture->id.'"', false)
            ->assertDontSee('data-photo-id="'.$weldingShopPicture->id.'"', false)
            ->assertSee('aria-current="true"', false)
            ->assertSee('3 Businesses')
            ->assertSee('Add New Business');

        $this->assertStringContainsString('return_business_documentation='.$gasStation->id, $response->getContent());

        $this->get(route('client-folders.media.documentation.preview', [$folder, $gasStation]))
            ->assertOk()
            ->assertSee('Business Name: GAS STATION')
            ->assertSee('Gas Station location')
            ->assertSee(route('client-folders.media.content', [$folder, $gasStationPicture]), false)
            ->assertDontSee(route('client-folders.media.content', [$folder, $sariSariPicture]), false)
            ->assertDontSee(route('client-folders.media.content', [$folder, $weldingShopPicture]), false);

        $draft = $this->get(route('client-folders.media.index', [
            $folder,
            'tab' => 'business',
            'business_draft' => 1,
            'return_business_documentation' => $gasStation->id,
        ]))
            ->assertOk()
            ->assertSee('name="business_name"', false)
            ->assertSee('New Business')
            ->assertSee('Unsaved Draft')
            ->assertSee('Save Business')
            ->assertSee('Cancel');
        $this->assertStringContainsString('business_documentation='.$gasStation->id, $draft->getContent());
        $this->assertDatabaseCount('residence_business_documentations', 3);
    }

    public function test_each_documentation_business_updates_only_its_own_fields_and_allows_blank_remarks(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $businessA = $this->documentationFor($folder, null, 'BUSINESS A', 'Location A', 'Remarks A');
        $businessB = $this->documentationFor($folder, null, 'BUSINESS B', 'Location B');

        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $businessA]), [
            'category' => 'business',
            'business_name' => 'BUSINESS A RENAMED',
            'location' => 'Updated A',
            'remarks' => '',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $businessA->refresh();
        $this->assertSame('BUSINESS A RENAMED', $businessA->business_name);
        $this->assertSame('Updated A', $businessA->location);
        $this->assertNull($businessA->remarks);
        $this->assertSame('BUSINESS B', $businessB->fresh()->business_name);
        $this->assertSame('Location B', $businessB->location);
    }

    public function test_media_paths_and_removal_are_isolated_by_documentation_id_even_after_rename(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $businessA = $this->documentationFor($folder, null, 'SAME NAME', 'Location A');
        $businessB = $this->documentationFor($folder, null, 'SAME NAME', 'Location B');

        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $businessA]), [
            'kind' => 'picture',
            'files' => [$this->png('first.png')],
        ])->assertRedirect();
        $pictureA = $businessA->pictures()->sole();
        $pathA = $pictureA->temporary_local_path;

        $this->patch(route('client-folders.media.documentation.update', [$folder, $businessA]), [
            'category' => 'business',
            'business_name' => 'RENAMED',
            'location' => 'Location A',
        ])->assertRedirect();
        $this->post(route('client-folders.media.documentation.upload-media', [$folder, $businessA]), [
            'kind' => 'picture',
            'files' => [$this->png('second.png')],
        ])->assertRedirect();
        $this->post(route('client-folders.media.documentation.upload-media', [$folder, $businessB]), [
            'kind' => 'picture',
            'files' => [$this->png('other.png')],
        ])->assertRedirect();

        $stableA = 'Business Pictures/BD-'.str_pad((string) $businessA->id, 6, '0', STR_PAD_LEFT).'/Pictures/';
        $stableB = 'Business Pictures/BD-'.str_pad((string) $businessB->id, 6, '0', STR_PAD_LEFT).'/Pictures/';
        foreach ($businessA->pictures as $picture) {
            $this->assertStringContainsString($stableA, str_replace('\\', '/', $picture->temporary_local_path));
            $this->assertNull($picture->income_source_id);
        }
        $pictureB = $businessB->pictures()->sole();
        $this->assertStringContainsString($stableB, str_replace('\\', '/', $pictureB->temporary_local_path));
        $this->assertSame($pathA, $pictureA->fresh()->temporary_local_path);

        $this->delete(route('client-folders.media.documentation.destroy-media', [$folder, $businessA, $pictureB]))->assertNotFound();
        $this->assertDatabaseHas('media_references', ['id' => $pictureB->id, 'deleted_at' => null]);
    }

    public function test_legacy_linked_documentation_is_an_inert_snapshot_and_legacy_path_remains_readable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::query()->where('is_fallback', false)->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'income_source_template_id' => $template->id,
            'source_name' => 'FORMER SHARED BUSINESS',
            'business_name' => 'FORMER SHARED BUSINESS',
            'created_by' => $ci->id,
        ]);
        $documentation = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => 'business',
            'business_name' => 'FORMER SHARED BUSINESS',
            'legacy_income_source_id' => $source->id,
            'location' => 'Preserved location',
            'created_by' => $ci->id,
        ]);
        $legacyPath = 'legacy/Business Pictures/IS-'.str_pad((string) $source->id, 6, '0', STR_PAD_LEFT).'/Pictures/legacy.jpg';
        app(CiTeamDocumentStorage::class)->disk()->put($legacyPath, 'legacy-contents');
        $media = $this->mediaFor($documentation, $legacyPath, 'legacy.jpg');

        $source->forceDelete();
        $this->assertDatabaseHas('residence_business_documentations', ['id' => $documentation->id, 'business_name' => 'FORMER SHARED BUSINESS']);
        $this->assertTrue(app(CiTeamDocumentStorage::class)->diskForMedia($media)->exists($legacyPath));
        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'tab' => 'business', 'business_documentation' => $documentation->id]))
            ->assertOk()->assertSee('FORMER SHARED BUSINESS')->assertSee('Preserved location');

        $legacy = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'category' => 'business',
            'business_name' => null,
            'location' => 'Generic legacy location',
            'created_by' => $ci->id,
        ]);
        $this->get(route('client-folders.media.index', [$folder, 'tab' => 'business', 'business_documentation' => $legacy->id]))
            ->assertOk()->assertSee('Legacy / Unassigned')->assertSee('Generic legacy location');
    }

    public function test_residence_documentation_remains_single_context_without_business_identity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), [
            'category' => 'residence',
            'location' => 'Residence address',
            'remarks' => '',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $documentation = ResidenceBusinessDocumentation::sole();
        $this->assertTrue($documentation->isResidence());
        $this->assertNull($documentation->business_name);
        $this->assertNull($documentation->remarks);
    }

    private function documentationFor(ClientFolder $folder, ?CoMaker $coMaker, string $name, string $location, ?string $remarks = null): ResidenceBusinessDocumentation
    {
        return ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker?->id,
            'category' => 'business',
            'business_name' => $name,
            'location' => $location,
            'remarks' => $remarks,
            'created_by' => $folder->created_by,
        ]);
    }

    private function mediaFor(ResidenceBusinessDocumentation $documentation, string $path, string $name): MediaReference
    {
        return MediaReference::create([
            'client_folder_id' => $documentation->client_folder_id,
            'co_maker_id' => $documentation->co_maker_id,
            'residence_business_documentation_id' => $documentation->id,
            'media_type' => MediaType::Photo,
            'category' => MediaCategory::Business,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CI_TEAM,
            'file_name' => $name,
            'label' => 'Legacy picture',
            'mime_type' => 'image/jpeg',
            'byte_size' => 15,
            'checksum' => hash('sha256', 'legacy-contents'),
            'uploaded_by' => $documentation->created_by,
            'temporary_local_path' => $path,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
