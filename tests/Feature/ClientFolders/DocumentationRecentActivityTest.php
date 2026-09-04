<?php

namespace Tests\Feature\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentationRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_save_locally_records_grouped_authoritative_activity_and_ignores_no_op_or_invalid_saves(): void
    {
        $creator = User::factory()->create(['full_name' => 'Documentation Creator']);
        $actor = User::factory()->create(['full_name' => 'Actual Documentation Actor']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $actor->id]);
        $documentation = $this->documentationFor($folder, $creator, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE);
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        $response = $this->actingAs($actor)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Updated Residence Address',
            'remarks' => 'Verified by the field investigator.',
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
            'pictures' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('side.jpg')],
            'videos' => [UploadedFile::fake()->createWithContent('walkthrough.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat")],
        ], $headers)->assertOk();

        $response->assertJsonPath('result', 'success')
            ->assertJsonPath('recent_activity_category', 'residence');
        $activityHtml = $response->json('recent_activity_html');
        $this->assertStringContainsString('Actual Documentation Actor', $activityHtml);
        $this->assertStringContainsString('Updated Residence Address and Remarks', $activityHtml);
        $this->assertStringContainsString('Uploaded Residence Google Map Screenshot', $activityHtml);
        $this->assertStringContainsString('Uploaded 2 Residence Pictures', $activityHtml);
        $this->assertStringContainsString('Uploaded 1 Residence Video', $activityHtml);

        $events = AuditLog::where('client_folder_id', $folder->id)->get();
        $this->assertCount(4, $events);
        $this->assertTrue($events->every(fn (AuditLog $event): bool => $event->user_id === $actor->id));
        $this->assertSame(['location', 'remarks'], $events->firstWhere('action', 'residence_business_documentation.updated')->metadata['changed_fields']);
        $this->assertSame(2, $events->firstWhere('action', 'residence_business_documentation.media_uploaded')->metadata['count']);
        $this->assertSame(2, $events->where('action', 'residence_business_documentation.media_uploaded')->count());

        $countBeforeNoOp = AuditLog::count();
        $this->actingAs($actor)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Updated Residence Address',
            'remarks' => 'Verified by the field investigator.',
        ], $headers)->assertOk();
        $this->assertSame($countBeforeNoOp, AuditLog::count());

        $this->actingAs($actor)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => '',
        ], $headers)->assertUnprocessable();
        $this->assertSame($countBeforeNoOp, AuditLog::count());
    }

    public function test_map_replace_remove_and_picture_video_remove_record_the_actual_actor_once(): void
    {
        $creator = User::factory()->create();
        $actor = User::factory()->create(['full_name' => 'Actual Remover']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $actor->id]);
        $documentation = $this->documentationFor($folder, $creator, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE);

        $this->actingAs($actor)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg'),
        ])->assertRedirect();
        $this->actingAs($actor)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertRedirect();
        $this->actingAs($actor)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'remove_map_screenshot' => '1',
        ])->assertRedirect();

        foreach (['picture' => UploadedFile::fake()->image('house.jpg'), 'video' => UploadedFile::fake()->createWithContent('house.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat")] as $kind => $file) {
            $this->actingAs($actor)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
                'kind' => $kind,
                'files' => [$file],
            ])->assertRedirect();
        }

        foreach ($documentation->mediaReferences()->get() as $media) {
            $this->actingAs($actor)->delete(route('client-folders.media.documentation.destroy-media', [$folder, $documentation, $media]))->assertRedirect();
        }

        foreach ([
            'residence_business_documentation.map_screenshot_uploaded',
            'residence_business_documentation.map_screenshot_replaced',
            'residence_business_documentation.map_screenshot_removed',
        ] as $action) {
            $this->assertSame(1, AuditLog::where('action', $action)->where('user_id', $actor->id)->count());
        }
        $this->assertSame(2, AuditLog::where('action', 'residence_business_documentation.media_uploaded')->where('user_id', $actor->id)->count());
        $this->assertSame(2, AuditLog::where('action', 'residence_business_documentation.media_removed')->where('user_id', $actor->id)->count());
    }

    public function test_business_activity_is_newest_first_and_isolated_to_the_exact_co_maker(): void
    {
        $actor = User::factory()->create(['full_name' => 'Business Field Actor']);
        $folder = ClientFolder::factory()->create(['created_by' => $actor->id, 'assigned_ci_id' => $actor->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $documentation = $this->documentationFor($folder, $actor, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, $coMakerA->id);
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        $response = $this->actingAs($actor)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'co_maker_id' => $coMakerA->id,
            'kind' => 'picture',
            'files' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('stock.jpg')],
        ], $headers)->assertOk();

        $response->assertJsonPath('recent_activity_category', 'business');
        $this->assertStringContainsString('Uploaded 2 Business Pictures', $response->json('recent_activity_html'));
        $this->assertStringContainsString('Business Field Actor', $response->json('recent_activity_html'));

        $this->actingAs($actor)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            '_method' => 'PATCH',
            'co_maker_id' => $coMakerA->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'location' => 'Updated Business Location',
        ], $headers)->assertOk();

        $coMakerAView = $this->actingAs($actor)->get(route('client-folders.media.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'tab' => 'business']))->assertOk();
        $coMakerAView->assertSee('Updated Business Location')->assertSee('Uploaded 2 Business Pictures')->assertSee('Business Field Actor');
        $xpath = $this->xpath($coMakerAView->getContent());
        $activityText = $xpath->query("//*[@data-documentation-recent-activity='business']")->item(0)->textContent;
        $this->assertLessThan(
            strpos($activityText, 'Uploaded 2 Business Pictures'),
            strpos($activityText, 'Updated Business Location'),
        );
        // The "View All" modal for this exact Co-Maker carries the same two entries — one
        // authoritative source, not a separate query scoped differently from the compact panel.
        $modalText = $xpath->query("//*[@data-documentation-activity-modal-body='business']")->item(0)->textContent;
        $this->assertStringContainsString('Uploaded 2 Business Pictures', $modalText);
        $this->assertStringContainsString('Updated Business Location', $modalText);

        $coMakerBView = $this->actingAs($actor)->get(route('client-folders.media.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id, 'tab' => 'business']))
            ->assertOk()->assertDontSee('Uploaded 2 Business Pictures')->assertDontSee('Updated Business Location');
        $coMakerBModalText = $this->xpath($coMakerBView->getContent())->query("//*[@data-documentation-activity-modal-body='business']")->item(0)->textContent;
        $this->assertStringNotContainsString('Uploaded 2 Business Pictures', $coMakerBModalText);
        $this->assertStringNotContainsString('Updated Business Location', $coMakerBModalText);

        $this->actingAs($actor)->get(route('client-folders.media.index', [$folder, 'tab' => 'business']))
            ->assertOk()->assertDontSee('Uploaded 2 Business Pictures')->assertDontSee('Updated Business Location');
    }

    public function test_compact_recent_activity_shows_latest_five_with_ci_activities_timeline_markup_and_view_all_modal_holds_the_full_history(): void
    {
        $actor = User::factory()->create(['full_name' => 'Timeline Actor']);
        $folder = ClientFolder::factory()->create(['created_by' => $actor->id, 'assigned_ci_id' => $actor->id]);
        $documentation = $this->documentationFor($folder, $actor, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE);
        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

        foreach (range(1, 6) as $i) {
            $this->actingAs($actor)->post(route('client-folders.media.documentation.update', [$folder, $documentation]), [
                '_method' => 'PATCH',
                'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
                'location' => "Address Update {$i}",
            ], $headers)->assertOk();
        }
        // A 7th, distinguishable event (map screenshot) so newest-first ordering can be verified
        // by text rather than by identical "Updated Residence Address" labels.
        $response = $this->actingAs($actor)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg'),
        ], $headers)->assertOk();

        $ids = AuditLog::where('client_folder_id', $folder->id)->orderBy('id')->pluck('id')->values();
        $this->assertCount(7, $ids);
        $newestFive = $ids->slice(-5)->values();
        $oldestTwo = $ids->slice(0, 2)->values();

        $compactHtml = $response->json('recent_activity_html');
        $this->assertSame(5, substr_count($compactHtml, 'data-ci-history-entry-id'));
        foreach ($newestFive as $id) {
            $this->assertStringContainsString('data-ci-history-entry-id="'.$id.'"', $compactHtml);
        }
        foreach ($oldestTwo as $id) {
            $this->assertStringNotContainsString('data-ci-history-entry-id="'.$id.'"', $compactHtml);
        }
        $this->assertStringContainsString('View All', $compactHtml);

        $modalHtml = $response->json('recent_activity_modal_html');
        $this->assertSame(6, substr_count($modalHtml, 'Updated Residence Address'));
        $this->assertSame(1, substr_count($modalHtml, 'Uploaded Residence Google Map Screenshot'));
        $this->assertLessThan(
            strpos($modalHtml, 'Updated Residence Address'),
            strpos($modalHtml, 'Uploaded Residence Google Map Screenshot'),
        );

        // AUTO-UPDATE: one more successful mutation keeps the compact list at exactly 5, drops the
        // next-oldest entry, and never duplicates an id already rendered.
        $response2 = $this->actingAs($actor)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('replacement.jpg'),
        ], $headers)->assertOk();

        $ids2 = AuditLog::where('client_folder_id', $folder->id)->orderBy('id')->pluck('id')->values();
        $this->assertCount(8, $ids2);
        $newestFiveAfter = $ids2->slice(-5)->values();
        $excludedAfter = $ids2->slice(0, 3)->values();

        $compactHtml2 = $response2->json('recent_activity_html');
        $this->assertSame(5, substr_count($compactHtml2, 'data-ci-history-entry-id'));
        foreach ($newestFiveAfter as $id) {
            $this->assertStringContainsString('data-ci-history-entry-id="'.$id.'"', $compactHtml2);
        }
        foreach ($excludedAfter as $id) {
            $this->assertStringNotContainsString('data-ci-history-entry-id="'.$id.'"', $compactHtml2);
        }
        preg_match_all('/data-ci-history-entry-id="(\d+)"/', $compactHtml2, $matches);
        $this->assertCount(5, $matches[1]);
        $this->assertCount(5, array_unique($matches[1]));

        $modalHtml2 = $response2->json('recent_activity_modal_html');
        $this->assertSame(8, substr_count($modalHtml2, 'Timeline Actor'));
    }

    public function test_empty_documentation_activity_shows_polished_empty_state_and_no_view_all(): void
    {
        $actor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['created_by' => $actor->id, 'assigned_ci_id' => $actor->id]);

        $response = $this->actingAs($actor)->get(route('client-folders.media.index', $folder))->assertOk();
        $activityText = $this->xpath($response->getContent())->query("//*[@data-documentation-recent-activity='residence']")->item(0)->textContent;

        $this->assertStringContainsString('No recent activity yet.', $activityText);
        $this->assertStringNotContainsString('View All', $activityText);
    }

    private function documentationFor(ClientFolder $folder, User $creator, string $category, ?int $coMakerId = null): ResidenceBusinessDocumentation
    {
        return ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'category' => $category,
            'location' => $category === ResidenceBusinessDocumentation::CATEGORY_RESIDENCE ? 'Original Residence Address' : 'Original Business Location',
            'created_by' => $creator->id,
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
