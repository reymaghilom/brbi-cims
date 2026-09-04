<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\DocumentationTelegramDelivery;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Media\DocumentationStorageException;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class BusinessDocumentationFirstTest extends TestCase
{
    use RefreshDatabase;

    private string $documentRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->documentRoot = storage_path('framework/testing/business-documentation-first');
        File::deleteDirectory($this->documentRoot);
        config([
            'cims.documents_root' => $this->documentRoot,
            'services.telegram.bot_token' => 'fake-documentation-first-token',
            'services.telegram.chat_id' => '-1001234567890',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->documentRoot);
        parent::tearDown();
    }

    public function test_zero_business_page_immediately_shows_an_unsaved_draft_without_writes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'tab' => 'business']))
            ->assertOk()
            ->assertSee('name="business_name"', false)
            ->assertSee('name="location"', false)
            ->assertSee('name="map_screenshot"', false)
            ->assertSee('name="pictures[]"', false)
            ->assertSee('name="videos[]"', false)
            ->assertSee('name="remarks"', false)
            ->assertSee('New Business')
            ->assertSee('Unsaved Draft')
            ->assertSee('Save Business')
            ->assertSee('Cancel')
            ->assertSee('Add New Business')
            ->assertDontSee('Save a Business Report or Business Check first.');

        preg_match('/id="business-documentation-form"(.*?)<\/form>/s', $page->getContent(), $formMatch);
        $documentationForm = $formMatch[1] ?? '';
        $this->assertNotSame('', $documentationForm);
        $this->assertStringNotContainsString('name="income_source_id"', $documentationForm);

        $this->assertStringContainsString('business_draft=1', $page->getContent());
        $this->assertStringContainsString("window.addEventListener('beforeunload'", file_get_contents(resource_path('js/app.js')));
        $this->assertDatabaseCount('income_sources', 0);
        $this->assertDatabaseCount('residence_business_documentations', 0);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->get(route('client-folders.media.index', [$folder, 'tab' => 'business', 'business_draft' => 1]))->assertOk();
        $this->assertDatabaseCount('residence_business_documentations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_first_save_creates_only_independent_documentation_and_staged_media_atomically(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $save = $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), $this->draftPayload('SARI-SARI STORE', 'Direct location', [
            'remarks' => '',
            'map_screenshot' => $this->png('map.png'),
            'pictures' => [$this->png('front.png')],
            'videos' => [$this->mp4('walkthrough.mp4')],
        ]))->assertSessionDoesntHaveErrors();

        $documentation = ResidenceBusinessDocumentation::sole();
        $selectedUrl = route('client-folders.media.index', [
            $folder,
            'business_documentation' => $documentation->id,
            'tab' => 'business',
        ]);
        $save->assertRedirect($selectedUrl);
        $this->assertSame('SARI-SARI STORE', $documentation->business_name);
        $this->assertNull($documentation->legacy_income_source_id);
        $this->assertNull($documentation->remarks);
        $this->assertNotNull($documentation->mapScreenshot);
        $this->assertSame(1, $documentation->pictures()->count());
        $this->assertSame(1, $documentation->videos()->count());
        $this->assertDatabaseCount('income_sources', 0);
        $this->assertDatabaseCount('business_reports', 0);
        $this->assertDatabaseCount('business_checks', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'residence_business_documentation.created', 'user_id' => $ci->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'income_source.created']);
        foreach ($documentation->mediaReferences as $media) {
            $this->assertNull($media->income_source_id);
            $this->assertStringContainsString('Business Pictures/BD-'.str_pad((string) $documentation->id, 6, '0', STR_PAD_LEFT), str_replace('\\', '/', $media->temporary_local_path));
        }

        $this->get($selectedUrl)
            ->assertOk()
            ->assertSee('Selected Business')
            ->assertSee('SARI-SARI STORE')
            ->assertSee('BD-'.str_pad((string) $documentation->id, 6, '0', STR_PAD_LEFT))
            ->assertSee('Saved')
            ->assertSee('Save Changes')
            ->assertDontSee('Save Business')
            ->assertSee('Add Pictures stages new evidence')
            ->assertSee('Add Videos stages new evidence');

        $continued = $this->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => 'business',
            'business_name' => 'SARI-SARI STORE',
            'location' => 'Direct location',
            'remarks' => '',
            'pictures' => [$this->png('side.png')],
            'videos' => [$this->mp4('second-walkthrough.mp4')],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        $continued->assertOk()->assertJson([
            'result' => 'success',
            'return_url' => $selectedUrl,
        ]);
        $this->assertDatabaseCount('residence_business_documentations', 1);
        $this->assertSame(2, $documentation->pictures()->count());
        $this->assertSame(2, $documentation->videos()->count());
        $this->assertSame(
            [$documentation->id],
            MediaReference::query()->whereIn('media_type', ['photo', 'video'])->pluck('residence_business_documentation_id')->unique()->values()->all(),
        );
    }

    public function test_failed_late_upload_rolls_back_the_independent_identity_and_files(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $realStorage = app(PrivateMediaStorage::class);

        $this->mock(PrivateMediaStorage::class, function (MockInterface $mock) use ($realStorage): void {
            $mock->shouldReceive('storeDocumentation')->andReturnUsing(
                function (ResidenceBusinessDocumentation $documentation, UploadedFile $file, string $kind) use ($realStorage): array {
                    if ($kind === 'video') {
                        throw new DocumentationStorageException('Unable to save the video to local CI Team storage.');
                    }

                    return $realStorage->storeDocumentation($documentation, $file, $kind);
                },
            );
            $mock->shouldReceive('deleteStoredFiles')->andReturnUsing(
                fn (array $paths, string $provider) => $realStorage->deleteStoredFiles($paths, $provider),
            );
        });

        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), $this->draftPayload('ROLLBACK BUSINESS', 'Rollback location', [
            'map_screenshot' => $this->png('map.png'),
            'pictures' => [$this->png('front.png')],
            'videos' => [$this->mp4('failure.mp4')],
        ]), ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(500)->assertJson(['result' => 'storage_failure']);

        $this->assertDatabaseCount('income_sources', 0);
        $this->assertDatabaseCount('residence_business_documentations', 0);
        $this->assertSame(0, MediaReference::withTrashed()->count());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], app(CiTeamDocumentStorage::class)->disk()->allFiles());
    }

    public function test_report_and_check_businesses_are_not_loaded_or_reused_by_documentation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->reportAndCheckBusiness($folder, $ci, 'REPORT CHECK ONLY');

        $page = $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'tab' => 'business']))
            ->assertOk()->assertSee('name="business_name"', false);
        preg_match('/data-documentation-business-selector[^>]*>(.*?)<\/div>/s', $page->getContent(), $selectorMatch);
        $documentationSelector = $selectorMatch[1] ?? '';
        $this->assertNotSame('', $documentationSelector);
        $this->assertStringNotContainsString('REPORT CHECK ONLY', $documentationSelector);

        $documentation = $this->saveDraft($ci, $folder, 'REPORT CHECK ONLY', 'Documentation location', null);
        $this->assertNull($documentation->legacy_income_source_id);
        $this->assertSame(1, IncomeSource::count());
        $this->assertSame($source->id, BusinessReport::sole()->income_source_id);
        $this->assertSame($source->id, BusinessCheck::sole()->income_source_id);
    }

    public function test_report_check_and_documentation_mutations_do_not_cross_module_boundaries(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->reportAndCheckBusiness($folder, $ci, 'SHARED DISPLAY NAME');
        $documentation = $this->saveDraft($ci, $folder, 'SHARED DISPLAY NAME', 'Documentation location', 'Documentation remarks');

        $source->businessReport->update(['business_name' => 'REPORT RENAMED', 'main_business_address' => 'Report changed']);
        $source->businessCheck->update(['business_name' => 'CHECK RENAMED', 'location' => 'Check changed']);
        $this->assertSame('SHARED DISPLAY NAME', $documentation->fresh()->business_name);
        $this->assertSame('Documentation location', $documentation->location);

        $documentation->delete();
        $this->assertDatabaseHas('business_reports', ['id' => $source->businessReport->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $source->businessCheck->id]);

        $other = $this->saveDraft($ci, $folder, 'OTHER DOCUMENTATION', 'Other location', null);
        $source->businessReport->delete();
        $source->businessCheck->delete();
        $this->assertDatabaseHas('residence_business_documentations', ['id' => $other->id, 'deleted_at' => null]);
    }

    public function test_same_name_documentation_businesses_and_telegram_remain_independent(): void
    {
        $ci = User::factory()->create(['full_name' => 'ACTUAL DOCUMENTATION CI']);
        $folder = $this->folderFor($ci);
        $first = $this->saveDraft($ci, $folder, 'SAME NAME', 'Location A', 'Remarks A', [
            'map_screenshot' => $this->png('map-a.png'),
            'pictures' => [$this->png('picture-a.png')],
        ]);
        $second = $this->saveDraft($ci, $folder, 'SAME NAME', 'Location B', null, [
            'map_screenshot' => $this->png('map-b.png'),
            'pictures' => [$this->png('picture-b.png')],
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(['Location A', 'Location B'], ResidenceBusinessDocumentation::orderBy('id')->pluck('location')->all());
        $this->assertNull($second->remarks);

        $sentCaption = null;
        Http::fake(function ($request) use (&$sentCaption) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $sentCaption = $request['text'];
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 101]]);
        });
        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $first]))
            ->assertRedirect()->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $this->assertCount(3, Http::recorded());
        $this->assertStringContainsString('Business Name: SAME NAME', $sentCaption);
        $this->assertStringContainsString('Location A', $sentCaption);
        $this->assertStringNotContainsString('Location B', $sentCaption);
        $this->assertSame($first->id, DocumentationTelegramDelivery::sole()->residence_business_documentation_id);
        $this->assertSame($ci->id, DocumentationTelegramDelivery::sole()->sent_by);
        $this->assertSame(0, $second->telegramDeliveries()->count());
    }

    private function saveDraft(User $ci, ClientFolder $folder, string $name, string $location, ?string $remarks, array $extra = []): ResidenceBusinessDocumentation
    {
        $this->actingAs($ci)->post(route('client-folders.media.documentation.store', $folder), $this->draftPayload($name, $location, ['remarks' => $remarks] + $extra))
            ->assertRedirect()->assertSessionDoesntHaveErrors();

        return ResidenceBusinessDocumentation::latest('id')->firstOrFail();
    }

    private function draftPayload(string $name, string $location, array $extra = []): array
    {
        return ['category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS, 'business_name' => $name, 'location' => $location] + $extra;
    }

    private function reportAndCheckBusiness(ClientFolder $folder, User $ci, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('is_fallback', false)->where('form_handler', 'dedicated-business')->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'revision' => 2,
            'created_by' => $ci->id,
        ]);
        BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => $name, 'main_business_address' => 'Report location']);
        BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'income_source_id' => $source->id,
            'business_name' => $name,
            'ci_date' => now()->toDateString(),
            'location' => 'Check location',
            'ci_user_id' => $ci->id,
        ]);

        return $source->refresh();
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }

    private function mp4(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");
    }
}
