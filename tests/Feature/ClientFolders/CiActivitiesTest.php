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
use Illuminate\Support\Carbon;
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
        $emptyApplicantResponse = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $emptyApplicantResponse
            ->assertOk()
            ->assertSee('No CI activities yet.')
            ->assertSee('Add an activity when there is something to process, schedule, follow up, or document.')
            ->assertSee($activeDefinition->name)
            ->assertSee('+ Add New Activity Type')
            ->assertDontSee('Residence Check')
            ->assertDontSee('Business Check');
        $this->assertSame(1, substr_count($emptyApplicantResponse->getContent(), 'data-ci-activity-dialog-open'));
        foreach ([$coMakerA, $coMakerB] as $coMaker) {
            $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
                ->assertOk()
                ->assertSee('No CI activities yet.');
        }

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $activeDefinition->id,
            'status' => 'pending',
            'remarks' => 'FIRST MANUAL ACTIVITY',
        ])->assertRedirect();

        $manualActivity = $folder->activities()->whereNull('co_maker_id')->sole();
        $this->assertSame($activeDefinition->id, $manualActivity->activity_definition_id);
        $this->assertSame(0, $folder->activities()->whereIn('co_maker_id', [$coMakerA->id, $coMakerB->id])->count());
        app(SeedCiActivities::class)->execute($folder);
        $this->assertDatabaseHas('ci_activities', ['id' => $manualActivity->id, 'remarks' => 'FIRST MANUAL ACTIVITY']);
        $this->assertSame(1, $folder->activities()->count());
    }

    public function test_target_is_absent_from_add_and_edit_while_forged_input_is_ignored_and_historical_data_is_preserved(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'ISOLATED MAKER',
            'first_name' => 'Isolated',
            'last_name' => 'Maker',
        ]);
        $definition = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertDontSee('Bank / Office / Target')
            ->assertDontSee('name="target"', false)
            ->assertSee('name="remarks"', false);

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
            'target' => 'FORGED NEW TARGET',
            'remarks' => 'Applicant details belong in remarks.',
        ])->assertRedirect();
        $activity = $folder->activities()->whereNull('co_maker_id')->sole();
        $this->assertNull($activity->target);
        $this->assertSame('Applicant details belong in remarks.', $activity->remarks);

        $activity->forceFill(['target' => 'HISTORICAL STORED TARGET'])->saveQuietly();
        $this->get(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertOk()
            ->assertDontSee('Bank / Office / Target')
            ->assertDontSee('name="target"', false)
            ->assertSee('name="remarks"', false);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'pending',
            'target' => 'FORGED REPLACEMENT TARGET',
            'remarks' => 'Updated details remain in remarks.',
        ])->assertRedirect();
        $activity->refresh();
        $this->assertSame('HISTORICAL STORED TARGET', $activity->target);
        $this->assertSame('Updated details remain in remarks.', $activity->remarks);

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
            'remarks' => 'Co-maker details remain isolated.',
        ])->assertRedirect();
        $this->assertSame(1, $folder->activities()->whereNull('co_maker_id')->count());
        $this->assertSame(1, $folder->activities()->where('co_maker_id', $coMaker->id)->count());
    }

    public function test_neighbor_and_bank_activity_submissions_create_one_row_and_one_audit_each(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'MANUAL ACTIVITY CO MAKER',
            'first_name' => 'Manual',
            'last_name' => 'Maker',
        ]);
        $neighbor = ActivityDefinition::query()->where('name', 'Neighbor Check')->sole();
        $bank = ActivityDefinition::query()->where('name', 'Bank / Coop Check')->sole();
        $definitionCount = ActivityDefinition::query()->count();

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $neighbor->id,
            'status' => 'pending',
            'remarks' => 'ONE NEIGHBOR SUBMISSION',
        ])->assertRedirect();
        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'activity_definition_id' => $bank->id,
            'status' => 'pending',
            'remarks' => 'ONE BANK SUBMISSION',
        ])->assertRedirect();

        $this->assertSame(1, $folder->activities()->whereNull('co_maker_id')->where('activity_definition_id', $neighbor->id)->count());
        $this->assertSame(1, $folder->activities()->where('co_maker_id', $coMaker->id)->where('activity_definition_id', $bank->id)->count());
        $this->assertSame(2, $folder->activities()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'ci_activity.created')->where('metadata->activity_definition_id', $neighbor->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'ci_activity.created')->where('metadata->activity_definition_id', $bank->id)->count());
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());

        $response = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))->assertOk();
        $response
            ->assertSee('data-ci-activity-create-form', false)
            ->assertSee("form.dataset.submitting === 'true'", false)
            ->assertSee("form.dataset.submitting = 'true'", false)
            ->assertSee('submitButton.disabled = true', false);
    }

    public function test_add_activity_rejects_duplicates_only_within_the_exact_folder_and_person_context(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $neighbor = ActivityDefinition::query()->where('name', 'Neighbor Check')->sole();
        $asset = ActivityDefinition::query()->where('name', 'Asset Check')->sole();
        $applicantNeighbor = CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $neighbor->id,
            'name' => $neighbor->name,
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
            'creator_id' => $ci->id,
        ]);
        CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $asset->id,
            'name' => $asset->name,
            'creator_id' => $ci->id,
        ]);
        $this->actingAs($ci);

        $applicantPage = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))->assertOk();
        $this->assertTrue($applicantPage->viewData('existingDefinitionIds')->contains($neighbor->id));
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-value="'.preg_quote((string) $neighbor->id, '/').'"[^>]*disabled[^>]*>Neighbor Check.*Already Added.*<\/button>/s',
            $applicantPage->getContent(),
        );

        $coMakerPage = $this->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMakerA->id,
            'status' => 'all',
        ]))->assertOk();
        $this->assertFalse($coMakerPage->viewData('existingDefinitionIds')->contains($neighbor->id));
        $matchedCoMakerOption = preg_match(
            '/<button([^>]*)data-value="'.preg_quote((string) $neighbor->id, '/').'"([^>]*)>Neighbor Check<\/button>/',
            $coMakerPage->getContent(),
            $coMakerOption,
        );
        $this->assertSame(1, $matchedCoMakerOption);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|>)/', $coMakerOption[1].$coMakerOption[2]);

        $activityCount = CiActivity::query()->count();
        $creationAuditCount = AuditLog::query()->where('action', 'ci_activity.created')->count();
        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $neighbor->id,
            'status' => 'pending',
        ])->assertSessionHasErrors([
            'activity_definition_id' => 'This activity already exists for the current Applicant.',
        ]);
        $this->assertSame($activityCount, CiActivity::query()->count());
        $this->assertSame($creationAuditCount, AuditLog::query()->where('action', 'ci_activity.created')->count());
        $this->assertSame(ActivityStatus::Completed, $applicantNeighbor->fresh()->status);

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $neighbor->id,
            'status' => 'pending',
        ])->assertRedirect();
        $this->assertDatabaseHas('ci_activities', [
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $neighbor->id,
        ]);

        $activityCount = CiActivity::query()->count();
        $creationAuditCount = AuditLog::query()->where('action', 'ci_activity.created')->count();
        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $neighbor->id,
            'status' => 'follow_up',
        ])->assertSessionHasErrors([
            'activity_definition_id' => 'This activity already exists for this Co-Maker.',
        ]);
        $this->assertSame($activityCount, CiActivity::query()->count());
        $this->assertSame($creationAuditCount, AuditLog::query()->where('action', 'ci_activity.created')->count());

        foreach ([
            [$folder, $coMakerB->id],
            [$otherFolder, null],
        ] as [$targetFolder, $coMakerId]) {
            $this->post(route('client-folders.activities.store', $targetFolder), [
                'co_maker_id' => $coMakerId,
                'activity_definition_id' => $neighbor->id,
                'status' => 'scheduled',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])->assertRedirect();
        }
        $this->assertDatabaseHas('ci_activities', ['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerB->id, 'activity_definition_id' => $neighbor->id]);
        $this->assertDatabaseHas('ci_activities', ['client_folder_id' => $otherFolder->id, 'co_maker_id' => null, 'activity_definition_id' => $neighbor->id]);

        $activityCount = CiActivity::query()->count();
        $definitionCount = ActivityDefinition::query()->count();
        $creationAuditCount = AuditLog::query()->where('action', 'ci_activity.created')->count();
        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'asset check',
            'status' => 'pending',
        ])->assertRedirect()
            ->assertSessionHas('ci_activity_modal_open', true);
        $this->assertSame($activityCount, CiActivity::query()->count());
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $this->assertSame($creationAuditCount, AuditLog::query()->where('action', 'ci_activity.created')->count());

        $this->delete(route('client-folders.activities.destroy', [$folder, $applicantNeighbor]), ['co_maker_id' => null])
            ->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'all']));
        $this->assertDatabaseMissing('ci_activities', ['id' => $applicantNeighbor->id]);
        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $neighbor->id,
            'status' => 'pending',
        ])->assertRedirect();
        $this->assertSame(1, CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->whereNull('co_maker_id')
            ->where('activity_definition_id', $neighbor->id)
            ->count());
    }

    public function test_completed_activity_submission_is_separate_from_status_and_preserves_creator_accountability(): void
    {
        $creator = User::factory()->create(['full_name' => 'Rey Creator']);
        $submitter = User::factory()->create(['full_name' => 'Mark Submitter']);
        [$folder, $activity] = $this->folderWithActivities($creator);
        $activity->update([
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $submissionPage = $this->actingAs($submitter)->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']))
            ->assertOk()
            ->assertSee('Mark as Submitted')
            ->assertSee('Record this CI result as submitted to the Credit Analyst.')
            ->assertSee('Submitted To / Credit Analyst')
            ->assertSee('Submission Note')
            ->assertSee('data-submission-action="create"', false)
            ->assertSee('max-w-lg overflow-y-auto', false);
        $this->assertMatchesRegularExpression(
            '/data-submission-cell="'.$activity->id.'".*data-submission-action="create"/s',
            $submissionPage->getContent(),
        );

        $this->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'submitted_to' => '  Ana Credit Analyst  ',
            'submission_note' => '  Endorsed with the verified field result.  ',
        ])->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'completed']));

        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($submitter->id, $activity->submitted_by);
        $this->assertSame($submitter->id, $activity->updated_by);
        $this->assertNotNull($activity->submitted_at);
        $this->assertSame('Ana Credit Analyst', $activity->submitted_to);
        $this->assertSame('Endorsed with the verified field result.', $activity->submission_note);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ci_activity.submitted',
            'user_id' => $submitter->id,
            'client_folder_id' => $folder->id,
            'metadata->activity_id' => $activity->id,
            'metadata->submitted_to' => 'Ana Credit Analyst',
        ]);

        $page = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']))->assertOk();
        $page->assertSee('Proof / Submission')
            ->assertSee('No Attachment')
            ->assertSee('Submitted')
            ->assertSee('To: Ana Credit Analyst')
            ->assertSee('View / Update')
            ->assertSee('View / Update Submission')
            ->assertSee('data-submission-action="update"', false)
            ->assertSee('data-submission-summary', false)
            ->assertSee('Submitted By')
            ->assertSee('Submitted At')
            ->assertSee('Submitted To')
            ->assertSee('Note')
            ->assertSee('submitted to Credit Analyst')
            ->assertSee('Submitted to: Ana Credit Analyst')
            ->assertSee('Endorsed with the verified field result.')
            ->assertSee('by Mark Submitter')
            ->assertDontSee('Submission Method');
        $this->assertMatchesRegularExpression(
            '/data-submission-cell="'.$activity->id.'".*data-submission-action="update"/s',
            $page->getContent(),
        );

        $this->actingAs($creator)->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'submitted_to' => 'Ben Credit Analyst',
            'submission_note' => 'Re-endorsed after review.',
        ])->assertRedirect();
        $activity->refresh();
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($creator->id, $activity->submitted_by);
        $this->assertSame('Ben Credit Analyst', $activity->submitted_to);
        $this->assertSame(2, AuditLog::query()->where('action', 'ci_activity.submitted')->where('metadata->activity_id', $activity->id)->count());

        $activity->update(['status' => ActivityStatus::Pending, 'completed_at' => null]);
        $reopenedPage = $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $reopenedPage->assertSee('Submitted')->assertDontSee('data-submission-action', false);
    }

    public function test_submission_requires_completed_status_and_exact_folder_person_scope(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $foreignActivity = CiActivity::create([
            'client_folder_id' => $otherFolder->id,
            'activity_definition_id' => $activity->activity_definition_id,
            'name' => $activity->name,
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
            'creator_id' => $ci->id,
        ]);

        $this->actingAs($ci)->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
        ])->assertSessionHasErrors('submission_activity_id', null, 'submission');
        $this->assertNull($activity->fresh()->submitted_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ci_activity.submitted']);

        $activity->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
        $this->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
            'co_maker_id' => $coMaker->id,
        ])->assertForbidden();
        $this->patch(route('client-folders.activities.submit', [$folder, $foreignActivity]), [
            'submission_activity_id' => $foreignActivity->id,
        ])->assertNotFound();
        $this->assertNull($activity->fresh()->submitted_at);
        $this->assertNull($foreignActivity->fresh()->submitted_at);

        auth()->logout();
        $this->patch(route('client-folders.activities.submit', [$folder, $activity]), [
            'submission_activity_id' => $activity->id,
        ])->assertRedirect(route('login'));
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
        $this->assertCount(3, $rowCheckboxes[0]);
        $this->assertCount(3, $page->viewData('activities'));
        $this->assertCount(2, $page->viewData('visibleActivityIds'));
        $page->assertSee('aria-label="Select all visible activities"', false)
            ->assertSee('data-clear-selected-button hidden', false)
            ->assertSee('ui-button-secondary-compact shrink-0', false)
            ->assertSee('Clear Selected')
            ->assertSee('data-bulk-delete-button disabled', false)
            ->assertSee('ui-button-danger-compact shrink-0', false)
            ->assertSee('Delete Selected')
            ->assertSee('Permanently Delete Selected Activities?')
            ->assertSee('The selected activities will be permanently deleted and cannot be restored.')
            ->assertSee('0 activities selected')
            ->assertSee('Delete Selected (${selected.length})', false)
            ->assertSee('const visibleSelections = () =>', false)
            ->assertSee('visibleSelections().forEach', false)
            ->assertSee('clearButton.hidden = selected.length === 0;', false)
            ->assertSee("clearButton.addEventListener('click', clearSelections);", false);
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
        $coMakerAActivityB = CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'target' => 'SECOND ALPHA ACTIVITY', 'creator_id' => $ci->id]);
        $coMakerBActivity = CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerB->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id]);
        $otherFolderActivity = CiActivity::create(['client_folder_id' => $otherFolder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id]);
        $this->actingAs($ci);

        $this->delete(route('client-folders.activities.bulk-destroy', $folder), ['activity_ids' => [$applicantActivity->id, $coMakerAActivity->id], 'status' => 'all'])->assertNotFound();
        $this->delete(route('client-folders.activities.bulk-destroy', $folder), ['co_maker_id' => $coMakerA->id, 'activity_ids' => [$coMakerAActivity->id, $coMakerBActivity->id], 'status' => 'all'])->assertNotFound();
        $this->delete(route('client-folders.activities.bulk-destroy', $folder), ['activity_ids' => [$applicantActivity->id, $otherFolderActivity->id], 'status' => 'all'])->assertNotFound();
        foreach ([$applicantActivity, $coMakerAActivity, $coMakerAActivityB, $coMakerBActivity, $otherFolderActivity] as $activity) {
            $this->assertDatabaseHas('ci_activities', ['id' => $activity->id]);
        }

        $response = $this->delete(route('client-folders.activities.bulk-destroy', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_ids' => [$coMakerAActivity->id, $coMakerAActivityB->id],
            'status' => 'all',
        ]);
        $response
            ->assertRedirect(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'status' => 'all']))
            ->assertSessionHas('status', '2 activities permanently deleted.');
        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('No CI activities yet.');
        $this->assertDatabaseMissing('ci_activities', ['id' => $coMakerAActivity->id]);
        $this->assertDatabaseMissing('ci_activities', ['id' => $coMakerAActivityB->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerBActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $applicantActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $otherFolderActivity->id]);
        $this->assertSame(2, AuditLog::query()->where('action', 'ci_activity.deleted')->where('user_id', $ci->id)->count());
    }

    public function test_view_all_activity_history_uses_a_centered_exact_scope_modal_and_normal_page_scrolling(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'HISTORY CO MAKER',
            'first_name' => 'History',
            'last_name' => 'Maker',
        ]);

        foreach (range(1, 10) as $sequence) {
            AuditLog::create([
                'user_id' => $ci->id,
                'client_folder_id' => $folder->id,
                'action' => $sequence === 1 ? 'ci_activity.deleted' : 'ci_activity.updated',
                'module' => 'ci_activities',
                'description' => 'Applicant history event.',
                'metadata' => [
                    'activity_id' => $sequence,
                    'co_maker_id' => null,
                    'activity_title' => sprintf('Applicant Event %02d', $sequence),
                ],
                'created_at' => now()->subMinutes(10 - $sequence),
            ]);
        }
        AuditLog::create([
            'user_id' => $ci->id,
            'client_folder_id' => $folder->id,
            'action' => 'ci_activity.reopened',
            'module' => 'ci_activities',
            'description' => 'Co-maker history event.',
            'metadata' => [
                'activity_id' => 100,
                'co_maker_id' => $coMaker->id,
                'activity_title' => 'Co-Maker Scoped Event',
            ],
            'created_at' => now()->addMinute(),
        ]);

        $this->actingAs($ci);
        $assertNormalPageScroll = function (string $html, string $attribute): void {
            $matched = preg_match('/<[^>]*\b'.preg_quote($attribute, '/').'\b[^>]*>/', $html, $tag);
            $this->assertSame(1, $matched, $attribute.' marker was not rendered.');

            foreach (['overflow-y-auto', 'overflow-y-scroll', 'overflow-auto', 'max-h-'] as $forbiddenClass) {
                $this->assertStringNotContainsString($forbiddenClass, $tag[0]);
            }
            $this->assertDoesNotMatchRegularExpression('/(?:^|\s)h-(?:\d|\[)/', $tag[0]);
        };
        $compactHistoryResponse = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']));
        $compactHistoryResponse
            ->assertOk()
            ->assertSee('View All')
            ->assertSee('data-modal-open="all-activity-history"', false)
            ->assertSee('id="all-activity-history"', false)
            ->assertSee('data-ci-history-modal-body', false)
            ->assertSee('fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-2xl overflow-hidden', false)
            ->assertSee('flex max-h-[calc(100dvh-2rem)] flex-col', false)
            ->assertSee('min-h-0 overflow-y-auto overscroll-contain', false)
            ->assertSee('Close Activity History')
            ->assertSee('Applicant Event 10 updated')
            ->assertSee('Applicant Event 01 deleted')
            ->assertDontSee('Co-Maker Scoped Event')
            ->assertDontSee('history=all', false);
        $this->assertSame(
            [
                'Applicant Event 10 updated',
                'Applicant Event 09 updated',
                'Applicant Event 08 updated',
                'Applicant Event 07 updated',
                'Applicant Event 06 updated',
            ],
            $compactHistoryResponse->viewData('history')->pluck('label')->all(),
        );
        $this->assertCount(10, $compactHistoryResponse->viewData('allHistory'));
        $this->assertMatchesRegularExpression(
            '/<aside[^>]*data-ci-history-panel[^>]*>.*Applicant Event 06 updated.*View All.*<\/aside>/s',
            $compactHistoryResponse->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<aside(?=[^>]*data-ci-history-panel)(?![^>]*\shidden(?:\s|>))[^>]*>/',
            $compactHistoryResponse->getContent(),
        );
        $compactHistoryResponse
            ->assertSee('data-ci-history-hide', false)
            ->assertSee('Hide Activity History panel')
            ->assertSee('data-ci-history-show', false)
            ->assertSee('Show Activity History panel');
        $assertNormalPageScroll($compactHistoryResponse->getContent(), 'data-ci-activities-panel');
        $assertNormalPageScroll($compactHistoryResponse->getContent(), 'data-ci-history-panel');

        $coMakerResponse = $this->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
            'status' => 'all',
        ]));
        $coMakerResponse
            ->assertOk()
            ->assertSee('Co-Maker Scoped Event reopened')
            ->assertDontSee('Applicant Event 10 updated')
            ->assertDontSee('Applicant Event 01 deleted');
        $this->assertCount(1, $coMakerResponse->viewData('allHistory'));
    }

    public function test_simple_status_workflow_allows_direct_completion_and_requires_a_date_only_when_scheduled(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'pending'])->assertSessionHasNoErrors();
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'scheduled'])
            ->assertSessionHasErrors('scheduled_at');
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'follow_up'])
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'follow_up', 'scheduled_at' => 'not-a-date'])
            ->assertSessionHasErrors('scheduled_at');
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'completed'])
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'invalid'])
            ->assertSessionHasErrors('status');
    }

    public function test_add_activity_schedule_field_and_backend_follow_the_selected_status(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $definitions = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->get();
        $futureSchedule = now()->addDay()->format('Y-m-d H:i:s');
        $this->actingAs($ci);

        $page = $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $page
            ->assertSee('data-ci-activity-status', false)
            ->assertSee('data-ci-activity-schedule disabled', false)
            ->assertSee('disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted disabled:opacity-75', false)
            ->assertSee("const enabled = ! addingNewActivityType() && ['scheduled', 'follow_up'].includes(status.value);", false)
            ->assertSee("if (! enabled) schedule.value = '';", false)
            ->assertSee("schedule.required = ! addingNewActivityType() && status.value === 'scheduled';", false);

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definitions[0]->id,
            'status' => 'pending',
            'scheduled_at' => $futureSchedule,
        ])->assertRedirect();
        $pending = $folder->activities()->where('activity_definition_id', $definitions[0]->id)->sole();
        $this->assertSame(ActivityStatus::Pending, $pending->status);
        $this->assertNull($pending->scheduled_at);

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definitions[1]->id,
            'status' => 'completed',
            'scheduled_at' => $futureSchedule,
        ])->assertRedirect();
        $completed = $folder->activities()->where('activity_definition_id', $definitions[1]->id)->sole();
        $this->assertSame(ActivityStatus::Completed, $completed->status);
        $this->assertNull($completed->scheduled_at);

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definitions[2]->id,
            'status' => 'scheduled',
        ])->assertSessionHasErrors('scheduled_at');
        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definitions[2]->id,
            'status' => 'scheduled',
            'scheduled_at' => $futureSchedule,
        ])->assertRedirect();
        $scheduled = $folder->activities()->where('activity_definition_id', $definitions[2]->id)->sole();
        $this->assertSame(ActivityStatus::Scheduled, $scheduled->status);
        $this->assertNotNull($scheduled->scheduled_at);

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definitions[3]->id,
            'status' => 'follow_up',
            'scheduled_at' => $futureSchedule,
        ])->assertRedirect();
        $followUp = $folder->activities()->where('activity_definition_id', $definitions[3]->id)->sole();
        $this->assertSame(ActivityStatus::FollowUp, $followUp->status);
        $this->assertNotNull($followUp->scheduled_at);
    }

    public function test_edit_activity_schedule_field_clears_stale_schedule_and_reminder_state(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $initialSchedule = now()->addDay()->startOfMinute();
        $activity->forceFill([
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $initialSchedule,
            'reminder_sent_at' => now(),
        ])->saveQuietly();
        $this->actingAs($ci);

        $scheduledPage = $this->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $scheduledPage
            ->assertSee('data-ci-edit-status', false)
            ->assertSee('data-ci-edit-schedule', false)
            ->assertSee($initialSchedule->copy()->timezone(config('cims.display_timezone'))->format('Y-m-d\TH:i'), false)
            ->assertSee("const enabled = ['scheduled', 'follow_up'].includes(status.value);", false)
            ->assertSee("if (!enabled) schedule.value = '';", false);
        $this->assertSame(1, preg_match('/<input[^>]+data-ci-edit-schedule[^>]*>/', $scheduledPage->getContent(), $scheduledInput));
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|=|>)/', $scheduledInput[0]);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'pending',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertNull($activity->reminder_sent_at);

        $followUpSchedule = now()->addDays(3)->startOfMinute();
        $this->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'follow_up',
            'scheduled_at' => $followUpSchedule->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::FollowUp, $activity->status);
        $this->assertTrue($activity->scheduled_at->equalTo($followUpSchedule));
        $followUpPage = $this->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $this->assertSame(1, preg_match('/<input[^>]+data-ci-edit-schedule[^>]*>/', $followUpPage->getContent(), $followUpInput));
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|=|>)/', $followUpInput[0]);
        $followUpPage->assertSee('aria-disabled="false"', false);

        $activity->forceFill(['reminder_sent_at' => now()])->saveQuietly();
        $this->put(route('client-folders.activities.update', [$folder, $activity]), [
            'status' => 'completed',
            'scheduled_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertNull($activity->reminder_sent_at);

        $completedPage = $this->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $this->assertSame(1, preg_match('/<input[^>]+data-ci-edit-schedule[^>]*>/', $completedPage->getContent(), $completedInput));
        $this->assertMatchesRegularExpression('/\sdisabled(?:\s|=|>)/', $completedInput[0]);
        $completedPage->assertSee('aria-disabled="true"', false);
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
        $this->assertSame(0, $newFolder->activities()->where('status', 'pending')->count());
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

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->assertSee('1 Attachment');
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

    public function test_custom_activity_type_creation_is_separate_from_activity_creation_and_reuses_global_definitions(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
        $this->actingAs($ci);

        $modal = $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $modal
            ->assertSee('Add Activity Type')
            ->assertSee('Saving this reusable Activity Type will not create a CI Activity or assign a Creator.')
            ->assertSee('data-custom-activity-info', false)
            ->assertSee("submitLabel.textContent = addingNewType ? 'Add Activity Type' : 'Add Activity';", false)
            ->assertSee("form.dataset.submissionMode = addingNewType ? 'activity-type' : 'activity';", false);

        $definitionOnlyResponse = $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => '  Barangay   Certification Follow-up  ',
            'status' => 'completed',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'remarks' => 'THIS MUST NOT CREATE AN ACTIVITY',
        ])->assertRedirect()
            ->assertSessionHas('ci_activity_modal_open', true);

        $definition = ActivityDefinition::query()->where('name', 'Barangay Certification Follow-up')->sole();
        $definitionOnlyResponse->assertSessionHas('_old_input.activity_definition_id', $definition->id);
        $this->assertTrue($definition->is_active);
        $this->assertFalse($definition->is_required);
        $this->assertStringStartsWith('custom_', $definition->code);
        $this->assertSame(0, CiActivity::query()->where('activity_definition_id', $definition->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'ci_activity.created')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'activity_definition.created')->where('metadata->activity_definition_id', $definition->id)->count());

        $reopenedModal = $this->get($definitionOnlyResponse->headers->get('Location'))->assertOk();
        $reopenedModal
            ->assertSee('Barangay Certification Follow-up activity type is ready to use.')
            ->assertSee('data-ci-activity-dialog-body', false)
            ->assertSee('dialogBody.scrollTop = 0;', false)
            ->assertSee('Proof / Attachment')
            ->assertDontSee('You will become the Creator of this activity.');
        $this->assertMatchesRegularExpression(
            '/<dialog[^>]*data-ci-activity-dialog[^>]*open[^>]*>/',
            $reopenedModal->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="activity-definition"[^>]*value="'.preg_quote((string) $definition->id, '/').'"[^>]*>/',
            $reopenedModal->getContent(),
        );
        $reopenedModal
            ->assertSee('My Custom Activity Types')
            ->assertSee('data-activity-type-remove="'.$definition->id.'"', false)
            ->assertSee('Remove Activity Type');

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'barangay certification follow-up',
        ])->assertRedirect();
        $equivalentDefinitions = ActivityDefinition::query()->get()
            ->filter(fn (ActivityDefinition $candidate): bool => ActivityDefinition::normalizedNameKey($candidate->name) === ActivityDefinition::normalizedNameKey($definition->name));
        $this->assertCount(1, $equivalentDefinitions);
        $this->assertSame(0, CiActivity::query()->where('activity_definition_id', $definition->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'activity_definition.created')->where('metadata->activity_definition_id', $definition->id)->count());

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
            'remarks' => 'APPLICANT CUSTOM DETAILS',
        ])->assertRedirect();
        $applicantActivity = CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->whereNull('co_maker_id')
            ->where('activity_definition_id', $definition->id)
            ->sole();
        $this->assertSame($ci->id, $applicantActivity->creator_id);
        $this->assertSame('APPLICANT CUSTOM DETAILS', $applicantActivity->remarks);
        $this->assertSame(1, AuditLog::query()->where('action', 'ci_activity.created')->where('metadata->activity_definition_id', $definition->id)->count());

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
        ])->assertSessionHasErrors('activity_definition_id');
        $this->assertSame(1, CiActivity::query()->where('activity_definition_id', $definition->id)->count());

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
            'remarks' => 'CO-MAKER A CUSTOM DETAILS',
        ])->assertRedirect();
        $this->assertDatabaseHas('ci_activities', [
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $definition->id,
            'creator_id' => $ci->id,
        ]);
        $this->assertSame(2, CiActivity::query()->where('activity_definition_id', $definition->id)->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'ci_activity.created')->where('metadata->activity_definition_id', $definition->id)->count());
        $this->assertSame(0, CiActivity::query()->where('co_maker_id', $coMakerB->id)->where('activity_definition_id', $definition->id)->count());
    }

    public function test_add_activity_modal_stays_open_with_clean_form_and_scoped_close_behavior_after_success(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();

        $storeResponse = $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
            'remarks' => 'Completed request.',
        ])->assertRedirect();

        $page = $this->get($storeResponse->headers->get('Location'))->assertOk();
        $page
            ->assertSee('Activity added successfully.')
            ->assertSee('data-ci-activity-success', false)
            ->assertSee('data-ci-activity-initial-open', false)
            ->assertSee('event.target === dialog', false)
            ->assertSee("dialog.addEventListener('cancel'", false)
            ->assertSee('event.preventDefault();', false)
            ->assertSee('window.setTimeout(finishClose, 190)', false)
            ->assertSee("window.matchMedia('(prefers-reduced-motion: reduce)')", false)
            ->assertSee("dialog.dataset.modalState = 'closing';", false)
            ->assertSee('if (dialog.open) dialog.close();', false)
            ->assertSee('data-ci-activity-dialog-body', false)
            ->assertSee('max-w-xl overflow-hidden', false)
            ->assertSee('flex max-h-[calc(100dvh-2rem)] flex-col', false)
            ->assertSee('min-h-0 overflow-y-auto overscroll-contain', false)
            ->assertSee('dialogBody.scrollTop = 0;', false)
            ->assertSee('Proof / Attachment')
            ->assertSee('Upload Attachment')
            ->assertDontSee('You will become the Creator of this activity.');
        $this->assertMatchesRegularExpression(
            '/<dialog[^>]*data-ci-activity-dialog[^>]*open[^>]*data-ci-activity-initial-open[^>]*>/',
            $page->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="activity-definition"[^>]*value=""[^>]*>/',
            $page->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<option value="pending"[^>]*selected[^>]*>Pending<\/option>/',
            $page->getContent(),
        );
        $page->assertDontSee('Completed request.');
        $this->assertSame(2, substr_count($page->getContent(), ' data-ci-activity-dialog-close'));
        preg_match('/<form[^>]*data-ci-activity-create-form[^>]*>(.*?)<\/form>/s', $page->getContent(), $activityForm);
        $this->assertStringNotContainsString('Creator:</span>', $activityForm[1] ?? '');
        $this->assertSame($ci->id, $folder->activities()->where('activity_definition_id', $definition->id)->sole()->creator_id);
    }

    public function test_add_activity_modal_blocks_empty_client_submit_and_server_errors_do_not_replay_open_animation(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci);

        $page = $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $page
            ->assertSee('data-ci-activity-create-form novalidate', false)
            ->assertSee('data-ci-activity-type-error', false)
            ->assertSee('data-ci-activity-schedule-error', false)
            ->assertSee('const validateActivityForm = () => {', false)
            ->assertSee('revealFirstInvalid(firstInvalid);', false)
            ->assertSee("status.value === 'scheduled' && schedule.value === ''", false)
            ->assertSee('control.focus({ preventScroll: true });', false);

        $invalidResponse = $this->post(route('client-folders.activities.store', $folder), [
            'status' => 'pending',
        ])->assertRedirect()->assertSessionHasErrors('activity_definition_id');

        $validationPage = $this->get($invalidResponse->headers->get('Location'))->assertOk();
        $validationPage
            ->assertSee('data-ci-activity-validation-open', false)
            ->assertSee('data-modal-state="open"', false)
            ->assertSee("if (dialog.hasAttribute('data-ci-activity-validation-open'))", false);
    }

    public function test_activity_type_picker_only_offers_remove_controls_for_custom_definitions(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $systemDefinition = ActivityDefinition::query()->where('is_active', true)->where('code', 'not like', 'custom%')->firstOrFail();
        $customDefinition = ActivityDefinition::factory()->create([
            'name' => 'Supplier Follow-up',
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'supplier_follow_up',
            'is_required' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk();
        $response
            ->assertSee('Built-in Activity Types')
            ->assertSee('My Custom Activity Types')
            ->assertSee('data-activity-type-option', false)
            ->assertSee('data-value="'.$systemDefinition->id.'"', false)
            ->assertSee('data-value="'.$customDefinition->id.'"', false)
            ->assertDontSee('data-activity-type-remove="'.$systemDefinition->id.'"', false)
            ->assertSee('data-activity-type-remove="'.$customDefinition->id.'"', false)
            ->assertSee('data-custom-activity-type-row="'.$customDefinition->id.'"', false)
            ->assertSee('data-custom-activity-types', false)
            ->assertSee('title="Remove Activity Type"', false)
            ->assertSee('Delete Activity Type?')
            ->assertSee('Supplier Follow-up</span> will be permanently deleted and can be created again later.', false)
            ->assertSee('Delete Permanently')
            ->assertSee('event.stopPropagation();', false)
            ->assertSee("select.dispatchEvent(new Event('change'));", false)
            ->assertSee('dialog.showModal();', false)
            ->assertSee('await fetch(removalForm.action', false)
            ->assertSee("button.closest('[data-custom-activity-type-row]')?.remove();", false)
            ->assertSee('HTMLFormElement.prototype.submit.call(removalForm);', false);

        $deletedDefinitionId = $customDefinition->id;
        $this->delete(route('client-folders.activity-definitions.deactivate', [$folder, $customDefinition]), [
            'selected_activity_definition_id' => (string) $systemDefinition->id,
        ])->assertSessionHas('_old_input.activity_definition_id', (string) $systemDefinition->id)
            ->assertSessionHas('status', 'Supplier Follow-up activity type permanently deleted.');
        $this->assertDatabaseMissing('activity_definitions', ['id' => $deletedDefinitionId]);
        $this->assertDatabaseMissing('activity_definitions', ['name' => 'Supplier Follow-up', 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'activity_definition.deleted',
            'metadata->activity_definition_id' => $deletedDefinitionId,
            'metadata->removal_mode' => 'deleted',
        ]);

        $this->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'Supplier Follow-up',
        ])->assertRedirect();
        $recreatedDefinition = ActivityDefinition::query()->where('name', 'Supplier Follow-up')->sole();
        $this->assertNotSame($deletedDefinitionId, $recreatedDefinition->id);
        $this->assertTrue($recreatedDefinition->is_active);
        $this->assertSame(1, ActivityDefinition::query()->where('name', 'Supplier Follow-up')->count());
    }

    public function test_custom_activity_type_deactivation_is_global_and_preserves_historical_records(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'HISTORICAL CO MAKER',
            'first_name' => 'Historical',
            'last_name' => 'Co Maker',
        ]);
        $customDefinition = ActivityDefinition::factory()->create([
            'name' => 'Barangay Verification',
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'barangay_verification',
            'is_required' => false,
            'is_active' => true,
        ]);
        $activity = CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $customDefinition->id,
            'name' => $customDefinition->name,
            'status' => ActivityStatus::Completed,
            'creator_id' => $ci->id,
            'submitted_by' => $ci->id,
            'submitted_at' => now(),
            'submitted_to' => 'Credit Analyst',
            'submission_note' => 'Historical submission remains available.',
            'completed_at' => now(),
        ]);
        $coMakerActivity = CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker->id,
            'activity_definition_id' => $customDefinition->id,
            'name' => $customDefinition->name,
            'creator_id' => $ci->id,
        ]);
        $media = MediaReference::factory()->create([
            'client_folder_id' => $folder->id,
            'uploaded_by' => $ci->id,
            'file_name' => 'barangay-verification.pdf',
        ]);
        $activity->mediaReferences()->attach($media, ['label' => 'Historical proof']);
        $history = AuditLog::create([
            'user_id' => $ci->id,
            'client_folder_id' => $folder->id,
            'action' => 'ci_activity.submitted',
            'module' => 'ci_activities',
            'description' => 'A CI activity submission was recorded.',
            'metadata' => [
                'activity_id' => $activity->id,
                'co_maker_id' => null,
                'activity_title' => $activity->name,
            ],
        ]);

        $confirmation = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk();
        $confirmation
            ->assertSee('Remove Activity Type?')
            ->assertSee('Barangay Verification</span> has existing activity history. It will be removed from future selection, but historical records will be preserved.', false);

        $response = $this->delete(
            route('client-folders.activity-definitions.deactivate', [$folder, $customDefinition]),
            [
                'status' => 'completed',
                'selected_activity_definition_id' => (string) $customDefinition->id,
            ],
        );

        $response
            ->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'completed']))
            ->assertSessionHas('status', 'Barangay Verification activity type removed.')
            ->assertSessionHas('ci_activity_modal_open', true)
            ->assertSessionHas('_old_input.activity_definition_id', '');
        $this->assertDatabaseHas('activity_definitions', ['id' => $customDefinition->id, 'is_active' => false]);
        $this->assertDatabaseHas('ci_activities', [
            'id' => $activity->id,
            'activity_definition_id' => $customDefinition->id,
            'submitted_to' => 'Credit Analyst',
            'submission_note' => 'Historical submission remains available.',
        ]);
        $this->assertDatabaseHas('ci_activities', [
            'id' => $coMakerActivity->id,
            'co_maker_id' => $coMaker->id,
            'activity_definition_id' => $customDefinition->id,
        ]);
        $this->assertDatabaseHas('media_references', ['id' => $media->id, 'file_name' => 'barangay-verification.pdf']);
        $this->assertDatabaseHas('activity_media', ['ci_activity_id' => $activity->id, 'media_reference_id' => $media->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $history->id, 'action' => 'ci_activity.submitted']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'activity_definition.deactivated',
            'user_id' => $ci->id,
            'metadata->activity_definition_id' => $customDefinition->id,
            'metadata->removal_mode' => 'deactivated',
        ]);

        $page = $this->get($response->headers->get('Location'))->assertOk();
        $page
            ->assertSee('Barangay Verification')
            ->assertSee('Historical submission remains available.')
            ->assertSee('data-ci-activity-dialog-body', false)
            ->assertSee('dialogBody.scrollTop = 0;', false)
            ->assertDontSee('data-value="'.$customDefinition->id.'"', false)
            ->assertDontSee('data-activity-type-remove="'.$customDefinition->id.'"', false);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="activity-definition"[^>]*value=""[^>]*>/',
            $page->getContent(),
        );
    }

    public function test_system_activity_definition_cannot_be_deactivated_through_custom_type_route(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $systemDefinition = ActivityDefinition::query()->where('is_active', true)->where('code', 'not like', 'custom%')->firstOrFail();

        $this->actingAs($ci)
            ->delete(route('client-folders.activity-definitions.deactivate', [$folder, $systemDefinition]))
            ->assertNotFound();

        $this->assertDatabaseHas('activity_definitions', ['id' => $systemDefinition->id, 'is_active' => true]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'activity_definition.deactivated',
            'metadata->activity_definition_id' => $systemDefinition->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'activity_definition.deleted',
            'metadata->activity_definition_id' => $systemDefinition->id,
        ]);
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

    public function test_custom_activity_definition_creation_does_not_run_the_activity_completion_pipeline(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->mock(CiActivitiesCompletionEvaluator::class)
            ->shouldNotReceive('evaluate');

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'new_activity_type' => 'Definition Only Inquiry',
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_definitions', ['name' => 'Definition Only Inquiry']);
        $this->assertDatabaseMissing('ci_activities', ['name' => 'Definition Only Inquiry']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'activity_definition.created']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ci_activity.created']);
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
        $indexResponse = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $indexResponse->assertOk()
            ->assertSee('aria-label="Breadcrumb"', false)
            ->assertSee(route('client-folders.index'), false)
            ->assertSee(route('client-folders.show', $folder), false)
            ->assertDontSee('Current View:')->assertDontSee('Switch Person')
            ->assertSee('xl:grid-cols-[minmax(0,4fr)_minmax(15rem,1fr)]', false)->assertSee('Activity History')->assertSee('No Attachment')->assertSee('Not Submitted')
            ->assertSee('data-ci-activities-layout', false)
            ->assertSee('data-ci-history-hide', false)
            ->assertSee('data-ci-history-show', false)
            ->assertSee('panel.hidden = hidden;', false)
            ->assertSee('layout.classList.toggle(desktopColumns, ! hidden);', false)
            ->assertSee("layout.classList.toggle('gap-5', ! hidden);", false)
            ->assertDontSee('document.body.style.overflow', false)
            ->assertSee('min-w-[64rem]', false)
            ->assertSee('Scheduled Today')->assertSee('For Follow-up')->assertSee('All Activities')
            ->assertSee('data-ci-activity-tab', false)
            ->assertSee('data-ci-activity-row', false)
            ->assertSee('data-ci-sort="activity"', false)
            ->assertSee('data-ci-sort="status"', false)
            ->assertSee('data-ci-sort="schedule"', false)
            ->assertSee('data-ci-sort="creator"', false)
            ->assertSee('data-ci-sort="updated"', false)
            ->assertSee('event.preventDefault();', false)
            ->assertSee("window.history.replaceState({}, '', url);", false)
            ->assertSee('const matchesFilter = (row, filter) =>', false)
            ->assertSee('+ Add New Activity Type')->assertSee('data-new-activity-type-fields', false)
            ->assertSee('fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-hidden', false)
            ->assertSee('data-ci-activity-dialog-body', false)
            ->assertSee('backdrop:bg-brand-sidebar/45', false)
            ->assertDontSee('Residence Check')->assertDontSee('Business Check')
            ->assertSeeInOrder(['Barangay Check', 'Neighbor Check', 'Asset Check', 'Bank / Coop Check']);
        $this->assertSame('all', $indexResponse->viewData('filter'));
        $this->assertSame($folder->activities()->count(), $indexResponse->viewData('counts')['all']);
        preg_match('/<nav[^>]*aria-label="Activity status filters"[^>]*>(.*?)<\/nav>/s', $indexResponse->getContent(), $tabNavigation);
        $this->assertMatchesRegularExpression('/All Activities.*Pending.*Scheduled Today.*For Follow-up.*Completed/s', $tabNavigation[1] ?? '');
        $pendingResponse = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'pending']))
            ->assertOk()
            ->assertViewHas('filter', 'pending');
        $this->assertSame($folder->activities()->count(), $pendingResponse->viewData('activities')->count());
        $this->assertSame(
            $folder->activities()->where('status', ActivityStatus::Pending)->count(),
            $pendingResponse->viewData('visibleActivityIds')->count(),
        );
        $this->get(route('client-folders.activities.index', [$folder, 'status' => 'invalid']))
            ->assertOk()
            ->assertViewHas('filter', 'all');
        $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk()
            ->assertSee('xl:grid-cols-', false)->assertSee('No notes recorded.')->assertSee('No proof is linked. Proof is optional.')
            ->assertSee(route('client-folders.media.index', $folder), false);
    }

    public function test_bulk_selection_controls_reuse_the_residence_business_compact_icon_pattern(): void
    {
        $activityView = file_get_contents(resource_path('views/client-folders/activities/index.blade.php'));
        $referenceView = file_get_contents(resource_path('views/client-folders/residence-business/edit.blade.php'));

        $this->assertStringContainsString(
            '<button type="button" class="ui-button-secondary-compact" data-check-clear-selection><x-ui.icon name="close" size="size-3.5" />Clear Selection</button>',
            $referenceView,
        );
        $this->assertStringContainsString(
            '<button type="button" class="ui-button-secondary-compact shrink-0" data-clear-selected-button hidden><x-ui.icon name="close" size="size-3.5" />Clear Selected</button>',
            $activityView,
        );
        $this->assertStringContainsString(
            '<button type="button" class="ui-button-danger-compact shrink-0" data-modal-open="bulk-delete-activities" data-bulk-delete-button disabled>',
            $activityView,
        );
        $this->assertStringContainsString('<x-ui.icon name="trash" size="size-3.5" />', $activityView);
        $this->assertStringContainsString('flex shrink-0 flex-wrap items-center justify-end gap-2', $activityView);
        $this->assertStringNotContainsString('name="circle-x"', $activityView);

        preg_match('/const clearSelections = \(\) => \{(.*?)\n\s*\};/s', $activityView, $clearSelectionFunction);
        $this->assertStringContainsString('checkbox.checked = false', $clearSelectionFunction[1] ?? '');
        $this->assertStringContainsString('syncBulkSelection();', $clearSelectionFunction[1] ?? '');
        $this->assertStringNotContainsString('activeFilter', $clearSelectionFunction[1] ?? '');
        $this->assertStringNotContainsString('activeSort', $clearSelectionFunction[1] ?? '');
        $this->assertStringContainsString("clearButton.addEventListener('click', clearSelections);", $activityView);
    }

    public function test_activity_creation_locks_creator_and_another_ci_can_update_without_replacing_them(): void
    {
        $creator = User::factory()->create(['full_name' => 'Rey Creator']);
        $other = User::factory()->create(['full_name' => 'Mark Updater']);
        [$folder] = $this->folderWithActivities($creator);
        $definition = ActivityDefinition::factory()->create([
            'name' => 'Accountability Check',
            'is_active' => true,
        ]);

        $this->actingAs($creator)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'status' => 'pending',
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

        foreach ([[$coMakerA, 'ALPHA DETAILS'], [$coMakerB, 'BETA DETAILS']] as [$person, $remarks]) {
            $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
                'co_maker_id' => $person->id,
                'activity_definition_id' => $definition->id,
                'status' => 'pending',
                'remarks' => $remarks,
            ])->assertRedirect();
        }

        $applicantResponse = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()
            ->assertDontSee('Switch Person');
        $this->assertFalse($applicantResponse->viewData('activities')->contains('remarks', 'ALPHA DETAILS'));
        $this->assertFalse($applicantResponse->viewData('activities')->contains('remarks', 'BETA DETAILS'));
        $coMakerAResponse = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id, 'status' => 'all']))->assertOk()
            ->assertDontSee('Current View:')->assertDontSee('Switch Person')
            ->assertSee(route('client-folders.show', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]));
        $this->assertTrue($coMakerAResponse->viewData('activities')->contains('remarks', 'ALPHA DETAILS'));
        $this->assertFalse($coMakerAResponse->viewData('activities')->contains('remarks', 'BETA DETAILS'));
        $coMakerBResponse = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id, 'status' => 'all']))->assertOk()
            ->assertDontSee('Switch Person');
        $this->assertTrue($coMakerBResponse->viewData('activities')->contains('remarks', 'BETA DETAILS'));
        $this->assertFalse($coMakerBResponse->viewData('activities')->contains('remarks', 'ALPHA DETAILS'));
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

    public function test_explicit_manila_schedule_round_trips_through_create_edit_display_today_and_reminder_without_a_shift(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-29 05:59:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            [$folder] = $this->folderWithActivities($creator);
            $definition = ActivityDefinition::factory()->create([
                'name' => 'Timezone Round Trip',
                'code' => 'timezone_round_trip',
            ]);

            $this->actingAs($creator)->post(route('client-folders.activities.store', $folder), [
                'activity_definition_id' => $definition->id,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-29',
                'scheduled_time' => '14:00',
            ])->assertRedirect();

            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($creator): bool {
                return $notification->toArray($creator)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED;
            });

            $activity = CiActivity::query()->where('activity_definition_id', $definition->id)->sole();
            $this->assertTrue($activity->scheduled_has_time);
            $this->assertSame('2026-08-29 06:00:00', DB::table('ci_activities')->where('id', $activity->id)->value('scheduled_at'));
            $this->assertSame('2026-08-29 14:00', $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('Y-m-d H:i'));

            $indexResponse = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'scheduled_today']))
                ->assertOk()
                ->assertSee('Aug 29, 2026')
                ->assertSee('2:00 PM');
            $this->assertSame(1, $indexResponse->viewData('counts')['scheduled_today']);
            $this->assertTrue($indexResponse->viewData('visibleActivityIds')->contains($activity->id));
            $this->get(route('home'))
                ->assertOk()
                ->assertSee('Timezone Round Trip')
                ->assertSee('2:00 PM');

            $this->get(route('client-folders.activities.edit', [$folder, $activity]))
                ->assertOk()
                ->assertSee('name="scheduled_at" type="date" value="2026-08-29"', false)
                ->assertSee('name="scheduled_time" type="time" value="14:00"', false);

            Notification::fake();
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertNothingSent();

            Carbon::setTestNow(Carbon::parse('2026-08-29 06:00:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertSentTo($creator, CiActivityScheduledReminder::class);

            Notification::fake();
            Carbon::setTestNow(Carbon::parse('2026-08-29 06:01:00', 'UTC'));
            $this->actingAs($creator)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-29',
                'scheduled_time' => '15:30',
            ])->assertRedirect();

            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($creator): bool {
                return $notification->toArray($creator)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED;
            });

            $activity->refresh();
            $this->assertSame('2026-08-29 07:30:00', DB::table('ci_activities')->where('id', $activity->id)->value('scheduled_at'));
            $this->assertSame('2026-08-29 15:30', $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('Y-m-d H:i'));
            $this->assertNull($activity->reminder_sent_at);
            $this->get(route('client-folders.activities.index', [$folder, 'status' => 'scheduled_today']))
                ->assertOk()
                ->assertSee('Aug 29, 2026')
                ->assertSee('3:30 PM');
            $this->get(route('home'))
                ->assertOk()
                ->assertSee('Timezone Round Trip')
                ->assertSee('3:30 PM');

            $audit = AuditLog::query()->where('action', 'ci_activity.rescheduled')->latest('id')->firstOrFail();
            $this->assertSame('2026-08-29T07:30:00.000000Z', $audit->metadata['scheduled_at']);

            Notification::fake();
            Carbon::setTestNow(Carbon::parse('2026-08-29 07:29:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertNothingSent();

            Carbon::setTestNow(Carbon::parse('2026-08-29 07:30:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertSentTo($creator, CiActivityScheduledReminder::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_due_reminder_goes_only_to_creator_and_completed_activity_stops_future_reminders(): void
    {
        Notification::fake();
        $creator = User::factory()->create();
        $other = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($creator);
        $activity->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'creator_id' => $creator->id]);

        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($creator): bool {
            return $notification->toArray($creator)['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER;
        });
        Notification::assertNotSentTo($other, CiActivityScheduledReminder::class);
        $this->assertNotNull($activity->refresh()->reminder_sent_at);

        Notification::fake();
        $activity->update(['status' => ActivityStatus::Completed, 'reminder_sent_at' => null]);
        $this->artisan('ci-activities:send-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_scheduled_today_notification_is_read_only_when_its_creator_opens_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 06:00:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $otherCi = User::factory()->create();
            [$folder, $activity] = $this->folderWithActivities($creator);
            $activity->update([
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 07:00:00', 'UTC'),
                'scheduled_has_time' => true,
                'creator_id' => $creator->id,
            ]);
            $creator->notify(new CiActivityScheduledReminder($activity->fresh()));
            $notification = $creator->notifications()->sole();
            $trackerUrl = route('client-folders.activities.index', $folder);
            $this->assertSame($trackerUrl, data_get($notification->data, 'url'));
            $this->assertNotSame(route('client-folders.activities.edit', [$folder, $activity]), data_get($notification->data, 'url'));

            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('data-scheduled-notification-state="unread"', false)
                ->assertSee($activity->name);
            $this->assertNull($notification->fresh()->read_at);

            $this->actingAs($otherCi)
                ->post(route('notifications.ci-activities.read', $notification->id))
                ->assertNotFound();
            $this->assertNull($notification->fresh()->read_at);

            $this->actingAs($creator)
                ->post(route('notifications.ci-activities.read', $notification->id))
                ->assertRedirect($trackerUrl);
            $this->assertNotNull($notification->fresh()->read_at);

            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertDontSee('data-scheduled-today-count', false)
                ->assertSee('data-scheduled-notification-state="read"', false)
                ->assertSee($activity->name);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_co_maker_notification_opens_the_exact_person_tracker_and_marks_only_that_notification_read(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 06:00:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id]);
            $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
            $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'BETA MAKER', 'first_name' => 'Beta', 'last_name' => 'Maker']);
            $definition = ActivityDefinition::query()->where('is_active', true)->firstOrFail();
            $activity = CiActivity::create([
                'client_folder_id' => $folder->id,
                'co_maker_id' => $coMakerA->id,
                'activity_definition_id' => $definition->id,
                'name' => $definition->name,
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 07:00:00', 'UTC'),
                'scheduled_has_time' => true,
                'creator_id' => $creator->id,
            ]);
            $creator->notify(new CiActivityScheduledReminder($activity));
            $notification = $creator->notifications()->sole();
            $trackerUrl = route('client-folders.activities.index', [
                $folder,
                'person' => 'co-maker',
                'co_maker_id' => $coMakerA->id,
            ]);

            $this->assertSame($trackerUrl, data_get($notification->data, 'url'));
            $this->assertStringContainsString('co_maker_id='.$coMakerA->id, $trackerUrl);
            $this->assertStringNotContainsString('co_maker_id='.$coMakerB->id, $trackerUrl);
            $this->assertStringNotContainsString('/activities/'.$activity->id, $trackerUrl);

            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('Co-Maker: ALPHA MAKER')
                ->assertSee('data-scheduled-notification-state="unread"', false);
            $this->assertNull($notification->fresh()->read_at);

            $this->post(route('notifications.ci-activities.read', $notification->id))
                ->assertRedirect($trackerUrl);
            $this->assertNotNull($notification->fresh()->read_at);
            $this->get(route('home'))
                ->assertOk()
                ->assertDontSee('data-scheduled-today-count', false)
                ->assertSee('data-scheduled-notification-state="read"', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rescheduling_creates_a_fresh_unread_notification_for_the_original_creator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 06:00:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $updater = User::factory()->create();
            [$folder, $activity] = $this->folderWithActivities($creator);

            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-29',
                'scheduled_time' => '15:00',
            ])->assertRedirect();

            $activity->refresh();
            $createdNotification = $creator->notifications()->sole();
            $this->assertNull($createdNotification->read_at);
            $this->assertSame(CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED, data_get($createdNotification->data, 'purpose'));
            $this->assertSame($creator->id, $activity->creator_id);
            $this->assertSame($updater->id, $activity->updated_by);
            $this->assertNull($activity->reminder_sent_at);
            $this->assertCount(0, $updater->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('data-scheduled-notification-state="unread"', false)
                ->assertSee('3:00 PM');
            $this->post(route('notifications.ci-activities.read', $createdNotification->id))->assertRedirect();
            $this->assertNotNull($createdNotification->fresh()->read_at);

            Carbon::setTestNow(Carbon::parse('2026-08-29 06:05:00', 'UTC'));
            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-30',
                'scheduled_time' => '15:00',
            ])->assertRedirect();
            $dateChangedNotification = $creator->notifications()->whereNull('read_at')->sole();
            $this->assertNotSame($createdNotification->id, $dateChangedNotification->id);
            $this->assertSame(CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED, data_get($dateChangedNotification->data, 'purpose'));
            $this->assertSame('2026-08-30T07:00:00.000000Z', data_get($dateChangedNotification->data, 'scheduled_at'));
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false);
            $this->post(route('notifications.ci-activities.read', $dateChangedNotification->id))->assertRedirect();

            Carbon::setTestNow(Carbon::parse('2026-08-29 06:10:00', 'UTC'));
            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-30',
                'scheduled_time' => '10:00',
            ])->assertRedirect();
            $timeChangedNotification = $creator->notifications()->whereNull('read_at')->sole();
            $this->assertNotSame($dateChangedNotification->id, $timeChangedNotification->id);
            $this->assertSame(CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED, data_get($timeChangedNotification->data, 'purpose'));
            $this->assertSame('2026-08-30T02:00:00.000000Z', data_get($timeChangedNotification->data, 'scheduled_at'));
            $this->assertCount(3, $creator->notifications()->get());
            $this->assertCount(0, $updater->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false);
            $this->post(route('notifications.ci-activities.read', $timeChangedNotification->id))->assertRedirect();

            Carbon::setTestNow(Carbon::parse('2026-08-30 01:59:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            $this->assertCount(3, $creator->notifications()->get());

            Carbon::setTestNow(Carbon::parse('2026-08-30 02:00:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            $dueNotifications = $creator->notifications()->get()->filter(
                fn ($notification): bool => data_get($notification->data, 'purpose') === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER,
            );
            $this->assertCount(1, $dueNotifications);
            $dueNotification = $dueNotifications->sole();
            $this->assertNull($dueNotification->read_at);
            $this->assertNotSame($timeChangedNotification->id, $dueNotification->id);
            $this->assertSame($activity->id, (int) data_get($dueNotification->data, 'ci_activity_id'));
            $this->assertCount(4, $creator->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('data-scheduled-notification-state="unread"', false)
                ->assertSee('10:00 AM');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reopened_activity_waits_for_an_explicit_new_schedule_before_notifying_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 06:00:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $updater = User::factory()->create();
            [$folder, $activity] = $this->folderWithActivities($creator);
            $activity->update([
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => now(),
                'scheduled_has_time' => true,
                'creator_id' => $creator->id,
            ]);
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            $oldNotification = $creator->notifications()->sole();

            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'completed',
            ])->assertRedirect();
            $activity->refresh();
            $this->assertSame(ActivityStatus::Completed, $activity->status);
            $this->assertNull($activity->scheduled_at);
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertDontSee('data-scheduled-today-count', false);
            $this->post(route('notifications.ci-activities.read', $oldNotification->id))->assertRedirect();

            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'expected_updated_at' => $activity->updated_at->toISOString(),
                'status' => 'pending',
            ])->assertRedirect();
            $activity->refresh();
            $this->assertSame(ActivityStatus::Pending, $activity->status);
            $this->assertNull($activity->scheduled_at);
            $this->assertNull($activity->reminder_sent_at);
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            $this->assertCount(1, $creator->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertDontSee('data-scheduled-today-count', false);

            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-30',
                'scheduled_time' => '',
            ])->assertRedirect();
            $activity->refresh();
            $rescheduledAfterReopen = $creator->notifications()->whereNull('read_at')->sole();
            $this->assertSame(CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED, data_get($rescheduledAfterReopen->data, 'purpose'));
            $this->assertFalse((bool) data_get($rescheduledAfterReopen->data, 'scheduled_has_time'));
            $this->assertSame($creator->id, $activity->creator_id);
            $this->assertFalse($activity->scheduled_has_time);
            $this->assertNull($activity->reminder_sent_at);
            $this->assertCount(2, $creator->notifications()->get());
            $this->assertCount(0, $updater->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false);
            $this->post(route('notifications.ci-activities.read', $rescheduledAfterReopen->id))->assertRedirect();

            Carbon::setTestNow(Carbon::parse('2026-08-29 23:59:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            $this->assertCount(2, $creator->notifications()->get());

            Carbon::setTestNow(Carbon::parse('2026-08-30 00:00:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            $newNotification = $creator->notifications()->whereNull('read_at')->sole();
            $this->assertNotSame($oldNotification->id, $newNotification->id);
            $this->assertSame(CiActivityScheduledReminder::PURPOSE_DUE_REMINDER, data_get($newNotification->data, 'purpose'));
            $this->assertFalse((bool) data_get($newNotification->data, 'scheduled_has_time'));
            $this->assertSame($creator->id, $activity->fresh()->creator_id);
            $this->assertCount(3, $creator->notifications()->get());
            $this->assertCount(0, $updater->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('Today')
                ->assertDontSee('8:00 AM');

            $this->actingAs($updater)->delete(route('client-folders.activities.destroy', [$folder, $activity]), [
                'co_maker_id' => null,
            ])->assertRedirect();
            $this->assertDatabaseMissing('ci_activities', ['id' => $activity->id]);
            $this->assertCount(3, $creator->notifications()->get());
            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertDontSee('data-scheduled-today-count', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_date_only_schedule_reminds_the_original_creator_at_eight_and_exact_time_remains_exact(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-29 23:59:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $updater = User::factory()->create();
            [$folder, $activity] = $this->folderWithActivities($creator);

            $this->actingAs($creator)->get(route('client-folders.activities.index', $folder))
                ->assertOk()
                ->assertSee('name="scheduled_at" type="date"', false)
                ->assertSee('name="scheduled_time" type="time"', false)
                ->assertSee('Without one, the creator is reminded at 8:00 AM', false);

            $this->actingAs($creator)->get(route('client-folders.activities.edit', [$folder, $activity]))
                ->assertOk()
                ->assertSee('name="scheduled_at" type="date"', false)
                ->assertSee('name="scheduled_time" type="time"', false)
                ->assertSee('Time <span class="font-normal text-text-muted">(optional)</span>', false);

            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-30',
                'scheduled_time' => '',
            ])->assertRedirect();

            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($activity, $creator): bool {
                $payload = $notification->toArray($creator);

                return $payload['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED
                    && $payload['scheduled_has_time'] === false
                    && $payload['message'] === $activity->name.' was scheduled.';
            });
            Notification::assertNotSentTo($updater, CiActivityScheduledReminder::class);

            $activity->refresh();
            $this->assertSame($creator->id, $activity->creator_id);
            $this->assertSame($updater->id, $activity->updated_by);
            $this->assertFalse($activity->scheduled_has_time);
            $this->assertSame('2026-08-30 00:00:00', $activity->scheduled_at->utc()->format('Y-m-d H:i:s'));
            $this->assertNull($activity->reminder_sent_at);

            Notification::fake();
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertNothingSent();

            Carbon::setTestNow(Carbon::parse('2026-08-30 00:00:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($activity, $creator): bool {
                $payload = $notification->toArray($creator);

                return $payload['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
                    && $payload['scheduled_has_time'] === false
                    && $payload['message'] === $activity->name.' is scheduled today.';
            });
            Notification::assertNotSentTo($updater, CiActivityScheduledReminder::class);

            Notification::fake();
            Carbon::setTestNow(Carbon::parse('2026-08-30 00:01:00', 'UTC'));
            $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $activity]), [
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-31',
                'scheduled_time' => '14:00',
            ])->assertRedirect();

            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($creator): bool {
                return $notification->toArray($creator)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED;
            });
            Notification::assertNotSentTo($updater, CiActivityScheduledReminder::class);

            $activity->refresh();
            $this->assertSame($creator->id, $activity->creator_id);
            $this->assertTrue($activity->scheduled_has_time);
            $this->assertSame('2026-08-31 06:00:00', $activity->scheduled_at->utc()->format('Y-m-d H:i:s'));
            $this->assertNull($activity->reminder_sent_at);

            Notification::fake();
            Carbon::setTestNow(Carbon::parse('2026-08-31 05:59:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertNothingSent();

            Carbon::setTestNow(Carbon::parse('2026-08-31 06:00:00', 'UTC'));
            $this->artisan('ci-activities:send-reminders')->assertSuccessful();
            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($activity, $creator): bool {
                $payload = $notification->toArray($creator);

                return $payload['purpose'] === CiActivityScheduledReminder::PURPOSE_DUE_REMINDER
                    && $payload['scheduled_has_time'] === true
                    && $payload['message'] === $activity->name.' is scheduled now.';
            });
            Notification::assertNotSentTo($updater, CiActivityScheduledReminder::class);

            $newDefinition = ActivityDefinition::factory()->create();
            $this->actingAs($creator)->post(route('client-folders.activities.store', $folder), [
                'activity_definition_id' => $newDefinition->id,
                'status' => 'scheduled',
                'scheduled_at' => '2026-09-01',
                'scheduled_time' => '',
            ])->assertRedirect();
            Notification::assertSentTo($creator, CiActivityScheduledReminder::class, function (CiActivityScheduledReminder $notification) use ($creator): bool {
                return $notification->toArray($creator)['purpose'] === CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED;
            });

            $createdActivity = CiActivity::query()->where('activity_definition_id', $newDefinition->id)->sole();
            $this->assertSame($creator->id, $createdActivity->creator_id);
            $this->assertFalse($createdActivity->scheduled_has_time);
            $this->assertSame('2026-09-01 00:00:00', $createdActivity->scheduled_at->utc()->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
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

        $this->assertLessThanOrEqual(11, $queryCount);
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
