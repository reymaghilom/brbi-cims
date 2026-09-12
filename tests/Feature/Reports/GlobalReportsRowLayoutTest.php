<?php

namespace Tests\Feature\Reports;

use App\Enums\RecordState;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guard for the original Global Reports rows/columns presentation.
 *
 * The listing was briefly given a whole-row click target with pointer/hover/accent styling; that
 * was reverted because the plain table was preferred. This pins the restored markup — the bare
 * <tr>, the bare mobile <li>, the seven-column structure and the explicit per-row actions — so the
 * row-level polish cannot be reintroduced by accident. The KPI cards above the listing are a
 * separate concern and keep their interaction (see GlobalReportsKpiCardTest).
 */
class GlobalReportsRowLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name,
        ]);
    }

    private function table(string $html): string
    {
        return substr($html, 0, strpos($html, 'data-report-card'));
    }

    private function cards(string $html): string
    {
        return substr($html, strpos($html, 'data-report-card'));
    }

    public function test_the_listing_renders_the_original_plain_row_and_card_markup(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $response = $this->actingAs($ci)->get(route('reports.index'))->assertOk();
        $html = $response->getContent();
        $rows = $response->viewData('items')->count();

        // The desktop row is a bare <tr> and the mobile item a bare <li> — no interaction hooks.
        // +1 for the <thead> row.
        $this->assertSame($rows + 1, substr_count($this->table($html), '<tr>'));
        $this->assertSame($rows, substr_count($html, '<li class="p-4" data-report-card>'));

        foreach ([
            'data-report-open-url', 'data-report-row', 'class="reports-row"', 'reports-card',
        ] as $removed) {
            $this->assertStringNotContainsString($removed, $html, $removed.' is row-level polish that was reverted.');
        }
    }

    public function test_no_row_level_interaction_styling_or_script_survives(): void
    {
        $css = file_get_contents(base_path('resources/css/app.css'));
        $js = file_get_contents(base_path('resources/js/app.js'));

        foreach (['.reports-row', '.reports-card'] as $selector) {
            $this->assertStringNotContainsString($selector, $css);
        }
        $this->assertStringNotContainsString('data-report-open-url', $js);

        // The shared table treatment every other listing uses is the one still in force here.
        $this->assertStringContainsString('.ui-table tbody tr { @apply transition hover:bg-surface-muted; }', $css);

        // ...while the KPI cards keep their own interaction, which this revert must not touch.
        $this->assertStringContainsString('.ui-kpi-card { @apply block w-full cursor-pointer', $css);
        $this->assertStringContainsString("const card = event.target.closest('[data-reports-kpi]');", $js);
    }

    public function test_the_desktop_table_keeps_its_original_column_structure(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $table = $this->table($this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent());

        // #, the five sortable columns, then a right-aligned Actions column.
        $this->assertSame(7, substr_count($table, '<th scope="col"'));
        foreach (['client' => 'Client', 'client_type' => 'Client Type', 'report_type' => 'Report Type', 'status' => 'Status', 'updated' => 'Last Updated'] as $key => $label) {
            $this->assertStringContainsString('data-reports-sort="'.$key.'"', $table);
            $this->assertStringContainsString('>'.$label.'<', $table);
        }
        $this->assertStringContainsString('<th scope="col" class="text-right">Actions</th>', $table);
        // The Actions cell keeps its w-px/no-wrap sizing and right-aligned action group.
        $this->assertStringContainsString('<td class="w-px whitespace-nowrap">', $table);
        $this->assertStringContainsString('flex flex-nowrap items-center justify-end gap-1.5', $table);
        // Table above lg, cards below it — the original responsive split.
        $this->assertStringContainsString('hidden overflow-x-auto lg:block', $table);
    }

    public function test_the_explicit_per_row_actions_still_render_for_pending_and_completed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');

        // Pending: the explicit Create action plus the folder shortcut, and no preview/download.
        $pending = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'pending']))->assertOk()->getContent();
        $this->assertStringContainsString('Create Report', $pending);
        $this->assertStringContainsString('aria-label="Open Client Folder"', $pending);
        $this->assertStringNotContainsString('aria-label="Preview Report"', $pending);

        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete, 'completed_at' => now(),
        ]);

        // Completed: the three explicit icon actions, on both the table and the card.
        $completed = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'completed']))->assertOk()->getContent();
        foreach (['Preview Report', 'Download report', 'Open Client Folder'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $this->table($completed));
            $this->assertStringContainsString('aria-label="'.$label.'"', $this->cards($completed));
        }
        $this->assertStringContainsString('>Completed<', $completed);
    }

    public function test_report_context_and_the_filter_toolbar_are_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co Maker']);
        $template = IncomeSourceTemplate::query()->where('is_fallback', false)->firstOrFail();
        $business = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'business_name' => 'FIRST BUSINESS',
        ]);

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();

        // Applicant rows open the plain folder URL; the Co-Maker's carry that exact person.
        $this->assertStringContainsString('href="'.e(route('client-folders.show', $folder->id)).'"', $html);
        $this->assertStringContainsString(
            'href="'.e(route('client-folders.show', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id])).'"',
            $html
        );
        // The Business Report action still names its exact income source.
        $this->assertStringContainsString(
            'data-business-report-url="'.e(route('client-folders.income-sources.edit', [$folder->id, $business->id])).'"',
            $html
        );

        // Tabs, search, auto-applying filters and Clear Filters are untouched by this revert.
        $this->assertStringContainsString('data-reports-tabs', $html);
        $this->assertStringContainsString('data-reports-filters', $html);
        $this->assertStringContainsString('data-reports-client-search', $html);
        $this->assertStringContainsString('data-reports-auto-filter', $html);
        $this->assertSame(1, substr_count($html, 'Clear Filters</a>'));
        // ...and the KPI cards above are still the interactive ones.
        $this->assertSame(4, substr_count($html, 'ui-kpi-card'));
    }
}
