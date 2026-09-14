<?php

namespace Tests\Feature\ClientFolders;

use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Business Check optimistic concurrency for EDITS of one existing check.
 *
 * The previous guard compared a submitted expected_updated_at against the row's updated_at, read
 * without a lock — so two CIs could both compare against the same value before either wrote, and
 * the later request silently overwrote the earlier one. updated_at could not be made safe here
 * either: it is second-precision, so two saves inside the same second carry an identical token and
 * a stale one is indistinguishable from a current one. It is replaced by a monotonic `revision`
 * compared and advanced under lockForUpdate() inside the save transaction.
 *
 * SQLite :memory: runs a single connection, so these tests cannot schedule two genuinely
 * overlapping transactions and do NOT prove MySQL/MariaDB InnoDB row-lock behavior. They prove the
 * token logic: a stale token is refused before anything is written, and a successful save advances
 * it so it can never be replayed.
 */
class BusinessCheckConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_the_edit_form_renders_the_monotonic_revision_and_no_timestamp_token(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();

        $this->assertSame(1, $check->revision);

        $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('name="expected_revision" value="1"', false)
            ->assertDontSee('name="expected_updated_at"', false);
    }

    /** Two saves inside one frozen second still advance the token, which is exactly what updated_at could not do. */
    public function test_successful_updates_advance_the_revision_even_within_the_same_second(): void
    {
        Carbon::setTestNow('2026-09-13 10:15:00');
        [$ci, $folder, $source, $check] = $this->createCheck();
        $secondCi = User::factory()->create();
        $originalTimestamp = $check->updated_at->copy();

        $this->update($ci, $folder, $source, $check, 1, ['remarks' => 'First update'])->assertRedirect();
        $check->refresh();
        $this->assertSame(2, $check->revision);
        $this->assertTrue($originalTimestamp->equalTo($check->updated_at));

        $this->update($secondCi, $folder, $source, $check, 2, ['remarks' => 'Second update'])->assertRedirect();
        $check->refresh();
        $this->assertSame(3, $check->revision);
        $this->assertSame('Second update', $check->remarks);
        $this->assertSame($secondCi->id, $check->updated_by);
        // Creator provenance is never rewritten by an update.
        $this->assertSame($ci->id, $check->ci_user_id);
    }

    /** The core race: both CIs start from revision 1, the first wins, the second is refused and changes nothing. */
    public function test_a_stale_update_is_refused_and_changes_no_data_photos_or_audit_history(): void
    {
        [$winner, $folder, $source, $check] = $this->createCheck();
        $staleEditor = User::factory()->create();
        $photo = $check->photos()->sole();
        $mapPath = $check->map_screenshot_path;

        $this->update($winner, $folder, $source, $check, 1, ['remarks' => 'Authoritative newer value'])->assertRedirect();
        $updateAudits = $this->auditCount($folder, 'business_check.updated');
        $progressAfterWinner = (float) $folder->fresh()->progress_percent;

        $response = $this->update($staleEditor, $folder, $source, $check, 1, [
            'remarks' => 'Stale overwrite attempt',
            'competitor_remarks' => 'Stale competitor notes',
            'removed_photo_ids' => [$photo->id],
            'business_photos' => [UploadedFile::fake()->image('Stale.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('StaleMap.png', 800, 600)->size(400),
        ], json: true);

        $response->assertStatus(409)->assertJson([
            'result' => 'conflict',
            'status_type' => 'error',
            'message' => 'This Business Check was updated by another user while you were working on it. Please review the latest information before saving again.',
        ]);

        $check->refresh();
        $this->assertSame('Authoritative newer value', $check->remarks);
        $this->assertNull($check->competitor_remarks);
        $this->assertSame(2, $check->revision);
        $this->assertSame($winner->id, $check->updated_by);
        // Nothing about its media moved: the removal was not applied and neither upload landed.
        $this->assertSame([$photo->id], $check->photos()->pluck('id')->all());
        $this->assertSame($mapPath, $check->map_screenshot_path);
        $this->assertSame($updateAudits, $this->auditCount($folder, 'business_check.updated'));
        $this->assertSame($progressAfterWinner, (float) $folder->fresh()->progress_percent);
    }

    public function test_an_update_without_a_revision_token_is_rejected(): void
    {
        [$ci, $folder, $source, $check] = $this->createCheck();

        $this->actingAs($ci)->postJson(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => $check->location,
            'remarks' => 'Attempt without a revision',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->assertNull($check->fresh()->remarks);
    }

    /**
     * Identity is the exact check id inside the exact folder + exact person scope. Business A's
     * check can never be updated through Business B's, and no Applicant/Co-Maker or cross-folder
     * combination resolves either.
     */
    public function test_exact_business_person_and_folder_isolation_is_preserved(): void
    {
        [$ci, $folder, $sourceA, $checkA] = $this->createCheck();
        $otherFolder = $this->folderFor($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        // A second, independent business for the same Applicant, and one for each Co-Maker.
        $sourceB = $this->businessSource($folder, 'Hardware Store', 'Second Business Address');
        $checkB = $this->createCheckFor($ci, $folder, $sourceB);
        $coMakerSource = $this->businessSource($folder, 'Co-Maker Store', 'Co-Maker Address', $coMaker->id);
        $coMakerCheck = $this->createCheckFor($ci, $folder, $coMakerSource, $coMaker);
        $otherSource = $this->businessSource($otherFolder, 'Other Folder Store', 'Other Folder Address');
        $otherCheck = $this->createCheckFor($ci, $otherFolder, $otherSource);

        $attempts = [
            // Applicant's check id submitted as that Co-Maker's own check.
            [$folder, $checkA->id, $coMaker, $sourceA->id],
            // Co-Maker's check id submitted as the Applicant's.
            [$folder, $coMakerCheck->id, null, $coMakerSource->id],
            // Co-Maker A's check id submitted under Co-Maker B.
            [$folder, $coMakerCheck->id, $coMakerB, $coMakerSource->id],
            // Another folder's check id submitted into this folder.
            [$folder, $otherCheck->id, null, $sourceA->id],
        ];

        foreach ($attempts as [$routeFolder, $checkId, $person, $sourceId]) {
            $this->actingAs($ci)->postJson(route('client-folders.business-checks.store', $routeFolder), [
                'check_id' => $checkId,
                'co_maker_id' => $person?->id,
                'expected_revision' => 1,
                'income_source_id' => $sourceId,
                'ci_date' => now()->toDateString(),
                'location' => 'Cross-scope overwrite attempt',
                'remarks' => 'Cross-scope overwrite attempt',
            ])->assertUnprocessable();
        }

        // Business A's own check is untouched, and so is every other business's.
        $this->assertNull($checkA->fresh()->remarks);
        $this->assertNull($checkB->fresh()->remarks);
        $this->assertNull($coMakerCheck->fresh()->remarks);
        $this->assertNull($otherCheck->fresh()->remarks);
        $this->assertSame($sourceA->id, $checkA->fresh()->income_source_id);
        $this->assertSame($sourceB->id, $checkB->fresh()->income_source_id);
    }

    /** Submitting the concurrency token is not itself a business-data change. */
    public function test_the_revision_token_alone_never_counts_as_a_real_change(): void
    {
        [$ci, $folder, $source, $check] = $this->createCheck();

        $this->update($ci, $folder, $source, $check, 1)
            ->assertRedirect()
            ->assertSessionHas('statusType', 'info')
            ->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');

        // A rolled-back no-change save leaves the token exactly where it was, so the still-open
        // form stays valid.
        $this->assertSame(1, $check->fresh()->revision);
    }

    /** Create is untouched by this task: it needs no token and still starts a check at revision 1. */
    public function test_creating_a_business_check_still_works_without_a_revision_token(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $check = $folder->businessChecks()->sole();
        $this->assertSame(1, $check->revision);
        $this->assertSame(1, $this->auditCount($folder, 'business_check.created'));
    }

    private function auditCount(ClientFolder $folder, string $action): int
    {
        return AuditLog::query()->where('client_folder_id', $folder->id)->where('action', $action)->count();
    }

    private function update(User $ci, ClientFolder $folder, IncomeSource $source, BusinessCheck $check, int $revision, array $overrides = [], bool $json = false)
    {
        $payload = array_merge([
            'check_id' => $check->id,
            'co_maker_id' => $check->co_maker_id,
            'expected_revision' => $revision,
            'income_source_id' => $source->id,
            // Resubmitted as-is so an unchanged save really is unchanged — an omitted Business Name
            // is normalised to null by the FormRequest and would dirty the row on its own, which is
            // unrelated to the concurrency token under test here.
            'business_name' => $check->business_name,
            'ci_date' => now()->toDateString(),
            'location' => $check->location,
        ], $overrides);

        $headers = $json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [];

        return $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload, $headers);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource, 3: BusinessCheck} */
    private function createCheck(): array
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        return [$ci, $folder, $source, $this->createCheckFor($ci, $folder, $source)];
    }

    private function createCheckFor(User $ci, ClientFolder $folder, IncomeSource $source, ?CoMaker $coMaker = null): BusinessCheck
    {
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ])->assertSessionHasNoErrors();

        return $folder->businessChecks()->where('income_source_id', $source->id)->firstOrFail();
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
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
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
