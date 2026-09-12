<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Needs Attention = unique Client Folders holding at least one overdue item; the detail lists the
 * exact overdue activities and Bank / Coop / Asset targets, grouped by folder. The clock is fixed
 * at 2026-09-04 8:00 PM Asia/Manila (12:00 UTC).
 */
class DashboardNeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00', 'UTC'));
        $this->ci = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_folder_with_one_overdue_activity_reads_singular(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-03');

        $response = $this->home();

        $this->assertSame([1, 1], [$response->viewData('summary')['needs_attention'], $response->viewData('summary')['needs_attention_items']]);
        $response->assertSee('1 client folder • 1 overdue activity')
            ->assertSee('data-modal-open="dashboard-needs-attention-dialog"', false)
            ->assertSee('id="dashboard-needs-attention-dialog"', false);
    }

    public function test_one_folder_with_three_overdue_activities_counts_once_and_lists_each_by_name(): void
    {
        $folder = $this->folder('OBASA, REYNALDO SSS');
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-01');
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::FollowUp, '2026-09-02');
        $custom = $this->activity($folder, null, ActivityStatus::Scheduled, '2026-09-04', '09:00');

        $response = $this->home();
        $groups = $response->viewData('needsAttention');

        $this->assertSame(1, $response->viewData('summary')['needs_attention']);
        $this->assertSame(3, $response->viewData('summary')['needs_attention_items']);
        $this->assertCount(1, $groups);
        $this->assertSame('OBASA, REYNALDO SSS', $groups[0]['client']);
        // Oldest due first.
        $this->assertSame(['Neighbor Check', 'Barangay Check', $custom->display_name], array_column($groups[0]['items'], 'label'));
        $this->assertSame(['Scheduled', 'For Follow-up', 'Scheduled'], array_column($groups[0]['items'], 'status'));
        $this->assertSame(['Sep 1, 2026', 'Sep 2, 2026', 'Sep 4, 2026 · 9:00 AM'], array_column($groups[0]['items'], 'due'));
        $this->assertSame([3, 2, 0], array_column($groups[0]['items'], 'days_overdue'));
        $response->assertSee('1 client folder • 3 overdue activities')
            ->assertSee('Overdue by 3 days')
            ->assertSee('Overdue today');
    }

    public function test_pending_without_schedule_future_schedules_and_completed_work_are_not_overdue(): void
    {
        $folder = $this->folder('CALM, CLIENT');
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending, null);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-05');
        $this->activity($folder, null, ActivityStatus::Completed, '2026-09-01');
        // Due today, date only: not overdue during its own day.
        $this->activity($this->folder('TODAY, CLIENT'), ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-04');

        $response = $this->home();

        $this->assertSame([0, 0], [$response->viewData('summary')['needs_attention'], $response->viewData('summary')['needs_attention_items']]);
        $this->assertSame([], $response->viewData('needsAttention'));
        $response->assertSee('Nothing overdue')->assertDontSee('id="dashboard-needs-attention-dialog"', false);
    }

    public function test_bank_coop_targets_are_evaluated_per_institution_and_the_parent_is_not_counted_again(): void
    {
        $folder = $this->folder('BANK, CLIENT');
        $bank = $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-02');
        $this->bankTarget($bank, 'BPI', 'PUERTO', ActivityStatus::Scheduled, '2026-09-02');
        $this->bankTarget($bank, 'LANDBANK', 'PUERTO', ActivityStatus::Completed, '2026-09-01');
        $this->bankTarget($bank, 'MCCB', null, ActivityStatus::Scheduled, '2026-09-10', CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);

        $response = $this->home();

        $this->assertSame([1, 1], [$response->viewData('summary')['needs_attention'], $response->viewData('summary')['needs_attention_items']]);
        $this->assertSame(['Bank / Coop Check — BPI (PUERTO)'], array_column($response->viewData('needsAttention')[0]['items'], 'label'));
    }

    public function test_asset_targets_and_several_targets_in_one_folder_count_that_folder_once(): void
    {
        $folder = $this->folder('ASSET, CLIENT');
        $asset = $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending, null);
        $target = $this->assetTarget($asset, 'ROPA Department', ActivityStatus::FollowUp, '2026-09-03');
        $bank = $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Pending, null);
        $this->bankTarget($bank, 'BDO', null, ActivityStatus::Scheduled, '2026-09-01');

        $response = $this->home();
        $labels = array_column($response->viewData('needsAttention')[0]['items'], 'label');

        $this->assertSame(1, $response->viewData('summary')['needs_attention']);
        $this->assertSame(2, $response->viewData('summary')['needs_attention_items']);
        $this->assertSame(['Bank / Coop Check — BDO', 'Asset Check — '.$target->assessorLabel().' (ROPA Department)'], $labels);
    }

    public function test_three_folders_count_three_and_the_plural_subtitle_counts_every_item(): void
    {
        foreach (['LIMA, CLIENT' => 2, 'MIKE, CLIENT' => 3] as $name => $overdue) {
            $folder = $this->folder($name);
            foreach (range(1, $overdue) as $day) {
                $this->activity($folder, null, ActivityStatus::Scheduled, '2026-09-0'.$day);
            }
        }
        $this->home()->assertSee('2 client folders • 5 overdue activities');

        $this->activity($this->folder('NOVEMBER, CLIENT'), ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-03');
        $this->assertSame(3, $this->home()->viewData('summary')['needs_attention']);
    }

    public function test_person_context_is_exact_for_applicant_and_each_co_maker(): void
    {
        $folder = $this->folder('PERSON, CLIENT');
        $makerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Maker Alpha']);
        $makerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Maker Beta']);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-01');
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-02', null, $makerA->id);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-09', null, $makerB->id);

        $items = $this->home()->viewData('needsAttention')[0]['items'];

        $this->assertSame(['Applicant', 'Co-Maker: Maker Alpha'], array_column($items, 'person'));
        $this->assertSame(route('client-folders.activities.index', $folder), $items[0]['url']);
        $this->assertSame(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $makerA->id]), $items[1]['url']);
    }

    public function test_completing_the_last_overdue_item_removes_the_folder_while_in_progress_is_unchanged(): void
    {
        $folder = $this->folder('CLEAR, CLIENT');
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-03');
        $before = $this->home();
        $this->assertSame(1, $before->viewData('summary')['needs_attention']);
        // Nothing mandatory is met yet, so the folder has not started.
        $this->assertSame(0, $before->viewData('summary')['in_progress']);

        $this->putJson(route('client-folders.activities.update', [$folder, $barangay]), ['co_maker_id' => null, 'status' => ActivityStatus::Completed->value])->assertOk();

        $after = $this->home();
        $this->assertSame(0, $after->viewData('summary')['needs_attention']);
        $this->assertSame([], $after->viewData('needsAttention'));
        // The Barangay Check is now genuinely met: 1 of 7, so the folder has started but is not
        // finished — In Progress, not Completed.
        $this->assertSame(1, $after->viewData('summary')['in_progress'], 'One completed activity starts the folder without finishing it.');
        $this->assertSame(14, $after->viewData('kpiDetails')['in_progress'][0]['progress']['percent']);
    }

    public function test_the_card_is_an_interactive_button_only_when_something_is_overdue(): void
    {
        $folder = $this->folder('CARD, CLIENT');
        $calm = $this->home()->getContent();
        // Other KPIs may be clickable here (a folder exists); Needs Attention at zero must not be.
        $this->assertStringNotContainsString('data-modal-open="dashboard-needs-attention-dialog"', $calm);
        preg_match('/<article\s+class="([^"]*)"\s+aria-label="Needs Attention">/', $calm, $card);
        $this->assertNotEmpty($card, 'At zero the Needs Attention card stays a plain article.');
        $this->assertStringNotContainsString('cursor-pointer', $card[1]);
        $this->assertStringNotContainsString('hover:', $card[1]);

        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-03');
        $html = $this->home()->getContent();
        preg_match('/<button\s+type="button"\s+data-modal-open="dashboard-needs-attention-dialog"[^>]*>/', $html, $button);
        $this->assertNotEmpty($button, 'The Needs Attention card must render as a button that opens the modal.');
        foreach (['data-kpi-clickable', 'aria-haspopup="dialog"', 'cursor-pointer', 'hover:ring-danger/25', 'focus-visible:ring-2', 'aria-label="Needs Attention: 1, view overdue details"'] as $expected) {
            $this->assertStringContainsString($expected, $button[0]);
        }
        $this->assertStringContainsString('View details', $html);
        // Exactly one Needs Attention button (other KPIs have their own detail cards now).
        $this->assertSame(1, substr_count($html, 'data-modal-open="dashboard-needs-attention-dialog"'));
    }

    public function test_the_modal_summarises_counts_and_each_row_is_a_person_scoped_link(): void
    {
        $folder = $this->folder('OBASA, REYNALDO SSS');
        $maker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'JUAN DELA CRUZ']);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-08-25'); // 10 days
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::FollowUp, '2026-09-01', null, $maker->id); // 3 days
        $bank = $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Pending, null);
        $this->bankTarget($bank, 'LANDBANK', 'PUERTO', ActivityStatus::Scheduled, '2026-09-03'); // 1 day
        $asset = $this->activity($this->folder('SECOND, CLIENT'), ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending, null);
        $target = $this->assetTarget($asset, 'ROPA Department', ActivityStatus::Scheduled, '2026-09-02');

        $html = $this->home()->getContent();
        $start = strpos($html, 'id="dashboard-needs-attention-dialog"');
        $modal = substr($html, $start, strpos($html, '</dialog>', $start) - $start);

        $this->assertStringContainsString('2 client folders • 4 overdue activities', $modal);
        $this->assertStringContainsString('Oldest overdue activities are shown first.', $modal);
        $this->assertStringContainsString('data-modal-close', $modal);
        $this->assertSame(2, substr_count($modal, 'data-needs-attention-folder'));
        $this->assertSame(4, substr_count($modal, 'data-needs-attention-item'));

        // Whole-row links to the exact person's CI Activities.
        $applicantUrl = e(route('client-folders.activities.index', $folder));
        $makerUrl = e(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $maker->id]));
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote($applicantUrl, '/').'" class="group flex cursor-pointer[^"]*focus-visible:ring-2/', $modal);
        $this->assertStringContainsString('<a href="'.$makerUrl.'"', $modal);
        $this->assertStringNotContainsString('co_maker_id='.$maker->id.'</', $modal);

        // Title, person and the cleaned-up due • status line.
        foreach (['Barangay Check', 'Neighbor Check', 'Bank / Coop Check — LANDBANK (PUERTO)', 'Asset Check — '.$target->assessorLabel().' (ROPA Department)'] as $label) {
            $this->assertStringContainsString(e($label), $modal);
        }
        $this->assertStringContainsString('data-needs-attention-person>Co-Maker: JUAN DELA CRUZ</p>', $modal);
        $this->assertStringContainsString('data-needs-attention-person>Applicant</p>', $modal);
        $this->assertMatchesRegularExpression('/data-needs-attention-meta>Due: Sep 1, 2026 <span aria-hidden="true">&bull;<\/span> For Follow-up<\/p>/', $modal);
        $this->assertStringNotContainsString('Status:', $modal);

        // Badge tone follows age: 6+ solid danger, 3–5 danger tint, 0–2 amber.
        $this->assertMatchesRegularExpression('/bg-danger text-white" data-overdue-badge>Overdue by 10 days</', $modal);
        $this->assertMatchesRegularExpression('/bg-danger-soft text-danger" data-overdue-badge>Overdue by 3 days</', $modal);
        $this->assertMatchesRegularExpression('/bg-progress-soft text-progress" data-overdue-badge>Overdue by 1 day</', $modal);
    }

    /**
     * Needs Attention is an overdue FLAG, not a progress status, so it is no longer one of the
     * Workload by Status slices. A folder keeps its own progress status and is counted by the
     * Needs Attention KPI at the same time - the two metrics measure different things.
     */
    public function test_an_overdue_folder_keeps_its_progress_status_and_its_needs_attention_count(): void
    {
        $open = $this->folder('OPEN, CLIENT');
        $this->activity($open, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-02');
        $this->activity($open, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled, '2026-09-01');
        // A folder already marked Completed that still has an overdue (optional) Asset office.
        $completed = $this->folder('DONE, CLIENT');
        $completed->update(['status' => ClientFolderStatus::Completed, 'completed_at' => now()]);
        $asset = $this->activity($completed, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending, null);
        $this->assetTarget($asset, 'ROPA Department', ActivityStatus::Scheduled, '2026-09-01');
        $this->folder('QUIET, CLIENT');

        $response = $this->home();
        $segments = collect($response->viewData('workload')['segments'])->pluck('count', 'key');

        // Both overdue folders are still counted by the KPI...
        $this->assertSame(2, $response->viewData('summary')['needs_attention']);
        // ...while the chart reports only progress statuses, and no longer carries an overdue one.
        $this->assertSame(['not_started', 'in_progress', 'completed'], $segments->keys()->all());
        $this->assertSame(3, $segments->sum(), 'Slices stay mutually exclusive and add up to the folder total.');
        // None of the three has met a single mandatory requirement, DONE included: its stored
        // status was written directly, and the chart reports the authoritative calculation rather
        // than that flag. Its overdue OPTIONAL Asset office changes neither thing.
        $this->assertSame(['not_started' => 3, 'in_progress' => 0, 'completed' => 0], $segments->all());
    }

    private function home()
    {
        return $this->actingAs($this->ci)->get(route('home'))->assertOk();
    }

    private function folder(string $name): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'display_name' => $name]);
    }

    private function activity(ClientFolder $folder, ?string $code, ActivityStatus $status, ?string $date, ?string $time = null, ?int $coMakerId = null): CiActivity
    {
        $definition = $code !== null
            ? ActivityDefinition::query()->where('code', $code)->sole()
            // A custom (user-created) Activity Type.
            : ActivityDefinition::query()->firstOrCreate(['code' => 'employment_verification'], ['name' => 'Employment Verification', 'sort_order' => 90, 'is_required' => false, 'is_active' => true]);
        [$scheduledAt, $hasTime] = $date !== null ? CiActivity::normalizeScheduleInput($date, $time) : [null, false];

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $definition->id,
            'name' => $definition->name, 'status' => $status, 'scheduled_at' => $scheduledAt, 'scheduled_has_time' => $hasTime,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null, 'creator_id' => $this->ci->id,
        ]);
    }

    private function bankTarget(CiActivity $activity, string $institution, ?string $branch, ActivityStatus $status, string $date, string $type = CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK): CiActivityBankTarget
    {
        [$scheduledAt, $hasTime] = CiActivity::normalizeScheduleInput($date);

        return $activity->bankTargets()->create([
            'inquiry_type' => $type, 'institution_name' => $institution, 'branch_location' => $branch, 'status' => $status,
            'scheduled_at' => $scheduledAt, 'scheduled_has_time' => $hasTime, 'created_by' => $this->ci->id, 'updated_by' => $this->ci->id,
        ]);
    }

    private function assetTarget(CiActivity $activity, string $office, ActivityStatus $status, string $date): CiActivityAssetTarget
    {
        [$scheduledAt, $hasTime] = CiActivity::normalizeScheduleInput($date);

        return $activity->assetTargets()->create([
            'assessor_type' => 'city_assessor', 'office_location' => $office, 'status' => $status,
            'scheduled_at' => $scheduledAt, 'scheduled_has_time' => $hasTime, 'created_by' => $this->ci->id, 'updated_by' => $this->ci->id,
        ]);
    }
}
