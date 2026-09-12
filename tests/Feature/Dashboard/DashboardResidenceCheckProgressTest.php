<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RecordState;
use App\Models\BusinessCheck;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Regression coverage for the person-based Residence Check activity-progress bar. */
class DashboardResidenceCheckProgressTest extends TestCase
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
            'assigned_ci_id' => $this->ci->id,
            'created_by' => $this->ci->id,
            'display_name' => $name,
        ]);
    }

    private function check(ClientFolder $folder, ?int $coMakerId = null): ResidenceCheck
    {
        return ResidenceCheck::query()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'ci_date' => now()->toDateString(),
            'location' => 'Test residence',
            'ci_user_id' => $this->ci->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function bar(string $label = 'Residence Check'): array
    {
        $bars = collect(app(DashboardData::class)->for($this->ci)['activityProgress'])->keyBy('label');
        $this->assertArrayHasKey($label, $bars->all(), "The {$label} bar is missing.");

        return $bars[$label];
    }

    public function test_an_applicant_without_a_check_remains_in_the_denominator(): void
    {
        $this->folder('ALPHA, CLIENT');

        $this->assertSame(
            ['completed' => 0, 'applicable' => 1, 'percent' => 0],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_one_saved_applicant_check_satisfies_one_applicant(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->check($folder);

        $this->assertSame(
            ['completed' => 1, 'applicable' => 1, 'percent' => 100],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_multiple_applicant_checks_count_once(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $this->folder('BRAVO, CLIENT');
        $this->check($folder);
        $this->check($folder);
        $this->check($folder);

        $this->assertSame(3, ResidenceCheck::query()->count());
        $this->assertSame(
            ['completed' => 1, 'applicable' => 2, 'percent' => 50],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_a_co_maker_without_a_check_adds_one_unfinished_requirement(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);

        $this->assertSame(
            ['completed' => 0, 'applicable' => 2, 'percent' => 0],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_one_saved_co_maker_check_satisfies_only_that_co_maker(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->check($folder, $maria->id);

        $this->assertSame(
            ['completed' => 1, 'applicable' => 2, 'percent' => 50],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
        $this->assertFalse(ResidenceCheck::query()->whereNull('co_maker_id')->exists());
    }

    public function test_multiple_co_maker_checks_count_once(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->check($folder, $maria->id);
        $this->check($folder, $maria->id);

        $this->assertSame(2, ResidenceCheck::query()->where('co_maker_id', $maria->id)->count());
        $this->assertSame(1, $this->bar()['completed']);
        $this->assertSame(2, $this->bar()['applicable']);
    }

    public function test_applicant_and_each_co_maker_are_isolated_exact_people(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $maria = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $pedro = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);

        $this->check($folder);
        $this->assertSame(1, $this->bar()['completed'], 'Applicant must not satisfy either Co-Maker.');

        $this->check($folder, $maria->id);
        $this->assertSame(2, $this->bar()['completed'], 'Maria must not satisfy Pedro.');
        $this->assertSame(3, $this->bar()['applicable']);
        $this->assertFalse(ResidenceCheck::query()->where('co_maker_id', $pedro->id)->exists());
    }

    public function test_multiple_co_makers_each_contribute_a_separate_requirement(): void
    {
        $first = $this->folder('ALPHA, CLIENT');
        $second = $this->folder('BRAVO, CLIENT');
        CoMaker::query()->create(['client_folder_id' => $first->id, 'full_name' => 'FIRST CO-MAKER']);
        CoMaker::query()->create(['client_folder_id' => $second->id, 'full_name' => 'SECOND CO-MAKER']);
        CoMaker::query()->create(['client_folder_id' => $second->id, 'full_name' => 'THIRD CO-MAKER']);

        $this->assertSame(5, $this->bar()['applicable']);
        $this->assertSame(0, $this->bar()['completed']);
    }

    public function test_percentage_uses_required_people_and_existing_rounding(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'PEDRO DELA CRUZ']);
        $this->check($folder);

        $this->assertSame(
            ['completed' => 1, 'applicable' => 3, 'percent' => 33],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );
    }

    public function test_an_empty_workspace_is_zero_without_division_by_zero(): void
    {
        $this->assertSame(
            ['completed' => 0, 'applicable' => 0, 'percent' => 0],
            collect($this->bar())->only(['completed', 'applicable', 'percent'])->all(),
        );

        $this->actingAs($this->ci)->get(route('home'))->assertOk()
            ->assertSee('No applicable required Residence Checks yet');
    }

    public function test_the_card_renders_person_based_supporting_text(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        $this->check($folder);

        $this->actingAs($this->ci)->get(route('home'))->assertOk()
            ->assertSee('Residence Check')
            ->assertSee('1 of 2 required Residence Checks');
    }

    public function test_cibi_and_business_check_progress_are_unchanged(): void
    {
        $folder = $this->folder('ALPHA, CLIENT');
        $coMaker = CoMaker::query()->create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'ci_in_charge_id' => $this->ci->id,
            'state' => RecordState::Complete,
            'completed_at' => now(),
        ]);
        $income = IncomeSource::factory()->create([
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
            'income_source_id' => $income->id,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $this->ci->id,
        ])->save();

        $this->assertSame(
            ['completed' => 1, 'applicable' => 2, 'unit' => 'CI/BI Reports', 'percent' => 50],
            collect($this->bar('CI/BI Report'))->only(['completed', 'applicable', 'percent', 'unit'])->all(),
        );
        $this->assertSame(
            ['completed' => 1, 'applicable' => 1, 'unit' => 'businesses', 'percent' => 100],
            collect($this->bar('Business Check'))->only(['completed', 'applicable', 'percent', 'unit'])->all(),
        );
        $this->assertNotNull($coMaker->id);
    }
}
