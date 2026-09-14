<?php

namespace Tests\Feature\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceCheck;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResidenceCheckConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_create_still_works_and_edit_form_contains_the_monotonic_revision(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->createCheck($ci, $folder);

        $this->assertSame(1, $check->revision);
        $this->assertEquals(28.57, (float) $folder->fresh()->progress_percent);

        $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('name="expected_revision" value="1"', false)
            ->assertDontSee('name="expected_updated_at"', false);
    }

    public function test_create_request_token_remains_idempotent(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $payload = [
            'request_token' => 'same-loaded-form-token',
            'ci_date' => now()->toDateString(),
            'location' => 'Saved residence location',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ];

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $payload)->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('residence_checks', 1);
        $this->assertDatabaseCount('residence_check_photos', 1);
    }

    public function test_current_revisions_save_and_advance_even_within_the_same_second(): void
    {
        Carbon::setTestNow('2026-09-13 10:15:00');
        $firstCi = User::factory()->create();
        $secondCi = User::factory()->create();
        $folder = $this->folder($firstCi);
        $check = $this->createCheck($firstCi, $folder, null, 'Original');
        $originalTimestamp = $check->updated_at->copy();

        $this->updateCheck($firstCi, $folder, $check, 1, ['remarks' => 'First update'])->assertOk();
        $check->refresh();
        $this->assertSame(2, $check->revision);
        $this->assertTrue($originalTimestamp->equalTo($check->updated_at));

        $this->updateCheck($secondCi, $folder, $check, 2, ['remarks' => 'Second update'])->assertOk();
        $check->refresh();
        $this->assertSame(3, $check->revision);
        $this->assertSame('Second update', $check->remarks);
        $this->assertSame($secondCi->id, $check->updated_by);
        $this->assertTrue($originalTimestamp->equalTo($check->updated_at));
    }

    public function test_stale_update_cannot_change_parent_photos_attribution_or_audit_history(): void
    {
        $creator = User::factory()->create();
        $winner = User::factory()->create();
        $staleEditor = User::factory()->create();
        $folder = $this->folder($creator);
        $check = $this->createCheck($creator, $folder, null, 'Original');
        $photo = $check->photos()->sole();

        $this->updateCheck($winner, $folder, $check, 1, ['remarks' => 'Authoritative newer value'])->assertOk();
        $successfulAuditCount = AuditLog::query()
            ->where('client_folder_id', $folder->id)
            ->where('action', 'residence_check.updated')
            ->count();

        $response = $this->updateCheck($staleEditor, $folder, $check, 1, [
            'remarks' => 'Stale overwrite attempt',
            'removed_photo_ids' => [$photo->id],
            'photos' => [UploadedFile::fake()->image('Stale.jpg', 900, 700)->size(500)],
        ]);

        $response->assertStatus(409)->assertJson([
            'result' => 'conflict',
            'message' => 'This Residence Check was updated by another user. Review or reload the latest version before saving again.',
            'status_type' => 'error',
        ]);
        $check->refresh();
        $this->assertSame('Authoritative newer value', $check->remarks);
        $this->assertSame(2, $check->revision);
        $this->assertSame($winner->id, $check->updated_by);
        $this->assertSame([$photo->id], $check->photos()->pluck('id')->all());
        $this->assertSame($successfulAuditCount, AuditLog::query()
            ->where('client_folder_id', $folder->id)
            ->where('action', 'residence_check.updated')
            ->count());
    }

    public function test_update_requires_a_revision_token(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->createCheck($ci, $folder);

        $this->actingAs($ci)->postJson(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'remarks' => 'Attempt without a revision',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->assertSame('Original', $check->fresh()->remarks);
    }

    public function test_applicant_co_makers_and_folders_remain_exactly_isolated(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $otherFolder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $applicant = $this->createCheck($ci, $folder, null, 'Applicant');
        $checkA = $this->createCheck($ci, $folder, $coMakerA, 'Co-Maker A');
        $checkB = $this->createCheck($ci, $folder, $coMakerB, 'Co-Maker B');

        $attempts = [
            [$folder, $checkA, null],
            [$folder, $applicant, $coMakerA],
            [$folder, $checkB, $coMakerA],
            [$otherFolder, $applicant, null],
        ];

        foreach ($attempts as [$routeFolder, $check, $person]) {
            $this->actingAs($ci)->postJson(route('client-folders.residence-checks.store', $routeFolder), [
                'check_id' => $check->id,
                'co_maker_id' => $person?->id,
                'expected_revision' => $check->revision,
                'remarks' => 'Cross-scope overwrite attempt',
            ])->assertUnprocessable()->assertJsonValidationErrors('check_id');
        }

        $this->assertSame('Applicant', $applicant->fresh()->remarks);
        $this->assertSame('Co-Maker A', $checkA->fresh()->remarks);
        $this->assertSame('Co-Maker B', $checkB->fresh()->remarks);
    }

    /**
     * The manually reproduced duplicate: two CIs each open their own Add Residence Check form for
     * the same exact person, so each request carries its own request_token and the token guard
     * cannot pair them. The exact-person lock + authoritative lookup is what refuses the second one.
     */
    public function test_two_different_request_tokens_create_only_one_check_for_the_same_applicant(): void
    {
        $firstCi = User::factory()->create();
        $secondCi = User::factory()->create();
        $folder = $this->folder($firstCi);
        $winner = $this->createCheck($firstCi, $folder, null, 'First CI wins');
        $auditsAfterWinner = $this->createdAuditCount($folder);

        $this->losingCreate($secondCi, $folder, null)
            ->assertStatus(409)
            ->assertJson([
                'result' => 'exists',
                'status_type' => 'error',
                'return_url' => route('client-folders.residence-checks.edit', [$folder, $winner]),
            ]);

        $this->assertSame([$winner->id], $folder->residenceChecks()->whereNull('co_maker_id')->pluck('id')->all());
        $this->assertSame('First CI wins', $winner->fresh()->remarks);
        // The loser uploaded nothing and wrote no "created" history of its own.
        $this->assertSame(1, $winner->photos()->count());
        $this->assertSame($auditsAfterWinner, $this->createdAuditCount($folder));
        $this->assertEquals(28.57, (float) $folder->fresh()->progress_percent);
    }

    public function test_two_different_request_tokens_create_only_one_check_for_one_exact_co_maker(): void
    {
        $firstCi = User::factory()->create();
        $secondCi = User::factory()->create();
        $folder = $this->folder($firstCi);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $winner = $this->createCheck($firstCi, $folder, $coMaker, 'Co-Maker A first');
        $auditsAfterWinner = $this->createdAuditCount($folder);

        $this->losingCreate($secondCi, $folder, $coMaker)->assertStatus(409)->assertJson(['result' => 'exists']);

        $this->assertSame([$winner->id], $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->pluck('id')->all());
        $this->assertSame('Co-Maker A first', $winner->fresh()->remarks);
        $this->assertSame(1, $winner->photos()->count());
        $this->assertSame($auditsAfterWinner, $this->createdAuditCount($folder));
    }

    /** The invariant is per exact person, never per folder: the Applicant and each Co-Maker still get their own independent Residence Check. */
    public function test_each_exact_person_still_gets_their_own_independent_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $otherFolder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicant = $this->createCheck($ci, $folder, null, 'Applicant');
        $checkA = $this->createCheck($ci, $folder, $coMakerA, 'Co-Maker A');
        $checkB = $this->createCheck($ci, $folder, $coMakerB, 'Co-Maker B');
        $otherApplicant = $this->createCheck($ci, $otherFolder, null, 'Other folder applicant');

        $this->assertCount(4, collect([$applicant->id, $checkA->id, $checkB->id, $otherApplicant->id])->unique());
        $this->assertSame([$applicant->id], $folder->residenceChecks()->whereNull('co_maker_id')->pluck('id')->all());
        $this->assertSame([$checkA->id], $folder->residenceChecks()->where('co_maker_id', $coMakerA->id)->pluck('id')->all());
        $this->assertSame([$checkB->id], $folder->residenceChecks()->where('co_maker_id', $coMakerB->id)->pluck('id')->all());
        $this->assertSame([$otherApplicant->id], $otherFolder->residenceChecks()->whereNull('co_maker_id')->pluck('id')->all());
    }

    private function createdAuditCount(ClientFolder $folder): int
    {
        return AuditLog::query()
            ->where('client_folder_id', $folder->id)
            ->where('action', 'residence_check.created')
            ->count();
    }

    /** A second Add form for a person who already has a check — its own fresh request_token, its own photo, exactly as a second CI's browser would send it. */
    private function losingCreate(User $ci, ClientFolder $folder, ?CoMaker $coMaker)
    {
        return $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker?->id,
            'request_token' => 'second-add-form-'.str()->uuid(),
            'ci_date' => now()->toDateString(),
            'location' => 'Saved residence location',
            'remarks' => 'Second CI duplicate attempt',
            'photos' => [UploadedFile::fake()->image('Duplicate.jpg', 900, 700)->size(500)],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function createCheck(User $ci, ClientFolder $folder, ?CoMaker $coMaker = null, string $remarks = 'Original'): ResidenceCheck
    {
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker?->id,
            'request_token' => 'create-'.$folder->id.'-'.($coMaker?->id ?? 'applicant').'-'.str()->uuid(),
            'ci_date' => now()->toDateString(),
            'location' => 'Saved residence location',
            'remarks' => $remarks,
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->residenceChecks()->where('co_maker_id', $coMaker?->id)->latest('id')->firstOrFail();
    }

    private function updateCheck(User $ci, ClientFolder $folder, ResidenceCheck $check, int $revision, array $overrides = [])
    {
        return $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), array_merge([
            'check_id' => $check->id,
            'co_maker_id' => $check->co_maker_id,
            'expected_revision' => $revision,
            'ci_date' => $check->ci_date->toDateString(),
            'location' => $check->location,
            'remarks' => $check->remarks,
        ], $overrides), [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    private function folder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->cibiReports()->create([
            'ci_in_charge_id' => $ci->id,
            'start_date' => now()->toDateString(),
            'state' => 'complete',
        ]);

        return $folder;
    }
}
