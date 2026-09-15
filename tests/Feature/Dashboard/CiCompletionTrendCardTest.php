<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ClientFolderStatus;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Dashboard's CI Completion Trend card: a bar chart of completed investigations with a
 * "Completed this period" summary, a readable empty state when the whole period is zero, and
 * whole-number axis ticks. Presentation only - completion counts still come from folders with
 * status Completed and a completed_at inside the window. Browser visual QA is still required.
 */
class CiCompletionTrendCardTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', config('cims.display_timezone')));
        $this->ci = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_bars_are_grouped_from_the_same_authoritative_counts_for_every_range(): void
    {
        $this->completed(now());
        $this->completed(now());
        $this->completed(now()->subDays(2));
        $this->completed(now()->subDays(20));
        $this->completed(now()->subMonths(5));
        $this->folder(ClientFolderStatus::OnProgress); // never counted
        ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'status' => ClientFolderStatus::Completed, 'completed_at' => null]);

        $trends = $this->actingAs($this->ci)->get(route('home'))->assertOk()->viewData('trends');

        // 7 Days: one bar per day, day labels, total equals the bars.
        $week = $trends['7d'];
        $this->assertCount(7, $week['bars']);
        $this->assertSame(3, $week['total']);
        $this->assertSame($week['total'], array_sum(array_column($week['bars'], 'value')));
        $this->assertSame(array_column($week['points'], 'value'), array_column($week['bars'], 'value'));
        $this->assertSame(['Sep 9', 'Sep 15'], [$week['bars'][0]['label'], $week['bars'][6]['label']]);
        $this->assertSame(['9', '15'], [$week['bars'][0]['short'], $week['bars'][6]['short']]);
        $this->assertSame('Last 7 days', $week['period_label']);

        // 30 Days: daily points summed into week-long bars (7,7,7,7,2) - same total.
        $month = $trends['30d'];
        $this->assertCount(30, $month['points']);
        $this->assertCount(5, $month['bars']);
        $this->assertSame(4, $month['total']);
        $this->assertSame($month['total'], array_sum(array_column($month['bars'], 'value')));
        $this->assertSame(['Aug 17–23', 'Aug 24–30', 'Aug 31–Sep 6', 'Sep 7–13', 'Sep 14–15'], array_column($month['bars'], 'label'));
        $this->assertSame([0, 1, 0, 1, 2], array_column($month['bars'], 'value'));

        // 12 Months: one bar per month with short labels and a full-month tooltip.
        $year = $trends['12m'];
        $this->assertCount(12, $year['bars']);
        $this->assertSame(5, $year['total']);
        $this->assertSame($year['total'], array_sum(array_column($year['bars'], 'value')));
        $this->assertSame(['Oct', 'Sep'], [$year['bars'][0]['label'], $year['bars'][11]['label']]);
        $this->assertSame('September 2026', $year['bars'][11]['tooltip']);
    }

    public function test_the_card_renders_the_summary_bar_chart_integer_ticks_and_plural_tooltips(): void
    {
        $this->completed(now());
        $this->completed(now());
        $this->completed(now());
        $this->completed(now()->subDay());

        $html = $this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent();
        $panel = $this->panel($html, '7d');

        $this->assertStringContainsString('CI Completion Trend', $html);
        $this->assertStringContainsString('Completed investigations over time', $html);
        $this->assertMatchesRegularExpression('/data-trend-total>4<\/span>\s*<span[^>]*>Completed this period<\/span>/', $panel);
        $this->assertStringContainsString('data-trend-chart', $panel);
        $this->assertStringNotContainsString('data-trend-empty', $panel);
        $this->assertSame(7, substr_count($panel, 'data-trend-bar '));
        $this->assertStringNotContainsString('<polyline', $panel, 'No line chart remains.');

        // max 3 -> ticks 3,2,1,0: whole numbers only.
        preg_match_all('/translate-y-1\/2 text-xs tabular-nums leading-none text-text-subtle" style="bottom: [^"]+">([^<]+)<\/span>/', $panel, $ticks);
        $this->assertSame(['3', '2', '1', '0'], $ticks[1]);

        // Tooltips use natural singular/plural wording.
        $this->assertStringContainsString('3 completed investigations', $panel);
        $this->assertStringContainsString('1 completed investigation<', $panel);
        $this->assertStringContainsString('0 completed investigations', $panel);
        $this->assertStringContainsString('role="tooltip"', $panel);
    }

    public function test_an_all_zero_period_shows_the_empty_state_instead_of_a_flat_chart(): void
    {
        $this->completed(now()->subDays(20)); // inside 30 days only

        $html = $this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent();
        $week = $this->panel($html, '7d');
        $month = $this->panel($html, '30d');

        $this->assertStringContainsString('data-trend-empty', $week);
        $this->assertStringContainsString('No completed investigations in this period.', $week);
        $this->assertStringContainsString('Completed investigations will appear here once available.', $week);
        $this->assertMatchesRegularExpression('/data-trend-total>0<\/span>\s*<span[^>]*>Completed this period<\/span>/', $week);
        $this->assertStringNotContainsString('data-trend-chart', $week);
        $this->assertStringNotContainsString('data-trend-bar', $week);

        $this->assertStringContainsString('data-trend-chart', $month);
        $this->assertStringNotContainsString('data-trend-empty', $month);

        // The segmented control stays available in the empty state.
        $this->assertStringContainsString('data-trend-tabs', $html);
        foreach (['7 Days', '30 Days', '12 Months'] as $label) {
            $this->assertStringContainsString($label.'</a>', $html);
        }
    }

    public function test_the_scale_adapts_with_whole_number_steps(): void
    {
        foreach (range(1, 23) as $i) {
            $this->completed(now());
        }

        $panel = $this->panel($this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent(), '7d');
        preg_match_all('/translate-y-1\/2 text-xs tabular-nums leading-none text-text-subtle" style="bottom: [^"]+">([^<]+)<\/span>/', $panel, $ticks);

        $this->assertSame(['25', '20', '15', '10', '5', '0'], $ticks[1]);
        foreach ($ticks[1] as $tick) {
            $this->assertMatchesRegularExpression('/^\d+$/', $tick);
        }
    }

    public function test_the_other_dashboard_cards_still_render(): void
    {
        $this->completed(now());

        $response = $this->actingAs($this->ci)->get(route('home'))->assertOk();

        $response->assertSee('Workload by Status')->assertSee('CI Activity Progress');
        $this->assertArrayHasKey('workload', $response->viewData('summary') + ['workload' => $response->viewData('workload')]);
        $this->assertNotNull($response->viewData('activityProgress'));
    }

    public function test_trend_and_activity_progress_use_accessible_transform_only_entry_animations(): void
    {
        $this->completed(now());

        $html = $this->actingAs($this->ci)->get(route('home'))->assertOk()->getContent();
        $css = file_get_contents(resource_path('css/app.css'));
        $javascript = file_get_contents(resource_path('js/app.js'));
        $dashboardView = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $this->assertStringContainsString('data-trend-bar-fill', $this->panel($html, '7d'));
        $this->assertStringContainsString('data-activity-progress-list', $html);
        $this->assertStringContainsString('data-activity-progress-fill', $html);
        $this->assertStringContainsString("width: {{ \$bar['percent'] }}%", $dashboardView, 'The authoritative percentage width remains unchanged.');

        $animationStart = strpos($css, '/* Dashboard analytics entry animations.');
        $animationEnd = strpos($css, '/* End Dashboard analytics entry animations. */');
        $animationCss = substr($css, $animationStart, $animationEnd - $animationStart);
        $this->assertStringContainsString('transform: scaleY(0)', $animationCss);
        $this->assertStringContainsString('transform-origin: bottom center', $animationCss);
        $this->assertStringContainsString('transform: scaleX(0)', $animationCss);
        $this->assertStringContainsString('transform-origin: left center', $animationCss);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $animationCss);
        $this->assertStringNotContainsString('transition: width', $animationCss);
        $this->assertStringNotContainsString('transition: height', $animationCss);

        $this->assertStringContainsString("document.querySelectorAll('[data-trend-panel]:not([hidden])').forEach(replayDashboardTrendAnimation);", $javascript);
        $this->assertStringContainsString("if (name === 'activity-progress') replayDashboardProgressAnimation(current);", $javascript);
        $this->assertStringContainsString('replayDashboardTrendAnimation(selected);', $javascript);
    }

    private function completed(Carbon $at): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'status' => ClientFolderStatus::Completed, 'completed_at' => $at]);
    }

    private function folder(ClientFolderStatus $status): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'status' => $status]);
    }

    private function panel(string $html, string $range): string
    {
        $start = strpos($html, 'data-trend-panel="'.$range.'"');
        $this->assertNotFalse($start);
        $end = strpos($html, 'data-trend-panel=', $start + 20);
        if ($end === false) {
            $end = strpos($html, '</article>', $start);
        }

        return substr($html, $start, $end - $start);
    }
}
