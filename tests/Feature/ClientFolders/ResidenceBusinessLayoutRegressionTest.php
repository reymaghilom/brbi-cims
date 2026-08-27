<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for two UI cleanup fixes on the Residence & Business Checks page and the
 * Business / Income Sources page:
 *
 * 1. The "+ Add Residence Check" button had been wrapped in `@if($residenceChecks->isEmpty())`
 *    during an earlier compact-card redesign, hiding it the moment a person had one saved check.
 *    residence_checks has no unique constraint on (client_folder_id, co_maker_id) — only an index
 *    — and SyncResidenceCheckLocation/SyncResidenceCheckCiDate both explicitly account for "a
 *    legacy multi-check scenario", confirming multiple checks per person are genuinely supported,
 *    not just tolerated. The button is unconditional again, matching Business Checks' own Add
 *    button (which was never gated) and the last-committed baseline before that redesign.
 * 2. The redundant page-level headings "Residence & Business Report" and "Business / Income
 *    Sources" were removed from those two pages — the section headings and business content below
 *    them already make the page purpose clear.
 */
class ResidenceBusinessLayoutRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    /** A brand-new Residence Check always needs the required Residence Picture. */
    private function withPhoto(array $overrides = []): array
    {
        return array_merge(['photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)]], $overrides);
    }

    public function test_applicant_residence_section_shows_add_residence_check_when_no_check_exists_yet(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('Add Residence Check');
    }

    public function test_applicant_residence_section_still_shows_add_residence_check_after_one_already_exists(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto());

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('Add Residence Check');
    }

    public function test_co_maker_residence_section_shows_the_correctly_scoped_add_residence_check_button(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMaker->id]));

        $response = $this->actingAs($ci)
            ->get(route('client-folders.residence-business.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk();

        $response->assertSee('Add Residence Check');
        $expectedUrl = route('client-folders.residence-checks.create', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $response->assertSee('data-check-report-url="'.e($expectedUrl).'"', false);
    }

    public function test_add_residence_check_url_contains_the_applicant_context_not_a_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();

        $expectedUrl = route('client-folders.residence-checks.create', $folder);
        $response->assertSee('data-check-report-url="'.e($expectedUrl).'"', false);
    }

    public function test_add_residence_check_button_still_targets_the_existing_check_report_dialog(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();

        $response->assertSee('data-modal-open="check-report-dialog"', false);
        $response->assertSee('id="check-report-dialog"', false);
    }

    public function test_old_residence_and_business_report_heading_is_no_longer_rendered(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();

        $response->assertDontSee('<h1 class="ui-page-title">Residence &amp; Business Report</h1>', false);
        // The actual sections still open the page instead.
        $response->assertSee('Residence Check');
        $response->assertSee('Business Checks');
    }

    public function test_old_business_income_sources_heading_is_no_longer_rendered(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk();

        $response->assertDontSee('<h1 class="ui-page-title">Business / Income Sources</h1>', false);
    }

    public function test_business_functionality_still_renders_normally_on_the_income_sources_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk();

        $response->assertSee('Add Business');
        $response->assertSee('data-modal-open="add-business-template-dialog"', false);
    }

    public function test_residence_check_listing_still_renders_normally(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['remarks' => 'Residence confirmed']));

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();

        $response->assertSee('Residence Check');
        $response->assertSee('1 Residence Report');
    }

    public function test_add_business_check_button_remains_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();

        $response->assertSee('Add Business Check');
        $response->assertSee(route('client-folders.business-checks.create', $folder), false);
    }

    /**
     * The shared check-report-dialog's title defaults to the generic "Residence & Business
     * Checks" unless a trigger specifies its own via data-check-report-title (see app.js's
     * titleHeading logic) — Residence Check's own triggers already set "Residence Check", so
     * Business Check's triggers must set their own "Business Checks" title too, rather than
     * falling through to the generic combined default.
     */
    public function test_business_check_triggers_set_their_own_modal_title(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/data-check-report-url="'.preg_quote(route('client-folders.business-checks.create', $folder), '/').'"\s+data-check-report-title="Business Checks"/',
            $content,
        );
    }

    public function test_applicant_and_co_maker_residence_sections_stay_isolated_after_the_button_fix(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['remarks' => 'Applicant residence check']));
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->withPhoto(['co_maker_id' => $coMaker->id, 'remarks' => 'Co-Maker residence check']));

        $applicantResponse = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();
        $applicantResponse->assertSee('Add Residence Check');
        $applicantResponse->assertDontSee('co_maker_id='.$coMaker->id, false);

        $coMakerResponse = $this->actingAs($ci)
            ->get(route('client-folders.residence-business.edit', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk();
        $coMakerResponse->assertSee('Add Residence Check');
        $coMakerResponse->assertSee('co_maker_id='.$coMaker->id, false);
    }

    public function test_report_summary_sidebar_is_narrower_on_desktop_leaving_more_room_for_the_main_content(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk();

        $response->assertSee('xl:grid-cols-[minmax(0,1fr)_minmax(180px,210px)]', false);
        $response->assertDontSee('xl:grid-cols-[minmax(0,1fr)_minmax(240px,280px)]', false);
    }

    public function test_residence_and_business_tables_both_show_a_visible_actions_column_heading(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        // Visibly rendered, not merely present for screen readers.
        $this->assertSame(2, substr_count($content, '<th class="px-2 py-3 text-center">Actions</th>'));
        $this->assertStringNotContainsString('<span class="sr-only">Actions</span>', $content);
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
