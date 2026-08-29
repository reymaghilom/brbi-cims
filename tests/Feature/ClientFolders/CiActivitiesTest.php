<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SeedCiActivities;
use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Models\ActivityDefinition;
use App\Models\ActivityNote;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CiActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_admin_and_any_ci_can_access_the_shared_activity_workspace(): void
    {
        $admin = User::factory()->administrator()->create();
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($assigned);
        [$otherFolder, $otherActivity] = $this->folderWithActivities($other);
        $otherActivity->update(['visited_by' => 'OTHER FOLDER VISITOR']);

        $this->actingAs($admin)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->actingAs($assigned)->get(route('client-folders.activities.index', $folder))->assertOk()->assertDontSee('OTHER FOLDER VISITOR');
        $this->actingAs($assigned)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $this->actingAs($other)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->actingAs($other)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload())->assertRedirect();
        $this->assertNotSame($folder->id, $otherFolder->id);
    }

    public function test_assigned_ci_can_be_changed_without_restricting_other_ci_visibility(): void
    {
        $ci = User::factory()->create();
        $assignee = User::factory()->create();
        $other = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload() + ['assigned_ci_id' => $assignee->id])
            ->assertRedirect();
        $activity->refresh();
        $this->assertSame($assignee->id, $activity->assigned_ci_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.assignment_changed', 'client_folder_id' => $folder->id]);

        // Assignment is informational only — every CI can still view and act on the activity.
        $this->actingAs($other)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();

        // Saving again without changing the assignment must not fire a second assignment-changed event.
        $this->actingAs($assignee)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload() + ['assigned_ci_id' => $assignee->id]);
        $this->assertSame(1, AuditLog::where('action', 'ci_activity.assignment_changed')->count());
    }

    public function test_ci_activity_concurrent_save_with_stale_updated_at_is_rejected(): void
    {
        $ci = User::factory()->create();
        $other = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $staleTimestamp = $activity->updated_at->toISOString();

        $this->travel(1)->minutes();
        $this->actingAs($other)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload() + ['expected_updated_at' => $staleTimestamp])
            ->assertRedirect();

        $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload() + ['expected_updated_at' => $staleTimestamp])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_updated_at');
    }

    public function test_deleted_folder_is_unavailable_and_forged_nested_activity_is_rejected(): void
    {
        $admin = User::factory()->administrator()->create();
        [$folder, $activity] = $this->folderWithActivities();
        [$otherFolder, $otherActivity] = $this->folderWithActivities();

        $this->actingAs($admin)->get(route('client-folders.activities.edit', [$folder, $otherActivity]))->assertNotFound();
        $this->actingAs($admin)->put(route('client-folders.activities.update', [$folder, $otherActivity]), $this->payload())->assertNotFound();
        $folder->delete();
        $this->actingAs($admin)->get(route('client-folders.activities.index', $folder->id))->assertNotFound();
        $this->actingAs($admin)->get(route('client-folders.activities.edit', [$folder->id, $activity->id]))->assertNotFound();
        $this->assertNotSame($folder->id, $otherFolder->id);
    }

    public function test_activity_can_be_updated_and_completion_transition_is_audited_safely(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload())
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]))->assertSessionHas('status');

        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertSame('Assigned Investigator', $activity->visited_by);
        $this->assertNotNull($activity->completed_at);
        $this->assertSame($ci->id, $activity->updated_by);
        $audit = AuditLog::where('action', 'ci_activity.completed')->sole();
        $this->assertSame('completed', $audit->metadata['status']);
        $this->assertStringNotContainsString('Verified residence', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_completed_activity_requires_confirmation_and_reopens_without_reviving_schedule_or_reminder(): void
    {
        $creator = User::factory()->create(['full_name' => 'Original Creator']);
        $reopeningCi = User::factory()->create(['full_name' => 'Reopening CI']);
        [$folder, $activity] = $this->folderWithActivities($creator);
        $activity->forceFill(['scheduled_at' => now()->addHour(), 'reminder_sent_at' => now()])->saveQuietly();

        $this->actingAs($creator)->put(route('client-folders.activities.update', [$folder, $activity]), [
            'expected_updated_at' => $activity->updated_at->toISOString(),
            'status' => 'completed',
            'scheduled_at' => $activity->scheduled_at->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $activity->refresh();
        $activityId = $activity->id;
        $completedAt = $activity->completed_at;
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNotNull($completedAt);

        $this->actingAs($reopeningCi)
            ->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']))
            ->assertOk()
            ->assertSee('Reopen Activity')
            ->assertSee('Reopen Activity?')
            ->assertSee('This activity will be returned to Pending. The previous completion will remain visible in Activity History.')
            ->assertSee('data-modal-close', false);

        // Opening or cancelling the confirmation performs no request and leaves completion intact.
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
        $this->assertEquals($completedAt, $activity->fresh()->completed_at);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), [
            'expected_updated_at' => $activity->updated_at->toISOString(),
            'status' => 'pending',
            'intent' => 'return',
        ])->assertRedirect();

        $activity->refresh();
        $this->assertSame($activityId, $activity->id);
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($reopeningCi->id, $activity->updated_by);
        $this->assertNull($activity->completed_at);
        $this->assertNull($activity->scheduled_at);
        $this->assertNull($activity->reminder_sent_at);
        $this->assertNull($activity->co_maker_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.completed', 'user_id' => $creator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.reopened', 'user_id' => $reopeningCi->id]);

        $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertOk()
            ->assertSee($activity->name.' completed')
            ->assertSee($activity->name.' reopened');
    }

    public function test_reopen_enforces_folder_and_exact_co_maker_scope(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();
        $activity = CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
            'creator_id' => $ci->id,
        ]);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['co_maker_id' => null, 'status' => 'pending'])->assertForbidden();
        $this->put(route('client-folders.activities.update', [$folder, $activity]), ['co_maker_id' => $coMakerB->id, 'status' => 'pending'])->assertForbidden();
        $this->put(route('client-folders.activities.update', [$otherFolder, $activity]), ['co_maker_id' => $coMakerA->id, 'status' => 'pending'])->assertNotFound();
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), ['co_maker_id' => $coMakerA->id, 'status' => 'pending'])->assertRedirect();
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
    }

    public function test_delete_confirmation_permanently_deletes_only_the_activity_and_preserves_definition_shared_proof_and_history(): void
    {
        $creator = User::factory()->create(['full_name' => 'Delete Original Creator']);
        $deletingCi = User::factory()->create(['full_name' => 'Deleting CI']);
        [$folder, $activity] = $this->folderWithActivities($creator);
        $activity->update(['target' => 'DELETE SAFETY TARGET']);
        $definition = $activity->definition;
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'uploaded_by' => $creator->id,
            'file_name' => 'delete-safety-proof.pdf',
        ]);
        $activity->mediaReferences()->attach($media, ['label' => 'Preserved proof']);
        $otherActivity = $folder->activities()->whereKeyNot($activity->id)->firstOrFail();
        $otherActivity->mediaReferences()->attach($media, ['label' => 'Shared preserved proof']);
        AuditLog::create([
            'user_id' => $creator->id,
            'client_folder_id' => $folder->id,
            'action' => 'ci_activity.created',
            'module' => 'ci_activities',
            'description' => 'A CI activity was created.',
            'metadata' => ['activity_id' => $activity->id, 'co_maker_id' => null, 'activity_title' => $activity->name],
        ]);

        $this->actingAs($deletingCi)
            ->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertOk()
            ->assertSee('Delete Activity')
            ->assertSee('Permanently Delete Activity?')
            ->assertSee('This activity will be permanently deleted and cannot be restored. Continue only if this activity was created by mistake or is no longer needed.')
            ->assertSee('Permanently Delete')
            ->assertSee('ui-button-danger', false)
            ->assertSee('data-modal-close', false);

        // Opening or cancelling the confirmation performs no delete request.
        $this->assertNotNull(CiActivity::query()->find($activity->id));

        $activityName = $activity->name;
        $deleteResponse = $this->delete(route('client-folders.activities.destroy', [$folder, $activity]), ['co_maker_id' => null]);
        $deleteResponse
            ->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertSessionHas('status', $activityName.' activity deleted.');
        $this->get($deleteResponse->headers->get('Location'))->assertOk();

        $this->assertDatabaseMissing('ci_activities', ['id' => $activity->id]);
        $this->assertNull(CiActivity::withTrashed()->find($activity->id));
        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id, 'is_active' => true]);
        $this->assertDatabaseHas('media_references', ['id' => $media->id, 'file_name' => 'delete-safety-proof.pdf']);
        $this->assertDatabaseMissing('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $media->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $otherActivity->id, 'media_reference_id' => $media->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.created', 'user_id' => $creator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.deleted', 'user_id' => $deletingCi->id]);
        $deletionAudit = AuditLog::query()->where('action', 'ci_activity.deleted')->sole();
        $this->assertSame($activity->id, $deletionAudit->metadata['activity_id']);
        $this->assertSame($creator->id, $deletionAudit->metadata['creator_id']);
        $this->assertSame($definition->id, $deletionAudit->metadata['activity_definition_id']);
        $this->assertNull($deletionAudit->metadata['co_maker_id']);
        $this->assertNotEmpty($deletionAudit->metadata['deletion_time']);

        $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertOk()
            ->assertDontSee('DELETE SAFETY TARGET')
            ->assertSee($activity->name.' deleted');
    }

    public function test_permanent_delete_enforces_folder_and_exact_applicant_or_co_maker_scope(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();
        $applicantActivity = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'APPLICANT DELETE SCOPE', 'creator_id' => $ci->id]);
        $coMakerAActivity = CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'CO-MAKER A DELETE SCOPE', 'creator_id' => $ci->id]);
        $coMakerBActivity = CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerB->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'CO-MAKER B DELETE SCOPE', 'creator_id' => $ci->id]);

        $this->actingAs($ci)->delete(route('client-folders.activities.destroy', [$folder, $coMakerAActivity]), ['co_maker_id' => null])->assertNotFound();
        $this->delete(route('client-folders.activities.destroy', [$folder, $coMakerAActivity]), ['co_maker_id' => $coMakerB->id])->assertNotFound();
        $this->delete(route('client-folders.activities.destroy', [$otherFolder, $coMakerAActivity]), ['co_maker_id' => $coMakerA->id])->assertNotFound();
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerAActivity->id]);

        $applicantDeleteResponse = $this->delete(route('client-folders.activities.destroy', [$folder, $applicantActivity]), ['co_maker_id' => null]);
        $applicantDeleteResponse->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'all']));
        $this->get($applicantDeleteResponse->headers->get('Location'))->assertOk();
        $this->assertDatabaseMissing('ci_activities', ['id' => $applicantActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerAActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerBActivity->id]);

        $coMakerDeleteResponse = $this->delete(route('client-folders.activities.destroy', [$folder, $coMakerAActivity]), ['co_maker_id' => $coMakerA->id]);
        $coMakerRedirect = route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'status' => 'all']);
        $coMakerDeleteResponse->assertRedirect($coMakerRedirect);
        $this->get($coMakerDeleteResponse->headers->get('Location'))
            ->assertOk()
            ->assertDontSee('CO-MAKER A DELETE SCOPE')
            ->assertDontSee('CO-MAKER B DELETE SCOPE');
        $this->assertDatabaseMissing('ci_activities', ['id' => $coMakerAActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerBActivity->id]);
    }

    public function test_unauthenticated_user_cannot_reopen_or_delete_an_activity(): void
    {
        $folder = ClientFolder::factory()->create();
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();
        $activity = CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'pending'])
            ->assertRedirect(route('login'));
        $this->delete(route('client-folders.activities.destroy', [$folder, $activity]), ['co_maker_id' => null])
            ->assertRedirect(route('login'));
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
        $this->assertDatabaseHas('ci_activities', ['id' => $activity->id]);
    }

    public function test_fresh_ci_activity_contexts_start_empty_until_a_ci_adds_the_first_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);

        app(SeedCiActivities::class)->execute($folder);
        app(SeedCiActivities::class)->execute($folder, $coMakerA);
        app(SeedCiActivities::class)->execute($folder, $coMakerB);
        $this->assertSame(0, $folder->activities()->count());

        $activeDefinition = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('No CI activities yet.')
            ->assertSee('Add an activity when there is something to process, schedule, follow up, or document.')
            ->assertSee($activeDefinition->name)
            ->assertSee('+ Add New Activity Type')
            ->assertDontSee('Residence Check')
            ->assertDontSee('Business Check');
        foreach ([$coMakerA, $coMakerB] as $coMaker) {
            $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
                ->assertOk()
                ->assertSee('No CI activities yet.');
        }

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $activeDefinition->id,
            'status' => 'pending',
            'target' => 'FIRST MANUAL ACTIVITY',
        ])->assertRedirect();

        $manualActivity = $folder->activities()->whereNull('co_maker_id')->sole();
        $this->assertSame($activeDefinition->id, $manualActivity->activity_definition_id);
        $this->assertSame(0, $folder->activities()->whereIn('co_maker_id', [$coMakerA->id, $coMakerB->id])->count());
        app(SeedCiActivities::class)->execute($folder);
        $this->assertDatabaseHas('ci_activities', ['id' => $manualActivity->id, 'target' => 'FIRST MANUAL ACTIVITY']);
        $this->assertSame(1, $folder->activities()->count());
    }

    public function test_bulk_delete_selects_visible_rows_and_permanently_deletes_only_confirmed_ids(): void
    {
        $creator = User::factory()->create();
        $actor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id]);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();
        $pendingA = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'VISIBLE PENDING A', 'status' => ActivityStatus::Pending, 'creator_id' => $creator->id]);
        $pendingB = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'VISIBLE PENDING B', 'status' => ActivityStatus::Pending, 'creator_id' => $creator->id]);
        $completed = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'HIDDEN COMPLETED', 'status' => ActivityStatus::Completed, 'completed_at' => now(), 'creator_id' => $creator->id]);
        $sharedMedia = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $creator->id, 'file_name' => 'bulk-shared-proof.pdf']);
        $pendingA->mediaReferences()->attach($sharedMedia, ['label' => 'Selected proof']);
        $completed->mediaReferences()->attach($sharedMedia, ['label' => 'Unselected shared proof']);

        $page = $this->actingAs($actor)->get(route('client-folders.activities.index', [$folder, 'status' => 'pending']))->assertOk();
        preg_match_all('/<input[^>]+data-ci-activity-select/', $page->getContent(), $rowCheckboxes);
        $this->assertCount(2, $rowCheckboxes[0]);
        $page->assertSee('aria-label="Select all visible activities"', false)
            ->assertSee('data-bulk-delete-button disabled', false)
            ->assertSee('Delete Selected')
            ->assertSee('Permanently Delete Selected Activities?')
            ->assertSee('The selected activities will be permanently deleted and cannot be restored.')
            ->assertSee('0 activities selected')
            ->assertSee('Delete Selected (${selected.length})', false);
        $this->assertDatabaseHas('ci_activities', ['id' => $pendingA->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $pendingB->id]);

        // A forged hidden-tab selection is rejected before any activity is deleted.
        $this->delete(route('client-folders.activities.bulk-destroy', $folder), [
            'activity_ids' => [$pendingA->id, $completed->id],
            'status' => 'pending',
        ])->assertNotFound();
        $this->assertDatabaseHas('ci_activities', ['id' => $pendingA->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $completed->id]);

        $response = $this->delete(route('client-folders.activities.bulk-destroy', $folder), [
            'activity_ids' => [$pendingA->id, $pendingB->id],
            'status' => 'pending',
        ]);
        $response
            ->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'pending']))
            ->assertSessionHas('status', '2 activities permanently deleted.');

        foreach ([$pendingA, $pendingB] as $deletedActivity) {
            $this->assertDatabaseMissing('ci_activities', ['id' => $deletedActivity->id]);
            $this->assertNull(CiActivity::withTrashed()->find($deletedActivity->id));
        }
        $this->assertDatabaseHas('ci_activities', ['id' => $completed->id]);
        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id, 'is_active' => true]);
        $this->assertDatabaseHas('media_references', ['id' => $sharedMedia->id]);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $completed->id, 'media_reference_id' => $sharedMedia->id]);
        $this->assertSame(2, AuditLog::query()->where('action', 'ci_activity.deleted')->where('user_id', $actor->id)->count());

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('No activities in this view.')
            ->assertDontSee('VISIBLE PENDING A')
            ->assertDontSee('VISIBLE PENDING B');
        $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertOk()
            ->assertSee('HIDDEN COMPLETED')
            ->assertDontSee('VISIBLE PENDING A')
            ->assertDontSee('VISIBLE PENDING B');
    }

    public function test_bulk_delete_rejects_cross_person_and_cross_folder_ids_without_partial_deletion(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();
        $applicantActivity = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id]);
        $coMakerAActivity = CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id]);
        $coMakerBActivity = CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerB->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id]);
        $otherFolderActivity = CiActivity::create(['client_folder_id' => $otherFolder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id]);
        $this->actingAs($ci);

        $this->delete(route('client-folders.activities.bulk-destroy', $folder), ['activity_ids' => [$applicantActivity->id, $coMakerAActivity->id], 'status' => 'all'])->assertNotFound();
        $this->delete(route('client-folders.activities.bulk-destroy', $folder), ['co_maker_id' => $coMakerA->id, 'activity_ids' => [$coMakerAActivity->id, $coMakerBActivity->id], 'status' => 'all'])->assertNotFound();
        $this->delete(route('client-folders.activities.bulk-destroy', $folder), ['activity_ids' => [$applicantActivity->id, $otherFolderActivity->id], 'status' => 'all'])->assertNotFound();
        foreach ([$applicantActivity, $coMakerAActivity, $coMakerBActivity, $otherFolderActivity] as $activity) {
            $this->assertDatabaseHas('ci_activities', ['id' => $activity->id]);
        }

        $response = $this->delete(route('client-folders.activities.bulk-destroy', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_ids' => [$coMakerAActivity->id],
            'status' => 'all',
        ]);
        $response->assertRedirect(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'status' => 'all']));
        $this->get($response->headers->get('Location'))->assertOk();
        $this->assertDatabaseMissing('ci_activities', ['id' => $coMakerAActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerBActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $applicantActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $otherFolderActivity->id]);
    }

    public function test_simple_status_workflow_allows_direct_completion_and_requires_a_date_only_when_scheduled(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'pending'])->assertSessionHasNoErrors();
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'scheduled'])
            ->assertSessionHasErrors('scheduled_at');
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'completed'])
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'invalid'])
            ->assertSessionHasErrors('status');
    }

    public function test_legacy_status_normalization_preserves_applicant_co_maker_and_completed_records(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'LEGACY CO MAKER',
            'first_name' => 'Legacy',
            'last_name' => 'Maker',
        ]);
        $definitions = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->take(3)->get();
        $now = now();

        $applicantId = DB::table('ci_activities')->insertGetId([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'activity_definition_id' => $definitions[0]->id,
            'name' => 'Applicant Legacy Activity',
            'status' => 'not_started',
            'creator_id' => $ci->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $coMakerId = DB::table('ci_activities')->insertGetId([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker->id,
            'activity_definition_id' => $definitions[1]->id,
            'name' => 'Co-Maker Legacy Activity',
            'status' => 'not_started',
            'creator_id' => $ci->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $completedId = DB::table('ci_activities')->insertGetId([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'activity_definition_id' => $definitions[2]->id,
            'name' => 'Completed Legacy Activity',
            'status' => 'completed',
            'creator_id' => $ci->id,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration = require database_path('migrations/2026_08_29_000300_normalize_legacy_ci_activity_statuses.php');
        $migration->up();

        $this->assertDatabaseHas('ci_activities', ['id' => $applicantId, 'client_folder_id' => $folder->id, 'co_maker_id' => null, 'status' => 'pending']);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerId, 'client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'status' => 'pending']);
        $this->assertDatabaseHas('ci_activities', ['id' => $completedId, 'client_folder_id' => $folder->id, 'co_maker_id' => null, 'status' => 'completed']);

        $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertOk()
            ->assertSee('Applicant Legacy Activity')
            ->assertSee('Completed Legacy Activity')
            ->assertSee('Pending')
            ->assertSee('Completed');
        $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id, 'status' => 'all']))
            ->assertOk()
            ->assertSee('Co-Maker Legacy Activity')
            ->assertDontSee('Applicant Legacy Activity');

        $newFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci);
        app(SeedCiActivities::class)->execute($newFolder);
        $this->assertSame(0, $newFolder->activities()->where('status', 'not_started')->count());
        $this->assertSame(
            ActivityDefinition::query()->where('is_active', true)->count(),
            $newFolder->activities()->where('status', 'pending')->count(),
        );
    }

    public function test_visit_date_and_time_order_are_validated(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $payload = $this->payload();
        $payload['visit_date'] = now()->addDay()->toDateString();
        $payload['time_in'] = '16:00';
        $payload['time_out'] = '08:00';

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $payload)
            ->assertSessionHasErrors(['visit_date', 'time_out']);
    }

    public function test_notes_are_append_only_chronological_and_audited_without_body(): void
    {
        $ci = User::factory()->create(['full_name' => 'Rey Investigator']);
        [$folder, $activity] = $this->folderWithActivities($ci);
        ActivityNote::create(['ci_activity_id' => $activity->id, 'user_id' => $ci->id, 'note' => 'Earlier note', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

        $this->actingAs($ci)->post(route('client-folders.activities.notes.store', [$folder, $activity]), ['note' => 'New factual note', 'follow_up_needed' => '1'])->assertRedirect();
        $this->assertSame(2, $activity->notes()->count());
        $response = $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $response->assertSeeInOrder(['Earlier note', 'New factual note'])->assertSee('Rey Investigator')->assertSee('Follow-up needed');

        $audit = AuditLog::where('action', 'activity_note.created')->sole();
        $this->assertStringNotContainsString('New factual note', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_existing_media_references_are_counted_and_displayed_without_upload_controls(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $ci->id, 'file_name' => 'residence-front.jpg']);
        $activity->mediaReferences()->attach($media, ['label' => 'Residence frontage']);

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->assertSee('With proof');
        $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertOk()->assertSee('Residence frontage')->assertDontSee('type="file"', false);
    }

    public function test_dedicated_check_definitions_are_not_seeded_offered_or_listed_but_existing_rows_are_preserved(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $definitions = ActivityDefinition::query()
            ->whereIn('code', ['residence_check', 'business_check'])
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $definitions);
        $this->assertTrue($definitions->every(fn (ActivityDefinition $definition): bool => ! $definition->is_active));

        $existingIds = $definitions->map(fn (ActivityDefinition $definition): int => CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $ci->id,
        ])->id);

        $this->actingAs($ci);
        app(SeedCiActivities::class)->execute($folder);

        $response = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))->assertOk();
        $response->assertDontSee('Residence Check')->assertDontSee('Business Check');
        foreach ($existingIds as $id) {
            $this->assertDatabaseHas('ci_activities', ['id' => $id, 'client_folder_id' => $folder->id]);
        }

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definitions->first()->id,
            'status' => 'pending',
        ])->assertSessionHasErrors('activity_definition_id');

        $newFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        app(SeedCiActivities::class)->execute($newFolder);
        $this->assertSame(0, $newFolder->activities()->whereIn('activity_definition_id', $definitions->pluck('id'))->count());
    }

    public function test_custom_activity_type_is_created_reused_and_kept_in_exact_person_scope(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => '  Barangay   Certification Follow-up  ',
            'status' => 'pending',
            'target' => 'APPLICANT CUSTOM TARGET',
        ])->assertRedirect();

        $definition = ActivityDefinition::query()->where('name', 'Barangay Certification Follow-up')->sole();
        $this->assertTrue($definition->is_active);
        $this->assertFalse($definition->is_required);
        $this->assertStringStartsWith('custom_', $definition->code);
        $this->assertDatabaseHas('ci_activities', [
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'target' => 'APPLICANT CUSTOM TARGET',
            'creator_id' => $ci->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $ci->id,
            'action' => 'activity_definition.created',
            'module' => 'activity_definitions',
        ]);

        $this->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Barangay Certification Follow-up');

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'barangay certification follow-up',
            'status' => 'pending',
            'target' => 'CO-MAKER A CUSTOM TARGET',
        ])->assertRedirect();

        $equivalentDefinitions = ActivityDefinition::query()->get()
            ->filter(fn (ActivityDefinition $candidate): bool => ActivityDefinition::normalizedNameKey($candidate->name) === ActivityDefinition::normalizedNameKey($definition->name));
        $this->assertCount(1, $equivalentDefinitions);
        $this->assertSame(2, CiActivity::query()->where('activity_definition_id', $definition->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'activity_definition.created')->where('metadata->activity_definition_id', $definition->id)->count());

        $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertOk()->assertSee('APPLICANT CUSTOM TARGET')->assertDontSee('CO-MAKER A CUSTOM TARGET');
        $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'status' => 'all']))
            ->assertOk()->assertSee('CO-MAKER A CUSTOM TARGET')->assertDontSee('APPLICANT CUSTOM TARGET');
        $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id, 'status' => 'all']))
            ->assertOk()->assertDontSee('CO-MAKER A CUSTOM TARGET')->assertDontSee('APPLICANT CUSTOM TARGET');
    }

    public function test_custom_activity_type_rejects_dedicated_module_names_and_invalid_person_scope(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $foreignCoMaker = CoMaker::create(['client_folder_id' => $otherFolder->id, 'full_name' => 'FOREIGN MAKER', 'first_name' => 'Foreign', 'last_name' => 'Maker']);
        $definitionCount = ActivityDefinition::query()->count();

        foreach (['Residence Check', 'residence-check', 'Business Check', 'business_check'] as $dedicatedName) {
            $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
                'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
                'new_activity_type' => $dedicatedName,
                'status' => 'pending',
            ])->assertSessionHasErrors('new_activity_type');
        }

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $foreignCoMaker->id,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'Foreign Scope Inquiry',
            'status' => 'pending',
        ])->assertSessionHasErrors('co_maker_id');

        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $this->assertDatabaseMissing('activity_definitions', ['name' => 'Foreign Scope Inquiry']);
    }

    public function test_unauthenticated_user_cannot_create_a_custom_activity_type(): void
    {
        $folder = ClientFolder::factory()->create();

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'Unauthorized Inquiry',
            'status' => 'pending',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('activity_definitions', ['name' => 'Unauthorized Inquiry']);
    }

    public function test_custom_activity_definition_rolls_back_when_activity_creation_fails(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->mock(CiActivitiesCompletionEvaluator::class)
            ->shouldReceive('evaluate')
            ->once()
            ->andThrow(new \RuntimeException('Forced downstream failure.'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
                'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
                'new_activity_type' => 'Rollback Verification Inquiry',
                'status' => 'pending',
            ]);
            $this->fail('The forced downstream failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced downstream failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('activity_definitions', ['name' => 'Rollback Verification Inquiry']);
        $this->assertDatabaseMissing('ci_activities', ['name' => 'Rollback Verification Inquiry']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'activity_definition.created']);
    }

    public function test_all_required_activities_completed_updates_progress_and_folder_overview(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $folder->activities()->whereKeyNot($activity->id)->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload())->assertRedirect();

        $this->assertDatabaseHas('client_completion_results', ['client_folder_id' => $folder->id, 'is_satisfied' => true, 'explanation_key' => 'required_activities.complete']);
        $folder->refresh();
        $this->assertEquals(16.67, $folder->progress_percent);
        $this->assertSame(ClientFolderStatus::OnProgress, $folder->status);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee(route('client-folders.activities.index', $folder), false)
            ->assertSee('4 of 4 required activities completed; 0 pending.');
    }

    public function test_activity_pages_use_responsive_field_checklist_markup_and_neutral_states(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()
            ->assertSee('aria-label="Breadcrumb"', false)
            ->assertSee(route('client-folders.index'), false)
            ->assertSee(route('client-folders.show', $folder), false)
            ->assertDontSee('Current View:')->assertDontSee('Switch Person')
            ->assertSee('xl:grid-cols-[minmax(0,1fr)_20rem]', false)->assertSee('Activity History')->assertSee('No proof')
            ->assertSee('Scheduled Today')->assertSee('For Follow-up')->assertSee('All Activities')
            ->assertSee('+ Add New Activity Type')->assertSee('data-new-activity-type-fields', false)
            ->assertSee('fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-y-auto', false)
            ->assertSee('backdrop:bg-brand-sidebar/45', false)
            ->assertDontSee('Residence Check')->assertDontSee('Business Check')
            ->assertSeeInOrder(['Barangay Check', 'Neighbor Check', 'Asset Check', 'Bank / Coop Check']);
        $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk()
            ->assertSee('xl:grid-cols-', false)->assertSee('No notes recorded.')->assertSee('No proof is linked. Proof is optional.')
            ->assertSee(route('client-folders.media.index', $folder), false);
    }

    public function test_activity_creation_locks_creator_and_another_ci_can_update_without_replacing_them(): void
    {
        $creator = User::factory()->create(['full_name' => 'Rey Creator']);
        $other = User::factory()->create(['full_name' => 'Mark Updater']);
        [$folder] = $this->folderWithActivities($creator);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($creator)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
            'target' => 'Main Office',
            'remarks' => 'Initial request.',
        ])->assertRedirect();

        $activity = CiActivity::query()->latest('id')->firstOrFail();
        $this->assertSame($creator->id, $activity->creator_id);
        $this->actingAs($other)->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'completed',
            'creator_id' => $other->id,
        ])->assertRedirect();

        $activity->refresh();
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($other->id, $activity->updated_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.created', 'user_id' => $creator->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.completed', 'user_id' => $other->id]);
    }

    public function test_applicant_and_each_co_maker_activity_tracker_are_strictly_isolated(): void
    {
        $ci = User::factory()->create();
        [$folder] = $this->folderWithActivities($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();

        foreach ([[$coMakerA, 'ALPHA TARGET'], [$coMakerB, 'BETA TARGET']] as [$person, $target]) {
            $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
                'co_maker_id' => $person->id,
                'activity_definition_id' => $definition->id,
                'status' => 'pending',
                'target' => $target,
            ])->assertRedirect();
        }

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()
            ->assertDontSee('ALPHA TARGET')->assertDontSee('BETA TARGET')->assertDontSee('Switch Person');
        $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'status' => 'all']))->assertOk()
            ->assertSee('ALPHA TARGET')->assertDontSee('BETA TARGET')
            ->assertDontSee('Current View:')->assertDontSee('Switch Person')
            ->assertSee(route('client-folders.show', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]));
        $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id, 'status' => 'all']))->assertOk()
            ->assertSee('BETA TARGET')->assertDontSee('ALPHA TARGET')->assertDontSee('Switch Person');
    }

    public function test_scheduling_and_rescheduling_are_audited_and_reset_the_reminder(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'scheduled', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.scheduled', 'user_id' => $ci->id]);
        $activity->forceFill(['reminder_sent_at' => now()])->saveQuietly();

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'scheduled', 'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'ci_activity.rescheduled', 'user_id' => $ci->id]);
        $this->assertNull($activity->refresh()->reminder_sent_at);
    }

    public function test_due_reminder_goes_only_to_creator_and_completed_activity_stops_future_reminders(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $other = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($creator);
        $activity->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'creator_id' => $creator->id]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class);
        Notification::assertNotSentTo($other, CiActivityScheduledReminder::class);
        $this->assertNotNull($activity->refresh()->reminder_sent_at);

        Notification::fake();
        $activity->update(['status' => ActivityStatus::Completed, 'reminder_sent_at' => null]);
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_activity_list_query_count_stays_constant_with_related_records(): void
    {
        $ci = User::factory()->create();
        [$folder] = $this->folderWithActivities($ci);
        foreach ($folder->activities as $activity) {
            ActivityNote::create(['ci_activity_id' => $activity->id, 'user_id' => $ci->id, 'note' => 'Count-only note']);
        }

        $this->actingAs($ci);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(9, $queryCount);
    }

    private function folderWithActivities(?User $ci = null): array
    {
        $ci ??= User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $activities = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (ActivityDefinition $definition) => CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $ci->id,
        ]));

        return [$folder, $activities->first()];
    }

    private function payload(): array
    {
        return [
            'status' => 'completed', 'visit_date' => '2026-08-01', 'time_in' => '09:00', 'time_out' => '10:15',
            'visited_by' => ' Assigned  Investigator ', 'person_met_contact' => 'Juan Dela Cruz / 09170000000',
            'remarks' => 'Verified residence and neighborhood details.', 'supporting_reference' => 'Barangay reference BR-10',
            'intent' => 'stay',
        ];
    }
}
