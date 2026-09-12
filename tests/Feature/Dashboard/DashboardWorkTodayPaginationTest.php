<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class DashboardWorkTodayPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_five_rows_need_no_paginator_while_six_are_reachable_across_dedicated_ajax_pages(): void
    {
        $ci = User::factory()->create();
        $activities = collect(range(1, 6))->map(fn (int $number): CiActivity => $this->activity(
            $this->folder($ci, 'PAGE '.$number.', CLIENT'),
            $ci,
            ActivityStatus::Pending,
        ));

        $pageOneResponse = $this->actingAs($ci)->get(route('home', ['range' => '30d']))->assertOk()
            ->assertSee('data-work-today-region', false)
            ->assertSee('data-work-today-view-all', false)
            ->assertSee('data-work-today-view-all-icon', false)
            ->assertSee('href="'.route('ci-activities.index').'"', false)
            ->assertSee('data-work-today-pagination', false)
            ->assertSee('aria-label="Previous page"', false)
            ->assertSee('aria-label="Next page"', false)
            ->assertSee('ui-action-icon-button', false);
        $pageOne = $pageOneResponse->viewData('workToday');

        $this->assertInstanceOf(LengthAwarePaginator::class, $pageOne);
        $this->assertSame('work_page', $pageOne->getPageName());
        $this->assertSame(5, $pageOne->perPage());
        $this->assertSame(6, $pageOne->total());
        $this->assertCount(5, $pageOne->items());
        $this->assertSame(2, $pageOne->lastPage());
        $this->assertStringContainsString('work_page=2', $pageOne->nextPageUrl());
        $this->assertStringContainsString('range=30d', $pageOne->nextPageUrl());
        $this->assertStringNotContainsString('?page=', $pageOne->nextPageUrl());

        $pageTwoResponse = $this->get(route('home', ['range' => '30d', 'work_page' => 2]), [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html',
        ])->assertOk()
            ->assertViewIs('dashboard._work-today')
            ->assertDontSee('data-work-today-region', false)
            ->assertSee('data-work-today-presentation="modern"', false)
            ->assertSee('data-work-today-desktop-table', false)
            ->assertSee('data-work-today-mobile-list', false)
            ->assertSee('data-work-today-page="2"', false);
        $pageTwo = $pageTwoResponse->viewData('workToday');

        $this->assertSame(2, $pageTwo->currentPage());
        $this->assertCount(1, $pageTwo->items());
        $this->assertEqualsCanonicalizing(
            $activities->pluck('id')->all(),
            collect($pageOne->items())->pluck('id')->merge(collect($pageTwo->items())->pluck('id'))->all(),
        );

        $activities->last()->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
        $fiveResponse = $this->get(route('home'))->assertOk()->assertDontSee('data-work-today-pagination', false);
        $five = $fiveResponse->viewData('workToday');
        $this->assertSame(5, $five->total());
        $this->assertCount(5, $five->items());
    }

    public function test_priority_is_applied_to_the_full_dataset_before_pagination_and_completed_work_is_excluded(): void
    {
        $ci = User::factory()->create();
        $future = $this->activity($this->folder($ci, 'FUTURE SCHEDULED, CLIENT'), $ci, ActivityStatus::Scheduled, now()->addDay());
        $pendingOne = $this->activity($this->folder($ci, 'PENDING ONE, CLIENT'), $ci, ActivityStatus::Pending);
        $followUpToday = $this->activity($this->folder($ci, 'FOLLOW-UP, CLIENT'), $ci, ActivityStatus::FollowUp, now());
        $scheduledToday = $this->activity($this->folder($ci, 'SCHEDULED, CLIENT'), $ci, ActivityStatus::Scheduled, now());
        $overdue = $this->activity($this->folder($ci, 'OVERDUE, CLIENT'), $ci, ActivityStatus::Scheduled, now()->subDay());
        $pendingTwo = $this->activity($this->folder($ci, 'PENDING TWO, CLIENT'), $ci, ActivityStatus::Pending);
        $completed = $this->activity($this->folder($ci, 'COMPLETED, CLIENT'), $ci, ActivityStatus::Completed);

        $pageOne = $this->actingAs($ci)->get(route('home'))->assertOk()->viewData('workToday');
        $pageTwo = $this->get(route('home', ['work_page' => 2]))->assertOk()->viewData('workToday');
        $ordered = collect($pageOne->items())->merge($pageTwo->items());

        $this->assertSame($overdue->id, $ordered[0]['id']);
        $this->assertSame($scheduledToday->id, $ordered[1]['id']);
        $this->assertSame($future->id, $ordered[2]['id']);
        $this->assertSame($followUpToday->id, $ordered[3]['id']);
        $this->assertEqualsCanonicalizing([$pendingOne->id, $pendingTwo->id], $ordered->slice(4, 2)->pluck('id')->all());
        $this->assertFalse($ordered->contains('id', $completed->id));
        $this->assertSame('Overdue', $ordered[0]['status']);
        $this->assertSame(6, $pageOne->total());
        $this->assertCount(5, $pageOne->items());
        $this->assertCount(1, $pageTwo->items());
    }

    public function test_scheduled_follow_up_and_pending_checks_use_open_links_without_dashboard_edit_modal_triggers(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'STATUS ACTIONS, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Status Co-Maker']);
        $definitions = ActivityDefinition::query()
            ->whereIn('code', [ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE])
            ->get()
            ->keyBy('code');
        $activities = collect([
            [ActivityStatus::Scheduled, ActivityDefinition::BARANGAY_CHECK_CODE, null],
            [ActivityStatus::Scheduled, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id],
            [ActivityStatus::FollowUp, ActivityDefinition::BARANGAY_CHECK_CODE, null],
            [ActivityStatus::FollowUp, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id],
            [ActivityStatus::Pending, ActivityDefinition::BARANGAY_CHECK_CODE, null],
            [ActivityStatus::Pending, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id],
        ])->map(function (array $row) use ($ci, $folder, $definitions): CiActivity {
            [$status, $code, $coMakerId] = $row;
            $definition = $definitions[$code];

            return CiActivity::create([
                'client_folder_id' => $folder->id,
                'co_maker_id' => $coMakerId,
                'activity_definition_id' => $definition->id,
                'name' => $definition->name,
                'status' => $status,
                'scheduled_at' => $status === ActivityStatus::Pending ? null : now()->addDay(),
                'scheduled_has_time' => false,
                'creator_id' => $ci->id,
                'updated_by' => $ci->id,
            ]);
        });

        $pageOneResponse = $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertSee('Client Name')
            ->assertSee('Pending Activity')
            ->assertSee('Last Activity')
            ->assertSee('Scheduled')
            ->assertSee('For Follow-up')
            ->assertSee('Pending')
            ->assertSee('data-work-today-action="open"', false)
            ->assertDontSee('data-modal-open="dashboard-activity-dialog"', false);
        $pageTwoResponse = $this->get(route('home', ['work_page' => 2]))->assertOk()
            ->assertDontSee('data-modal-open="dashboard-activity-dialog"', false);
        $items = collect($pageOneResponse->viewData('workToday')->items())
            ->merge($pageTwoResponse->viewData('workToday')->items())
            ->keyBy('id');

        foreach ($activities as $activity) {
            $expectedUrl = route(
                'client-folders.activities.index',
                [$folder->id] + ActivePersonResolver::queryParamsForId($activity->co_maker_id),
            ).'#activity-'.$activity->id;
            $item = $items[$activity->id];

            $this->assertSame('Open', $item['action']);
            $this->assertSame($expectedUrl, $item['url']);
            $this->assertSame($expectedUrl, $item['client_url']);
            $this->assertFalse($item['direct_completion']);
        }

        $first = collect($pageOneResponse->viewData('workToday')->items())->first();
        $pageOneResponse->assertSee($first['client'])
            ->assertSee($first['activity'])
            ->assertSee($first['updated_at']->timezone(config('cims.display_timezone'))->format('M j, Y'))
            ->assertSee($first['updated_at']->timezone(config('cims.display_timezone'))->format('g:i A'));

        $this->assertStringContainsString('class="size-4 shrink-0"', $pageOneResponse->getContent());
        $this->assertStringContainsString('class="size-4 shrink-0"', $pageTwoResponse->getContent());
    }

    public function test_client_links_preserve_exact_applicant_and_co_maker_context_separately_from_continue(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'LINKED, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $applicant = $this->activity($folder, $ci, ActivityStatus::Pending);
        $maker = $this->activity($folder, $ci, ActivityStatus::Pending, null, $coMaker->id);

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();
        $items = collect($response->viewData('workToday')->items())->keyBy('id');
        $applicantUrl = route('client-folders.activities.index', $folder).'#activity-'.$applicant->id;
        $makerUrl = route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
        ]).'#activity-'.$maker->id;

        $this->assertSame($applicantUrl, $items[$applicant->id]['client_url']);
        $this->assertStringNotContainsString('co_maker_id', $items[$applicant->id]['client_url']);
        $this->assertSame($makerUrl, $items[$maker->id]['client_url']);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $items[$maker->id]['client_url']);
        $this->assertSame($items[$applicant->id]['client_url'], $items[$applicant->id]['url']);
        $this->assertSame($items[$maker->id]['client_url'], $items[$maker->id]['url']);
        $response->assertSee('href="'.$applicantUrl.'"', false)
            ->assertSee('href="'.e($makerUrl).'"', false)
            ->assertSee('id="work-today-desktop-'.$applicant->id.'-activity"', false)
            ->assertSee('id="work-today-desktop-'.$maker->id.'-activity"', false);
    }

    public function test_completion_refills_page_one_and_an_invalid_final_page_falls_back_safely(): void
    {
        $ci = User::factory()->create();
        $activities = collect(range(1, 6))->map(fn (int $number): CiActivity => $this->activity(
            $this->folder($ci, 'REFILL '.$number.', CLIENT'),
            $ci,
            ActivityStatus::Pending,
        ));
        $pageOne = $this->actingAs($ci)->get(route('home'))->assertOk()->viewData('workToday');
        $initialIds = collect($pageOne->items())->pluck('id');
        $waiting = $activities->first(fn (CiActivity $activity): bool => ! $initialIds->contains($activity->id));
        $completed = $activities->firstWhere('id', $initialIds->first());

        $completed->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
        $refilled = $this->get(route('home'))->assertOk()->viewData('workToday');
        $this->assertCount(5, $refilled->items());
        $this->assertTrue(collect($refilled->items())->contains('id', $waiting->id));
        $this->assertFalse(collect($refilled->items())->contains('id', $completed->id));

        $replacement = $this->activity($this->folder($ci, 'FINAL PAGE, CLIENT'), $ci, ActivityStatus::Pending);
        $pageTwo = $this->get(route('home', ['work_page' => 2]))->assertOk()->viewData('workToday');
        $this->assertSame(2, $pageTwo->currentPage());
        $this->assertSame($replacement->id, $pageTwo->items()[0]['id']);
        $replacement->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $fallbackResponse = $this->get(route('home', ['work_page' => 2]), [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html',
        ])->assertOk()->assertSee('data-work-today-page="1"', false);
        $fallback = $fallbackResponse->viewData('workToday');
        $this->assertSame(1, $fallback->currentPage());
        $this->assertCount(5, $fallback->items());

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("initAsyncListRegion(region, '[data-work-today-pagination] a[href]')", $script);
        $this->assertStringContainsString("url.searchParams.set('work_page', String(renderedPage))", $script);
        $this->assertStringContainsString('current.innerHTML = fresh.innerHTML', $script);
    }

    public function test_empty_work_preserves_the_existing_empty_state_without_pagination(): void
    {
        $ci = User::factory()->create();

        $response = $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertSee("You're all caught up")
            ->assertDontSee('data-work-today-pagination', false);

        $work = $response->viewData('workToday');
        $this->assertTrue($work->isEmpty());
        $this->assertSame(0, $work->total());
        $this->assertSame(1, $work->currentPage());
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => $name,
        ]);
    }

    private function activity(
        ClientFolder $folder,
        User $creator,
        ActivityStatus $status,
        $scheduledAt = null,
        ?int $coMakerId = null,
    ): CiActivity {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => false,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
