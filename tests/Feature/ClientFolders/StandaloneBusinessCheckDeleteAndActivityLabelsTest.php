<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Two verified issues, covered together because they share the same audit vocabulary:
 *
 * 1. A STANDALONE Business Check (income_source_id = null — a fully supported mode, see
 *    BusinessCheckIndependentArchitectureTest) used to be undeletable: DeleteBusinessCheck
 *    resolved its parent IncomeSource unconditionally, so whereKey(null)->firstOrFail() turned
 *    every standalone delete into a 404 that deleted nothing and logged nothing. A LINKED check
 *    still resolves its exact IncomeSource strictly, and its business_check_deleted_at suppression
 *    marker behaviour is unchanged.
 *
 * 2. Folder Contents Recent Activity rendered create and update with one identical label, so a
 *    newly added check was indistinguishable from an edited one. The AuditLog action keys were
 *    already correct and are NOT touched — only the displayed labels now differ.
 */
class StandaloneBusinessCheckDeleteAndActivityLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // -----------------------------------------------------------------
    // 1. Standalone Business Check delete
    // -----------------------------------------------------------------

    public function test_standalone_business_check_is_deleted_and_audited_by_the_actual_deleter(): void
    {
        $creator = User::factory()->create(['full_name' => 'USER A']);
        $deleter = User::factory()->create(['full_name' => 'USER C']);
        $folder = $this->folderFor($creator);

        // An unrelated business that must not be touched by a standalone delete.
        $untouched = $this->savedBusiness($folder, 'UNRELATED TRADING', 'Unrelated Address', '2026-01-05');
        $reportBefore = $untouched->businessReport()->first()->only(['business_name', 'main_business_address']);

        $check = $this->standaloneCheck($folder, $creator, 'MANUAL SARI-SARI STORE', 'Purok 3, Poblacion');
        $this->assertNull($check->income_source_id, 'Precondition: this is a standalone Business Check.');

        $response = $this->actingAs($deleter)->delete(route('client-folders.business-checks.destroy', [$folder, $check]));

        $response->assertRedirect();
        $this->assertNotSame(404, $response->getStatusCode(), 'A standalone Business Check delete must never 404.');
        $this->assertSame(0, $folder->businessChecks()->count(), 'The exact standalone check is gone.');

        // Deletion never invents or removes a business, and never edits a Business Report.
        $this->assertSame(1, IncomeSource::query()->count(), 'No IncomeSource is created or removed.');
        $this->assertTrue($untouched->fresh()->exists);
        $this->assertNull($untouched->fresh()->business_check_deleted_at, 'No suppression marker is written for a standalone delete.');
        $this->assertSame($reportBefore, $untouched->businessReport()->first()->only(['business_name', 'main_business_address']));
        $this->assertSame(1, BusinessReport::query()->count(), 'The Business Report is untouched.');

        $audit = AuditLog::where('action', 'business_check.deleted')->sole();
        $this->assertSame($deleter->id, $audit->user_id, 'The actor is the user who actually deleted.');
        $this->assertSame($folder->id, $audit->client_folder_id);
        $this->assertSame($check->id, $audit->metadata['business_check_id']);
        $this->assertNull($audit->metadata['income_source_id'], 'A standalone check records a null business reference.');
        $this->assertNull($audit->metadata['co_maker_id'], 'Applicant context.');
        $this->assertSame('MANUAL SARI-SARI STORE', $audit->metadata['business_name'], 'Snapshotted before delete.');
        $this->assertSame('Purok 3, Poblacion', $audit->metadata['location'], 'Snapshotted before delete.');
    }

    public function test_recent_activity_renders_a_standalone_deletion_after_the_row_is_gone(): void
    {
        $ci = User::factory()->create(['full_name' => 'USER C']);
        $folder = $this->folderFor($ci);
        $check = $this->standaloneCheck($folder, $ci, 'GONE STORE', 'Purok 9');

        $this->actingAs($ci)->delete(route('client-folders.business-checks.destroy', [$folder, $check]))->assertRedirect();
        $this->assertSame(0, BusinessCheck::query()->count());

        // The panel reads AuditLog metadata only — it never loads the deleted model.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Business Check removed')
            ->assertSee('GONE STORE')
            ->assertSee('USER C');
    }

    public function test_a_failed_standalone_delete_logs_no_successful_deletion(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $check = $this->standaloneCheck($folder, $ci, 'WRONG FOLDER STORE', 'Purok 1');

        // Cross-folder id guessing is rejected by the nested route's scopeBindings().
        $this->actingAs($ci)
            ->delete(route('client-folders.business-checks.destroy', [$otherFolder, $check]))
            ->assertNotFound();

        $this->assertSame(1, $folder->businessChecks()->count(), 'The check survives a rejected delete.');
        $this->assertSame(0, AuditLog::where('action', 'business_check.deleted')->count(), 'No success audit for a failed delete.');
    }

    // -----------------------------------------------------------------
    // 2. Linked Business Check delete — regression
    // -----------------------------------------------------------------

    public function test_linked_business_check_delete_still_works_and_keeps_its_suppression_marker(): void
    {
        $creator = User::factory()->create(['full_name' => 'USER A']);
        $deleter = User::factory()->create(['full_name' => 'USER C']);
        $folder = $this->folderFor($creator);
        $source = $this->savedBusiness($folder, 'ALPHA TRADING', 'Alpha Address', '2026-01-15');

        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id, 'co_maker_id' => null,
            'business_name' => 'ALPHA TRADING', 'location' => 'Alpha Address',
            'ci_date' => '2026-01-15', 'ci_user_id' => $creator->id,
        ]);

        $this->actingAs($deleter)
            ->delete(route('client-folders.business-checks.destroy', [$folder, $check]))
            ->assertRedirect();

        $this->assertSame(0, $folder->businessChecks()->count(), 'The linked check is deleted.');
        $this->assertTrue($source->fresh()->exists, 'The exact IncomeSource is preserved.');
        $this->assertNotNull($source->fresh()->business_check_deleted_at, 'The suppression marker still applies to the exact linked IncomeSource.');
        $this->assertSame(1, BusinessReport::query()->count(), 'The Business Report is preserved.');
        $this->assertSame('ALPHA TRADING', $source->businessReport()->first()->business_name);

        $audit = AuditLog::where('action', 'business_check.deleted')->sole();
        $this->assertSame($deleter->id, $audit->user_id);
        $this->assertSame($source->id, $audit->metadata['income_source_id'], 'The exact income_source_id is retained.');
        $this->assertSame('ALPHA TRADING', $audit->metadata['business_name']);
    }

    // -----------------------------------------------------------------
    // 3. Person isolation
    // -----------------------------------------------------------------

    public function test_deleting_one_co_makers_standalone_check_never_touches_another_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $applicantCheck = $this->standaloneCheck($folder, $ci, 'APPLICANT STORE', 'Applicant Purok');
        $checkA = $this->standaloneCheck($folder, $ci, 'CO MAKER A STORE', 'A Purok', $coMakerA->id);
        $checkB = $this->standaloneCheck($folder, $ci, 'CO MAKER B STORE', 'B Purok', $coMakerB->id);

        $this->actingAs($ci)->delete(route('client-folders.business-checks.destroy', [$folder, $checkA])
            .'?person=co-maker&co_maker_id='.$coMakerA->id)->assertRedirect();

        $this->assertNull(BusinessCheck::find($checkA->id), "Only Co-Maker A's exact check is deleted.");
        $this->assertNotNull(BusinessCheck::find($applicantCheck->id), 'The Applicant is untouched.');
        $this->assertNotNull(BusinessCheck::find($checkB->id), 'Co-Maker B is untouched.');

        $audit = AuditLog::where('action', 'business_check.deleted')->sole();
        $this->assertSame($coMakerA->id, $audit->metadata['co_maker_id'], 'The exact Co-Maker A context is retained.');
        $this->assertSame('CO MAKER A STORE', $audit->metadata['business_name']);
    }

    // -----------------------------------------------------------------
    // 4-6. Recent Activity labels + actor semantics
    // -----------------------------------------------------------------

    public function test_residence_check_create_update_delete_render_distinct_labels_and_actors(): void
    {
        [$a, $b, $c] = $this->threeUsers();
        $folder = $this->folderFor($a);

        $this->actingAs($a)->post(route('client-folders.residence-checks.store', $folder), [
            'location' => 'Purok 1', 'ci_date' => '2026-01-01', 'remarks' => 'first',
            'photos' => [UploadedFile::fake()->image('r.jpg', 900, 700)->size(300)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->sole();

        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Residence Check added')
            ->assertDontSee('Residence Check updated');

        // A real change, by a different user.
        $this->actingAs($b)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'location' => 'Purok 1', 'ci_date' => '2026-01-01', 'remarks' => 'CHANGED BY B',
        ])->assertSessionHasNoErrors();

        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Residence Check added')
            ->assertSee('Residence Check updated');

        $this->actingAs($c)->delete(route('client-folders.residence-checks.destroy', [$folder, $check]))->assertRedirect();

        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Residence Check removed');

        // Each event keeps the user who actually performed it — never the original creator.
        $this->assertSame($a->id, AuditLog::where('action', 'residence_check.created')->sole()->user_id);
        $this->assertSame($b->id, AuditLog::where('action', 'residence_check.updated')->sole()->user_id);
        $this->assertSame($c->id, AuditLog::where('action', 'residence_check.deleted')->sole()->user_id);
    }

    public function test_business_check_create_update_delete_render_distinct_labels_and_actors(): void
    {
        [$a, $b, $c] = $this->threeUsers();
        $folder = $this->folderFor($a);

        $this->actingAs($a)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'MANUAL STORE', 'location' => 'Purok 3', 'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('b.jpg', 900, 700)->size(300)],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->sole();

        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Business Check added')
            ->assertDontSee('Business Check updated');

        $this->actingAs($b)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id, 'business_name' => 'MANUAL STORE', 'location' => 'Purok 3',
            'ci_date' => '2026-02-10', 'remarks' => 'EDITED BY B',
        ])->assertSessionHasNoErrors();

        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Business Check added')
            ->assertSee('Business Check updated');

        $this->actingAs($c)->delete(route('client-folders.business-checks.destroy', [$folder, $check]))->assertRedirect();

        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('Business Check removed');

        $this->assertSame($a->id, AuditLog::where('action', 'business_check.created')->sole()->user_id);
        $this->assertSame($b->id, AuditLog::where('action', 'business_check.updated')->sole()->user_id);
        $this->assertSame($c->id, AuditLog::where('action', 'business_check.deleted')->sole()->user_id);
    }

    public function test_the_canonical_audit_action_keys_are_unchanged_by_the_relabelling(): void
    {
        [$a, $b] = $this->threeUsers();
        $folder = $this->folderFor($a);

        $this->actingAs($a)->post(route('client-folders.residence-checks.store', $folder), [
            'location' => 'Purok 1', 'ci_date' => '2026-01-01',
            'photos' => [UploadedFile::fake()->image('r.jpg', 900, 700)->size(300)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->sole();
        $this->actingAs($b)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'location' => 'Purok 1', 'ci_date' => '2026-01-01', 'remarks' => 'REAL CHANGE',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', ['action' => 'residence_check.created', 'user_id' => $a->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'residence_check.updated', 'user_id' => $b->id]);
    }

    // -----------------------------------------------------------------
    // 7. No-change protection
    // -----------------------------------------------------------------

    public function test_a_no_change_residence_edit_creates_no_update_history(): void
    {
        $a = User::factory()->create();
        $folder = $this->folderFor($a);

        $this->actingAs($a)->post(route('client-folders.residence-checks.store', $folder), [
            'location' => 'Purok 1', 'ci_date' => '2026-01-01', 'remarks' => 'same',
            'photos' => [UploadedFile::fake()->image('r.jpg', 900, 700)->size(300)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->sole();

        $this->actingAs($a)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'location' => 'Purok 1', 'ci_date' => '2026-01-01', 'remarks' => 'same',
        ]);

        $this->assertSame(0, AuditLog::where('action', 'residence_check.updated')->count());
        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertDontSee('Residence Check updated');
    }

    public function test_a_no_change_business_edit_creates_no_update_history(): void
    {
        $a = User::factory()->create();
        $folder = $this->folderFor($a);

        $this->actingAs($a)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'MANUAL STORE', 'location' => 'Purok 3', 'ci_date' => '2026-02-10', 'remarks' => 'same',
            'business_photos' => [UploadedFile::fake()->image('b.jpg', 900, 700)->size(300)],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->sole();

        $this->actingAs($a)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id, 'business_name' => 'MANUAL STORE', 'location' => 'Purok 3',
            'ci_date' => '2026-02-10', 'remarks' => 'same',
        ]);

        $this->assertSame(0, AuditLog::where('action', 'business_check.updated')->count());
        $this->actingAs($a)->get(route('client-folders.show', $folder))->assertOk()
            ->assertDontSee('Business Check updated');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return array{0: User, 1: User, 2: User} */
    private function threeUsers(): array
    {
        return [
            User::factory()->create(['full_name' => 'USER A']),
            User::factory()->create(['full_name' => 'USER B']),
            User::factory()->create(['full_name' => 'USER C']),
        ];
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);

        return $folder;
    }

    /** A standalone Business Check: income_source_id stays null, no business is ever created. */
    private function standaloneCheck(ClientFolder $folder, User $ci, string $name, string $location, ?int $coMakerId = null): BusinessCheck
    {
        return $folder->businessChecks()->create([
            'income_source_id' => null,
            'co_maker_id' => $coMakerId,
            'business_name' => $name,
            'location' => $location,
            'ci_date' => '2026-02-10',
            'ci_user_id' => $ci->id,
        ]);
    }

    /** A genuinely saved Business Report (revision > 1) — the same shape the dropdown lists. */
    private function savedBusiness(ClientFolder $folder, string $name, string $address, string $ciDate, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
            'state' => RecordState::Complete,
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $address,
            'start_date' => $ciDate,
            'report_category' => 'Leasing',
            'year_established' => 2020,
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }
}
