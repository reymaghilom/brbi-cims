<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Business Check delete vs edit / delete vs delete race safety.
 *
 * DeleteBusinessCheck already re-read and locked the row before touching anything. What it got
 * wrong was the identity of that locked lookup: it pinned the ROUTE-BOUND instance's
 * income_source_id, which SaveBusinessCheck lets a CI change. So when an edit won the race and
 * repointed the check at a different business, the delete could no longer resolve the very row it
 * was authorized to delete and failed as "not found". Identity is now the exact row id inside the
 * exact folder + exact person scope.
 *
 * SQLite :memory: runs a single connection, so these tests cannot schedule two genuinely
 * overlapping transactions and do NOT prove MySQL/MariaDB InnoDB row-lock scheduling. They prove
 * the outcome of each logical ordering, and that a stale instance can neither produce a second
 * successful delete nor resurrect a deleted check. No live Cloudinary: media is local fakes only.
 */
class BusinessCheckDeleteConcurrencyTest extends TestCase
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

    /** Ordering A — EDIT wins: the delete must act on the authoritative updated row, including media the edit added. */
    public function test_a_delete_holding_a_stale_instance_removes_the_authoritative_updated_record(): void
    {
        $folder = $this->folder();
        $source = $this->businessSource($folder, 'Sari-Sari Store');
        $check = $this->createLinkedCheck($folder, $source);
        $disk = app(CiTeamDocumentStorage::class)->disk();
        // Captured before the edit, exactly as a delete whose route binding resolved first holds it.
        $staleInstance = BusinessCheck::query()->findOrFail($check->id);

        $this->actingAs($this->ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'expected_revision' => $check->revision,
            'income_source_id' => $source->id,
            'business_name' => $check->business_name,
            'ci_date' => now()->toDateString(),
            'location' => $check->location,
            'competitor_photos' => [UploadedFile::fake()->image('AddedByEdit.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $check->refresh();
        $this->assertSame(2, $check->photos()->count());
        $addedPaths = $check->photos()->pluck('path')->all();
        foreach ($addedPaths as $path) {
            $this->assertTrue($disk->exists($path));
        }

        app(DeleteBusinessCheck::class)->execute($this->ci, $folder, $staleInstance);

        // The photo the edit committed is gone too — the media list comes from the locked row,
        // not from the snapshot the delete was handed.
        $this->assertModelMissing($check);
        $this->assertDatabaseCount('business_check_photos', 0);
        foreach ($addedPaths as $path) {
            $this->assertFalse($disk->exists($path), 'Local media of the authoritative record must be cleaned up.');
        }
        $this->assertSame(1, $this->auditCount($folder, 'business_check.deleted'));
    }

    /** The exact defect: an edit repointed the check at another business, and the stale delete must still resolve it. */
    public function test_a_delete_still_resolves_a_check_an_edit_repointed_at_another_business(): void
    {
        $folder = $this->folder();
        $sourceA = $this->businessSource($folder, 'Sari-Sari Store');
        $sourceB = $this->businessSource($folder, 'Hardware Store');
        $check = $this->createLinkedCheck($folder, $sourceA);
        $staleInstance = BusinessCheck::query()->findOrFail($check->id);

        $this->actingAs($this->ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'expected_revision' => $check->revision,
            'income_source_id' => $sourceB->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Second Business Address',
        ])->assertSessionHasNoErrors();
        $this->assertSame($sourceB->id, $check->fresh()->income_source_id);

        app(DeleteBusinessCheck::class)->execute($this->ci, $folder, $staleInstance);

        $this->assertModelMissing($check);
        // Suppression follows the AUTHORITATIVE business, not the one the stale instance named.
        $this->assertNotNull($sourceB->fresh()->business_check_deleted_at);
        $this->assertNull($sourceA->fresh()->business_check_deleted_at);
        $this->assertSame($sourceB->id, AuditLog::query()
            ->where('client_folder_id', $folder->id)->where('action', 'business_check.deleted')
            ->sole()->metadata['income_source_id']);
    }

    /** Ordering B — DELETE wins: the CI who still has the old edit page open cannot bring it back. */
    public function test_a_stale_edit_after_a_completed_delete_cannot_update_or_recreate_the_check(): void
    {
        $staleEditor = User::factory()->create();
        $folder = $this->folder();
        $source = $this->businessSource($folder, 'Sari-Sari Store');
        $check = $this->createLinkedCheck($folder, $source);
        $staleRevision = $check->revision;

        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $check))->assertRedirect();
        $this->assertModelMissing($check);
        $updateAudits = $this->auditCount($folder, 'business_check.updated');

        $response = $this->actingAs($staleEditor)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'expected_revision' => $staleRevision,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Stale editor location',
            'remarks' => 'Saved from a page opened before the delete',
            'business_photos' => [UploadedFile::fake()->image('Stale.jpg', 900, 700)->size(500)],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

        // Refused by the request's own exists rule on check_id, before the action runs. The message
        // is the CI-facing sentence, not Laravel's default "The selected check id is invalid."
        $response->assertUnprocessable()->assertJsonValidationErrors([
            'check_id' => 'This Business Check was deleted by another user while you were working on it. Please return to the Residence & Business Check page.',
        ]);
        $this->assertSame(0, BusinessCheck::query()->count());
        $this->assertDatabaseCount('business_check_photos', 0);
        $this->assertSame($updateAudits, $this->auditCount($folder, 'business_check.updated'));

        // Second line of defense, independent of that rule: the edit path's own locked lookup.
        $this->expectException(ModelNotFoundException::class);
        app(SaveBusinessCheck::class)->execute($staleEditor, $folder, [
            'check_id' => $check->id,
            'expected_revision' => $staleRevision,
            'ci_date' => now()->toDateString(),
            'location' => 'Stale editor location',
        ]);
    }

    /** Ordering C — two deletes: only one succeeds, only one audit, no repeated cleanup. */
    public function test_a_second_delete_of_the_same_check_creates_no_second_delete_audit(): void
    {
        $secondCi = User::factory()->create();
        $folder = $this->folder();
        $source = $this->businessSource($folder, 'Sari-Sari Store');
        $check = $this->createLinkedCheck($folder, $source);
        $staleInstance = BusinessCheck::query()->findOrFail($check->id);

        app(DeleteBusinessCheck::class)->execute($this->ci, $folder, $check);
        $suppressedAt = $source->fresh()->business_check_deleted_at;
        $this->assertNotNull($suppressedAt);

        try {
            app(DeleteBusinessCheck::class)->execute($secondCi, $folder, $staleInstance);
            $this->fail('The second delete must not report success for an already-deleted check.');
        } catch (ModelNotFoundException) {
            // Same outcome the route binding produces once the row is gone.
        }

        $audits = AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'business_check.deleted')->get();
        $this->assertCount(1, $audits);
        $this->assertSame($this->ci->id, $audits->sole()->user_id, 'Attribution stays with the CI who actually deleted it.');
        $this->assertEquals($suppressedAt, $source->fresh()->business_check_deleted_at, 'The marker is not rewritten by a failed second delete.');
    }

    /** Applicant / Co-Maker A / Co-Maker B / other folder can never delete one another's checks. */
    public function test_exact_person_and_folder_isolation_is_preserved(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicant = $this->createManualCheck($folder, 'Applicant Store');
        $checkA = $this->createManualCheck($folder, 'Co-Maker A Store', $coMakerA);
        $checkB = $this->createManualCheck($folder, 'Co-Maker B Store', $coMakerB);
        $otherApplicant = $this->createManualCheck($otherFolder, 'Other Folder Store');

        // Each attempt hands the action a check that belongs to a different folder than the one it
        // is run against, so the scoped locked lookup resolves nothing.
        $this->assertDeleteRejected($otherFolder, BusinessCheck::query()->findOrFail($applicant->id));
        $this->assertDeleteRejected($folder, BusinessCheck::query()->findOrFail($otherApplicant->id));
        $this->assertDeleteRejected($otherFolder, BusinessCheck::query()->findOrFail($checkA->id));

        // And through the HTTP route, a Co-Maker context cannot reach another person's check.
        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $applicant, $coMakerA))->assertNotFound();
        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $checkA, $coMakerB))->assertNotFound();
        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $checkA))->assertNotFound();

        foreach ([$applicant, $checkA, $checkB, $otherApplicant] as $survivor) {
            $this->assertModelExists($survivor);
        }
        $this->assertSame(0, $this->auditCount($folder, 'business_check.deleted'));
    }

    /** Two checks that look alike are still two rows: deleting one never touches the other. */
    public function test_deleting_one_check_leaves_an_identically_named_check_untouched(): void
    {
        $folder = $this->folder();
        $first = $this->createManualCheck($folder, 'Sari-Sari Store');
        // A deliberate second manual business with the same name at another location.
        $second = $this->createManualCheck($folder, 'Sari-Sari Store', null, 'Another Barangay');

        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $first))->assertRedirect();

        $this->assertModelMissing($first);
        $this->assertModelExists($second);
        $this->assertSame(1, $second->fresh()->photos()->count(), 'The survivor keeps its own media.');
        $this->assertSame(1, $this->auditCount($folder, 'business_check.deleted'));
    }

    /** A manual check has no business to suppress — nothing may be invented or mutated. */
    public function test_deleting_a_manual_check_never_mutates_any_income_source(): void
    {
        $folder = $this->folder();
        $untouched = $this->businessSource($folder, 'Unrelated Business');
        $manual = $this->createManualCheck($folder, 'Manual Store');

        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $manual))->assertRedirect();

        $this->assertModelMissing($manual);
        $this->assertNull($untouched->fresh()->business_check_deleted_at);
        $this->assertSame(1, IncomeSource::query()->count(), 'No IncomeSource is created or removed by a manual delete.');
        $this->assertNull(AuditLog::query()
            ->where('client_folder_id', $folder->id)->where('action', 'business_check.deleted')
            ->sole()->metadata['income_source_id']);
    }

    /** Progress reacts to the delete, and a manual re-add afterwards still works. */
    public function test_progress_recalculates_and_a_new_check_can_be_added_after_delete(): void
    {
        $folder = $this->folder();
        $source = $this->businessSource($folder, 'Sari-Sari Store');
        $check = $this->createLinkedCheck($folder, $source);
        $progressWithCheck = (float) $folder->fresh()->progress_percent;

        $this->actingAs($this->ci)->delete($this->deleteRoute($folder, $check))->assertRedirect();

        $progressAfterDelete = (float) $folder->fresh()->progress_percent;
        $this->assertLessThan($progressWithCheck, $progressAfterDelete);

        // Re-adding a manual Business Check for the same person is unaffected by the delete.
        $replacement = $this->createManualCheck($folder, 'Replacement Store');
        $this->assertModelExists($replacement);
        $this->assertGreaterThan($progressAfterDelete, (float) $folder->fresh()->progress_percent);
    }

    private function assertDeleteRejected(ClientFolder $folder, BusinessCheck $check): void
    {
        try {
            app(DeleteBusinessCheck::class)->execute($this->ci, $folder, $check);
            $this->fail('A cross-person / cross-folder delete must never resolve a row.');
        } catch (ModelNotFoundException) {
            // Expected: the scoped locked lookup finds nothing.
        }
    }

    private function auditCount(ClientFolder $folder, string $action): int
    {
        return AuditLog::query()->where('client_folder_id', $folder->id)->where('action', $action)->count();
    }

    private function deleteRoute(ClientFolder $folder, BusinessCheck $check, ?CoMaker $coMaker = null): string
    {
        $person = $coMaker === null ? [] : ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];

        return route('client-folders.business-checks.destroy', [$folder, $check] + $person);
    }

    private function createLinkedCheck(ClientFolder $folder, IncomeSource $source): BusinessCheck
    {
        $this->actingAs($this->ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ])->assertSessionHasNoErrors();

        return $folder->businessChecks()->where('income_source_id', $source->id)->firstOrFail();
    }

    private function createManualCheck(ClientFolder $folder, string $name, ?CoMaker $coMaker = null, string $location = 'Poblacion, San Miguel, Bulacan'): BusinessCheck
    {
        $this->actingAs($this->ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker?->id,
            'request_token' => 'create-'.$folder->id.'-'.str()->uuid(),
            'business_name' => $name,
            'ci_date' => now()->toDateString(),
            'location' => $location,
            'business_photos' => [UploadedFile::fake()->image('Manual.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->businessChecks()->where('co_maker_id', $coMaker?->id)->latest('id')->firstOrFail();
    }

    private function folder(): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $this->ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $name.' Address', 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
