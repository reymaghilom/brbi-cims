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

class CiActivityQuickCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_bulk_selection_is_absent_and_completion_controls_are_the_only_activity_checkboxes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertCheckboxState($content, $barangay->id, false, false);
        $this->assertCheckboxState($content, $neighbor->id, true, true);
        foreach ([$barangay, $neighbor, $bank, $asset] as $activity) {
            $this->assertActivityRowHasNoDecorativeIcon($content, $activity->id);
        }
        $this->assertSame(4, preg_match_all('/<input[^>]+data-ci-activity-completion=/', $content));
        $this->assertSame(0, preg_match_all('/<input[^>]+data-ci-activity-select/', $content));
        $this->assertStringNotContainsString('data-ci-select-all', $content);
        $this->assertStringNotContainsString('data-bulk-delete-button', $content);
        $this->assertStringNotContainsString('data-clear-selected-button', $content);
        $this->assertStringNotContainsString('bulk-delete-activities', $content);
        $this->assertStringNotContainsString('Delete Selected', $content);
        $this->assertStringNotContainsString("document.querySelectorAll('[data-ci-activity-select]')", $content);
        $this->assertStringContainsString('data-default-check-open="'.$barangay->id.'"', $content);
        $this->assertStringContainsString('data-default-check-open="'.$neighbor->id.'"', $content);
        $this->assertStringContainsString('data-bank-coop-open="'.$bank->id.'"', $content);
        $this->assertStringContainsString('data-asset-check-open="'.$asset->id.'"', $content);
        $this->assertStringContainsString('aria-label="Edit Barangay Check"', $content);
        $this->assertStringContainsString('aria-label="Edit Neighbor Check"', $content);
        $this->assertStringContainsString('aria-label="Edit Bank / Coop Check"', $content);
        $this->assertStringContainsString('aria-label="Edit Asset Check"', $content);
        $this->assertStringContainsString('aria-label="Mark Barangay Check as completed"', $content);
        $this->assertStringContainsString('aria-label="Neighbor Check completed"', $content);
        $this->assertStringContainsString('aria-label="Open Bank / Coop Check tracker to complete remaining targets"', $content);
        $this->assertStringContainsString('aria-label="Open Asset Check tracker to complete remaining targets"', $content);
        $this->assertStringNotContainsString('title="View"', $content);
        $this->assertStringNotContainsString('id="delete-activity-'.$barangay->id.'"', $content);
        $this->assertStringNotContainsString('id="delete-activity-'.$neighbor->id.'"', $content);
        $this->assertStringContainsString('id="delete-activity-'.$bank->id.'"', $content);
        $this->assertStringContainsString('id="delete-activity-'.$asset->id.'"', $content);
    }

    public function test_bank_and_asset_completion_controls_reflect_all_targets_and_open_trackers_when_incomplete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        foreach ([ActivityStatus::Completed, ActivityStatus::Completed] as $index => $status) {
            $bank->bankTargets()->create([
                'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
                'institution_name' => 'Bank '.($index + 1),
                'status' => $status,
                'scheduled_has_time' => false,
                'created_by' => $ci->id,
                'updated_by' => $ci->id,
            ]);
            $asset->assetTargets()->create([
                'assessor_type' => 'city_assessor',
                'office_location' => 'Office '.($index + 1),
                'status' => $status,
                'scheduled_has_time' => false,
                'created_by' => $ci->id,
                'updated_by' => $ci->id,
            ]);
        }

        $completePage = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertCheckboxState($completePage, $bank->id, true, true);
        $this->assertCheckboxState($completePage, $asset->id, true, true);

        $bank->bankTargets()->first()->update(['status' => ActivityStatus::FollowUp]);
        $asset->assetTargets()->first()->update(['status' => ActivityStatus::Pending]);
        $incompletePage = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertCheckboxState($incompletePage, $bank->id, false, false);
        $this->assertCheckboxState($incompletePage, $asset->id, false, false);
        $this->assertStringContainsString("checkbox.dataset.completionKind !== 'default'", $incompletePage);
        $this->assertStringContainsString("checkbox.dataset.completionKind === 'bank' ? 'data-bank-coop-open' : 'data-asset-check-open'", $incompletePage);
        $this->assertDoesNotMatchRegularExpression('/data-ci-activity-completion="'.$bank->id.'"[^>]+data-completion-update-url/', $incompletePage);
        $this->assertDoesNotMatchRegularExpression('/data-ci-activity-completion="'.$asset->id.'"[^>]+data-completion-update-url/', $incompletePage);
    }

    public function test_confirmed_default_quick_completion_uses_centralized_workflow_and_cancel_has_no_submit_path(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $folder = $this->folderFor($creator);

        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE] as $code) {
            $activity = $this->activity($folder, $creator, $code);
            $activity->update([
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => now()->addDay(),
                'scheduled_has_time' => true,
                'reminder_sent_at' => now(),
            ]);

            $this->actingAs($updater)->putJson(route('client-folders.activities.update', [$folder, $activity]), [
                'co_maker_id' => null,
                'expected_updated_at' => $activity->updated_at->toISOString(),
                'status' => ActivityStatus::Completed->value,
                'intent' => 'return',
            ])->assertOk()->assertJson(['updated' => true]);

            $activity->refresh();
            $this->assertSame(ActivityStatus::Completed, $activity->status);
            $this->assertSame($creator->id, $activity->creator_id);
            $this->assertSame($updater->id, $activity->updated_by);
            $this->assertNotNull($activity->completed_at);
            $this->assertNull($activity->scheduled_at);
            $this->assertFalse($activity->scheduled_has_time);
            $this->assertNull($activity->reminder_sent_at);
            $this->assertTrue(AuditLog::query()->where('action', 'ci_activity.completed')->where('user_id', $updater->id)->whereJsonContains('metadata->activity_id', $activity->id)->exists());
        }

        $content = $this->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Mark Barangay Check as completed?', str_replace('${checkbox.dataset.completionName ?? \'activity\'}', 'Barangay Check', $content));
        $this->assertStringContainsString("cancel.addEventListener('click', () => modal.close());", $content);
        $this->assertStringContainsString("confirm.addEventListener('click', async () =>", $content);
        $this->assertStringContainsString("confirm.textContent = 'Completing…';", $content);
        $this->assertStringContainsString('checkbox.disabled = true;', $content);
        $this->assertStringContainsString('checkbox.disabled = false;', $content);
    }

    public function test_derived_parent_status_cannot_be_bypassed_through_the_activity_update_endpoint(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        foreach ([ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityDefinition::ASSET_CHECK_CODE] as $code) {
            $activity = $this->activity($folder, $ci, $code);

            $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $activity]), [
                'co_maker_id' => null,
                'status' => ActivityStatus::Completed->value,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');

            $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
            $this->assertNull($activity->completed_at);
        }
    }

    private function assertCheckboxState(string $content, int $activityId, bool $checked, bool $disabled): void
    {
        $this->assertSame(1, preg_match('/<input(?=[^>]*data-ci-activity-completion="'.$activityId.'")[^>]*>/', $content, $matches));
        $this->assertSame($checked, str_contains($matches[0], ' checked'));
        $this->assertSame($disabled, str_contains($matches[0], ' disabled'));
    }

    private function assertActivityRowHasNoDecorativeIcon(string $content, int $activityId): void
    {
        $this->assertSame(1, preg_match('/<tr(?=[^>]*data-ci-activity-id="'.$activityId.'")[^>]*>.*?<\/tr>/s', $content, $matches));
        $this->assertStringNotContainsString('<x-ui.icon name="report"', $matches[0]);
        $this->assertStringNotContainsString('place-items-center rounded-full border border-ui-border bg-surface-subtle', $matches[0]);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, ActivityStatus $status = ActivityStatus::Pending): CiActivity
    {
        $definition = ActivityDefinition::query()->updateOrCreate(
            ['code' => $code],
            ['name' => match ($code) {
                ActivityDefinition::BARANGAY_CHECK_CODE => 'Barangay Check',
                ActivityDefinition::NEIGHBOR_CHECK_CODE => 'Neighbor Check',
                ActivityDefinition::BANK_COOP_CHECK_CODE => 'Bank / Coop Check',
                default => 'Asset Check',
            }, 'sort_order' => 10, 'is_required' => in_array($code, [ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE], true), 'is_active' => true],
        );

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => $status,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            'creator_id' => $creator->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
