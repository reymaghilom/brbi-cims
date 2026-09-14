<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Same-row optimistic concurrency for an individual Asset Check target.
 *
 * The parent CiActivity row lock already serialized every Asset target mutation, so parent-status
 * synchronization, same-parent/different-target ordering and edit-vs-delete integrity were never
 * the defect. What was missing is a way to tell a CURRENT save apart from a STALE one — these
 * tests pin that behaviour, plus the structural guarantees the lock provides, so neither can be
 * regressed silently.
 *
 * LIMITATION: these tests prove the LOGICAL ordering (the token, the revision arithmetic, the
 * side-effect suppression), not true simultaneous database locking. The suite runs on SQLite
 * :memory:, which serializes writes at the connection level and has no meaningful FOR UPDATE, so
 * genuine concurrent MySQL/InnoDB row-lock interleaving is NOT exercised here.
 */
class CiActivityAssetTargetConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const UNAVAILABLE = 'This Asset Check target is no longer available. It may have been deleted or changed by another user. Please return to the Asset Check page.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->assetDefinition();
    }

    public function test_the_edit_form_renders_the_token_and_the_add_form_does_not(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');

        $this->assertSame(1, $target->revision);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.asset-check.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="expected_revision" value="'.$target->revision.'"', $html);
        $this->assertSame(1, substr_count($html, 'name="expected_revision"'), 'Only the edit form carries the token.');
    }

    public function test_update_requires_the_token_and_a_successful_update_advances_it_exactly_once(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');

        // Omitting the token must not be a way around stale protection.
        $this->actingAs($ci)
            ->putJson($this->updateUrl($folder, $activity, $target), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_revision']);
        $this->assertSame('City Hall Assessor', $target->fresh()->office_location);
        $this->assertSame(1, $target->fresh()->revision);

        $this->put($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => 1,
            'office_location' => 'City Hall Annex',
        ]))->assertRedirect();

        $this->assertSame('City Hall Annex', $target->fresh()->office_location);
        $this->assertSame(2, $target->fresh()->revision);

        // Reloading the form and saving again continues the sequence.
        $this->put($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => 2,
            'office_location' => 'City Hall Third Floor',
        ]))->assertRedirect();
        $this->assertSame(3, $target->fresh()->revision);
        $this->assertSame(2, $this->auditsFor($target, 'ci_activity.asset_target_updated'));
    }

    public function test_a_stale_second_edit_is_refused_and_has_zero_side_effects(): void
    {
        Notification::fake();
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');

        // Both CIs open the edit form while the target is at revision 1.
        $openedRevision = $target->revision;

        $this->actingAs($ci)->put($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => $openedRevision,
            'office_location' => 'City Hall Annex',
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => '2026-09-20',
            'scheduled_time' => '10:30',
            'remarks' => 'Winner remarks.',
        ]))->assertRedirect();

        $afterWinner = $target->fresh();
        $this->assertSame(2, $afterWinner->revision);
        $auditsAfterWinner = AuditLog::query()->count();

        // The second CI saves the form it opened at revision 1. Same-second saves are protected
        // because the token is a counter, not a timestamp: updated_at is second-precision and
        // would have been identical here.
        $folder->update(['assigned_ci_id' => $other->id]);
        $this->actingAs($other)->put($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => $openedRevision,
            'assessor_type' => 'provincial_assessor',
            'office_location' => 'Stale Overwrite',
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => '2026-12-31',
            'scheduled_time' => '08:00',
            'remarks' => 'Stale remarks.',
        ]))->assertRedirect()->assertSessionHas('statusType', 'error');

        // Nothing the stale form carried reached the row.
        $stillWinner = $target->fresh();
        $this->assertSame('city_assessor', $stillWinner->assessor_type);
        $this->assertSame('City Hall Annex', $stillWinner->office_location);
        $this->assertSame(ActivityStatus::Scheduled, $stillWinner->status);
        $this->assertTrue($stillWinner->scheduled_at->equalTo($afterWinner->scheduled_at));
        $this->assertTrue($stillWinner->scheduled_has_time);
        $this->assertSame('Winner remarks.', $stillWinner->remarks);
        $this->assertSame($ci->id, $stillWinner->updated_by);
        $this->assertSame(2, $stillWinner->revision);

        // Zero audit, zero reminder, parent untouched.
        $this->assertSame($auditsAfterWinner, AuditLog::query()->count());
        Notification::assertSentToTimes($ci, CiActivityScheduledReminder::class, 1);
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
    }

    public function test_a_stale_edit_returns_409_conflict_for_json_without_technical_text(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');

        $this->actingAs($ci)->put($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => 1,
            'office_location' => 'City Hall Annex',
        ]))->assertRedirect();

        $response = $this->putJson($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => 1,
            'office_location' => 'Stale',
        ]))->assertStatus(409);

        $response->assertJsonPath('result', 'conflict');
        $response->assertJsonPath('status_type', 'error');
        $this->assertStringContainsString('updated by another user', $response->json('message'));
        $this->assertStringNotContainsString('App\Models', $response->json('message'));
    }

    public function test_completion_advances_the_revision_once_and_a_repeat_is_inert(): void
    {
        Notification::fake();
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');

        $this->actingAs($ci)
            ->patch($this->completeUrl($folder, $activity, $target), ['co_maker_id' => ''])
            ->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $target->fresh()->status);
        $this->assertSame(2, $target->fresh()->revision);
        $this->assertSame(1, $this->auditsFor($target, 'ci_activity.asset_target_completed'));

        // Repeating completion short-circuits: no second bump, no duplicate audit.
        $this->patch($this->completeUrl($folder, $activity, $target), ['co_maker_id' => ''])->assertRedirect();
        $this->assertSame(2, $target->fresh()->revision);
        $this->assertSame(1, $this->auditsFor($target, 'ci_activity.asset_target_completed'));
        Notification::assertNothingSent();
    }

    public function test_an_edit_form_opened_before_another_user_completes_the_target_is_refused(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');
        $openedRevision = $target->revision;

        $this->actingAs($ci)
            ->patch($this->completeUrl($folder, $activity, $target), ['co_maker_id' => ''])
            ->assertRedirect();

        $this->put($this->updateUrl($folder, $activity, $target), $this->payload([
            'expected_revision' => $openedRevision,
            'status' => ActivityStatus::Pending->value,
        ]))->assertRedirect()->assertSessionHas('statusType', 'error');

        // The Completed state was not silently reopened by the stale form.
        $this->assertSame(ActivityStatus::Completed, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    public function test_complete_many_advances_the_revision_of_every_target_it_actually_changes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $first = $this->createTarget($activity, $ci, 'City Hall Assessor');
        $second = $this->createTarget($activity, $ci, 'Provincial Capitol');

        $this->actingAs($ci)
            ->patch($this->completeUrl($folder, $activity, $first), ['co_maker_id' => ''])
            ->assertRedirect();
        $this->assertSame(2, $first->fresh()->revision);

        $this->patch(route('client-folders.activities.asset-targets.complete-many', [$folder, $activity]), [
            'co_maker_id' => '',
            'asset_target_ids' => [$first->id, $second->id],
        ])->assertRedirect();

        // The already-Completed one is untouched; only the one that actually changed advances.
        $this->assertSame(2, $first->fresh()->revision);
        $this->assertSame(2, $second->fresh()->revision);
        $this->assertSame(1, $this->auditsFor($first, 'ci_activity.asset_target_completed'));
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    public function test_stale_work_against_a_deleted_target_is_a_safe_friendly_not_found(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'City Hall Assessor');
        $keeper = $this->createTarget($activity, $ci, 'Provincial Capitol');
        $targetId = $target->id;

        $this->actingAs($ci)
            ->delete(route('client-folders.activities.asset-targets.destroy', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect();
        $auditsBefore = AuditLog::query()->count();

        // Stale update, stale delete and stale complete all end the same safe way.
        $update = $this->put($this->updateUrl($folder, $activity, $targetId), $this->payload([
            'expected_revision' => 1,
            'office_location' => 'Resurrected',
        ]))->assertNotFound();
        $this->delete(route('client-folders.activities.asset-targets.destroy', [$folder, $activity, $targetId]), ['co_maker_id' => ''])
            ->assertNotFound();
        $this->patch($this->completeUrl($folder, $activity, $targetId), ['co_maker_id' => ''])->assertNotFound();

        foreach ([$update->getContent()] as $body) {
            $this->assertStringContainsString(self::UNAVAILABLE, $body);
            $this->assertStringNotContainsString('No query results for model', $body);
            $this->assertStringNotContainsString('CiActivityAssetTarget', $body);
        }

        $json = $this->putJson($this->updateUrl($folder, $activity, $targetId), $this->payload(['expected_revision' => 1]))
            ->assertStatus(404);
        $json->assertJsonPath('result', 'not_available');
        $json->assertJsonPath('status_type', 'error');
        $json->assertJsonPath('message', self::UNAVAILABLE);

        // Nothing recreated, no side effect, parent still derived from what remains.
        $this->assertDatabaseMissing('ci_activity_asset_targets', ['id' => $targetId]);
        $this->assertDatabaseMissing('ci_activity_asset_targets', ['office_location' => 'Resurrected']);
        $this->assertSame($auditsBefore, AuditLog::query()->count());
        $this->assertSame(1, $activity->fresh()->assetTargets()->count());
        $this->assertSame($keeper->id, $activity->fresh()->assetTargets()->first()->id);
    }

    public function test_sequential_updates_to_different_targets_of_one_parent_derive_the_correct_status(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);
        $first = $this->createTarget($activity, $ci, 'City Hall Assessor');
        $second = $this->createTarget($activity, $ci, 'Provincial Capitol');

        $this->actingAs($ci)->put($this->updateUrl($folder, $activity, $first), $this->payload([
            'expected_revision' => $first->fresh()->revision,
            'office_location' => 'City Hall Assessor',
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => '2026-09-20',
        ]))->assertRedirect();
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);

        $this->put($this->updateUrl($folder, $activity, $second), $this->payload([
            'expected_revision' => $second->fresh()->revision,
            'office_location' => 'Provincial Capitol',
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => '2026-09-21',
        ]))->assertRedirect();
        $this->assertSame(ActivityStatus::FollowUp, $activity->fresh()->status);

        // Each sibling advanced only its own token.
        $this->assertSame(2, $first->fresh()->revision);
        $this->assertSame(2, $second->fresh()->revision);
    }

    public function test_the_token_does_not_weaken_target_parent_person_or_folder_isolation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $applicantActivity = $this->assetActivity($folder, $ci);
        $otherApplicantActivity = $this->assetActivity($folder, $ci);
        $coMakerActivity = $this->assetActivity($folder, $ci, $coMaker->id);
        $otherFolderActivity = $this->assetActivity($otherFolder, $ci);
        $target = $this->createTarget($applicantActivity, $ci, 'City Hall Assessor');

        $payload = $this->payload(['expected_revision' => 1, 'office_location' => 'Substitution']);

        // A correct token never substitutes for scope.
        $this->actingAs($ci)
            ->put($this->updateUrl($folder, $otherApplicantActivity, $target), $payload)
            ->assertNotFound();
        $this->put($this->updateUrl($folder, $coMakerActivity, $target), ['co_maker_id' => $coMaker->id] + $payload)
            ->assertNotFound();
        $this->put($this->updateUrl($otherFolder, $otherFolderActivity, $target), $payload)
            ->assertNotFound();

        $this->assertSame('City Hall Assessor', $target->fresh()->office_location);
        $this->assertSame(1, $target->fresh()->revision);
    }

    private function updateUrl(ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget|int $target): string
    {
        return route('client-folders.activities.asset-targets.update', [$folder, $activity, $target]);
    }

    private function completeUrl(ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget|int $target): string
    {
        return route('client-folders.activities.asset-targets.complete', [$folder, $activity, $target]);
    }

    private function auditsFor(CiActivityAssetTarget $target, string $action): int
    {
        return AuditLog::query()
            ->where('action', $action)
            ->get()
            ->filter(fn (AuditLog $log): bool => ($log->metadata['asset_target_id'] ?? null) === $target->id)
            ->count();
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'assessor_type' => 'city_assessor',
            'office_location' => 'City Hall Assessor',
            'status' => ActivityStatus::Pending->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => null,
        ];
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $name, 2);

        return CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    private function assetActivity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $this->assetDefinition()->id,
            'name' => 'Asset Check',
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createTarget(CiActivity $activity, User $actor, string $office): CiActivityAssetTarget
    {
        // Refreshed, because revision is filled by the column default: a just-created instance
        // would carry null in memory.
        return tap($activity->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => $office,
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]))->refresh();
    }

    private function assetDefinition(): ActivityDefinition
    {
        return ActivityDefinition::query()->updateOrCreate(
            ['code' => ActivityDefinition::ASSET_CHECK_CODE],
            ['name' => 'Asset Check', 'sort_order' => 36, 'is_required' => false, 'is_active' => true],
        );
    }
}
