<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Presentation-only coverage for four surfaces that had drifted from the project's own conventions:
 * the Reports table/card typography, the Saved Businesses status wording, the Business Report action
 * bar (which had no Cancel and an ambiguous one-word label), and the Add Business modal footer.
 *
 * Nothing here touches state, completion, duplicate or revision logic — these assertions exist so a
 * later edit cannot quietly undo the wording or the shared button/icon convention.
 */
class BusinessReportAndReportsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------------
    // Reports typography
    // ---------------------------------------------------------------------

    public function test_report_type_reads_at_the_same_size_and_weight_as_client_type_and_last_updated(): void
    {
        $row = file_get_contents(resource_path('views/reports/_row.blade.php'));

        // Report Type pill: same text-sm as the row's own scale, and explicitly not emphasised.
        $this->assertStringContainsString('bg-surface-muted px-2.5 py-1 text-sm font-normal text-text-main', $row);
        $this->assertStringNotContainsString('bg-surface-muted px-2.5 py-1 text-xs font-normal', $row, 'Report Type must not sit a size below the columns beside it.');

        // Client Type and Last Updated carry no size or weight class of their own, so they inherit
        // the table's text-sm at normal weight — which is exactly what Report Type now matches.
        $this->assertStringContainsString('<span class="inline-flex items-center gap-1.5 whitespace-nowrap text-text-muted"', $row);
        $this->assertStringContainsString('<td class="whitespace-nowrap text-text-muted">', $row);

        $table = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.ui-table { @apply min-w-full divide-y divide-ui-border text-left text-sm; }', $table);
    }

    public function test_the_client_name_keeps_the_bold_weight_every_primary_table_column_uses(): void
    {
        $row = file_get_contents(resource_path('views/reports/_row.blade.php'));
        $card = file_get_contents(resource_path('views/reports/_card.blade.php'));

        $this->assertStringContainsString('class="text-sm font-bold text-text-main hover:text-brand-primary hover:underline"', $row);
        $this->assertStringContainsString('text-sm font-bold text-text-main hover:text-brand-primary hover:underline', $card);
        $this->assertStringNotContainsString('font-medium', $row, 'The reduced weight is gone from the row.');
        $this->assertStringNotContainsString('font-medium', $card, 'The reduced weight is gone from the card.');

        // The same weight the Saved Businesses table gives its own primary column.
        $this->assertStringContainsString(
            'font-bold text-text-main',
            file_get_contents(resource_path('views/client-folders/income-sources/partials/saved-businesses-panel-body.blade.php')),
        );
    }

    // ---------------------------------------------------------------------
    // Saved Businesses status wording
    // ---------------------------------------------------------------------

    public function test_a_saved_business_report_row_reads_complete(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('text-xs font-bold text-success">Complete</span>', $html);
        $this->assertStringNotContainsString('text-xs font-bold text-success">Saved</span>', $html);

        // Wording only — the record behind it is untouched.
        $business->refresh();
        $this->assertSame(2, $business->revision);
        $this->assertNotNull($business->businessReport);
    }

    // ---------------------------------------------------------------------
    // Business Report action bar
    // ---------------------------------------------------------------------

    public function test_an_existing_business_report_offers_cancel_and_update_business_report_with_icons(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $business]))->assertOk()->getContent();

        $this->assertStringContainsString('Update Business Report', $html);
        $this->assertStringNotContainsString('Save Business Report', $html, 'An existing report never offers the create label.');

        $this->assertCancelButtonMatchesTheBusinessCheckConvention($html);
        $this->assertPrimaryButtonCarriesTheSaveIcon($html);
    }

    public function test_a_new_business_report_offers_cancel_and_save_business_report_with_icons(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        $html = $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Save Business Report', $html);
        $this->assertStringNotContainsString('Update Business Report', $html, 'A new report never offers the edit label.');
        // The retired one-word labels are gone from both modes.
        $this->assertStringNotContainsString('data-business-save>Save</button>', $html);
        $this->assertStringNotContainsString('data-business-save>Update</button>', $html);

        $this->assertCancelButtonMatchesTheBusinessCheckConvention($html);
        $this->assertPrimaryButtonCarriesTheSaveIcon($html);
    }

    public function test_the_business_report_submit_keeps_its_exact_intent_wiring(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $business = $this->savedBusiness($folder, 'ALPHA TRADING');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $business]))->assertOk()->getContent();

        // Unchanged submit contract: same target form, same intent name/value, same hook.
        $this->assertStringContainsString('name="intent" value="complete" class="ui-button-primary" data-business-save>', $html);
    }

    // ---------------------------------------------------------------------
    // Add Business modal footer
    // ---------------------------------------------------------------------

    public function test_the_add_business_modal_footer_buttons_carry_icons(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-modal-close><svg[^>]*>.*?<\/svg>\s*Cancel<\/button>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-primary" data-add-business-next>Next<svg[^>]*>.*?<\/svg>\s*<\/button>/s',
            $html,
        );

        // The duplicate-template plumbing around them is untouched.
        $this->assertStringContainsString('data-add-business-template-select', $html);
        $this->assertStringContainsString('data-used-template-ids=', $html);
        $this->assertStringContainsString('data-duplicate-template-message=', $html);
        $this->assertStringContainsString('data-add-business-template-error', $html);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** The same secondary Cancel the Edit Business Check form uses: class, icon size and hook. */
    private function assertCancelButtonMatchesTheBusinessCheckConvention(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-close-parent-dialog><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*Cancel<\/button>/s',
            $html,
        );

        $check = file_get_contents(resource_path('views/client-folders/business-checks/form.blade.php'));
        $this->assertStringContainsString(
            '<button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>',
            $check,
            'The convention being mirrored still lives on the Business Check form.',
        );
    }

    private function assertPrimaryButtonCarriesTheSaveIcon(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/class="ui-button-primary" data-business-save><svg[^>]*class="[^"]*size-4[^"]*"[^>]*>.*?<\/svg>\s*(Save|Update) Business Report<\/button>/s',
            $html,
        );
    }

    private function savedBusiness(ClientFolder $folder, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
            'state' => RecordState::Complete,
            'completed_at' => now(),
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'report_category' => 'Retail',
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }
}
