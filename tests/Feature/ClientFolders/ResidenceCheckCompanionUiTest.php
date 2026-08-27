<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Multiple CI / Companion CI for Residence Check — the backend (CiParticipantService,
 * UpdateResidenceCheckContributors, the residence-checks.contributors.update route) already
 * existed and was already covered generically (see CiParticipantFoundationTest and
 * ResidenceBusinessReportTest's own contributor tests) before this feature had any UI. This file
 * covers what's actually new here: the Add/Edit form rendering the same CI In-Charge + Companion
 * CI widget/modal Business Check already uses, the primary-CI display bug that widget replaces
 * (the old "CI Name" field always showed the *current* auth user, never the actual saved
 * investigator), and the official report now showing the full participant list instead of just
 * the primary CI's first name.
 */
class ResidenceCheckCompanionUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_add_residence_check_form_shows_the_ci_in_charge_widget_and_add_companion_button(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('CI In-Charge', $content);
        $this->assertStringContainsString('Add Companion CI', $content);
        $this->assertStringContainsString('data-companion-dialog-id="residence-check-companion-ci-dialog"', $content);
        $this->assertStringContainsString('id="residence-check-companion-ci-dialog"', $content);
        // Still the same three accordions — no new section was added for this.
        $this->assertStringContainsString('residence-basic-info-title', $content);
        $this->assertStringContainsString('residence-photos-title', $content);
        $this->assertStringContainsString('residence-map-title', $content);
    }

    public function test_edit_form_shows_the_actual_primary_ci_not_the_current_editor(): void
    {
        $creator = User::factory()->create(['full_name' => 'REYNALDO OBASA']);
        $editor = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $folder = $this->folderFor($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        // A different active CI (who could legitimately open this record — see
        // test_contributor_assignment_does_not_hide_the_check_from_another_ci) must still see the
        // real creator as CI In-Charge, never themselves.
        $content = $this->actingAs($editor)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk()->getContent();

        $this->assertStringContainsString('REYNALDO OBASA', $content);
        $this->assertMatchesRegularExpression('/data-ci-primary-name>REYNALDO OBASA</', $content);
    }

    public function test_edit_form_shows_saved_companions_in_order(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $yong = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        app(CiParticipantService::class)->syncCompanions($check, [$yong->id, $mark->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk()->getContent();

        $yongPos = strpos($content, 'ANTHONY YONG');
        $markPos = strpos($content, 'MARK DELA CRUZ');
        $this->assertNotFalse($yongPos);
        $this->assertNotFalse($markPos);
        $this->assertLessThan($markPos, $yongPos, 'Companions must render in their saved order.');
    }

    public function test_updating_a_residence_check_never_replaces_the_primary_ci_or_auto_adds_the_editor(): void
    {
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        $folder = $this->folderFor($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($editor)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Updated by a different CI.',
        ]);

        $check->refresh();
        $this->assertSame($creator->id, $check->ci_user_id);
        $this->assertSame([$creator->id], app(CiParticipantService::class)->orderedParticipantIds($check));
    }

    public function test_report_shows_primary_ci_then_companions_as_first_names(): void
    {
        $creator = User::factory()->create(['full_name' => 'JUAN DELA CRUZ']);
        $companionOne = User::factory()->create(['full_name' => 'PEDRO SANTOS']);
        $companionTwo = User::factory()->create(['full_name' => 'MARIA REYES']);
        $folder = $this->folderFor($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        app(CiParticipantService::class)->syncCompanions($check, [$companionOne->id, $companionTwo->id]);

        $preview = $this->actingAs($creator)->get(route('client-folders.residence-business.preview', $folder))->assertOk();

        $preview->assertSee('JUAN / PEDRO / MARIA', false);
        $preview->assertDontSee('JUAN DELA CRUZ / PEDRO SANTOS / MARIA REYES', false);
    }

    public function test_report_shows_only_the_primary_first_name_when_there_are_no_companions(): void
    {
        $creator = User::factory()->create(['full_name' => 'JUAN DELA CRUZ']);
        $folder = $this->folderFor($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);

        $preview = $this->actingAs($creator)->get(route('client-folders.residence-business.preview', $folder))->assertOk();

        $preview->assertSee('JUAN', false);
        $preview->assertDontSee('JUAN /', false);
    }

    public function test_report_participants_never_leak_between_applicant_and_co_maker_residence_checks(): void
    {
        $ci = User::factory()->create(['full_name' => 'JUAN DELA CRUZ']);
        $applicantCompanion = User::factory()->create(['full_name' => 'PEDRO SANTOS']);
        $coMakerCompanion = User::factory()->create(['full_name' => 'MARIA REYES']);
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'CO MAKER PERSON', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $applicantCheck = $folder->residenceChecks()->where('co_maker_id', null)->firstOrFail();
        app(CiParticipantService::class)->syncCompanions($applicantCheck, [$applicantCompanion->id]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address', 'photos' => [UploadedFile::fake()->image('Front2.jpg', 900, 700)->size(500)],
        ]);
        $coMakerCheck = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        app(CiParticipantService::class)->syncCompanions($coMakerCheck, [$coMakerCompanion->id]);

        $applicantPreview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $applicantPreview->assertSee('JUAN / PEDRO', false)->assertDontSee('MARIA', false);

        $coMakerPreview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk();
        $coMakerPreview->assertSee('JUAN / MARIA', false)->assertDontSee('PEDRO', false);
    }

    /**
     * Web preview, the PDF batch export, and the DOCX batch export all read the exact same
     * OfficialReportDataBuilder::residenceCheckSection()['ci'] value (see
     * BuildsOfficialReportDocx::residenceHeaderTable() and the residence branch of
     * _photo-sections.blade.php) — this proves that single-source-of-truth line actually reaches
     * the DOCX bytes themselves as first names, not just that the export doesn't error.
     */
    public function test_pdf_and_docx_batch_exports_show_the_same_first_names_line_as_the_web_preview(): void
    {
        $creator = User::factory()->create(['full_name' => 'JUAN DELA CRUZ']);
        $companion = User::factory()->create(['full_name' => 'PEDRO SANTOS']);
        $folder = $this->folderFor($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        app(CiParticipantService::class)->syncCompanions($check, [$companion->id]);

        $pdf = $this->actingAs($creator)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $docxResponse = $this->actingAs($creator)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'residence_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertGreaterThan(0, strlen($pdf->streamedContent()));

        $docxPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        file_put_contents($docxPath, $docxResponse->streamedContent());
        $zip = new \ZipArchive();
        $zip->open($docxPath);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($docxPath);

        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $documentXml, $matches);
        $text = implode('', $matches[1]);

        $this->assertStringContainsString('JUAN / PEDRO', $text);
        $this->assertStringNotContainsString('JUAN DELA CRUZ / PEDRO SANTOS', $text);
    }

    /**
     * The actual bug: SaveResidenceCheck's CREATE path never touched contributor_ids at all — only
     * a later, separate Update actually synced companions (via UpdateResidenceCheckContributors).
     * The fix wires that exact same authoritative action into SaveResidenceCheck's save() for both
     * create and update, so the very first Save already persists companions — no second Update
     * required. This posts through the real HTTP store route with the same contributor_ids[]/
     * contributor_ids_present payload the companion picker actually submits (see
     * residence-checks/form.blade.php), not a direct CiParticipantService::syncCompanions() call, so
     * it genuinely exercises the save flow this bug was in.
     */
    public function test_create_saves_companions_immediately_without_requiring_a_separate_update(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $juan = User::factory()->create(['full_name' => 'JUAN SANTOS']);
        $folder = $this->folderFor($creator);

        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id, $juan->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame($creator->id, $check->ci_user_id);
        $this->assertSame([$mark->id, $juan->id], $check->contributors()->orderByPivot('position')->pluck('users.id')->all());
    }

    public function test_primary_ci_is_never_stored_as_a_companion_even_if_submitted(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $folder = $this->folderFor($creator);

        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'contributor_ids_present' => '1', 'contributor_ids' => [$creator->id, $mark->id],
        ])->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertSame([$mark->id], $check->contributors()->pluck('users.id')->all());
    }

    public function test_update_still_saves_companion_changes(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $folder = $this->folderFor($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$mark->id], $check->fresh()->contributors()->pluck('users.id')->all());
    }

    public function test_first_report_load_after_create_already_shows_the_saved_companions(): void
    {
        $creator = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $juan = User::factory()->create(['full_name' => 'JUAN SANTOS']);
        $folder = $this->folderFor($creator);

        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id, $juan->id],
        ])->assertSessionHasNoErrors();

        // No Update in between — the very first report load after Create must already read the
        // companions this same request just persisted.
        $preview = $this->actingAs($creator)->get(route('client-folders.residence-business.preview', $folder))->assertOk();

        $preview->assertSee('REY / MARK / JUAN', false);
    }

    public function test_created_applicant_and_co_maker_checks_never_leak_companions(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY MAGHILOM']);
        $mark = User::factory()->create(['full_name' => 'MARK DELA CRUZ']);
        $juan = User::factory()->create(['full_name' => 'JUAN SANTOS']);
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'CO MAKER PERSON', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Applicant Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address',
            'photos' => [UploadedFile::fake()->image('Front2.jpg', 900, 700)->size(500)],
            'contributor_ids_present' => '1', 'contributor_ids' => [$juan->id],
        ])->assertSessionHasNoErrors();

        $applicantCheck = $folder->residenceChecks()->where('co_maker_id', null)->firstOrFail();
        $coMakerCheck = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame([$mark->id], $applicantCheck->contributors()->pluck('users.id')->all());
        $this->assertSame([$juan->id], $coMakerCheck->contributors()->pluck('users.id')->all());

        $applicantPreview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $applicantPreview->assertSee('REY / MARK', false)->assertDontSee('JUAN', false);

        $coMakerPreview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk();
        $coMakerPreview->assertSee('REY / JUAN', false)->assertDontSee('MARK', false);
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
