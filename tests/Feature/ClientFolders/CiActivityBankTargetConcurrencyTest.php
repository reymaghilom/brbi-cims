<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Same-row optimistic concurrency for an individual Bank / Coop target.
 *
 * The parent CiActivity row lock already serializes every target mutation, so parent-status
 * synchronization, same-parent/different-target ordering and edit-vs-delete integrity were never
 * the defect. What was missing is a way to tell a CURRENT save apart from a STALE one — these
 * tests pin that behaviour, plus the structural guarantees the lock provides, so neither can be
 * regressed silently.
 *
 * LIMITATION: these tests prove the LOGICAL ordering (the token, the revision arithmetic, the
 * side-effect suppression), not true simultaneous database locking. The suite runs on SQLite
 * :memory:, which serializes writes at the connection level and has no meaningful FOR UPDATE, so
 * genuine concurrent MySQL/InnoDB row-lock behaviour is NOT exercised here. Real interleaving on
 * the development MySQL is deliberately out of scope for an automated test run.
 */
class CiActivityBankTargetConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->bankDefinition();
    }

    public function test_edit_form_renders_the_revision_token_and_the_add_form_does_not(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $editForm = $this->formHtml($html, 'data-bank-target-edit-form="'.$target->id.'"');
        $this->assertStringContainsString('name="expected_revision" value="'.$target->revision.'"', $editForm);

        // The Add form creates a brand new row, so it carries no edit token at all.
        $addForm = $this->formHtml($html, route('client-folders.activities.bank-targets.store', [$folder, $activity]).'"');
        $this->assertStringNotContainsString('expected_revision', $addForm);
    }

    public function test_update_requires_the_revision_token_and_a_successful_update_advances_it_exactly_once(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);
        $this->assertSame(1, $target->revision);

        // Omitting the token must not be a way around stale protection.
        $this->actingAs($ci)
            ->putJson(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_revision']);
        $this->assertSame('BDO Divisoria', $target->fresh()->institution_name);
        $this->assertSame(1, $target->fresh()->revision);

        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'BDO Carmen']),
        )->assertRedirect();

        $this->assertSame('BDO Carmen', $target->fresh()->institution_name);
        $this->assertSame(2, $target->fresh()->revision);
    }

    public function test_a_stale_second_edit_is_refused_and_changes_nothing(): void
    {
        Notification::fake();
        $ci = User::factory()->create();
        $other = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);

        // Both CIs open the edit form while the target is at revision 1.
        $openedRevision = $target->revision;

        // The first save wins.
        $this->actingAs($ci)->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload([
                'expected_revision' => $openedRevision,
                'institution_name' => 'BDO Carmen',
                'branch_location' => 'Carmen Branch',
                'status' => ActivityStatus::Scheduled->value,
                'scheduled_at' => '2026-09-20',
                'scheduled_time' => '10:30',
                'remarks' => 'Winner remarks.',
            ]),
        )->assertRedirect();

        $afterWinner = $target->fresh();
        $this->assertSame(2, $afterWinner->revision);
        $this->assertSame($ci->id, $afterWinner->updated_by);

        // The second CI saves the form it opened at revision 1. Same-second saves are protected
        // because the token is a counter, not a timestamp: updated_at is second-precision and
        // would have been identical here.
        $folder->update(['assigned_ci_id' => $other->id]);
        $this->actingAs($other)->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload([
                'expected_revision' => $openedRevision,
                'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
                'institution_name' => 'Stale Overwrite',
                'branch_location' => 'Stale Branch',
                'status' => ActivityStatus::FollowUp->value,
                'scheduled_at' => '2026-12-31',
                'scheduled_time' => '08:00',
                'remarks' => 'Stale remarks.',
            ]),
        )->assertRedirect()->assertSessionHas('statusType', 'error');

        // Nothing the stale form carried reached the row.
        $stillWinner = $target->fresh();
        $this->assertSame(CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, $stillWinner->inquiry_type);
        $this->assertSame('BDO Carmen', $stillWinner->institution_name);
        $this->assertSame('Carmen Branch', $stillWinner->branch_location);
        $this->assertSame(ActivityStatus::Scheduled, $stillWinner->status);
        $this->assertTrue($stillWinner->scheduled_at->equalTo($afterWinner->scheduled_at));
        $this->assertTrue($stillWinner->scheduled_has_time);
        $this->assertSame('Winner remarks.', $stillWinner->remarks);
        $this->assertSame($ci->id, $stillWinner->updated_by);
        $this->assertSame(2, $stillWinner->revision);

        // Parent status still reflects the winning edit, and the refused save sent no reminder:
        // exactly one notification exists, the winner's.
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        Notification::assertSentToTimes($ci, CiActivityScheduledReminder::class, 1);
    }

    public function test_a_stale_edit_returns_409_conflict_for_json_without_technical_text(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);

        $this->actingAs($ci)->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'BDO Carmen']),
        )->assertRedirect();

        $response = $this->putJson(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'Stale']),
        )->assertStatus(409);

        $response->assertJsonPath('result', 'conflict');
        $response->assertJsonPath('status_type', 'error');
        $this->assertStringContainsString('updated by another user', $response->json('message'));
        $this->assertStringNotContainsString('App\Models', $response->json('message'));
    }

    public function test_completion_advances_the_revision_once_and_a_repeat_is_inert(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);

        $this->actingAs($ci)
            ->patch(route('client-folders.activities.bank-targets.complete', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect();

        $this->assertSame(ActivityStatus::Completed, $target->fresh()->status);
        $this->assertSame(2, $target->fresh()->revision);
        $this->assertSame(1, $this->completionAudits($target));

        // Repeating completion short-circuits: no second revision bump, no duplicate audit.
        $this->patch(route('client-folders.activities.bank-targets.complete', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect();
        $this->assertSame(2, $target->fresh()->revision);
        $this->assertSame(1, $this->completionAudits($target));
    }

    public function test_an_edit_form_opened_before_another_user_completes_the_target_is_refused(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);
        $openedRevision = $target->revision;

        $this->actingAs($ci)
            ->patch(route('client-folders.activities.bank-targets.complete', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect();

        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => $openedRevision, 'status' => ActivityStatus::Pending->value]),
        )->assertRedirect()->assertSessionHas('statusType', 'error');

        // The Completed state was not silently reopened by the stale form.
        $this->assertSame(ActivityStatus::Completed, $target->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    public function test_complete_many_advances_the_revision_of_every_target_it_actually_changes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $first = $this->createTarget($activity, $ci, 'First Bank', ActivityStatus::Pending);
        $second = $this->createTarget($activity, $ci, 'Second Bank', ActivityStatus::Pending);

        $this->actingAs($ci)
            ->patch(route('client-folders.activities.bank-targets.complete', [$folder, $activity, $first]), ['co_maker_id' => ''])
            ->assertRedirect();
        $this->assertSame(2, $first->fresh()->revision);

        $this->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $activity]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$first->id, $second->id],
        ])->assertRedirect();

        // The already-Completed one is untouched; only the one that actually changed advances.
        $this->assertSame(2, $first->fresh()->revision);
        $this->assertSame(2, $second->fresh()->revision);
        $this->assertSame(1, $this->completionAudits($first));
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    public function test_delete_removes_the_latest_authoritative_row_and_a_stale_edit_cannot_recreate_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO Divisoria', ActivityStatus::Pending);
        $keeper = $this->createTarget($activity, $ci, 'Keeper Bank', ActivityStatus::Scheduled);
        $openedRevision = $target->revision;

        // An edit lands first; the delete that follows removes the UPDATED row, not the snapshot.
        $this->actingAs($ci)->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => $openedRevision, 'institution_name' => 'BDO Carmen']),
        )->assertRedirect();

        $this->delete(route('client-folders.activities.bank-targets.destroy', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect();
        $this->assertDatabaseMissing('ci_activity_bank_targets', ['id' => $target->id]);

        // A second delete of the same row fails as not-found, with no second parent sync.
        $this->delete(route('client-folders.activities.bank-targets.destroy', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertNotFound();

        // A stale edit form for the deleted row cannot resurrect it.
        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => $openedRevision, 'institution_name' => 'Resurrected']),
        )->assertNotFound();
        $this->assertDatabaseMissing('ci_activity_bank_targets', ['id' => $target->id]);
        $this->assertDatabaseMissing('ci_activity_bank_targets', ['institution_name' => 'Resurrected']);

        // Parent status is derived from what actually remains.
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        $this->assertSame(1, $activity->fresh()->bankTargets()->count());
        $this->assertSame($keeper->id, $activity->fresh()->bankTargets()->first()->id);
    }

    public function test_sequential_updates_to_different_targets_of_one_parent_derive_the_correct_final_status(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $targetA = $this->createTarget($activity, $ci, 'Alpha Bank', ActivityStatus::Pending);
        $targetB = $this->createTarget($activity, $ci, 'Beta Bank', ActivityStatus::Pending);

        // A then B.
        $this->actingAs($ci)->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $targetA]),
            $this->payload(['expected_revision' => $targetA->fresh()->revision, 'institution_name' => 'Alpha Bank', 'status' => ActivityStatus::Scheduled->value, 'scheduled_at' => '2026-09-20']),
        )->assertRedirect();
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);

        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $targetB]),
            $this->payload(['expected_revision' => $targetB->fresh()->revision, 'institution_name' => 'Beta Bank', 'status' => ActivityStatus::FollowUp->value, 'scheduled_at' => '2026-09-21']),
        )->assertRedirect();
        $this->assertSame(ActivityStatus::FollowUp, $activity->fresh()->status);

        // Reversed logical ordering on a second parent reaches the same derived answer, so the
        // final parent status does not depend on which target was saved last.
        $other = $this->bankActivity($folder, $ci);
        $otherA = $this->createTarget($other, $ci, 'Alpha Bank', ActivityStatus::Pending);
        $otherB = $this->createTarget($other, $ci, 'Beta Bank', ActivityStatus::Pending);

        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $other, $otherB]),
            $this->payload(['expected_revision' => $otherB->fresh()->revision, 'institution_name' => 'Beta Bank', 'status' => ActivityStatus::FollowUp->value, 'scheduled_at' => '2026-09-21']),
        )->assertRedirect();
        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $other, $otherA]),
            $this->payload(['expected_revision' => $otherA->fresh()->revision, 'institution_name' => 'Alpha Bank', 'status' => ActivityStatus::Scheduled->value, 'scheduled_at' => '2026-09-20']),
        )->assertRedirect();
        $this->assertSame(ActivityStatus::FollowUp, $other->fresh()->status);

        // Completing every target of a parent is the only path to a Completed parent.
        $this->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $other]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$otherA->id, $otherB->id],
        ])->assertRedirect();
        $this->assertSame(ActivityStatus::Completed, $other->fresh()->status);
    }

    public function test_the_revision_token_does_not_weaken_target_parent_person_or_folder_isolation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $applicantActivity = $this->bankActivity($folder, $ci);
        $otherApplicantActivity = $this->bankActivity($folder, $ci);
        $coMakerActivity = $this->bankActivity($folder, $ci, $coMaker->id);
        $otherFolderActivity = $this->bankActivity($otherFolder, $ci);
        $target = $this->createTarget($applicantActivity, $ci, 'Applicant Bank', ActivityStatus::Pending);

        $payload = $this->payload(['expected_revision' => 1, 'institution_name' => 'Substitution']);

        // A correct token never substitutes for scope: wrong parent, wrong person and wrong folder
        // all stay 404 regardless of the revision being right.
        $this->actingAs($ci)
            ->put(route('client-folders.activities.bank-targets.update', [$folder, $otherApplicantActivity, $target]), $payload)
            ->assertNotFound();
        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $coMakerActivity, $target]), ['co_maker_id' => $coMaker->id] + $payload)
            ->assertNotFound();
        $this->put(route('client-folders.activities.bank-targets.update', [$otherFolder, $otherFolderActivity, $target]), $payload)
            ->assertNotFound();

        $this->assertSame('Applicant Bank', $target->fresh()->institution_name);
        $this->assertSame(1, $target->fresh()->revision);
    }

    public function test_create_needs_no_edit_token_and_reaches_the_duplicate_warning_before_a_second_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $payload = [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO Divisoria',
            'branch_location' => 'Divisoria Branch',
            'status' => ActivityStatus::Pending->value,
        ];

        // No edit token is required to create. Adding the same institution/branch twice is an
        // advisory warning rather than a second row; only an explicit Continue Anyway creates it.
        // The duplicate rules themselves are covered by CiActivityBankTargetDuplicateWarningTest.
        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $payload)
            ->assertRedirect();
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $payload)
            ->assertRedirect()
            ->assertSessionHas('bank_target_duplicate');
        $this->assertSame(1, $activity->bankTargets()->count());

        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $payload + ['allow_duplicate' => '1'])
            ->assertRedirect();

        $this->assertSame(2, $activity->bankTargets()->where('institution_name', 'BDO Divisoria')->count());
        foreach ($activity->bankTargets as $created) {
            $this->assertSame(1, $created->revision);
        }
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
    }

    private function completionAudits(CiActivityBankTarget $target): int
    {
        return AuditLog::query()
            ->where('action', 'ci_activity.bank_target_completed')
            ->get()
            ->filter(fn (AuditLog $log): bool => ($log->metadata['bank_target_id'] ?? null) === $target->id)
            ->count();
    }

    private function formHtml(string $html, string $needle): string
    {
        $position = strpos($html, $needle);
        $this->assertNotFalse($position, 'Expected to find a form containing: '.$needle);
        $start = strrpos(substr($html, 0, $position), '<form');
        $end = strpos($html, '</form>', $position);

        return substr($html, $start, $end - $start);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO Divisoria',
            'branch_location' => null,
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

    private function bankActivity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $this->bankDefinition()->id,
            'name' => 'Bank / Coop Check',
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createTarget(CiActivity $activity, User $actor, string $name, ActivityStatus $status): CiActivityBankTarget
    {
        // Refreshed, because revision is filled by the column default: a just-created instance
        // would carry null in memory and every token assertion below reads it as the CI would,
        // from the stored row.
        return tap($activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $name,
            'status' => $status,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]))->refresh();
    }

    private function bankDefinition(): ActivityDefinition
    {
        return ActivityDefinition::query()->updateOrCreate(
            ['code' => ActivityDefinition::BANK_COOP_CHECK_CODE],
            ['name' => 'Bank / Coop Check', 'sort_order' => 35, 'is_required' => false, 'is_active' => true],
        );
    }
}
