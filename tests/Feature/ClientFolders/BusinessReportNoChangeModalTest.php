<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Submitting "Update Business Report" without changing anything must leave the encoding modal open.
 *
 * The modal closes on one signal only: the [data-business-saved-notify] element the encoding layout
 * renders after a save, which the iframe posts to the parent as 'brbi:business-saved' and whose
 * handler ends in dialog.close(). A no-change submit never persisted anything, so it must not emit
 * that element — the same lifecycle Update Business Check already follows, where a no-change result
 * shows an info message and the form stays put.
 *
 * These tests pin the element that actually controls closing, not merely the message text.
 */
class BusinessReportNoChangeModalTest extends TestCase
{
    use RefreshDatabase;

    private const NO_CHANGE = 'Nothing changed. No updates were saved to the database.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------------
    // TEST A — no change keeps the modal open
    // ---------------------------------------------------------------------

    public function test_a_no_change_update_never_emits_the_signal_that_closes_the_modal(): void
    {
        [$ci, $folder, $business] = $this->savedBusiness();
        $before = [
            'revision' => $business->revision,
            'updated_at' => $business->businessReport->updated_at,
            'reports' => BusinessReport::query()->count(),
        ];

        $response = $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $business]),
            $this->payload($business),
        );

        // Stays on the same encoding form for the same exact business — never the manage page.
        $response->assertRedirect(route('client-folders.income-sources.edit', [$folder, $business]));
        $response->assertSessionHas('status', self::NO_CHANGE);
        $response->assertSessionHas('statusType', 'info');

        // The refresh payload is what the notify element carries to the parent; a no-op has none.
        $response->assertSessionMissing('business_manage_refresh');

        // And the landing page renders no saved-notify element, so nothing posts
        // 'brbi:business-saved' and the dialog is never told to close.
        $page = $this->actingAs($ci)->get($response->headers->get('Location'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-business-saved-notify', $page, 'A no-op must not announce itself as a save.');
        $this->assertStringContainsString(self::NO_CHANGE, $page, 'The CI still sees why nothing happened, on the form itself.');

        // Nothing was written.
        $business->refresh();
        $this->assertSame($before['revision'], $business->revision);
        $this->assertTrue($before['updated_at']->equalTo($business->businessReport->updated_at));
        $this->assertSame($before['reports'], BusinessReport::query()->count());
    }

    public function test_the_close_signal_is_the_notify_element_the_no_change_page_omits(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $layout = file_get_contents(resource_path('views/layouts/business-encoding.blade.php'));

        // The iframe only posts the saved message when that element is present...
        $this->assertStringContainsString("const businessSavedNotify = document.querySelector('[data-business-saved-notify]');", $script);
        $this->assertStringContainsString("type: 'brbi:business-saved',", $script);
        // ...and the parent's handler for that message is what closes the dialog.
        $handler = substr($script, strpos($script, "event.data?.type !== 'brbi:business-saved'"));
        $this->assertStringContainsString('dialog.close();', substr($handler, 0, strpos($handler, 'businessSavedNotify')));

        // The layout gates that element on the save having actually persisted.
        $this->assertStringContainsString("session('statusType', 'success') !== 'info'", $layout);
    }

    // ---------------------------------------------------------------------
    // TEST B — a real change still saves and still closes
    // ---------------------------------------------------------------------

    public function test_a_real_change_still_persists_and_still_announces_the_save(): void
    {
        [$ci, $folder, $business] = $this->savedBusiness();
        $beforeRevision = $business->revision;

        $response = $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $business]),
            $this->payload($business, ['main_business_address' => 'Genuinely New Address']),
        );

        $response->assertRedirect(route('client-folders.income-sources.edit', [$folder, $business]));
        $response->assertSessionHas('statusType', 'success');
        $response->assertSessionHas('business_manage_refresh');

        $page = $this->actingAs($ci)->get($response->headers->get('Location'))->assertOk()->getContent();
        $this->assertStringContainsString('data-business-saved-notify', $page, 'A real save still announces itself, so the dialog still closes.');

        $business->refresh();
        $this->assertSame('Genuinely New Address', $business->businessReport->main_business_address);
        $this->assertGreaterThan($beforeRevision, $business->revision);
    }

    public function test_stale_write_protection_is_untouched_by_the_no_change_path(): void
    {
        [$ci, $folder, $business] = $this->savedBusiness();

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $business]),
            $this->payload($business, ['expected_revision' => $business->revision - 1]),
        )->assertSessionHasErrors('expected_revision');

        $this->assertSame(2, $business->fresh()->revision, 'A rejected stale write changes nothing.');
    }

    // ---------------------------------------------------------------------
    // TEST C — the Business Check lifecycle this mirrors
    // ---------------------------------------------------------------------

    public function test_business_check_still_keeps_its_own_form_open_on_a_no_change_save(): void
    {
        // Business Check's own no-change contract, left exactly as it is: a distinct 'no_change'
        // result that the client shows as an info message and then returns from, without closing.
        $controller = file_get_contents(app_path('Http/Controllers/BusinessCheckController.php'));
        $this->assertStringContainsString("'result' => 'no_change'", $controller);

        $script = file_get_contents(resource_path('js/app.js'));
        $noChangeAt = strpos($script, "payload?.result === 'no_change'");
        $this->assertNotFalse($noChangeAt);
        $branch = substr($script, $noChangeAt, 160);
        $this->assertStringContainsString("showToast(payload.message, 'info');", $branch);
        $this->assertStringContainsString('return;', $branch);
        $this->assertStringNotContainsString('close()', $branch, 'Business Check does not close on a no-change save.');
    }

    // ---------------------------------------------------------------------
    // Isolation
    // ---------------------------------------------------------------------

    public function test_a_no_change_save_stays_on_the_exact_person_and_business(): void
    {
        [$ci, $folder, $applicantBusiness] = $this->savedBusiness();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerBusiness = $this->business($ci, $folder, 'CO MAKER STORE', $coMaker);

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $coMakerBusiness]),
            $this->payload($coMakerBusiness, ['co_maker_id' => $coMaker->id]),
        )->assertRedirect(route('client-folders.income-sources.edit', [
            $folder, $coMakerBusiness, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]));

        $this->assertNull($applicantBusiness->fresh()->co_maker_id);
        $this->assertSame($coMaker->id, $coMakerBusiness->fresh()->co_maker_id);
        $this->assertSame(2, $applicantBusiness->fresh()->revision, "The Applicant's business is untouched.");
    }

    // ---------------------------------------------------------------------
    // TESTS D & E — Barangay / Neighbor header icon
    // ---------------------------------------------------------------------

    public function test_the_barangay_and_neighbor_headers_carry_the_verification_icon(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE] as $code) {
            $definition = ActivityDefinition::query()->where('code', $code)->sole();
            $activity = CiActivity::create([
                'client_folder_id' => $folder->id,
                'activity_definition_id' => $definition->id,
                'name' => $definition->name,
                'creator_id' => $ci->id,
            ]);

            $html = $this->actingAs($ci)
                ->get(route('client-folders.activities.default-check.show', [$folder, $activity]))
                ->assertOk()->getContent();

            // Header title: icon component output, then the untouched title wording.
            $this->assertMatchesRegularExpression(
                '/<h2 id="default-check-title"[^>]*><svg[^>]*class="[^"]*size-5[^"]*"[^>]*>.*?<\/svg>\s*<span[^>]*>'.preg_quote($definition->name, '/').'<\/span><\/h2>/s',
                $html,
                $code.' header icon',
            );

            // The action buttons keep the icons they already had — nothing duplicated, nothing lost.
            $this->assertMatchesRegularExpression('/data-default-check-cancel><svg[^>]*>.*?<\/svg>\s*Cancel<\/button>/s', $html, $code.' cancel');
            $this->assertMatchesRegularExpression('/data-default-check-submit><svg[^>]*>.*?<\/svg>\s*Save Changes<\/button>/s', $html, $code.' save');
            $this->assertSame(1, substr_count($html, 'id="default-check-title"'));
        }
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{User, ClientFolder, IncomeSource} */
    private function savedBusiness(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        return [$ci, $folder, $this->business($ci, $folder, 'ALPHA TRADING')];
    }

    /**
     * A legitimate saved Business Report, created through the real encoding workflow — so the stored
     * rows (including the template's own required child rows) are exactly what the form produces,
     * and re-submitting the same payload is a genuine no-change rather than an invalid request.
     */
    private function business(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $this->actingAs($ci)->post(
            route('client-folders.income-sources.store', $folder),
            $this->formFields($name) + [
                'income_source_template_id' => $template->id,
                'co_maker_id' => $coMaker?->id,
            ],
        )->assertSessionHasNoErrors();

        return $folder->incomeSources()->where('source_name', $name)->sole()->fresh();
    }

    /** The editable Business Report values the encoding form submits. */
    private function formFields(string $name): array
    {
        return [
            'intent' => 'complete',
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Leasing',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'year_established' => 2020,
            'registered_owner' => 'Registered Owner',
            'is_primary' => true,
            'properties' => [['property_type' => 'Apartment', 'is_declared' => true, 'is_inspected' => true, 'location' => 'Main Street', 'units_available' => '4', 'units_with_tenants' => '3']],
            'tenants' => [],
        ];
    }

    /** Exactly what the encoding form re-submits when the CI changes nothing. */
    private function payload(IncomeSource $business, array $overrides = []): array
    {
        $property = $business->businessReport->properties()->first();

        return array_merge($this->formFields($business->source_name), [
            'expected_revision' => $business->revision,
            'co_maker_id' => $business->co_maker_id,
            // The same saved row, carried back by id — a repeater row without its id would read as
            // a delete plus an insert rather than "unchanged".
            'properties' => [[
                'id' => $property?->id,
                'property_type' => $property?->property_type,
                'is_declared' => $property?->is_declared,
                'is_inspected' => $property?->is_inspected,
                'location' => $property?->location,
                'units_available' => $property?->units_available,
                'units_with_tenants' => $property?->units_with_tenants,
            ]],
        ], $overrides);
    }
}
