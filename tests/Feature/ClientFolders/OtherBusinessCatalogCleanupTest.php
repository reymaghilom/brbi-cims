<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * OTHER BUSINESS / SOURCE OF INCOME catalog.
 *
 * The form no longer carries its own "SELECT ALL APPLICABLE INCOME SOURCES" heading, but the
 * checkbox catalog itself is the complete default list from config/business-report-templates.php.
 * A category is deliberately allowed to exist BOTH as a dedicated Business Template and as a
 * checkbox here — having a template is never a reason to hide the checkbox, and the two are offered
 * independently of one another.
 */
class OtherBusinessCatalogCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** TEST 4 — the section heading stays removed. */
    public function test_the_select_all_applicable_income_sources_heading_is_gone(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk();

        $page->assertDontSee('SELECT ALL APPLICABLE INCOME SOURCES');
        // The catalog itself is still rendered, and the section is still named for assistive tech.
        $page->assertSee('data-other-income-source', false);
        $page->assertSee('aria-label="Other Business / Source of Income"', false);
    }

    /** TEST 1 — every default catalog key from the authoritative config is offered. */
    public function test_the_catalog_offers_every_default_option_from_the_config(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $catalog = $this->catalog($this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', $folder))
            ->assertOk()->getContent());

        $defaults = $this->defaultCatalogKeys();
        $this->assertNotEmpty($defaults);

        foreach ($defaults as $key) {
            $this->assertStringContainsString('value="'.$key.'"', $catalog, $key.' is a default option.');
        }

        // Nothing is dropped: the rendered checkbox count matches the config exactly.
        $this->assertSame(count($defaults), substr_count($catalog, 'data-income-source-choice'));
    }

    /**
     * TEST 2 — the coexistence rule itself. Each of these categories also has its own dedicated
     * Business Template, and every one of them must still be offered as a checkbox here.
     */
    public function test_a_category_with_its_own_dedicated_template_is_still_offered_as_a_checkbox(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $catalog = $this->catalog($this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', $folder))
            ->assertOk()->getContent());

        foreach ([
            'leasing_truck_equipment', 'leasing_real_estate_agri', 'leasing_real_estate_non_agri',
            'leasing_farm_operations', 'taxi_puj_operator', 'trucking_services',
            'distributorship_dealer_wholesaler', 'pharmacy_store',
            'general_merchandise_hardware_auto_parts', 'buy_sell_dry_goods',
            'grocery_convenience_supermarket', 'water_refilling_station', 'meatshop',
            'contractor', 'subcontractor', 'restaurant_cafeteria_carenderia_stall',
            'corn_production', 'sugarcane_production', 'ofw_foreigner_allotment',
        ] as $alsoATemplate) {
            $this->assertStringContainsString(
                'value="'.$alsoATemplate.'"',
                $catalog,
                $alsoATemplate.' has a dedicated template, which is not a reason to hide its checkbox.',
            );
        }
    }

    /** TEST 3 — those dedicated templates remain separately available in the normal selector. */
    public function test_dedicated_business_templates_remain_separately_available(): void
    {
        $available = IncomeSourceTemplate::query()->activeBusiness()->pluck('template_type')->all();

        foreach ([
            'leasing_truck_equipment', 'leasing_non_agricultural', 'leasing_agricultural',
            'leasing_poultry_farm', 'taxi_operator', 'puj_van_jeepney_operator', 'trucking_services',
            'distributorship_wholesaler_b2b', 'pharmacy_drugstore',
            'general_merchandise_hardware_parts', 'buy_sell_dry_goods',
            'retail_grocery_water_refilling', 'meatshop_store', 'contractor_subcontractor',
            'restaurant_food_stall', 'farming_corn', 'farming_sugarcane', 'remittance_income',
        ] as $templateType) {
            $this->assertContains($templateType, $available, $templateType.' must stay selectable.');
        }
    }

    /** TEST 5 — a selection mixing a template-backed category with a plain one still saves. */
    public function test_an_other_business_selection_including_a_template_backed_category_saves(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->store($ci, $folder, 'Custom Income Activity', ['still_lotto_outlet', 'meatshop'])
            ->assertSessionHasNoErrors();

        $report = $folder->incomeSources()->with('businessReport')->firstOrFail()->businessReport;
        $this->assertSame(['still_lotto_outlet', 'meatshop'], data_get($report->template_data, 'fields.income_sources'));
    }

    /** TEST 6 — an exact duplicate combination is still blocked, whichever order it arrives in. */
    public function test_an_exact_duplicate_combination_is_still_blocked(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->store($ci, $folder, 'First Activity', ['still_lotto_outlet', 'meatshop'])
            ->assertSessionHasNoErrors();

        // The same set, reversed — the comparison is order-insensitive.
        $this->store($ci, $folder, 'Duplicate Activity', ['meatshop', 'still_lotto_outlet'])
            ->assertSessionHasErrors();

        $this->assertSame(1, $folder->incomeSources()->count());
    }

    /** TEST 7 — an expanded or reduced combination stays distinct and still saves. */
    public function test_expanded_and_subset_combinations_remain_distinct(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->store($ci, $folder, 'A plus B', ['still_lotto_outlet', 'meatshop'])
            ->assertSessionHasNoErrors();
        $this->store($ci, $folder, 'A plus B plus C', ['still_lotto_outlet', 'meatshop', 'laundry_shop'])
            ->assertSessionHasNoErrors();
        $this->store($ci, $folder, 'A only', ['still_lotto_outlet'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $folder->incomeSources()->count());
    }

    /** TEST 8 — the same combination is legitimate for a different exact person. */
    public function test_applicant_and_co_maker_selections_stay_isolated(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);

        $this->store($ci, $folder, 'Applicant Activity', ['still_lotto_outlet'])
            ->assertSessionHasNoErrors();
        $this->store($ci, $folder, 'Co-Maker Activity', ['still_lotto_outlet'], $coMaker)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $folder->incomeSources()->whereNull('co_maker_id')->count());
        $this->assertSame(1, $folder->incomeSources()->where('co_maker_id', $coMaker->id)->count());

        // And that Co-Maker's own duplicate is still blocked within that person.
        $this->store($ci, $folder, 'Co-Maker Duplicate', ['still_lotto_outlet'], $coMaker)
            ->assertSessionHasErrors();
        $this->assertSame(1, $folder->incomeSources()->where('co_maker_id', $coMaker->id)->count());
    }

    /** Every catalog key the authoritative config defines, across all five groups. */
    private function defaultCatalogKeys(): array
    {
        return collect(config('business-report-templates.other_business_source_of_income.schema.income_source_groups'))
            ->flatten(1)
            ->pluck('key')
            ->all();
    }

    private function store(User $ci, ClientFolder $folder, string $name, array $keys, ?CoMaker $person = null): TestResponse
    {
        $route = $person === null
            ? route('client-folders.income-sources.store', $folder)
            : route('client-folders.income-sources.store', [$folder, 'person' => 'co-maker', 'co_maker_id' => $person->id]);

        return $this->actingAs($ci)->post($route, array_filter([
            'income_source_template_id' => IncomeSourceTemplate::query()
                ->where('template_type', 'other_business_source_of_income')->value('id'),
            'co_maker_id' => $person?->id,
            'source_name' => $name,
            'start_date' => '2026-01-01',
            'report_remarks' => 'Client-entered fallback details.',
            'template_data' => ['fields' => ['income_sources' => $keys]],
        ]));
    }

    /**
     * Just the Other Business checkbox catalog, so nothing elsewhere on the page is mistaken for it.
     * Bounded by the catalog wrapper and the first retained legacy hidden input that follows it —
     * the groups inside are themselves <section> elements, so a naive search for the next </section>
     * would stop at the end of the first group and silently truncate everything after it.
     */
    private function catalog(string $html): string
    {
        $start = strpos($html, 'business-other-income-catalog');
        $this->assertNotFalse($start, 'The Other Business catalog must be on the page.');

        $end = strpos($html, 'template_data[fields][business_selected]', $start);
        $this->assertNotFalse($end, 'The catalog is followed by the retained legacy inputs.');

        return substr($html, $start, $end - $start);
    }
}
