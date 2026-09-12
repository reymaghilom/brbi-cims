<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Presentation-only guard for the Global CI Activities KPI cards.
 *
 * The five cards became links to the tabs they already counted. This pins that they point at the
 * exact URL the matching tab link builds, that they still read from the one authoritative $counts
 * array rather than a second query, that the card matching the open tab is marked current, and
 * that the interaction/active styling stays ordered so active outranks hover. Activity filtering,
 * completion, target semantics and person isolation are covered by GlobalCiActivitiesTest and are
 * only asserted here to prove this change did not disturb them.
 */
class GlobalCiActivitiesKpiCardTest extends TestCase
{
    use RefreshDatabase;

    /** Label => tab key, in the order the cards are rendered. */
    private const CARDS = [
        'Due Today' => 'due_today',
        'Scheduled' => 'scheduled',
        'Follow-up' => 'follow_up',
        'Completed' => 'completed',
        'All Activities' => 'all',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', config('cims.display_timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, array $overrides = []): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'status' => ActivityStatus::Pending,
        ], $overrides));
    }

    private function css(): string
    {
        return file_get_contents(base_path('resources/css/app.css'));
    }

    public function test_each_kpi_card_links_to_the_exact_url_of_the_tab_it_counts(): void
    {
        $ci = User::factory()->create();
        $this->folderFor($ci);

        $html = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()->getContent();

        foreach (self::CARDS as $label => $tab) {
            $url = e(route('ci-activities.index', ['tab' => $tab]));
            // The card is a real anchor carrying the KPI interaction class and the tab's own URL.
            $this->assertMatchesRegularExpression(
                '/<a\s[^>]*href="'.preg_quote($url, '/').'"[^>]*class="[^"]*\bui-kpi-card\b/s',
                $html,
                $label.' must be a link to its own tab.'
            );
        }

        // No second filtering system was introduced: the tab strip is untouched and still there.
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertSame(count(self::CARDS), substr_count($html, 'role="tab"'));
    }

    public function test_a_kpi_card_carries_every_active_filter_exactly_as_the_tab_link_does(): void
    {
        $ci = User::factory()->create();
        $this->folderFor($ci);

        $query = ['status' => 'scheduled', 'person' => 'applicant', 'schedule' => 'today', 'sort' => 'client_name', 'page' => 3];
        $html = $this->actingAs($ci)->get(route('ci-activities.index', $query))->assertOk()->getContent();

        foreach (self::CARDS as $label => $tab) {
            // Same URL the tab builds: every filter rides along, only the page resets.
            $expected = e(route('ci-activities.index', array_merge($query, ['tab' => $tab, 'page' => null])));
            $this->assertStringContainsString('href="'.$expected.'"', $html, $label.' must preserve the active filters.');
            $this->assertStringNotContainsString('page=3&amp;tab='.$tab, $html);
        }
    }

    public function test_the_card_matching_the_open_tab_is_the_only_one_marked_current(): void
    {
        $ci = User::factory()->create();
        $this->folderFor($ci);

        foreach (self::CARDS as $label => $tab) {
            $html = $this->actingAs($ci)->get(route('ci-activities.index', ['tab' => $tab]))->assertOk()->getContent();

            // Exactly one KPI card is current, and it is this tab's card. Counted inside the KPI
            // grid only — aria-current also legitimately marks the active sidebar nav link.
            $this->assertSame(1, substr_count($this->kpiGrid($html), 'aria-current="page"'), $label.' must be the only current card.');
            $this->assertMatchesRegularExpression(
                '/<a\s[^>]*href="'.preg_quote(e(route('ci-activities.index', ['tab' => $tab])), '/').'"[^>]*aria-current="page"/s',
                $html,
                $label.' must be the card marked current for tab '.$tab.'.'
            );
            // The tab strip still marks the same tab selected — the two never disagree.
            $this->assertStringContainsString('aria-selected="true"', $html);
        }
    }

    public function test_kpi_counts_still_come_from_the_one_authoritative_counts_array(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $timezone = config('cims.display_timezone');

        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-01 15:00:00', $timezone)->utc(),
        ]);
        $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => Carbon::parse('2026-09-05 15:00:00', $timezone)->utc(),
        ]);
        $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE, ['status' => ActivityStatus::FollowUp]);
        $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, ['status' => ActivityStatus::Completed]);

        $response = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk();
        $counts = $response->viewData('counts');
        $html = $response->getContent();

        // The card's number is the same $counts entry the tab badge shows — one source, no
        // duplicate query, and no number massaged to suit the design.
        foreach (self::CARDS as $label => $tab) {
            $this->assertArrayHasKey($tab, $counts, $label.' must read an existing count.');
            $card = $this->cardMarkup($html, $tab);
            $this->assertStringContainsString('>'.$counts[$tab].'</p>', $card, $label.' must show its own count.');
        }

        $this->assertSame(1, $counts['due_today']);
        $this->assertSame(2, $counts['scheduled']);
        $this->assertSame(1, $counts['follow_up']);
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(4, $counts['all']);
    }

    public function test_a_zero_count_card_stays_a_working_link_to_its_own_empty_tab(): void
    {
        $ci = User::factory()->create();
        $this->folderFor($ci);

        $response = $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk();
        $this->assertSame(0, $response->viewData('counts')['all']);

        // A zero card is not disabled and not hidden — its tab is simply empty, exactly as before.
        $card = $this->cardMarkup($response->getContent(), 'completed');
        $this->assertStringContainsString('>0</p>', $card);
        $this->assertStringContainsString('ui-kpi-card', $card);

        $this->actingAs($ci)->get(route('ci-activities.index', ['tab' => 'completed']))->assertOk();
    }

    public function test_the_kpi_card_styling_is_professional_and_active_outranks_hover(): void
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

    public function test_a_summary_card_without_a_url_is_still_the_plain_non_interactive_section(): void
    {
        $rendered = $this->blade('<x-ui.summary-card label="Plain" :value="7" />');

        $this->assertStringContainsString('<section', $rendered);
        $this->assertStringNotContainsString('ui-kpi-card', $rendered);
        $this->assertStringNotContainsString('href=', $rendered);
        $this->assertStringNotContainsString('aria-current', $rendered);
        $this->assertStringContainsString('aria-label="Plain"', $rendered);
    }

    public function test_filters_tabs_and_person_isolation_are_untouched_by_the_card_change(): void
    {
        $ci = User::factory()->create();
        // The listing renders each row's definition name, so the two sides are told apart by their
        // own folder instead — which is also the stronger isolation assertion.
        $applicantFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => 'APPLICANTSIDE FOLDER']);
        $coMakerFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => 'COMAKERSIDE FOLDER']);
        $coMaker = CoMaker::create(['client_folder_id' => $coMakerFolder->id, 'full_name' => 'Exact Co Maker']);

        $this->activity($applicantFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->activity($coMakerFolder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $applicant = $this->actingAs($ci)->get(route('ci-activities.index', ['person' => 'applicant']))->assertOk()->getContent();
        $this->assertStringContainsString('APPLICANTSIDE FOLDER', $applicant);
        $this->assertStringNotContainsString('COMAKERSIDE FOLDER', $applicant);

        $coMakerOnly = $this->actingAs($ci)->get(route('ci-activities.index', ['person' => 'co_maker']))->assertOk()->getContent();
        $this->assertStringContainsString('COMAKERSIDE FOLDER', $coMakerOnly);
        $this->assertStringContainsString('Exact Co Maker', $coMakerOnly);
        $this->assertStringNotContainsString('APPLICANTSIDE FOLDER', $coMakerOnly);

        // The toolbar the tabs and filters live in is exactly as it was.
        $this->assertStringContainsString('id="global-ci-filter"', $applicant);
        $this->assertStringContainsString('Clear Filters', $applicant);
        $this->assertStringContainsString('data-ci-activities-listing', $applicant);
    }

    /** Just the KPI grid, so page chrome (sidebar, breadcrumb) cannot pollute an assertion. */
    private function kpiGrid(string $html): string
    {
        $start = strpos($html, 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5');
        $this->assertNotFalse($start, 'The KPI grid is missing.');

        return substr($html, $start, strpos($html, '<section class="mt-6', $start) - $start);
    }

    /** The rendered markup of one KPI card, from its anchor up to the end of that element. */
    private function cardMarkup(string $html, string $tab): string
    {
        $needle = 'href="'.e(route('ci-activities.index', ['tab' => $tab])).'"';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, 'No KPI card links to tab '.$tab.'.');
        $start = strrpos(substr($html, 0, $start), '<a');

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }
}
