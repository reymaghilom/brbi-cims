<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Enums\RecordState;
use App\Http\Controllers\IncomeSourceController;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Business / Income Sources and Business Check are INDEPENDENT modules.
 *
 * A Business Check MAY reference an existing business, in which case that exact Business Report
 * prefills it (Report -> Check, one way only, snapshotted on save). It may equally reference no
 * business at all, in which case the CI types the business details straight onto the Check and
 * income_source_id stays null.
 *
 * What Business Check must NEVER do, in either mode: create an IncomeSource, create a
 * BusinessReport (not even a shell), assign a template, bump a revision, change a completion
 * state, or write any of its own values back into a Business Report. The retired "+ Add Business"
 * quick-create and the Check -> Report prefill that used to make those things happen are gone.
 */
class BusinessCheckIndependentArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // 1. No existing business — manual Business Check
    // ---------------------------------------------------------------------

    public function test_person_without_a_business_gets_editable_manual_fields_and_no_add_business_control(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        // Manual mode: all three business fields are plain editable inputs.
        $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*name="business_name"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-business-name"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-location"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="ci_date"[^>]*readonly/', $html);

        // Client Name is derived and displayed, never an input.
        $this->assertStringContainsString('Applicant / Co-Maker Name', $html);
        $this->assertStringNotContainsString('name="client_name"', $html);

        // No Add Business entry point of any kind survives. ("Add Business Check" — the page's own
        // breadcrumb/title — is a different string and legitimately stays.)
        $this->assertStringNotContainsString('Add Business</button>', $html);
        $this->assertStringNotContainsString('data-business-check-add-new', $html);
        $this->assertStringNotContainsString('business-check-quick-add-dialog', $html);
        $this->assertStringNotContainsString('data-quick-add-business-confirm', $html);

        // Merely opening the form creates nothing.
        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    public function test_manual_business_check_saves_without_creating_an_income_source_or_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'MANUAL SARI-SARI STORE',
            'location' => 'Purok 3, Poblacion',
            'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('store.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertNull($check->income_source_id, 'A manual Business Check keeps a null business reference.');
        $this->assertSame('MANUAL SARI-SARI STORE', $check->business_name);
        $this->assertSame('Purok 3, Poblacion', $check->location);
        $this->assertSame('2026-02-10', $check->ci_date->toDateString());

        $this->assertSame(0, IncomeSource::query()->count(), 'Business Check never creates an IncomeSource.');
        $this->assertSame(0, BusinessReport::query()->count(), 'Business Check never creates a Business Report.');
    }

    public function test_manual_business_check_stays_editable_when_reopened_and_still_creates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $check = $this->manualCheck($ci, $folder, 'MANUAL STORE', 'Manual Address', '2026-02-10');

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-business-name"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-location"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="ci_date"[^>]*readonly/', $html);
        $this->assertStringContainsString('value="MANUAL STORE"', $html);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'business_name' => 'MANUAL STORE RENAMED',
            'location' => 'Manual Address Updated',
            'ci_date' => '2026-02-11',
            'business_photos' => [UploadedFile::fake()->image('b.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check->refresh();
        $this->assertSame('MANUAL STORE RENAMED', $check->business_name);
        $this->assertSame('Manual Address Updated', $check->location);
        $this->assertNull($check->income_source_id);
        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    public function test_manual_business_check_requires_its_own_business_details(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasErrors(['business_name', 'location', 'ci_date']);

        $this->assertSame(0, BusinessCheck::query()->count());
        $this->assertSame(0, IncomeSource::query()->count());
    }

    // ---------------------------------------------------------------------
    // 2 & 3. Existing business selected — one-way prefill, exact row identity
    // ---------------------------------------------------------------------

    public function test_existing_business_is_listed_and_prefills_read_only_fields(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');

        $html = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $business->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('ALPHA TRADING', $html);
        $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*value="ALPHA TRADING"/', $html);
        $this->assertMatchesRegularExpression('/<input id="business-check-location"[^>]*value="Alpha Address"/', $html);
        $this->assertMatchesRegularExpression('/id="ci_date"[^>]*value="2026-01-15"/', $html);

        foreach (['business-check-business-name', 'business-check-location', 'ci_date'] as $id) {
            $this->assertMatchesRegularExpression('/<input id="'.$id.'"[^>]*readonly/', $html, $id.' is read-only for a referenced business.');
        }

        // The option carries every prefill value the client-side selection handler applies.
        $this->assertMatchesRegularExpression('/<option value="'.$business->id.'"[^>]*data-business-name="ALPHA TRADING"[^>]*data-location="Alpha Address"[^>]*data-ci-date="2026-01-15"/', $html);
    }

    public function test_saving_against_an_existing_business_links_it_and_changes_nothing_on_the_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');
        $before = $this->reportSnapshot($business);
        $beforeRevision = $business->revision;
        $beforeState = $business->state;

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $business->id,
            // What the read-only inputs post is ignored — the referenced business is authoritative.
            'business_name' => 'FORGED NAME',
            'location' => 'FORGED ADDRESS',
            'ci_date' => '2026-03-30',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame($business->id, $check->income_source_id, 'The exact selected business is linked.');
        $this->assertSame('ALPHA TRADING', $check->business_name);
        $this->assertSame('Alpha Address', $check->location);
        $this->assertSame('2026-01-15', $check->ci_date->toDateString());

        $business->refresh();
        $this->assertSame($before, $this->reportSnapshot($business));
        $this->assertSame($beforeRevision, $business->revision, 'Saving a Business Check never bumps the Business Report revision.');
        $this->assertSame($beforeState, $business->state, 'Saving a Business Check never changes IncomeSource state.');
        $this->assertSame(1, IncomeSource::query()->count());
        $this->assertSame(1, BusinessReport::query()->count());
    }

    public function test_selecting_one_of_several_businesses_prefills_only_that_exact_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $a = $this->savedBusiness($folder, 'BUSINESS A', 'Address A', '2026-01-01');
        $b = $this->savedBusiness($folder, 'BUSINESS B', 'Address B', '2026-02-02');
        $c = $this->savedBusiness($folder, 'BUSINESS C', 'Address C', '2026-03-03');

        $html = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $b->id]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*value="BUSINESS B"/', $html);
        $this->assertMatchesRegularExpression('/<input id="business-check-location"[^>]*value="Address B"/', $html);
        $this->assertMatchesRegularExpression('/id="ci_date"[^>]*value="2026-02-02"/', $html);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $b->id, 'business_name' => 'BUSINESS B', 'location' => 'Address B', 'ci_date' => '2026-02-02',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame($b->id, $check->income_source_id);
        $this->assertSame('BUSINESS B', $check->business_name);
        $this->assertSame('Address B', $check->location);

        foreach ([$a, $c] as $other) {
            $other->refresh();
            $this->assertNull($other->businessCheck, 'Business A / C never receive Business B\'s check.');
        }
    }

    // ---------------------------------------------------------------------
    // 4. Applicant / Co-Maker isolation
    // ---------------------------------------------------------------------

    public function test_business_dropdown_never_crosses_between_applicant_and_co_makers(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $this->savedBusiness($folder, 'APPLICANT STORE', 'Applicant Address', '2026-01-01');
        $this->savedBusiness($folder, 'CO MAKER A STORE', 'A Address', '2026-01-02', $coMakerA->id);
        $this->savedBusiness($folder, 'CO MAKER B STORE', 'B Address', '2026-01-03', $coMakerB->id);

        $applicantHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('APPLICANT STORE', $applicantHtml);
        $this->assertStringNotContainsString('CO MAKER A STORE', $applicantHtml);
        $this->assertStringNotContainsString('CO MAKER B STORE', $applicantHtml);

        $aHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder).'?person=co-maker&co_maker_id='.$coMakerA->id)->assertOk()->getContent();
        $this->assertStringContainsString('CO MAKER A STORE', $aHtml);
        $this->assertStringNotContainsString('APPLICANT STORE', $aHtml);
        $this->assertStringNotContainsString('CO MAKER B STORE', $aHtml);

        $bHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder).'?person=co-maker&co_maker_id='.$coMakerB->id)->assertOk()->getContent();
        $this->assertStringContainsString('CO MAKER B STORE', $bHtml);
        $this->assertStringNotContainsString('CO MAKER A STORE', $bHtml);
        $this->assertStringNotContainsString('APPLICANT STORE', $bHtml);
    }

    public function test_a_co_maker_check_cannot_reference_another_persons_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $applicantBusiness = $this->savedBusiness($folder, 'APPLICANT STORE', 'Applicant Address', '2026-01-01');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'income_source_id' => $applicantBusiness->id,
            'business_name' => 'APPLICANT STORE', 'location' => 'Applicant Address', 'ci_date' => '2026-01-01',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasErrors('income_source_id');

        $this->assertSame(0, BusinessCheck::query()->count());
    }

    // ---------------------------------------------------------------------
    // 5 & 6. Business Check never feeds the Business Report
    // ---------------------------------------------------------------------

    public function test_a_manual_check_puts_no_business_into_the_business_income_sources_module(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->manualCheck($ci, $folder, 'MANUAL STORE', 'Manual Address', '2026-02-10');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('MANUAL STORE', $html, 'A manual Business Check never makes a business appear.');
        $this->assertStringNotContainsString('Report Pending', $html);
        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    public function test_a_business_report_created_after_a_manual_check_is_never_prefilled_from_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->manualCheck($ci, $folder, 'MANUAL STORE', 'Manual Address', '2026-02-10');

        // The normal Business / Income Sources workflow: pick a template, then encode. The manual
        // Business Check above must have contributed nothing to it.
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => 'BRAND NEW BUSINESS',
            'business_name' => 'BRAND NEW BUSINESS',
        ]);

        $encoding = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();

        $this->assertStringNotContainsString('MANUAL STORE', $encoding, 'Business Check business name never prefills a Business Report.');
        $this->assertStringNotContainsString('Manual Address', $encoding, 'Business Check address never prefills a Business Report.');
        $this->assertStringNotContainsString('2026-02-10', $encoding, 'Business Check CI Date never prefills a Business Report.');
        $this->assertNull($source->businessReport?->main_business_address);
        $this->assertNull($source->businessReport?->start_date);
    }

    public function test_a_historical_check_first_shell_no_longer_prefills_its_unfinalized_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        // The exact shape the retired Check-first flow left behind: a revision-1 IncomeSource with
        // an empty Business Report shell plus its own Business Check. It stays readable, but the
        // Check's values must no longer bleed into the Report page.
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id, 'template_type' => $template->template_type,
            'template_version' => $template->version, 'source_name' => 'LEGACY SHELL', 'business_name' => 'LEGACY SHELL',
        ]);
        $source->businessReport()->create(['business_name' => 'LEGACY SHELL', 'report_category' => 'Leasing']);
        BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => $source->id, 'ci_user_id' => $ci->id,
            'business_name' => 'CHECK ONLY NAME', 'location' => 'CHECK ONLY ADDRESS', 'ci_date' => '2026-05-05',
        ]);

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();

        $this->assertStringNotContainsString('CHECK ONLY ADDRESS', $html);
        $this->assertStringNotContainsString('2026-05-05', $html);
        // The Business Report row itself is untouched in the database.
        $this->assertNull($source->businessReport->fresh()->main_business_address);
        $this->assertNull($source->businessReport->fresh()->start_date);
    }

    // ---------------------------------------------------------------------
    // 7. Existing linked Business Check keeps working
    // ---------------------------------------------------------------------

    public function test_a_historical_linked_check_still_loads_with_its_own_snapshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');
        $check = BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => $business->id, 'ci_user_id' => $ci->id,
            'business_name' => 'ALPHA TRADING', 'location' => 'Alpha Address', 'ci_date' => '2026-01-15',
        ]);

        // A later Business Report rename must not rewrite what the saved check shows.
        $business->businessReport->update(['business_name' => 'ALPHA RENAMED', 'main_business_address' => 'New Address']);

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*value="ALPHA TRADING"/', $html);
        $this->assertMatchesRegularExpression('/<input id="business-check-location"[^>]*value="Alpha Address"/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$business->id.'"[^>]*selected/', $html);
    }

    public function test_a_check_whose_business_was_deleted_still_loads_and_relinks_to_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');
        $check = BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => $business->id, 'ci_user_id' => $ci->id,
            'business_name' => 'ALPHA TRADING', 'location' => 'Alpha Address', 'ci_date' => '2026-01-15',
        ]);

        // Only the Business Report is deleted; the Check and its income source reference survive.
        $business->businessReport->delete();

        $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('ALPHA TRADING');

        $check->refresh();
        $this->assertSame($business->id, $check->income_source_id, 'The saved reference is preserved, never silently relinked.');
        $this->assertSame('ALPHA TRADING', $check->business_name);
    }

    // ---------------------------------------------------------------------
    // 8. Add Business removal
    // ---------------------------------------------------------------------

    public function test_the_quick_create_endpoint_and_its_client_script_are_gone(): void
    {
        $this->assertFalse(app('router')->has('client-folders.income-sources.quick-create'), 'The Business Check quick-create route is removed.');
        $this->assertFalse(method_exists(IncomeSourceController::class, 'quickCreate'));
        $this->assertFileDoesNotExist(app_path('Http/Requests/ClientFolders/QuickCreateIncomeSourceRequest.php'));

        $script = file_get_contents(resource_path('js/app.js'));
        foreach (['data-quick-add-business-confirm', 'data-quick-add-business-dialog', 'data-business-check-add-new', 'quick-create'] as $hook) {
            $this->assertStringNotContainsString($hook, $script, 'No dead quick-add handler remains in app.js.');
        }
    }

    public function test_business_check_page_offers_no_add_business_control_even_with_businesses_present(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('Add Business</button>', $html);
        $this->assertStringNotContainsString('data-business-check-add-new', $html);
        $this->assertStringNotContainsString('business-check-quick-add-dialog', $html);
    }

    // ---------------------------------------------------------------------
    // 10. Surrounding Business Check behavior that must not regress
    // ---------------------------------------------------------------------

    public function test_business_check_ci_participants_remain_independent_from_business_report(): void
    {
        $ci = User::factory()->create();
        $mark = User::factory()->create();
        $folder = $this->folderFor($ci);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $business->id, 'business_name' => 'ALPHA TRADING', 'location' => 'Alpha Address', 'ci_date' => '2026-01-15',
            'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id],
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame([$ci->id, $mark->id], app(CiParticipantService::class)->orderedParticipantIds($check));
        $this->assertSame(0, $business->fresh()->contributors()->count(), 'Business Check companions never leak into the Business Report.');
    }

    public function test_business_check_media_behavior_remains_intact_for_a_manual_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'MANUAL STORE', 'location' => 'Manual Address', 'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $folder->businessChecks()->sole()->photos()->count());
    }

    public function test_basic_information_renders_as_an_open_by_default_accordion(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="business-basic-info-title">/', $content);
        $this->assertStringContainsString('>1</span>Basic Information<', $content);
    }

    public function test_business_check_still_renders_its_photo_and_map_sections_without_a_google_map_section(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('Google Map / Location', $content);
        $this->assertStringNotContainsString('name="google_maps_link"', $content);
        $this->assertMatchesRegularExpression('/>2<\/span>\s*Business Photos/', $content);
        $this->assertStringContainsString('>3</span>Map Screenshot<', $content);
        $this->assertMatchesRegularExpression('/>4<\/span>\s*Competitors/', $content);
        $this->assertStringContainsString('data-map-screenshot-input', $content);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);

        return $folder;
    }

    /** A genuinely, explicitly saved Business Report (revision > 1) — what the dropdown lists. */
    private function savedBusiness(ClientFolder $folder, string $name, string $address, string $ciDate, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
            'state' => RecordState::Complete,
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $address,
            'start_date' => $ciDate,
            'report_category' => 'Leasing',
            'year_established' => 2020,
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }

    /** @return array<string, string|null> */
    private function reportSnapshot(IncomeSource $business): array
    {
        $report = $business->businessReport()->first();

        return [
            'business_name' => $report->business_name,
            'main_business_address' => $report->main_business_address,
            'start_date' => $report->start_date?->toDateString(),
        ];
    }

    private function manualCheck(User $ci, ClientFolder $folder, string $name, string $location, string $ciDate): BusinessCheck
    {
        return BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'income_source_id' => null,
            'ci_user_id' => $ci->id,
            'business_name' => $name,
            'location' => $location,
            'ci_date' => $ciDate,
        ]);
    }
}
