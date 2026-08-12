<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\MediaCategory;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessReport;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessReportCompletionEvaluator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ResidenceBusinessReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_access_is_limited_to_administrator_and_assigned_ci_and_deleted_folders_are_unavailable(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $this->actingAs($admin)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $this->actingAs($other)->get(route('client-folders.residence-business.edit', $folder))->assertForbidden();
        $this->actingAs($other)->get(route('client-folders.residence-business.preview', $folder))->assertForbidden();
        $folder->delete();
        $this->actingAs($admin)->get(route('client-folders.residence-business.edit', $folder->id))->assertNotFound();
    }

    public function test_repeated_saves_update_one_report_and_existing_sections(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $payload = $this->completePayload($source, $residenceMedia, $businessMedia);
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload)->assertRedirect();
        $report = $folder->residenceBusinessReport()->firstOrFail();
        $payload['sections'][0]['id'] = $report->sections()->where('category', 'residence')->value('id');
        $payload['sections'][1]['id'] = $report->sections()->where('category', 'business')->value('id');
        $payload['sections'][0]['remarks'] = 'Updated residence finding';
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload)->assertRedirect();

        $this->assertSame(1, ResidenceBusinessReport::where('client_folder_id', $folder->id)->count());
        $this->assertSame(2, $report->sections()->count());
        $this->assertSame('Updated residence finding', $report->sections()->where('category', 'residence')->value('remarks'));
        $this->assertSame(2, $report->fresh()->revision);
    }

    public function test_report_supports_residence_co_maker_and_multiple_distinct_business_sections(): void
    {
        [$ci, $folder, $firstSource, $residenceMedia, $firstBusinessMedia] = $this->context();
        $secondSource = $this->incomeSource($folder, 'Second Business');
        $secondBusinessMedia = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $secondSource->id, 'category' => MediaCategory::Business]);
        $payload = $this->completePayload($firstSource, $residenceMedia, $firstBusinessMedia);
        $payload['sections'][] = ['category' => 'residence', 'subject_party' => 'co_maker', 'subject_name' => 'Co-maker Person', 'heading_subject' => 'Residence Check', 'location' => 'Co-maker Address', 'sort_order' => 3, 'media' => [$this->mediaRow($residenceMedia, 1, 'Co-maker Residence')]];
        $payload['sections'][] = ['category' => 'business', 'subject_party' => 'applicant', 'heading_subject' => 'Business Check', 'business_name' => 'Second Business', 'income_source_id' => $secondSource->id, 'location' => 'Second Location', 'sort_order' => 4, 'media' => [$this->mediaRow($secondBusinessMedia, 1, 'Second Business')]];

        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload)->assertRedirect();
        $report = $folder->residenceBusinessReport()->firstOrFail();
        $this->assertSame(2, $report->sections()->where('category', 'residence')->count());
        $this->assertSame(2, $report->sections()->where('category', 'business')->count());
        $this->assertEqualsCanonicalizing([$firstSource->id, $secondSource->id], $report->sections()->where('category', 'business')->pluck('income_source_id')->all());
    }

    public function test_media_links_preserve_output_metadata_and_can_be_reordered_or_unlinked(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $extra = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'category' => MediaCategory::Residence]);
        $payload = $this->completePayload($source, $residenceMedia, $businessMedia);
        $payload['sections'][0]['media'][] = $this->mediaRow($extra, 2, 'Second View', 'Second caption');
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload);
        $section = $folder->residenceBusinessReport->sections()->where('category', 'residence')->firstOrFail();
        $this->assertDatabaseHas('photo_report_media', ['photo_report_section_id' => $section->id, 'media_reference_id' => $extra->id, 'output_label' => 'Second View', 'caption' => 'Second caption', 'sort_order' => 2]);

        $payload['sections'][0]['id'] = $section->id;
        $payload['sections'][1]['id'] = $folder->residenceBusinessReport->sections()->where('category', 'business')->value('id');
        $payload['sections'][0]['media'] = [$this->mediaRow($extra, 1, 'First View')];
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload);
        $this->assertDatabaseMissing('photo_report_media', ['photo_report_section_id' => $section->id, 'media_reference_id' => $residenceMedia->id]);
        $this->assertDatabaseHas('photo_report_media', ['photo_report_section_id' => $section->id, 'media_reference_id' => $extra->id, 'sort_order' => 1]);
    }

    public function test_forged_section_source_and_media_ids_are_rejected(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $foreignSource = $this->incomeSource($otherFolder, 'Foreign Source');
        $foreignMedia = MediaReference::factory()->create(['client_folder_id' => $otherFolder->id, 'income_source_id' => $foreignSource->id, 'category' => MediaCategory::Business]);
        $foreignReport = ResidenceBusinessReport::factory()->create(['client_folder_id' => $otherFolder->id, 'ci_user_id' => $ci->id]);
        $foreignSection = $foreignReport->sections()->create(['category' => 'residence', 'subject_party' => 'applicant']);
        $payload = $this->completePayload($source, $residenceMedia, $businessMedia);
        $payload['sections'][0]['id'] = $foreignSection->id;
        $payload['sections'][1]['income_source_id'] = $foreignSource->id;
        $payload['sections'][1]['media'] = [$this->mediaRow($foreignMedia, 1)];

        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload)
            ->assertSessionHasErrors(['sections.0.id', 'sections.1.income_source_id', 'sections.1.media.0.media_reference_id']);
        $this->assertDatabaseMissing('residence_business_reports', ['client_folder_id' => $folder->id]);
    }

    public function test_media_from_another_income_source_or_category_cannot_be_linked(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $otherSource = $this->incomeSource($folder, 'Other Business');
        $otherBusinessMedia = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $otherSource->id, 'category' => MediaCategory::Business]);
        $payload = $this->completePayload($source, $residenceMedia, $businessMedia);
        $payload['sections'][0]['media'] = [$this->mediaRow($businessMedia, 1)];
        $payload['sections'][1]['media'] = [$this->mediaRow($otherBusinessMedia, 1)];

        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload)
            ->assertSessionHasErrors(['sections.0.media.0.media_reference_id', 'sections.1.media.0.media_reference_id']);
    }

    public function test_transaction_rolls_back_when_completion_evaluation_fails(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $this->app->instance(ResidenceBusinessReportCompletionEvaluator::class, new class extends ResidenceBusinessReportCompletionEvaluator
        {
            public function evaluate(ResidenceBusinessReport $report): bool
            {
                throw new RuntimeException('Injected completion failure');
            }
        });

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        try {
            $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $this->completePayload($source, $residenceMedia, $businessMedia));
        } finally {
            $this->assertDatabaseMissing('residence_business_reports', ['client_folder_id' => $folder->id]);
            $this->assertDatabaseCount('photo_report_sections', 0);
        }
    }

    public function test_completion_recalculates_progress_and_folder_overview_uses_real_route(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $this->completePayload($source, $residenceMedia, $businessMedia));
        $report = $folder->residenceBusinessReport()->firstOrFail();
        $this->assertSame(RecordState::Complete, $report->state);
        $result = $folder->completionResults()->whereHas('rule', fn ($query) => $query->where('code', 'residence_business_report'))->firstOrFail();
        $this->assertTrue($result->is_satisfied);
        $this->assertEquals(16.67, $folder->refresh()->progress_percent);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee(route('client-folders.residence-business.edit', $folder), false)->assertSee('Residence and business report record available.');
    }

    public function test_preview_uses_official_paper_foundation_two_media_pages_maps_and_escaped_narratives(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $extraOne = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'category' => MediaCategory::Residence]);
        $extraTwo = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'category' => MediaCategory::Residence]);
        $payload = $this->completePayload($source, $residenceMedia, $businessMedia);
        $payload['sections'][0]['google_maps_link'] = 'https://maps.google.com/example';
        $payload['sections'][0]['remarks'] = '<script>alert("unsafe")</script>';
        $payload['sections'][0]['media'] = [$this->mediaRow($residenceMedia, 1), $this->mediaRow($extraOne, 2), $this->mediaRow($extraTwo, 3)];
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->assertSee('Read-only Report Preview')->assertSee('https://maps.google.com/example')->assertSee('&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;', false)->assertDontSee('<script>alert("unsafe")</script>', false);
        $this->assertGreaterThanOrEqual(3, substr_count($response->getContent(), 'official-report-page photo-report-page'));
        $css = file_get_contents(resource_path('css/print/official-report.css'));
        $this->assertStringContainsString('size: 8.5in 13in', $css);
        $this->assertStringContainsString('grid-template-rows: repeat(2', $css);
    }

    public function test_encoding_ui_has_section_controls_existing_media_only_and_safe_audit_metadata(): void
    {
        [$ci, $folder, $source, $residenceMedia, $businessMedia] = $this->context();
        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->assertSee('+ Residence Section')->assertSee('+ Business Section')->assertSee('Existing Media References')->assertSee('Preview Report')->assertDontSee('Upload Media');
        $payload = $this->completePayload($source, $residenceMedia, $businessMedia);
        $payload['residence_remarks'] = 'PRIVATE RESIDENCE NARRATIVE';
        $payload['sections'][0]['media'][0]['caption'] = 'PRIVATE CAPTION';
        $this->actingAs($ci)->put(route('client-folders.residence-business.update', $folder), $payload)->assertRedirect();
        $audit = AuditLog::where('action', 'residence_business_report.created')->firstOrFail();
        $json = json_encode($audit->metadata);
        $this->assertStringNotContainsString('PRIVATE RESIDENCE NARRATIVE', $json);
        $this->assertStringNotContainsString('PRIVATE CAPTION', $json);
    }

    private function context(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->incomeSource($folder, 'Water Refilling');
        $residenceMedia = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'category' => MediaCategory::Residence, 'label' => 'Residence Front']);
        $businessMedia = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $source->id, 'category' => MediaCategory::Business, 'label' => 'Business Front']);

        return [$ci, $folder, $source, $residenceMedia, $businessMedia];
    }

    private function incomeSource(ClientFolder $folder, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        return $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
    }

    private function completePayload(IncomeSource $source, MediaReference $residenceMedia, MediaReference $businessMedia): array
    {
        return ['intent' => 'complete', 'report_date' => now()->toDateString(), 'default_location' => 'Default Address', 'default_subject' => 'Residence and Business Check', 'residence_remarks' => 'Residence confirmed', 'business_remarks' => 'Business confirmed', 'sections' => [
            ['category' => 'residence', 'subject_party' => 'applicant', 'heading_subject' => 'Residence Check', 'location' => 'Applicant Address', 'google_maps_link' => 'https://maps.google.com/residence', 'remarks' => 'Residence finding', 'sort_order' => 1, 'media' => [$this->mediaRow($residenceMedia, 1, 'Residence Front', 'Front view')]],
            ['category' => 'business', 'subject_party' => 'applicant', 'heading_subject' => 'Business Check', 'business_name' => $source->business_name, 'income_source_id' => $source->id, 'location' => 'Business Address', 'google_maps_link' => 'https://maps.google.com/business', 'remarks' => 'Business finding', 'sort_order' => 2, 'media' => [$this->mediaRow($businessMedia, 1, 'Business Front', 'Storefront')]],
        ]];
    }

    private function mediaRow(MediaReference $media, int $order, ?string $label = null, ?string $caption = null): array
    {
        return ['media_reference_id' => $media->id, 'selected' => true, 'output_label' => $label, 'caption' => $caption, 'sort_order' => $order];
    }
}
