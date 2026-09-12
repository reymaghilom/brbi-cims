<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWorkTodayModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_non_overdue_work_opens_the_exact_ci_activities_page_instead_of_the_dashboard_edit_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE, $coMaker->id);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $bankTarget = $this->bankTarget($bank, $ci, 'Exact Cooperative');
        $assetTarget = $this->assetTarget($asset, $ci, 'Exact City Assessor');

        $response = $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertSee('id="dashboard-activity-dialog"', false)
            ->assertSee('data-work-today-region', false)
            ->assertSee('data-work-today-presentation="modern"', false)
            ->assertSee('data-work-today-desktop-table', false)
            ->assertSee('data-work-today-mobile-list', false)
            ->assertSee('data-work-today-client-link', false)
            ->assertSee('data-work-today-activity', false)
            ->assertSee('data-work-today-status', false)
            ->assertSee('data-work-today-last-activity', false)
            ->assertSee('data-work-today-action="open"', false)
            ->assertDontSee('data-modal-open="dashboard-activity-dialog"', false);
        $work = collect($response->viewData('workToday')->items());

        $applicantItem = $work->firstWhere('id', $barangay->id);
        $coMakerItem = $work->firstWhere('id', $neighbor->id);
        $bankItem = $work->first(fn (array $item): bool => $item['target_id'] === $bankTarget->id && $item['modal_kind'] === 'bank');
        $assetItem = $work->first(fn (array $item): bool => $item['target_id'] === $assetTarget->id && $item['modal_kind'] === 'asset');

        $this->assertNull($applicantItem['person']);
        $this->assertSame('Exact Co-Maker', $coMakerItem['person']);
        $this->assertFalse($applicantItem['direct_completion']);
        $this->assertFalse($coMakerItem['direct_completion']);
        $this->assertFalse($bankItem['direct_completion']);
        $this->assertFalse($assetItem['direct_completion']);
        foreach ([$applicantItem, $coMakerItem, $bankItem, $assetItem] as $item) {
            $this->assertSame('Open', $item['action']);
            $this->assertSame($item['client_url'], $item['url']);
        }
        $this->assertSame(0, substr_count($response->getContent(), 'data-modal-open="dashboard-overdue-complete-activity-modal"'));
        $this->assertStringNotContainsString('co_maker_id', $applicantItem['modal_url']);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $coMakerItem['modal_url']);
        $this->assertStringContainsString('dashboard_target_id='.$bankTarget->id, $bankItem['modal_url']);
        $this->assertStringContainsString('dashboard_target_id='.$assetTarget->id, $assetItem['modal_url']);

        $response->assertSee('href="'.$applicantItem['client_url'].'"', false)
            ->assertSee('href="'.e($coMakerItem['client_url']).'"', false)
            ->assertSee('href="'.e($bankItem['client_url']).'"', false)
            ->assertSee('href="'.$assetItem['client_url'].'"', false)
            ->assertSee('class="size-4 shrink-0"', false);
    }

    public function test_default_check_validation_stays_unsaved_and_success_removes_only_the_exact_person_work(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Remaining Co-Maker']);
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $remaining = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id);
        $applicant->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDay(), 'scheduled_has_time' => true]);
        $remaining->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDays(2), 'scheduled_has_time' => false]);

        $dashboard = $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertSee('id="dashboard-overdue-complete-activity-modal"', false)
            ->assertSee('max-w-md overflow-y-auto rounded-panel border-0', false)
            ->assertSee('data-completion-icon="indicator"', false)
            ->assertSee('data-completion-icon="cancel"', false)
            ->assertSee('data-completion-icon="confirm"', false)
            ->assertDontSee('data-dashboard-overdue-complete-edit', false)
            ->assertDontSee('data-completion-icon="edit"', false)
            ->assertSee('The schedule and time will be cleared. This completion will be recorded in Recent Activity under the user who confirms it.');
        $this->assertSame(4, substr_count($dashboard->getContent(), 'data-work-today-action="continue"'));
        $overdueWork = collect($dashboard->viewData('workToday')->items())->keyBy('id');
        $this->assertTrue($overdueWork[$applicant->id]['direct_completion']);
        $this->assertTrue($overdueWork[$remaining->id]['direct_completion']);
        $this->assertSame('Continue', $overdueWork[$applicant->id]['action']);
        $this->assertSame('Continue', $overdueWork[$remaining->id]['action']);
        $this->assertNull($overdueWork[$applicant->id]['completion_co_maker_id']);
        $this->assertSame($coMaker->id, $overdueWork[$remaining->id]['completion_co_maker_id']);
        $this->assertSame(route('client-folders.activities.update', [$folder, $applicant]), $overdueWork[$applicant->id]['completion_url']);
        $this->assertSame(route('client-folders.activities.update', [$folder, $remaining]), $overdueWork[$remaining->id]['completion_url']);
        $this->assertStringNotContainsString('co_maker_id', $overdueWork[$applicant->id]['modal_url']);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $overdueWork[$remaining->id]['modal_url']);
        $this->assertSame(4, substr_count($dashboard->getContent(), 'data-modal-open="dashboard-overdue-complete-activity-modal"'));
        $this->assertStringContainsString('data-dashboard-completion-name="Barangay Check"', $dashboard->getContent());
        $this->assertStringContainsString('data-dashboard-completion-name="Neighbor Check"', $dashboard->getContent());

        $globalActivities = $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $globalActivities->assertSee('id="quick-complete-activity-modal"', false)
            ->assertSee('data-completion-icon="indicator"', false)
            ->assertSee('data-completion-icon="cancel"', false)
            ->assertSee('data-completion-icon="edit"', false)
            ->assertSee('data-completion-icon="confirm"', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("cancel?.addEventListener('click', () => dashboardCompletionModal.close())", $script);
        $this->assertStringNotContainsString('data-dashboard-overdue-complete-edit', $script);
        $this->assertStringContainsString('dashboardCompletionModal.close();', $script);
        $this->assertStringContainsString(': `${activityName} marked as completed.`', $script);
        $this->assertStringContainsString('refreshDashboard()', $script);
        $this->assertStringContainsString("'X-Dashboard-Refresh': '1'", $script);
        $refreshFunction = substr($script, strpos($script, 'async function refreshDashboard()'), strpos($script, "document.querySelectorAll('[data-dashboard-completion-modal]')") - strpos($script, 'async function refreshDashboard()'));
        $this->assertStringNotContainsString('window.location.reload', $refreshFunction);
        $this->assertStringContainsString('current.innerHTML = fresh.innerHTML', $refreshFunction);

        $headers = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $applicant]), [
            'co_maker_id' => null,
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => null,
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->assertSame(ActivityStatus::Scheduled, $applicant->fresh()->status);

        $this->put(route('client-folders.activities.update', [$folder, $applicant]), [
            'co_maker_id' => null,
            'expected_updated_at' => $applicant->updated_at->toISOString(),
            'status' => ActivityStatus::Completed->value,
            'remarks' => 'Completed from Dashboard.',
        ], $headers)->assertOk()->assertJson(['updated' => true]);

        $work = collect($this->get(route('home'))->assertOk()->viewData('workToday')->items());
        $this->assertFalse($work->contains('id', $applicant->id));
        $this->assertTrue($work->contains('id', $remaining->id));
        $this->assertSame(ActivityStatus::Completed, $applicant->fresh()->status);
        $this->assertNull($applicant->fresh()->scheduled_at);
        $this->assertFalse($applicant->fresh()->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $remaining]), [
            'co_maker_id' => $coMaker->id,
            'expected_updated_at' => $remaining->updated_at->toISOString(),
            'status' => ActivityStatus::Completed->value,
            'remarks' => 'Exact Co-Maker Neighbor completed from Dashboard.',
        ], $headers)->assertOk()->assertJson(['updated' => true]);
        $this->assertFalse(collect($this->get(route('home'))->assertOk()->viewData('workToday')->items())->contains('id', $remaining->id));
        $this->assertSame(ActivityStatus::Completed, $remaining->fresh()->status);

        $refresh = $this->get(route('home'), [
            'Accept' => 'text/html',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Dashboard-Refresh' => '1',
        ])->assertOk()
            ->assertViewIs('dashboard.index')
            ->assertSee('data-dashboard-refresh-region="kpis"', false)
            ->assertSee('data-dashboard-refresh-region="workload"', false)
            ->assertSee('data-dashboard-refresh-region="activity-progress"', false)
            ->assertSee('data-dashboard-refresh-region="work-today"', false)
            ->assertSee('data-dashboard-refresh-region="recent-activity"', false)
            ->assertSee('data-dashboard-refresh-region="detail-modals"', false);
        $this->assertSame(0, $refresh->viewData('summary')['needs_attention']);
        $refreshedWork = collect($refresh->viewData('workToday')->items());
        $this->assertFalse($refreshedWork->contains('id', $applicant->id));
        $this->assertFalse($refreshedWork->contains('id', $remaining->id));
        $this->assertSame(1, collect($refresh->viewData('workload')['segments'])->firstWhere('key', 'in_progress')['count']);
        $ciProgress = collect($refresh->viewData('activityProgress'))->firstWhere('label', 'CI Activities');
        $this->assertSame(2, $ciProgress['completed']);
        $this->assertSame(5, $ciProgress['applicable']);
        $this->assertCount(2, $refresh->viewData('recentActivity'));
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_overdue_asset_targets_open_the_exact_compact_completion_modal_and_complete_independently(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $city = $this->assetTarget($asset, $ci, 'SS');
        $ropa = $this->assetTarget($asset, $ci, 'Main Branch');
        $future = $this->assetTarget($asset, $ci, 'Future Office');
        $city->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDays(2)]);
        $ropa->update(['assessor_type' => 'provincial_assessor', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subDay()]);
        $future->update(['assessor_type' => 'provincial_assessor', 'status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $asset->update(['status' => ActivityStatus::Scheduled]);

        $dashboard = $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertSee('id="dashboard-overdue-asset-complete-modal"', false)
            ->assertSee('data-dashboard-overdue-asset-complete-modal', false)
            ->assertSee('max-w-md rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45', false)
            ->assertSee('Mark as completed?')
            ->assertSee('data-completion-icon="cancel"', false)
            ->assertSee('data-completion-icon="confirm"', false)
            ->assertSee('Mark Completed')
            ->assertDontSee('data-dashboard-overdue-asset-complete-edit', false);
        $items = collect($dashboard->viewData('workToday')->items())
            ->filter(fn (array $item): bool => $item['modal_kind'] === 'asset')
            ->keyBy('target_id');

        $this->assertTrue($items[$city->id]['direct_completion']);
        $this->assertTrue($items[$ropa->id]['direct_completion']);
        $this->assertFalse($items[$future->id]['direct_completion']);
        $this->assertSame('Continue', $items[$city->id]['action']);
        $this->assertSame('Continue', $items[$ropa->id]['action']);
        $this->assertSame('Open', $items[$future->id]['action']);
        $this->assertSame('City Assessor — SS', $items[$city->id]['completion_target']);
        $this->assertSame('Provincial Assessor — Main Branch', $items[$ropa->id]['completion_target']);
        $this->assertSame('dashboard-overdue-asset-complete-modal', $items[$city->id]['completion_modal_id']);
        $this->assertSame('PATCH', $items[$city->id]['completion_method']);
        $this->assertSame(
            route('client-folders.activities.asset-targets.complete', [$folder, $asset, $city]),
            $items[$city->id]['completion_url'],
        );
        $this->assertSame(4, substr_count($dashboard->getContent(), 'data-modal-open="dashboard-overdue-asset-complete-modal"'));
        $this->assertStringContainsString('data-dashboard-completion-target="City Assessor — SS"', $dashboard->getContent());
        $this->assertStringContainsString('data-dashboard-completion-target="Provincial Assessor — Main Branch"', $dashboard->getContent());

        $global = $this->get(route('client-folders.activities.asset-check.show', [$folder, $asset]))->assertOk();
        $global->assertSee('id="complete-asset-target-'.$city->id.'"', false)
            ->assertSee('City Assessor — SS')
            ->assertSee('data-asset-modal-close', false)
            ->assertSee('Mark Completed');

        $beforeCancel = $city->fresh()->getAttributes();
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("cancel?.addEventListener('click', () => dashboardCompletionModal.close())", $script);
        $this->assertSame($beforeCancel, $city->fresh()->getAttributes(), 'Opening or cancelling the client-side dialog sends no mutation request.');

        $this->patch(route('client-folders.activities.asset-targets.complete', [$folder, $asset, $city]), [
            'co_maker_id' => null,
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $city->fresh()->status);
        $this->assertNull($city->fresh()->scheduled_at);
        $this->assertSame(ActivityStatus::Scheduled, $ropa->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $future->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $asset->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.asset_target_completed',
            'user_id' => $ci->id,
        ]);

        $remaining = collect($this->get(route('home'))->assertOk()->viewData('workToday')->items())
            ->filter(fn (array $item): bool => $item['modal_kind'] === 'asset')
            ->keyBy('target_id');
        $this->assertFalse($remaining->has($city->id));
        $this->assertTrue($remaining->has($ropa->id));
        $this->assertTrue($remaining[$ropa->id]['direct_completion']);
        $this->assertTrue($remaining->has($future->id));
        $this->assertFalse($remaining[$future->id]['direct_completion']);

        $this->assertStringContainsString('if (!response.ok)', $script);
        $this->assertStringContainsString('error.hidden = false', $script);
        $this->assertStringContainsString('refreshDashboard()', $script);
        $this->assertStringContainsString('showToast(targetName ?', $script);
    }

    public function test_overdue_bank_targets_share_the_polished_exact_target_confirmation_and_complete_independently(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $bpi = $this->bankTarget($bank, $ci, 'BPI', CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY, ActivityStatus::Scheduled);
        $ficco = $this->bankTarget($bank, $ci, 'FICCO', CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, ActivityStatus::Scheduled);
        $future = $this->bankTarget($bank, $ci, 'Future Bank', CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY, ActivityStatus::Scheduled);
        $completed = $this->bankTarget($bank, $ci, 'MCCB');
        $bpi->update(['scheduled_at' => now()->subDays(2)]);
        $ficco->update(['scheduled_at' => now()->subDay()]);
        $future->update(['scheduled_at' => now()->addDay()]);
        $completed->update(['status' => ActivityStatus::Completed]);
        $bank->update(['status' => ActivityStatus::Scheduled]);

        $dashboard = $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertSee('id="dashboard-overdue-bank-complete-modal"', false)
            ->assertSee('data-dashboard-overdue-bank-complete-modal', false)
            ->assertSee('Complete Bank / Coop Check?')
            ->assertSee('This will mark this target as completed and record the action in Recent Activity.')
            ->assertSee('data-completion-icon="indicator"', false)
            ->assertSee('data-completion-icon="cancel"', false)
            ->assertSee('data-completion-icon="confirm"', false)
            ->assertSee('Mark as Completed')
            ->assertDontSee('data-dashboard-overdue-bank-complete-edit', false);
        $items = collect($dashboard->viewData('workToday')->items())
            ->filter(fn (array $item): bool => $item['modal_kind'] === 'bank')
            ->keyBy('target_id');

        $this->assertTrue($items[$bpi->id]['direct_completion']);
        $this->assertTrue($items[$ficco->id]['direct_completion']);
        $this->assertFalse($items[$future->id]['direct_completion']);
        $this->assertSame('Continue', $items[$bpi->id]['action']);
        $this->assertSame('Continue', $items[$ficco->id]['action']);
        $this->assertSame('Open', $items[$future->id]['action']);
        $this->assertFalse($items->has($completed->id));
        $this->assertSame('BPI', $items[$bpi->id]['completion_target']);
        $this->assertSame('Loan Inquiry', $items[$bpi->id]['completion_target_type']);
        $this->assertSame('FICCO — Main Branch', $items[$ficco->id]['completion_target']);
        $this->assertSame('Bank / Coop Check', $items[$ficco->id]['completion_target_type']);
        $this->assertSame('dashboard-overdue-bank-complete-modal', $items[$bpi->id]['completion_modal_id']);
        $this->assertSame('PATCH', $items[$bpi->id]['completion_method']);
        $this->assertSame(
            route('client-folders.activities.bank-targets.complete', [$folder, $bank, $bpi]),
            $items[$bpi->id]['completion_url'],
        );
        $this->assertSame(4, substr_count($dashboard->getContent(), 'data-modal-open="dashboard-overdue-bank-complete-modal"'));
        $this->assertStringContainsString('data-dashboard-completion-target="BPI"', $dashboard->getContent());
        $this->assertStringContainsString('data-dashboard-completion-target-type="Loan Inquiry"', $dashboard->getContent());
        $this->assertStringContainsString('data-dashboard-completion-target="FICCO — Main Branch"', $dashboard->getContent());
        $this->assertStringContainsString('data-dashboard-completion-target-type="Bank / Coop Check"', $dashboard->getContent());

        $global = $this->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk();
        $global->assertSee('id="complete-bank-target-'.$bpi->id.'"', false)
            ->assertSee('Complete Bank / Coop Check?')
            ->assertSee('BPI')
            ->assertSee('Loan Inquiry')
            ->assertSee('FICCO – Main Branch')
            ->assertSee('Bank / Coop Check')
            ->assertSee('This will mark this target as completed and record the action in Recent Activity.')
            ->assertDontSee('Mark BPI as completed?')
            ->assertSee('data-modal-close', false);

        $beforeCancel = $bpi->fresh()->getAttributes();
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("cancel?.addEventListener('click', () => dashboardCompletionModal.close())", $script);
        $this->assertSame($beforeCancel, $bpi->fresh()->getAttributes(), 'Opening or cancelling the client-side dialog sends no mutation request.');

        $this->patch(route('client-folders.activities.bank-targets.complete', [$folder, $bank, $bpi]), [
            'co_maker_id' => null,
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $bpi->fresh()->status);
        $this->assertNull($bpi->fresh()->scheduled_at);
        $this->assertSame(ActivityStatus::Scheduled, $ficco->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $future->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $completed->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $bank->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.bank_target_completed',
            'user_id' => $ci->id,
        ]);

        $remaining = collect($this->get(route('home'))->assertOk()->viewData('workToday')->items())
            ->filter(fn (array $item): bool => $item['modal_kind'] === 'bank')
            ->keyBy('target_id');
        $this->assertFalse($remaining->has($bpi->id));
        $this->assertTrue($remaining->has($ficco->id));
        $this->assertTrue($remaining[$ficco->id]['direct_completion']);
        $this->assertTrue($remaining->has($future->id));
        $this->assertFalse($remaining[$future->id]['direct_completion']);

        $this->assertStringContainsString('if (!response.ok)', $script);
        $this->assertStringContainsString('dashboardCompletionModal.close();', $script);
        $this->assertStringContainsString('showToast(targetName ?', $script);
        $this->assertStringContainsString('refreshDashboard()', $script);
    }

    public function test_target_saves_update_only_the_selected_target_and_parent_progress(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $bankFirst = $this->bankTarget($bank, $ci, 'First Bank');
        $bankRemaining = $this->bankTarget(
            $bank,
            $ci,
            'Remaining Loan Inquiry',
            CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            ActivityStatus::Scheduled,
        );
        $assetFirst = $this->assetTarget($asset, $ci, 'First Assessor');
        $assetRemaining = $this->assetTarget($asset, $ci, 'Remaining Assessor');

        $this->actingAs($ci)->putJson(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankFirst]), [
            'co_maker_id' => null,
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'First Bank',
            'status' => ActivityStatus::FollowUp->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->assertSame(ActivityStatus::Pending, $bankFirst->fresh()->status);

        $this->actingAs($ci)->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankFirst]), [
            'co_maker_id' => null,
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'First Bank Updated',
            'status' => ActivityStatus::Completed->value,
        ])->assertRedirect();
        $this->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $assetFirst]), [
            'co_maker_id' => null,
            'assessor_type' => 'city_assessor',
            'office_location' => 'First Assessor Updated',
            'status' => ActivityStatus::Completed->value,
        ])->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $bankFirst->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $bankRemaining->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $bank->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $assetFirst->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $assetRemaining->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $asset->fresh()->status);

        $work = collect($this->get(route('home'))->assertOk()->viewData('workToday')->items());
        $this->assertFalse($work->contains(fn (array $item): bool => $item['modal_kind'] === 'bank' && $item['target_id'] === $bankFirst->id));
        $remainingBankItem = $work->first(fn (array $item): bool => $item['modal_kind'] === 'bank' && $item['target_id'] === $bankRemaining->id);
        $this->assertNotNull($remainingBankItem);
        $this->assertSame(CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY, $remainingBankItem['target_type']);
        $this->assertSame('Overdue', $remainingBankItem['status']);
        $this->assertFalse($work->contains(fn (array $item): bool => $item['modal_kind'] === 'asset' && $item['target_id'] === $assetFirst->id));
        $this->assertTrue($work->contains(fn (array $item): bool => $item['modal_kind'] === 'asset' && $item['target_id'] === $assetRemaining->id));
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => 'DASHBOARD, EXACT CLIENT',
        ]);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function bankTarget(
        CiActivity $activity,
        User $actor,
        string $name,
        string $type = CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
        ActivityStatus $status = ActivityStatus::Pending,
    ): CiActivityBankTarget {
        return $activity->bankTargets()->create([
            'inquiry_type' => $type,
            'institution_name' => $name,
            'branch_location' => $type === CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK ? 'Main Branch' : null,
            'status' => $status,
            'scheduled_at' => $status === ActivityStatus::Scheduled ? now()->subDay() : null,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function assetTarget(CiActivity $activity, User $actor, string $location): CiActivityAssetTarget
    {
        return $activity->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => $location,
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
