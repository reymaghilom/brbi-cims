<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderOverview;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Client Folder Overview "CI Activities" and "Residence & Business Report" cards follow the same
 * mandatory requirements as folder progress (MandatoryInvestigationRequirements), for the exact person
 * being viewed. Missing activities count as pending without any row being created, and optional
 * Asset / custom activities never enter the required denominator.
 */
class ClientFolderOverviewModuleCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    public function test_applicant_with_no_mandatory_activity_rows_is_not_started_without_fabricating_rows(): void
    {
        $folder = $this->folder();

        $this->assertCard($folder, null, 'activities', 'not_started', '0 of 3 required activities completed; 3 pending.');

        // Opening the Overview itself never creates anything either.
        $this->actingAs($this->ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee('0 of 3 required activities completed; 3 pending.');
        $this->assertSame(0, CiActivity::query()->count());
        $this->assertSame(0, ResidenceCheck::query()->count());
        $this->assertSame(0, BusinessCheck::query()->count());
    }

    public function test_applicant_card_counts_only_barangay_neighbor_and_bank_coop(): void
    {
        $folder = $this->folder();

        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->assertCard($folder, null, 'activities', 'in_progress', '1 of 3 required activities completed; 2 pending.');

        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed);
        $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Completed);
        $this->assertCard($folder, null, 'activities', 'completed', '3 of 3 required activities completed; 0 pending.');

        // Optional Asset (pending) and a custom activity never change the fixed denominator.
        $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending);
        $custom = ActivityDefinition::query()->create(['code' => 'custom_employer_call_abc', 'name' => 'Employer Call', 'is_required' => false, 'is_active' => true]);
        CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'activity_definition_id' => $custom->id,
            'name' => $custom->name, 'status' => ActivityStatus::Pending, 'creator_id' => $this->ci->id,
        ]);
        $this->assertCard($folder, null, 'activities', 'completed', '3 of 3 required activities completed; 0 pending.');
    }

    public function test_a_started_but_unfinished_requirement_is_in_progress_and_pending_rows_alone_are_not_started(): void
    {
        $folder = $this->folder();

        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);
        $this->assertCard($folder, null, 'activities', 'not_started', '0 of 3 required activities completed; 3 pending.');

        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::FollowUp);
        $this->assertCard($folder, null, 'activities', 'in_progress', '0 of 3 required activities completed; 3 pending.');
    }

    public function test_co_maker_card_has_exactly_two_requirements_and_every_person_stays_isolated(): void
    {
        $folder = $this->folder();
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed, $coMakerA->id);
        // Bank / Coop is not a Co-Maker requirement, so it never counts for them.
        $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Completed, $coMakerA->id);

        $this->assertCard($folder, $coMakerA, 'activities', 'in_progress', '1 of 2 required activities completed; 1 pending.');
        $this->assertCard($folder, $coMakerB, 'activities', 'not_started', '0 of 2 required activities completed; 2 pending.');
        $this->assertCard($folder, null, 'activities', 'not_started', '0 of 3 required activities completed; 3 pending.');

        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed, $coMakerA->id);
        $this->assertCard($folder, $coMakerA, 'activities', 'completed', '2 of 2 required activities completed; 0 pending.');
        $this->assertCard($folder, $coMakerB, 'activities', 'not_started', '0 of 2 required activities completed; 2 pending.');

        $this->actingAs($this->ci)->get(route('client-folders.show', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]))->assertOk()
            ->assertSee('2 of 2 required activities completed; 0 pending.');
    }

    public function test_applicant_residence_and_business_card_needs_both_checks(): void
    {
        $residenceOnly = $this->folder();
        $this->residenceCheck($residenceOnly, null);
        $this->assertCard($residenceOnly, null, 'residence-business', 'in_progress');

        $businessOnly = $this->folder();
        $this->businessCheck($businessOnly, null);
        $this->assertCard($businessOnly, null, 'residence-business', 'in_progress');

        $both = $this->folder();
        $this->residenceCheck($both, null);
        $this->businessCheck($both, null);
        $this->assertCard($both, null, 'residence-business', 'completed');

        $this->assertCard($this->folder(), null, 'residence-business', 'not_started');
    }

    public function test_co_maker_residence_and_business_card_needs_only_the_residence_check(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $other = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $this->assertCard($folder, $coMaker, 'residence-business', 'not_started');

        // A Business Check alone is not a Co-Maker requirement.
        $this->businessCheck($folder, $coMaker->id);
        $this->assertCard($folder, $coMaker, 'residence-business', 'not_started');

        $this->residenceCheck($folder, $coMaker->id);
        $this->assertCard($folder, $coMaker, 'residence-business', 'completed');

        // Neither the Applicant nor Co-Maker B borrows Co-Maker A's checks.
        $this->assertCard($folder, null, 'residence-business', 'not_started');
        $this->assertCard($folder, $other, 'residence-business', 'not_started');
    }

    public function test_the_overview_header_progress_reads_the_stored_folder_progress_with_a_fixed_query_budget(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->residenceCheck($folder, $coMaker->id);
        $folder->forceFill(['progress_percent' => 18.18])->save();

        $overview = app(ClientFolderOverview::class);
        foreach ([null, $coMaker] as $person) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $data = $overview->for($folder, $person);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            // The header shows the stored authoritative progress (written by ClientProgressService).
            $this->assertSame(18.18, $data['progress']['percentage']);
            // 8: the unused legacy ClientCompletionResult read and the five unused activity counts
            // were removed (was 9 queries); the mandatory CI Activity card adds exactly one query.
            $this->assertLessThanOrEqual(8, $queries);
        }

        $this->actingAs($this->ci)->get(route('client-folders.show', $folder))->assertOk();
        $this->assertSame(1, CiActivity::query()->count(), 'Opening the Overview creates nothing.');
    }

    private function assertCard(ClientFolder $folder, ?CoMaker $person, string $key, string $state, ?string $description = null): void
    {
        $module = collect(app(ClientFolderOverview::class)->for($folder, $person)['modules'])->firstWhere('key', $key);

        $this->assertSame($state, $module['state'], "{$key} state for ".($person?->full_name ?? 'Applicant'));
        if ($description !== null) {
            $this->assertSame($description, $module['description']);
        }
    }

    private function folder(): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
    }

    private function activity(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $definition->id,
            'name' => $definition->name, 'status' => $status,
            'scheduled_at' => $status === ActivityStatus::Scheduled ? now()->addDay() : null,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null, 'creator_id' => $this->ci->id,
        ]);
    }

    private function residenceCheck(ClientFolder $folder, ?int $coMakerId): void
    {
        (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
    }

    private function businessCheck(ClientFolder $folder, ?int $coMakerId): void
    {
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'income_source_id' => null,
            'business_name' => 'Manual Store', 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
    }
}
