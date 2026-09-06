<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Switching the Business Check's existing-business selection must re-derive Business Name, Location
 * and CI Date from the NEWLY selected business alone. No value may survive from the business
 * selected a moment before — the bug this covers was an address from Business A staying put when
 * the CI switched to a Business B that has none.
 *
 * Two halves are asserted here, because correctness cannot rest on JavaScript alone:
 *   - what the server renders for one exact income_source_id (the same per-field rule the client
 *     applies on switch, and the only thing that survives a reload), and
 *   - what the save actually stores, which always re-derives from the exact referenced business and
 *     never writes anything back into the Business Report.
 */
class BusinessCheckBusinessSwitchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // TEST A — address available -> missing
    // ---------------------------------------------------------------------

    public function test_switching_to_a_business_without_an_address_leaves_no_trace_of_the_previous_one(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'retail_grocery_water_refilling', 'A Store', 'Claveria', '2026-09-01');
        $b = $this->business($folder, 'leasing_non_agricultural', 'B Store', null, '2026-09-02');

        // Business A first: everything of A's is present and locked.
        $aHtml = $this->form($ci, $folder, $a);
        $this->assertFieldValue($aHtml, 'business-check-business-name', 'A Store');
        $this->assertFieldValue($aHtml, 'business-check-location', 'Claveria');
        $this->assertFieldValue($aHtml, 'ci_date', '2026-09-01');
        $this->assertReadOnly($aHtml, 'business-check-location');

        // Switch to Business B, which has no address of its own.
        $bHtml = $this->form($ci, $folder, $b);
        $this->assertFieldValue($bHtml, 'business-check-business-name', 'B Store');
        $this->assertFieldValue($bHtml, 'ci_date', '2026-09-02');
        $this->assertReadOnly($bHtml, 'business-check-business-name');
        $this->assertReadOnly($bHtml, 'ci_date');

        // The address field is empty and editable — never Business A's Claveria.
        $this->assertFieldValue($bHtml, 'business-check-location', '');
        $this->assertNotReadOnly($bHtml, 'business-check-location');
        $this->assertFieldValueIsNot($bHtml, 'business-check-location', 'Claveria', "Business A's address never reaches Business B.");
    }

    // ---------------------------------------------------------------------
    // TEST B — address missing -> available
    // ---------------------------------------------------------------------

    public function test_switching_to_a_business_with_an_address_fills_and_locks_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'retail_grocery_water_refilling', 'A Store', null, '2026-09-01');
        $b = $this->business($folder, 'leasing_non_agricultural', 'B Store', 'Opol', '2026-09-02');

        $aHtml = $this->form($ci, $folder, $a);
        $this->assertFieldValue($aHtml, 'business-check-location', '');
        $this->assertNotReadOnly($aHtml, 'business-check-location');

        $bHtml = $this->form($ci, $folder, $b);
        $this->assertFieldValue($bHtml, 'business-check-location', 'Opol');
        $this->assertReadOnly($bHtml, 'business-check-location');
    }

    // ---------------------------------------------------------------------
    // TEST C — business name switches
    // ---------------------------------------------------------------------

    public function test_switching_replaces_the_business_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'retail_grocery_water_refilling', 'Alpha Store', 'A Address', '2026-09-01');
        $b = $this->business($folder, 'leasing_non_agricultural', 'Beta Store', 'B Address', '2026-09-02');

        $this->assertFieldValue($this->form($ci, $folder, $a), 'business-check-business-name', 'Alpha Store');

        $bHtml = $this->form($ci, $folder, $b);
        $this->assertFieldValue($bHtml, 'business-check-business-name', 'Beta Store');
        $this->assertFieldValueIsNot($bHtml, 'business-check-business-name', 'Alpha Store', 'The previous business name never lingers.');
    }

    // ---------------------------------------------------------------------
    // TEST D — a business that genuinely resolves to no name
    // ---------------------------------------------------------------------

    public function test_switching_to_a_nameless_business_clears_the_name_and_unlocks_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $named = $this->business($folder, 'retail_grocery_water_refilling', 'Alpha Store', 'A Address', '2026-09-01');
        // A historical, non-mapped source with nothing to resolve a name from. Nothing is fabricated
        // for it — the CI supplies a Business Check-only name instead.
        $nameless = $this->business($folder, 'leasing_non_agricultural', '', 'B Address', '2026-09-02');
        $nameless->forceFill(['business_name' => null, 'source_name' => ''])->save();
        $nameless->businessReport->forceFill(['business_name' => ''])->save();
        $this->assertSame('', $nameless->fresh()->resolvedBusinessName());

        $this->assertFieldValue($this->form($ci, $folder, $named), 'business-check-business-name', 'Alpha Store');

        $html = $this->form($ci, $folder, $nameless);
        $this->assertFieldValue($html, 'business-check-business-name', '');
        $this->assertNotReadOnly($html, 'business-check-business-name');
        $this->assertFieldValueIsNot($html, 'business-check-business-name', 'Alpha Store');
    }

    public function test_the_six_mapped_defaults_are_untouched_by_the_switching_rules(): void
    {
        $this->assertSame([
            'leasing_agricultural' => 'LEASING OPERATIONS: AGRICULTURAL REAL ESTATE',
            'leasing_poultry_farm' => 'LEASING OF POULTRY FARM OPERATIONS',
            'farming_corn' => 'FARMING: CORN PRODUCTION',
            'farming_sugarcane' => 'FARMING: SUGARCANE PRODUCTION',
            'remittance_income' => 'Remittance',
            'other_business_source_of_income' => 'OTHER BUSINESS/SOURCE OF INCOME',
        ], IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES);
    }

    // ---------------------------------------------------------------------
    // TEST E — CI Date switches
    // ---------------------------------------------------------------------

    public function test_switching_replaces_the_ci_date_and_keeps_it_locked(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'retail_grocery_water_refilling', 'A Store', 'A Address', '2026-09-01');
        $b = $this->business($folder, 'leasing_non_agricultural', 'B Store', 'B Address', '2026-09-05');

        $this->assertFieldValue($this->form($ci, $folder, $a), 'ci_date', '2026-09-01');

        $bHtml = $this->form($ci, $folder, $b);
        $this->assertFieldValue($bHtml, 'ci_date', '2026-09-05');
        $this->assertReadOnly($bHtml, 'ci_date');
        $this->assertFieldValueIsNot($bHtml, 'ci_date', '2026-09-01', 'The previous CI Date never lingers.');
    }

    // ---------------------------------------------------------------------
    // TEST F — back to manual mode
    // ---------------------------------------------------------------------

    public function test_manual_mode_starts_empty_and_unlocked_and_creates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->business($folder, 'retail_grocery_water_refilling', 'A Store', 'Claveria', '2026-09-01');

        // The unselected form — what clearing the dropdown returns to — carries no business's values.
        $html = $this->form($ci, $folder, null);
        foreach (['business-check-business-name', 'business-check-location', 'ci_date'] as $field) {
            $this->assertFieldValue($html, $field, '');
            $this->assertNotReadOnly($html, $field);
        }
        $this->assertStringNotContainsString('value="Claveria"', $html);
        $this->assertStringNotContainsString('value="2026-09-01"', $html);

        // Saving with no business selected stays a manual Business Check.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'MANUAL STORE', 'location' => 'Manual Address', 'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertNull($check->income_source_id);
        $this->assertSame('MANUAL STORE', $check->business_name);
        $this->assertSame('Manual Address', $check->location);
        $this->assertSame(1, IncomeSource::query()->count(), 'Only the business created by this test exists.');
        $this->assertSame(1, BusinessReport::query()->count());
    }

    /**
     * TESTS A–D and F, client side: a selection change RESETS all three fields and then takes only
     * what the newly selected business provides. That single unconditional reset is what makes a
     * value the CI typed under Business A — not just one auto-prefilled from it — impossible to
     * carry into Business B, and what empties the form on the way back to manual mode.
     */
    public function test_the_selection_handler_resets_every_field_before_loading_the_new_business(): void
    {
        $handler = $this->selectionHandler();

        // Reset-then-populate in one expression: no branch can leave the previous value in place.
        $this->assertStringContainsString("field.value = available ? value : '';", $handler);
        $this->assertStringContainsString('field.readOnly = available;', $handler);

        // Both retired shapes must stay gone: the original "only ever overwrite, never clear", and
        // the provenance scheme that let a CI-typed value outlive the business it was typed under.
        $this->assertStringNotContainsString('if (available) field.value = value;', $handler);
        $this->assertStringNotContainsString('autofilled', $handler);
        $this->assertStringNotContainsString('autofilled', file_get_contents(resource_path('js/app.js')));
        $this->assertStringNotContainsString('autofilled', file_get_contents(resource_path('views/client-folders/business-checks/form.blade.php')));
    }

    /**
     * TEST G, client side: the reset is bound to an actual change of income_source_id. While the CI
     * stays on one business, what they type into an unlocked field is left completely alone.
     */
    public function test_the_selection_handler_only_resets_when_the_selected_business_changes(): void
    {
        $handler = $this->selectionHandler();

        $this->assertStringContainsString('if (select.dataset.appliedIncomeSourceId === selectedId) return;', $handler);
        $this->assertStringContainsString('select.dataset.appliedIncomeSourceId = selectedId;', $handler);

        // Nothing rewrites these three fields on plain typing — the retired `input` listener that
        // re-flagged them (and so let a typed value outlive its business) is gone. Unrelated
        // `input` handlers elsewhere in app.js are none of this rule's business, so the assertion
        // names that listener's own field matcher rather than the generic event registration.
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString(
            "field.matches('[data-business-check-business-name], [data-business-check-location], [data-business-check-ci-date]')",
            $script,
        );

        // The server seeds the guard with the selection it rendered.
        $this->assertStringContainsString(
            'data-applied-income-source-id="{{ $selectedIncomeSourceId }}"',
            file_get_contents(resource_path('views/client-folders/business-checks/form.blade.php')),
        );
    }

    /** The Business Check selection handler alone, from its hook down to its last applied field. */
    private function selectionHandler(): string
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $handler = substr($script, strpos($script, '[data-business-check-income-source-select]'));

        return substr($handler, 0, strpos($handler, 'data-business-check-ci-date]'));
    }

    // ---------------------------------------------------------------------
    // TEST G — a Check-only address never reaches the Business Report
    // ---------------------------------------------------------------------

    public function test_an_address_typed_for_an_addressless_business_is_saved_to_the_check_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $b = $this->business($folder, 'leasing_non_agricultural', 'B Store', null, '2026-09-02');
        $before = $b->revision;

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $b->id,
            'business_name' => 'B Store',
            'location' => 'Cagayan de Oro',
            'ci_date' => '2026-09-02',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame('Cagayan de Oro', $check->location);
        $this->assertSame($b->id, $check->income_source_id);

        $b->refresh();
        $this->assertNull($b->businessReport->main_business_address, 'The Business Report address stays blank.');
        $this->assertSame($before, $b->revision);
    }

    public function test_forged_values_for_a_business_that_has_its_own_are_ignored_on_save(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'retail_grocery_water_refilling', 'A Store', 'Claveria', '2026-09-01');
        $reportBefore = $a->businessReport->only(['business_name', 'main_business_address']);

        // The three read-only inputs are posted with another business's values; the exact referenced
        // business is authoritative and wins.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $a->id,
            'business_name' => 'FORGED STORE', 'location' => 'FORGED ADDRESS', 'ci_date' => '2026-01-05',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame('A Store', $check->business_name);
        $this->assertSame('Claveria', $check->location);
        $this->assertSame('2026-09-01', $check->ci_date->toDateString());

        $a->refresh();
        $this->assertSame($reportBefore, $a->businessReport->only(['business_name', 'main_business_address']));
    }

    // ---------------------------------------------------------------------
    // TEST H — exact business isolation
    // ---------------------------------------------------------------------

    public function test_each_option_carries_only_its_own_business_values(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'retail_grocery_water_refilling', 'A Store', 'Claveria', '2026-09-01');
        $b = $this->business($folder, 'leasing_non_agricultural', 'B Store', null, '2026-09-02');

        $html = $this->form($ci, $folder, null);

        // Business A's option carries A's data...
        $this->assertMatchesRegularExpression(
            '/<option value="'.$a->id.'"[^>]*data-business-name="A Store"[^>]*data-location="Claveria"[^>]*data-ci-date="2026-09-01"/',
            $html,
        );
        // ...and Business B's carries B's own, with an empty address rather than A's.
        $this->assertMatchesRegularExpression(
            '/<option value="'.$b->id.'"[^>]*data-business-name="B Store"[^>]*data-location=""[^>]*data-ci-date="2026-09-02"/',
            $html,
        );

        // Saving against B links B alone; A is untouched and keeps no check of its own.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $b->id, 'business_name' => 'B Store', 'location' => 'Typed Address', 'ci_date' => '2026-09-02',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame($b->id, $check->income_source_id);
        $this->assertSame('B Store', $check->business_name);
        $this->assertNull($a->fresh()->businessCheck, "Business A never receives Business B's check.");
    }

    // ---------------------------------------------------------------------
    // TEST I — person isolation
    // ---------------------------------------------------------------------

    public function test_switching_never_crosses_person_scope(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $applicant = $this->business($folder, 'retail_grocery_water_refilling', 'APPLICANT STORE', 'Applicant Address', '2026-09-01');
        $aSource = $this->business($folder, 'retail_grocery_water_refilling', 'CO MAKER A STORE', 'A Address', '2026-09-02', $coMakerA->id);

        $applicantHtml = $this->form($ci, $folder, null);
        $this->assertStringContainsString('APPLICANT STORE', $applicantHtml);
        $this->assertStringNotContainsString('CO MAKER A STORE', $applicantHtml);
        $this->assertStringNotContainsString('A Address', $applicantHtml);

        $aHtml = $this->form($ci, $folder, $aSource, $coMakerA);
        $this->assertFieldValue($aHtml, 'business-check-business-name', 'CO MAKER A STORE');
        $this->assertFieldValue($aHtml, 'business-check-location', 'A Address');
        $this->assertStringNotContainsString('APPLICANT STORE', $aHtml);
        $this->assertStringNotContainsString('Applicant Address', $aHtml);

        // Co-Maker B owns nothing, so neither of the other two people's businesses is selectable.
        $bHtml = $this->form($ci, $folder, null, $coMakerB);
        $this->assertStringContainsString('No existing business found. Enter the business details below.', $bHtml);
        $this->assertStringNotContainsString('APPLICANT STORE', $bHtml);
        $this->assertStringNotContainsString('CO MAKER A STORE', $bHtml);

        // Another person's business id is refused outright rather than silently prefilled.
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', [
            $folder, 'income_source_id' => $applicant->id, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id,
        ]))->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    /** The Business Check form as rendered for one exact business (or for no selection at all). */
    private function form(User $ci, ClientFolder $folder, ?IncomeSource $business, ?CoMaker $coMaker = null): string
    {
        $params = [$folder]
            + ($business ? ['income_source_id' => $business->id] : [])
            + ($coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : []);

        return $this->actingAs($ci)->get(route('client-folders.business-checks.create', $params))->assertOk()->getContent();
    }

    private function assertFieldValue(string $html, string $id, string $expected): void
    {
        $pattern = $expected === ''
            ? '/<input id="'.preg_quote($id, '/').'"[^>]*value=""/'
            : '/<input id="'.preg_quote($id, '/').'"[^>]*value="'.preg_quote($expected, '/').'"/';

        $this->assertMatchesRegularExpression($pattern, $html, $id.' should hold '.($expected === '' ? 'no value' : $expected));
    }

    private function assertFieldValueIsNot(string $html, string $id, string $unexpected, string $because = ''): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<input id="'.preg_quote($id, '/').'"[^>]*value="'.preg_quote($unexpected, '/').'"/',
            $html,
            $because !== '' ? $because : $id.' should not hold '.$unexpected,
        );
    }

    private function assertReadOnly(string $html, string $id): void
    {
        $this->assertMatchesRegularExpression('/<input id="'.preg_quote($id, '/').'"[^>]*readonly/', $html, $id.' should be read-only.');
    }

    private function assertNotReadOnly(string $html, string $id): void
    {
        $this->assertDoesNotMatchRegularExpression('/<input id="'.preg_quote($id, '/').'"[^>]*readonly/', $html, $id.' should be editable.');
    }

    /** A genuinely saved business (revision > 1) on one exact person, with an optional address. */
    private function business(ClientFolder $folder, string $templateType, string $name, ?string $address, string $ciDate, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', $templateType)->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $address,
            'start_date' => $ciDate,
            'report_category' => $template->business_category ?: $template->name,
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }
}
