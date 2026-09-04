<?php

namespace Tests\Feature\Reports;

use App\Enums\GenerationStatus;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Phase 3: official Business Report output must show the exact saved IncomeSource CI
 * participant list (primary creator first, then companions in saved order) — never the
 * folder's assigned_ci_id, never Business Check's own participants.
 */
class BusinessReportMultiCiOutputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_creator_only_historical_report_shows_only_the_creator(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator);

        $this->assertSame('REY MAGHILOM', app(CiParticipantService::class)->fullNames($source));
        $this->previewFor($creator, $folder, $source)->assertSee('REY MAGHILOM');
    }

    public function test_creator_plus_one_companion_renders_slash_separated(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $anthony = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator, [$anthony->id]);

        $this->assertSame('REY MAGHILOM / ANTHONY YONG', app(CiParticipantService::class)->fullNames($source));
        $this->previewFor($creator, $folder, $source)
            ->assertSee('REY MAGHILOM / ANTHONY YONG')
            ->assertDontSee('REY MAGHILOM / ANTHONY YONG / ANTHONY YONG');
    }

    public function test_multiple_companions_preserve_saved_order(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $anthony = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        // Submitted out of alphabetical/id order on purpose — saved order must be preserved.
        $source = $this->businessSource($folder, $creator, [$mark->id, $anthony->id]);

        $this->assertSame('REY MAGHILOM / MARK DELA CRUZ / ANTHONY YONG', app(CiParticipantService::class)->fullNames($source));
        $this->previewFor($creator, $folder, $source)->assertSee('REY MAGHILOM / MARK DELA CRUZ / ANTHONY YONG');
    }

    public function test_duplicate_submitted_participant_ids_do_not_duplicate_output(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $anthony = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator, [$anthony->id, $anthony->id, $creator->id]);

        $this->assertSame('REY MAGHILOM / ANTHONY YONG', app(CiParticipantService::class)->fullNames($source));
    }

    public function test_output_uses_creator_not_folder_assigned_ci(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $assignedCi = User::factory()->create(['full_name' => 'MARK ASSIGNED']);
        $yong = User::factory()->create(['full_name' => 'YONG SANTOS']);
        // Folder is assigned to a different CI than the one who created this Business Report.
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $assignedCi->id]);
        $source = $this->businessSource($folder, $creator, [$yong->id]);

        $output = app(CiParticipantService::class)->fullNames($source);
        $this->assertSame('REY MAGHILOM / YONG SANTOS', $output);
        $this->assertStringNotContainsString('MARK ASSIGNED', $output);
        $this->previewFor($creator, $folder, $source)
            ->assertSee('REY MAGHILOM / YONG SANTOS')
            ->assertDontSee('MARK ASSIGNED');
    }

    public function test_another_editor_does_not_automatically_appear_in_output(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $editor = User::factory()->create(['full_name' => 'EDITOR NOT A PARTICIPANT']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator);

        // Editor saves the report without ever touching the companion picker.
        $this->actingAs($editor)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->businessPayload());

        $output = app(CiParticipantService::class)->fullNames($source->fresh());
        $this->assertSame('REY MAGHILOM', $output);
        $this->assertStringNotContainsString('EDITOR NOT A PARTICIPANT', $output);
    }

    public function test_editor_appears_in_output_only_after_being_explicitly_saved_as_companion(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $editor = User::factory()->create(['full_name' => 'YONG EXPLICIT COMPANION']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator);

        $this->assertSame('REY MAGHILOM', app(CiParticipantService::class)->fullNames($source));

        $payload = $this->businessPayload() + ['contributor_ids_present' => '1', 'contributor_ids' => [$editor->id]];
        $this->actingAs($editor)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)->assertSessionHasNoErrors();

        $this->assertSame('REY MAGHILOM / YONG EXPLICIT COMPANION', app(CiParticipantService::class)->fullNames($source->fresh()));
    }

    public function test_applicant_and_co_maker_business_reports_have_isolated_output(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $applicantCompanion = User::factory()->create(['full_name' => 'APPLICANT COMPANION']);
        $coMakerCompanion = User::factory()->create(['full_name' => 'COMAKER COMPANION']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);

        $applicantSource = $this->businessSource($folder, $creator, [$applicantCompanion->id]);
        $coMakerSource = $this->businessSource($folder, $creator, [$coMakerCompanion->id], $coMaker);

        $participants = app(CiParticipantService::class);
        $this->assertSame('REY MAGHILOM / APPLICANT COMPANION', $participants->fullNames($applicantSource));
        $this->assertSame('REY MAGHILOM / COMAKER COMPANION', $participants->fullNames($coMakerSource));

        $this->previewFor($creator, $folder, $applicantSource)
            ->assertSee('REY MAGHILOM / APPLICANT COMPANION')
            ->assertDontSee('COMAKER COMPANION');
        $this->previewFor($creator, $folder, $coMakerSource)
            ->assertSee('REY MAGHILOM / COMAKER COMPANION')
            ->assertDontSee('APPLICANT COMPANION');
    }

    public function test_business_check_participants_do_not_affect_business_report_output(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $businessCheckCi = User::factory()->create(['full_name' => 'BUSINESS CHECK CI']);
        $businessCheckCompanion = User::factory()->create(['full_name' => 'BUSINESS CHECK COMPANION']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator);

        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $businessCheckCi->id,
        ]);
        app(CiParticipantService::class)->syncCompanions($check, [$businessCheckCompanion->id]);

        $output = app(CiParticipantService::class)->fullNames($source->fresh());
        $this->assertSame('REY MAGHILOM', $output);
        $this->assertStringNotContainsString('BUSINESS CHECK', $output);
    }

    public function test_web_preview_displays_the_correct_full_name_participant_string(): void
    {
        $creator = User::factory()->create(['full_name' => 'REYNALDO J. OBASA']);
        $mark = User::factory()->create(['full_name' => 'MARK S. DELA CRUZ']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator, [$mark->id]);

        $this->previewFor($creator, $folder, $source)->assertOk()->assertSee('REYNALDO J. OBASA / MARK S. DELA CRUZ');
    }

    public function test_pdf_export_uses_the_same_saved_participant_data_as_the_web_preview(): void
    {
        // The web Preview and PDF export share the exact same reports.official.business partial
        // (PDF is that same HTML rendered through Dompdf) — this is the same pattern already
        // relied on for CIBI text assertions in OfficialReportGenerationTest.
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $anthony = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator, [$anthony->id]);

        $this->previewFor($creator, $folder, $source)->assertSee('REY MAGHILOM / ANTHONY YONG');

        $response = $this->actingAs($creator)->post(route('client-folders.income-sources.export-pdf', [$folder, $source]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertSame('%PDF', substr($response->streamedContent(), 0, 4));
    }

    #[RunInSeparateProcess]
    public function test_excel_export_uses_the_correct_multi_ci_string_in_the_mapped_header(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $anthony = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        // retail_grocery_water_refilling is one of BusinessExcelExporter's mapped templates,
        // which independently queries its own CI In-Charge cell (not the generic header array).
        $source = $this->businessSource($folder, $creator, [$anthony->id]);

        $response = $this->actingAs($creator)->post(route('client-folders.income-sources.export-excel', [$folder, $source]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $temporary = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($temporary, $response->streamedContent());
        // The output workbook's single sheet is retitled "Business Report" (title case) by
        // BusinessExcelExporter::fromTemplate() — distinct from the SHEET constant ("BUSINESS
        // REPORT", all caps) it uses to locate the section within the reference template file.
        $sheet = IOFactory::load($temporary)->getSheet(0);
        unlink($temporary);

        $this->assertSame('REY MAGHILOM / ANTHONY YONG', $sheet?->getCell('G6')->getValue());
    }

    public function test_docx_export_uses_the_correct_multi_ci_string_in_the_generic_header(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $anthony = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator, [$anthony->id]);

        $this->actingAs($creator)->post(route('client-folders.generated-reports.store', $folder), [
            'report_type' => 'business_income_source', 'format' => 'docx', 'income_source_id' => $source->id,
        ])->assertRedirect();

        $report = GeneratedReport::where('report_type', 'business_income_source')->where('format', 'docx')->sole();
        $this->assertSame(GenerationStatus::Completed, $report->status);
        $this->assertSame('PK', substr(app(CiTeamDocumentStorage::class)->disk()->get($report->private_file_reference), 0, 2));
    }

    public function test_long_participant_list_renders_fully_without_truncation(): void
    {
        $creator = User::factory()->create(['full_name' => 'REYNALDO J. OBASA']);
        $companions = collect(['MARK S. DELA CRUZ', 'YONG P. SANTOS', 'JUAN P. REYES'])
            ->map(fn (string $name) => User::factory()->create(['full_name' => $name]));
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator, $companions->pluck('id')->all());

        $expected = 'REYNALDO J. OBASA / MARK S. DELA CRUZ / YONG P. SANTOS / JUAN P. REYES';
        $this->assertSame($expected, app(CiParticipantService::class)->fullNames($source));
        $this->previewFor($creator, $folder, $source)->assertSee($expected);
    }

    public function test_historical_single_ci_report_without_any_contributor_rows_remains_backward_compatible(): void
    {
        $creator = User::factory()->create(['full_name' => 'LEGACY CREATOR']);
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
        $source = $this->businessSource($folder, $creator);

        $this->assertSame(0, $source->contributors()->count());
        $this->assertSame('LEGACY CREATOR', app(CiParticipantService::class)->fullNames($source));
        $this->previewFor($creator, $folder, $source)->assertOk()->assertSee('LEGACY CREATOR');
    }

    /** @param  array<int, int>  $companionIds */
    private function businessSource(ClientFolder $folder, User $creator, array $companionIds = [], ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'income_source_template_id' => $template->id,
            'template_type' => $template->template_type, 'template_version' => 1,
            'source_name' => 'Saved Store', 'created_by' => $creator->id, 'co_maker_id' => $coMaker?->id,
        ]);
        BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => 'Saved Store', 'registered_owner' => 'Saved Owner']);

        if ($companionIds !== []) {
            app(CiParticipantService::class)->syncCompanions($source, $companionIds);
        }

        return $source;
    }

    private function businessPayload(): array
    {
        return ['intent' => 'stay', 'source_name' => 'Saved Store', 'business_name' => 'Saved Store', 'report_category' => 'Retail', 'main_business_address' => 'Main Street', 'start_date' => '2026-01-01', 'year_established' => 2020];
    }

    private function previewFor(User $actor, ClientFolder $folder, IncomeSource $source)
    {
        return $this->actingAs($actor)->get(route('client-folders.generated-reports.preview', [
            $folder, 'report_type' => 'business_income_source', 'income_source_id' => $source->id,
        ]));
    }
}
