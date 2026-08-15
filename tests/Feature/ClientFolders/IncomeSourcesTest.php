<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncomeSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_assigned_ci_and_administrator_can_browse_but_another_ci_cannot(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->assertSee('Please choose Business Template');
        $this->actingAs($admin)->get(route('client-folders.income-sources.index', $folder))->assertOk()->assertSee('Please choose Business Template');
        $this->actingAs($other)->get(route('client-folders.income-sources.index', $folder))->assertForbidden();
    }

    public function test_business_entry_route_opens_the_neutral_template_chooser_instead_of_a_saved_business(): void
    {
        [$ci, $folder, $first] = $this->createSource('leasing_non_agricultural');
        [, , $primary] = $this->createSource('retail_grocery_water_refilling', $ci, $folder);
        $primary->update(['is_primary' => true]);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', $folder))
            ->assertOk()
            ->assertSee('data-business-report-form', false)
            ->assertSee('!bottom-3 !rounded-control !p-2.5', false)
            ->assertSee('<span class="sr-only">Business Report actions</span>', false)
            ->assertSee('Please choose Business Template')
            ->assertSee('<option value="">Please choose Business Template</option>', false)
            ->assertSee('No Business Report yet')
            ->assertDontSee('data-business-selector', false)
            ->assertDontSee('role="tablist"', false)
            ->assertDontSee('Add Income Source')
            ->assertDontSee('Business / Income Sources')
            ->assertDontSee('income sources available.');

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $primary]))
            ->assertOk()
            ->assertSee('data-business-encoding-layout', false)
            ->assertSee('data-business-report-form', false)
            ->assertDontSee('Business / Income Sources</h1>', false);

        $this->assertNotSame($first->id, $primary->id);
    }

    public function test_legacy_create_routes_are_bypassed_by_the_business_report_template_chooser(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.create', $folder));
        $response->assertRedirect(route('client-folders.income-sources.index', $folder));
        $this->assertCount(0, $folder->incomeSources()->get());

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', $folder))
            ->assertOk()
            ->assertSee('data-business-encoding-layout', false)
            ->assertSee('data-business-report-form', false)
            ->assertSeeInOrder(['CREDIT INVESTIGATION REPORT', 'Please choose Business Template'])
            ->assertSee('Add Business')
            ->assertDontSee('Add Income Source')
            ->assertDontSee('Select the Official Form')
            ->assertDontSee('Source Identity')
            ->assertDontSee('Create and Open Form');

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.select-template', $folder))
            ->assertRedirect(route('client-folders.income-sources.index', $folder));

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.manage', $folder))
            ->assertRedirect(route('client-folders.income-sources.index', $folder));
    }

    public function test_add_business_creates_an_independent_blank_report_without_rendering_business_tabs(): void
    {
        [$ci, $folder, $first] = $this->createSource('leasing_non_agricultural');
        $firstPayload = $this->businessPayload();
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $first]), $firstPayload)
            ->assertRedirect();
        $clientCount = ClientFolder::query()->count();

        $response = $this->actingAs($ci)
            ->post(route('client-folders.income-sources.businesses.store', [$folder, $first]));
        $second = $folder->incomeSources()->with('businessReport')->latest('id')->firstOrFail();

        $response->assertRedirect(route('client-folders.income-sources.edit', [$folder, $second]));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->income_source_template_id, $second->income_source_template_id);
        $this->assertSame('', $second->source_name);
        $this->assertSame('', $second->businessReport->business_name);
        $this->assertSame($clientCount, ClientFolder::query()->count());
        $this->assertCount(2, $folder->incomeSources()->get());

        $secondPayload = $this->businessPayload();
        $secondPayload['source_name'] = 'Second Retail Activity';
        $secondPayload['business_name'] = 'Second Business';
        $secondPayload['is_primary'] = false;
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $second]), $secondPayload)
            ->assertRedirect();

        $this->assertSame('Sample Apartments', $first->fresh()->businessReport->business_name);
        $this->assertSame('Second Business', $second->fresh()->businessReport->business_name);
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $second]))
            ->assertOk()
            ->assertSee('Add Business')
            ->assertSee('Please choose Business Template')
            ->assertSee('data-business-template-select', false)
            ->assertDontSee('data-business-selector', false)
            ->assertDontSee('business-selector-tab', false)
            ->assertDontSee('role="tablist"', false)
            ->assertDontSee('aria-current="page"', false)
            ->assertSee('data-business-report-form', false);
    }

    public function test_duplicate_blank_legacy_businesses_are_preserved_but_not_rendered_as_old_business_ui(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        foreach (range(1, 3) as $index) {
            $source = $folder->incomeSources()->create([
                'income_source_template_id' => $template->id,
                'template_type' => $template->template_type,
                'template_version' => $template->version,
                'source_name' => '',
                'revision' => 1,
                'last_edited_by' => $ci->id,
            ]);
            $source->businessReport()->create(['business_name' => '', 'report_category' => 'Leasing']);
        }

        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))
            ->assertOk()
            ->assertSee('CREDIT INVESTIGATION REPORT')
            ->assertSee('Please choose Business Template')
            ->assertSee('>Save</button>', false)
            ->assertDontSee('data-business-selector', false)
            ->assertDontSee('Business 1')
            ->assertDontSee('Revision 1')
            ->assertDontSee('Save and Return')
            ->assertDontSee('Save Draft')
            ->assertDontSee('Save and Mark Complete');

        $this->assertSame(3, $folder->incomeSources()->count());
        $this->assertSame(3, $folder->incomeSources()->whereHas('businessReport')->count());

        $valid = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Approved Business',
            'business_name' => 'Approved Business',
            'revision' => 1,
            'last_edited_by' => $ci->id,
        ]);
        $valid->businessReport()->create(['business_name' => 'Approved Business', 'report_category' => 'Leasing']);

        $savedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $valid]))
            ->assertOk()
            ->assertSee('Approved Business')
            ->assertSee('!bottom-3 !rounded-control !p-2.5', false)
            ->assertSee('form="business-report-form" name="intent" value="complete" class="ui-button-primary" data-business-save>Save</button>', false)
            ->assertDontSee('Business 1')
            ->assertDontSee('Save and Return')
            ->assertDontSee('Save Draft')
            ->assertDontSee('Save and Mark Complete')
            ->assertDontSee('Save &amp; Next Business', false)
            ->assertDontSee('Official outputs use the latest saved data.');
        $this->assertSame(1, substr_count($savedPage->getContent(), '<span class="sr-only">Business Report actions</span>'));

        $this->assertSame(4, $folder->incomeSources()->count());
    }

    public function test_another_ci_cannot_add_a_business_to_an_unassigned_folder(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_non_agricultural');
        $other = User::factory()->create();

        $this->actingAs($other)
            ->post(route('client-folders.income-sources.businesses.store', [$folder, $source]))
            ->assertForbidden();

        $this->assertCount(1, $folder->incomeSources()->get());
    }

    public function test_deleted_folder_is_unavailable_and_forged_nested_source_is_not_found(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = $otherFolder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => 'Other Source']);

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertNotFound();
        $folder->delete();
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder->id))->assertNotFound();
    }

    public function test_selector_only_shows_active_templates_and_rejects_inactive_or_forged_ids(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $inactive = IncomeSourceTemplate::factory()->create(['template_type' => 'inactive-template', 'name' => 'Hidden Template', 'is_active' => false]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.select-template', $folder))->assertRedirect(route('client-folders.income-sources.index', $folder));
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->assertDontSee('Hidden Template')->assertDontSee('SOURCES OF INCOME DECLARED BY CLIENT');
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $inactive->id, 'source_name' => 'Hidden'])->assertSessionHasErrors('income_source_template_id');
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => 999999, 'source_name' => 'Forged'])->assertSessionHasErrors('income_source_template_id');
        $fallback = IncomeSourceTemplate::where('is_fallback', true)->firstOrFail();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $fallback->id])->assertSessionHasErrors('income_source_template_id');
    }

    public function test_selector_loads_every_active_business_template_and_renders_each_compatible_inline_form(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $templates = IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->orderBy('sort_order')
            ->get();

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk();
        $this->assertCount(19, $templates);
        $this->assertSame('Other Business/Source of Income', $templates->last()->name);
        preg_match('/<select[^>]+data-business-template-select[^>]*>(.*?)<\/select>/s', $response->getContent(), $templateSelect);
        $this->assertNotEmpty($templateSelect[1]);
        $this->assertMatchesRegularExpression('/<option value="">Please choose Business Template<\/option>/', $templateSelect[1]);
        $this->assertMatchesRegularExpression('/<option value="'.$templates->last()->id.'"[^>]*>Other Business\/Source of Income<\/option>\s*$/', trim($templateSelect[1]));
        foreach ($templates as $template) {
            $response
                ->assertSee('<option value="'.$template->id.'"', false)
                ->assertDontSee('<option value="'.$template->id.'" selected', false)
                ->assertSee('data-business-template-preview="'.$template->id.'"', false)
                ->assertSee($template->name);
        }
        $response->assertSee('Leasing Operations: Agricultural Real Estate');
        $response->assertSee('Restaurant / Cafeteria / Carenderia / Food Stall');
        $response->assertSee('Farming: Sugarcane Production');
        $response->assertSee('Remittance Received from OFW / Foreigner (Allotment); Alimony; Allowance; Family Sharing of Profits');
        $response->assertDontSee('OTHER BUSINESS / INCOME SOURCE');
        $response->assertSee('Other Business/Source of Income');
        $response->assertDontSee('NO BUSINESS TEMPLATE APPLICABLE');
        $response->assertSee('Summary of Units Inspected');
        $response->assertSee('business-report-column-guide', false);
        $response->assertSee('(brand/capacity/fully paid or mortgaged)');
        $response->assertSee('(NAME/OFFICE ADDRESS/CONTACT NUMBER)');
        $response->assertSee('(AREA TRAVELLED/GOODS TRANSPORTED)');
        $response->assertSee('TOTAL UNITS DECLARED');
        $response->assertSee('TOTAL UNITS VALIDATED');
        $response->assertSee('(php per km/php per cubic/fixed monthly)');
        $response->assertSee('W/ CONTRACT? (Y/N)');
        $response->assertSee('INDUSTRY RESEARCH/SURVEY');
        $response->assertSee('NAME OF THE PERSON REMITTING THE FUNDS');
        $response->assertSee('+ Add Row');
        $response->assertSee('business-report-table', false);
        $response->assertSee('business-add-entry', false);
        $response->assertDontSee('+ Add Entry');
        $response->assertSee('business-template-field-grid', false);
        $response->assertSee('business-remittance-question-row', false);
        $response->assertSee('Type of Store (Check Applicable)');
        $response->assertSee('value="General Merchandise"', false);
        $response->assertSee('value="High End Display"', false);
        $response->assertSee('value="Mall - Restaurant"', false);
        $response->assertSee('data-business-template-switch-dialog', false);
        $response->assertSee('You have unsaved data in the current Business Report. Switching templates may cause this data to be lost. Do you want to continue?');
        $response->assertSee('<input type="hidden" name="intent" value="complete">', false);
        $response->assertSee('<span class="sr-only">Business Report actions</span>', false);
        $response->assertSee('form="business-template-form" name="intent" value="complete" class="ui-button-primary" data-business-save>Save</button>', false);
        $this->assertSame(1, substr_count($response->getContent(), '<span class="sr-only">Business Report actions</span>'));
        $response->assertDontSee('This creates one independent Business Report using the selected template.');
        $response->assertDontSee('Save and Return');
        $response->assertDontSee('Save Draft');
        $response->assertDontSee('Save and Mark Complete');
        $response->assertDontSee('Save & Next Business');
    }

    public function test_workbook_template_data_is_saved_only_on_the_selected_business_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $corn = IncomeSourceTemplate::where('template_type', 'farming_corn')->firstOrFail();
        $taxi = IncomeSourceTemplate::where('template_type', 'taxi_operator')->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $corn->id,
            'business_name' => 'North Field',
            'template_data' => [
                'fields' => ['total_ha_planted' => '12.5'],
                'tables' => ['farms' => [['location_size' => 'Barangay Norte - 12.5 HA']]],
            ],
        ])->assertRedirect();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $taxi->id,
            'business_name' => 'Town Taxi',
            'template_data' => ['fields' => ['minimum_units' => '3']],
        ])->assertRedirect();

        $reports = $folder->incomeSources()->with('businessReport')->orderBy('id')->get()->pluck('businessReport');
        $this->assertSame('12.5', data_get($reports[0]->template_data, 'fields.total_ha_planted'));
        $this->assertNull(data_get($reports[0]->template_data, 'fields.minimum_units'));
        $this->assertSame('3', data_get($reports[1]->template_data, 'fields.minimum_units'));
        $this->assertNull(data_get($reports[1]->template_data, 'tables.farms.0.location_size'));
    }

    public function test_workbook_fields_from_another_template_are_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $corn = IncomeSourceTemplate::where('template_type', 'farming_corn')->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $corn->id,
            'business_name' => 'North Field',
            'template_data' => ['fields' => ['minimum_units' => '3']],
        ])->assertSessionHasErrors('template_data.fields.minimum_units');

        $this->assertDatabaseCount('income_sources', 0);
    }

    public function test_client_can_add_multiple_businesses_with_different_business_templates(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $leasing = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $retail = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        foreach ([[$leasing, 'Apartment Rentals'], [$retail, 'Neighborhood Store']] as [$template, $name]) {
            $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $template->id, 'source_name' => $name])->assertRedirect();
        }

        $this->assertCount(2, $folder->incomeSources);
        $this->assertDatabaseCount('business_reports', 2);
        $this->assertDatabaseCount('general_income_source_reports', 0);
    }

    public function test_retired_other_business_income_source_is_hidden_but_historical_entries_remain_editable(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'other_business_income_source')->firstOrFail();

        $this->assertFalse($template->is_active);
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->assertDontSee('OTHER BUSINESS / INCOME SOURCE');
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id,
            'source_name' => 'Retired option',
        ])->assertSessionHasErrors('income_source_template_id');

        $template->update(['is_active' => true]);

        foreach (['Consulting Income', 'Online Selling'] as $name) {
            $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
                'income_source_template_id' => $template->id,
                'source_name' => $name,
            ])->assertRedirect();
        }
        $template->update(['is_active' => false]);

        $sources = $folder->incomeSources()->with('businessReport')->orderBy('id')->get();
        $this->assertCount(2, $sources);
        $this->assertNotSame($sources[0]->businessReport->id, $sources[1]->businessReport->id);

        foreach ($sources as $index => $source) {
            $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
                'intent' => 'stay',
                'source_name' => $source->source_name,
                'business_name' => $source->source_name,
                'report_category' => 'Other',
                'report_remarks' => $index === 0 ? 'Consulting details' : 'Online selling details',
            ])->assertSessionHasNoErrors();
        }

        $reports = $folder->incomeSources()->with('businessReport')->orderBy('id')->get()->pluck('businessReport');
        $this->assertSame('Consulting details', $reports[0]->report_remarks);
        $this->assertSame('Online selling details', $reports[1]->report_remarks);
        $this->assertDatabaseCount('general_income_source_reports', 0);
    }

    public function test_other_business_source_of_income_is_the_last_fallback_form_option(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $templates = IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->orderBy('sort_order')
            ->get();
        $template = $templates->last();

        $this->assertSame('other_business_source_of_income', $template->template_type);
        $this->assertSame('Other Business/Source of Income', $template->name);
        $this->assertFalse($template->businessReportSchema()['profile']);
        $this->assertSame('leasing_real_estate_agri_rank', $template->businessReportSchema()['fields'][0]['key']);

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk();
        $page->assertSee('RANK ALL INCOME SOURCES THAT CLIENT DECLARED BASED ON CONTRIBUTION (1 BEING THE HIGHEST)');
        $page->assertSee('data-other-income-source', false);
        $page->assertSee('INCOME SOURCE');
        $page->assertSee('Agriculture Production');
        $page->assertSee('Professional Services');
        $page->assertSee('Employment');
        $page->assertSee('STL/Lotto Outlet');
        $page->assertSee('Government Agency/Corporation');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id,
            'source_name' => 'Custom Income Activity',
        ])->assertRedirect();

        $source = $folder->incomeSources()->with('businessReport')->firstOrFail();
        $this->assertSame($template->id, $source->income_source_template_id);
        $this->assertSame('other_business_source_of_income', $source->template_type);
        $this->assertNotNull($source->businessReport);
        $this->assertDatabaseCount('general_income_source_reports', 0);

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Custom Income Activity',
            'business_name' => 'Other Business/Source of Income',
            'report_category' => 'Other',
            'template_data' => ['fields' => [
                'still_lotto_outlet_rank' => '1',
            ]],
            'report_remarks' => 'Client-entered fallback details.',
        ])->assertSessionHasNoErrors();

        $source->refresh()->load('businessReport');
        $this->assertSame('1', data_get($source->businessReport->template_data, 'fields.still_lotto_outlet_rank'));
        $this->assertSame('Client-entered fallback details.', $source->businessReport->report_remarks);
    }

    public function test_dedicated_template_renders_only_compatible_sections_and_saves_normalized_rows(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_non_agricultural');

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('data-business-encoding-layout', false)
            ->assertSee('data-business-report-form', false)
            ->assertSee('Business Address Status')
            ->assertSee('name="ownership_type"', false)
            ->assertSee('type="radio"', false)
            ->assertSee('MORTGAGED FROM')
            ->assertSee('RENTED FROM')
            ->assertSee('placeholder="Specify Mortgagee/Lessor"', false)
            ->assertDontSee('Specify Mortgagee/Lessor:')
            ->assertDontSee('INCOME SOURCE CONTRIBUTION')
            ->assertDontSee('Contribution Rank')
            ->assertDontSee('Estimated Monthly Contribution')
            ->assertDontSee('Primary income source')
            ->assertSee('type="hidden" name="contribution_rank"', false)
            ->assertSee('data-business-address-from disabled', false)
            ->assertSee('id="rented_from"', false)
            ->assertSee('<label class="ui-label business-address-inline-label" for="monthly_rent">PHP MONTHLY RENT:</label>', false)
            ->assertSee('id="monthly_rent" class="ui-control business-address-inline-input business-address-monthly-rent" name="monthly_rent" type="text" value="" data-business-monthly-rent disabled', false)
            ->assertSee('id="main_business_address"', false)
            ->assertSee('name="length_of_stay_months" type="text"', false)
            ->assertSee('class="business-excel-paired-row business-main-address-row"', false)
            ->assertSee('class="business-excel-paired-row business-owner-relationship-row"', false)
            ->assertSee('Leasing Business Registration Status:')
            ->assertSee('value="Registered"', false)
            ->assertSee('value="Not Registered"', false)
            ->assertSee('id="previous_business_address"', false)
            ->assertSee('id="previous_business_address_length_of_stay"', false)
            ->assertSee('name="previous_business_address_length_of_stay" type="text"', false)
            ->assertDontSee('Select status')
            ->assertSee('CREDIT INVESTIGATION REPORT')
            ->assertSee('SOURCE OF INCOME VALIDATION')
            ->assertSee('LEASING OPERATIONS: NON-AGRICULTURAL REAL ESTATE')
            ->assertSee('Properties')
            ->assertSee('Tenants')
            ->assertDontSee('data-app-shell', false)
            ->assertDontSee('<aside', false)
            ->assertDontSee('<nav', false)
            ->assertDontSee('breadcrumb', false)
            ->assertSeeInOrder([
                'Business Name:', 'Year Established:', 'Main Business Address:', 'Length of Stay:',
                'Business Address Status:', 'Previous Business Address', 'Length of Stay:', 'Reason for Transfer:',
                'Informant:', 'Registered Owner:', 'Leasing Business Registration Status:',
            ]);
        preg_match_all('/<div class="business-address-status-row">(.*?)<\/div>/s', $response->getContent(), $addressStatusRows);
        $this->assertNotEmpty($addressStatusRows[1]);
        foreach ($addressStatusRows[1] as $addressStatusRow) {
            $this->assertSame(1, substr_count($addressStatusRow, 'class="business-address-status-divider"'));
            $this->assertGreaterThan(strpos($addressStatusRow, 'RENTED FROM'), strpos($addressStatusRow, 'class="business-address-status-divider"'));
        }

        $payload = $this->businessPayload() + ['length_of_stay_months' => 18, 'ownership_type' => 'Rented', 'rented_from' => 'Maria Santos', 'monthly_rent' => 'PHP 12,500 / month', 'previous_business_address' => 'Old Market Road', 'previous_business_address_length_of_stay' => '2 years and 6 months'];
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)->assertRedirect();
        $source->refresh();
        $this->assertSame(RecordState::Complete, $source->state);
        $this->assertSame('Rented', $source->businessReport->ownership_type);
        $this->assertSame(18, $source->businessReport->length_of_stay_months);
        $this->assertSame('Maria Santos', $source->businessReport->rented_from);
        $this->assertSame('PHP 12,500 / month', $source->businessReport->monthly_rent);
        $this->assertSame('Old Market Road', $source->businessReport->previous_business_address);
        $this->assertSame('2 years and 6 months', $source->businessReport->previous_business_address_length_of_stay);
        $rentedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="18"', false)
            ->assertSee('value="2 years and 6 months"', false)
            ->assertSee('type="hidden" name="is_primary" value="1"', false);
        $this->assertMatchesRegularExpression('/<input id="rented_from"(?![^>]* disabled)[^>]*data-business-address-from[^>]*>/', $rentedPage->getContent());
        $this->assertMatchesRegularExpression('/<input id="monthly_rent"(?![^>]* disabled)[^>]*data-business-monthly-rent[^>]*>/', $rentedPage->getContent());
        $this->assertDatabaseHas('business_properties', ['business_report_id' => $source->businessReport->id, 'property_type' => 'Apartment']);
        $this->assertSame(1, $source->businessReport->properties_declared);
        $this->assertDatabaseMissing('business_products', ['business_report_id' => $source->businessReport->id]);

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Apartment Rentals',
            'business_name' => 'Sample Apartments',
            'report_category' => 'Leasing',
            'ownership_type' => 'Mortgaged',
            'rented_from' => 'Community Bank',
        ])->assertRedirect();
        $mortgagedReport = $source->refresh()->businessReport;
        $this->assertSame('Community Bank', $mortgagedReport->rented_from);
        $this->assertSame('PHP 12,500 / month', $mortgagedReport->monthly_rent);
        $mortgagedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $this->assertMatchesRegularExpression('/<input id="rented_from"(?![^>]* disabled)[^>]*data-business-address-from[^>]*>/', $mortgagedPage->getContent());
        $this->assertMatchesRegularExpression('/<input id="monthly_rent"[^>]*data-business-monthly-rent[^>]* disabled>/', $mortgagedPage->getContent());

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Apartment Rentals',
            'business_name' => 'Sample Apartments',
            'report_category' => 'Leasing',
            'ownership_type' => 'Owned',
            'rented_from' => 'Stale lessor value',
        ])->assertRedirect();
        $ownedReport = $source->refresh()->businessReport;
        $this->assertNull($ownedReport->rented_from);
        $this->assertSame('PHP 12,500 / month', $ownedReport->monthly_rent);
        $ownedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $this->assertMatchesRegularExpression('/<input id="rented_from"[^>]*data-business-address-from[^>]* disabled>/', $ownedPage->getContent());
        $this->assertMatchesRegularExpression('/<input id="monthly_rent"[^>]*data-business-monthly-rent[^>]* disabled>/', $ownedPage->getContent());

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Apartment Rentals',
            'business_name' => 'Sample Apartments',
            'report_category' => 'Leasing',
            'ownership_type' => 'Residence Only',
        ])->assertRedirect();
        $residenceOnlyPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $this->assertMatchesRegularExpression('/<input id="rented_from"[^>]*data-business-address-from[^>]* disabled>/', $residenceOnlyPage->getContent());
        $this->assertMatchesRegularExpression('/<input id="monthly_rent"[^>]*data-business-monthly-rent[^>]* disabled>/', $residenceOnlyPage->getContent());
    }

    public function test_units_table_starts_with_three_blank_rows_but_only_persists_populated_rows(): void
    {
        [$ci, $folder, $source] = $this->createSource('taxi_operator');
        $source->businessReport->update([
            'previous_business_address' => 'Legacy Taxi Garage',
            'previous_business_address_length_of_stay' => '4 years',
            'reason_for_transfer' => 'Route relocation',
            'informant' => 'Legacy Informant',
            'registered_owner' => 'Legacy Registered Owner',
            'relationship_to_borrower' => 'Sibling',
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $response
            ->assertSee('Years Operating:')
            ->assertSee('LTFRB RESEARCH (date)')
            ->assertSee('FRANCHISE/LICENSE FEE PER UNIT')
            ->assertSee('(INDICATE HOW FREQUENT RENEWAL)')
            ->assertSee('MINIMUM YEAR MODEL REQUIREMENT')
            ->assertSee('MINIMUM NUMBER OF UNITS PER OPERATOR')
            ->assertSee('name="template_data[fields][ltfrb_research_date]"', false)
            ->assertSee('name="template_data[fields][franchise_fee]"', false)
            ->assertSee('name="template_data[fields][minimum_year_model]"', false)
            ->assertSee('name="template_data[fields][minimum_units]"', false);
        preg_match('/<section class="business-report-profile".*?<\/section>/s', $response->getContent(), $taxiProfile);
        $this->assertNotEmpty($taxiProfile[0]);
        $this->assertSame(1, substr_count($taxiProfile[0], 'Length of Stay:'));
        foreach (['previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'informant', 'registered_owner', 'relationship_to_borrower'] as $hiddenField) {
            $this->assertStringNotContainsString('name="'.$hiddenField.'"', $taxiProfile[0]);
        }
        foreach (['Previous Business Address', 'Reason for Transfer:', 'Informant:', 'Registered Owner:', 'If Registered Owner Is Not Borrower, Relationship:'] as $hiddenLabel) {
            $this->assertStringNotContainsString($hiddenLabel, $taxiProfile[0]);
        }
        preg_match('/<section[^>]+data-repeater="template-units".*?<\/section>/s', $response->getContent(), $unitsSection);
        $this->assertNotEmpty($unitsSection);
        $this->assertMatchesRegularExpression('/<header[^>]+business-units-summary-heading[^>]*>.*Summary of Units Inspected.*data-repeater-add.*<\/header>/s', $unitsSection[0]);
        $this->assertStringContainsString('class="business-report-table business-operator-units-table business-taxi-units-table"', $unitsSection[0]);
        $this->assertStringContainsString('<span>BRAND/CAR MODEL</span><small class="business-report-column-guide">(ONLY THOSE INSPECTED)</small>', $unitsSection[0]);
        $this->assertStringContainsString('<span>YEAR MODEL</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>PLATE NO.</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>W/ DRIVER?</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>SINCE?</span><small class="business-report-column-guide">(MONTH/YEAR)</small>', $unitsSection[0]);
        $this->assertStringContainsString('<span>DAILY BOUNDARY</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>FRANCHISE/OPERATOR NAME &amp; CONTACT NO.</span><small class="business-report-column-guide">(AS SEEN IN TAXI UNIT)</small>', $unitsSection[0]);
        $this->assertStringContainsString('<span>AREA PARKED?</span>', $unitsSection[0]);
        $this->assertStringNotContainsString('Franchise / Operator / Contact Number', $unitsSection[0]);
        $this->assertStringNotContainsString('business-schema-table-actions', $unitsSection[0]);
        $this->assertStringContainsString('data-repeater-add', $unitsSection[0]);
        $stylesheet = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.business-operator-units-table { min-width: 72rem; table-layout: fixed; }', $stylesheet);
        foreach ([0, 1, 2] as $index) {
            $response->assertSee("template_data[tables][units][$index][brand_model]", false);
        }

        $payload = [
            'intent' => 'stay',
            'source_name' => 'Town Taxi',
            'business_name' => 'Town Taxi',
            'report_category' => 'Transportation',
            'template_data' => ['tables' => ['units' => [
                ['brand_model' => ''],
                ['brand_model' => 'Toyota Vios', 'plate_number' => 'ABC 1234'],
                ['brand_model' => ''],
            ]]],
        ];

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)->assertRedirect();

        $source->refresh();
        $this->assertSame([['brand_model' => 'Toyota Vios', 'plate_number' => 'ABC 1234']], data_get($source->businessReport->template_data, 'tables.units'));
        $this->assertSame('Legacy Taxi Garage', $source->businessReport->previous_business_address);
        $this->assertSame('4 years', $source->businessReport->previous_business_address_length_of_stay);
        $this->assertSame('Route relocation', $source->businessReport->reason_for_transfer);
        $this->assertSame('Legacy Informant', $source->businessReport->informant);
        $this->assertSame('Legacy Registered Owner', $source->businessReport->registered_owner);
        $this->assertSame('Sibling', $source->businessReport->relationship_to_borrower);
        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Toyota Vios"', false)
            ->assertSee('value="ABC 1234"', false);
    }

    public function test_puj_van_jeepney_operator_reuses_the_taxi_structure_without_mixing_report_data(): void
    {
        [$ci, $folder, $pujSource] = $this->createSource('puj_van_jeepney_operator');
        $pujSource->businessReport->update([
            'previous_business_address' => 'Legacy PUJ Terminal',
            'registered_owner' => 'Legacy PUJ Owner',
        ]);

        $page = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $pujSource]))
            ->assertOk()
            ->assertSee('Years Operating:')
            ->assertSee('LTFRB RESEARCH (date)')
            ->assertSee('FRANCHISE/LICENSE FEE PER UNIT')
            ->assertSee('(INDICATE HOW FREQUENT RENEWAL)')
            ->assertSee('MINIMUM YEAR MODEL REQUIREMENT')
            ->assertSee('MINIMUM NUMBER OF UNITS PER OPERATOR');
        preg_match('/<section class="business-report-profile".*?<\/section>/s', $page->getContent(), $pujProfile);
        $this->assertNotEmpty($pujProfile[0]);
        $this->assertSame(1, substr_count($pujProfile[0], 'Length of Stay:'));
        foreach (['previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'informant', 'registered_owner', 'relationship_to_borrower'] as $hiddenField) {
            $this->assertStringNotContainsString('name="'.$hiddenField.'"', $pujProfile[0]);
        }

        preg_match('/<section[^>]+data-repeater="template-units".*?<\/section>/s', $page->getContent(), $unitsSection);
        $this->assertNotEmpty($unitsSection[0]);
        $this->assertStringContainsString('class="business-report-table business-operator-units-table business-puj-units-table"', $unitsSection[0]);
        $this->assertStringContainsString('<span>BRAND/CAR MODEL</span><small class="business-report-column-guide">(ONLY THOSE INSPECTED)</small>', $unitsSection[0]);
        $this->assertStringContainsString('<span>W/ DRIVER?</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>SINCE?</span><small class="business-report-column-guide">(MONTH/YEAR)</small>', $unitsSection[0]);
        $this->assertStringContainsString('<span>ROUTE</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>AVERAGE FARE</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>FRANCHISE/OPERATOR NAME &amp; CONTACT NO.</span><small class="business-report-column-guide">(AS SEEN IN PUJ/VAN/JEEPNEY UNIT)</small>', $unitsSection[0]);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $unitsSection[0], $blankUnitRows);
        $this->assertNotEmpty($blankUnitRows[1]);
        $this->assertSame(3, substr_count($blankUnitRows[1], 'data-repeater-row'));

        $pujPayload = [
            'intent' => 'stay',
            'source_name' => 'PUJ Operations',
            'business_name' => 'PUJ Operations',
            'report_category' => 'Transportation',
            'template_data' => ['tables' => ['units' => [[
                'brand_model' => 'Isuzu Modern PUJ',
                'plate_number' => 'PUJ 7788',
                'route' => 'Bugo-Cogon',
                'average_fare' => 'PHP 25',
                'franchise_operator' => 'PUJ Cooperative / 09171111111',
            ]]]],
        ];
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $pujSource]), $pujPayload)
            ->assertSessionHasNoErrors();

        [, , $taxiSource] = $this->createSource('taxi_operator', $ci, $folder);
        $taxiPayload = [
            'intent' => 'stay',
            'source_name' => 'Taxi Operations',
            'business_name' => 'Taxi Operations',
            'report_category' => 'Transportation',
            'template_data' => ['tables' => ['units' => [[
                'brand_model' => 'Toyota Taxi',
                'plate_number' => 'TXI 1122',
                'area_parked' => 'City Terminal',
            ]]]],
        ];
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $taxiSource]), $taxiPayload)
            ->assertSessionHasNoErrors();

        $pujSource->refresh();
        $taxiSource->refresh();
        $this->assertSame('Isuzu Modern PUJ', data_get($pujSource->businessReport->template_data, 'tables.units.0.brand_model'));
        $this->assertSame('Bugo-Cogon', data_get($pujSource->businessReport->template_data, 'tables.units.0.route'));
        $this->assertNull(data_get($pujSource->businessReport->template_data, 'tables.units.0.area_parked'));
        $this->assertSame('Toyota Taxi', data_get($taxiSource->businessReport->template_data, 'tables.units.0.brand_model'));
        $this->assertSame('City Terminal', data_get($taxiSource->businessReport->template_data, 'tables.units.0.area_parked'));
        $this->assertNull(data_get($taxiSource->businessReport->template_data, 'tables.units.0.route'));
        $this->assertSame('Legacy PUJ Terminal', $pujSource->businessReport->previous_business_address);
        $this->assertSame('Legacy PUJ Owner', $pujSource->businessReport->registered_owner);
    }

    public function test_agricultural_leasing_starts_with_three_blank_property_rows_and_loads_saved_rows(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_agricultural');

        $blankPage = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        $this->assertMatchesRegularExpression('/data-repeater="template-properties"\s+data-empty-row-remove-without-confirmation/', $blankPage->getContent());
        preg_match('/<section[^>]+data-repeater="template-properties".*?<\/section>/s', $blankPage->getContent(), $propertySection);
        $this->assertNotEmpty($propertySection[0]);
        $this->assertStringContainsString('<span>TENANT</span><small class="business-report-column-guide">(NAME &amp; YEARS RENTING)</small>', $propertySection[0]);
        $this->assertStringContainsString('<span>W/ CONTRACT? (Y/N)</span>', $propertySection[0]);
        $this->assertStringContainsString('<span>CONTACT NO. OF TENANT</span>', $propertySection[0]);
        $this->assertStringContainsString('<span>RELEVANT INFORMATION</span><small class="business-report-column-guide">(EX. LEASE INCOME SHARED AMONG RELATIVES?)</small>', $propertySection[0]);
        $this->assertStringNotContainsString('Tenant / Years Renting', $propertySection[0]);
        $this->assertStringNotContainsString('Tenant Contact Number', $propertySection[0]);
        foreach (['total_declared', 'total_inspected', 'total_not_inspected', 'reason_not_inspected'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]+name="template_data\[fields\]\['.$field.'\]"[^>]+required/', $blankPage->getContent());
        }
        preg_match('/<section[^>]+data-repeater="template-properties".*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $blankPage->getContent(), $blankRows);
        $this->assertNotEmpty($blankRows[1]);
        $this->assertSame(3, substr_count($blankRows[1], 'data-repeater-row'));
        foreach ([0, 1, 2] as $index) {
            $blankPage->assertSee("template_data[tables][properties][$index][location_area]", false);
        }

        $basePayload = [
            'intent' => 'stay',
            'source_name' => 'Agricultural Leasing',
            'business_name' => 'Agricultural Leasing',
            'report_category' => 'Leasing',
        ];
        $summaryFields = [
            'total_declared' => '3',
            'total_inspected' => '1',
            'total_not_inspected' => '2',
            'reason_not_inspected' => 'Two remote properties were not accessible',
        ];
        $originalRevision = $source->revision;

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['tables' => ['properties' => [
                ['location_area' => 'This partial row must not save'],
                [],
                [],
            ]]],
        ])->assertSessionHasErrors([
            'template_data.fields.total_declared',
            'template_data.fields.total_inspected',
            'template_data.fields.total_not_inspected',
            'template_data.fields.reason_not_inspected',
        ]);
        $source->refresh();
        $this->assertSame($originalRevision, $source->revision);
        $this->assertNull(data_get($source->businessReport->template_data, 'tables.properties.0.location_area'));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['fields' => $summaryFields, 'tables' => ['properties' => [[], [], []]]],
        ])->assertSessionHasNoErrors();
        $source->refresh();
        $this->assertSame([], data_get($source->businessReport->template_data, 'tables.properties', []));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['fields' => $summaryFields, 'tables' => ['properties' => [
                ['location_area' => 'Barangay Farm / 4 HA', 'tenant' => 'Maria / 5 years'],
                [],
                [],
            ]]],
        ])->assertRedirect();
        $source->refresh();
        $this->assertSame(
            [['location_area' => 'Barangay Farm / 4 HA', 'tenant' => 'Maria / 5 years']],
            data_get($source->businessReport->template_data, 'tables.properties', [])
        );

        $savedPage = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Barangay Farm / 4 HA"', false);
        preg_match('/<section[^>]+data-repeater="template-properties".*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedPage->getContent(), $savedRows);
        $this->assertNotEmpty($savedRows[1]);
        $this->assertSame(1, substr_count($savedRows[1], 'data-repeater-row'));

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("repeaterRowHasData(row, repeater)", $script);
        $this->assertStringContainsString('pendingRepeaterRemoval = { row, repeater }', $script);

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['fields' => $summaryFields, 'tables' => ['properties' => []]],
        ])->assertSessionHasNoErrors();
        $source->refresh();
        $this->assertSame([], data_get($source->businessReport->template_data, 'tables.properties', []));

        $resetPage = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        preg_match('/<section[^>]+data-repeater="template-properties".*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $resetPage->getContent(), $resetRows);
        $this->assertNotEmpty($resetRows[1]);
        $this->assertSame(3, substr_count($resetRows[1], 'data-repeater-row'));
    }

    public function test_poultry_farm_leasing_requires_its_summary_and_persists_only_populated_default_rows(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_poultry_farm');

        $blankPage = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        $this->assertMatchesRegularExpression('/data-repeater="template-farms"\s+data-empty-row-remove-without-confirmation/', $blankPage->getContent());
        preg_match('/<section[^>]+data-repeater="template-farms".*?<\/section>/s', $blankPage->getContent(), $farmSection);
        $this->assertNotEmpty($farmSection[0]);
        $this->assertStringContainsString('<span>LESSOR</span><small class="business-report-column-guide">(NAME &amp; CONTACT NO. &amp; YEARS RENTING)</small>', $farmSection[0]);
        $this->assertStringContainsString('<span>W/ CONTRACT? (Y/N)</span>', $farmSection[0]);
        $this->assertStringContainsString('<span>RELEVANT INFORMATION GATHERED</span><small class="business-report-column-guide">(EX. LEASE INCOME SHARED AMONG RELATIVES?)</small>', $farmSection[0]);
        $this->assertStringNotContainsString('Lessor / Contact / Years Renting', $farmSection[0]);
        foreach (['total_declared', 'total_inspected', 'total_not_inspected', 'reason_not_inspected'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]+name="template_data\[fields\]\['.$field.'\]"[^>]+required/', $blankPage->getContent());
        }
        preg_match('/<section[^>]+data-repeater="template-farms".*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $blankPage->getContent(), $blankRows);
        $this->assertNotEmpty($blankRows[1]);
        $this->assertSame(3, substr_count($blankRows[1], 'data-repeater-row'));
        $this->assertStringContainsString('data-repeater-add', $farmSection[0]);
        foreach ([0, 1, 2] as $index) {
            $blankPage->assertSee("template_data[tables][farms][$index][location_area]", false);
        }

        $basePayload = [
            'intent' => 'stay',
            'source_name' => 'Poultry Farm Leasing',
            'business_name' => 'Poultry Farm Leasing',
            'report_category' => 'Leasing',
        ];
        $originalRevision = $source->revision;
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['tables' => ['farms' => [
                ['location_area' => 'This partial row must not save'],
                [],
                [],
            ]]],
        ])->assertSessionHasErrors([
            'template_data.fields.total_declared',
            'template_data.fields.total_inspected',
            'template_data.fields.total_not_inspected',
            'template_data.fields.reason_not_inspected',
        ]);
        $source->refresh();
        $this->assertSame($originalRevision, $source->revision);
        $this->assertNull(data_get($source->businessReport->template_data, 'tables.farms.0.location_area'));

        $summaryFields = [
            'total_declared' => '3',
            'total_inspected' => '1',
            'total_not_inspected' => '2',
            'reason_not_inspected' => 'Two farms were inaccessible',
        ];
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['fields' => $summaryFields, 'tables' => ['farms' => [[], [], []]]],
        ])->assertSessionHasNoErrors();
        $source->refresh();
        $this->assertSame([], data_get($source->businessReport->template_data, 'tables.farms', []));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $basePayload + [
            'template_data' => ['fields' => $summaryFields, 'tables' => ['farms' => [
                ['location_area' => 'North Farm / 6 HA', 'lessor' => 'Ana / 09170000000 / 4 years'],
                [],
                [],
            ]]],
        ])->assertSessionHasNoErrors();
        $source->refresh();
        $this->assertSame(
            [['location_area' => 'North Farm / 6 HA', 'lessor' => 'Ana / 09170000000 / 4 years']],
            data_get($source->businessReport->template_data, 'tables.farms', [])
        );

        $savedPage = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="North Farm / 6 HA"', false)
            ->assertSee('value="Ana / 09170000000 / 4 years"', false);
        preg_match('/<section[^>]+data-repeater="template-farms".*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedPage->getContent(), $savedRows);
        $this->assertNotEmpty($savedRows[1]);
        $this->assertSame(1, substr_count($savedRows[1], 'data-repeater-row'));
    }

    public function test_non_agricultural_leasing_uses_the_combined_excel_property_and_tenant_table(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_non_agricultural');
        $blankPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $blankPage->assertSee('data-repeater="properties" data-empty-row-remove-without-confirmation', false);
        preg_match('/<table class="business-report-table business-property-inspection-table">.*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $blankPage->getContent(), $blankPropertyRows);
        $this->assertNotEmpty($blankPropertyRows[1]);
        $this->assertSame(3, substr_count($blankPropertyRows[1], 'data-repeater-row'));
        foreach ([0, 1, 2] as $index) {
            $blankPage->assertSee("properties[$index][property_type]", false);
        }
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("repeater.matches('[data-empty-row-remove-without-confirmation]')", $script);
        $this->assertStringContainsString("repeaterRowHasData(row, repeater)", $script);

        $blankPayload = $this->businessPayload();
        $blankPayload['intent'] = 'stay';
        $blankPayload['properties'] = [[], [], []];
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $blankPayload)
            ->assertRedirect();
        $this->assertDatabaseCount('business_properties', 0);

        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->businessPayload())
            ->assertRedirect();

        $property = $source->refresh()->businessReport->properties()->firstOrFail();
        $property->update(['property_type' => 'Commercial Space', 'has_contract' => null]);
        $tenant = $property->tenants()->create(['tenant_name' => 'New Leaf', 'monthly_rent' => 14000, 'years_renting' => 3]);
        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('business-property-summary', false)
            ->assertSee('business-property-inspection-table', false)
            ->assertSee('<span class="ui-label">TOTAL PROPERTIES DECLARED:</span>', false)
            ->assertSee('<span class="ui-label">TOTAL PROPERTIES INSPECTED:</span>', false)
            ->assertSee('<span class="ui-label">TOTAL PROP NOT INSPECTED:</span>', false)
            ->assertSee('<span class="ui-label">REASON NOT INSPECTED:</span>', false)
            ->assertSee('class="business-property-summary-reason"', false)
            ->assertSeeInOrder([
                'TOTAL PROPERTIES DECLARED:',
                'TOTAL PROPERTIES INSPECTED:',
                'TOTAL PROP NOT INSPECTED:',
                'REASON NOT INSPECTED:',
                'Summary of Properties Inspected',
                'TYPE OF REAL ESTATE',
                '(PER PROPERTY DECLARED)',
                'INSPECTED?',
                'TOTAL UNITS AVAILABLE',
                'UNITS W/ TENANTS',
                'LOCATION &amp; TOTAL SQM OF BUILDING',
                'TENANT INFORMATION',
                '(ENUMERATE NAME &amp; MONTHLY RENT &amp; YEARS RENTING)',
                'W/ CONTRACT?',
                'Action',
            ], false)
            ->assertSee('name="properties_declared" type="number" min="0" value="1"', false)
            ->assertSee('name="properties_inspected" type="number" min="0" value="1"', false)
            ->assertSee('name="properties_not_inspected" type="number" min="0" value="0"', false)
            ->assertSee('name="properties_reason_not_inspected" type="text" value="" data-property-summary-reason', false)
            ->assertSee('type="radio" value="Commercial Space" checked', false)
            ->assertSee('WAREHOUSE')
            ->assertSee("COMM'L")
            ->assertSee("RES'L")
            ->assertSee('value="New Leaf / PHP 14000.00 / 3.00 years"', false)
            ->assertDontSee('placeholder="Reason if not inspected"', false)
            ->assertDontSee('placeholder="Location"', false)
            ->assertDontSee('placeholder="Total SQM"', false)
            ->assertDontSee('data-property-tenant-add', false)
            ->assertSee('<td class="business-report-action-cell"><button type="button" class="business-remove-entry" data-repeater-remove title="Remove entry" aria-label="Remove entry">', false)
            ->assertSee('<label for="report_remarks" class="sr-only">Other Remarks</label>', false)
            ->assertDontSee('<label for="report_remarks" class="ui-label">Other Remarks', false)
            ->assertDontSee('id="tenants-section"', false);
        preg_match('/<table class="business-report-table business-property-inspection-table">.*?<tbody data-repeater-rows>\s*(<tr.*?<\/tr>)/s', $page->getContent(), $propertyRow);
        $this->assertNotEmpty($propertyRow[1]);
        $this->assertSame(8, substr_count($propertyRow[1], '<td'));
        $this->assertSame(3, substr_count($propertyRow[1], 'class="business-report-choice-option"'));
        $this->assertSame(6, substr_count($propertyRow[1], 'class="ui-control'));
        $this->assertStringNotContainsString('placeholder=', $propertyRow[1]);
        $this->assertStringContainsString('name="properties[0][reason_not_inspected]" type="hidden"', $propertyRow[1]);
        $this->assertStringNotContainsString('name="properties[0][reason_not_inspected]" type="text"', $propertyRow[1]);
        $stylesheet = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('minmax(22rem, 2.35fr)', $stylesheet);
        $this->assertStringContainsString('.business-property-summary-reason { grid-column: 1 / -1; }', $stylesheet);
        $payload = $this->businessPayload();
        $payload['intent'] = 'stay';
        $payload['properties_declared'] = 5;
        $payload['properties_inspected'] = 2;
        $payload['properties_not_inspected'] = 3;
        $payload['properties_reason_not_inspected'] = 'Client unavailable';
        $payload['report_remarks'] = 'Electric and water billing is shouldered by the tenant.';
        $payload['properties'] = [[
            'id' => $property->id,
            'property_type' => 'Commercial Space',
            'is_declared' => true,
            'is_inspected' => false,
            'reason_not_inspected' => 'Client unavailable',
            'units_available' => 4,
            'units_with_tenants' => 3,
            'location' => 'Zone 6, Bugo / 500 SQM',
            'area_square_meters' => 500,
            'has_contract' => true,
            'remarks' => 'New Leaf / PHP 14,000 monthly / 3 years',
        ]];
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('business_properties', ['id' => $property->id, 'property_type' => 'Commercial Space', 'is_inspected' => false, 'reason_not_inspected' => 'Client unavailable', 'location' => 'Zone 6, Bugo / 500 SQM', 'remarks' => 'New Leaf / PHP 14,000 monthly / 3 years', 'has_contract' => true]);
        $this->assertDatabaseHas('business_tenants', ['id' => $tenant->id, 'business_property_id' => $property->id, 'tenant_name' => 'New Leaf']);
        $this->assertSame('Electric and water billing is shouldered by the tenant.', $source->refresh()->businessReport->report_remarks);
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('name="properties_inspected" type="number" min="0" value="0"', false)
            ->assertSee('name="properties_not_inspected" type="number" min="0" value="1"', false)
            ->assertSee('name="properties_reason_not_inspected" type="text" value="Client unavailable" data-property-summary-reason', false)
            ->assertSee('value="Zone 6, Bugo / 500 SQM"', false)
            ->assertSee('value="New Leaf / PHP 14,000 monthly / 3 years"', false)
            ->assertSee('id="properties-0-has-contract" type="text" maxlength="1" pattern="[YyNn]" value="Y"', false)
            ->assertSee('>Electric and water billing is shouldered by the tenant.</textarea>', false);
    }

    public function test_truck_equipment_employee_and_supplier_values_use_the_combined_excel_table(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_truck_equipment');

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('business-truck-employee-supplier-table', false)
            ->assertSee('<span class="ui-label !mb-0"># OF OPERATORS:</span>', false)
            ->assertSee('<span class="ui-label !mb-0">MAIN FUEL SUPPLIER:</span>', false)
            ->assertSee('EMPLOYEES:')
            ->assertSee('# OF OPERATORS:')
            ->assertSee('# OF DRIVERS:')
            ->assertSee('# OF HELPERS:')
            ->assertSee('SUPPLIERS:')
            ->assertSee('MAIN FUEL SUPPLIER:')
            ->assertSee('REPAIR &amp; MAINTENANCE:', false)
            ->assertSee('MAIN SUPPLIER/LENDER:')
            ->assertSee('BUSINESS NAME/CONTACT PERSON')
            ->assertSee('MONTHLY AVE EXPENSE &amp; PAYMENT TRACK RECORD', false)
            ->assertSee('name="template_data[fields][operators_count]" type="text"', false);
        preg_match('/<section[^>]+business-truck-employee-supplier-section.*?<\/section>/s', $response->getContent(), $employeeSupplierSection);
        $this->assertNotEmpty($employeeSupplierSection);
        $this->assertStringNotContainsString('data-repeater-add', $employeeSupplierSection[0]);

        $payload = [
            'intent' => 'stay',
            'source_name' => 'Equipment Leasing',
            'business_name' => 'Equipment Leasing',
            'report_category' => 'Leasing',
            'template_data' => [
                'fields' => ['operators_count' => 'Two shifts', 'drivers_count' => '8 regular', 'helpers_count' => '4 on call'],
                'tables' => ['suppliers' => [
                    ['supplier_category' => 'MAIN FUEL SUPPLIER', 'supplier_name' => 'North Fuel', 'contact_information' => 'Ana Cruz', 'office_location' => 'North Road', 'years_transacting' => '5 years', 'payment_performance' => 'PHP 40,000 monthly / current'],
                    ['supplier_category' => 'REPAIR & MAINTENANCE', 'supplier_name' => ''],
                    ['supplier_category' => 'MAIN SUPPLIER/LENDER', 'supplier_name' => 'Capital Equipment', 'contact_information' => 'Ben Lim'],
                ]],
            ],
        ];

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)->assertRedirect();

        $source->refresh();
        $this->assertSame('Two shifts', data_get($source->businessReport->template_data, 'fields.operators_count'));
        $this->assertCount(2, data_get($source->businessReport->template_data, 'tables.suppliers'));
        $this->assertSame('MAIN SUPPLIER/LENDER', data_get($source->businessReport->template_data, 'tables.suppliers.1.supplier_category'));
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Two shifts"', false)
            ->assertSee('value="North Fuel"', false)
            ->assertSee('value="Capital Equipment"', false);
    }

    public function test_trucking_services_matches_the_grouped_excel_units_and_employee_supplier_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('trucking_services');

        $blankPage = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        preg_match('/<section[^>]+data-repeater="template-units".*?<tbody data-repeater-rows>(.*?)<\/tbody>/s', $blankPage->getContent(), $blankRows);
        $this->assertNotEmpty($blankRows[1]);
        $this->assertSame(3, substr_count($blankRows[1], 'data-repeater-row'));

        $source->businessReport->update(['template_data' => [
            'fields' => ['operators_count' => '4', 'drivers_count' => '8', 'helpers_count' => '6'],
            'tables' => ['units' => [[
                'brand_model' => 'Isuzu Forward',
                'year_model' => '2022',
                'plate_number' => 'TRK 7788',
                'years_employed' => '5 years',
                'goods_transported' => 'Construction materials',
                'pickup_delivery_areas' => 'Cagayan de Oro / Bukidnon',
                'registered_owner' => 'North Hauling Corp.',
                'encumbrances' => 'Chattel mortgage',
                'orcr_validation' => 'OR/CR verified against originals',
            ]]],
        ]]);

        $page = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        preg_match('/<section class="business-report-profile".*?<\/section>/s', $page->getContent(), $profile);
        $this->assertNotEmpty($profile[0]);
        $this->assertStringContainsString('Garage/ Office Address:', $profile[0]);
        $this->assertStringNotContainsString('Main Business Address:', $profile[0]);

        preg_match('/<section[^>]+data-repeater="template-units".*?<\/section>/s', $page->getContent(), $unitsSection);
        $this->assertNotEmpty($unitsSection[0]);
        $this->assertStringContainsString('class="business-report-table business-trucking-units-table"', $unitsSection[0]);
        $this->assertStringContainsString('rowspan="2"><span>BRAND/VEHICLE MODEL</span>', $unitsSection[0]);
        $this->assertStringContainsString('colspan="3"><span>INTERVIEW WITH DRIVER</span>', $unitsSection[0]);
        $this->assertStringContainsString('colspan="2"><span>ACTUAL OR/CR CHECKING</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>YEARS EMPLOYED</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>TYPES OF GOODS/BRANDS TRANSPORTED</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>AREAS PICKUP / DELIVERY SITES?</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>REGISTERED OWNER</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>ENCUMBRANCES</span>', $unitsSection[0]);
        $this->assertStringNotContainsString('<span>Actual OR/CR Validation</span>', $unitsSection[0]);
        $this->assertStringContainsString('type="hidden" name="template_data[tables][units][0][orcr_validation]" value="OR/CR verified against originals"', $unitsSection[0]);
        preg_match('/<tbody data-repeater-rows>\s*(<tr.*?<\/tr>)/s', $unitsSection[0], $savedUnitRow);
        $this->assertNotEmpty($savedUnitRow[1]);
        $this->assertSame(9, substr_count($savedUnitRow[1], '<td'));

        preg_match('/<section[^>]+business-truck-employee-supplier-section.*?<\/section>/s', $page->getContent(), $employeeSupplierSection);
        $this->assertNotEmpty($employeeSupplierSection[0]);
        $this->assertStringContainsString('EMPLOYEES (HIRED BY BORROWER):', $employeeSupplierSection[0]);
        $this->assertStringContainsString('SUPPLIERS:', $employeeSupplierSection[0]);
        $this->assertStringContainsString('BUSINESS NAME/CONTACT PERSON', $employeeSupplierSection[0]);
        $this->assertStringContainsString('MONTHLY AVE EXPENSE &amp; PAYMENT TRACK RECORD', $employeeSupplierSection[0]);
        $this->assertStringNotContainsString('business-report-action-heading', $employeeSupplierSection[0]);
        $this->assertStringNotContainsString('business-report-action-cell', $employeeSupplierSection[0]);
        $this->assertStringNotContainsString('data-repeater-add', $employeeSupplierSection[0]);

        $payload = [
            'intent' => 'stay',
            'source_name' => 'North Hauling',
            'business_name' => 'North Hauling',
            'report_category' => 'Transportation',
            'template_data' => [
                'fields' => [
                    'total_declared' => '5', 'total_inspected' => '1', 'total_not_inspected' => '4', 'reason_not_inspected' => 'Units were dispatched',
                    'operators_count' => '4', 'drivers_count' => '8', 'helpers_count' => '6',
                ],
                'tables' => [
                    'units' => [[
                        'brand_model' => 'Isuzu Forward', 'year_model' => '2022', 'plate_number' => 'TRK 7788', 'years_employed' => '5 years',
                        'goods_transported' => 'Construction materials', 'pickup_delivery_areas' => 'Cagayan de Oro / Bukidnon',
                        'registered_owner' => 'North Hauling Corp.', 'encumbrances' => 'Chattel mortgage', 'orcr_validation' => 'OR/CR verified against originals',
                    ]],
                    'suppliers' => [
                        ['supplier_category' => 'MAIN FUEL SUPPLIER', 'supplier_name' => 'North Fuel', 'office_location' => 'National Highway', 'years_transacting' => '3 years', 'payment_performance' => 'PHP 80,000 monthly / current'],
                        ['supplier_category' => 'REPAIR & MAINTENANCE'],
                        ['supplier_category' => 'MAIN SUPPLIER/LENDER', 'supplier_name' => 'Truck Finance Corp.'],
                    ],
                ],
            ],
        ];
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame('OR/CR verified against originals', data_get($source->businessReport->template_data, 'tables.units.0.orcr_validation'));
        $this->assertSame('4', data_get($source->businessReport->template_data, 'fields.operators_count'));
        $this->assertSame('North Fuel', data_get($source->businessReport->template_data, 'tables.suppliers.0.supplier_name'));
        $this->assertSame('Truck Finance Corp.', data_get($source->businessReport->template_data, 'tables.suppliers.1.supplier_name'));
    }

    public function test_distributorship_uses_the_excel_stockroom_vehicle_interview_and_products_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('distributorship_wholesaler_b2b');
        $source->businessReport->update(['template_data' => [
            'fields' => ['office_staff' => '2', 'products_seen' => 'PHARMACEUTICALS, FRESH/REFRIGERATED GOODS', 'top_brands' => "Fresh Brand\n\nHome Brand", 'top_brand_prices' => "PHP 500/case\n\nPHP 300/box"],
            'tables' => ['units' => [[
                'brand_model' => 'Isuzu Elf',
                'driver_agent_interview' => 'Legacy combined interview notes',
                'orcr_validation' => 'Legacy OR/CR verification',
            ]]],
            'questions' => ['Warehouse District', '12 stores', '3X', 'PHP 8,000', 'BANK DEPOSIT', 'TERM: 30 DAYS'],
        ]]);

        $page = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        preg_match('/<section class="business-report-profile".*?<\/section>/s', $page->getContent(), $profile);
        $this->assertNotEmpty($profile[0]);
        $this->assertStringContainsString('Garage/ Office Address:', $profile[0]);

        preg_match('/<section[^>]+business-distributor-stockroom.*?<\/section>/s', $page->getContent(), $stockroom);
        $this->assertNotEmpty($stockroom[0]);
        foreach (['Summary of Office/Warehouse/Stockroom:', 'EMPLOYEES:', 'INVENTORY LEVEL:', 'PRODUCTS/GOODS SEEN IN INVENTORY:', 'TOP BRANDS STOCKED:', 'SELLING PRICE', '# OF OFFICE STAFF:', '# OF AGENTS:', '# OF DRIVERS:', '# OF HELPERS:', 'HIGH', 'MODERATE', 'LOW', 'NONE', 'PHARMACEUTICALS', 'BEVERAGES', 'CONSTRUCTION MATS', 'PACKAGED DRY FOOD', 'FRESH / REFRIGERATED GOODS', 'EQUIPMENT / DEVICES / MACHINES', 'HOME / CONSUMER GOODS', 'AGRI FEEDS / FERTILIZER / SEEDS'] as $label) {
            $this->assertStringContainsString($label, $stockroom[0]);
        }
        foreach (['office_staff', 'agents', 'drivers', 'helpers', 'products_seen', 'top_brands', 'top_brand_prices'] as $field) {
            $this->assertSame(1, substr_count($stockroom[0], 'template_data[fields]['.$field.']'));
        }
        $this->assertSame(4, substr_count($stockroom[0], 'template_data[fields][inventory_level]'));
        $this->assertSame(8, substr_count($stockroom[0], 'data-distributor-product-option'));
        $this->assertSame(2, preg_match_all('/data-distributor-product-option checked/', $stockroom[0]));
        $this->assertSame(4, substr_count($stockroom[0], 'class="business-distributor-product-options"'));
        $this->assertMatchesRegularExpression('/PHARMACEUTICALS.*BEVERAGES.*CONSTRUCTION MATS.*PACKAGED DRY FOOD.*FRESH \/ REFRIGERATED GOODS.*EQUIPMENT \/ DEVICES \/ MACHINES.*HOME \/ CONSUMER GOODS.*AGRI FEEDS \/ FERTILIZER \/ SEEDS/s', $stockroom[0]);
        $this->assertSame(4, substr_count($stockroom[0], 'data-distributor-stockroom-input="brand"'));
        $this->assertSame(4, substr_count($stockroom[0], 'data-distributor-stockroom-input="price"'));
        $this->assertStringNotContainsString('<textarea', $stockroom[0]);
        $this->assertStringContainsString('id="distributor-top-brand-0" class="ui-control" type="text" value="Fresh Brand"', $stockroom[0]);
        $this->assertStringContainsString('id="distributor-top-brand-2" class="ui-control" type="text" value="Home Brand"', $stockroom[0]);
        $this->assertStringContainsString('id="distributor-top-brand-price-0" class="ui-control" type="text" value="PHP 500/case"', $stockroom[0]);
        $this->assertStringContainsString('id="distributor-top-brand-price-2" class="ui-control" type="text" value="PHP 300/box"', $stockroom[0]);

        preg_match('/<section[^>]+data-repeater="template-units".*?<\/section>/s', $page->getContent(), $unitsSection);
        $this->assertNotEmpty($unitsSection[0]);
        $this->assertStringContainsString('class="business-report-table business-distributor-units-table"', $unitsSection[0]);
        $this->assertStringContainsString('INTERVIEW WITH DRIVER/AGENT', $unitsSection[0]);
        $this->assertStringContainsString('ACTUAL OR/CR CHECKING', $unitsSection[0]);
        $this->assertStringContainsString('<span>YEARS EMPLOYED</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>TYPES OF GOODS/BRANDS TRANSPORTED</span>', $unitsSection[0]);
        $this->assertStringContainsString('<span>AREAS/TERRITORIES?</span>', $unitsSection[0]);
        $this->assertStringContainsString('name="template_data[tables][units][0][driver_agent_interview]" value="Legacy combined interview notes"', $unitsSection[0]);
        $this->assertStringContainsString('name="template_data[tables][units][0][orcr_validation]" value="Legacy OR/CR verification"', $unitsSection[0]);

        preg_match('/<section[^>]+business-distributor-interview-products.*?<\/section>/s', $page->getContent(), $interviewProducts);
        $this->assertNotEmpty($interviewProducts[0]);
        $this->assertStringContainsString('Detailed Information Gathered from Employee/Driver/Manager Interview:', $interviewProducts[0]);
        $this->assertStringContainsString('Where are stocks purchased from?', $interviewProducts[0]);
        $this->assertStringContainsString('Are stores given payment terms for deliveries?', $interviewProducts[0]);
        $this->assertSame(3, preg_match_all('/<input[^>]+name="template_data\[questions\]\[\d+\]"[^>]+type="text"/', $interviewProducts[0]));
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]+name="template_data\[questions\]/', $interviewProducts[0]);
        $this->assertSame(3, substr_count($interviewProducts[0], 'class="business-report-choice-group business-address-status business-distributor-question-choice"'));
        $this->assertSame(4, substr_count($interviewProducts[0], 'name="template_data[questions][2]" type="radio"'));
        $this->assertSame(3, substr_count($interviewProducts[0], 'name="template_data[questions][4]" type="radio"'));
        $this->assertSame(3, substr_count($interviewProducts[0], 'name="template_data[questions][5]" type="radio"'));
        $this->assertStringContainsString('name="template_data[questions][2]" type="radio" value="3X" checked', $interviewProducts[0]);
        $this->assertStringContainsString('name="template_data[questions][4]" type="radio" value="BANK DEPOSIT" checked', $interviewProducts[0]);
        $this->assertStringContainsString('data-distributor-payment-option="term" checked', $interviewProducts[0]);
        $this->assertStringContainsString('value="30 DAYS" aria-label="Payment term" data-distributor-payment-term-input', $interviewProducts[0]);
        $this->assertStringContainsString('Top Sellable Products', $interviewProducts[0]);
        $this->assertStringContainsString('Selling Price per Unit', $interviewProducts[0]);
        $this->assertStringContainsString('Sales per Month', $interviewProducts[0]);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $interviewProducts[0], $blankProductRows);
        $this->assertNotEmpty($blankProductRows[1]);
        $this->assertSame(5, substr_count($blankProductRows[1], 'data-repeater-row'));
        $script = file_get_contents(resource_path('js/app.js'));
        $stylesheet = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.business-distributor-question-row { display: grid; grid-template-columns: minmax(15rem, 12fr) minmax(15rem, 13fr); align-items: stretch; }', $stylesheet);
        $this->assertStringContainsString('.business-distributor-question-row { grid-template-columns: minmax(0, 1fr); }', $stylesheet);
        $this->assertStringContainsString('[data-distributor-stockroom-input]', $script);
        $this->assertStringContainsString('data-distributor-stockroom-value', $script);
        $this->assertStringContainsString('[data-distributor-product-option]:checked', $script);
        $this->assertStringContainsString('[data-distributor-payment-option="term"]', $script);
        $this->assertStringContainsString("if (!termSelected && clearInactive) termInput.value = '';", $script);

        $payload = [
            'intent' => 'stay',
            'source_name' => 'Regional Distribution',
            'business_name' => 'Regional Distribution',
            'report_category' => 'Distribution',
            'template_data' => [
                'fields' => [
                    'office_staff' => '3', 'agents' => '5', 'drivers' => '4', 'helpers' => '6',
                    'inventory_level' => 'HIGH', 'products_seen' => 'PHARMACEUTICALS, HOME / CONSUMER GOODS',
                    'top_brands' => "Fresh Brand\n\nHome Brand", 'top_brand_prices' => "PHP 500/case\n\nPHP 300/box",
                    'total_declared' => '3', 'total_inspected' => '1', 'total_not_inspected' => '2', 'reason_not_inspected' => 'Delivery vehicles were dispatched',
                ],
                'tables' => [
                    'units' => [[
                        'brand_model' => 'Isuzu Elf', 'year_model' => '2022', 'plate_number' => 'DST 1234', 'years_employed' => '4 years',
                        'goods_transported' => 'Beverages and dry goods', 'areas_territories' => 'Northern Mindanao',
                        'registered_owner' => 'Regional Distribution', 'encumbrances' => 'None',
                        'driver_agent_interview' => 'Legacy combined interview notes', 'orcr_validation' => 'Legacy OR/CR verification',
                    ]],
                    'products' => [
                        ['product' => 'Bottled Drinks', 'selling_price_per_unit' => 'PHP 500/case', 'sales_per_month' => '120 cases'],
                        [], [], [], [],
                    ],
                ],
                'questions' => ['Warehouse District', '12 stores', '2X', 'PHP 8,000', 'OFFICE', 'COD'],
            ],
        ];
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame('3', data_get($source->businessReport->template_data, 'fields.office_staff'));
        $this->assertSame('PHARMACEUTICALS, HOME / CONSUMER GOODS', data_get($source->businessReport->template_data, 'fields.products_seen'));
        $this->assertSame("Fresh Brand\n\nHome Brand", data_get($source->businessReport->template_data, 'fields.top_brands'));
        $this->assertSame("PHP 500/case\n\nPHP 300/box", data_get($source->businessReport->template_data, 'fields.top_brand_prices'));
        $this->assertSame('Legacy combined interview notes', data_get($source->businessReport->template_data, 'tables.units.0.driver_agent_interview'));
        $this->assertSame('Legacy OR/CR verification', data_get($source->businessReport->template_data, 'tables.units.0.orcr_validation'));
        $this->assertSame([['product' => 'Bottled Drinks', 'selling_price_per_unit' => 'PHP 500/case', 'sales_per_month' => '120 cases']], data_get($source->businessReport->template_data, 'tables.products'));
        $this->assertSame('2X', data_get($source->businessReport->template_data, 'questions.2'));
        $this->assertSame('OFFICE', data_get($source->businessReport->template_data, 'questions.4'));
        $this->assertSame('COD', data_get($source->businessReport->template_data, 'questions.5'));
    }

    public function test_pharmacy_branch_table_uses_its_approved_labels_and_three_blank_default_rows(): void
    {
        [$ci, $folder, $source] = $this->createSource('pharmacy_drugstore');

        $page = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();
        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $page->getContent(), $branchesSection);
        $this->assertNotEmpty($branchesSection[0]);
        $this->assertTrue(str_contains($branchesSection[0], '<span>INVENTORY LEVEL</span><small class="business-report-column-guide">(HIGH, MID, LOW)</small>'));
        $this->assertTrue(str_contains($branchesSection[0], '<span>BIG BRANDS NEAR THE AREA?</span>'));
        $this->assertTrue(str_contains($branchesSection[0], '+ Add Row'));
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $branchesSection[0], $blankRows);
        $this->assertNotEmpty($blankRows[1]);
        $this->assertSame(3, substr_count($blankRows[1], 'data-repeater-row'));

        preg_match('/<section[^>]+business-pharmacy-products-observations.*?<\/section>/s', $page->getContent(), $productsObservationsSection);
        $this->assertNotEmpty($productsObservationsSection[0]);
        $this->assertTrue(str_contains($productsObservationsSection[0], '<span>Top Sellable Products</span><small class="business-report-column-guide">(branded or generic)</small>'));
        $this->assertTrue(str_contains($productsObservationsSection[0], '<span>Selling Price per Item</span>'));
        $this->assertTrue(str_contains($productsObservationsSection[0], 'OBSERVATIONS DURING BUSINESS INSPECTION:'));
        $this->assertSame(4, substr_count($productsObservationsSection[0], 'name="template_data[questions]'));
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $productsObservationsSection[0], $blankProductRows);
        $this->assertSame(4, substr_count($blankProductRows[1], 'data-repeater-row'));

        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $page->getContent(), $suppliersSection);
        $this->assertNotEmpty($suppliersSection[0]);
        foreach (['Supplier Validation - Especially Supplier of Top Sellable Products (if applicable)', 'SUPPLIER NAME', 'OFFICE LOCATION', 'CONFIMRED', '(Y/N)', 'IMPORTANT REMARKS'] as $supplierLabel) {
            $this->assertTrue(str_contains($suppliersSection[0], $supplierLabel));
        }
        $this->assertFalse(str_contains($suppliersSection[0], 'Top Sellable Products (if applicable):'));
        $this->assertFalse(str_contains($suppliersSection[0], '<span>Contact Information</span>'));
        $this->assertFalse(str_contains($suppliersSection[0], '<span>Years Transacting</span>'));
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $suppliersSection[0], $blankSupplierRows);
        $this->assertSame(3, substr_count($blankSupplierRows[1], 'data-repeater-row'));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Community Pharmacy',
            'business_name' => 'Community Pharmacy',
            'report_category' => 'Pharmacy',
            'template_data' => [
                'tables' => [
                    'branches' => [
                        ['location' => 'Main Street', 'inventory_level' => 'HIGH', 'nearby_brands' => 'National Pharmacy'],
                        [],
                        [],
                    ],
                    'products' => [
                        ['product' => 'Branded Medicine', 'selling_price' => 'PHP 125'],
                        [], [], [],
                    ],
                    'suppliers' => [
                        ['supplier_name' => 'Medicine Distributor', 'office_location' => 'City Center', 'confirmed' => 'Y', 'payment_performance' => 'Pays on time', 'contact_information' => '09170000000', 'years_transacting' => '5'],
                        [], [],
                    ],
                ],
                'questions' => ['Competitor Pharmacy', 'Accessible market', 'Customers observed at noon', 'Main Bank / Main Branch'],
            ],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame([[
            'location' => 'Main Street',
            'inventory_level' => 'HIGH',
            'nearby_brands' => 'National Pharmacy',
        ]], data_get($source->businessReport->template_data, 'tables.branches'));
        $this->assertSame([['product' => 'Branded Medicine', 'selling_price' => 'PHP 125']], data_get($source->businessReport->template_data, 'tables.products'));
        $this->assertSame('09170000000', data_get($source->businessReport->template_data, 'tables.suppliers.0.contact_information'));
        $this->assertSame('5', data_get($source->businessReport->template_data, 'tables.suppliers.0.years_transacting'));

        $savedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $savedPage->getContent(), $savedBranchesSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedBranchesSection[0], $savedRows);
        $this->assertSame(1, substr_count($savedRows[1], 'data-repeater-row'));
        preg_match('/<section[^>]+business-pharmacy-products-observations.*?<\/section>/s', $savedPage->getContent(), $savedProductsObservationsSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedProductsObservationsSection[0], $savedProductRows);
        $this->assertSame(1, substr_count($savedProductRows[1], 'data-repeater-row'));
        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $savedPage->getContent(), $savedSuppliersSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedSuppliersSection[0], $savedSupplierRows);
        $this->assertSame(1, substr_count($savedSupplierRows[1], 'data-repeater-row'));
        $this->assertTrue(str_contains($savedSuppliersSection[0], 'name="template_data[tables][suppliers][0][contact_information]" value="09170000000"'));
        $this->assertTrue(str_contains($savedSuppliersSection[0], 'name="template_data[tables][suppliers][0][years_transacting]" value="5"'));

    }

    public function test_general_merchandise_matches_the_excel_branch_product_observation_and_supplier_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('general_merchandise_hardware_parts');
        $source->businessReport->update(['template_data' => ['questions' => ['Competitor', 'Good location', 'Customers seen', 'Main Bank', 'Dry goods', 'Wholesale Buyer', 'Two owned trucks']]]);

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $this->assertTrue(str_contains($page->getContent(), 'Type of Store (Check Applicable)'));
        preg_match('/<div class="business-template-field business-report-choice-field business-general-store-type">(.*?)<\/div>/s', $page->getContent(), $storeTypeRow);
        $this->assertNotEmpty($storeTypeRow[1]);
        $this->assertTrue(strpos($storeTypeRow[1], 'Type of Store (Check Applicable)') < strpos($storeTypeRow[1], 'value="General Merchandise"'));
        foreach (['General Merchandise', 'Hardware', 'Auto or Motor Parts', 'Paint Only'] as $storeType) {
            $this->assertTrue(str_contains($page->getContent(), 'value="'.$storeType.'"'));
        }

        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $page->getContent(), $branchesSection);
        $this->assertTrue(str_contains($branchesSection[0], '<span>AVE. PHP SALES PER SHIFT</span>'));
        $this->assertTrue(str_contains($branchesSection[0], '<span>INVENTORY LEVEL</span><small class="business-report-column-guide">(HIGH, MID, LOW)</small>'));
        $this->assertTrue(str_contains($branchesSection[0], '<span>BIG BRANDS NEAR THE AREA?</span>'));
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $branchesSection[0], $branchRows);
        $this->assertSame(3, substr_count($branchRows[1], 'data-repeater-row'));

        preg_match('/<section[^>]+business-pharmacy-products-observations.*?<\/section>/s', $page->getContent(), $productsObservationsSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $productsObservationsSection[0], $productRows);
        $this->assertSame(6, substr_count($productRows[1], 'data-repeater-row'));
        $this->assertSame(8, substr_count($productsObservationsSection[0], 'name="template_data[questions]'));
        foreach (['What are the most stocked products seen in the store or bodega?', 'Aside from walk-in clients, are there big recurring customers?', 'Do they have delivery trucks? How many?', 'Are these delivery trucks owned or mortgaged? Which institution?'] as $question) {
            $this->assertTrue(str_contains($productsObservationsSection[0], $question));
        }
        $this->assertTrue(str_contains($productsObservationsSection[0], 'value="Main Bank"'));
        $this->assertTrue(strpos($productsObservationsSection[0], 'value="Main Bank"') > strpos($productsObservationsSection[0], 'Are these delivery trucks owned or mortgaged?'));

        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $page->getContent(), $suppliersSection);
        foreach (['Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):', 'SUPPLIER NAME', 'OFFICE LOCATION', 'CONFIMRED', 'IMPORTANT REMARKS'] as $label) {
            $this->assertTrue(str_contains($suppliersSection[0], $label));
        }
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $suppliersSection[0], $supplierRows);
        $this->assertSame(3, substr_count($supplierRows[1], 'data-repeater-row'));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Hardware Store',
            'business_name' => 'Hardware Store',
            'report_category' => 'General Merchandise',
            'template_data' => [
                'fields' => ['store_type' => 'Hardware'],
                'tables' => [
                    'branches' => [['location' => 'Main Branch'], [], []],
                    'products' => [['product' => 'Power Tools', 'selling_price' => 'PHP 5,000'], [], [], [], [], []],
                    'suppliers' => [['supplier_name' => 'Tool Supplier', 'office_location' => 'Warehouse District', 'confirmed' => 'Y', 'payment_performance' => 'Good', 'contact_information' => '09171234567', 'years_transacting' => '6'], [], []],
                ],
                'questions' => ['Competitor', 'Good location', 'Customers seen', 'Construction materials', 'Contractors', 'Two trucks', 'Owned', 'Main Bank'],
            ],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.branches'));
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.products'));
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.suppliers'));
        $this->assertSame('Hardware', data_get($source->businessReport->template_data, 'fields.store_type'));
        $this->assertSame('09171234567', data_get($source->businessReport->template_data, 'tables.suppliers.0.contact_information'));
        $this->assertSame('Main Bank', data_get($source->businessReport->template_data, 'questions.7'));
    }

    public function test_buy_sell_dry_goods_matches_its_excel_branch_product_observation_and_supplier_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('buy_sell_dry_goods');

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<div class="business-template-field business-report-choice-field business-general-store-type">(.*?)<\/div>/s', $page->getContent(), $storeTypeRow);
        $this->assertNotEmpty($storeTypeRow[1]);
        foreach (['High End Display', 'Middle Tier Store', 'Ukay-Ukay', 'Stall Type Only'] as $storeType) {
            $this->assertTrue(str_contains($storeTypeRow[1], 'value="'.$storeType.'"'));
        }
        $this->assertTrue(str_contains($page->getContent(), '# Branches Not Inspected'));

        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $page->getContent(), $branchesSection);
        foreach (['AVE. PHP SALES PER SHIFT', 'INVENTORY LEVEL', '(HIGH, MID, LOW)', 'BIG BRANDS NEAR THE AREA?'] as $label) {
            $this->assertTrue(str_contains($branchesSection[0], $label));
        }
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $branchesSection[0], $branchRows);
        $this->assertSame(3, substr_count($branchRows[1], 'data-repeater-row'));

        preg_match('/<section[^>]+business-pharmacy-products-observations.*?<\/section>/s', $page->getContent(), $productsObservationsSection);
        $this->assertTrue(str_contains($productsObservationsSection[0], '<span>Top Sellable Products</span>'));
        $this->assertTrue(str_contains($productsObservationsSection[0], '<span>Selling Price per Item</span>'));
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $productsObservationsSection[0], $productRows);
        $this->assertSame(6, substr_count($productRows[1], 'data-repeater-row'));
        $this->assertSame(8, substr_count($productsObservationsSection[0], 'name="template_data[questions]'));
        $this->assertTrue(strpos($productsObservationsSection[0], 'Are items brand new or second hand?') < strpos($productsObservationsSection[0], 'Which declared bank shows the business income?'));

        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $page->getContent(), $suppliersSection);
        foreach (['Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):', 'SUPPLIER NAME', 'OFFICE LOCATION', 'CONFIMRED', 'IMPORTANT REMARKS'] as $label) {
            $this->assertTrue(str_contains($suppliersSection[0], $label));
        }
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $suppliersSection[0], $supplierRows);
        $this->assertSame(3, substr_count($supplierRows[1], 'data-repeater-row'));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Dry Goods Store',
            'business_name' => 'Dry Goods Store',
            'report_category' => 'Retail',
            'template_data' => [
                'fields' => ['store_type' => 'Middle Tier Store'],
                'tables' => [
                    'branches' => [['location' => 'Main Market'], [], []],
                    'products' => [['product' => 'School Bags', 'selling_price' => 'PHP 450'], [], [], [], [], []],
                    'suppliers' => [['supplier_name' => 'Dry Goods Supplier', 'office_location' => 'Commercial Center', 'confirmed' => 'Y', 'payment_performance' => 'Good'], [], []],
                ],
                'questions' => ['Competitor', 'Good location', 'Customers seen', 'Main Bank', 'Apparel', 'Thirty days', 'Validated receipts', 'Brand new'],
            ],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.branches'));
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.products'));
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.suppliers'));
        $this->assertSame('Middle Tier Store', data_get($source->businessReport->template_data, 'fields.store_type'));
        $this->assertSame('Main Bank', data_get($source->businessReport->template_data, 'questions.3'));
    }

    public function test_business_header_uses_saved_client_values_and_keeps_its_optional_dates_independent_from_cibi(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_non_agricultural');
        $cibiStart = now()->subDays(12)->toDateString();
        $cibiSubmitted = now()->subDays(10)->toDateString();
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'start_date' => $cibiStart,
            'submitted_date' => $cibiSubmitted,
            'party_type' => 'co_maker',
            'branch_name' => 'Saved Client Branch',
            'account_officer_name' => 'Saved Account Officer',
            'amount_applied' => 275000,
        ]);
        $source->update(['amount_applied' => 'Stale Business Amount']);

        $headerResponse = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('RESTRICTED &amp; CONFIDENTIAL', false)
            ->assertSee('(v.as of -2020.08.03)')
            ->assertSee('Saved Client Branch')
            ->assertSee('Saved Account Officer')
            ->assertSee('CO-MAKER')
            ->assertSee('business-report-party-check" aria-hidden="true">( ✓ )', false)
            ->assertSee('id="branch_name"', false)
            ->assertSee('id="account_officer_name"', false)
            ->assertSee('readonly aria-readonly="true"', false)
            ->assertSee('id="amount_applied"', false)
            ->assertSee('type="text"', false)
            ->assertSee('data-business-template-preview="', false)
            ->assertSee('name="start_date"', false)
            ->assertSee('name="submitted_date"', false)
            ->assertDontSee('value="'.$cibiStart.'"', false)
            ->assertDontSee('value="'.$cibiSubmitted.'"', false);
        $this->assertMatchesRegularExpression('/<input id="amount_applied" type="text" value="275,000\.00" class="business-report-header-control" readonly aria-readonly="true">/', $headerResponse->getContent());
        $this->assertDoesNotMatchRegularExpression('/<input id="amount_applied"[^>]+(?:name=|disabled)/', $headerResponse->getContent());
        $headerResponse->assertDontSee('Stale Business Amount');

        $stylesheet = file_get_contents(resource_path('css/app.css'));
        preg_match('/\.business-report-official-header\s*\{([^}]*)\}/', $stylesheet, $headerRule);
        $this->assertStringNotContainsString('border-bottom', $headerRule[1] ?? '');

        $businessStart = now()->subDays(4)->toDateString();
        $businessSubmitted = now()->subDays(2)->toDateString();
        $payload = $this->businessPayload();
        $payload['intent'] = 'stay';
        $payload['start_date'] = $businessStart;
        $payload['submitted_date'] = $businessSubmitted;
        $payload['amount_applied'] = 'PHP 275,000 / approved range';

        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame($businessStart, $source->businessReport->fresh()->start_date->toDateString());
        $this->assertSame($businessSubmitted, $source->businessReport->fresh()->submitted_date->toDateString());
        $this->assertSame('Stale Business Amount', $source->fresh()->amount_applied);
        $this->assertSame($cibiStart, $folder->cibiReport->fresh()->start_date->toDateString());
        $this->assertSame($cibiSubmitted, $folder->cibiReport->fresh()->submitted_date->toDateString());

        $folder->cibiReport()->update(['amount_applied' => 1000000.50]);
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('id="amount_applied" type="text" value="1,000,000.50"', false)
            ->assertDontSee('value="275,000.00"', false);
    }

    public function test_official_fallback_saves_ranked_declared_items_and_requires_rank_one_to_complete(): void
    {
        [$ci, $folder, $source] = $this->createSource('general_income_sources');
        $payload = $this->generalPayload();
        $payload['items'][0]['contribution_rank'] = 2;
        $this->actingAs($ci)->put(route('client-folders.income-sources.general.update', [$folder, $source]), $payload)->assertSessionHasErrors('items');

        $payload = $this->generalPayload();
        $payload['items'][] = ['source_name' => 'Rental', 'contribution_rank' => 1];
        $this->actingAs($ci)->put(route('client-folders.income-sources.general.update', [$folder, $source]), $payload)->assertSessionHasErrors('items.1.contribution_rank');

        $payload = $this->generalPayload();
        $this->actingAs($ci)->put(route('client-folders.income-sources.general.update', [$folder, $source]), $payload)->assertRedirect();
        $this->assertDatabaseHas('declared_income_source_items', ['source_name' => 'Pension', 'contribution_rank' => 1]);
        $this->assertSame(RecordState::Complete, $source->refresh()->state);
    }

    public function test_dedicated_child_ids_and_incompatible_sections_cannot_cross_report_boundaries(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_non_agricultural');
        [, , $other] = $this->createSource('leasing_non_agricultural', $ci, $folder);
        $foreignProperty = $other->businessReport->properties()->create(['property_type' => 'Foreign']);
        $payload = $this->businessPayload();
        $payload['properties'][0]['id'] = $foreignProperty->id;
        $payload['branches'] = [['location' => 'Not compatible']];

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertSessionHasErrors(['properties.0.id', 'branches.0']);
        $this->assertDatabaseHas('business_properties', ['id' => $foreignProperty->id, 'business_report_id' => $other->businessReport->id]);
    }

    public function test_retail_template_dispatches_its_own_sections_and_index_avoids_n_plus_one_queries(): void
    {
        [$ci, $folder, $retail] = $this->createSource('retail_grocery_water_refilling');
        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $retail]))
            ->assertOk()
            ->assertSee('RETAIL: GROCERY STORE / SUPERMARKET / SARI-SARI STORE / WATER REFILLING')
            ->assertSee('Summary of Branches Inspected')
            ->assertSee('TOTAL BRANCHES DECLARED:')
            ->assertSee('OBSERVATIONS DURING BUSINESS INSPECTION:')
            ->assertSee('Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):')
            ->assertSee('Scale of Business:')
            ->assertSee('value="Water Refilling"', false)
            ->assertSee('data-business-template-preview', false);
        preg_match('/<section[^>]+data-repeater="branches".*?<\/section>/s', $page->getContent(), $branchesSection);
        $this->assertStringNotContainsString('type="checkbox"', $branchesSection[0]);
        $this->assertStringContainsString('name="branches[0][is_air_conditioned]" type="text"', $branchesSection[0]);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $branchesSection[0], $branchRows);
        $this->assertSame(3, substr_count($branchRows[1], 'data-repeater-row'));
        preg_match('/<div class="business-pharmacy-products-panel" data-repeater="products">(.*?)<\/div>\s*<div class="business-distributor-interview-panel/s', $page->getContent(), $productsSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $productsSection[1], $productRows);
        $this->assertSame(6, substr_count($productRows[1], 'data-repeater-row'));
        preg_match('/<section[^>]+data-repeater="suppliers".*?<\/section>/s', $page->getContent(), $suppliersSection);
        $this->assertStringNotContainsString('type="checkbox"', $suppliersSection[0]);
        $this->assertStringContainsString('<span>CONFIMRED</span><small class="business-report-column-guide">(Y/N)</small>', $suppliersSection[0]);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $suppliersSection[0], $supplierRows);
        $this->assertSame(3, substr_count($supplierRows[1], 'data-repeater-row'));
        preg_match('/<section[^>]+business-pharmacy-products-observations.*?<\/section>/s', $page->getContent(), $productsObservationsSection);
        $this->assertSame(7, substr_count($productsObservationsSection[0], 'class="ui-control" name="observations['));

        $template = IncomeSourceTemplate::where('template_type', 'general_income_sources')->firstOrFail();
        for ($i = 0; $i < 12; $i++) {
            $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => "Source $i"]);
        }
        DB::enableQueryLog();
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertRedirect(route('client-folders.income-sources.index', $folder));
        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_retail_excel_default_rows_only_persist_entered_values(): void
    {
        [$ci, $folder, $retail] = $this->createSource('retail_grocery_water_refilling');
        $questions = [
            ['observation_code' => 'competitors', 'question_snapshot' => 'Who are the competitors near the area?', 'answer' => 'Nearby Grocery'],
            ['observation_code' => 'location', 'question_snapshot' => 'Does the client have a good location?'],
            ['observation_code' => 'customers', 'question_snapshot' => 'Were customers observed?'],
            ['observation_code' => 'stocked_products', 'question_snapshot' => 'What products are stocked?'],
            ['observation_code' => 'pos_machine', 'question_snapshot' => 'Is there a POS machine?'],
            ['observation_code' => 'refrigerated_goods', 'question_snapshot' => 'Are refrigerated goods available?'],
            ['observation_code' => 'declared_bank', 'question_snapshot' => 'Which bank receives business income?'],
        ];

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $retail]), [
            'intent' => 'stay',
            'source_name' => 'Neighborhood Grocery',
            'business_name' => 'Neighborhood Grocery',
            'report_category' => 'Retail',
            'scale' => 'Grocery Store',
            'branches' => [['location' => 'Town Center', 'is_air_conditioned' => 'Y'], [], []],
            'products' => [['product_name' => 'Canned Goods', 'selling_price' => 75], [], [], [], [], []],
            'suppliers' => [['supplier_name' => 'Wholesale Supplier', 'office_location' => 'Trading Center', 'is_confirmed' => 'N', 'payment_performance' => 'Good'], [], []],
            'observations' => $questions,
        ])->assertSessionHasNoErrors();

        $report = $retail->businessReport->fresh();
        $this->assertCount(1, $report->branches);
        $this->assertCount(1, $report->products);
        $this->assertCount(1, $report->suppliers);
        $this->assertCount(1, $report->observations);
        $this->assertTrue((bool) $report->branches->first()->is_air_conditioned);
        $this->assertFalse((bool) $report->suppliers->first()->is_confirmed);
        $this->assertSame('Nearby Grocery', $report->observations->first()->answer);

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $retail]))
            ->assertOk()
            ->assertSee('value="Nearby Grocery"', false)
            ->assertSee('value="Canned Goods"', false)
            ->assertSee('value="Wholesale Supplier"', false)
            ->assertSee('name="branches[0][is_air_conditioned]" type="text" value="Y"', false)
            ->assertSee('name="suppliers[0][is_confirmed]" type="text" value="N"', false);
    }

    public function test_meatshop_matches_the_excel_inventory_and_observation_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('meatshop_store');

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $page->getContent(), $branchesSection);
        $this->assertNotEmpty($branchesSection[0]);
        $this->assertStringContainsString('<span>INVENTORY LEVEL</span><small class="business-report-column-guide">(HIGH, MID, LOW)</small>', $branchesSection[0]);
        $this->assertStringContainsString('<span>BIG BRANDS NEAR THE AREA?</span>', $branchesSection[0]);
        $this->assertStringContainsString('+ Add Row', $branchesSection[0]);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $branchesSection[0], $branchRows);
        $this->assertSame(3, substr_count($branchRows[1], 'data-repeater-row'));
        preg_match('/<section class="business-report-section">\s*<header class="business-distributor-section-title"><h2>PRODUCTS\/GOODS SEEN IN INVENTORY AT STORE.*?<\/section>/s', $page->getContent(), $inventorySection);
        $this->assertNotEmpty($inventorySection[0]);
        foreach (['POULTRY: CHICKEN', 'PORK', 'BEEF', 'FISH', 'PACKAGED/CANNED FOOD', 'OTHER PRODUCTS', 'Main Supplier', 'Location', 'Contact No.', 'Ave. Daily Kilos Sold', 'Range of Price per Kilo', 'Other Remarks'] as $label) {
            $this->assertStringContainsString($label, $inventorySection[0]);
        }
        $this->assertSame(6, substr_count($inventorySection[0], 'type="checkbox"'));
        $this->assertStringNotContainsString('data-repeater-add', $inventorySection[0]);
        $this->assertStringNotContainsString('business-report-action-heading', $inventorySection[0]);

        preg_match('/<section class="business-report-section business-meatshop-observations">.*?<\/section>/s', $page->getContent(), $observationsSection);
        $this->assertNotEmpty($observationsSection[0]);
        $this->assertSame(9, substr_count($observationsSection[0], 'name="template_data[questions]'));
        $this->assertStringNotContainsString('<textarea', $observationsSection[0]);
        $this->assertStringContainsString('How many refrigerators? What other specialized equipment is available?', $observationsSection[0]);

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'City Meatshop',
            'business_name' => 'City Meatshop',
            'report_category' => 'Meatshop',
            'template_data' => [
                'tables' => [
                    'branches' => [
                        ['location' => 'Public Market', 'inventory_level' => 'HIGH', 'nearby_brands' => 'National Meatshop'],
                        [],
                        [],
                    ],
                    'inventory' => [
                        [],
                        [],
                        ['product_type' => 'Beef', 'main_supplier' => 'Local Ranch', 'daily_kilos_sold' => '35'],
                        [],
                        [],
                        [],
                    ],
                ],
                'questions' => ['', '', 'Beef and pork', '', '', '', '', '', 'Main Bank'],
            ],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame([[
            'location' => 'Public Market',
            'inventory_level' => 'HIGH',
            'nearby_brands' => 'National Meatshop',
        ]], data_get($source->businessReport->template_data, 'tables.branches'));
        $this->assertSame([[
            'product_type' => 'Beef',
            'main_supplier' => 'Local Ranch',
            'daily_kilos_sold' => '35',
        ]], data_get($source->businessReport->template_data, 'tables.inventory'));
        $this->assertSame('Beef and pork', data_get($source->businessReport->template_data, 'questions.2'));

        $savedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Beef" checked', false)
            ->assertSee('value="Local Ranch"', false)
            ->assertSee('value="Beef and pork"', false);
        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $savedPage->getContent(), $savedBranchesSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedBranchesSection[0], $savedBranchRows);
        $this->assertSame(1, substr_count($savedBranchRows[1], 'data-repeater-row'));
    }

    public function test_contractor_matches_the_excel_project_supplier_and_validation_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('contractor_subcontractor');

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section[^>]+data-repeater="template-projects".*?<\/section>/s', $page->getContent(), $projectsSection);
        $this->assertNotEmpty($projectsSection[0]);
        foreach (['PROJECT OWNER (CLIENT) AND/ OR MAIN CONTRACTOR', 'LOCATION OF PROJECT', 'GOV&#039;T?', '(Y/N)', 'SCOPE OF WORK', '(VALIDATED AND OBSERVED)', 'START DATE', 'TARGET COMPLETION DATE', '% COMPLETED?'] as $label) {
            $this->assertStringContainsString($label, $projectsSection[0]);
        }
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $projectsSection[0], $projectRows);
        $this->assertSame(3, substr_count($projectRows[1], 'data-repeater-row'));

        preg_match_all('/<section class="business-report-section business-contractor-validation-section">(.*?)<\/section>/s', $page->getContent(), $validationSections);
        $this->assertGreaterThanOrEqual(2, count($validationSections[0]));
        $this->assertStringContainsString('OBSERVATIONS DURING BUSINESS INSPECTION:', $validationSections[0][0]);
        $this->assertSame(5, substr_count($validationSections[0][0], 'name="template_data[questions]'));
        $this->assertStringNotContainsString('<textarea', $validationSections[0][0]);
        $this->assertStringContainsString('For Additional Validation:', $validationSections[0][1]);
        $this->assertSame(4, substr_count($validationSections[0][1], 'name="template_data[questions]'));

        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $page->getContent(), $suppliersSection);
        foreach (['Supplier Validation - Especially Suppliers for Main materials or services required', 'SUPPLIER NAME', 'OFFICE LOCATION', 'CONFIRMED', '(Y/N)', 'IMPORTANT REMARKS', 'CONTACT INFORMATION, YEARS TRANSACTING, BAD / GOOD PAYMENT PERFORMANCE, ETC.'] as $label) {
            $this->assertStringContainsString($label, $suppliersSection[0]);
        }
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $suppliersSection[0], $supplierRows);
        $this->assertSame(3, substr_count($supplierRows[1], 'data-repeater-row'));
        $this->assertTrue(strpos($page->getContent(), 'OBSERVATIONS DURING BUSINESS INSPECTION:') < strpos($page->getContent(), 'data-repeater="template-suppliers"'));
        $this->assertTrue(strpos($page->getContent(), 'data-repeater="template-suppliers"') < strpos($page->getContent(), 'For Additional Validation:'));

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Prime Contractor',
            'business_name' => 'Prime Contractor',
            'report_category' => 'Contractor',
            'template_data' => [
                'fields' => [
                    'projects_declared' => '3',
                    'projects_inspected' => '1',
                    'projects_not_inspected' => '2',
                    'reason_not_inspected' => 'Remote sites',
                ],
                'tables' => [
                    'projects' => [
                        ['project_owner' => 'Main Client', 'location' => 'Project Site', 'government' => 'N', 'scope_of_work' => 'Structural works', 'percent_completed' => '45'],
                        [],
                        [],
                    ],
                    'suppliers' => [
                        ['supplier_name' => 'Construction Supply', 'office_location' => 'Warehouse District', 'confirmed' => 'Y', 'payment_performance' => 'Good payment record'],
                        [],
                        [],
                    ],
                ],
                'questions' => ['Backhoe and grader', 'Owned', '', 'Ongoing', '20 workers', 'Yes', 'Yes', '', 'Main Bank'],
            ],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.projects'));
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.suppliers'));
        $this->assertSame('Remote sites', data_get($source->businessReport->template_data, 'fields.reason_not_inspected'));
        $this->assertSame('Main Bank', data_get($source->businessReport->template_data, 'questions.8'));

        $savedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section[^>]+data-repeater="template-projects".*?<\/section>/s', $savedPage->getContent(), $savedProjectsSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedProjectsSection[0], $savedProjectRows);
        $this->assertSame(1, substr_count($savedProjectRows[1], 'data-repeater-row'));
        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $savedPage->getContent(), $savedSuppliersSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedSuppliersSection[0], $savedSupplierRows);
        $this->assertSame(1, substr_count($savedSupplierRows[1], 'data-repeater-row'));
        $this->assertTrue(str_contains($savedPage->getContent(), 'value="Main Bank"'));
    }

    public function test_restaurant_food_stall_matches_the_excel_branch_and_observation_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('restaurant_food_stall');

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        $content = $page->getContent();
        $this->assertStringContainsString('business-restaurant-scale', $content);
        foreach (['SCALE OF BUSINESS:', 'RESTAURANT', 'CARENDERIA', 'CAFETERIA', 'STALL', '/ MALL OPERATIONS:', 'STALL ONLY'] as $label) {
            $this->assertStringContainsString($label, $content);
        }

        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $content, $branchesSection);
        $this->assertNotEmpty($branchesSection[0]);
        foreach (['LOCATION', 'FRONT (SQM)', 'TOTAL SQM', 'AIRCON (Y/N)', 'OPERATING DAYS &amp; HOURS', '# OF SHIFTS', '# OF EMPLOYEES PER SHIFT', 'AVE. PHP SALES PER SHIFT', 'INVENTORY LEVEL', '(HIGH, MID, LOW)', 'RENT PER MONTH', 'YEARS IN THE AREA', 'IN-STORE DINING CAPACITY', '(# OF TABLES &amp; CHAIRS)'] as $label) {
            $this->assertStringContainsString($label, $branchesSection[0]);
        }
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $branchesSection[0], $branchRows);
        $this->assertSame(3, substr_count($branchRows[1], 'data-repeater-row'));

        preg_match('/<section class="business-report-section business-restaurant-observations">(.*?)<\/section>/s', $content, $observationsSection);
        $this->assertNotEmpty($observationsSection[0]);
        $this->assertStringContainsString('OBSERVATIONS DURING BUSINESS INSPECTION:', $observationsSection[0]);
        $this->assertSame(8, substr_count($observationsSection[0], 'name="template_data[questions]'));
        $this->assertStringNotContainsString('<textarea', $observationsSection[0]);
        foreach (['EQUIPMENT SEEN ONSITE?', 'HOW MANY WORKERS WERE ON SITE?', 'INVENTORY LEVEL?', 'DO THEY HAVE DELIVERY', 'HOW MUCH IS THEIR MENU?', 'TARGET MARKET BASED ON LOCATION &amp; PRICE POINT?', 'WHERE DO THEY SOURCE INGREDIENTS/SUPPLY FOR FOOD AND DRINKS?', 'BANK DECLARED SHOWING BUSINESS INCOME?'] as $label) {
            $this->assertStringContainsString($label, $observationsSection[0]);
        }

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Main Restaurant',
            'business_name' => 'Main Restaurant',
            'report_category' => 'Restaurant',
            'template_data' => [
                'fields' => [
                    'scale_of_business' => 'Mall - Restaurant',
                    'total_declared' => '3',
                    'total_inspected' => '1',
                    'total_not_inspected' => '2',
                    'reason_not_inspected' => 'Closed branches',
                ],
                'tables' => [
                    'branches' => [
                        ['location' => 'Central Mall', 'inventory_level' => 'High', 'dining_capacity' => '20 tables'],
                        [],
                        [],
                    ],
                ],
                'questions' => ['Refrigerators and ovens', '12 workers', 'High', 'Third-party app', 'Meals from 150', 'Office employees', 'Local market', 'Main Bank'],
            ],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertCount(1, data_get($source->businessReport->template_data, 'tables.branches'));
        $this->assertSame('Mall - Restaurant', data_get($source->businessReport->template_data, 'fields.scale_of_business'));
        $this->assertSame('Main Bank', data_get($source->businessReport->template_data, 'questions.7'));

        $savedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section[^>]+data-repeater="template-branches".*?<\/section>/s', $savedPage->getContent(), $savedBranchesSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedBranchesSection[0], $savedBranchRows);
        $this->assertSame(1, substr_count($savedBranchRows[1], 'data-repeater-row'));
        $savedPage->assertSee('value="Mall - Restaurant" checked', false)->assertSee('value="Main Bank"', false);
    }

    public function test_corn_and_sugarcane_farming_templates_match_the_excel_tables(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        foreach (['farming_corn', 'farming_sugarcane'] as $templateType) {
            [, , $source] = $this->createSource($templateType, $ci, $folder);
            $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
            $content = $page->getContent();

            foreach (['INDUSTRY RESEARCH/SURVEY', 'AS OF DATE', 'SEEDS COST PER HA', 'FERTILIZER COST PER HA', 'CROP CYCLE TERM (MONTHS)', 'PEAK HARVEST MONTHS', 'TOTAL HA PLANTED', 'TOTAL SITES/AREAS', 'TOTAL SITES VALIDATED', 'TOTAL SITES NOT INSPECTED', 'REASON NOT INSPECTED'] as $label) {
                $this->assertStringContainsString($label, $content);
            }

            preg_match('/<section[^>]+data-repeater="template-farms".*?<\/section>/s', $content, $farmsSection);
            foreach (['LOCATION &amp; SIZE OF LAND (HA)', 'TOTAL HA', 'OWNED/RENTED/PRENDA', 'IF RENTED, ANNUAL RENT PER HA', 'IF PRENDA, AMOUNT PER HA', 'IF PRENDA/RENT, EXPIRY DATE', 'TARGET HARVEST MONTH', 'RELEVANT INFORMATION', 'EX. IF OWNED - NOT TRANSFERRED'] as $label) {
                $this->assertStringContainsString($label, $farmsSection[0]);
            }
            preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $farmsSection[0], $farmRows);
            $this->assertSame(5, substr_count($farmRows[1], 'data-repeater-row'));

            preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $content, $suppliersSection);
            foreach (['SUPPLIERS:', 'BUSINESS NAME/CONTACT PERSON', 'ADDRESS', 'YEARS TRANSACTING', 'PAYMENT PERFORMANCE / OTHER REMARKS', 'SEEDS SUPPLIER', 'FERTILIZER SUPPLIER', 'TRUCKING PROVIDER (IF ANY)'] as $label) {
                $this->assertStringContainsString($label, $suppliersSection[0]);
            }
            preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $suppliersSection[0], $supplierRows);
            $this->assertSame(3, substr_count($supplierRows[1], 'business-report-static-cell'));

            if ($templateType === 'farming_corn') {
                $this->assertStringContainsString('AVE. SELLING PRICE PER KILO', $content);
                $this->assertStringContainsString('AVE. KILOS YIELD PER HA', $content);
                preg_match('/<section[^>]+data-repeater="template-buyers".*?<\/section>/s', $content, $validationSection);
                $this->assertStringContainsString('CORN BUYERS', $validationSection[0]);
                $this->assertStringContainsString('PRODUCTION PERFORMANCE IN LAST HARVEST / OTHER REMARKS', $validationSection[0]);
            } else {
                $this->assertStringContainsString('AVE. SELLING PRICE PER LKG', $content);
                $this->assertStringContainsString('AVE. TONNES YIELD PER HA', $content);
                $this->assertStringContainsString('RATOON CYCLE', $farmsSection[0]);
                preg_match('/<section[^>]+data-repeater="template-sugarmills".*?<\/section>/s', $content, $validationSection);
                $this->assertStringContainsString('value="BUSCO"', $validationSection[0]);
                $this->assertStringContainsString('value="CRYSTAL"', $validationSection[0]);
            }
            preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $validationSection[0], $validationRows);
            $this->assertSame(2, substr_count($validationRows[1], 'data-repeater-row'));
        }

        $cornSource = $folder->incomeSources()->whereHas('template', fn ($query) => $query->where('template_type', 'farming_corn'))->firstOrFail();
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $cornSource]), [
            'intent' => 'stay',
            'source_name' => 'Corn Farm',
            'business_name' => 'Corn Farm',
            'report_category' => 'Farming',
            'template_data' => [
                'fields' => [
                    'average_selling_price' => '42.50',
                    'seed_cost_per_ha' => '12500',
                    'fertilizer_cost_per_ha' => '18000',
                    'average_yield_per_ha' => '6500',
                    'total_ha_planted' => '25',
                    'reason_not_inspected' => 'Remote site',
                ],
                'tables' => [
                    'farms' => [['location_size' => 'North Field - 25 HA'], [], [], [], []],
                    'suppliers' => [
                        ['supplier_category' => 'SEEDS SUPPLIER'],
                        ['supplier_category' => 'FERTILIZER SUPPLIER', 'supplier_name' => 'Farm Inputs Co.'],
                        ['supplier_category' => 'TRUCKING PROVIDER (IF ANY)'],
                    ],
                    'buyers' => [['buyer' => 'Corn Buyer'], []],
                ],
            ],
        ])->assertSessionHasNoErrors();

        $cornSource->refresh();
        $this->assertCount(1, data_get($cornSource->businessReport->template_data, 'tables.farms'));
        $this->assertCount(1, data_get($cornSource->businessReport->template_data, 'tables.suppliers'));
        $this->assertCount(1, data_get($cornSource->businessReport->template_data, 'tables.buyers'));
        $this->assertSame('FERTILIZER SUPPLIER', data_get($cornSource->businessReport->template_data, 'tables.suppliers.0.supplier_category'));

        $savedPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $cornSource]))->assertOk();
        preg_match('/<section[^>]+data-repeater="template-suppliers".*?<\/section>/s', $savedPage->getContent(), $savedSupplierSection);
        preg_match('/<tbody data-repeater-rows>(.*?)<\/tbody>/s', $savedSupplierSection[0], $savedSupplierRows);
        $this->assertSame(3, substr_count($savedSupplierRows[1], 'data-repeater-row'));
        $this->assertStringContainsString('value="Farm Inputs Co."', $savedSupplierRows[1]);
    }

    public function test_corn_farming_requires_the_four_approved_production_fields_before_save(): void
    {
        [$ci, $folder, $source] = $this->createSource('farming_corn');
        $requiredFields = [
            'average_selling_price' => '42.50',
            'seed_cost_per_ha' => '12500',
            'fertilizer_cost_per_ha' => '18000',
            'average_yield_per_ha' => '6500',
        ];

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        foreach (array_keys($requiredFields) as $fieldKey) {
            $this->assertMatchesRegularExpression('/name="template_data\[fields\]\['.$fieldKey.'\]"[^>]*required/', $page->getContent());
        }
        $this->assertDoesNotMatchRegularExpression('/name="template_data\[fields\]\[peak_harvest_months\]"[^>]*required/', $page->getContent());

        $payload = [
            'intent' => 'stay',
            'source_name' => 'Required Corn Farm',
            'business_name' => 'Required Corn Farm',
            'report_category' => 'Agriculture',
        ];
        $originalRevision = $source->revision;
        foreach (array_keys($requiredFields) as $missingField) {
            $fields = $requiredFields;
            unset($fields[$missingField]);
            $this->actingAs($ci)
                ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload + ['template_data' => ['fields' => $fields]])
                ->assertSessionHasErrors('template_data.fields.'.$missingField);
            $this->assertSame($originalRevision, $source->refresh()->revision);
        }

        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload + ['template_data' => ['fields' => $requiredFields]])
            ->assertSessionHasNoErrors();
        $source->refresh();
        foreach ($requiredFields as $fieldKey => $value) {
            $this->assertSame($value, data_get($source->businessReport->template_data, 'fields.'.$fieldKey));
        }
    }

    public function test_sugarcane_farming_requires_the_four_approved_production_fields_before_save(): void
    {
        [$ci, $folder, $source] = $this->createSource('farming_sugarcane');
        $requiredFields = [
            'seed_cost_per_ha' => '14500',
            'fertilizer_cost_per_ha' => '21000',
            'average_yield_per_ha' => '78',
            'crop_cycle_months' => '12',
        ];

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        foreach (array_keys($requiredFields) as $fieldKey) {
            $this->assertSame(1, preg_match('/name="template_data\[fields\]\['.$fieldKey.'\]"[^>]*required/', $page->getContent()), $fieldKey.' should be required.');
        }
        $this->assertSame(array_keys($requiredFields), config('business-report-templates.farming_sugarcane.schema.required_fields'));

        $payload = [
            'intent' => 'stay',
            'source_name' => 'Required Sugarcane Farm',
            'business_name' => 'Required Sugarcane Farm',
            'report_category' => 'Agriculture',
        ];
        $originalRevision = $source->revision;
        foreach (array_keys($requiredFields) as $missingField) {
            $fields = $requiredFields;
            unset($fields[$missingField]);
            $this->actingAs($ci)
                ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload + ['template_data' => ['fields' => $fields]])
                ->assertSessionHasErrors('template_data.fields.'.$missingField);
            $this->assertSame($originalRevision, $source->refresh()->revision);
        }

        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload + ['template_data' => ['fields' => $requiredFields]])
            ->assertSessionHasNoErrors();
        $source->refresh();
        foreach ($requiredFields as $fieldKey => $value) {
            $this->assertSame($value, data_get($source->businessReport->template_data, 'fields.'.$fieldKey));
        }
    }

    public function test_remittance_template_matches_the_excel_question_layout(): void
    {
        [$ci, $folder, $source] = $this->createSource('remittance_income');

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section class="business-report-section business-remittance-questions".*?<\/section>/s', $page->getContent(), $questionSection);
        $this->assertNotEmpty($questionSection[0]);
        $this->assertSame(10, substr_count($questionSection[0], 'business-remittance-question-row'));
        $this->assertSame(10, substr_count($questionSection[0], 'name="template_data[questions]'));
        $this->assertStringNotContainsString('<textarea', $questionSection[0]);
        foreach (['NAME OF THE PERSON REMITTING THE FUNDS', 'ADDRESS/ LOCATION OF THE PERSON REMITTING FUNDS', 'RELATIONSHIP OF REMITTER TO THE APPLICANT?', 'NATURE OF WORK/SOURCE OF INCOME OF REMITTER?', 'HOW OFTEN DOES REMITTER SEND FUNDS?', 'WHICH BANK CAN THE REMITTANCE BE SEEN?', 'CONTRACT SUBMITTED WITH SALARY AND EMPLOYER INFO?', 'HOW MUCH MONTHLY REMITTANCE IS RECEIVED', 'REMITTANCE CASH FLOW IS NOT STABLE?', 'WHEN DID APPLICANT START RECEIVING REMITTANCES:'] as $label) {
            $this->assertStringContainsString($label, $questionSection[0]);
        }

        $answers = ['Juan Remitter - 09170000000', 'Singapore', 'Brother', 'Engineer', 'Monthly', 'Main Bank', 'Yes', '50,000', 'None', 'January 2020'];
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => 'Family Remittance',
            'business_name' => 'Family Remittance',
            'report_category' => 'Remittance',
            'template_data' => ['questions' => $answers],
        ])->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame($answers, data_get($source->businessReport->template_data, 'questions'));
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Juan Remitter - 09170000000"', false)
            ->assertSee('value="January 2020"', false);
    }

    public function test_remittance_relationship_to_applicant_is_required_before_save(): void
    {
        [$ci, $folder, $source] = $this->createSource('remittance_income');
        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk();
        preg_match('/<section class="business-report-section business-remittance-questions".*?<\/section>/s', $page->getContent(), $questionSection);
        $this->assertMatchesRegularExpression('/name="template_data\[questions\]\[2\]"[^>]*required/', $questionSection[0]);
        $this->assertDoesNotMatchRegularExpression('/name="template_data\[questions\]\[1\]"[^>]*required/', $questionSection[0]);
        $this->assertStringContainsString('RELATIONSHIP OF REMITTER TO THE APPLICANT? <span class="text-danger"', $questionSection[0]);

        $payload = [
            'intent' => 'stay',
            'source_name' => 'Required Remittance',
            'business_name' => 'Required Remittance',
            'report_category' => 'Remittance',
        ];
        $originalRevision = $source->revision;
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload + ['template_data' => ['questions' => ['Remitter', 'Singapore', '']]])
            ->assertSessionHasErrors('template_data.questions.2');
        $this->assertSame($originalRevision, $source->refresh()->revision);

        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload + ['template_data' => ['questions' => ['Remitter', 'Singapore', 'Sibling']]])
            ->assertSessionHasNoErrors();
        $source->refresh();
        $this->assertSame('Sibling', data_get($source->businessReport->template_data, 'questions.2'));
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Sibling" required', false);
    }

    public function test_forged_child_ids_are_rejected_and_template_is_immutable(): void
    {
        [$ci, $folder, $source] = $this->createSource('general_income_sources');
        [, , $other] = $this->createSource('general_income_sources', $ci, $folder);
        $foreignItem = $other->generalReport->declaredItems()->create(['source_name' => 'Other', 'contribution_rank' => 1]);
        $originalTemplate = $source->income_source_template_id;
        $payload = $this->generalPayload();
        $payload['income_source_template_id'] = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->value('id');
        $payload['items'][0]['id'] = $foreignItem->id;

        $this->actingAs($ci)->put(route('client-folders.income-sources.general.update', [$folder, $source]), $payload)->assertSessionHasErrors('items.0.id');
        $this->assertSame($originalTemplate, $source->refresh()->income_source_template_id);
    }

    public function test_deletion_is_blocked_for_referenced_source_and_safe_source_is_soft_deleted(): void
    {
        [$ci, $folder, $source] = $this->createSource('general_income_sources');
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $source->id]);
        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $source]))->assertSessionHasErrors('income_source');
        $this->assertNotSoftDeleted($source);

        [, , $safe] = $this->createSource('general_income_sources', $ci, $folder);
        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $safe]))->assertRedirect(route('client-folders.income-sources.index', $folder));
        $this->assertSoftDeleted($safe);
    }

    public function test_folder_completion_requires_every_active_source_to_be_complete_and_recalculates_progress(): void
    {
        [$ci, $folder, $complete] = $this->createSource('general_income_sources');
        $this->actingAs($ci)->put(route('client-folders.income-sources.general.update', [$folder, $complete]), $this->generalPayload());
        $rule = $folder->completionResults()->whereHas('rule', fn ($query) => $query->where('code', 'income_sources'))->firstOrFail();
        $this->assertTrue($rule->is_satisfied);
        $this->assertEquals(16.67, $folder->refresh()->progress_percent);

        [, , $draft] = $this->createSource('general_income_sources', $ci, $folder);
        $this->assertFalse($rule->fresh()->is_satisfied);
        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $draft]));
        $this->assertTrue($rule->fresh()->is_satisfied);
    }

    public function test_overview_links_to_real_module_and_audit_metadata_does_not_store_report_narrative(): void
    {
        [$ci, $folder, $source] = $this->createSource('general_income_sources');
        $payload = $this->generalPayload();
        $payload['general_remarks'] = 'PRIVATE NARRATIVE VALUE';
        $this->actingAs($ci)->put(route('client-folders.income-sources.general.update', [$folder, $source]), $payload);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee(route('client-folders.income-sources.index', $folder));
        $audit = AuditLog::where('action', 'general_income_source_report.updated')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('PRIVATE NARRATIVE VALUE', json_encode($audit->metadata));
    }

    private function createSource(string $templateType, ?User $ci = null, ?ClientFolder $folder = null): array
    {
        $ci ??= User::factory()->create();
        $folder ??= ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', $templateType)->firstOrFail();
        if ($template->is_fallback) {
            $this->app->make(CreateIncomeSource::class)->execute($ci, $folder, ['income_source_template_id' => $template->id, 'source_name' => 'Income Source', 'business_name' => 'Sample Business']);
        } else {
            $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $template->id, 'source_name' => 'Income Source', 'business_name' => 'Sample Business']);
        }

        return [$ci, $folder, $folder->incomeSources()->latest('id')->firstOrFail()];
    }

    private function generalPayload(): array
    {
        return ['intent' => 'complete', 'source_name' => 'Pension Validation', 'applicant_name_snapshot' => 'DELA CRUZ, JUAN', 'branch_name' => 'Main Branch', 'amount_applied' => 100000, 'account_officer_name' => 'Account Officer', 'general_remarks' => 'Validated', 'items' => [['source_name' => 'Pension', 'source_type' => 'Pension', 'amount_contribution' => 25000, 'contribution_rank' => 1, 'remarks' => 'Confirmed']]];
    }

    private function businessPayload(): array
    {
        return ['intent' => 'complete', 'source_name' => 'Apartment Rentals', 'business_name' => 'Sample Apartments', 'report_category' => 'Leasing', 'main_business_address' => 'Main Street', 'registered_owner' => 'Juan Dela Cruz', 'is_primary' => true, 'properties' => [['property_type' => 'Apartment', 'is_declared' => true, 'is_inspected' => true, 'location' => 'Main Street', 'units_available' => 4, 'units_with_tenants' => 3]], 'tenants' => []];
    }
}
