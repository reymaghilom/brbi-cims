<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteCiActivity;
use App\Actions\ClientFolders\UpdateCiActivity;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Shared CI Activity edit + delete concurrency — Barangay Check, Neighbor Check and every other
 * ordinary activity that goes through UpdateCiActivity / DeleteCiActivity.
 *
 * UpdateCiActivity trusted the route-bound instance, never locked the row, and guarded with an
 * OPTIONAL expected_updated_at compared outside any lock — second-precision, so two saves in the
 * same second carried an identical token, and simply omitting the field skipped the guard entirely.
 * DeleteCiActivity likewise trusted its route-bound snapshot and gathered Supporting Proof before
 * any activity-row lock existed. Both now re-read the row under lockForUpdate(), scoped to the exact
 * folder and exact person, and the edit compares a monotonic `revision` under that lock.
 *
 * SQLite :memory: runs a single connection, so these tests cannot schedule two genuinely
 * overlapping transactions and do NOT prove MySQL/MariaDB InnoDB row-lock scheduling. They prove
 * the token logic and the outcome of each logical ordering. No live storage: Storage::fake only.
 */
class CiActivityConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        $this->ci = User::factory()->create();
    }

    public function test_the_edit_form_exposes_the_revision_and_no_timestamp_token(): void
    {
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->assertSame(1, $barangay->revision);

        $this->actingAs($this->ci)->get(route('client-folders.activities.edit', [$folder, $barangay]))
            ->assertOk()
            ->assertSee('name="expected_revision" value="1"', false)
            ->assertDontSee('name="expected_updated_at"', false);
    }

    public function test_an_update_without_a_revision_token_is_rejected(): void
    {
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($this->ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'remarks' => 'Attempt without a revision',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->assertSame(ActivityStatus::Pending, $barangay->fresh()->status);
    }

    /** Two edits inside one frozen second still advance the token — exactly what updated_at could not do. */
    public function test_two_edits_in_the_same_second_advance_the_revision_and_the_stale_one_conflicts(): void
    {
        Carbon::setTestNow('2026-09-13 10:15:00');
        $staleEditor = User::factory()->create();
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $originalTimestamp = $barangay->updated_at->copy();

        $this->update($this->ci, $folder, $barangay, 1, ['remarks' => 'Authoritative newer value'])->assertRedirect();
        $barangay->refresh();
        $this->assertSame(2, $barangay->revision);
        $this->assertTrue($originalTimestamp->equalTo($barangay->updated_at), 'The timestamp token would still be identical here.');
        $updateAudits = $this->auditCount($folder, 'ci_activity.completed');

        $response = $this->update($staleEditor, $folder, $barangay, 1, [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toDateString(),
            'remarks' => 'Stale overwrite attempt',
        ], json: true);

        $response->assertStatus(409)->assertJson([
            'result' => 'conflict',
            'status_type' => 'error',
            'message' => 'This CI Activity was updated by another user while you were working on it. Please review the latest information before saving again.',
        ]);

        $barangay->refresh();
        $this->assertSame('Authoritative newer value', $barangay->remarks);
        $this->assertSame(ActivityStatus::Completed, $barangay->status);
        $this->assertNull($barangay->scheduled_at);
        $this->assertSame(2, $barangay->revision, 'A refused save must not advance the token.');
        $this->assertSame($this->ci->id, $barangay->updated_by);
        $this->assertSame($updateAudits, $this->auditCount($folder, 'ci_activity.completed'));
        $this->assertSame(0, $this->auditCount($folder, 'ci_activity.scheduled'));
    }

    public function test_a_successful_update_advances_the_revision_exactly_once(): void
    {
        $folder = $this->folder();
        $neighbor = $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $this->update($this->ci, $folder, $neighbor, 1, ['remarks' => 'First'])->assertRedirect();
        $this->assertSame(2, $neighbor->fresh()->revision);
        $this->update($this->ci, $folder, $neighbor, 2, ['remarks' => 'Second'])->assertRedirect();
        $this->assertSame(3, $neighbor->fresh()->revision);
    }

    /** Barangay, Neighbor and an ordinary custom activity all travel the same protected path. */
    public function test_barangay_neighbor_and_a_custom_activity_all_use_the_same_guard(): void
    {
        $folder = $this->folder();
        $custom = ActivityDefinition::query()->create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'employment_check',
            'name' => 'Employment Check',
            'sort_order' => 900,
            'is_required' => false,
            'is_active' => true,
        ]);

        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE, $custom->code] as $code) {
            $activity = $this->activity($folder, $code);

            $this->update($this->ci, $folder, $activity, 1, ['remarks' => 'Saved '.$code])->assertRedirect();
            $this->assertSame(2, $activity->fresh()->revision);

            $this->update($this->ci, $folder, $activity, 1, ['remarks' => 'Stale '.$code], json: true)
                ->assertStatus(409)->assertJson(['result' => 'conflict']);
            $this->assertSame('Saved '.$code, $activity->fresh()->remarks);
        }
    }

    /** The locked lookup keeps the exact person and folder scope. */
    public function test_exact_person_and_folder_isolation_is_preserved(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicant = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $checkA = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $coMakerA);
        $checkB = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $coMakerB);
        $otherApplicant = $this->activity($otherFolder, ActivityDefinition::BARANGAY_CHECK_CODE);

        // Each attempt names a person that does not own the targeted row, or a foreign folder.
        $attempts = [
            [$folder, $applicant, $coMakerA],
            [$folder, $checkA, null],
            [$folder, $checkA, $coMakerB],
            [$otherFolder, $applicant, null],
        ];

        foreach ($attempts as [$routeFolder, $activity, $person]) {
            $response = $this->actingAs($this->ci)->putJson(route('client-folders.activities.update', [$routeFolder, $activity]), [
                'co_maker_id' => $person?->id ?? '',
                'expected_revision' => 1,
                'status' => 'completed',
                'remarks' => 'Cross-scope overwrite attempt',
            ]);
            // 403 from the request's own authorize(), or 404 from the scoped route binding —
            // which one applies depends on the attempt; both refuse without touching data.
            $this->assertContains($response->status(), [403, 404]);
        }

        foreach ([$applicant, $checkA, $checkB, $otherApplicant] as $untouched) {
            $this->assertNull($untouched->fresh()->remarks);
            $this->assertSame(1, $untouched->fresh()->revision);
        }
    }

    /** The dashboard quick-complete posts to the same endpoint and must carry the new token. */
    public function test_the_dashboard_quick_complete_hook_uses_the_revision_and_still_completes(): void
    {
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        // The quick-complete hook itself: asserted on the partial that renders it, because whether
        // a given folder surfaces a Work Today item depends on unrelated dashboard fixtures.
        $partial = file_get_contents(resource_path('views/dashboard/_work-today-action.blade.php'));
        $this->assertStringContainsString('data-dashboard-completion-expected-revision', $partial);
        $this->assertStringNotContainsString('data-dashboard-completion-expected-updated-at', $partial);
        $this->assertStringNotContainsString('expected_updated_at', file_get_contents(resource_path('js/app.js')));

        // Exactly what the quick-complete fetch sends.
        $this->actingAs($this->ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'expected_revision' => 1,
            'status' => 'completed',
            'intent' => 'return',
        ])->assertOk();

        $this->assertSame(ActivityStatus::Completed, $barangay->fresh()->status);
        $this->assertSame(2, $barangay->fresh()->revision);
    }

    /** A schedule reminder must only reach a CI for a change that actually committed. */
    public function test_only_a_winning_schedule_change_notifies_and_a_stale_one_never_does(): void
    {
        Notification::fake();
        $staleEditor = User::factory()->create();
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->update($this->ci, $folder, $barangay, 1, [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(2)->toDateString(),
        ])->assertRedirect();
        Notification::assertSentToTimes($this->ci, CiActivityScheduledReminder::class, 1);

        // A stale reschedule: refused, so nobody is notified about a change that never happened.
        $this->update($staleEditor, $folder, $barangay, 1, [
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(9)->toDateString(),
        ], json: true)->assertStatus(409);

        Notification::assertSentToTimes($this->ci, CiActivityScheduledReminder::class, 1);
        $this->assertTrue(now()->addDays(2)->isSameDay($barangay->fresh()->scheduled_at));
    }

    // ==================================================
    // DELETE
    // ==================================================

    /** Ordering A — UPDATE wins: the delete acts on the authoritative latest state. */
    public function test_a_delete_holding_a_stale_instance_removes_the_authoritative_updated_record(): void
    {
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $staleInstance = CiActivity::query()->findOrFail($barangay->id);

        $this->update($this->ci, $folder, $barangay, 1, ['status' => 'completed', 'remarks' => 'Completed before the delete'])->assertRedirect();
        $this->assertSame(ActivityStatus::Completed, $barangay->fresh()->status);

        app(DeleteCiActivity::class)->execute($this->ci, $folder, $staleInstance);

        $this->assertDatabaseMissing('ci_activities', ['id' => $barangay->id]);
        // Audit metadata is read from the locked row, so it describes the state actually removed —
        // not the Pending snapshot the delete was handed.
        $audit = AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'ci_activity.deleted')->sole();
        $this->assertSame(ActivityStatus::Completed->value, $audit->metadata['status']);
    }

    /** Ordering B — DELETE wins: the stale editor cannot update or resurrect the row. */
    public function test_a_stale_update_after_a_completed_delete_fails_safely(): void
    {
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $staleInstance = CiActivity::query()->findOrFail($barangay->id);

        $this->actingAs($this->ci)->delete(route('client-folders.activities.destroy', [$folder, $barangay]))->assertRedirect();
        $this->assertDatabaseMissing('ci_activities', ['id' => $barangay->id]);
        $updateAudits = $this->auditCount($folder, 'ci_activity.updated');

        // Through HTTP the route binding itself refuses the deleted row.
        $this->update($this->ci, $folder, $barangay, 1, ['remarks' => 'Saved from a page opened before the delete'], json: true)
            ->assertNotFound();

        // And the action's own locked lookup refuses it independently of route binding.
        try {
            app(UpdateCiActivity::class)->execute($this->ci, $folder, $staleInstance, [
                'co_maker_id' => null, 'expected_revision' => 1, 'status' => 'completed',
            ]);
            $this->fail('A stale update must not resolve a deleted activity.');
        } catch (ModelNotFoundException) {
            // Expected.
        }

        $this->assertSame(0, CiActivity::query()->count());
        $this->assertSame($updateAudits, $this->auditCount($folder, 'ci_activity.updated'));
    }

    /** Ordering C — two deletes: one success, one audit, one cleanup. */
    public function test_a_second_delete_creates_no_second_delete_audit(): void
    {
        $secondCi = User::factory()->create();
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $staleInstance = CiActivity::query()->findOrFail($barangay->id);

        app(DeleteCiActivity::class)->execute($this->ci, $folder, $barangay);
        $this->assertSame(1, $this->auditCount($folder, 'ci_activity.deleted'));

        try {
            app(DeleteCiActivity::class)->execute($secondCi, $folder, $staleInstance);
            $this->fail('The second delete must not report success for an already-deleted activity.');
        } catch (ModelNotFoundException) {
            // Same outcome the route binding produces once the row is gone.
        }

        $audits = AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'ci_activity.deleted')->get();
        $this->assertCount(1, $audits);
        $this->assertSame($this->ci->id, $audits->sole()->user_id, 'Attribution stays with the CI who actually deleted it.');
    }

    /** Delete is scoped to the exact row and the exact person. */
    public function test_delete_stays_exact_row_person_and_folder_scoped(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);

        $applicantBarangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $applicantNeighbor = $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $coMakerBarangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $coMaker);
        $otherFolderBarangay = $this->activity($otherFolder, ActivityDefinition::BARANGAY_CHECK_CODE);

        foreach ([[$otherFolder, $applicantBarangay], [$folder, $otherFolderBarangay]] as [$routeFolder, $activity]) {
            try {
                app(DeleteCiActivity::class)->execute($this->ci, $routeFolder, CiActivity::query()->findOrFail($activity->id));
                $this->fail('A cross-folder delete must never resolve a row.');
            } catch (ModelNotFoundException) {
                // Expected.
            }
        }

        // Deleting the Applicant's Barangay Check leaves every other exact row alone.
        app(DeleteCiActivity::class)->execute($this->ci, $folder, $applicantBarangay);

        $this->assertDatabaseMissing('ci_activities', ['id' => $applicantBarangay->id]);
        foreach ([$applicantNeighbor, $coMakerBarangay, $otherFolderBarangay] as $survivor) {
            $this->assertDatabaseHas('ci_activities', ['id' => $survivor->id]);
        }
    }

    /** Deleting frees the slot: the same person may add the built-in check again, and the new adder is its creator. */
    public function test_barangay_and_neighbor_can_be_re_added_after_delete(): void
    {
        $secondCi = User::factory()->create();
        $folder = $this->folder();
        $barangay = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        app(DeleteCiActivity::class)->execute($this->ci, $folder, $barangay);
        app(DeleteCiActivity::class)->execute($this->ci, $folder, $neighbor);
        // Both mandatory rows are gone and no fake Pending row was synthesised in their place.
        $this->assertSame(0, $folder->activities()->count());

        $replacement = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, null, $secondCi);

        $this->assertNotSame($barangay->id, $replacement->id);
        $this->assertSame($secondCi->id, $replacement->creator_id, 'The new adder becomes the creator.');
        $this->assertSame(1, $replacement->revision);
        $this->assertSame(1, $folder->activities()->whereNull('co_maker_id')
            ->where('activity_definition_id', $replacement->activity_definition_id)->count());
    }

    private function auditCount(ClientFolder $folder, string $action): int
    {
        return AuditLog::query()->where('client_folder_id', $folder->id)->where('action', $action)->count();
    }

    private function update(User $actor, ClientFolder $folder, CiActivity $activity, int $revision, array $overrides = [], bool $json = false)
    {
        $payload = array_merge([
            'co_maker_id' => $activity->co_maker_id ?? '',
            'expected_revision' => $revision,
            'status' => 'completed',
            'intent' => 'stay',
        ], $overrides);

        $route = route('client-folders.activities.update', [$folder, $activity]);

        return $json
            ? $this->actingAs($actor)->putJson($route, $payload)
            : $this->actingAs($actor)->put($route, $payload);
    }

    private function activity(ClientFolder $folder, string $code, ?CoMaker $coMaker = null, ?User $creator = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->firstOrFail();

        $activity = $folder->activities()->create([
            'co_maker_id' => $coMaker?->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => ($creator ?? $this->ci)->id,
            'updated_by' => ($creator ?? $this->ci)->id,
        ]);

        // Eloquent does not backfill a column default into the created instance.
        return $activity->fresh();
    }

    private function folder(): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $this->ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
