<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceCheck;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Residence Check delete vs edit / delete vs delete race safety.
 *
 * DeleteResidenceCheck used to act on the route-bound model instance — a snapshot taken before its
 * transaction opened — without ever re-reading or locking the authoritative row. Two overlapping
 * deletes therefore each believed they had deleted the check: each wrote its own
 * residence_check.deleted audit event and each retired the same Cloudinary assets. It now re-reads
 * the row under lockForUpdate(), scoped to the exact folder + exact person + exact id, before any
 * photo snapshot, delete, audit write or progress recalculation.
 *
 * SQLite :memory: runs one connection, so these tests cannot schedule two genuinely overlapping
 * transactions and do NOT prove MySQL/MariaDB InnoDB row-lock behavior. What they do prove is the
 * outcome of each logical ordering, and specifically that a second actor holding a stale instance
 * can no longer produce a second successful delete or resurrect a deleted check.
 */
class ResidenceCheckDeleteConcurrencyTest extends TestCase
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

    /** Ordering A — DELETE wins: the record is gone and the CI who still had it open cannot bring it back. */
    public function test_a_stale_edit_after_a_completed_delete_cannot_update_or_recreate_the_check(): void
    {
        $staleEditor = User::factory()->create();
        $folder = $this->folder();
        $check = $this->createCheck($folder);
        // Exactly what the still-open edit page holds: a valid, current revision token.
        $staleRevision = $check->revision;

        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $check))->assertRedirect();
        $this->assertModelMissing($check);
        $auditsAfterDelete = $this->auditCount($folder, 'residence_check.updated');

        $response = $this->actingAs($staleEditor)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'expected_revision' => $staleRevision,
            'ci_date' => now()->toDateString(),
            'location' => 'Stale editor location',
            'remarks' => 'Saved from a page opened before the delete',
            'photos' => [UploadedFile::fake()->image('Stale.jpg', 900, 700)->size(500)],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        // Refused by SaveResidenceCheckRequest's own exists rule on check_id, before the action is
        // ever reached — the dedicated deleted-record response, never a success and never the
        // "updated by another user" conflict wording, which would wrongly imply the record still
        // exists to reload. Nothing was recreated and no photo was uploaded on the way.
        $response->assertNotFound()->assertJson([
            'result' => 'deleted',
            'message' => 'This Residence Check was deleted by another user while you were working on it. Please return to the Residence & Business Check page.',
            'residence_check_id' => $check->id,
        ]);
        $this->assertSame(0, ResidenceCheck::query()->count());
        $this->assertDatabaseCount('residence_check_photos', 0);
        $this->assertSame($auditsAfterDelete, $this->auditCount($folder, 'residence_check.updated'));

        // Second line of defense, independent of that validation rule: the action's own locked
        // lookup refuses a deleted row outright, so no caller can resurrect one.
        $this->expectException(ModelNotFoundException::class);
        app(SaveResidenceCheck::class)->execute($staleEditor, $folder, [
            'check_id' => $check->id,
            'expected_revision' => $staleRevision,
            'location' => 'Stale editor location',
            'remarks' => 'Saved from a page opened before the delete',
        ]);
    }

    /** Ordering B — EDIT wins: the delete then operates on the authoritative updated record, not the stale snapshot it was handed. */
    public function test_a_delete_holding_a_stale_instance_removes_the_authoritative_updated_record(): void
    {
        $folder = $this->folder();
        $check = $this->createCheck($folder);
        // Captured before the edit, exactly as a delete request whose route binding resolved first
        // would still be holding it.
        $staleInstance = ResidenceCheck::query()->findOrFail($check->id);

        $this->actingAs($this->ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'expected_revision' => $check->revision,
            'ci_date' => $check->ci_date->toDateString(),
            'location' => $check->location,
            'remarks' => 'Edited while a delete was in flight',
            'photos' => [UploadedFile::fake()->image('AddedByEdit.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $check->fresh()->photos()->count());
        $this->assertSame('Edited while a delete was in flight', $staleInstance->fresh()->remarks);

        app(DeleteResidenceCheck::class)->execute($this->ci, $folder, $staleInstance);

        // The photo the edit added is gone too — the cleanup list is read from the locked row, not
        // from the snapshot the delete was handed.
        $this->assertModelMissing($check);
        $this->assertDatabaseCount('residence_check_photos', 0);
        $this->assertSame(1, $this->auditCount($folder, 'residence_check.deleted'));
    }

    /** A second delete acting on its own stale instance writes no second success: no audit event, no repeated cleanup. */
    public function test_a_second_delete_of_the_same_check_creates_no_second_delete_audit_event(): void
    {
        $secondCi = User::factory()->create();
        $folder = $this->folder();
        $check = $this->createCheck($folder);
        $staleInstance = ResidenceCheck::query()->findOrFail($check->id);

        app(DeleteResidenceCheck::class)->execute($this->ci, $folder, $check);
        $this->assertSame(1, $this->auditCount($folder, 'residence_check.deleted'));

        try {
            app(DeleteResidenceCheck::class)->execute($secondCi, $folder, $staleInstance);
            $this->fail('The second delete must not report success for an already-deleted check.');
        } catch (ModelNotFoundException) {
            // Same outcome the route binding itself produces once the row is gone.
        }

        $audits = AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'residence_check.deleted')->get();
        $this->assertCount(1, $audits);
        // Attribution stays with the CI who actually deleted it.
        $this->assertSame($this->ci->id, $audits->sole()->user_id);
    }

    /** The locked re-read keeps the exact-person scope: a stale instance can never be used to reach another person's or another folder's check. */
    public function test_the_locked_delete_lookup_stays_exact_person_and_exact_folder_scoped(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $applicant = $this->createCheck($folder);
        $checkA = $this->createCheck($folder, $coMakerA);
        $checkB = $this->createCheck($folder, $coMakerB);
        $otherApplicant = $this->createCheck($otherFolder);

        // Each attempt hands the action a check that does not belong to the folder it is run
        // against, or whose person differs from the row being targeted.
        $crossFolder = ResidenceCheck::query()->findOrFail($otherApplicant->id);
        $this->assertDeleteRejected($folder, $crossFolder);

        $coMakerRowRunAgainstOtherFolder = ResidenceCheck::query()->findOrFail($checkA->id);
        $this->assertDeleteRejected($otherFolder, $coMakerRowRunAgainstOtherFolder);

        $this->assertModelExists($applicant);
        $this->assertModelExists($checkA);
        $this->assertModelExists($checkB);
        $this->assertModelExists($otherApplicant);
        $this->assertSame(0, $this->auditCount($folder, 'residence_check.deleted'));
    }

    /** Deleting frees the one-per-person slot again — no duplicate-create state survives the delete. */
    public function test_the_same_person_can_add_a_new_residence_check_after_a_delete(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $applicant = $this->createCheck($folder);
        $coMakerCheck = $this->createCheck($folder, $coMaker);

        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $applicant))->assertRedirect();
        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $coMakerCheck, $coMaker))->assertRedirect();

        $replacementApplicant = $this->createCheck($folder);
        $replacementCoMaker = $this->createCheck($folder, $coMaker);

        $this->assertNotSame($applicant->id, $replacementApplicant->id);
        $this->assertNotSame($coMakerCheck->id, $replacementCoMaker->id);
        $this->assertSame([$replacementApplicant->id], $folder->residenceChecks()->whereNull('co_maker_id')->pluck('id')->all());
        $this->assertSame([$replacementCoMaker->id], $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->pluck('id')->all());
    }

    private function assertDeleteRejected(ClientFolder $folder, ResidenceCheck $check): void
    {
        try {
            app(DeleteResidenceCheck::class)->execute($this->ci, $folder, $check);
            $this->fail('A cross-person / cross-folder delete must never resolve a row.');
        } catch (ModelNotFoundException) {
            // Expected: the locked lookup is scoped and simply finds nothing.
        }
    }

    private function auditCount(ClientFolder $folder, string $action): int
    {
        return AuditLog::query()->where('client_folder_id', $folder->id)->where('action', $action)->count();
    }

    private function deleteRoute(ClientFolder $folder, ResidenceCheck $check, ?CoMaker $coMaker = null): string
    {
        $person = $coMaker === null ? [] : ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];

        return route('client-folders.residence-checks.destroy', [$folder, $check] + $person);
    }

    /** Creates through the real store route so revision, photos, audit history and progress all match production. */
    private function createCheck(ClientFolder $folder, ?CoMaker $coMaker = null): ResidenceCheck
    {
        $this->actingAs($this->ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker?->id,
            'request_token' => 'create-'.$folder->id.'-'.($coMaker?->id ?? 'applicant').'-'.str()->uuid(),
            'ci_date' => now()->toDateString(),
            'location' => 'Saved residence location',
            'remarks' => 'Original',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->residenceChecks()->where('co_maker_id', $coMaker?->id)->latest('id')->firstOrFail();
    }

    private function folder(): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $this->ci->id,
            'start_date' => now()->toDateString(),
            'state' => 'complete',
        ]);

        return $folder;
    }
}
