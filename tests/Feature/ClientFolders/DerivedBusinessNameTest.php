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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Six Business Report templates deliberately have no Business Name input — the template itself IS
 * the business identity. Those six carry a derived business_name, stored by the authoritative
 * Business / Income Source save so every consumer (the Saved Businesses list, the Business Check
 * dropdown and its one-way prefill, Reports, previews/exports) reads one real value rather than
 * each screen inventing its own fallback.
 *
 * Everything else is untouched: a template that owns a real Business Name input keeps exactly what
 * the CI typed, the derived name is never an identity key (Other Business stays keyed on its exact
 * normalized category set), and Business Check still never writes anything back into a Report.
 */
class DerivedBusinessNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // TESTS A–F — the six derived names, stored authoritatively
    // ---------------------------------------------------------------------

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function derivedTemplates(): array
    {
        return [
            'agricultural real estate' => ['leasing_agricultural', 'LEASING OPERATIONS: AGRICULTURAL REAL ESTATE'],
            'poultry farm' => ['leasing_poultry_farm', 'LEASING OF POULTRY FARM OPERATIONS'],
            'corn' => ['farming_corn', 'FARMING: CORN PRODUCTION'],
            'sugarcane' => ['farming_sugarcane', 'FARMING: SUGARCANE PRODUCTION'],
            'remittance' => ['remittance_income', 'Remittance'],
            'other business' => ['other_business_source_of_income', 'OTHER BUSINESS/SOURCE OF INCOME'],
        ];
    }

    #[DataProvider('derivedTemplates')]
    public function test_saving_a_no_name_input_template_stores_its_derived_business_name(string $templateType, string $expected): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template($templateType);

        // Other Business additionally requires its category selection — that is its own identity,
        // and it stays exactly as it was.
        $payload = $templateType === 'other_business_source_of_income'
            ? $this->otherPayload($template, ['Sari-Sari'])
            : $this->payload($template, $templateType);

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $payload)
            ->assertSessionHasNoErrors();

        $source = $folder->incomeSources()->sole();

        // Stored on BOTH authoritative rows, not derived per screen.
        $this->assertSame($expected, $source->business_name);
        $this->assertSame($expected, $source->businessReport->business_name);
        // And the shared read paths agree with what was stored.
        $this->assertSame($expected, $source->resolvedBusinessName());
        $this->assertSame($expected, $source->displayName());
    }

    public function test_the_remittance_default_is_the_short_label_not_the_long_template_name(): void
    {
        $template = $this->template('remittance_income');

        $this->assertStringContainsString('Remittance Received from OFW', $template->name);
        $this->assertSame('Remittance', IncomeSourceTemplate::defaultBusinessNameFor('remittance_income'));
        $this->assertNotSame($template->name, IncomeSourceTemplate::defaultBusinessNameFor('remittance_income'));
    }

    public function test_the_other_business_category_set_duplicate_rule_is_unchanged_by_the_derived_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('other_business_source_of_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, ['Sari-Sari', 'Tricycle']))
            ->assertSessionHasNoErrors();

        // Same set in a different order is still the same business — blocked, as before.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, ['Tricycle', 'Sari-Sari']))
            ->assertSessionHasErrors('duplicate_business_categories');

        // A different / extended set is still allowed, even though every one of these rows now
        // shares the exact same derived business_name — the name is never the identity key.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, ['Sari-Sari', 'Farming']))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, ['Sari-Sari', 'Tricycle', 'Farming']))
            ->assertSessionHasNoErrors();

        $sources = $folder->incomeSources()->get();
        $this->assertCount(3, $sources);
        $this->assertSame(['OTHER BUSINESS/SOURCE OF INCOME'], $sources->pluck('business_name')->unique()->values()->all());
        $this->assertCount(3, $sources->pluck('id')->unique(), 'Three distinct businesses, never collapsed by name.');
    }

    // ---------------------------------------------------------------------
    // TEST G — a normal template keeps the CI's own name
    // ---------------------------------------------------------------------

    public function test_a_normal_template_keeps_the_business_name_the_ci_entered(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('retail_grocery_water_refilling');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'Reymark Store'))
            ->assertSessionHasNoErrors();

        $source = $folder->incomeSources()->sole();
        $this->assertSame('Reymark Store', $source->business_name);
        $this->assertSame('Reymark Store', $source->businessReport->business_name);
        $this->assertSame('Reymark Store', $source->resolvedBusinessName());
        $this->assertNotSame($template->name, $source->business_name, 'The template name never replaces a real entered name.');

        // Renaming through the normal edit route still wins.
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->payload($template, 'Reymark Store Final') + [
            'expected_revision' => $source->fresh()->revision,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Reymark Store Final', $source->fresh()->business_name);
    }

    // ---------------------------------------------------------------------
    // TEST H — Business Check prefill for the derived templates
    // ---------------------------------------------------------------------

    public function test_business_check_prefills_and_locks_the_derived_business_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        foreach ([['farming_corn', 'FARMING: CORN PRODUCTION'], ['remittance_income', 'Remittance']] as [$templateType, $expected]) {
            $business = $this->savedBusiness($folder, $templateType, 'Claveria, Misamis Oriental', '2026-01-15');

            $html = $this->actingAs($ci)
                ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $business->id]))
                ->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*value="'.preg_quote($expected, '/').'"/', $html);
            $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*readonly/', $html);

            $business->forceDelete();
        }
    }

    public function test_a_historical_blank_named_source_still_resolves_its_derived_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        // A row saved before the derived default existed: blank on the source AND on its report.
        $business = $this->savedBusiness($folder, 'farming_corn', 'Claveria, Misamis Oriental', '2026-01-15');
        $business->forceFill(['business_name' => null])->save();
        $business->businessReport->forceFill(['business_name' => ''])->save();

        $this->assertSame('FARMING: CORN PRODUCTION', $business->fresh()->resolvedBusinessName());

        $html = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $business->id]))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*value="FARMING: CORN PRODUCTION"/', $html);

        // Saving the check snapshots the same derived value — and still writes nothing back.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $business->id, 'business_name' => 'IGNORED', 'location' => 'Claveria, Misamis Oriental', 'ci_date' => '2026-01-15',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $this->assertSame('FARMING: CORN PRODUCTION', $folder->businessChecks()->sole()->business_name);
        $this->assertNull($business->fresh()->business_name, 'The historical source row is left exactly as it was.');
    }

    // ---------------------------------------------------------------------
    // TESTS I, J, K — per-field read-only availability
    // ---------------------------------------------------------------------

    public function test_an_available_address_is_prefilled_and_locked(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $business = $this->savedBusiness($folder, 'farming_corn', 'Claveria, Misamis Oriental', '2026-01-15');

        $html = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $business->id]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="business-check-location"[^>]*value="Claveria, Misamis Oriental"/', $html);
        $this->assertMatchesRegularExpression('/<input id="business-check-location"[^>]*readonly/', $html);
    }

    public function test_a_missing_address_stays_editable_and_is_saved_to_the_check_alone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $business = $this->savedBusiness($folder, 'farming_corn', null, '2026-01-15');

        $html = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $business->id]))
            ->assertOk()->getContent();

        // Referencing a business is not on its own enough to lock Location.
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-location"[^>]*readonly/', $html);
        // The other two still lock — the rule is per field, not all-or-nothing.
        $this->assertMatchesRegularExpression('/<input id="business-check-business-name"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<input id="ci_date"[^>]*readonly/', $html);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $business->id,
            'business_name' => 'FARMING: CORN PRODUCTION',
            'location' => 'CI Entered Address',
            'ci_date' => '2026-01-15',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame('CI Entered Address', $check->location, 'The address the CI typed is kept on the Business Check.');
        $this->assertSame($business->id, $check->income_source_id);

        $business->refresh();
        $this->assertNull($business->businessReport->main_business_address, 'The Business Report address is never backfilled.');
        $this->assertSame(2, $business->revision);
    }

    public function test_ci_date_is_prefilled_and_locked_for_an_existing_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $business = $this->savedBusiness($folder, 'farming_corn', 'Claveria, Misamis Oriental', '2026-01-15');

        $html = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $business->id]))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="ci_date"[^>]*value="2026-01-15"/', $html);
        $this->assertMatchesRegularExpression('/<input id="ci_date"[^>]*readonly/', $html);
    }

    // ---------------------------------------------------------------------
    // TEST L — manual Business Check is unchanged
    // ---------------------------------------------------------------------

    public function test_a_manual_business_check_keeps_all_three_fields_editable_and_creates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('No existing business found. Enter the business details below.', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-business-name"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="business-check-location"[^>]*readonly/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="ci_date"[^>]*readonly/', $html);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'MANUAL STORE', 'location' => 'Manual Address', 'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertNull($check->income_source_id);
        $this->assertSame('MANUAL STORE', $check->business_name);
        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    // ---------------------------------------------------------------------
    // TEST M — isolation
    // ---------------------------------------------------------------------

    public function test_derived_name_businesses_stay_isolated_per_person_and_per_exact_source(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        // Same template — and therefore the same derived business_name — for three different people.
        $applicant = $this->savedBusiness($folder, 'farming_corn', 'Applicant Farm', '2026-01-01');
        $aSource = $this->savedBusiness($folder, 'farming_corn', 'A Farm', '2026-01-02', $coMakerA->id);
        $this->savedBusiness($folder, 'farming_corn', 'B Farm', '2026-01-03', $coMakerB->id);

        $this->assertSame('FARMING: CORN PRODUCTION', $applicant->resolvedBusinessName());
        $this->assertSame('FARMING: CORN PRODUCTION', $aSource->resolvedBusinessName());

        // Each person's dropdown carries only their own exact income_source_id, despite the shared name.
        $applicantIds = $this->dropdownIds($ci, $folder);
        $this->assertSame([$applicant->id], $applicantIds);

        $aIds = $this->dropdownIds($ci, $folder, $coMakerA);
        $this->assertSame([$aSource->id], $aIds);
        $this->assertNotContains($applicant->id, $aIds);

        $bIds = $this->dropdownIds($ci, $folder, $coMakerB);
        $this->assertNotContains($applicant->id, $bIds);
        $this->assertNotContains($aSource->id, $bIds);

        // A check on Co-Maker A's business links that exact id, not the same-named Applicant one.
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMakerA->id, 'income_source_id' => $aSource->id,
            'business_name' => 'FARMING: CORN PRODUCTION', 'location' => 'A Farm', 'ci_date' => '2026-01-02',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertSame($aSource->id, $check->income_source_id);
        $this->assertSame($coMakerA->id, $check->co_maker_id);
        $this->assertNull($applicant->fresh()->businessCheck, 'The same-named Applicant business is untouched.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function template(string $templateType): IncomeSourceTemplate
    {
        return IncomeSourceTemplate::query()->where('template_type', $templateType)->firstOrFail();
    }

    /** @return list<int> */
    private function dropdownIds(User $ci, ClientFolder $folder, ?CoMaker $coMaker = null): array
    {
        $person = $coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : [];

        return collect($this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder] + $person))
            ->assertOk()
            ->viewData('businesses'))
            ->pluck('id')
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(IncomeSourceTemplate $template, string $name): array
    {
        return [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Test Category',
            'main_business_address' => 'Test Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
            'intent' => 'stay',
        ];
    }

    /** @param  list<string>  $incomeSources */
    private function otherPayload(IncomeSourceTemplate $template, array $incomeSources): array
    {
        return $this->payload($template, 'Other Business') + [
            'template_data' => ['fields' => ['income_sources' => $incomeSources]],
            'report_remarks' => 'Other business details.',
        ];
    }

    /** A genuinely saved business (revision > 1) on one exact person, with an optional address. */
    private function savedBusiness(ClientFolder $folder, string $templateType, ?string $address, string $ciDate, ?int $coMakerId = null): IncomeSource
    {
        $template = $this->template($templateType);
        $derived = IncomeSourceTemplate::defaultBusinessNameFor($templateType);
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $template->name,
            'business_name' => $derived,
        ]);
        $source->businessReport()->create([
            'business_name' => $derived,
            'main_business_address' => $address,
            'start_date' => $ciDate,
            'report_category' => $template->business_category ?: $template->name,
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }
}
