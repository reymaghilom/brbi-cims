<?php

namespace Tests\Feature\Dashboard;

use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CI Activity Progress -> "CI/BI Report".
 *
 * A CI/BI Report is required once PER PERSON: the Applicant of every folder in scope, plus every
 * existing Co-Maker (MandatoryInvestigationRequirements lists 'cibi' under both). The denominator
 * therefore counts PEOPLE, not cibi_reports rows - a person whose report has not been created yet
 * is precisely the outstanding work the bar exists to show.
 *
 * The numerator follows the same rule: at most ONE completed report per logical person
 * (client_folder_id + co_maker_id, with NULL meaning the Applicant). That is not something the
 * composite unique index can be trusted to enforce - SQL treats NULLs as distinct inside a UNIQUE
 * index, so a folder can hold several Applicant rows - which is why the count groups by person
 * instead of counting rows.
 *
 * The bar previously divided completed rows by EXISTING rows, so it read 100% whenever every
 * report that happened to exist was finished, no matter how many folders had none at all. These
 * tests pin the corrected contract.
 */
class DashboardCiBiProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    private function folder(string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id, 'display_name' => $name,
        ]);
    }

    private function cibi(ClientFolder $folder, ?int $coMakerId, RecordState $state): CibiReport
    {
        return CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
            'ci_in_charge_id' => $this->ci->id, 'state' => $state,
            'completed_at' => $state === RecordState::Complete ? now() : null,
        ]);
    }

    /** The CI/BI bar exactly as the Dashboard renders it. */
    private function bar(): array
    {
        $bars = collect(app(DashboardData::class)->for($this->ci)['activityProgress'])->keyBy('label');
        $this->assertArrayHasKey('CI/BI Report', $bars->all(), 'The CI/BI bar is missing.');

        return $bars['CI/BI Report'];
    }

    // =====================================================================================
    // The denominator counts people, including people with no report yet
    // =====================================================================================

    public function test_an_applicant_with_no_cibi_record_still_counts_toward_the_requirement(): void
    {
        $this->folder('ALPHA, CLIENT');
        $this->folder('BRAVO, CLIENT');
        $this->folder('CHARLIE, CLIENT');

        $bar = $this->bar();

        // Three Applicants, no reports created at all: nothing done, three still required.
        $this->assertSame(0, CibiReport::query()->count());
        $this->assertSame(['completed' => 0, 'applicable' => 3, 'percent' => 0], collect($bar)->only(['completed', 'applicable', 'percent'])->all());
    }

    public function test_only_a_completed_record_counts_toward_the_numerator(): void
    {
        $done = $this->folder('ALPHA, CLIENT');
        $draft = $this->folder('BRAVO, CLIENT');
        $none = $this->folder('CHARLIE, CLIENT');

        $this->cibi($done, null, RecordState::Complete);
        $this->cibi($draft, null, RecordState::Draft);

        $bar = $this->bar();

        // A merely-created record is outstanding work, not progress.
        $this->assertSame(2, CibiReport::query()->count());
        $this->assertSame(1, $bar['completed']);
        $this->assertSame(3, $bar['applicable']);
        $this->assertSame(33, $bar['percent']);
        $this->assertNotNull($none->id);
    }

    /** The exact shape of the bug: every EXISTING report finished, most folders untouched. */
    public function test_all_existing_records_complete_no_longer_reads_one_hundred_percent(): void
    {
        $withReports = collect(range(1, 3))->map(fn (int $i) => $this->folder('WITH '.$i.', CLIENT'));
        collect(range(1, 7))->each(fn (int $i) => $this->folder('WITHOUT '.$i.', CLIENT'));
        $withReports->each(fn (ClientFolder $folder) => $this->cibi($folder, null, RecordState::Complete));

        $bar = $this->bar();

        // Old formula: 3 complete / 3 existing = 100%. Corrected: 3 of 10 people = 30%.
        $this->assertSame(3, CibiReport::query()->count());
        $this->assertSame(10, ClientFolder::query()->count());
        $this->assertSame(3, $bar['completed']);
        $this->assertSame(10, $bar['applicable']);
        $this->assertSame(30, $bar['percent']);
    }

    // =====================================================================================
    // Co-Makers each add exactly one requirement
    // =====================================================================================

    public function test_each_co_maker_adds_one_requirement_and_is_counted_separately(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);

        // One folder, three people: Applicant + two Co-Makers.
        $this->assertSame(['completed' => 0, 'applicable' => 3, 'percent' => 0], collect($this->bar())->only(['completed', 'applicable', 'percent'])->all());

        // The Applicant finishes: 1 of 3.
        $this->cibi($folder, null, RecordState::Complete);
        $this->assertSame(1, $this->bar()['completed']);
        $this->assertSame(3, $this->bar()['applicable']);

        // One Co-Maker finishes: 2 of 3 — the other Co-Maker is still outstanding.
        $this->cibi($folder, $maria->id, RecordState::Complete);
        $this->assertSame(2, $this->bar()['completed']);
        $this->assertSame(3, $this->bar()['applicable']);
        $this->assertSame(67, $this->bar()['percent']);

        // And the last one: 3 of 3.
        $this->cibi($folder, $pedro->id, RecordState::Complete);
        $this->assertSame(['completed' => 3, 'applicable' => 3, 'percent' => 100], collect($this->bar())->only(['completed', 'applicable', 'percent'])->all());
    }

    public function test_a_co_maker_record_never_satisfies_the_applicant_or_another_co_maker(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);

        // Only MARIA's report is finished.
        $this->cibi($folder, $maria->id, RecordState::Complete);

        $this->assertSame(1, $this->bar()['completed'], 'One person done, not the whole folder.');
        $this->assertSame(3, $this->bar()['applicable']);

        // The persisted rows stay strictly separated by their own exact person.
        $this->assertTrue(CibiReport::query()->where('co_maker_id', $maria->id)->where('state', RecordState::Complete->value)->exists());
        $this->assertFalse(CibiReport::query()->where('co_maker_id', $pedro->id)->exists());
        $this->assertFalse(CibiReport::query()->whereNull('co_maker_id')->exists());
    }

    public function test_co_makers_in_different_folders_each_add_their_own_requirement(): void
    {
        $first = $this->folder('ALPHA, CLIENT');
        $second = $this->folder('BRAVO, CLIENT');
        CoMaker::create(['client_folder_id' => $first->id, 'full_name' => 'FIRST CO-MAKER']);
        CoMaker::create(['client_folder_id' => $second->id, 'full_name' => 'SECOND CO-MAKER']);
        CoMaker::create(['client_folder_id' => $second->id, 'full_name' => 'THIRD CO-MAKER']);

        // 2 Applicants + 3 Co-Makers = 5 required, and the denominator legitimately exceeds the
        // Client Folder count.
        $this->assertSame(5, $this->bar()['applicable']);
        $this->assertSame(2, ClientFolder::query()->count());
    }

    // =====================================================================================
    // At most one completed report per logical person
    // =====================================================================================

    /**
     * First, prove the gap is real rather than theoretical: the composite unique index on
     * (client_folder_id, co_maker_id) does NOT stop a folder holding several Applicant rows,
     * because SQL treats NULL as distinct inside a UNIQUE index. If a future schema change ever
     * does close that hole this test will fail loudly, which is the right outcome - it would mean
     * the dedup below is no longer load-bearing.
     */
    public function test_duplicate_applicant_rows_are_constructible_despite_the_unique_index(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');

        $this->cibi($folder, null, RecordState::Complete);
        $this->cibi($folder, null, RecordState::Complete);

        $this->assertSame(2, CibiReport::query()->whereNull('co_maker_id')->count());
    }

    public function test_an_applicant_with_duplicate_completed_rows_still_counts_once(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->folder('BRAVO, CLIENT');

        // Three completed rows, one logical Applicant.
        $this->cibi($folder, null, RecordState::Complete);
        $this->cibi($folder, null, RecordState::Complete);
        $this->cibi($folder, null, RecordState::Complete);

        $bar = $this->bar();

        $this->assertSame(3, CibiReport::query()->where('state', RecordState::Complete->value)->count(), 'Three raw rows...');
        $this->assertSame(1, $bar['completed'], '...but one completed person.');
        $this->assertSame(2, $bar['applicable']);
        $this->assertSame(50, $bar['percent']);
    }

    /**
     * The other half of the asymmetry: for a non-NULL co_maker_id the composite unique index DOES
     * bite, so the database itself already guarantees one row per Co-Maker. Grouping by person is
     * therefore defence-in-depth on the Co-Maker side and load-bearing on the Applicant side - and
     * this test documents which is which, so nobody later "simplifies" the count back to rows.
     */
    public function test_duplicate_co_maker_rows_are_rejected_by_the_database_itself(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->cibi($folder, $maria->id, RecordState::Complete);

        try {
            $this->cibi($folder, $maria->id, RecordState::Complete);
            $this->fail('A second CI/BI row for the same Co-Maker must violate the composite unique index.');
        } catch (UniqueConstraintViolationException) {
            // Expected: (client_folder_id, co_maker_id) is unique when co_maker_id is not NULL.
        }

        $this->assertSame(1, CibiReport::query()->where('co_maker_id', $maria->id)->count());
        $this->assertSame(1, $this->bar()['completed']);
        $this->assertSame(2, $this->bar()['applicable'], 'Applicant + one Co-Maker.');
    }

    public function test_duplicates_never_let_the_numerator_exceed_the_requirement(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);

        // Two Applicant rows and one Co-Maker row: three rows, exactly two people.
        $this->cibi($folder, null, RecordState::Complete);
        $this->cibi($folder, null, RecordState::Complete);
        $this->cibi($folder, $maria->id, RecordState::Complete);

        $bar = $this->bar();

        $this->assertSame(3, CibiReport::query()->count());
        $this->assertSame(['completed' => 2, 'applicable' => 2, 'percent' => 100], collect($bar)->only(['completed', 'applicable', 'percent'])->all());
        $this->assertLessThanOrEqual($bar['applicable'], $bar['completed'], 'The bar can never exceed 100%.');
    }

    public function test_a_duplicate_draft_row_never_promotes_an_unfinished_person(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');

        // Two rows for the same Applicant, neither finished.
        $this->cibi($folder, null, RecordState::Draft);
        $this->cibi($folder, null, RecordState::Draft);

        $bar = $this->bar();

        $this->assertSame(2, CibiReport::query()->count());
        $this->assertSame(0, $bar['completed'], 'Drafts are outstanding work, however many rows exist.');
        $this->assertSame(1, $bar['applicable']);
        $this->assertSame(0, $bar['percent']);
    }

    /** Duplicates in one folder must not bleed across folders or people. */
    public function test_duplicates_stay_inside_their_own_exact_person(): void
    {
        $first = $this->folder('ALPHA, CLIENT');
        $second = $this->folder('BRAVO, CLIENT');
        $maria = CoMaker::create(['client_folder_id' => $first->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::create(['client_folder_id' => $first->id, 'full_name' => 'PEDRO DELA CRUZ']);

        // ALPHA's Applicant is duplicated and finished; PEDRO is finished; MARIA and BRAVO's
        // Applicant have nothing at all.
        $this->cibi($first, null, RecordState::Complete);
        $this->cibi($first, null, RecordState::Complete);
        $this->cibi($first, $pedro->id, RecordState::Complete);

        $bar = $this->bar();

        // Four people (2 Applicants + 2 Co-Makers), two of them done.
        $this->assertSame(4, $bar['applicable']);
        $this->assertSame(2, $bar['completed']);
        $this->assertSame(50, $bar['percent']);
        // The duplication never reached MARIA or the other folder's Applicant.
        $this->assertFalse(CibiReport::query()->where('co_maker_id', $maria->id)->exists());
        $this->assertFalse(CibiReport::query()->where('client_folder_id', $second->id)->exists());
    }

    // =====================================================================================
    // Generated output files never move the bar
    // =====================================================================================

    public function test_repeated_generated_output_versions_never_inflate_the_numerator(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->folder('BRAVO, CLIENT');
        $this->cibi($folder, null, RecordState::Complete);

        foreach (range(1, 6) as $version) {
            GeneratedReport::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => null, 'scope_key' => 'folder:'.$folder->id.':cibi',
                'source_type' => 'cibi', 'report_type' => 'cibi', 'format' => 'pdf', 'version' => $version,
                'status' => GenerationStatus::Completed, 'generated_by' => $this->ci->id,
            ]);
        }

        $this->assertSame(6, GeneratedReport::query()->count());
        $this->assertSame(['completed' => 1, 'applicable' => 2, 'percent' => 50], collect($this->bar())->only(['completed', 'applicable', 'percent'])->all());
    }

    // =====================================================================================
    // Empty state and rendered copy
    // =====================================================================================

    public function test_an_empty_workspace_reports_no_applicable_work_without_dividing_by_zero(): void
    {
        $bar = $this->bar();

        $this->assertSame(0, $bar['applicable']);
        $this->assertSame(0, $bar['completed']);
        $this->assertSame(0, $bar['percent']);

        $this->actingAs($this->ci)->get(route('home'))->assertOk()
            ->assertSee('No applicable CI/BI Reports yet');
    }

    public function test_the_card_renders_the_new_label_and_supporting_wording(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->cibi($folder, null, RecordState::Complete);

        $response = $this->actingAs($this->ci)->get(route('home'))->assertOk();

        $response->assertSee('CI Activity Progress')
            ->assertSee('CI/BI Report')
            ->assertSee('1 of 2 CI/BI Reports')
            ->assertDontSee('CIBI Investigation')
            ->assertDontSee('CI/BI records');
        // The bar's accessible label carries the same percentage the row shows.
        $response->assertSee('CI/BI Report: 50 percent complete.', false);
    }

    // =====================================================================================
    // Scope is untouched
    // =====================================================================================

    public function test_the_bar_uses_the_existing_shared_workspace_folder_scope(): void
    {
        $otherCi = User::factory()->create();
        $this->folder('MINE, CLIENT');
        // An active folder carrying another investigator's name is still shared workspace work,
        // exactly as every other folder-level Dashboard metric counts it.
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'created_by' => $otherCi->id, 'display_name' => 'THEIRS, CLIENT']);

        $data = app(DashboardData::class)->for($this->ci);

        $this->assertSame(2, $data['summary']['assigned']);
        $this->assertSame(2, collect($data['activityProgress'])->keyBy('label')['CI/BI Report']['applicable']);

        // A trashed folder is out of scope for the KPI and must be out of scope here too.
        ClientFolder::query()->where('display_name', 'THEIRS, CLIENT')->first()->delete();
        $after = app(DashboardData::class)->for($this->ci);
        $this->assertSame(1, $after['summary']['assigned']);
        $this->assertSame(1, collect($after['activityProgress'])->keyBy('label')['CI/BI Report']['applicable']);
    }
}
