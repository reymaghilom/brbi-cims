<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityTrackerSaveUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ==================================================
    // No-changes message: wording, styling, one shared implementation
    // ==================================================

    public function test_no_changes_message_uses_the_new_wording_and_the_old_wording_is_gone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $barangay]));
        $content = $page->assertOk()->getContent();

        $this->assertStringContainsString('No changes detected. Nothing needs to be updated.', $content);
        $this->assertStringNotContainsString('No changes to save.', $content);
    }

    public function test_no_changes_notice_is_a_polished_informational_banner_not_plain_text(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $barangay]));
        $content = $page->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<p class="flex items-start gap-1\.5 rounded-control border border-progress\/30 bg-progress-soft px-3 py-2 text-sm font-semibold text-progress" data-default-check-no-changes role="status" aria-live="polite" hidden>/',
            $content,
        );
        $this->assertStringContainsString('data-default-check-no-changes', $content);
        // Decorative icon: the visible text already carries the meaning.
        $this->assertMatchesRegularExpression(
            '/data-default-check-no-changes[^>]*>.*?aria-hidden="true"/s',
            $content,
        );
        // Never the alarming/error styling.
        $this->assertStringNotContainsString('bg-danger-soft" data-default-check-no-changes', $content);
    }

    public function test_editing_a_field_hides_the_no_changes_message_via_one_shared_listener(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        // Exactly one shared hide-on-edit helper serving both Barangay and Neighbor (same template).
        $this->assertSame(1, substr_count($content, 'const hideNoChangesMessage = ()'));
        $this->assertStringContainsString("form.addEventListener('input', hideNoChangesMessage)", $content);
        $this->assertStringContainsString("form.addEventListener('change', hideNoChangesMessage)", $content);
    }

    // ==================================================
    // Auto-close after a real successful save
    // ==================================================

    public function test_successful_save_path_synchronizes_then_closes_the_tracker_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        // synchronizeTable + history insertion must happen before modal.close(), inside the
        // success branch, and that branch must return before the catch/error path.
        $this->assertMatchesRegularExpression(
            '/synchronizeTable\(freshSource\);.*?insertCiActivityHistoryEntries\(payload\.history\);\s*\n\s*\n?\s*.*?modal\.close\(\);\s*\n\s*return;\s*\n\s*\} catch \(requestError\) \{/s',
            $content,
        );
    }

    public function test_failure_path_does_not_close_the_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/if \(! response\.ok\) \{\s*if \(errors instanceof HTMLElement\) \{.*?\}\s*return;\s*\}/s',
            $content,
        );
    }

    // ==================================================
    // Real Barangay / Neighbor save still succeeds and produces authoritative state
    // ==================================================

    public function test_barangay_real_save_succeeds_and_returns_authoritative_history(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '09:00',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ]);

        $response->assertOk();
        $fresh = $barangay->fresh();
        $this->assertSame(ActivityStatus::Scheduled, $fresh->status);
        $this->assertSame(1, AuditLog::where('action', 'ci_activity.scheduled')->where('metadata->activity_id', $barangay->id)->count());
    }

    public function test_neighbor_real_save_succeeds_identically_to_barangay(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $neighbor]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'expected_updated_at' => $neighbor->updated_at->toISOString(),
        ]);

        $response->assertOk();
        $fresh = $neighbor->fresh();
        $this->assertSame(ActivityStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_remarks_only_change_still_persists_and_audits(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['remarks' => 'Original remarks']);

        $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'pending',
            'remarks' => 'Updated remarks after visit.',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $this->assertSame('Updated remarks after visit.', $barangay->fresh()->remarks);
    }

    // ==================================================
    // Revert-to-original still counts as no change (value comparison, not event tracking)
    // ==================================================

    public function test_change_then_revert_to_original_value_is_not_treated_as_dirty_by_the_shared_comparator(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        // isDirty() re-reads current field values and diffs them against the captured baseline
        // on every call — it is not a "something fired an input event" flag — so a value that
        // ends up matching the original is correctly seen as unchanged regardless of the edits
        // made in between.
        $this->assertMatchesRegularExpression(
            '/const isDirty = \(\) => \{\s*const current = readValues\(\);\s*return Object\.keys\(baseline\)\.some\(\(key\) => baseline\[key\] !== current\[key\]\);\s*\};/',
            $content,
        );
    }

    // ==================================================
    // Applicant / Co-Maker isolation for the save itself
    // ==================================================

    public function test_save_stays_scoped_to_the_exact_activity_and_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'Scope Maker',
            'first_name' => 'Scope',
            'last_name' => 'Maker',
        ]);
        $applicantBarangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => null]);
        $coMakerBarangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $coMakerBarangay]), [
            'co_maker_id' => $coMaker->id,
            'status' => 'completed',
            'expected_updated_at' => $coMakerBarangay->updated_at->toISOString(),
        ])->assertOk();

        $this->assertSame(ActivityStatus::Completed, $coMakerBarangay->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $applicantBarangay->fresh()->status);
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

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
