<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
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

        $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertRedirect(route('client-folders.income-sources.create', $folder));
        $this->actingAs($admin)->get(route('client-folders.income-sources.index', $folder))->assertRedirect(route('client-folders.income-sources.create', $folder));
        $this->actingAs($other)->get(route('client-folders.income-sources.index', $folder))->assertForbidden();
    }

    public function test_business_entry_route_opens_the_primary_dedicated_encoding_form_instead_of_the_summary(): void
    {
        [$ci, $folder, $first] = $this->createSource('leasing_non_agricultural');
        [, , $primary] = $this->createSource('retail_grocery_water_refilling', $ci, $folder);
        $primary->update(['is_primary' => true]);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', $folder))
            ->assertRedirect(route('client-folders.income-sources.edit', [$folder, $primary]));

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $primary]))
            ->assertOk()
            ->assertSee('data-business-encoding-layout', false)
            ->assertSee('data-business-report-form', false)
            ->assertDontSee('Business / Income Sources</h1>', false);

        $this->assertNotSame($first->id, $primary->id);
    }

    public function test_create_route_provisions_and_opens_the_business_report_without_the_template_selector(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.create', $folder));
        $source = $folder->incomeSources()->with('businessReport')->sole();

        $response->assertRedirect(route('client-folders.income-sources.edit', [$folder, $source]));
        $this->assertNotNull($source->businessReport);
        $this->assertSame('', $source->source_name);
        $this->assertSame('', $source->businessReport->business_name);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('data-business-encoding-layout', false)
            ->assertSee('data-business-report-form', false)
            ->assertDontSee('Add Income Source')
            ->assertDontSee('Select the Official Form')
            ->assertDontSee('Source Identity')
            ->assertDontSee('Create and Open Form');

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.create', $folder))
            ->assertRedirect(route('client-folders.income-sources.edit', [$folder, $source]));

        $this->assertCount(1, $folder->incomeSources()->get());
    }

    public function test_add_business_creates_an_independent_blank_report_and_selector_opens_each_business(): void
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
            ->assertSee('data-business-selector', false)
            ->assertSee('Add Business')
            ->assertSee('Sample Apartments')
            ->assertSee('Second Business')
            ->assertSee(route('client-folders.income-sources.edit', [$folder, $first]), false)
            ->assertSee(route('client-folders.income-sources.edit', [$folder, $second]), false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-business-report-form', false);
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

        $this->actingAs($ci)->get(route('client-folders.income-sources.select-template', $folder))->assertOk()->assertDontSee('Hidden Template')->assertSee('SOURCES OF INCOME DECLARED BY CLIENT');
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $inactive->id, 'source_name' => 'Hidden'])->assertSessionHasErrors('income_source_template_id');
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => 999999, 'source_name' => 'Forged'])->assertSessionHasErrors('income_source_template_id');
    }

    public function test_client_can_have_multiple_sources_with_the_correct_detail_record(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $leasing = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $fallback = IncomeSourceTemplate::where('is_fallback', true)->firstOrFail();

        foreach ([[$leasing, 'Apartment Rentals'], [$fallback, 'Freelance and Pension']] as [$template, $name]) {
            $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $template->id, 'source_name' => $name])->assertRedirect();
        }

        $this->assertCount(2, $folder->incomeSources);
        $this->assertDatabaseCount('business_reports', 1);
        $this->assertDatabaseCount('general_income_source_reports', 1);
    }

    public function test_dedicated_template_renders_only_compatible_sections_and_saves_normalized_rows(): void
    {
        [$ci, $folder, $source] = $this->createSource('leasing_non_agricultural');

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('data-business-encoding-layout', false)
            ->assertSee('data-business-report-form', false)
            ->assertSee('CREDIT INVESTIGATION REPORT')
            ->assertSee('SOURCE OF INCOME VALIDATION')
            ->assertSee('LEASING OPERATIONS: NON-AGRICULTURAL REAL ESTATE')
            ->assertSee('Properties')
            ->assertSee('Tenants')
            ->assertDontSee('Products')
            ->assertDontSee('data-app-shell', false)
            ->assertDontSee('<aside', false)
            ->assertDontSee('<nav', false)
            ->assertDontSee('breadcrumb', false);

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->businessPayload())->assertRedirect();
        $source->refresh();
        $this->assertSame(RecordState::Complete, $source->state);
        $this->assertDatabaseHas('business_properties', ['business_report_id' => $source->businessReport->id, 'property_type' => 'Apartment']);
        $this->assertSame(1, $source->businessReport->properties_declared);
        $this->assertDatabaseMissing('business_products', ['business_report_id' => $source->businessReport->id]);
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
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $retail]))
            ->assertOk()
            ->assertSee('RETAIL: GROCERY STORE / SUPERMARKET / SARI-SARI STORE / WATER REFILLING')
            ->assertSee('Summary of Branches Inspected')
            ->assertSee('Products')
            ->assertSee('Business Observations')
            ->assertSee('Suppliers')
            ->assertDontSee('Properties');

        $template = IncomeSourceTemplate::where('template_type', 'general_income_sources')->firstOrFail();
        for ($i = 0; $i < 12; $i++) {
            $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => "Source $i"]);
        }
        DB::enableQueryLog();
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk();
        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
        DB::disableQueryLog();
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
        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $safe]))->assertRedirect(route('client-folders.income-sources.manage', $folder));
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
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $template->id, 'source_name' => 'Income Source', 'business_name' => 'Sample Business']);

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
