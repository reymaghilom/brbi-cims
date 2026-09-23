<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Focused coverage for Business Check's existing-business dropdown eligibility. */
class BusinessCheckSurvivingBusinessSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_only_current_saved_business_reports_are_eligible(): void
    {
        [$ci, $folder] = $this->folderWithCi('APPLICANT CLIENT');
        $saved = $this->savedBusiness($ci, $folder, 'Saved Store');
        $draft = $this->draftBusiness($ci, $folder, 'Draft Store', null, 'leasing_non_agricultural');

        $this->assertSame([$saved->id], $this->offeredIds($ci, $folder));

        app(DeleteBusinessReport::class)->execute($ci, $folder, $saved->fresh());

        $this->assertNotNull(IncomeSource::query()->find($saved->id), 'The IncomeSource intentionally survives report-only deletion.');
        $this->assertSame([], $this->offeredIds($ci, $folder));
        $this->assertNotContains($draft->id, $this->offeredIds($ci, $folder));
        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'income_source_id' => $saved->id]))
            ->assertNotFound();
    }

    public function test_an_existing_check_excludes_only_its_business_and_deleting_the_check_makes_it_eligible_again(): void
    {
        [$ci, $folder] = $this->folderWithCi('MULTIPLE BUSINESS CLIENT');
        $businessA = $this->savedBusiness($ci, $folder, 'Business A');
        $businessB = $this->savedBusiness($ci, $folder, 'Business B', null, 'leasing_non_agricultural');

        $this->assertSame([$businessA->id, $businessB->id], $this->offeredIds($ci, $folder));

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null,
            'co_maker_id' => null,
            'income_source_id' => $businessA->id,
            'ci_date' => '2026-09-02',
            'location' => 'Business A Address',
        ]);

        $this->assertSame([$businessB->id], $this->offeredIds($ci, $folder));

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertSame([$businessA->id, $businessB->id], $this->offeredIds($ci, $folder));

        app(DeleteBusinessReport::class)->execute($ci, $folder, $businessA->fresh());

        $this->assertSame([$businessB->id], $this->offeredIds($ci, $folder));
    }

    public function test_applicant_and_each_co_maker_remain_exactly_isolated(): void
    {
        [$ci, $folder] = $this->folderWithCi('ISOLATION CLIENT');
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $applicant = $this->savedBusiness($ci, $folder, 'Applicant Store');
        $businessA = $this->savedBusiness($ci, $folder, 'Co-Maker A Store', $coMakerA);
        $businessB = $this->savedBusiness($ci, $folder, 'Co-Maker B Store', $coMakerB);

        $this->assertSame([$applicant->id], $this->offeredIds($ci, $folder));
        $this->assertSame([$businessA->id], $this->offeredIds($ci, $folder, $coMakerA));
        $this->assertSame([$businessB->id], $this->offeredIds($ci, $folder, $coMakerB));

        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null,
            'co_maker_id' => $coMakerA->id,
            'income_source_id' => $businessA->id,
            'ci_date' => '2026-09-02',
            'location' => 'Co-Maker A Address',
        ]);

        $this->assertSame([$applicant->id], $this->offeredIds($ci, $folder));
        $this->assertSame([], $this->offeredIds($ci, $folder, $coMakerA));
        $this->assertSame([$businessB->id], $this->offeredIds($ci, $folder, $coMakerB));
    }

    /** @return list<int> */
    private function offeredIds(User $ci, ClientFolder $folder, ?CoMaker $coMaker = null): array
    {
        $query = $coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : [];
        $businesses = collect($this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder] + $query))
            ->assertOk()
            ->viewData('businesses'));
        $ids = $businesses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folderWithCi(string $name): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => $name,
        ])];
    }

    private function draftBusiness(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null, string $templateType = 'retail_grocery_water_refilling'): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', $templateType)->firstOrFail();

        return app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ])->fresh();
    }

    private function savedBusiness(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null, string $templateType = 'retail_grocery_water_refilling'): IncomeSource
    {
        $source = $this->draftBusiness($ci, $folder, $name, $coMaker, $templateType);
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, [
            'intent' => 'complete',
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->revision,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
        ]);

        return $source->fresh();
    }
}
