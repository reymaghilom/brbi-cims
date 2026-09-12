<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Progress\MandatoryInvestigationRequirements;
use App\Services\Reports\ReportWorkspaceQuery;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Active Client Folders, In Progress, Completed This Month and Reports Ready follow the Needs
 * Attention pattern: interactive card when count > 0, a detail modal listing exactly the set the
 * card counts, actionable rows. Clock: 2026-09-15 8:00 PM Asia/Manila.
 */
class DashboardKpiDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        $this->ci = User::factory()->create(['full_name' => 'Rey Investigator']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_zero_state_cards_are_plain_and_render_no_detail_modals(): void
    {
        $html = $this->home()->getContent();

        $this->assertStringNotContainsString('data-kpi-clickable', $html);
        foreach (['active-folders', 'in-progress', 'completed-month', 'reports-ready', 'needs-attention'] as $modal) {
            $this->assertStringNotContainsString('dashboard-'.$modal.'-dialog', $html);
        }
        $this->assertStringContainsString('No completed reports yet', $html);
    }

    public function test_active_folders_card_opens_a_most_recently_updated_first_list(): void
    {
        $older = $this->folder('OLDER, CLIENT');
        $older->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        $newer = $this->folder('NEWER, CLIENT');

        $response = $this->home();
        $html = $response->getContent();
        $modal = $this->modal($html, 'dashboard-active-folders-dialog');

        $this->assertSame(2, $response->viewData('summary')['assigned']);
        $this->assertInteractiveCard($html, 'dashboard-active-folders-dialog', 'Active Client Folders: 2, view details');
        $this->assertStringContainsString('2 active client folders', $modal);
        $this->assertSame(['NEWER, CLIENT', 'OLDER, CLIENT'], array_column($response->viewData('kpiDetails')['active'], 'client'));
        $this->assertStringContainsString('href="'.route('client-folders.show', $newer).'"', $modal);
        $this->assertStringContainsString('Rey Investigator', $modal);
        $this->assertSame(2, substr_count($modal, '<li data-kpi-detail-row'));

        $older->delete();
        $this->assertStringContainsString('1 active client folder<', $this->modal($this->home()->getContent(), 'dashboard-active-folders-dialog'));
    }

    public function test_in_progress_lists_each_folders_missing_mandatory_work_per_person(): void
    {
        $folder = $this->completeFolder('OBASA, REYNALDO SSS');
        $juan = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'JUAN DELA CRUZ']);
        $this->completePerson($folder, $juan->id, neighbor: ActivityStatus::Pending);
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA SANTOS']);
        $this->completePerson($folder, $maria->id);
        $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending); // optional
        $this->completeFolder('DONE, CLIENT'); // every mandatory item met: not In Progress
        $empty = $this->folder('EMPTY, CLIENT');

        $response = $this->home();
        $details = collect($response->viewData('kpiDetails')['in_progress'])->keyBy('client');
        $modal = $this->modal($response->getContent(), 'dashboard-in-progress-dialog');

        // EMPTY, CLIENT has not started: 0 of 7 mandatory requirements met, so In Progress does
        // not claim it. Only the part-finished folder is listed.
        $this->assertSame(1, $response->viewData('summary')['in_progress']);
        $this->assertSame(['OBASA, REYNALDO SSS'], $details->keys()->all());
        $obasa = $details['OBASA, REYNALDO SSS']['progress'];
        $this->assertSame(['completed' => 14, 'total' => 15, 'percent' => 93, 'missing' => ['Co-Maker: JUAN DELA CRUZ — Neighbor Check']], $obasa);
        $this->assertStringContainsString('1 client folder with incomplete mandatory requirements', $modal);
        $this->assertStringContainsString('Co-Maker: JUAN DELA CRUZ — Neighbor Check', $modal);
        $this->assertStringContainsString('14 of 15 mandatory requirements complete', $modal);
        $this->assertStringNotContainsString('MARIA SANTOS', $modal);
        $this->assertStringNotContainsString('>Asset Check<', $modal);
        $this->assertStringNotContainsString('href="'.route('client-folders.show', $empty).'"', $modal);
        $this->assertSame(array_values(MandatoryInvestigationRequirements::APPLICANT), $response->viewData('kpiDetails')['active'][0]['progress']['missing'] ?? []);
        $this->assertInteractiveCard($response->getContent(), 'dashboard-in-progress-dialog', 'In Progress: 1, view details');
    }

    public function test_completed_this_month_lists_only_this_months_completions_newest_first(): void
    {
        $this->folder('EARLY, CLIENT', ClientFolderStatus::Completed, '2026-09-02 02:00:00');
        $this->folder('LATE, CLIENT', ClientFolderStatus::Completed, '2026-09-10 02:00:00');
        // 2026-08-31 11:30 PM in Manila: last month locally, even though the UTC date is also Aug 31.
        $this->folder('AUGUST, CLIENT', ClientFolderStatus::Completed, '2026-08-31 15:30:00');
        $this->folder('OPEN, CLIENT');

        $response = $this->home();
        $modal = $this->modal($response->getContent(), 'dashboard-completed-month-dialog');

        $this->assertSame(2, $response->viewData('summary')['completed_this_month']);
        $this->assertSame(['LATE, CLIENT', 'EARLY, CLIENT'], array_column($response->viewData('kpiDetails')['completed_this_month'], 'client'));
        $this->assertStringContainsString('2 client folders completed this month', $modal);
        $this->assertStringContainsString('Completed Sep 10, 2026', $modal);
        $this->assertStringNotContainsString('AUGUST, CLIENT', $modal);
        $this->assertInteractiveCard($response->getContent(), 'dashboard-completed-month-dialog', 'Completed This Month: 2, view details');
    }

    public function test_reports_ready_lists_completed_records_by_folder_and_person_and_matches_global_reports(): void
    {
        $folder = $this->folder('REPORTS, CLIENT');
        $juan = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'JUAN DELA CRUZ']);
        $this->cibi($folder, null);
        $this->cibi($folder, $juan->id);
        (new ResidenceCheck)->forceFill(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id])->save();
        CibiReport::factory()->create(['client_folder_id' => $this->folder('DRAFT, CLIENT')->id, 'co_maker_id' => null, 'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Draft]);
        foreach (range(1, 3) as $version) {
            GeneratedReport::create([
                'client_folder_id' => $folder->id, 'scope_key' => 'folder:'.$folder->id.':cibi', 'source_type' => 'cibi', 'report_type' => 'cibi',
                'format' => 'pdf', 'version' => $version, 'status' => GenerationStatus::Completed, 'generated_by' => $this->ci->id,
            ]);
        }

        $response = $this->home();
        $groups = $response->viewData('kpiDetails')['reports_ready'];
        $modal = $this->modal($response->getContent(), 'dashboard-reports-ready-dialog');

        $this->assertSame(3, $response->viewData('summary')['reports_ready']);
        $this->assertSame(app(ReportWorkspaceQuery::class)->summary($this->ci)['completed'], $response->viewData('summary')['reports_ready']);
        $this->assertCount(1, $groups);
        $this->assertSame(['Applicant', 'Co-Maker: JUAN DELA CRUZ'], array_column($groups[0]['people'], 'person'));
        $this->assertEqualsCanonicalizing(['CI / BI Report', 'Residence Check'], array_column($groups[0]['people'][0]['reports'], 'label'));
        $this->assertSame(['CI / BI Report'], array_column($groups[0]['people'][1]['reports'], 'label'));
        $this->assertStringContainsString('3 completed reports ready for release', $modal);
        $this->assertStringContainsString('>3 reports<', $modal);
        $this->assertStringNotContainsString('DRAFT, CLIENT', $modal);
        // Safe navigation only: a read-only preview or the exact person's folder, never an export.
        $this->assertStringContainsString('href="'.e(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'cibi', 'person' => 'co-maker', 'co_maker_id' => $juan->id])).'"', $modal);
        $this->assertStringNotContainsString('export-pdf', $modal);
        $this->assertInteractiveCard($response->getContent(), 'dashboard-reports-ready-dialog', 'Reports Ready: 3, view details');
    }

    private function assertInteractiveCard(string $html, string $modalId, string $ariaLabel): void
    {
        preg_match('/<button\s+type="button"\s+data-modal-open="'.$modalId.'"[^>]*>/', $html, $button);
        $this->assertNotEmpty($button, "{$modalId} card must be a button.");
        foreach (['data-kpi-clickable', 'aria-haspopup="dialog"', 'cursor-pointer', 'focus-visible:ring-2', 'aria-label="'.$ariaLabel.'"'] as $expected) {
            $this->assertStringContainsString($expected, $button[0]);
        }
        $this->assertStringContainsString('id="'.$modalId.'"', $html);
    }

    private function modal(string $html, string $id): string
    {
        $start = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($start, "{$id} not rendered.");

        return substr($html, $start, strpos($html, '</dialog>', $start) - $start);
    }

    private function home()
    {
        return $this->actingAs($this->ci)->get(route('home'))->assertOk();
    }

    private function folder(string $name, ClientFolderStatus $status = ClientFolderStatus::OnProgress, ?string $completedAtUtc = null): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'display_name' => $name,
            'status' => $status, 'completed_at' => $completedAtUtc ? Carbon::parse($completedAtUtc, 'UTC') : null,
        ]);
    }

    private function completeFolder(string $name): ClientFolder
    {
        $folder = $this->folder($name);
        $this->completePerson($folder, null);
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
            'state' => RecordState::Complete, 'revision' => 2,
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => 'Store', 'report_category' => 'retail'])->save();
        (new BusinessCheck)->forceFill(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id])->save();
        $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Completed);

        return $folder;
    }

    private function completePerson(ClientFolder $folder, ?int $coMakerId, ActivityStatus $neighbor = ActivityStatus::Completed): void
    {
        $this->cibi($folder, $coMakerId);
        (new ResidenceCheck)->forceFill(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id])->save();
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed, $coMakerId);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $neighbor, $coMakerId);
    }

    private function cibi(ClientFolder $folder, ?int $coMakerId): void
    {
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);
    }

    private function activity(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): void
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();
        CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => $status, 'completed_at' => $status === ActivityStatus::Completed ? now() : null, 'creator_id' => $this->ci->id,
        ]);
    }
}
