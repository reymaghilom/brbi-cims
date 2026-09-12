<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\Notifications\ScheduledTodayNotificationFeed;
use App\Services\Progress\ClientProgressService;
use App\Services\Progress\MandatoryInvestigationRequirements;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDefaultCiActivities;
use Tests\TestCase;

/**
 * The 3-dot Actions menu on Barangay Check / Neighbor Check rows.
 *
 * These two are manually added built-in types now, so they carry the same Open + Delete menu Asset
 * Check has always had. Deleting one removes only that exact person's activity: the requirement
 * stays mandatory and simply reads incomplete again, the folder's progress and status are
 * recalculated authoritatively, and the type becomes addable again for that person alone.
 */
class DefaultCheckRowActionsTest extends TestCase
{
    use CreatesDefaultCiActivities, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN',
        ]);
    }

    private function activityFor(ClientFolder $folder, string $code, ?int $coMakerId = null): ?CiActivity
    {
        return CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $coMakerId)
            ->whereHas('definition', fn ($query) => $query->where('code', $code))
            ->first();
    }

    private function deleteActivity(User $actor, ClientFolder $folder, CiActivity $activity, ?int $coMakerId = null)
    {
        return $this->actingAs($actor)->delete(
            route('client-folders.activities.destroy', [$folder, $activity]),
            ['co_maker_id' => $coMakerId ?? '']
        );
    }

    private function addActivity(User $actor, ClientFolder $folder, string $code, ?int $coMakerId = null)
    {
        return $this->actingAs($actor)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerId ?? '',
            'create_new_activity_type' => '0',
            'activity_definition_id' => ActivityDefinition::query()->where('code', $code)->sole()->id,
            'status' => ActivityStatus::Pending->value,
            'intent' => 'return',
        ]);
    }

    // =====================================================================================
    // The menu itself
    // =====================================================================================

    public function test_both_rows_carry_the_same_three_dot_menu_asset_check_uses(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->createDefaultCiActivities($folder, null, $ci);
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        // The identical trigger Asset Check renders.
        $this->assertSame(2, substr_count($content, 'ui-dots-trigger !size-8'));
        foreach ([$barangay, $neighbor] as $activity) {
            $this->assertStringContainsString('aria-label="Actions for '.$activity->display_name.'"', $content);
            // Open keeps the existing default-check hook and route.
            $this->assertStringContainsString('data-default-check-open="'.$activity->id.'"', $content);
            $this->assertStringContainsString(
                'data-default-check-url="'.e(route('client-folders.activities.default-check.show', [$folder, $activity])).'"',
                $content
            );
            $this->assertStringContainsString('data-modal-open="delete-activity-'.$activity->id.'"', $content);
        }

        // Both items are menu items with the project's own icons, and Delete is destructive.
        $this->assertSame(2, substr_count($content, 'class="client-folder-menu-item" data-default-check-open'));
        $this->assertSame(2, substr_count($content, 'client-folder-menu-item text-danger" data-modal-open="delete-activity-'));
        $this->assertStringContainsString('Open</button>', $content);

        // The confirmation dialog now exists for these rows and says what deleting one costs,
        // without ever implying the definition or the requirement itself is being removed.
        $this->assertStringContainsString('id="delete-activity-'.$barangay->id.'"', $content);
        $this->assertStringContainsString('id="delete-activity-'.$neighbor->id.'"', $content);
        $this->assertStringContainsString('Delete Barangay Check?', $content);
        $this->assertStringContainsString('Delete Neighbor Check?', $content);
        $this->assertStringContainsString(
            'Removing this activity will make Barangay Check incomplete for this Applicant until it is added and completed again.',
            $content
        );
        $this->assertStringContainsString(
            'Removing this activity will make Neighbor Check incomplete for this Applicant until it is added and completed again.',
            $content
        );
        $this->assertStringContainsString('This action cannot be undone.', $content);
        $this->assertStringNotContainsString('Permanently delete Barangay Check?', $content);
        $this->assertStringNotContainsString('Permanently delete Neighbor Check?', $content);

        // No Assigned CI surface was reintroduced.
        $this->assertStringNotContainsString('Assigned CI', $content);
        $this->assertStringNotContainsString('assigned_ci_id', $content);
    }

    public function test_open_resolves_the_exact_person_activity(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->createDefaultCiActivities($folder, null, $ci);
        $this->createDefaultCiActivities($folder, $maria, $ci);

        $applicantBarangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $coMakerNeighbor = $this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $maria->id);

        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $applicantBarangay]))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [
            $folder, $coMakerNeighbor, 'person' => 'co-maker', 'co_maker_id' => $maria->id,
        ]))->assertOk();

        // A Co-Maker's row can never be opened under the Applicant context.
        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $coMakerNeighbor]))
            ->assertNotFound();
    }

    // =====================================================================================
    // Delete: exact instance only
    // =====================================================================================

    public function test_each_person_and_type_deletes_independently(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);
        $this->createDefaultCiActivities($folder, null, $ci);
        $this->createDefaultCiActivities($folder, $maria, $ci);
        $this->createDefaultCiActivities($folder, $pedro, $ci);
        $this->assertSame(6, CiActivity::query()->count());

        // Applicant Barangay only.
        $this->deleteActivity($ci, $folder, $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE))
            ->assertRedirect();
        $this->assertNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $pedro->id));

        // Co-Maker A Neighbor only — Co-Maker B untouched.
        $this->deleteActivity($ci, $folder, $this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $maria->id), $maria->id)
            ->assertRedirect();
        $this->assertNull($this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $maria->id));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $pedro->id));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id));

        $this->assertSame(4, CiActivity::query()->count());

        // The built-in definitions themselves are untouched and still protected.
        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE] as $code) {
            $definition = ActivityDefinition::query()->where('code', $code)->sole();
            $this->assertTrue($definition->is_active);
            $this->assertTrue(ActivityDefinition::isMandatoryDefaultCode($definition->code));
        }
    }

    public function test_a_forged_cross_person_delete_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->createDefaultCiActivities($folder, $maria, $ci);
        $coMakerBarangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id);

        // Claiming the Applicant context for a Co-Maker's row must not delete it.
        $this->deleteActivity($ci, $folder, $coMakerBarangay)->assertNotFound();
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id));

        // An unauthenticated request is rejected outright (the nested scoped binding refuses it
        // before anything is touched).
        $this->delete(route('client-folders.activities.destroy', [$folder, $coMakerBarangay]))
            ->assertNotFound();
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id));
    }

    // =====================================================================================
    // After delete: addable again, with a new Creator
    // =====================================================================================

    public function test_a_deleted_type_becomes_addable_again_for_that_person_only(): void
    {
        $juan = User::factory()->create();
        $maria = User::factory()->create();
        $folder = $this->folder($juan);
        $this->addActivity($juan, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->assertSame($juan->id, $barangay->creator_id);

        // While it exists the option is marked Already Added.
        $before = $this->actingAs($juan)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Already Added', $before);

        $this->deleteActivity($juan, $folder, $barangay)->assertRedirect();

        $after = $this->actingAs($juan)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringNotContainsString('Already Added', $after);

        // Re-adding it by a DIFFERENT user makes that user the Creator — never the old one.
        $this->addActivity($maria, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $readded = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->assertSame($maria->id, $readded->creator_id);
        $this->assertNotSame($barangay->id, $readded->id);
    }

    // =====================================================================================
    // Progress and folder status
    // =====================================================================================

    public function test_deleting_a_completed_check_returns_the_requirement_to_incomplete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->createDefaultCiActivities($folder, null, $ci);
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $barangay->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $label = 'Applicant: '.MandatoryInvestigationRequirements::APPLICANT['barangay_check'];
        $met = fn (): bool => app(MandatoryInvestigationRequirements::class)->evaluate($folder->fresh())[$label];
        $this->assertTrue($met(), 'Completed satisfies it.');

        $this->deleteActivity($ci, $folder, $barangay)->assertRedirect();

        $this->assertFalse($met(), 'Deleting the row returns the requirement to incomplete.');
        // The requirement is still counted — the denominators never move.
        $this->assertSame(7, count(MandatoryInvestigationRequirements::APPLICANT));
        $this->assertSame(4, count(MandatoryInvestigationRequirements::CO_MAKER));
        $this->assertCount(7, app(MandatoryInvestigationRequirements::class)->evaluate($folder->fresh()));
    }

    public function test_folder_progress_and_status_are_recalculated_from_backend_state(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->createDefaultCiActivities($folder, null, $ci);
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $barangay->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        // Force a known authoritative starting point, then delete and let the action recompute.
        app(ClientProgressService::class)->recalculate($folder);
        $before = $folder->fresh();

        $this->deleteActivity($ci, $folder, $barangay)->assertRedirect();

        $after = $folder->fresh();
        $this->assertLessThan($before->progress_percent, $after->progress_percent, 'Progress drops.');
        $this->assertSame(ClientFolderStatus::OnProgress, $after->status);
        $this->assertNull($after->completed_at);
    }

    // =====================================================================================
    // Audit and reminders
    // =====================================================================================

    public function test_the_delete_is_audited_once_against_the_exact_actor_and_context(): void
    {
        $ci = User::factory()->create();
        $deleter = User::factory()->create();
        $folder = $this->folder($ci);
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->createDefaultCiActivities($folder, $maria, $ci);
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id);

        $this->deleteActivity($deleter, $folder, $barangay, $maria->id)->assertRedirect();

        $logs = AuditLog::query()->where('action', 'ci_activity.deleted')->get();
        $this->assertCount(1, $logs, 'Exactly one delete event.');
        $log = $logs->first();
        $this->assertSame($deleter->id, $log->user_id);
        $this->assertSame($folder->id, $log->client_folder_id);
        $this->assertSame('ci_activities', $log->module);
        $this->assertSame($maria->id, $log->metadata['co_maker_id']);
        $this->assertSame($barangay->id, $log->metadata['activity_id']);
        $this->assertSame($ci->id, $log->metadata['creator_id'], 'Creator is preserved in the record.');
    }

    public function test_a_deleted_scheduled_check_leaves_the_reminder_feed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->createDefaultCiActivities($folder, null, $ci);
        $scheduled = [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now(config('cims.display_timezone'))->setTime(9, 0)->utc(),
            'scheduled_has_time' => true,
        ];
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $barangay->update($scheduled);
        $neighbor->update($scheduled);

        $this->assertCount(2, ScheduledTodayNotificationFeed::build($ci)->items);

        $this->deleteActivity($ci, $folder, $barangay)->assertRedirect();

        // The deleted one is gone; the remaining creator-based reminder still works.
        $remaining = ScheduledTodayNotificationFeed::build($ci)->items;
        $this->assertCount(1, $remaining);
        $this->assertSame($neighbor->id, $remaining->first()->ci_activity_id);
    }
}
