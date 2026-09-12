<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use App\Services\Progress\MandatoryInvestigationRequirements;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Regression coverage for the obligation-based CI Activities dashboard bar. */
class DashboardCiActivitiesProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    private function folder(string $name = 'ALPHA, CLIENT'): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $this->ci->id,
            'created_by' => $this->ci->id,
            'display_name' => $name,
        ]);
    }

    private function activity(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->firstOrFail();
        $activity = (new CiActivity)->forceFill([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => $status,
            'creator_id' => $this->ci->id,
            'updated_by' => $this->ci->id,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
        ]);
        $activity->save();

        return $activity;
    }

    /** @return array<string, mixed> */
    private function bar(string $label = 'CI Activities'): array
    {
        $bars = collect(app(DashboardData::class)->for($this->ci)['activityProgress'])->keyBy('label');
        $this->assertArrayHasKey($label, $bars->all(), "The {$label} bar is missing.");

        return $bars[$label];
    }

    public function test_missing_applicant_barangay_and_neighbor_rows_remain_required(): void
    {
        $folder = $this->folder();

        $this->assertSame(
            ['completed' => 0, 'applicable' => 3, 'percent' => 0],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );

        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->assertSame(1, $this->bar()['completed']);

        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed);
        $this->assertSame(2, $this->bar()['completed']);
        $this->assertSame(3, $this->bar()['applicable']);
    }

    public function test_each_co_maker_adds_separate_barangay_and_neighbor_requirements(): void
    {
        $folder = $this->folder();
        $maria = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);

        $this->assertSame(7, $this->bar()['applicable']);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed, $maria->id);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed, $maria->id);

        $this->assertSame(2, $this->bar()['completed'], 'Maria must not satisfy Applicant or Pedro obligations.');
        $this->assertFalse(CiActivity::query()->where('co_maker_id', $pedro->id)->exists());
        $this->assertFalse(CiActivity::query()->whereNull('co_maker_id')->exists());
    }

    public function test_applicant_completion_never_satisfies_a_co_maker(): void
    {
        $folder = $this->folder();
        CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed);

        $this->assertSame(2, $this->bar()['completed']);
        $this->assertSame(5, $this->bar()['applicable']);
    }

    public function test_bank_coop_is_one_applicant_obligation_with_target_derived_parent_completion(): void
    {
        $folder = $this->folder();
        $bank = $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Pending);
        foreach ([ActivityStatus::Completed, ActivityStatus::Pending] as $index => $status) {
            CiActivityBankTarget::query()->create([
                'ci_activity_id' => $bank->id,
                'inquiry_type' => $index === 0
                    ? CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK
                    : CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
                'institution_name' => 'Institution '.$index,
                'status' => $status,
                'created_by' => $this->ci->id,
                'updated_by' => $this->ci->id,
            ]);
        }

        $bank->update(['status' => CiActivityBankTarget::deriveParentStatus($bank->bankTargets()->pluck('status'))]);
        $this->assertSame(0, $this->bar()['completed']);

        $bank->bankTargets()->update(['status' => ActivityStatus::Completed->value]);
        $bank->update(['status' => CiActivityBankTarget::deriveParentStatus($bank->bankTargets()->pluck('status'))]);
        $this->assertSame(1, $this->bar()['completed'], 'All targets complete satisfy one Bank / Coop parent obligation.');
        $this->assertSame(3, $this->bar()['applicable']);
    }

    public function test_co_maker_bank_coop_is_not_a_mandatory_obligation(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Completed, $coMaker->id);

        $this->assertSame(5, $this->bar()['applicable']);
        $this->assertSame(0, $this->bar()['completed']);
        $this->assertArrayNotHasKey('bank_coop_check', MandatoryInvestigationRequirements::CO_MAKER);
    }

    public function test_optional_asset_work_never_changes_the_bar(): void
    {
        $folder = $this->folder();
        $before = $this->bar();
        $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Completed);

        $this->assertSame($before, $this->bar());
        $this->assertArrayNotHasKey('asset_check', MandatoryInvestigationRequirements::APPLICANT);
        $this->assertArrayNotHasKey('asset_check', MandatoryInvestigationRequirements::CO_MAKER);
    }

    public function test_duplicate_completed_rows_count_once_per_logical_obligation(): void
    {
        $folder = $this->folder();
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);

        $this->assertSame(3, CiActivity::query()->count());
        $this->assertSame(1, $this->bar()['completed']);
        $this->assertSame(3, $this->bar()['applicable']);
    }

    public function test_percentage_uses_all_required_person_obligations(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed, $coMaker->id);

        $this->assertSame(
            ['completed' => 2, 'applicable' => 5, 'percent' => 40],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_zero_denominator_is_safe(): void
    {
        $this->assertSame(
            ['completed' => 0, 'applicable' => 0, 'percent' => 0],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_visible_label_and_supporting_text_are_corrected(): void
    {
        $folder = $this->folder();
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed);

        $response = $this->actingAs($this->ci)->get(route('home'))->assertOk();
        $response->assertSee('CI Activities')
            ->assertSee('1 of 3 required activities')
            ->assertDontSee('CI Activities (Supporting Proof)');
    }

    public function test_other_three_progress_metrics_remain_unchanged(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'ci_in_charge_id' => $this->ci->id,
            'state' => RecordState::Complete,
            'completed_at' => now(),
        ]);
        ResidenceCheck::query()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $this->ci->id,
        ]);
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'income_source_template_id' => IncomeSourceTemplate::query()
                ->where('template_type', 'business_source_validation')
                ->where('version', 1)
                ->value('id'),
        ]);
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $this->ci->id,
        ])->save();

        $this->assertSame(['completed' => 1, 'applicable' => 2, 'percent' => 50], collect($this->bar('CI/BI Report'))->only(['completed', 'applicable', 'percent'])->all());
        $this->assertSame(['completed' => 1, 'applicable' => 2, 'percent' => 50], collect($this->bar('Residence Check'))->only(['completed', 'applicable', 'percent'])->all());
        $this->assertSame(['completed' => 1, 'applicable' => 1, 'percent' => 100], collect($this->bar('Business Check'))->only(['completed', 'applicable', 'percent'])->all());
        $this->assertNotNull($coMaker->id);
    }
}
