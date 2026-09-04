<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityTableAutoUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // Shared row/counter synchronization wiring (static)
    // ==================================================

    public function test_shared_tab_counter_helper_is_defined_once_and_invoked_from_the_single_shared_sync_path(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'const updateTabCounts = ()'));
        $this->assertSame(1, substr_count($content, 'updateTabCounts();'));
        // It must live inside the one shared `ci-bank-coop-updated` handler that Barangay,
        // Neighbor, Bank, and Asset all dispatch into — not a per-type duplicate.
        $this->assertMatchesRegularExpression(
            "/document\.addEventListener\('ci-bank-coop-updated', \(event\) => \{.*?updateTabCounts\(\);\s*\}\);/s",
            $content,
        );
    }

    public function test_no_full_page_reload_is_introduced(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()->assertDontSee('window.location.reload', false);
    }

    public function test_no_stale_refresh_function_reference_remains(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()->assertDontSee('refreshCiActivityHistory', false);
    }

    public function test_asset_row_sync_uses_the_stable_status_badge_hook_instead_of_a_fragile_column_index(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertStringNotContainsString('td:nth-child(3) span', $content);
        $this->assertStringContainsString('data-ci-activity-status-badge="${activityId}"', $content);
    }

    // ==================================================
    // Bank / Asset tracker: Updated date/user now exposed and synchronized
    // ==================================================

    public function test_bank_tracker_response_exposes_updated_date_and_user_for_row_sync(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $this->bankTarget($bank, $ci, 'BDO');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk()->getContent();

        $this->assertStringContainsString('data-bank-coop-updated-date="', $content);
        $this->assertStringContainsString('data-bank-coop-updated-detail="', $content);
        $this->assertStringContainsString($ci->full_name, $content);
    }

    public function test_asset_tracker_response_exposes_updated_date_and_user_for_row_sync(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.asset-check.show', [$folder, $asset]))->assertOk()->getContent();

        $this->assertStringContainsString('data-asset-updated-date="', $content);
        $this->assertStringContainsString('data-asset-updated-detail="', $content);
    }

    public function test_bank_completion_advances_the_updated_timestamp_visible_to_row_sync(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $this->bankTarget($bank, $ci, 'BDO');

        $this->travel(1)->minutes();
        $complete = $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete', [$folder, $bank, $target]), ['co_maker_id' => '']);
        $content = $this->get($complete->headers->get('Location'))->assertOk()->getContent();

        $this->assertStringContainsString('data-bank-coop-updated-timestamp="'.$bank->fresh()->updated_at->timestamp.'"', $content);
    }

    // ==================================================
    // Submission: row cell auto-updates from the SAME response, no reload
    // ==================================================

    public function test_submission_form_carries_a_stable_hook_and_uses_auto_update_not_reload(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertStringContainsString('data-ci-submission-form="', $content);
        $this->assertStringContainsString("querySelectorAll('[data-ci-submission-form]')", $content);
    }

    public function test_mark_as_submitted_json_response_returns_the_authoritative_updated_cell_and_history(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed]);

        $response = $this->actingAs($ci)->patchJson(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'co_maker_id' => '',
            'submitted_to' => 'Jane Analyst',
        ]);

        $response->assertOk()->assertJson(['submitted' => true]);
        $payload = $response->json();
        $this->assertStringContainsString('Submitted', $payload['cell']);
        $this->assertStringContainsString('View / Update', $payload['cell']);
        $this->assertStringContainsString('To: Jane Analyst', $payload['cell']);
        $this->assertStringNotContainsString('Mark as Submitted<', $payload['cell']);

        $audit = AuditLog::where('action', 'ci_activity.submitted')->where('metadata->activity_id', $activity->id)->sole();
        $this->assertCount(1, $payload['history']);
        $this->assertStringContainsString('data-ci-history-entry-id="'.$audit->id.'"', $payload['history'][0]);
    }

    public function test_submission_only_creates_one_audit_entry_no_duplicates(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed]);

        $this->actingAs($ci)->patchJson(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'co_maker_id' => '',
        ])->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'ci_activity.submitted')->where('metadata->activity_id', $activity->id)->count());
    }

    public function test_failed_submission_does_not_falsely_report_success_or_create_history(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerActivity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed, 'co_maker_id' => null]);
        $otherCi = User::factory()->create();
        $otherFolder = $this->folderFor($otherCi);
        $unrelatedActivity = $this->activity($otherFolder, $otherCi, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed]);

        // Wrong folder context for this activity: must be rejected, not silently "succeed".
        $response = $this->actingAs($ci)->patchJson(route('client-folders.activities.submit', [$folder, $unrelatedActivity]), [
            'submission_activity_id' => $unrelatedActivity->id,
            'co_maker_id' => '',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ci_activity.submitted', 'metadata->activity_id' => $unrelatedActivity->id]);
    }

    // ==================================================
    // Applicant / Co-Maker isolation
    // ==================================================

    public function test_submission_response_stays_scoped_to_the_exact_activity_and_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed, 'co_maker_id' => null]);

        $response = $this->actingAs($ci)->patchJson(route('client-folders.activities.submit', [$folder, $applicant]), [
            'submission_activity_id' => $applicant->id,
            'co_maker_id' => '',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('ci_activities', ['id' => $applicant->id, 'co_maker_id' => null]);
        $this->assertNotNull($applicant->fresh()->submitted_at);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, array $overrides = []): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
        ], $overrides));
    }

    private function bankTarget(CiActivity $activity, User $actor, string $name): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $name,
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
