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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Two different problems with creating a MANUAL (unlinked) Business Check, handled differently:
 *
 *  A. One Add form submitted twice — double-click, a network/browser retry. Deduped on
 *     request_token, a fresh UUID the form embeds once per page load, so it yields exactly one row.
 *  B. A genuinely new Add form whose Business Name + Location + CI Date match an existing manual
 *     check for the same exact person. That is only ADVISORY: a person may legitimately run
 *     several businesses, so the create is held back with a similar_exists warning and the CI can
 *     deliberately proceed with Continue Anyway (allow_similar_duplicate).
 *
 * A LINKED check (income_source_id set) is unaffected: its one-check-per-business rule stays a
 * hard block that Continue Anyway cannot bypass.
 *
 * SQLite :memory: runs a single connection, so these tests cannot schedule two genuinely
 * overlapping requests and do NOT prove production lock behavior. Same-token serialization relies
 * on Cache::lock()->block(), the convention SaveResidenceCheck already uses.
 */
class ManualBusinessCheckDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    private const WARNING = 'A similar Business Check already exists for this person with the same Business Name, Location, and CI Date. Please review the existing record before creating another one.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_a_first_manual_business_check_is_created_normally(): void
    {
        [$ci, $folder] = $this->folder();

        $this->create($ci, $folder, 'token-1')->assertOk()->assertJson(['result' => 'success']);

        $check = $folder->businessChecks()->sole();
        $this->assertNull($check->income_source_id);
        $this->assertSame('SARI-SARI STORE', $check->business_name);
        $this->assertSame(1, $this->createdAudits($folder));
    }

    /** A. The same loaded form submitted twice — only one row, one photo, one audit event. */
    public function test_the_same_request_token_submitted_twice_creates_only_one_business_check(): void
    {
        [$ci, $folder] = $this->folder();

        $first = $this->create($ci, $folder, 'same-form-token');
        $second = $this->create($ci, $folder, 'same-form-token');

        $first->assertOk()->assertJson(['result' => 'success']);
        // The retry is answered with the first request's own outcome, not an error.
        $second->assertOk()->assertJson(['result' => 'success']);
        $this->assertSame($first->json('return_url'), $second->json('return_url'));
        $this->assertCount(1, $folder->businessChecks()->get());
        $this->assertSame(1, BusinessCheck::query()->sole()->photos()->count());
        $this->assertSame(1, $this->createdAudits($folder));
    }

    /** B. A separate Add form (its own token) with the same signature warns instead of creating. */
    public function test_a_matching_signature_from_a_new_form_warns_and_creates_nothing(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();
        $existing = $folder->businessChecks()->sole();
        $auditsAfterFirst = $this->createdAudits($folder);
        $progressAfterFirst = (float) $folder->fresh()->progress_percent;

        $this->create($ci, $folder, 'token-2')
            ->assertStatus(409)
            ->assertJson([
                'result' => 'similar_exists',
                'status_type' => 'warning',
                'message' => self::WARNING,
                'existing_url' => route('client-folders.business-checks.edit', [$folder, $existing]),
            ]);

        // No row, no photo, no audit, no progress movement — the warning has no side effects.
        $this->assertCount(1, $folder->businessChecks()->get());
        $this->assertDatabaseCount('business_check_photos', 1);
        $this->assertSame($auditsAfterFirst, $this->createdAudits($folder));
        $this->assertSame($progressAfterFirst, (float) $folder->fresh()->progress_percent);
    }

    /** Continue Anyway is what makes this advisory rather than a uniqueness rule. */
    public function test_continue_anyway_creates_the_second_manual_business_check(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();
        $this->create($ci, $folder, 'token-2')->assertStatus(409);
        $auditsBefore = $this->createdAudits($folder);

        // Continue Anyway resubmits the very same form, so it carries the same token it was warned on.
        $this->create($ci, $folder, 'token-2', ['allow_similar_duplicate' => '1'])
            ->assertOk()->assertJson(['result' => 'success']);

        $this->assertCount(2, $folder->businessChecks()->get());
        $this->assertSame($auditsBefore + 1, $this->createdAudits($folder));
    }

    /** The warning never consumes the token, but a successful Continue Anyway does. */
    public function test_double_clicking_continue_anyway_still_creates_only_one_second_row(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();
        $this->create($ci, $folder, 'token-2')->assertStatus(409);
        $auditsBefore = $this->createdAudits($folder);

        $first = $this->create($ci, $folder, 'token-2', ['allow_similar_duplicate' => '1']);
        $second = $this->create($ci, $folder, 'token-2', ['allow_similar_duplicate' => '1']);

        $first->assertOk()->assertJson(['result' => 'success']);
        $second->assertOk()->assertJson(['result' => 'success']);
        $this->assertSame($first->json('return_url'), $second->json('return_url'));
        $this->assertCount(2, $folder->businessChecks()->get());
        $this->assertSame($auditsBefore + 1, $this->createdAudits($folder));
    }

    /** Business Name alone is never the signal — one person may run two same-named stalls in different places. */
    public function test_the_same_name_at_a_different_location_is_not_treated_as_similar(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();

        $this->create($ci, $folder, 'token-2', ['location' => 'Another Barangay, San Miguel, Bulacan'])
            ->assertOk()->assertJson(['result' => 'success']);

        $this->assertCount(2, $folder->businessChecks()->get());
    }

    public function test_the_same_name_and_location_on_a_different_ci_date_is_not_treated_as_similar(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();

        $this->create($ci, $folder, 'token-2', ['ci_date' => now()->subDay()->toDateString()])
            ->assertOk()->assertJson(['result' => 'success']);

        $this->assertCount(2, $folder->businessChecks()->get());
    }

    /** Normalization is conservative: casing and stray whitespace still match, nothing fuzzier does. */
    public function test_casing_and_whitespace_differences_still_match_the_signature(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();

        $this->create($ci, $folder, 'token-2', [
            'business_name' => '  sari-sari   store ',
            'location' => ' Poblacion,  San Miguel, Bulacan ',
        ])->assertStatus(409)->assertJson(['result' => 'similar_exists']);

        $this->assertCount(1, $folder->businessChecks()->get());
    }

    /** Similarity never crosses a person or a folder boundary. */
    public function test_other_people_and_other_folders_never_trigger_the_warning(): void
    {
        [$ci, $folder] = $this->folder();
        [, $otherFolder] = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        // The identical signature saved four times over, for four different exact people.
        $this->create($ci, $folder, 'applicant-token')->assertOk();
        $this->create($ci, $folder, 'co-maker-a-token', ['co_maker_id' => $coMakerA->id])->assertOk();
        $this->create($ci, $folder, 'co-maker-b-token', ['co_maker_id' => $coMakerB->id])->assertOk();
        $this->create($ci, $otherFolder, 'other-folder-token')->assertOk();

        $this->assertCount(1, $folder->businessChecks()->whereNull('co_maker_id')->get());
        $this->assertCount(1, $folder->businessChecks()->where('co_maker_id', $coMakerA->id)->get());
        $this->assertCount(1, $folder->businessChecks()->where('co_maker_id', $coMakerB->id)->get());
        $this->assertCount(1, $otherFolder->businessChecks()->get());

        // And each person's own second attempt is still warned about, independently.
        $this->create($ci, $folder, 'co-maker-a-token-2', ['co_maker_id' => $coMakerA->id])
            ->assertStatus(409)->assertJson(['result' => 'similar_exists']);
    }

    /** A linked business keeps its hard block — and Continue Anyway must not open a hole in it. */
    public function test_the_linked_income_source_rule_stays_a_hard_block_that_continue_anyway_cannot_bypass(): void
    {
        [$ci, $folder] = $this->folder();
        $source = $this->businessSource($folder);

        $this->create($ci, $folder, 'linked-token-1', ['income_source_id' => $source->id])
            ->assertOk()->assertJson(['result' => 'success']);

        foreach ([[], ['allow_similar_duplicate' => '1']] as $index => $bypassAttempt) {
            $this->create($ci, $folder, 'fresh-linked-token-'.$index, $bypassAttempt + ['income_source_id' => $source->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('income_source_id');
        }

        $this->assertCount(1, $folder->businessChecks()->get());
        $this->assertSame(1, $this->createdAudits($folder));
    }

    public function test_the_same_request_token_replays_a_successful_linked_business_create(): void
    {
        [$ci, $folder] = $this->folder();
        $source = $this->businessSource($folder);
        $payload = ['income_source_id' => $source->id];

        $first = $this->create($ci, $folder, 'linked-replay-token', $payload);
        $replay = $this->create($ci, $folder, 'linked-replay-token', $payload);

        $first->assertOk()->assertJson(['result' => 'success']);
        $replay->assertOk()->assertJson(['result' => 'success']);
        $this->assertSame($first->json('return_url'), $replay->json('return_url'));
        $this->assertCount(1, $folder->businessChecks()->get());
        $this->assertDatabaseCount('business_check_photos', 1);
        $this->assertSame(1, $this->createdAudits($folder));
    }

    /** A genuinely different manual business is never obstructed. */
    public function test_a_different_manual_business_is_created_without_any_warning(): void
    {
        [$ci, $folder] = $this->folder();
        $this->create($ci, $folder, 'token-1')->assertOk();

        $this->create($ci, $folder, 'token-2', [
            'business_name' => 'HARDWARE STORE',
            'location' => 'Second Business Address',
        ])->assertOk()->assertJson(['result' => 'success']);

        $this->assertCount(2, $folder->businessChecks()->get());
        $this->assertSame(2, $this->createdAudits($folder));
    }

    private function createdAudits(ClientFolder $folder): int
    {
        return AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'business_check.created')->count();
    }

    private function create(User $ci, ClientFolder $folder, string $token, array $overrides = [])
    {
        $payload = array_merge([
            'request_token' => $token,
            'business_name' => 'SARI-SARI STORE',
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
        ], $overrides);

        return $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $payload, [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folder(?User $ci = null): array
    {
        $ci ??= User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return [$ci, $folder];
    }

    private function businessSource(ClientFolder $folder): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Linked Store',
            'business_name' => 'Linked Store',
        ]);
        $source->businessReport()->create(['business_name' => 'Linked Store', 'main_business_address' => 'Linked Address', 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
