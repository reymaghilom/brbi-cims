<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardDataContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_public_dashboard_view_data_contract_is_stable(): void
    {
        $data = app(DashboardData::class)->for(User::factory()->create());

        $this->assertSame([
            'today',
            'summary',
            'workload',
            'needsAttention',
            'kpiDetails',
            'trends',
            'trend',
            'trendRange',
            'trendRanges',
            'activityProgress',
            'workToday',
            'recentActivity',
            'recentActivityHasMore',
            'recentActivityAll',
        ], array_values(array_filter(array_keys($data), fn (string $key): bool => $key !== 'greeting')));
        $this->assertArrayNotHasKey('greeting', $data, 'The visible greeting is owned by the application layout.');

        $this->assertSame([
            'assigned',
            'in_progress',
            'needs_attention',
            'needs_attention_items',
            'completed_this_month',
            'reports_ready',
        ], array_keys($data['summary']));
        $this->assertSame(['total', 'segments'], array_keys($data['workload']));
        $this->assertSame(['not_started', 'in_progress', 'completed'], collect($data['workload']['segments'])->pluck('key')->all());
        $this->assertSame(['active', 'in_progress', 'completed_this_month', 'reports_ready'], array_keys($data['kpiDetails']));
        $this->assertSame(['7d', '30d', '12m'], array_keys($data['trends']));
        $this->assertSame(['7d', '30d', '12m'], array_keys($data['trendRanges']));
        $this->assertSame(DashboardData::DEFAULT_TREND_RANGE, $data['trendRange']);
        $this->assertSame($data['trends']['7d'], $data['trend']);
        $this->assertCount(4, $data['activityProgress']);
        foreach ($data['activityProgress'] as $progress) {
            $this->assertSame(['label', 'completed', 'applicable', 'unit', 'percent'], array_keys($progress));
        }
        $this->assertInstanceOf(LengthAwarePaginator::class, $data['workToday']);
        $this->assertSame(5, $data['workToday']->perPage());
        $this->assertIsArray($data['needsAttention']);
        $this->assertIsArray($data['recentActivity']);
        $this->assertIsBool($data['recentActivityHasMore']);
        $this->assertIsArray($data['recentActivityAll']);
    }

    public function test_the_visible_greeting_is_owned_by_the_application_layout(): void
    {
        $user = User::factory()->create(['full_name' => 'Layout Greeting Owner']);

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        $hour = now(config('cims.display_timezone'))->hour;
        $greeting = match (true) {
            $hour >= 5 && $hour < 12 => 'Good Morning',
            $hour >= 12 && $hour < 18 => 'Good Afternoon',
            default => 'Good Evening',
        };

        $response->assertSee($greeting.',')
            ->assertSee('Layout Greeting Owner');
        $this->assertStringContainsString('$greeting = match (true)', file_get_contents(resource_path('views/layouts/app.blade.php')));
        $this->assertStringNotContainsString('$greeting', file_get_contents(resource_path('views/dashboard/index.blade.php')));
    }

    public function test_dashboard_analytics_keep_the_exact_animation_contract(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $trend = file_get_contents(resource_path('views/dashboard/_trend-chart.blade.php'));
        $dashboard = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $start = strpos($css, '/* Dashboard analytics entry animations.');
        $end = strpos($css, '/* End Dashboard analytics entry animations. */');
        $animationCss = substr($css, $start, $end - $start);

        $this->assertSame(2, substr_count($animationCss, '750ms cubic-bezier(0.22, 1, 0.36, 1)'));
        $this->assertStringContainsString('transform: scaleY(0)', $animationCss);
        $this->assertStringContainsString('transform: scaleX(0)', $animationCss);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $animationCss);
        $this->assertStringContainsString('transform: none !important', $animationCss);
        $this->assertStringNotContainsString('transition: width', $animationCss);
        $this->assertStringNotContainsString('transition: height', $animationCss);
        $this->assertStringContainsString('--dashboard-entry-delay: {{ $index * 60 }}ms', $trend);
        $this->assertStringContainsString('--dashboard-entry-delay: {{ $loop->index * 70 }}ms', $dashboard);
    }

    /**
     * Clock: 2026-01-04 16:30 UTC = 2026-01-05 00:30 Asia/Manila, so the local day and the UTC day
     * differ and the 12-month window rolls back across a year boundary.
     */
    public function test_trend_ranges_keep_local_inclusive_boundaries_utc_conversion_and_year_rollover(): void
    {
        config(['cims.display_timezone' => 'Asia/Manila']);
        Carbon::setTestNow(Carbon::parse('2026-01-04 16:30:00', 'UTC'));
        $manila = fn (string $local): CarbonImmutable => CarbonImmutable::parse($local, 'Asia/Manila')->utc();

        foreach ([
            '2025-12-30 00:00:00', // 7 Days first boundary (inclusive)
            '2025-12-29 23:59:59', // just before the 7 Days window
            '2026-01-05 00:00:00', // 2026-01-04 16:00 UTC: today locally, yesterday in UTC
            '2026-01-04 23:59:59', // yesterday locally
            '2025-12-07 00:00:00', // 30 Days first boundary (inclusive)
            '2025-12-06 23:59:59', // just before the 30 Days window
            '2025-02-01 00:00:00', // 12 Months first boundary (inclusive)
            '2025-01-31 23:59:59', // just before the 12 Months window
        ] as $index => $local) {
            $this->completedFolder('TREND '.$index.', CLIENT', ClientFolderStatus::Completed, $manila($local));
        }
        // Not a stored Completed status: never counted, whatever completed_at says.
        $this->completedFolder('NOT COMPLETED, CLIENT', ClientFolderStatus::OnProgress, $manila('2026-01-05 00:10:00'));

        $trends = app(DashboardData::class)->for(User::factory()->create())['trends'];
        $values = fn (array $trend): array => collect($trend['points'])->pluck('value', 'label')->filter()->all();

        $this->assertCount(7, $trends['7d']['points']);
        $this->assertSame('Dec 30', $trends['7d']['points'][0]['label']);
        $this->assertSame('Jan 5', $trends['7d']['points'][6]['label']);
        $this->assertSame(['Dec 30' => 1, 'Jan 4' => 1, 'Jan 5' => 1], $values($trends['7d']));
        $this->assertSame(3, $trends['7d']['total']);
        $this->assertSame(1, $trends['7d']['max']);
        $this->assertSame('Last 7 days', $trends['7d']['period_label']);

        $this->assertCount(30, $trends['30d']['points']);
        $this->assertSame('Dec 7', $trends['30d']['points'][0]['label']);
        $this->assertSame(['Dec 7' => 1, 'Dec 29' => 1, 'Dec 30' => 1, 'Jan 4' => 1, 'Jan 5' => 1], $values($trends['30d']));
        $this->assertSame(5, $trends['30d']['total']);
        $this->assertSame(5, collect($trends['30d']['bars'])->sum('value'));
        $this->assertSame('Last 30 days', $trends['30d']['period_label']);

        $this->assertCount(12, $trends['12m']['points']);
        $this->assertSame('Feb 2025', $trends['12m']['points'][0]['label']);
        $this->assertSame('Jan 2026', $trends['12m']['points'][11]['label']);
        $this->assertSame(['Feb 2025' => 1, 'Dec 2025' => 4, 'Jan 2026' => 2], $values($trends['12m']));
        $this->assertSame(7, $trends['12m']['total']);
        $this->assertSame(4, $trends['12m']['max']);
        $this->assertSame('February 2025', $trends['12m']['bars'][0]['tooltip']);
        $this->assertSame('January 2026', $trends['12m']['bars'][11]['tooltip']);
        $this->assertSame('Last 12 months', $trends['12m']['period_label']);
    }

    public function test_empty_trend_ranges_still_render_every_bucket_with_a_chart_maximum_of_one(): void
    {
        $trends = app(DashboardData::class)->for(User::factory()->create())['trends'];

        foreach (['7d' => 7, '30d' => 30, '12m' => 12] as $range => $buckets) {
            $this->assertCount($buckets, $trends[$range]['points']);
            $this->assertSame(0, $trends[$range]['total']);
            $this->assertSame(1, $trends[$range]['max']);
            $this->assertSame(0, collect($trends[$range]['bars'])->sum('value'));
        }
    }

    public function test_my_work_today_breaks_equal_priority_ties_by_schedule_then_oldest_update(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 02:00:00', 'UTC'));
        $ci = User::factory()->create();
        $tomorrowAt = fn (string $time): Carbon => Carbon::parse('2026-09-16 '.$time, config('cims.display_timezone'))->utc();

        $scheduledLateNewest = $this->openActivity($ci, ActivityStatus::Scheduled, $tomorrowAt('10:00'), '2026-09-14 09:00:00');
        $scheduledEarly = $this->openActivity($ci, ActivityStatus::Scheduled, $tomorrowAt('09:00'), '2026-09-14 12:00:00');
        $scheduledLateOldest = $this->openActivity($ci, ActivityStatus::Scheduled, $tomorrowAt('10:00'), '2026-09-14 08:00:00');
        $pendingNewest = $this->openActivity($ci, ActivityStatus::Pending, null, '2026-09-14 07:00:00');
        $pendingOldest = $this->openActivity($ci, ActivityStatus::Pending, null, '2026-09-14 06:00:00');

        $workToday = app(DashboardData::class)->for($ci)['workToday'];

        $this->assertSame(
            [$scheduledEarly->id, $scheduledLateOldest->id, $scheduledLateNewest->id, $pendingOldest->id, $pendingNewest->id],
            collect($workToday->items())->pluck('id')->all(),
        );
    }

    private function completedFolder(string $name, ClientFolderStatus $status, CarbonImmutable $completedAtUtc): void
    {
        $folder = ClientFolder::factory()->create(['display_name' => $name, 'status' => $status]);
        DB::table('client_folders')->where('id', $folder->id)->update(['completed_at' => $completedAtUtc->format('Y-m-d H:i:s')]);
    }

    private function openActivity(User $creator, ActivityStatus $status, ?Carbon $scheduledAt, string $updatedAtUtc): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id, 'created_by' => $creator->id]);
        $activity = CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => $scheduledAt !== null,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
        DB::table('ci_activities')->where('id', $activity->id)->update(['updated_at' => $updatedAtUtc]);

        return $activity;
    }
}
