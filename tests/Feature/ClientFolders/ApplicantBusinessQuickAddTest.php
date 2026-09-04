<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
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
 * Applicant-only: the Business Check must work even when the Applicant has no Business Report
 * yet. "+ Add Business" quick-creates the exact same shared IncomeSource/BusinessReport shell
 * CreateIncomeSource already produces for the normal Business Report flow — never a duplicate
 * identity, never a Business-Check-only text field, never a fake completed report.
 */
class ApplicantBusinessQuickAddTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_applicant_with_no_business_can_quick_add_one(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $response = $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [
            'business_name' => 'Micabalo Sari-Sari Store',
            'income_source_template_id' => $template->id,
            'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertOk();

        $response->assertJsonStructure(['id', 'name']);
        $this->assertSame('Micabalo Sari-Sari Store', $response->json('name'));
    }

    public function test_quick_add_creates_a_shared_income_source_identity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $source = IncomeSource::findOrFail($id);
        $this->assertSame($folder->id, $source->client_folder_id);
        $this->assertNull($source->co_maker_id);
        $this->assertSame($template->id, $source->income_source_template_id);
        $this->assertSame($ci->id, $source->created_by);
        $this->assertNotNull($source->businessReport, 'The shared BusinessReport row must exist so it can be completed later.');
    }

    public function test_quick_add_does_not_create_a_fake_completed_business_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');
        $source = IncomeSource::findOrFail($id);

        $this->assertSame(RecordState::Draft, $source->state);
        // Location is required on quick-add (it becomes main_business_address), but nothing else
        // from the full Business Report profile is faked in on its behalf.
        $this->assertNotNull($source->businessReport->main_business_address);
        $this->assertNull($source->businessReport->year_established);
    }

    public function test_business_check_can_save_against_the_quick_added_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame($id, $check->income_source_id);
        $this->assertSame($ci->id, $check->ci_user_id);
    }

    public function test_later_business_report_creation_reuses_the_exact_same_income_source(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        // Business Check happens first, against the quick-added business.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ]);

        // Later, the user opens Business / Income Sources and completes the report for that
        // exact same record — no new IncomeSource is ever created for this.
        $this->assertSame(1, IncomeSource::where('client_folder_id', $folder->id)->count());
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $id]), $this->businessPayload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, IncomeSource::where('client_folder_id', $folder->id)->count(), 'Completing the report must not create a second IncomeSource.');
        $source = IncomeSource::findOrFail($id);
        $this->assertSame(RecordState::Complete, $source->state);
        $this->assertSame($this->businessPayload()['main_business_address'], $source->businessReport->main_business_address);

        // The Business Check created earlier must still point at this exact same record.
        $this->assertSame($id, $folder->businessChecks()->firstOrFail()->income_source_id);
    }

    public function test_existing_applicant_business_with_completed_report_remains_selectable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->completedBusinessSource($folder, 'Reyes Hardware');

        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Reyes Hardware')
            ->assertSee('data-report-complete="1"', false);
    }

    public function test_a_quick_added_business_can_still_complete_the_current_business_check(): void
    {
        // The quick-add dialog's own client-side script (app.js) appends and selects the new
        // business's <option> directly in the already-open form via a plain DOM insert — it never
        // depends on the server-rendered $businesses list, so the resulting "Add & Continue" flow
        // (quick-create the business, then immediately save the current Business Check against it)
        // must keep working exactly as it does today, before this business ever becomes an
        // Existing Business dropdown candidate on its own.
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Draft Business');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($id, $folder->businessChecks()->firstOrFail()->income_source_id);
    }

    public function test_quick_added_business_does_not_appear_as_an_existing_business_on_a_fresh_reload(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $this->quickAdd($ci, $folder, $template, 'Draft Business');

        // A fresh GET is a brand-new request — the client-side DOM injection from the quick-add
        // dialog belongs to the browser session that made it, not to this new server render. The
        // Business Report was never explicitly saved (revision stays 1), so it must not be listed.
        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Draft Business');
    }

    public function test_multiple_explicitly_saved_applicant_businesses_remain_isolated_and_ordered(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->completedBusinessSource($folder, 'First Business');
        $this->completedBusinessSource($folder, 'Second Business');

        $page = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk();
        $page->assertSeeInOrder(['First Business', 'Second Business']);
    }

    public function test_co_maker_business_check_behavior_is_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);

        // Co-Maker with zero businesses still gets the original blocking empty state, not the
        // Applicant quick-add UI.
        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()
            ->assertSee('No saved businesses yet')
            ->assertDontSee('+ Add Business', false)
            ->assertDontSee('data-quick-add-business-dialog', false);

        // The quick-create endpoint itself refuses a Co-Maker context this phase.
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [
            'business_name' => 'Co-Maker Business', 'income_source_template_id' => $template->id, 'co_maker_id' => $coMaker->id,
        ])->assertStatus(422)->assertJsonValidationErrors('co_maker_id');
    }

    public function test_business_check_ci_participants_remain_independent_from_business_report(): void
    {
        $ci = User::factory()->create();
        $mark = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion',
            'contributor_ids_present' => '1', 'contributor_ids' => [$mark->id],
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->businessChecks()->firstOrFail();
        $source = IncomeSource::findOrFail($id);

        $this->assertSame([$ci->id, $mark->id], app(CiParticipantService::class)->orderedParticipantIds($check));
        $this->assertSame([$ci->id], app(CiParticipantService::class)->orderedParticipantIds($source->fresh()));
        $this->assertSame(0, $source->contributors()->count(), 'Business Check companions must never leak into the Business Report participant list.');
    }

    public function test_business_report_ci_participants_remain_independent_from_business_check(): void
    {
        $creator = User::factory()->create();
        $yong = User::factory()->create();
        $folder = $this->folderFor($creator);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($creator, $folder, $template, 'Micabalo Sari-Sari Store');
        $source = IncomeSource::findOrFail($id);
        app(CiParticipantService::class)->syncCompanions($source, [$yong->id]);

        $this->actingAs($creator)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->businessChecks()->firstOrFail();

        $this->assertSame([$creator->id, $yong->id], app(CiParticipantService::class)->orderedParticipantIds($source->fresh()));
        $this->assertSame(0, $check->contributors()->count(), 'Business Report companions must never leak into the Business Check participant list.');
    }

    public function test_existing_business_check_media_behavior_remains_intact(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');
        $photo = UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion', 'business_photos' => [$photo],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame(1, $check->photos()->count());
    }

    public function test_validation_requires_a_selected_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'ci_date' => now()->toDateString(), 'location' => 'Poblacion',
        ])->assertSessionHasErrors('income_source_id');

        $this->assertSame(0, $folder->businessChecks()->count());
    }

    public function test_quick_add_requires_business_name_and_template(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'income_source_template_id', 'location']);
    }

    public function test_ui_renders_exactly_one_add_business_control_for_applicant(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'data-modal-open="business-check-quick-add-dialog"'));
        $this->assertSame(1, substr_count($content, 'id="business-check-quick-add-dialog"'));
    }

    public function test_basic_information_renders_as_an_open_by_default_accordion(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        // Same accordion element as the other sections (a plain <section> can't collapse/reopen at
        // all), open unconditionally on load — unlike Business Photos/Competitors/Map Screenshot,
        // which default to collapsed and only auto-open for their own validation error.
        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="business-basic-info-title">/', $content);
        $this->assertStringContainsString('>1</span>Basic Information<', $content);
    }

    public function test_business_check_no_longer_renders_a_separate_google_map_section(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('Google Map / Location', $content);
        $this->assertStringNotContainsString('business-map-title', $content);
        // Sections renumbered down by one after removing Google Map / Location, and reordered so
        // Map Screenshot comes before Competitors (Competitors is the last accordion section).
        $this->assertMatchesRegularExpression('/>2<\/span>\s*Business Photos/', $content);
        $this->assertStringContainsString('>3</span>Map Screenshot<', $content);
        $this->assertMatchesRegularExpression('/>4<\/span>\s*Competitors/', $content);
    }

    public function test_map_screenshot_section_still_renders_without_the_google_maps_link_field(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-map-screenshot-field', $content);
        $this->assertStringContainsString('data-map-screenshot-input', $content);
        // The standalone "Google Maps Link" input/label/helper was removed outright — Location
        // (Basic Information) is the only address input now, and OfficialReportDataBuilder falls
        // back to it for the official report's "map" field.
        $this->assertStringNotContainsString('name="google_maps_link"', $content);
        $this->assertStringNotContainsString('Google Maps Link', $content);
    }

    public function test_quick_add_location_becomes_the_shared_business_address(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $response = $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [
            'business_name' => 'Micabalo Sari-Sari Store',
            'income_source_template_id' => $template->id,
            'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertOk();

        $source = IncomeSource::findOrFail($response->json('id'));
        $this->assertSame('Poblacion, San Miguel, Bulacan', $source->businessReport->main_business_address);
        $this->assertSame('Poblacion, San Miguel, Bulacan', $response->json('location'));
    }

    public function test_business_check_first_business_report_later_shows_the_same_address(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $response = $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [
            'business_name' => 'Micabalo Sari-Sari Store',
            'income_source_template_id' => $template->id,
            'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertOk();
        $id = $response->json('id');

        // Opening Business / Income Sources for that same record shows the address already
        // populated — no separate, conflicting value to re-enter. (The quick-added business itself
        // is not expected to reappear as a fresh Existing Business dropdown candidate at this point —
        // see test_quick_added_business_does_not_appear_as_an_existing_business_on_a_fresh_reload —
        // this test is only about the Business Report side carrying the same address forward.)
        $reportPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $id]))->assertOk()->getContent();
        $this->assertStringContainsString('Poblacion, San Miguel, Bulacan', $reportPage);
    }

    public function test_business_report_first_business_check_shows_the_same_address(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->completedBusinessSource($folder, 'Reyes Hardware', 'Rizal Street, Bulacan');

        $checkPage = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-location="Rizal Street, Bulacan"', $checkPage);
    }

    public function test_editing_the_address_from_business_report_does_not_change_an_already_saved_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(), 'location' => 'Old Address, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        // The address changes from the Business Report side only, well after the Business Check
        // above was already saved with the old one — prefill is not synchronization (see
        // BusinessReportBusinessCheckIndependenceTest).
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $id]), array_merge($this->businessPayload(), [
            'source_name' => 'Micabalo Sari-Sari Store', 'business_name' => 'Micabalo Sari-Sari Store',
            'main_business_address' => 'New Address, Bulacan',
        ]))->assertSessionHasNoErrors();

        // Reopening the existing Business Check must keep showing its own saved address, never
        // the newer Business Report value.
        $editPage = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/id="business-check-location"[^>]*value="Old Address, Bulacan"/', $editPage);
        $this->assertStringNotContainsString('value="New Address, Bulacan"', $editPage);
        $this->assertSame('Old Address, Bulacan', $check->fresh()->location);
    }

    public function test_quick_add_requires_location(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [
            'business_name' => 'Micabalo Sari-Sari Store', 'income_source_template_id' => $template->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location');
    }

    public function test_business_check_requires_location(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => now()->toDateString(),
        ])->assertSessionHasErrors('location');

        $this->assertSame(0, $folder->businessChecks()->count());
    }

    public function test_business_check_requires_ci_date(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertSessionHasErrors('ci_date');

        $this->assertSame(0, $folder->businessChecks()->count());
    }

    public function test_business_check_first_leaves_the_still_unfinalized_business_report_blank_in_the_database(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => '2026-01-15', 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        // Saving the Check never writes into the Business Report's own columns — the report stays
        // whatever it was (still blank/unfinalized here) until explicitly saved through its own form.
        $source = IncomeSource::findOrFail($id);
        $this->assertNull($source->businessReport->start_date);

        // The still-unfinalized Business Report form prefills from the surviving Check instead
        // (see BusinessReportBusinessCheckIndependenceTest).
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $id]))
            ->assertOk()
            ->assertSee('value="2026-01-15"', false);
    }

    public function test_business_report_first_business_check_shows_the_same_ci_date(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->completedBusinessSource($folder, 'Reyes Hardware', 'Rizal Street, Bulacan');
        $source->businessReport->update(['start_date' => '2026-02-20']);

        $checkPage = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-ci-date="2026-02-20"', $checkPage);
    }

    public function test_changing_ci_date_from_business_check_never_writes_into_the_business_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => '2026-01-15', 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        // Editing the same Business Check with a different CI Date.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id, 'income_source_id' => $id, 'ci_date' => '2026-03-10', 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertSessionHasNoErrors();

        $this->assertNull(IncomeSource::findOrFail($id)->businessReport->start_date);
        $this->assertSame('2026-03-10', $check->fresh()->ci_date->toDateString());
    }

    public function test_changing_start_date_from_business_report_does_not_change_an_already_saved_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $id = $this->quickAdd($ci, $folder, $template, 'Micabalo Sari-Sari Store');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $id, 'ci_date' => '2026-01-15', 'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $id]), array_merge($this->businessPayload(), [
            'source_name' => 'Micabalo Sari-Sari Store', 'business_name' => 'Micabalo Sari-Sari Store',
            'start_date' => '2026-04-05',
        ]))->assertSessionHasNoErrors();

        $editPage = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="ci_date"[^>]*value="2026-01-15"/', $editPage);
        $this->assertStringNotContainsString('value="2026-04-05"', $editPage);
        $this->assertSame('2026-01-15', $check->fresh()->ci_date->toDateString());
    }

    private function quickAdd(User $ci, ClientFolder $folder, IncomeSourceTemplate $template, string $name, string $location = 'Poblacion, San Miguel, Bulacan'): int
    {
        return $this->actingAs($ci)->postJson(route('client-folders.income-sources.quick-create', $folder), [
            'business_name' => $name, 'income_source_template_id' => $template->id, 'location' => $location,
        ])->assertOk()->json('id');
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function completedBusinessSource(ClientFolder $folder, string $name, string $address = 'Some Address'): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name, 'state' => RecordState::Complete]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'Leasing', 'year_established' => 2020]);
        // Represents a genuinely, explicitly saved Business Report (revision > 1) — the Business
        // Check "Select Business" dropdown is Saved-Report-based (see BusinessCheckController::form()),
        // so a revision-1 shell would not appear as a candidate regardless of how complete its data
        // looks.
        $source->forceFill(['revision' => 2])->save();

        return $source;
    }

    private function businessPayload(): array
    {
        return ['intent' => 'complete', 'source_name' => 'Micabalo Sari-Sari Store', 'business_name' => 'Micabalo Sari-Sari Store', 'report_category' => 'Leasing', 'main_business_address' => 'Main Street', 'start_date' => '2026-01-01', 'year_established' => 2020, 'registered_owner' => 'Ronilo Micabalo', 'is_primary' => true, 'properties' => [['property_type' => 'Apartment', 'is_declared' => true, 'is_inspected' => true, 'location' => 'Main Street', 'units_available' => '4', 'units_with_tenants' => '3']], 'tenants' => []];
    }
}
