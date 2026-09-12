<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Enums\UserRole;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Focused coverage for the rebuilt Credit Investigator dashboard. Everything asserted here is
 * derived from real workflow state: the page must never render an illustrative number, an
 * illustrative client name, or a folder the signed-in investigator is not assigned.
 */
class CreditInvestigatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_dashboard_renders_the_new_composition_without_the_folder_browser(): void
    {
        $ci = User::factory()->create(['full_name' => 'Rey C. Maghilom']);

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();

        foreach (['Active Client Folders', 'In Progress', 'Needs Attention', 'Completed This Month', 'Reports Ready',
            'CI Completion Trend', 'Workload by Status', 'CI Activity Progress', 'My Work Today',
            'Recent Activity', 'Quick Actions'] as $section) {
            $response->assertSee($section);
        }

        // Quick Actions keeps its subtitle, its three shortcuts and their original destinations.
        $response->assertSee('Common tasks to help you get started')
            ->assertSee('New Client Folder')
            ->assertSee('Open Pending CI')
            ->assertSee('View Reports')
            ->assertSee(route('client-folders.index'), false)
            ->assertSee('href="'.route('ci-activities.index', ['status' => 'pending']).'"', false)
            ->assertSee(route('reports.index'), false);

        $response->assertSee('Stay focused. Every investigation helps build better decisions.')
            ->assertDontSee('Stay focused. Every investigation builds a safer community.')
            ->assertDontSee('Stay focused. Every investigation supports sound and responsible decisions.');

        // Recent Activity and CI Activity Progress keep their own natural layout: they sit in
        // different rows at their original spans, with nothing forcing them to equal height.
        $response->assertSee('xl:col-span-3" aria-labelledby="progress-title"', false)
            ->assertSee('xl:col-span-4" aria-labelledby="recent-activity-title"', false)
            ->assertDontSee('dashboard-progress-title', false);

        // The date is rendered in the display timezone; the time-of-day greeting stays in the
        // shared app header (covered by GlobalLayoutTest) rather than being duplicated here.
        $response->assertSee(now(config('cims.display_timezone'))->format('l, F j, Y'));
        $hour = now(config('cims.display_timezone'))->hour;
        $greeting = match (true) {
            $hour >= 5 && $hour < 12 => 'Good Morning',
            $hour >= 12 && $hour < 18 => 'Good Afternoon',
            default => 'Good Evening',
        };
        // The name is its own element now — it carries the stronger weight against the muted
        // greeting label — so the two halves are asserted separately.
        $response->assertSee($greeting.',')->assertSee('Rey C. Maghilom');

        // The folder grid, its selection panel and its search moved back to Client Folders.
        $response->assertDontSee('data-folder-browser-layout', false)
            ->assertDontSee('data-folder-preview-template', false)
            ->assertDontSee('data-client-search-form', false);
    }

    public function test_the_dashboard_contains_no_illustrative_data(): void
    {
        $ci = User::factory()->create();

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();

        foreach (['Juan Dela Cruz', 'Maria Santos', 'Pedro Cruz', 'Anna Reyes', 'Luis Torres'] as $name) {
            $response->assertDontSee($name);
        }

        // With nothing assigned, every KPI is a truthful zero and the sections say so.
        $summary = $response->viewData('summary');
        $this->assertSame([0, 0, 0, 0, 0], [
            $summary['assigned'], $summary['in_progress'], $summary['needs_attention'],
            $summary['completed_this_month'], $summary['reports_ready'],
        ]);
        $response->assertSee('No items need your attention right now.')
            ->assertSee('No client folders yet');
    }

    /**
     * Folder-level metrics describe the shared CI workspace: active folders belong to the team, so a
     * folder carrying another investigator's name still counts here (ClientFolder::isAccessibleBy()).
     */
    public function test_folder_metrics_cover_the_whole_collaborative_workspace(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();

        // Carrying this CI's name: one overdue, one started, one untouched, one completed.
        $overdue = $this->folder($ci, 'ALPHA, CLIENT');
        $this->activity($overdue, ActivityStatus::Scheduled, now()->subDay());
        $started = $this->folder($ci, 'BRAVO, CLIENT');
        $this->activity($started, ActivityStatus::Scheduled, now()->addWeek());
        $untouched = $this->folder($ci, 'CHARLIE, CLIENT');
        $this->activity($untouched, ActivityStatus::Pending);
        $completed = $this->folder($ci, 'DELTA, CLIENT', ClientFolderStatus::Completed);

        // Carries another investigator's name, but it is an active folder anyone on the team may
        // work on, so every folder-level metric must include it.
        $foreign = $this->folder($otherCi, 'ZULU, OTHER CI');
        $this->activity($foreign, ActivityStatus::Scheduled, now()->subDays(2));

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();
        $summary = $response->viewData('summary');
        $workload = $response->viewData('workload');

        $this->assertSame(5, $summary['assigned']);
        $this->assertSame(2, $summary['needs_attention'], 'Both overdue folders count, whoever they are assigned to.');
        // In Progress = folders whose mandatory work has STARTED but is unfinished. None of these
        // five has completed a single mandatory requirement, so none has started.
        $this->assertSame(0, $summary['in_progress']);
        $this->assertSame(1, $summary['completed_this_month']);

        // The four buckets are mutually exclusive and their percentages describe the same total.
        $this->assertSame(5, $workload['total']);
        $this->assertSame(5, collect($workload['segments'])->sum('count'));
        $this->assertSame(100, collect($workload['segments'])->sum('percent'));
        // Progress statuses only. None of the five has met a single mandatory requirement, so all
        // five are Not Started - including the two overdue ones, which stay counted by the
        // Needs Attention KPI above rather than being pulled out of their progress status.
        $this->assertSame(
            ['not_started' => 5, 'in_progress' => 0, 'completed' => 0],
            collect($workload['segments'])->pluck('count', 'key')->all(),
        );

        // The Dashboard and the Client Folders page must never disagree about how many active
        // folders this user may work on.
        $this->assertSame(
            $this->actingAs($ci)->get(route('client-folders.index'))->assertOk()->viewData('clientFolders')->total(),
            $summary['assigned'],
        );
    }

    public function test_completion_trend_counts_only_completions_inside_the_selected_window(): void
    {
        $ci = User::factory()->create();
        $timezone = config('cims.display_timezone');

        $this->folder($ci, 'TODAY, CLIENT', ClientFolderStatus::Completed)->update(['completed_at' => now()]);
        $this->folder($ci, 'YESTERDAY, CLIENT', ClientFolderStatus::Completed)->update(['completed_at' => now()->subDay()]);
        $this->folder($ci, 'OLD, CLIENT', ClientFolderStatus::Completed)->update(['completed_at' => now()->subDays(20)]);

        $sevenDay = $this->actingAs($ci)->get(route('home'))->assertOk()->viewData('trend');
        $this->assertCount(7, $sevenDay['points']);
        $this->assertSame(2, $sevenDay['total'], 'The 20-day-old completion is outside the 7-day window.');
        $this->assertSame(1, collect($sevenDay['points'])->last()['value']);
        $this->assertSame(now($timezone)->format('M j'), collect($sevenDay['points'])->last()['label']);

        $thirtyDay = $this->actingAs($ci)->get(route('home', ['range' => '30d']))->assertOk()->viewData('trend');
        $this->assertCount(30, $thirtyDay['points']);
        $this->assertSame(3, $thirtyDay['total']);

        $yearly = $this->actingAs($ci)->get(route('home', ['range' => '12m']))->assertOk()->viewData('trend');
        $this->assertCount(12, $yearly['points']);
        $this->assertSame(3, $yearly['total']);

        // An unknown range falls back to the default rather than reaching a query.
        $this->assertSame(
            DashboardData::DEFAULT_TREND_RANGE,
            $this->actingAs($ci)->get(route('home', ['range' => 'forever']))->assertOk()->viewData('trendRange'),
        );
    }

    public function test_every_trend_range_is_pre_rendered_so_switching_needs_no_request(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'TODAY, CLIENT', ClientFolderStatus::Completed)->update(['completed_at' => now()]);

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();

        // All three ranges arrive with the first response, so app.js switches by showing an
        // already-rendered chart instead of firing a request and painting a loading state.
        $trends = $response->viewData('trends');
        $this->assertSame(['7d', '30d', '12m'], array_keys($trends));
        $this->assertCount(7, $trends['7d']['points']);
        $this->assertCount(30, $trends['30d']['points']);
        $this->assertCount(12, $trends['12m']['points']);

        // The default range stays the visible one; the other two ship hidden, ready to swap in.
        foreach (['7d', '30d', '12m'] as $range) {
            $response->assertSee('data-trend-panel="'.$range.'"', false)
                ->assertSee('data-trend-tab="'.$range.'"', false);
        }
        $response->assertSee('data-trend-panel="30d" hidden', false)
            ->assertSee('data-trend-panel="12m" hidden', false)
            ->assertSee('data-trend-tabs', false);

        // The three labels and the active-tab styling are unchanged.
        foreach (['7 Days', '30 Days', '12 Months'] as $label) {
            $response->assertSee($label);
        }
        $this->assertSame(DashboardData::DEFAULT_TREND_RANGE, $response->viewData('trendRange'));
    }

    public function test_activity_progress_percentages_use_the_applicable_denominator(): void
    {
        $ci = User::factory()->create();
        $first = $this->folder($ci, 'ECHO, CLIENT');
        $second = $this->folder($ci, 'FOXTROT, CLIENT');

        $first->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'state' => RecordState::Complete]);
        $second->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString(), 'state' => RecordState::Draft]);
        $first->residenceChecks()->create(['ci_date' => now()->toDateString(), 'location' => 'Somewhere', 'ci_user_id' => $ci->id]);

        $this->activity($first, ActivityStatus::Completed);
        $this->activity($second, ActivityStatus::Pending);

        $bars = collect($this->actingAs($ci)->get(route('home'))->assertOk()->viewData('activityProgress'))->keyBy('label');

        // Two folders, no Co-Makers: two required person-level CI/BI Reports, one of them done.
        $this->assertSame(50, $bars['CI/BI Report']['percent'], '1 complete of 2 required CI/BI Reports.');
        $this->assertSame(50, $bars['Residence Check']['percent'], '1 checked of 2 assigned clients.');
        $this->assertSame(17, $bars['CI Activities']['percent'], '1 complete of 6 required Applicant activities.');

        // No business income sources exist, so Business Check has no applicable work and must not
        // be reported as unfinished progress against a denominator it does not have.
        $this->assertSame(0, $bars['Business Check']['applicable']);
        $this->assertSame(0, $bars['Business Check']['percent']);
    }

    public function test_my_work_today_lists_only_open_authorized_work_and_links_to_that_exact_activity(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = $this->folder($ci, 'GOLF, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Golf Co-Maker']);

        $overdue = $this->activity($folder, ActivityStatus::Scheduled, now()->subDays(2));
        $coMakerWork = $this->activity($folder, ActivityStatus::Pending, null, $coMaker->id);
        $this->activity($folder, ActivityStatus::Completed);
        $this->activity($this->folder($otherCi, 'ZULU, OTHER CI'), ActivityStatus::Pending);

        $work = collect($this->actingAs($ci)->get(route('home'))->assertOk()->viewData('workToday')->items());

        $this->assertSame([$overdue->id, $coMakerWork->id], $work->pluck('id')->all(), 'Overdue work is surfaced first; completed and unassigned work is excluded.');
        $this->assertSame('Overdue', $work->first()['status']);

        // Each action targets that activity's authoritative check page, carrying its exact person context.
        $this->assertSame(route('client-folders.activities.default-check.show', [$folder->id, $overdue->id]), $work->first()['url']);
        $this->assertSame(
            route('client-folders.activities.default-check.show', [$folder->id, $coMakerWork->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]),
            $work->last()['url'],
        );
        $this->assertSame($coMaker->full_name, $work->last()['person']);
        $this->assertNull($work->first()['person'], 'Applicant work never borrows a Co-Maker name.');

        // The links are real routes a signed-in CI can actually open.
        $this->actingAs($ci)->get($work->first()['url'])->assertOk();
    }

    /** Recent Activity follows folder access, so team activity on any accessible folder appears. */
    public function test_recent_activity_covers_every_accessible_folder(): void
    {
        $ci = User::factory()->create(['full_name' => 'Recorded Actor']);
        $colleague = User::factory()->create(['full_name' => 'Team Mate']);
        $mine = $this->folder($ci, 'HOTEL, CLIENT');
        $theirs = $this->folder($colleague, 'ZULU, OTHER CI');

        AuditLog::create(['user_id' => $ci->id, 'client_folder_id' => $mine->id, 'action' => 'residence_check.updated', 'module' => 'residence_checks', 'description' => 'Saved.']);
        AuditLog::create(['user_id' => $colleague->id, 'client_folder_id' => $theirs->id, 'action' => 'business_check.updated', 'module' => 'business_checks', 'description' => 'Saved.']);

        $events = collect($this->actingAs($ci)->get(route('home'))->assertOk()->viewData('recentActivity'));

        $this->assertEqualsCanonicalizing(['HOTEL, CLIENT', 'ZULU, OTHER CI'], $events->pluck('client')->all());
        $this->assertSame('Business Check updated', $events->first()['label'], 'Labels reuse the existing audit vocabulary.');
        $this->assertSame('Team Mate', $events->first()['user']);
    }

    public function test_reports_ready_counts_completed_report_records_across_accessible_folders(): void
    {
        $ci = User::factory()->create();
        $mine = $this->folder($ci, 'INDIA, CLIENT');
        $theirs = $this->folder(User::factory()->create(), 'ZULU, OTHER CI');
        $draft = $this->folder($ci, 'JULIET, CLIENT');
        foreach ([[$mine, RecordState::Complete], [$theirs, RecordState::Complete], [$draft, RecordState::Draft]] as [$folder, $state]) {
            CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $ci->id, 'state' => $state]);
        }

        // Generated output history is not a report record: it never adds to the count.
        $this->report($mine, GenerationStatus::Completed);
        $this->report($mine, GenerationStatus::Processing);
        $this->report($theirs, GenerationStatus::Completed);

        // Both completed CI / BI reports sit on accessible folders; the draft never counts.
        $this->assertSame(2, $this->actingAs($ci)->get(route('home'))->assertOk()->viewData('summary')['reports_ready']);
    }

    public function test_every_role_sees_the_same_workspace_folder_total(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $ci = User::factory()->create();
        $this->folder(User::factory()->create(), 'JULIET, CLIENT');
        $this->folder(User::factory()->create(), 'KILO, CLIENT');

        $this->assertSame(2, $this->actingAs($admin)->get(route('home'))->assertOk()->viewData('summary')['assigned']);
        $this->assertSame(2, $this->actingAs($ci)->get(route('home'))->assertOk()->viewData('summary')['assigned']);
    }

    /**
     * The dashboard is aggregate-only: growing the workload must not grow the query count. This is
     * the N+1 guard — the absolute number matters far less than it staying flat.
     */
    public function test_dashboard_queries_do_not_grow_with_the_size_of_the_workload(): void
    {
        $ci = User::factory()->create();
        $measure = function (int $folders) use ($ci): int {
            ClientFolder::query()->delete();
            foreach (range(1, $folders) as $index) {
                $this->activity($this->folder($ci, "LOAD {$index}, CLIENT"), ActivityStatus::Pending);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(DashboardData::class)->for($ci);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $measure(2);
        $large = $measure(12);

        $this->assertSame($small, $large, 'Dashboard query count must not scale with the number of assigned folders.');
        // 21: the Needs Attention detail adds three fixed queries (activities, Bank / Coop targets,
        // Asset targets); the flat count above remains the actual N+1 guard.
        $this->assertLessThanOrEqual(21, $large);
    }

    /**
     * The Needs Attention KPI counts CLIENT FOLDERS holding overdue work, and "overdue" respects how
     * the schedule was recorded: a date-only schedule stores the date at 08:00 local as a reminder
     * default (CiActivity::normalizeScheduleInput), which is not a deadline — so an activity due
     * today must not be flagged during its own due date.
     */
    #[DataProvider('overdueScenarios')]
    public function test_needs_attention_only_counts_genuinely_overdue_work(
        string $nowUtc,
        ?string $scheduledDate,
        ?string $scheduledTime,
        ActivityStatus $status,
        bool $expectedOverdue,
    ): void {
        Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));

        try {
            $ci = User::factory()->create();
            $folder = $this->folder($ci, 'SCHEDULE, CLIENT');
            [$scheduledAt, $hasTime] = CiActivity::normalizeScheduleInput($scheduledDate, $scheduledTime);
            $activity = $this->activity($folder, $status, $scheduledAt);
            $activity->update(['scheduled_has_time' => $hasTime]);

            $response = $this->actingAs($ci)->get(route('home'))->assertOk();

            $this->assertSame($expectedOverdue ? 1 : 0, $response->viewData('summary')['needs_attention']);
            $badge = collect($response->viewData('workToday')->items())->firstWhere('id', $activity->id)['status'] ?? null;
            if ($expectedOverdue) {
                $this->assertSame('Overdue', $badge, 'The work list badge must agree with the KPI.');
            } else {
                $this->assertNotSame('Overdue', $badge, 'Work that is not overdue keeps its own status badge.');
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ActivityStatus, 4: bool}> */
    public static function overdueScenarios(): array
    {
        // 2026-09-04 12:00 UTC is 2026-09-04 8:00 PM in Asia/Manila — mid-evening on the 4th.
        $eveningOfTheFourth = '2026-09-04 12:00:00';

        return [
            'scheduled yesterday, incomplete' => [$eveningOfTheFourth, '2026-09-03', null, ActivityStatus::Scheduled, true],
            'scheduled earlier today with an explicit past time' => [$eveningOfTheFourth, '2026-09-04', '09:00', ActivityStatus::Scheduled, true],
            'scheduled later today with an explicit future time' => [$eveningOfTheFourth, '2026-09-04', '23:00', ActivityStatus::Scheduled, false],
            'scheduled today, date only, after its 8am reminder default' => [$eveningOfTheFourth, '2026-09-04', null, ActivityStatus::Scheduled, false],
            'scheduled tomorrow, date only' => [$eveningOfTheFourth, '2026-09-05', null, ActivityStatus::Scheduled, false],
            'completed even though it is past due' => [$eveningOfTheFourth, '2026-09-01', null, ActivityStatus::Completed, false],
            // 15:59 UTC is 11:59 PM in Manila on the 4th: still the 4th locally, so the 5th is
            // tomorrow and must not be overdue despite the UTC clock nearing the next day.
            'late local evening does not age tomorrow into overdue' => ['2026-09-04 15:59:00', '2026-09-05', null, ActivityStatus::Scheduled, false],
            'late local evening still flags yesterday' => ['2026-09-04 15:59:00', '2026-09-03', null, ActivityStatus::Scheduled, true],
        ];
    }

    public function test_a_folder_with_several_overdue_activities_is_counted_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00', 'UTC'));

        try {
            $ci = User::factory()->create();
            $folder = $this->folder($ci, 'REPEATED, CLIENT');
            [$yesterday] = CiActivity::normalizeScheduleInput('2026-09-03');

            foreach (range(1, 3) as $ignored) {
                $this->activity($folder, ActivityStatus::Scheduled, $yesterday)->update(['scheduled_has_time' => false]);
            }

            $response = $this->actingAs($ci)->get(route('home'))->assertOk();

            $this->assertSame(1, $response->viewData('summary')['needs_attention'], 'The KPI counts folders, not activities.');
            // Overdue work is a flag, not a progress status: the chart classifies this folder by
            // its progress (nothing mandatory met yet) and leaves the count to the KPI card.
            $this->assertNull(collect($response->viewData('workload')['segments'])->firstWhere('key', 'needs_attention'));
            $this->assertSame(1, collect($response->viewData('workload')['segments'])->firstWhere('key', 'not_started')['count']);
            $this->assertSame(3, $response->viewData('summary')['needs_attention_items']);
            $response->assertSee('1 client folder • 3 overdue activities');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_needs_attention_hint_pluralises_across_folders(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00', 'UTC'));

        try {
            $ci = User::factory()->create();
            [$yesterday] = CiActivity::normalizeScheduleInput('2026-09-03');

            foreach (['LIMA, CLIENT', 'MIKE, CLIENT'] as $name) {
                $this->activity($this->folder($ci, $name), ActivityStatus::Scheduled, $yesterday)->update(['scheduled_has_time' => false]);
            }

            $this->actingAs($ci)->get(route('home'))->assertOk()
                ->assertSee('2 client folders • 2 overdue activities');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_in_progress_hint_reads_active_investigations(): void
    {
        $ci = User::factory()->create();
        // One completed activity is not all of the folder's mandatory work, so it is still In Progress.
        $this->activity($this->folder($ci, 'NOVEMBER, CLIENT'), ActivityStatus::Completed);

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();

        $response->assertSee('Active Investigations')
            ->assertDontSee('Ongoing Investigations')
            ->assertDontSee('Investigations already underway');

        $this->assertSame(1, $response->viewData('summary')['in_progress']);
    }

    /**
     * The one place the collaborative model stops. Folder access is shared, but CI Activity
     * responsibility is not: the app assigns responsibility through `creator_id` (the field the
     * scheduled-today reminder feed scopes by), so a colleague's activity on a folder this user can
     * open must not appear on this user's personal list.
     */
    public function test_my_work_today_stays_personal_even_on_a_shared_folder(): void
    {
        $ci = User::factory()->create();
        $colleague = User::factory()->create();
        $shared = $this->folder($colleague, 'NOVEMBER, SHARED CLIENT');

        $mine = $this->activity($shared, ActivityStatus::Pending);
        $mine->update(['creator_id' => $ci->id]);
        $theirs = $this->activity($shared, ActivityStatus::Pending);
        $theirs->update(['creator_id' => $colleague->id]);

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();

        // The folder itself is fully part of this user's workspace...
        $this->assertSame(1, $response->viewData('summary')['assigned']);
        // ...but only the activity this user is responsible for is on their action list.
        $this->assertSame([$mine->id], collect($response->viewData('workToday')->items())->pluck('id')->all());

        // And the colleague sees the mirror image of that.
        $this->assertSame(
            [$theirs->id],
            collect($this->actingAs($colleague)->get(route('home'))->assertOk()->viewData('workToday')->items())->pluck('id')->all(),
        );
    }

    public function test_applicant_and_co_maker_work_never_cross_contaminate(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'OSCAR, CLIENT');
        $first = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'First Co-Maker']);
        $second = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Second Co-Maker']);

        $applicantWork = $this->activity($folder, ActivityStatus::Pending);
        $firstWork = $this->activity($folder, ActivityStatus::Pending, null, $first->id);
        $secondWork = $this->activity($folder, ActivityStatus::Pending, null, $second->id);

        $work = collect($this->actingAs($ci)->get(route('home'))->assertOk()->viewData('workToday')->items())->keyBy('id');

        $this->assertNull($work[$applicantWork->id]['person'], 'Applicant work carries no Co-Maker.');
        $this->assertSame('First Co-Maker', $work[$firstWork->id]['person']);
        $this->assertSame('Second Co-Maker', $work[$secondWork->id]['person']);

        // Each row links to its own person context, so no row can open another person's record.
        $this->assertStringContainsString('co_maker_id='.$first->id, $work[$firstWork->id]['url']);
        $this->assertStringContainsString('co_maker_id='.$second->id, $work[$secondWork->id]['url']);
        $this->assertStringNotContainsString('co_maker_id', $work[$applicantWork->id]['url']);

        // Three activities on one folder still describe one folder.
        $this->assertSame(1, $this->actingAs($ci)->get(route('home'))->assertOk()->viewData('workload')['total']);
    }

    private function folder(User $ci, string $name, ClientFolderStatus $status = ClientFolderStatus::OnProgress): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => $name,
            'status' => $status,
            'completed_at' => $status === ClientFolderStatus::Completed ? now() : null,
        ]);
    }

    private function activity(ClientFolder $folder, ActivityStatus $status, $scheduledAt = null, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('is_active', true)->where('is_required', true)->orderBy('sort_order')->firstOrFail();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            'creator_id' => $folder->assigned_ci_id,
        ]);
    }

    private function report(ClientFolder $folder, GenerationStatus $status): GeneratedReport
    {
        return GeneratedReport::create([
            'client_folder_id' => $folder->id,
            'scope_key' => 'folder-'.$folder->id.'-'.$status->value,
            'source_type' => 'cibi_report',
            'source_id' => $folder->id,
            'report_type' => 'cibi',
            'format' => 'pdf',
            'version' => 1,
            'status' => $status,
            'generated_by' => $folder->assigned_ci_id,
        ]);
    }
}
