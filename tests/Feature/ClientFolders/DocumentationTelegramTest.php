<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\MediaCategory;
use App\Enums\MediaType;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\DocumentationTelegramDelivery;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Media\DocumentationCaptionBuilder;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentationTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake(config('cims.media_disk'));
        config([
            'services.telegram.bot_token' => 'unit-test-bot-token',
            'services.telegram.chat_id' => '-1001234567890',
        ]);
        Http::preventStrayRequests();
    }

    public function test_telegram_is_unavailable_when_configuration_is_missing_and_the_token_is_never_rendered(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-bytes');
        config(['services.telegram.chat_id' => null]);

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $response->assertSee('Not connected');
        $response->assertSee('aria-disabled="true"', false);
        $response->assertDontSee('unit-test-bot-token');

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('statusType', 'error')
            ->assertSessionHas('status', 'Telegram is not connected. Add the required Telegram configuration first.');
        Http::assertNothingSent();
    }

    public function test_configured_ready_documentation_has_confirmation_and_sending_ui(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-bytes');

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', $folder))->assertOk();

        $response->assertSee('Connected');
        $response->assertSee('data-telegram-message-body', false);
        $response->assertSee("Client Name: {$folder->display_name}\nResidence pictures and videos with Google Map located at {$documentation->location}");
        $response->assertSee('Sent by: '.$ci->full_name);
        $response->assertSee('Reset to Default');
        $response->assertSee('data-modal-open="telegram-send-'.$documentation->id.'"', false);
        $response->assertSee('Send this Residence Documentation to Telegram?');
        $response->assertSee('data-telegram-send-form', false);
        $response->assertSee('Sending multiple photos or videos may take a little longer depending on your internet connection. Please wait until the sending process is complete.');
        $response->assertSee('Please wait. Sending time depends on the number and size of files and your internet connection.');
        $response->assertSee("form.dataset.submitting = 'true'", false);
        $response->assertSee("label.textContent = 'Sending to Telegram...'", false);
        $response->assertSee("textarea.value = button.dataset.defaultMessage ?? ''", false);
        $response->assertDontSee('unit-test-bot-token');
    }

    public function test_empty_and_over_limit_custom_messages_are_rejected_without_contacting_telegram(): void
    {
        $ci = User::factory()->create(['full_name' => 'Validation Sender']);
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        $limit = app(DocumentationCaptionBuilder::class)->maxMessageBodyLength($ci);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]), [
            'telegram_message_body' => '   ',
        ])->assertSessionHasErrors(['telegram_message_body' => 'Telegram message cannot be empty.']);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]), [
            'telegram_message_body' => str_repeat('x', $limit + 1),
        ])->assertSessionHasErrors(['telegram_message_body' => 'Telegram message is too long. Please shorten it before sending.']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('documentation_telegram_deliveries', 0);
    }

    public function test_successful_save_locally_refreshes_the_default_preview_from_saved_documentation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');

        $this->actingAs($ci)->patch(route('client-folders.media.documentation.update', [$folder, $documentation]), [
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'New authoritative residence address',
            'remarks' => 'New authoritative remarks',
        ])->assertRedirect();

        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'residence_documentation' => $documentation->id]))
            ->assertOk()
            ->assertSee('Residence pictures and videos with Google Map located at New authoritative residence address')
            ->assertSee("Remarks:\nNew authoritative remarks")
            ->assertDontSee('Unsent custom text');
    }

    public function test_applicant_and_co_makers_cannot_send_another_persons_documentation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, $coMakerB->id);
        $this->pictureFor($documentation, 'house.jpg', 'house-bytes');

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertNotFound();

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [
            $folder,
            $documentation,
            'person' => 'co-maker',
            'co_maker_id' => $coMakerA->id,
        ]), ['co_maker_id' => $coMakerA->id])->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('documentation_telegram_deliveries', 0);
    }

    public function test_residence_requires_a_picture_but_not_map_remarks_or_video(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Add at least one Residence Picture before sending to Telegram.');
        Http::assertNothingSent();

        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        $nextMessageId = 40;
        $photoWasAttached = false;
        Http::fake(function (Request $request) use (&$nextMessageId, &$photoWasAttached) {
            if (str_ends_with($request->url(), '/sendPhoto')) {
                $photoWasAttached = $this->requestHasFileContents($request, 'photo', 'house.jpg', 'house-content');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();
        $this->assertCount(2, $urls);
        $this->assertStringEndsWith('/sendMessage', $urls[0]);
        $this->assertStringEndsWith('/sendPhoto', $urls[1]);
        $this->assertTrue($photoWasAttached);
    }

    public function test_caption_map_pictures_and_videos_are_sent_in_exact_saved_order_with_local_attachments(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $folder->update(['display_name' => 'Reynaldo Obasa']);
        $documentation = $this->documentationFor($folder);
        $map = $this->pictureFor($documentation, 'map.jpg', 'map-content');
        $documentation->update(['map_screenshot_media_id' => $map->id]);
        $this->pictureFor($documentation, 'house-1.jpg', 'picture-one');
        $this->pictureFor($documentation, 'house-2.jpg', 'picture-two');
        $this->videoFor($documentation, 'walkthrough.mp4', 'video-content');

        $nextMessageId = 100;
        $attachmentOrder = [];
        $chatIds = [];
        Http::fake(function (Request $request) use (&$nextMessageId, &$attachmentOrder, &$chatIds) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $chatIds[] = (string) $request['chat_id'];
            } else {
                foreach ($request->data() as $part) {
                    if (($part['name'] ?? null) === 'chat_id') {
                        $chatIds[] = (string) ($part['contents'] ?? '');
                    }
                }
            }
            if (str_ends_with($request->url(), '/sendPhoto')) {
                foreach ([
                    'map.jpg' => 'map-content',
                    'house-1.jpg' => 'picture-one',
                    'house-2.jpg' => 'picture-two',
                ] as $name => $contents) {
                    if ($this->requestHasFileContents($request, 'photo', $name, $contents)) {
                        $attachmentOrder[] = $name;
                    }
                }
            } elseif (str_ends_with($request->url(), '/sendVideo') && $this->requestHasFileContents($request, 'video', 'walkthrough.mp4', 'video-content')) {
                $attachmentOrder[] = 'walkthrough.mp4';
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
        $this->assertCount(5, $requests);
        $this->assertStringEndsWith('/sendMessage', $requests[0]->url());
        $this->assertSame("Client Name: Reynaldo Obasa\nResidence pictures and videos with Google Map located at Bugo, Cagayan de Oro\n\nSent by: {$ci->full_name}", $requests[0]['text']);
        $this->assertSame(array_fill(0, 5, '-1001234567890'), $chatIds);
        $this->assertSame(['map.jpg', 'house-1.jpg', 'house-2.jpg', 'walkthrough.mp4'], $attachmentOrder);

        $delivery = DocumentationTelegramDelivery::sole();
        $this->assertSame(DocumentationTelegramDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame($ci->id, $delivery->sent_by);
        $this->assertSame([101, 102, 103, 104, 105], array_values($delivery->message_ids));
        $this->assertNotNull($delivery->completed_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'residence_business_documentation.telegram_sent',
            'client_folder_id' => $folder->id,
        ]);
        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'residence_documentation' => $documentation->id]))
            ->assertOk()
            ->assertSee('Already Sent')
            ->assertSee('Sent to Telegram');
    }

    public function test_optional_missing_media_creates_no_placeholder_requests(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        $nextMessageId = 0;
        $requestBodies = [];
        Http::fake(function (Request $request) use (&$nextMessageId, &$requestBodies) {
            $requestBodies[] = $request->body();

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))->assertRedirect();

        $this->assertCount(2, Http::recorded());
        foreach ($requestBodies as $body) {
            $this->assertStringNotContainsString('No map screenshot', $body);
            $this->assertStringNotContainsString('No videos uploaded', $body);
            $this->assertStringNotContainsString('Remarks:', $body);
        }
    }

    public function test_api_failure_is_persisted_and_retry_resumes_after_accepted_messages(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        $call = 0;
        $retrying = false;
        Http::fake(function () use (&$call, &$retrying) {
            if ($retrying) {
                return Http::response(['ok' => true, 'result' => ['message_id' => 502]]);
            }

            $call++;

            return $call === 1
                ? Http::response(['ok' => true, 'result' => ['message_id' => 501]])
                : Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400);
        });

        $customBody = "Custom retry caption.\nKeep this exact text.";
        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]), [
            'telegram_message_body' => $customBody,
            'telegram_documentation_id' => $documentation->id,
        ])
            ->assertRedirect()
            ->assertSessionHas('statusType', 'error')
            ->assertSessionHas('status', 'Telegram rejected Residence/Business Picture (400: Bad Request: chat not found). Verify that the configured group is correct and the BRBI CIMS Bot is still a member.');

        $delivery = DocumentationTelegramDelivery::sole();
        $this->assertSame(DocumentationTelegramDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(['caption' => 501], $delivery->message_ids);
        $this->assertSame($customBody, $delivery->message_body);
        $this->assertSame('picture:'.$documentation->pictures()->sole()->id, $delivery->active_key);
        $this->assertStringContainsString('400: Bad Request: chat not found', $delivery->error_summary);
        $this->assertTrue(Storage::disk(config('cims.media_disk'))->exists($documentation->pictures()->sole()->temporary_local_path));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'residence_business_documentation.telegram_sent']);

        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'residence_documentation' => $documentation->id]))
            ->assertOk()
            ->assertSee($customBody);

        $retrying = true;
        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]), [
            'telegram_message_body' => $delivery->message_body,
            'telegram_documentation_id' => $documentation->id,
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $allRequests = Http::recorded();
        $this->assertCount(3, $allRequests);
        $this->assertStringEndsWith('/sendPhoto', $allRequests->last()[0]->url());
        $this->assertSame(1, $allRequests->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/sendMessage'))->count());
        $this->assertSame(DocumentationTelegramDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(['caption' => 501, 'picture:'.$documentation->pictures()->sole()->id => 502], $delivery->fresh()->message_ids);
    }

    public function test_telegram_rejection_redacts_secret_urls_from_persisted_and_user_errors(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        Http::fake(fn () => Http::response([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request at https://api.telegram.org/bot123456:VERY_SECRET/sendMessage',
        ], 400));

        $customBody = 'Custom caption retained after rejection.';
        $response = $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]), [
            'telegram_message_body' => $customBody,
            'telegram_documentation_id' => $documentation->id,
        ])
            ->assertRedirect()
            ->assertSessionHas('statusType', 'error');

        $this->assertStringNotContainsString('VERY_SECRET', (string) session('status'));
        $this->assertStringNotContainsString('VERY_SECRET', DocumentationTelegramDelivery::sole()->error_summary);
        $this->assertStringContainsString('[redacted URL]', DocumentationTelegramDelivery::sole()->error_summary);
        $this->assertSame('caption', DocumentationTelegramDelivery::sole()->active_key);
        $this->assertSame($customBody, DocumentationTelegramDelivery::sole()->message_body);
        $this->actingAs($ci)->get(route('client-folders.media.index', [$folder, 'residence_documentation' => $documentation->id]))
            ->assertOk()
            ->assertSee($customBody)
            ->assertDontSee('VERY_SECRET');
    }

    public function test_caption_and_preview_use_the_authenticated_sender_not_documentation_ownership_or_request_input(): void
    {
        $creator = User::factory()->create(['full_name' => 'Documentation Creator']);
        $assignedCi = User::factory()->create(['full_name' => 'Assigned Investigator']);
        $sender = User::factory()->create(['full_name' => 'Actual Telegram Sender']);
        $folder = ClientFolder::factory()->create([
            'display_name' => 'TEST, TEST',
            'created_by' => $creator->id,
            'assigned_ci_id' => $assignedCi->id,
        ]);
        $residence = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE);
        $residence->update(['created_by' => $creator->id]);
        $this->pictureFor($residence, 'house.jpg', 'house-content');
        $sentCaption = null;
        Http::fake(function (Request $request) use (&$sentCaption) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $sentCaption = $request['text'];
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 901]]);
        });

        $customBody = "Residence verification completed.\nAdditional CI note.";
        $originalLocation = $residence->location;
        $originalRemarks = $residence->remarks;
        $this->actingAs($sender)->post(route('client-folders.media.documentation.telegram', [$folder, $residence]), [
            'telegram_message_body' => $customBody,
            'sender_name' => 'Spoofed User',
            'sent_by' => $creator->id,
        ])->assertRedirect()->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $this->assertSame($customBody."\n\nSent by: Actual Telegram Sender", $sentCaption);
        $this->assertStringEndsWith("\n\nSent by: Actual Telegram Sender", $sentCaption);
        $this->assertStringNotContainsString('Spoofed User', $sentCaption);
        $this->assertStringNotContainsString('Documentation Creator', $sentCaption);
        $this->assertStringNotContainsString('Assigned Investigator', $sentCaption);
        $delivery = DocumentationTelegramDelivery::sole();
        $this->assertSame($sender->id, $delivery->sent_by);
        $this->assertSame($customBody, $delivery->message_body);
        $this->assertSame($originalLocation, $residence->fresh()->location);
        $this->assertSame($originalRemarks, $residence->fresh()->remarks);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'residence_business_documentation.telegram_sent',
            'user_id' => $sender->id,
        ]);

        $this->actingAs($sender)->get(route('client-folders.media.documentation.preview', [$folder, $residence]))
            ->assertOk()
            ->assertSee('Residence pictures and videos with Google Map located at Bugo, Cagayan de Oro')
            ->assertSee('Sent by: Actual Telegram Sender')
            ->assertDontSee('Residence verification completed.')
            ->assertDontSee('Spoofed User');

        $this->actingAs($sender)->get(route('client-folders.media.index', [$folder, 'residence_documentation' => $residence->id]))
            ->assertOk()
            ->assertSee('Residence pictures and videos with Google Map located at Bugo, Cagayan de Oro')
            ->assertDontSee('Additional CI note.');

        $business = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'Carmen');
        $this->actingAs($sender)->get(route('client-folders.media.documentation.preview', [$folder, $business]))
            ->assertOk()
            ->assertSee("Business: Legacy / Unassigned\nLocation: Carmen")
            ->assertSee('Sent by: Actual Telegram Sender');
    }

    public function test_business_send_places_remarks_before_the_authenticated_sender_and_omits_blank_remarks(): void
    {
        $sender = User::factory()->create(['full_name' => 'Business Sender']);
        $folder = $this->folderFor($sender);
        $folder->update(['display_name' => 'Business Client']);
        $business = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'Carmen');
        $business->update(['remarks' => 'Store is well maintained.']);
        $map = $this->pictureFor($business, 'map.jpg', 'map-content');
        $business->update(['map_screenshot_media_id' => $map->id]);
        $this->pictureFor($business, 'store.jpg', 'store-content');
        $this->actingAs($sender)->get(route('client-folders.media.index', [
            $folder,
            'business_documentation' => $business->id,
            'tab' => 'business',
        ]))->assertOk()
            ->assertSee("Business: Legacy / Unassigned\nLocation: Carmen\nRemarks: Store is well maintained.")
            ->assertSee('Sent by: Business Sender');
        $sentCaption = null;
        Http::fake(function (Request $request) use (&$sentCaption) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $sentCaption = $request['text'];
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 950]]);
        });

        $customBody = "Business verification completed.\nStore operations confirmed.";
        $this->actingAs($sender)->post(route('client-folders.media.documentation.telegram', [$folder, $business]), [
            'telegram_message_body' => $customBody,
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $this->assertSame($customBody."\n\nSent by: Business Sender", $sentCaption);
        $this->assertStringEndsWith("\n\nSent by: Business Sender", $sentCaption);
        $this->assertSame($sender->id, DocumentationTelegramDelivery::sole()->sent_by);
        $this->assertSame($customBody, DocumentationTelegramDelivery::sole()->message_body);
        $this->assertSame('Carmen', $business->fresh()->location);
        $this->assertSame('Store is well maintained.', $business->fresh()->remarks);
        $this->assertStringNotContainsString((string) config('services.telegram.bot_token'), $sentCaption);

        $blankRemarks = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, null, 'Blank Remarks Location');
        $blankCaption = app(DocumentationCaptionBuilder::class)->buildForSender($blankRemarks, $sender);
        $this->assertStringNotContainsString('Remarks:', $blankCaption);
        $this->assertStringEndsWith("\n\nSent by: Business Sender", $blankCaption);
    }

    public function test_retry_without_accepted_messages_uses_and_persists_the_current_authenticated_sender(): void
    {
        $firstSender = User::factory()->create(['full_name' => 'First Sender']);
        $retrySender = User::factory()->create(['full_name' => 'Retry Sender']);
        $folder = $this->folderFor($firstSender);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        $captions = [];
        $retrying = false;
        Http::fake(function (Request $request) use (&$captions, &$retrying) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $captions[] = $request['text'];
            }

            return $retrying
                ? Http::response(['ok' => true, 'result' => ['message_id' => 902]])
                : Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400);
        });
        $this->actingAs($firstSender)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))->assertRedirect();

        $retrying = true;
        $this->actingAs($retrySender)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $successful = DocumentationTelegramDelivery::where('status', DocumentationTelegramDelivery::STATUS_SENT)->sole();
        $this->assertSame($retrySender->id, $successful->sent_by);
        $this->assertStringEndsWith("\n\nSent by: Retry Sender", $captions[1]);
        $this->assertCount(1, DocumentationTelegramDelivery::all());
    }

    public function test_missing_local_file_fails_safely_without_exposing_a_path(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $picture = $this->pictureFor($documentation, 'missing.jpg', 'temporary-content');
        Storage::disk(config('cims.media_disk'))->delete($picture->temporary_local_path);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 601]]));

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'One of the saved media files could not be found. Please verify the documentation before sending again.')
            ->assertSessionHas('statusType', 'error');

        $this->assertSame(DocumentationTelegramDelivery::STATUS_FAILED, DocumentationTelegramDelivery::sole()->status);
        $this->assertStringNotContainsString($picture->temporary_local_path, DocumentationTelegramDelivery::sole()->error_summary);
        Http::assertNothingSent();
    }

    public function test_successful_or_in_progress_delivery_blocks_duplicate_sends(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        $nextMessageId = 700;
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]));

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))->assertRedirect();
        $sentRequestCount = Http::recorded()->count();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'This documentation was already successfully sent to Telegram.');
        $this->assertCount($sentRequestCount, Http::recorded());

        $second = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, null, 'Second location');
        $this->pictureFor($second, 'second.jpg', 'second-content');
        DocumentationTelegramDelivery::create([
            'residence_business_documentation_id' => $second->id,
            'client_folder_id' => $folder->id,
            'sent_by' => $ci->id,
            'chat_id_snapshot' => '-1001234567890',
            'status' => DocumentationTelegramDelivery::STATUS_SENDING,
            'payload_fingerprint' => str_repeat('a', 64),
            'active_key' => 'documentation:'.$second->id,
            'message_ids' => [],
            'started_at' => now(),
        ]);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $second]))
            ->assertRedirect()
            ->assertSessionHas('status', 'This documentation is already being sent to Telegram.');
        $this->assertCount($sentRequestCount, Http::recorded());
    }

    public function test_business_keeps_its_map_requirement_and_preview_never_sends(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS);
        $this->pictureFor($documentation, 'store.jpg', 'store-content');

        $this->actingAs($ci)->get(route('client-folders.media.documentation.preview', [$folder, $documentation]))
            ->assertOk();
        Http::assertNothingSent();

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Add a Google Map Screenshot before sending this Business Documentation to Telegram.');
        Http::assertNothingSent();
    }

    public function test_telegram_reads_ci_team_media_without_moving_or_deleting_the_original(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $path = app(CiTeamDocumentStorage::class)->residencePicturesDirectory($folder).'/telegram-test.jpg';
        app(CiTeamDocumentStorage::class)->disk()->put($path, 'ci-team-photo');
        MediaReference::create([
            'client_folder_id' => $folder->id,
            'residence_business_documentation_id' => $documentation->id,
            'media_type' => MediaType::Photo,
            'category' => MediaCategory::Residence,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CI_TEAM,
            'file_name' => 'telegram-test.jpg',
            'label' => 'Residence',
            'mime_type' => 'image/jpeg',
            'byte_size' => 13,
            'checksum' => hash('sha256', 'ci-team-photo'),
            'uploaded_by' => $ci->id,
            'temporary_local_path' => $path,
        ]);
        $nextMessageId = 800;
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]));

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))->assertRedirect();

        $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($path));
        $this->assertSame('ci-team-photo', app(CiTeamDocumentStorage::class)->disk()->get($path));
    }

    public function test_telegram_reads_legacy_clients_root_media_without_rewriting_or_deleting_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder);
        $documents = app(CiTeamDocumentStorage::class);
        $path = 'BRBI-CI-2026-00001 - Legacy Client/Residence Pictures/Pictures/legacy-telegram.jpg';
        $legacyDisk = Storage::build([
            'driver' => 'local',
            'root' => $documents->root().DIRECTORY_SEPARATOR.'Clients',
            'throw' => true,
        ]);
        $legacyDisk->put($path, 'legacy-ci-team-photo');
        MediaReference::create([
            'client_folder_id' => $folder->id,
            'residence_business_documentation_id' => $documentation->id,
            'media_type' => MediaType::Photo,
            'category' => MediaCategory::Residence,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CI_TEAM,
            'file_name' => 'legacy-telegram.jpg',
            'label' => 'Legacy Residence',
            'mime_type' => 'image/jpeg',
            'byte_size' => 20,
            'checksum' => hash('sha256', 'legacy-ci-team-photo'),
            'uploaded_by' => $ci->id,
            'temporary_local_path' => $path,
        ]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 801]]));

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $this->assertSame($path, MediaReference::sole()->temporary_local_path);
        $this->assertTrue($legacyDisk->exists($path));
        $this->assertSame('legacy-ci-team-photo', $legacyDisk->get($path));
    }

    public function test_successful_send_preserves_all_saved_documentation_data_media_files_and_visible_previews(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, null, 'Permanent Residence Location');
        $documentation->update(['remarks' => 'Permanent saved remarks.']);

        $this->actingAs($ci)->post(route('client-folders.media.documentation.map-screenshot', [$folder, $documentation]), [
            'map_screenshot' => UploadedFile::fake()->image('map.jpg', 600, 400),
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'picture',
            'files' => [
                UploadedFile::fake()->image('house-1.jpg'),
                UploadedFile::fake()->image('house-2.jpg'),
            ],
        ])->assertRedirect();
        $video = UploadedFile::fake()->createWithContent('walkthrough.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08mdat");
        $this->actingAs($ci)->post(route('client-folders.media.documentation.upload-media', [$folder, $documentation]), [
            'kind' => 'video',
            'files' => [$video],
        ])->assertRedirect();

        $documentation->refresh()->load(['mapScreenshot', 'pictures', 'videos']);
        $mapId = $documentation->map_screenshot_media_id;
        $pictureIds = $documentation->pictures->pluck('id')->all();
        $videoIds = $documentation->videos->pluck('id')->all();
        $paths = $documentation->pictures->concat($documentation->videos)->push($documentation->mapScreenshot)
            ->flatMap(fn (MediaReference $media): array => array_filter([$media->temporary_local_path, $media->thumbnail_path]))
            ->all();

        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 1001]]));
        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $documentation->refresh();
        $this->assertSame('Permanent Residence Location', $documentation->location);
        $this->assertSame('Permanent saved remarks.', $documentation->remarks);
        $this->assertSame($mapId, $documentation->map_screenshot_media_id);
        $this->assertSame($pictureIds, $documentation->pictures()->pluck('id')->all());
        $this->assertSame($videoIds, $documentation->videos()->pluck('id')->all());
        $this->assertSame(4, MediaReference::where('residence_business_documentation_id', $documentation->id)->count());
        foreach ($paths as $path) {
            $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($path));
        }

        $response = $this->actingAs($ci)->get(route('client-folders.media.index', [
            $folder,
            'tab' => 'residence',
            'residence_documentation' => $documentation->id,
        ]))->assertOk();
        $response->assertSee('value="Permanent Residence Location"', false)
            ->assertSee('Permanent saved remarks.')
            ->assertSee('data-map-screenshot-preview-img', false);
        foreach ($pictureIds as $id) {
            $response->assertSee('data-photo-id="'.$id.'"', false);
        }
        foreach ($videoIds as $id) {
            $response->assertSee('data-video-id="'.$id.'"', false);
        }
    }

    public function test_successful_telegram_json_response_returns_authoritative_recent_activity_for_the_actual_sender(): void
    {
        $creator = User::factory()->create(['full_name' => 'Original Documentation Creator']);
        $sender = User::factory()->create(['full_name' => 'Actual Telegram Activity Sender']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $sender->id]);
        $documentation = $this->documentationFor($folder);
        $documentation->update(['created_by' => $creator->id]);
        $this->pictureFor($documentation, 'house.jpg', 'house-content');
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 7001]]));

        $response = $this->actingAs($sender)->post(route('client-folders.media.documentation.telegram', [$folder, $documentation]), [], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk();

        $response->assertJsonPath('result', 'success')
            ->assertJsonPath('recent_activity_category', 'residence');
        $this->assertStringContainsString('Sent Residence Documentation to Telegram', $response->json('recent_activity_html'));
        $this->assertStringContainsString('Actual Telegram Activity Sender', $response->json('recent_activity_html'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'residence_business_documentation.telegram_sent',
            'user_id' => $sender->id,
        ]);
    }

    public function test_send_all_businesses_groups_three_saved_businesses_in_stable_bd_order_with_isolated_media(): void
    {
        $ci = User::factory()->create(['full_name' => 'Batch Sender']);
        $folder = $this->folderFor($ci);
        $first = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'Poblacion');
        $first->update(['business_name' => 'Sari-Sari Store', 'remarks' => 'Actively operating.']);
        $firstMap = $this->pictureFor($first, 'store-map.jpg', 'store-map');
        $first->update(['map_screenshot_media_id' => $firstMap->id]);
        $this->pictureFor($first, 'store-picture.jpg', 'store-picture');
        $this->videoFor($first, 'store-video.mp4', 'store-video');

        $second = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'National Highway');
        $second->update(['business_name' => 'Welding Shop', 'remarks' => '   ']);
        $this->pictureFor($second, 'welding-picture.jpg', 'welding-picture');

        $third = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'P-2');
        $third->update(['business_name' => 'Poultry Farm']);

        $residence = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, null, 'Applicant Home');
        $this->pictureFor($residence, 'home.jpg', 'home');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER']);
        $otherPersonBusiness = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, $coMaker->id, 'Other Person');
        $otherPersonBusiness->update(['business_name' => 'Excluded Business']);

        $texts = [];
        $mediaNames = [];
        $nextMessageId = 2000;
        Http::fake(function (Request $request) use (&$texts, &$mediaNames, &$nextMessageId) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = $request['text'];
            }
            foreach (['store-map.jpg', 'store-picture.jpg', 'store-video.mp4', 'welding-picture.jpg'] as $name) {
                if ($this->requestHasFileContents($request, str_ends_with($name, '.mp4') ? 'video' : 'photo', $name, match ($name) {
                    'store-map.jpg' => 'store-map',
                    'store-picture.jpg' => 'store-picture',
                    'store-video.mp4' => 'store-video',
                    default => 'welding-picture',
                })) {
                    $mediaNames[] = $name;
                }
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$nextMessageId]]);
        });

        $preview = $this->actingAs($ci)->get(route('client-folders.media.business-documentations.preview', $folder))->assertOk();
        $preview->assertSeeInOrder(['Sari-Sari Store', 'Welding Shop', 'Poultry Farm'])
            ->assertDontSee('Excluded Business')
            ->assertSee('No saved pictures.');
        Http::assertNothingSent();

        $this->actingAs($ci)->post(route('client-folders.media.business-documentations.telegram', $folder))
            ->assertRedirect()
            ->assertSessionHas('status', 'All 3 saved businesses were sent successfully to Telegram.');

        $this->assertSame('Business Pictures and Videos', $texts[0]);
        $this->assertStringContainsString("Business: Sari-Sari Store\nLocation: Poblacion\nRemarks: Actively operating.", $texts[1]);
        $this->assertStringContainsString("Business: Welding Shop\nLocation: National Highway", $texts[2]);
        $this->assertStringNotContainsString('Remarks:', $texts[2]);
        $this->assertStringContainsString("Business: Poultry Farm\nLocation: P-2", $texts[3]);
        $this->assertSame(['store-map.jpg', 'store-picture.jpg', 'store-video.mp4', 'welding-picture.jpg'], $mediaNames);
        $this->assertSame([$first->id, $second->id, $third->id], DocumentationTelegramDelivery::orderBy('id')->pluck('residence_business_documentation_id')->all());
        $this->assertSame(3, DocumentationTelegramDelivery::where('status', DocumentationTelegramDelivery::STATUS_SENT)->count());
        $this->assertDatabaseMissing('documentation_telegram_deliveries', ['residence_business_documentation_id' => $residence->id]);
        $this->assertDatabaseMissing('documentation_telegram_deliveries', ['residence_business_documentation_id' => $otherPersonBusiness->id]);
    }

    public function test_send_this_business_still_sends_only_the_selected_saved_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $selected = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'Selected Location');
        $selected->update(['business_name' => 'Selected Store']);
        $selectedMap = $this->pictureFor($selected, 'selected-map.jpg', 'selected-map');
        $selected->update(['map_screenshot_media_id' => $selectedMap->id]);
        $this->pictureFor($selected, 'selected.jpg', 'selected');

        $other = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'Other Location');
        $other->update(['business_name' => 'Other Store']);
        $otherMap = $this->pictureFor($other, 'other-map.jpg', 'other-map');
        $other->update(['map_screenshot_media_id' => $otherMap->id]);
        $this->pictureFor($other, 'other.jpg', 'other');
        $texts = [];
        Http::fake(function (Request $request) use (&$texts) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = $request['text'];
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 2500 + count($texts)]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.documentation.telegram', [$folder, $selected]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Documentation sent successfully to Telegram.');

        $this->assertCount(1, $texts);
        $this->assertStringContainsString('Business: Selected Store', $texts[0]);
        $this->assertStringNotContainsString('Other Store', $texts[0]);
        $this->assertSame([$selected->id], DocumentationTelegramDelivery::pluck('residence_business_documentation_id')->all());
    }

    public function test_send_all_businesses_uses_each_business_last_saved_delivery_caption(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $first = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'First Location');
        $first->update(['business_name' => 'First Store']);
        $second = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, 'Second Location');
        $second->update(['business_name' => 'Second Store']);
        DocumentationTelegramDelivery::create([
            'residence_business_documentation_id' => $second->id,
            'client_folder_id' => $folder->id,
            'sent_by' => $ci->id,
            'chat_id_snapshot' => '-1001234567890',
            'status' => DocumentationTelegramDelivery::STATUS_FAILED,
            'payload_fingerprint' => str_repeat('b', 64),
            'message_body' => 'Saved custom caption for Second Store',
            'active_key' => 'caption',
            'message_ids' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $texts = [];
        Http::fake(function (Request $request) use (&$texts) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = $request['text'];
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 3500 + count($texts)]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.business-documentations.telegram', $folder))->assertRedirect();

        $this->assertStringContainsString('Business: First Store', $texts[1]);
        $this->assertStringContainsString('Saved custom caption for Second Store', $texts[2]);
        $this->assertStringEndsWith('Sent by: '.$ci->full_name, $texts[2]);
        $this->assertDatabaseHas('documentation_telegram_deliveries', [
            'residence_business_documentation_id' => $second->id,
            'status' => DocumentationTelegramDelivery::STATUS_SENT,
            'message_body' => 'Saved custom caption for Second Store',
        ]);
    }

    public function test_send_all_businesses_keeps_applicant_and_each_co_maker_strictly_isolated(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        foreach ([
            [null, 'Applicant Store'], [null, 'Applicant Farm'],
            [$coMakerA->id, 'A Store'], [$coMakerA->id, 'A Farm'],
            [$coMakerB->id, 'B Store'],
        ] as [$coMakerId, $name]) {
            $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, $coMakerId, $name.' Location');
            $documentation->update(['business_name' => $name]);
        }
        $texts = [];
        Http::fake(function (Request $request) use (&$texts) {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = $request['text'];
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => count($texts) + 3000]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.business-documentations.telegram', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMakerA->id,
        ]), ['co_maker_id' => $coMakerA->id])->assertRedirect();

        $joined = implode("\n", $texts);
        $this->assertStringContainsString('Business: A Store', $joined);
        $this->assertStringContainsString('Business: A Farm', $joined);
        $this->assertStringNotContainsString('Applicant Store', $joined);
        $this->assertStringNotContainsString('B Store', $joined);
        $this->assertSame(2, DocumentationTelegramDelivery::count());
        $this->assertSame([$coMakerA->id], DocumentationTelegramDelivery::distinct()->pluck('co_maker_id')->all());
    }

    public function test_send_all_businesses_stops_on_failure_without_marking_later_businesses_sent(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $businesses = collect(['First', 'Second', 'Third'])->map(function (string $name) use ($folder): ResidenceBusinessDocumentation {
            $documentation = $this->documentationFor($folder, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, null, $name.' Location');
            $documentation->update(['business_name' => $name]);

            return $documentation;
        });
        $requestNumber = 0;
        Http::fake(function () use (&$requestNumber) {
            $requestNumber++;

            return $requestNumber === 3
                ? Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'], 400)
                : Http::response(['ok' => true, 'result' => ['message_id' => 4000 + $requestNumber]]);
        });

        $this->actingAs($ci)->post(route('client-folders.media.business-documentations.telegram', $folder))
            ->assertRedirect()
            ->assertSessionHas('statusType', 'error');

        $this->assertDatabaseHas('documentation_telegram_deliveries', [
            'residence_business_documentation_id' => $businesses[0]->id,
            'status' => DocumentationTelegramDelivery::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('documentation_telegram_deliveries', [
            'residence_business_documentation_id' => $businesses[1]->id,
            'status' => DocumentationTelegramDelivery::STATUS_FAILED,
        ]);
        $this->assertDatabaseMissing('documentation_telegram_deliveries', [
            'residence_business_documentation_id' => $businesses[2]->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function documentationFor(ClientFolder $folder, string $category = ResidenceBusinessDocumentation::CATEGORY_RESIDENCE, ?int $coMakerId = null, string $location = 'Bugo, Cagayan de Oro'): ResidenceBusinessDocumentation
    {
        return ResidenceBusinessDocumentation::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'category' => $category,
            'location' => $location,
            'created_by' => $folder->created_by,
        ]);
    }

    private function pictureFor(ResidenceBusinessDocumentation $documentation, string $name, string $contents): MediaReference
    {
        return $this->mediaFor($documentation, MediaType::Photo, $name, $contents);
    }

    private function videoFor(ResidenceBusinessDocumentation $documentation, string $name, string $contents): MediaReference
    {
        return $this->mediaFor($documentation, MediaType::Video, $name, $contents);
    }

    private function mediaFor(ResidenceBusinessDocumentation $documentation, MediaType $type, string $name, string $contents): MediaReference
    {
        $path = 'client-media/'.$documentation->client_folder_id.'/tests/'.$name;
        Storage::disk(config('cims.media_disk'))->put($path, $contents);

        return MediaReference::create([
            'client_folder_id' => $documentation->client_folder_id,
            'co_maker_id' => $documentation->co_maker_id,
            'residence_business_documentation_id' => $documentation->id,
            'media_type' => $type,
            'category' => $documentation->category === ResidenceBusinessDocumentation::CATEGORY_RESIDENCE ? MediaCategory::Residence : MediaCategory::Business,
            'file_name' => $name,
            'label' => pathinfo($name, PATHINFO_FILENAME),
            'mime_type' => $type === MediaType::Photo ? 'image/jpeg' : 'video/mp4',
            'byte_size' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'uploaded_by' => $documentation->created_by,
            'temporary_local_path' => $path,
        ]);
    }

    private function requestHasFileContents(Request $request, string $field, string $filename, string $expectedContents): bool
    {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) !== $field || ($part['filename'] ?? null) !== $filename) {
                continue;
            }

            $contents = $part['contents'] ?? null;
            if (is_resource($contents)) {
                $actual = stream_get_contents($contents);
                rewind($contents);

                return $actual === $expectedContents;
            }

            return $contents === $expectedContents;
        }

        return false;
    }
}
