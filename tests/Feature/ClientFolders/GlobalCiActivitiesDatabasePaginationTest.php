<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Global CI Activities: filtering, counting, sorting and pagination run in the database. The
 * equivalence test re-implements the previous load-everything-then-filter-in-PHP algorithm as a
 * reference and asserts the database-paginated page returns the same rows, order and counts.
 */
class GlobalCiActivitiesDatabasePaginationTest extends TestCase
{
    use RefreshDatabase;

    private const SORTS = ['earliest_schedule', 'latest_schedule', 'recently_updated', 'client_name'];

    private const SCHEDULES = ['all', 'today', 'tomorrow', 'this_week', 'overdue'];

    private const TABS = ['all', 'due_today', 'scheduled', 'follow_up', 'completed'];

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

    public function test_results_order_and_counts_match_the_previous_in_memory_algorithm_for_every_sort_schedule_and_tab(): void
    {
        $ci = $this->mixedDataset();

        // The dataset genuinely exercises every branch (so matching the reference is not vacuous).
        foreach (['today', 'tomorrow', 'this_week', 'overdue'] as $schedule) {
            $this->assertNotEmpty($this->reference($schedule, 'all', 'earliest_schedule')[0], $schedule.' dataset is empty');
        }
        $allCounts = $this->reference('all', 'all', 'earliest_schedule')[1];
        $this->assertGreaterThan(1, $allCounts['due_today']);
        $this->assertGreaterThan(1, $allCounts['overdue']);
        $this->assertNotSame(
            $this->reference('all', 'all', 'earliest_schedule')[0],
            $this->reference('all', 'all', 'client_name')[0],
        );

        foreach (self::SORTS as $sort) {
            foreach (self::SCHEDULES as $schedule) {
                foreach (self::TABS as $tab) {
                    $query = ['sort' => $sort, 'schedule' => $schedule, 'tab' => $tab, 'per_page' => 50];
                    $response = $this->actingAs($ci)->get(route('ci-activities.index', $query))->assertOk();
                    [$expectedIds, $expectedCounts] = $this->reference($schedule, $tab, $sort);

                    $label = json_encode($query);
                    $this->assertSame($expectedIds, $this->ids($response->viewData('rows')), 'Rows/order differ for '.$label);
                    $this->assertSame(count($expectedIds), $response->viewData('rows')->total(), 'Total differs for '.$label);
                    $this->assertSame($expectedCounts, $response->viewData('counts'), 'Counts differ for '.$label);
                }
            }
        }

        foreach ([['status' => 'scheduled'], ['person' => 'co_maker'], ['person' => 'applicant'], ['search' => 'BDO'], ['search' => 'zeta']] as $filter) {
            $response = $this->actingAs($ci)->get(route('ci-activities.index', $filter + ['per_page' => 50]))->assertOk();
            [$expectedIds, $expectedCounts] = $this->reference('all', 'all', 'earliest_schedule', $filter);

            $this->assertSame($expectedIds, $this->ids($response->viewData('rows')), 'Rows/order differ for '.json_encode($filter));
            $this->assertSame($expectedCounts, $response->viewData('counts'), 'Counts differ for '.json_encode($filter));
        }
    }

    public function test_status_and_search_filters_never_leak_through_or_precedence(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'Search Target Client');
        $pending = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Pending]);
        $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::Completed]);
        $trashed = $this->folder($ci, 'Search Target Deleted');
        $this->activity($trashed, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Pending]);
        $trashed->delete();

        $rows = $this->actingAs($ci)->get(route('ci-activities.index', ['search' => 'Search Target', 'status' => 'pending']))->assertOk()->viewData('rows');

        $this->assertSame([$pending->id], $this->ids($rows));
        $this->assertSame(1, $rows->total());
    }

    public function test_applicant_and_co_maker_rows_keep_exact_person_and_folder_context(): void
    {
        $ci = User::factory()->create();
        $folderA = $this->folder($ci, 'Same Name Client');
        $folderB = $this->folder($ci, 'Other Folder Client');
        $coMakerA = CoMaker::create(['client_folder_id' => $folderA->id, 'full_name' => 'Twin Co Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folderB->id, 'full_name' => 'Twin Co Maker']);
        $applicant = $this->activity($folderA, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $coA = $this->activity($folderA, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMakerA->id]);
        $coB = $this->activity($folderB, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMakerB->id]);

        $rows = collect($this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 50]))->assertOk()->viewData('rows')->items())
            ->keyBy(fn ($row) => $row->activity->id);

        $this->assertCount(3, $rows);
        $this->assertSame(['Applicant', $folderA->display_name, $folderA->id], [$rows[$applicant->id]->personLabel, $rows[$applicant->id]->personName, $rows[$applicant->id]->clientFolder->id]);
        $this->assertSame(['Co-Maker', 'Twin Co Maker', $folderA->id], [$rows[$coA->id]->personLabel, $rows[$coA->id]->personName, $rows[$coA->id]->clientFolder->id]);
        $this->assertSame(['Co-Maker', 'Twin Co Maker', $folderB->id], [$rows[$coB->id]->personLabel, $rows[$coB->id]->personName, $rows[$coB->id]->clientFolder->id]);
        $this->assertStringContainsString('co_maker_id='.$coMakerA->id, $rows[$coA->id]->openUrl);
        $this->assertStringContainsString('co_maker_id='.$coMakerB->id, $rows[$coB->id]->openUrl);
        $this->assertStringNotContainsString('co_maker_id', $rows[$applicant->id]->openUrl);

        $applicantOnly = $this->actingAs($ci)->get(route('ci-activities.index', ['person' => 'applicant']))->viewData('rows');
        $this->assertSame([$applicant->id], $this->ids($applicantOnly));
    }

    public function test_second_page_returns_the_next_records_in_default_order_and_links_keep_the_query_string(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'Paged Client');
        $expected = [];
        foreach (range(1, 12) as $day) {
            $expected[] = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, [
                'name' => 'Paged Check '.$day,
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse(sprintf('2026-09-%02d 09:00:00', 13 - $day), config('cims.display_timezone'))->utc(),
            ])->id;
        }
        $expected = array_reverse($expected);
        $query = ['search' => 'Paged', 'person' => 'applicant', 'status' => 'scheduled', 'per_page' => 5];

        $pageOne = $this->actingAs($ci)->get(route('ci-activities.index', $query))->assertOk();
        $pageTwo = $this->actingAs($ci)->get(route('ci-activities.index', $query + ['page' => 2]))->assertOk();
        $pageThree = $this->actingAs($ci)->get(route('ci-activities.index', $query + ['page' => 3]))->assertOk();

        $this->assertSame(array_slice($expected, 0, 5), $this->ids($pageOne->viewData('rows')));
        $this->assertSame(array_slice($expected, 5, 5), $this->ids($pageTwo->viewData('rows')));
        $this->assertSame(array_slice($expected, 10, 2), $this->ids($pageThree->viewData('rows')));
        $this->assertSame(12, $pageTwo->viewData('rows')->total());
        $this->assertSame(3, $pageTwo->viewData('rows')->lastPage());
        $pageTwo->assertSee('Showing 6 to 10 of 12 activities', false);

        $nextUrl = $pageOne->viewData('rows')->nextPageUrl();
        foreach (['search=Paged', 'person=applicant', 'status=scheduled', 'per_page=5', 'page=2'] as $carried) {
            $this->assertStringContainsString($carried, $nextUrl);
        }
    }

    public function test_page_one_hydrates_only_page_sized_activities_and_queries_do_not_grow_per_row(): void
    {
        $ci = User::factory()->create();
        foreach (range(1, 60) as $index) {
            $folder = $this->folder($ci, 'Volume Client '.$index);
            $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, ['status' => ActivityStatus::Scheduled]);
            $this->bankTarget($bank, $ci, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDays($index)]);
        }

        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.CiActivity::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        $rows = $this->actingAs($ci)->get(route('ci-activities.index', ['per_page' => 5]))->assertOk()->viewData('rows');

        $this->assertSame(5, $hydrated, 'Only the requested page of activities is hydrated.');
        $this->assertCount(5, $rows->items());
        $this->assertSame(60, $rows->total());

        $this->assertSame(
            $this->queryCountFor($ci, ['per_page' => 5]),
            $this->queryCountFor($ci, ['per_page' => 50]),
            '5 rows and 50 rows on one page configuration issue the same number of queries.',
        );
    }

    // ---------------------------------------------------------------- Reference (previous algorithm)

    /** @return array{0: list<int>, 1: array<string, int>} */
    private function reference(string $schedule, string $tab, string $sort, array $filter = []): array
    {
        $timezone = config('cims.display_timezone');
        $localToday = now($timezone)->startOfDay();
        $definitionIds = ActivityDefinition::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%'))
            ->pluck('id');

        $rows = CiActivity::query()
            ->whereHas('clientFolder')
            ->whereIn('activity_definition_id', $definitionIds)
            ->with(['definition', 'clientFolder', 'coMaker', 'bankTargets', 'assetTargets'])
            ->orderBy('id')
            ->get()
            ->filter(fn (CiActivity $activity): bool => ! isset($filter['status']) || $activity->status->value === $filter['status'])
            ->filter(fn (CiActivity $activity): bool => ! isset($filter['person']) || ($filter['person'] === 'co_maker') === ($activity->co_maker_id !== null))
            ->filter(function (CiActivity $activity) use ($filter): bool {
                if (! isset($filter['search'])) {
                    return true;
                }
                $needle = mb_strtolower($filter['search']);

                return collect([$activity->clientFolder->display_name, $activity->coMaker?->full_name, $activity->name, $activity->definition->name])
                    ->merge($activity->bankTargets->pluck('institution_name'))
                    ->merge($activity->assetTargets->pluck('office_location'))
                    ->contains(fn ($value) => $value !== null && str_contains(mb_strtolower($value), $needle));
            })
            ->map(function (CiActivity $activity) use ($timezone, $localToday): object {
                $code = $activity->definition->code;
                $targets = match ($code) {
                    ActivityDefinition::BANK_COOP_CHECK_CODE => $activity->bankTargets,
                    ActivityDefinition::ASSET_CHECK_CODE => $activity->assetTargets,
                    default => null,
                };
                $scheduledAt = $targets === null
                    ? $activity->scheduled_at
                    : $targets->filter(fn ($target) => $target->status === ActivityStatus::Scheduled && $target->scheduled_at !== null)->sortBy(fn ($target) => $target->scheduled_at->timestamp)->first()?->scheduled_at;
                $active = in_array($activity->status, [ActivityStatus::Scheduled, ActivityStatus::FollowUp], true);
                $localDate = $scheduledAt?->copy()->timezone($timezone)->startOfDay();

                return (object) [
                    'id' => $activity->id,
                    'status' => $activity->status->value,
                    'scheduledAt' => $scheduledAt,
                    'localDate' => $localDate,
                    'isDueToday' => $active && $localDate?->isSameDay($localToday) === true,
                    'isOverdue' => $active && $localDate?->lt($localToday) === true,
                    'updatedAt' => $activity->updated_at,
                    'clientName' => $activity->clientFolder->display_name,
                ];
            })
            ->values();

        $counts = [
            'pending' => $rows->where('status', 'pending')->count(),
            'scheduled' => $rows->where('status', 'scheduled')->count(),
            'follow_up' => $rows->where('status', 'follow_up')->count(),
            'completed' => $rows->where('status', 'completed')->count(),
            'due_today' => $rows->where('isDueToday', true)->count(),
            'overdue' => $rows->where('isOverdue', true)->count(),
            'all' => $rows->count(),
        ];

        /** @var Collection<int, object> $visible */
        $visible = $rows->filter(fn ($row): bool => $schedule === 'all' || ($row->localDate !== null && match ($schedule) {
            'today' => $row->localDate->isSameDay($localToday),
            'tomorrow' => $row->localDate->isSameDay($localToday->copy()->addDay()),
            'this_week' => $row->localDate->between($localToday->copy()->startOfWeek(), $localToday->copy()->endOfWeek()),
            'overdue' => $row->isOverdue,
        }))->filter(fn ($row): bool => match ($tab) {
            'due_today' => $row->isDueToday,
            'scheduled', 'follow_up', 'completed' => $row->status === $tab,
            default => true,
        })->values();

        $visible = match ($sort) {
            'latest_schedule' => $visible->sortByDesc(fn ($row) => $row->scheduledAt?->timestamp ?? -INF),
            'recently_updated' => $visible->sortByDesc(fn ($row) => $row->updatedAt?->timestamp ?? 0),
            'client_name' => $visible->sortBy(fn ($row) => $row->clientName),
            default => $visible->sortBy(fn ($row) => $row->scheduledAt?->timestamp ?? INF),
        };

        return [$visible->pluck('id')->values()->all(), $counts];
    }

    // ---------------------------------------------------------------- Dataset / helpers

    private function mixedDataset(): User
    {
        $ci = User::factory()->create();
        $local = fn (string $time) => Carbon::parse($time, config('cims.display_timezone'))->utc();

        $alpha = $this->folder($ci, 'Alpha Client');
        $zeta = $this->folder($ci, 'Zeta Client');
        $twin = $this->folder($ci, 'Alpha Client');
        $coMaker = CoMaker::create(['client_folder_id' => $zeta->id, 'full_name' => 'Zeta Co Maker']);

        $schedules = [
            ['2026-09-01 00:00:00', ActivityStatus::Scheduled],   // today, first instant
            ['2026-09-01 23:59:59', ActivityStatus::FollowUp],    // today, last instant
            ['2026-08-31 23:59:59', ActivityStatus::Scheduled],   // overdue by one second
            ['2026-08-20 08:00:00', ActivityStatus::FollowUp],    // overdue follow-up
            ['2026-08-20 08:00:00', ActivityStatus::Pending],     // past, but not an active status
            ['2026-08-20 08:00:00', ActivityStatus::Completed],   // past, completed
            ['2026-09-02 00:00:00', ActivityStatus::Scheduled],   // tomorrow
            ['2026-09-05 10:00:00', ActivityStatus::Scheduled],   // later this week (either week start)
            ['2026-09-06 10:00:00', ActivityStatus::Scheduled],   // week boundary
            ['2026-09-07 10:00:00', ActivityStatus::Scheduled],   // week boundary
            ['2026-09-20 10:00:00', ActivityStatus::FollowUp],    // far future
            [null, ActivityStatus::Pending],
            [null, ActivityStatus::Completed],
            ['2026-09-05 10:00:00', ActivityStatus::Scheduled],   // schedule tie
        ];
        foreach ($schedules as $index => [$time, $status]) {
            $folder = [$alpha, $zeta, $twin][$index % 3];
            $this->activity($folder, $ci, $index % 2 ? ActivityDefinition::NEIGHBOR_CHECK_CODE : ActivityDefinition::BARANGAY_CHECK_CODE, [
                'status' => $status,
                'scheduled_at' => $time ? $local($time) : null,
                'co_maker_id' => $folder->is($zeta) && $index % 2 ? $coMaker->id : null,
            ]);
        }

        // Bank: earliest *Scheduled* target wins; completed/pending targets with earlier dates and
        // the activity's own scheduled_at are ignored.
        $bank = $this->activity($zeta, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => $local('2026-08-01 08:00:00')]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => $local('2026-09-03 09:00:00')]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'BPI', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => $local('2026-09-01 18:00:00')]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Metro', 'status' => ActivityStatus::Completed, 'scheduled_at' => $local('2026-08-15 09:00:00')]);
        $this->bankTarget($bank, $ci, ['institution_name' => 'Union', 'status' => ActivityStatus::Pending, 'scheduled_at' => $local('2026-08-16 09:00:00')]);

        // Bank with no scheduled target: effective schedule is empty despite its own scheduled_at.
        $this->activity($alpha, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, ['status' => ActivityStatus::FollowUp, 'scheduled_at' => $local('2026-08-01 08:00:00'), 'co_maker_id' => null]);

        // Asset: overdue via its target, with a co-maker.
        $asset = $this->activity($zeta, $ci, ActivityDefinition::ASSET_CHECK_CODE, ['status' => ActivityStatus::FollowUp, 'co_maker_id' => $coMaker->id]);
        $asset->assetTargets()->create(['assessor_type' => 'city_assessor', 'office_location' => 'Zeta Land', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => $local('2026-08-25 09:00:00'), 'scheduled_has_time' => true, 'created_by' => $ci->id, 'updated_by' => $ci->id]);

        // Never visible: soft-deleted folder.
        $deleted = $this->folder($ci, 'Deleted Client');
        $this->activity($deleted, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => $local('2026-09-01 09:00:00')]);
        $deleted->delete();

        // Distinct and tied updated_at values for recently_updated.
        CiActivity::query()->orderBy('id')->get()->each(function (CiActivity $activity, int $index): void {
            DB::table('ci_activities')->where('id', $activity->id)->update(['updated_at' => now()->utc()->subMinutes(intdiv($index, 2))->format('Y-m-d H:i:s')]);
        });

        return $ci;
    }

    private function queryCountFor(User $ci, array $query): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->actingAs($ci)->get(route('ci-activities.index', $query))->assertOk();

        return $count;
    }

    /** @return list<int> */
    private function ids($paginator): array
    {
        return collect($paginator->items())->map(fn ($row) => $row->activity->id)->all();
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name]);
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

    private function bankTarget(CiActivity $activity, User $actor, array $overrides = []): CiActivityBankTarget
    {
        return $activity->bankTargets()->create(array_merge([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }
}
