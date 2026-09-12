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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Focused guard for the Global Reports KPI cards.
 *
 * The four cards were already links, but they were inert in three provable ways: they sat outside
 * the [data-reports-tabs] element the async click handler is bound to, their href was built from
 * the card's own query alone (so a click threw away every other active filter), and their only
 * hover treatment was `hover:border-brand-primary` on .ui-card — a class with no border-width, so
 * nothing was ever painted. None of them had an active state.
 *
 * This pins the wiring, the URL each card produces, the single active card, and the fact that the
 * counts, the row actions and the person/income-source isolation were not touched.
 */
class GlobalReportsKpiCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-12 09:00:00', config('cims.display_timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name,
        ]);
    }

    private function css(): string
    {
        return file_get_contents(base_path('resources/css/app.css'));
    }

    private function js(): string
    {
        return file_get_contents(base_path('resources/js/app.js'));
    }

    /** The markup of the KPI card whose identity key is $key, anchor tag included. */
    private function card(string $html, string $key): string
    {
        $needle = 'data-reports-kpi-key="'.$key.'"';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'No KPI card carries the key '.$key.'.');
        $start = strrpos(substr($html, 0, $start), '<a');

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }

    public function test_each_kpi_card_is_a_whole_clickable_link_to_its_own_existing_filter(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();

        $expected = [
            'all||' => route('reports.index', ['tab' => 'all']),
            'pending||' => route('reports.index', ['tab' => 'pending']),
            'completed||' => route('reports.index', ['tab' => 'completed']),
            'completed|2026-09-01|2026-09-30' => route('reports.index', ['tab' => 'completed', 'from' => '2026-09-01', 'to' => '2026-09-30']),
        ];

        foreach ($expected as $key => $url) {
            $card = $this->card($html, $key);
            $this->assertStringContainsString('href="'.e($url).'"', $card);
            // The whole card is the link, and it carries the shared KPI interaction class.
            $this->assertStringContainsString('ui-kpi-card', $card);
            $this->assertStringContainsString('data-reports-kpi=', $card);
        }

        // The dead hover rule is gone from the cards: .ui-card carries no border-width, so setting
        // a border colour painted nothing. Scoped to the KPI row — the class is legitimate
        // elsewhere on the page, on elements that do have a border.
        $summaryStart = strpos($html, 'data-reports-summary');
        $summaryRow = substr($html, $summaryStart, strpos($html, 'data-reports-tabs') - $summaryStart);
        $this->assertStringNotContainsString('hover:border-brand-primary', $summaryRow);
        $this->assertSame(4, substr_count($summaryRow, 'ui-kpi-card'));
        // No second filtering system: the tab strip is untouched and still present.
        $this->assertStringContainsString('data-reports-tabs', $html);
        $this->assertSame(3, substr_count($html, 'data-reports-tab='));
    }

    public function test_a_kpi_card_keeps_every_other_active_filter_instead_of_wiping_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');

        $query = [
            'search' => 'ALPHA', 'client_folder_id' => $folder->id, 'report_type' => 'cibi',
            'person' => 'applicant', 'sort' => 'client', 'direction' => 'asc',
            'tab' => 'pending', 'from' => '2026-01-01', 'to' => '2026-01-31',
        ];
        $html = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->getContent();

        $carried = ['search' => 'ALPHA', 'client_folder_id' => $folder->id, 'report_type' => 'cibi', 'person' => 'applicant', 'sort' => 'client', 'direction' => 'asc'];

        // A card owns its tab AND its date range; everything else the user applied rides along.
        $this->assertStringContainsString(
            'href="'.e(route('reports.index', $carried + ['tab' => 'completed'])).'"',
            $this->card($html, 'completed||')
        );
        $this->assertStringContainsString(
            'href="'.e(route('reports.index', $carried + ['tab' => 'completed', 'from' => '2026-09-01', 'to' => '2026-09-30'])).'"',
            $this->card($html, 'completed|2026-09-01|2026-09-30')
        );
        // The stale January range is not smuggled into a card that defines its own range.
        $this->assertStringNotContainsString('from=2026-01-01', $this->card($html, 'all||'));
    }

    public function test_exactly_one_card_is_active_and_it_follows_the_tab_that_is_open(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $views = [
            'all||' => ['tab' => 'all'],
            'pending||' => ['tab' => 'pending'],
            'completed||' => ['tab' => 'completed'],
            'completed|2026-09-01|2026-09-30' => ['tab' => 'completed', 'from' => '2026-09-01', 'to' => '2026-09-30'],
        ];

        foreach ($views as $key => $query) {
            $html = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->getContent();
            $summary = substr($html, strpos($html, 'data-reports-summary'), strpos($html, 'data-reports-tabs') - strpos($html, 'data-reports-summary'));

            $this->assertSame(1, substr_count($summary, 'aria-current="page"'), 'Exactly one KPI card is current for '.$key.'.');
            $this->assertStringContainsString('aria-current="page"', $this->card($html, $key));
            $this->assertStringContainsString('currently showing', $this->card($html, $key));
        }

        // Completed and Completed This Month are the same tab: the date range is what tells the
        // two apart, so opening plain Completed must not light up the month card as well.
        $completed = $this->actingAs($ci)->get(route('reports.index', ['tab' => 'completed']))->assertOk()->getContent();
        $this->assertStringNotContainsString('aria-current="page"', $this->card($completed, 'completed|2026-09-01|2026-09-30'));
    }

    public function test_following_a_kpi_card_actually_changes_the_visible_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete, 'completed_at' => now(),
        ]);

        // Each card's URL yields exactly the rows its tab semantics describe — the KPI and the tab
        // reach the identical view because they build the identical query.
        $pending = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'pending']))->assertOk();
        $completed = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'completed']))->assertOk();

        $this->assertSame(0, $pending->viewData('items')->count());
        $this->assertSame(1, $completed->viewData('items')->count());
        $this->assertTrue($completed->viewData('items')->first()->isCompleted);
    }

    public function test_kpi_counts_still_come_from_the_unfiltered_authoritative_summary(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete, 'completed_at' => now(),
        ]);

        $unfiltered = $this->actingAs($ci)->get(route('reports.index'))->assertOk();
        $summary = $unfiltered->viewData('summary');

        foreach (['total', 'pending', 'completed', 'completed_this_month'] as $metric) {
            $this->assertArrayHasKey($metric, $summary);
        }
        $this->assertSame($summary['total'], $summary['pending'] + $summary['completed']);
        $this->assertGreaterThanOrEqual(1, $summary['completed']);

        // The counts are deliberately unfiltered, so narrowing the view must not move them.
        $filtered = $this->actingAs($ci)->get(route('reports.index', ['tab' => 'pending', 'report_type' => 'cibi']))->assertOk();
        $this->assertSame($summary, $filtered->viewData('summary'));

        // And the number on each card is that same summary entry, not a second query.
        $html = $unfiltered->getContent();
        foreach ([['all||', 'total'], ['pending||', 'pending'], ['completed||', 'completed'], ['completed|2026-09-01|2026-09-30', 'completed_this_month']] as [$key, $metric]) {
            $this->assertStringContainsString('>'.$summary[$metric].'</p>', $this->card($html, $key));
        }
    }

    public function test_a_zero_count_card_stays_a_working_link_exactly_as_before(): void
    {
        $ci = User::factory()->create();

        $response = $this->actingAs($ci)->get(route('reports.index'))->assertOk();
        $this->assertSame(0, $response->viewData('summary')['total']);

        // Current behaviour is that every card is always a link; a zero count does not disable it.
        $card = $this->card($response->getContent(), 'completed||');
        $this->assertStringContainsString('>0</p>', $card);
        $this->assertStringContainsString('href=', $card);
        $this->assertStringNotContainsString('aria-disabled', $card);

        $this->actingAs($ci)->get(route('reports.index', ['tab' => 'completed']))->assertOk();
    }

    public function test_the_cards_carry_the_shared_professional_interaction_and_active_styling(): void
    {
        $css = $this->css();

        // A SOFT HAIRLINE, not an outline ring: the border is present at rest and only ever
        // changes colour, so no state can shift the layout.
        $this->assertStringContainsString('.ui-kpi-card { @apply block w-full cursor-pointer border border-ui-border/70 text-left transition duration-150; }', $css);
        $this->assertStringContainsString('.ui-kpi-card:hover { @apply border-ui-border-strong bg-surface-muted/70 shadow-card-hover; }', $css);
        $this->assertStringContainsString('.ui-kpi-card:active { @apply border-ui-border-strong bg-surface-muted; }', $css);
        $this->assertStringContainsString('.ui-kpi-card[aria-current="page"] { @apply border-brand-primary/35 bg-brand-soft/70 shadow-card-hover; }', $css);

        // The heavy treatment is gone: no tinted ring on hover, no 2px brand frame when active.
        $kpiBlock = substr($css, strpos($css, '.ui-kpi-card { @apply'), 700);
        $this->assertStringNotContainsString('ring-', $kpiBlock, 'The card must not draw an outline ring.');
        $this->assertStringNotContainsString('border-2', $kpiBlock);
        $this->assertStringNotContainsString('shadow-float', $kpiBlock, 'No dramatic shadow.');

        // The hover elevation stays inside the card shadow family rather than importing one.
        $this->assertStringContainsString('--shadow-card-hover: 0 1px 2px rgb(15 45 80 / 0.05), 0 8px 20px rgb(15 45 80 / 0.06);', $css);

        // ACTIVE > HOVER: the active rule is declared after the hover rule, so hover cannot erase it.
        $this->assertGreaterThan(
            strpos($css, '.ui-kpi-card:hover'),
            strpos($css, '.ui-kpi-card[aria-current="page"]'),
            'The active rule must come after the hover rule.'
        );

        // Restrained motion only, and only where motion is welcome.
        $this->assertStringContainsString('translate: 0 -1px;', $kpiBlock);
        $this->assertStringNotContainsString('scale(', $kpiBlock);
        $this->assertStringContainsString('@media (prefers-reduced-motion: no-preference)', $css);
        foreach (['.ui-kpi-card:hover { @apply', '@media (hover: hover) { .ui-kpi-card:hover { translate'] as $needle) {
            $offset = strpos($css, $needle);
            $this->assertNotFalse($offset);
            $this->assertNotFalse(strrpos(substr($css, 0, $offset), '@media (hover: hover)'));
        }

        // Keyboard focus stays the app-wide polished treatment: a low-opacity brand outline with
        // an offset, never suppressed on these cards.
        $this->assertStringContainsString(':focus-visible { outline: 3px solid color-mix(in srgb, var(--color-brand-primary) 35%, transparent); outline-offset: 2px; }', $css);
        $this->assertStringNotContainsString('focus-visible:outline-none', $kpiBlock);

        // .ui-card is untouched, so the Dashboard's own KPI cards keep their existing look.
        $this->assertStringContainsString('.ui-card { @apply rounded-card bg-surface shadow-card; }', $css);
    }

    public function test_the_cards_are_wired_into_the_existing_async_tab_path(): void
    {
        $js = $this->js();

        // The reason they were inert: the click handler is bound to the tabs element, and the
        // cards live in a sibling row. They now get their own delegated listener on that row.
        $this->assertStringContainsString("const summaryRow = document.querySelector('[data-reports-summary]');", $js);
        $this->assertStringContainsString("const card = event.target.closest('[data-reports-kpi]');", $js);

        $handler = substr($js, strpos($js, "summaryRow?.addEventListener('click'"), 1400);
        // It reuses the card's own server-built href — no second query builder in JS.
        $this->assertStringContainsString('new URL(card.href, window.location.origin)', $handler);
        $this->assertStringContainsString("url.searchParams.delete('page')", $handler);
        $this->assertStringContainsString("region.dispatchEvent(new CustomEvent('async-list:load'", $handler);
        // Modified/non-primary clicks stay the browser's own business.
        $this->assertStringContainsString('event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey', $handler);

        // One source of truth for "which view is current": syncTabState drives the KPI state too,
        // so changing the tab (or Back/Forward) re-marks the right card.
        $this->assertStringContainsString('const syncKpiState = (url) => {', $js);
        $syncTab = substr($js, strpos($js, 'const syncTabState = (url) => {'), 400);
        $this->assertStringContainsString('syncKpiState(url);', $syncTab);
        $this->assertLessThan(
            strpos($js, 'const syncTabState = (url) => {'),
            strpos($js, 'const syncKpiState = (url) => {'),
            'syncKpiState must be defined before syncTabState uses it.'
        );
    }

    public function test_the_post_save_summary_fragment_carries_the_same_hooks(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        // A save re-renders the cards on their own; without the hooks they would come back inert.
        $fragment = $this->actingAs($ci)
            ->get(route('reports.index'), ['X-Requested-With' => 'XMLHttpRequest', 'X-Reports-Summary' => '1'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-reports-summary-html', $fragment);
        $this->assertStringContainsString('data-reports-kpi-key="all||"', $fragment);
        $this->assertStringContainsString('ui-kpi-card', $fragment);
        $this->assertStringContainsString('aria-current="page"', $fragment);
    }

    public function test_row_actions_and_person_and_business_isolation_are_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co Maker']);
        $template = IncomeSourceTemplate::query()->where('is_fallback', false)->firstOrFail();
        $business = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'business_name' => 'FIRST BUSINESS',
        ]);

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();

        // Exact person context on the row links, and the exact income source on the row action.
        $this->assertStringContainsString(e(route('client-folders.show', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id])), $html);
        $this->assertStringContainsString(
            'data-business-report-url="'.e(route('client-folders.income-sources.edit', [$folder->id, $business->id])).'"',
            $html
        );
        // The listing keeps its original plain row/card markup — the KPI work never reached it.
        $this->assertStringContainsString('<tr>', $html);
        $this->assertStringContainsString('<li class="p-4" data-report-card>', $html);
        $this->assertStringNotContainsString('data-report-open-url', $html);

        // Search, filters and Clear Filters are exactly as they were.
        $this->assertStringContainsString('data-reports-filters', $html);
        $this->assertStringContainsString('data-reports-auto-filter', $html);
        $this->assertSame(1, substr_count($html, 'Clear Filters</a>'));
    }
}
