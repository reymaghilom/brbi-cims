<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Actions\ClientFolders\RestoreBusinessPair;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PairedBusinessRecycleBinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_report_delete_and_restore_are_exactly_scoped(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Applicant Folder');
        $otherFolder = $this->folderFor($ci, 'Other Folder');
        $coMaker = $this->coMaker($folder, 'Co-Maker A');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Applicant Business A');
        [$otherBusiness, $otherReport, $otherCheck] = $this->pair($folder, $ci, null, 'Applicant Business B');
        [$coMakerBusiness, $coMakerReport, $coMakerCheck] = $this->pair($folder, $ci, $coMaker, 'Co-Maker Business');
        [$otherFolderBusiness, $otherFolderReport, $otherFolderCheck] = $this->pair($otherFolder, $ci, null, 'Other Folder Business');

        app(DeleteIncomeSource::class)->execute($ci, $folder, $source);

        $this->assertPairTrashed($source, $report, $check);
        $this->assertPairActive($otherBusiness, $otherReport, $otherCheck);
        $this->assertPairActive($coMakerBusiness, $coMakerReport, $coMakerCheck);
        $this->assertPairActive($otherFolderBusiness, $otherFolderReport, $otherFolderCheck);

        app(RestoreBusinessPair::class)->execute($ci, $folder, IncomeSource::withTrashed()->findOrFail($source->id));

        $this->assertPairActive($source, $report, $check);
        $this->assertSame(1, IncomeSource::withTrashed()->whereKey($source->id)->count());
    }

    public function test_applicant_check_delete_and_restore_keep_the_same_income_source_id(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Applicant Check Folder');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Applicant Check Business');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);
        $this->assertPairTrashed($source, $report, $check);

        app(RestoreBusinessPair::class)->execute($ci, $folder, IncomeSource::withTrashed()->findOrFail($source->id));

        $this->assertPairActive($source, $report, $check);
        $this->assertSame($source->id, $check->income_source_id);
        $this->assertSame(1, IncomeSource::withTrashed()->whereKey($source->id)->count());
    }

    public function test_co_maker_report_delete_and_restore_never_touch_applicant_or_another_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Co-Maker Report Folder');
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        [$applicantSource, $applicantReport, $applicantCheck] = $this->pair($folder, $ci, null, 'Applicant Business');
        [$sourceA, $reportA, $checkA] = $this->pair($folder, $ci, $coMakerA, 'Co-Maker A Business');
        [$sourceB, $reportB, $checkB] = $this->pair($folder, $ci, $coMakerB, 'Co-Maker B Business');

        app(DeleteIncomeSource::class)->execute($ci, $folder, $sourceA);

        $this->assertPairTrashed($sourceA, $reportA, $checkA);
        $this->assertPairActive($applicantSource, $applicantReport, $applicantCheck);
        $this->assertPairActive($sourceB, $reportB, $checkB);

        app(RestoreBusinessPair::class)->execute($ci, $folder, IncomeSource::withTrashed()->findOrFail($sourceA->id));
        $this->assertPairActive($sourceA, $reportA, $checkA);
    }

    public function test_co_maker_check_delete_and_restore_are_exactly_scoped(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Co-Maker Check Folder');
        $otherFolder = $this->folderFor($ci, 'Unrelated Folder');
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        [$sourceA, $reportA, $checkA] = $this->pair($folder, $ci, $coMakerA, 'Co-Maker A Business');
        [$sourceB, $reportB, $checkB] = $this->pair($folder, $ci, $coMakerB, 'Co-Maker B Business');
        [$otherSource, $otherReport, $otherCheck] = $this->pair($otherFolder, $ci, null, 'Unrelated Folder Business');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $checkA);

        $this->assertPairTrashed($sourceA, $reportA, $checkA);
        $this->assertPairActive($sourceB, $reportB, $checkB);
        $this->assertPairActive($otherSource, $otherReport, $otherCheck);

        app(RestoreBusinessPair::class)->execute($ci, $folder, IncomeSource::withTrashed()->findOrFail($sourceA->id));
        $this->assertPairActive($sourceA, $reportA, $checkA);
    }

    public function test_a_failure_inside_paired_delete_rolls_back_all_three_records(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Rollback Folder');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Rollback Business');

        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class, function ($mock): void {
            $mock->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Simulated paired delete failure.'));
        });

        try {
            app(DeleteIncomeSource::class)->execute($ci, $folder, $source);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated paired delete failure.', $exception->getMessage());
        }

        $this->assertPairActive($source, $report, $check);
    }

    public function test_recycle_bin_represents_the_exact_business_root_and_restores_it(): void
    {
        $ci = User::factory()->create();
        $administrator = User::factory()->administrator()->create();
        $folder = $this->folderFor($ci, 'Recycle Display Folder');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Recycle Display Business');
        app(DeleteIncomeSource::class)->execute($ci, $folder, $source);

        $this->actingAs($administrator)->get(route('recycle-bin.index'))
            ->assertOk()
            ->assertSee('Recycle Display Business')
            ->assertSee('Applicant')
            ->assertSee('Business ID '.$source->id)
            ->assertSee('Restore business');

        $this->actingAs($administrator)
            ->patch(route('recycle-bin.businesses.restore', IncomeSource::withTrashed()->findOrFail($source->id)))
            ->assertRedirect(route('recycle-bin.index'));

        $this->assertPairActive($source, $report, $check);
    }

    private function folderFor(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => $name,
        ]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return $folder->coMakers()->create([
            'full_name' => $name,
            'first_name' => $name,
            'last_name' => 'Test',
        ]);
    }

    private function pair(ClientFolder $folder, User $ci, ?CoMaker $coMaker, string $name): array
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMaker?->id,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
            'created_by' => $ci->id,
        ]);
        $report = $source->businessReport()->create([
            'business_name' => $name,
            'report_category' => 'retail_grocery_water_refilling',
            'main_business_address' => $name.' Address',
        ]);
        $check = $folder->businessChecks()->create([
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => $name.' Address',
            'ci_user_id' => $ci->id,
        ]);

        return [$source, $report, $check];
    }

    private function assertPairTrashed(IncomeSource $source, BusinessReport $report, BusinessCheck $check): void
    {
        $this->assertSoftDeleted('income_sources', ['id' => $source->id, 'client_folder_id' => $source->client_folder_id, 'co_maker_id' => $source->co_maker_id]);
        $this->assertSoftDeleted('business_reports', ['id' => $report->id, 'income_source_id' => $source->id]);
        $this->assertSoftDeleted('business_checks', ['id' => $check->id, 'client_folder_id' => $source->client_folder_id, 'co_maker_id' => $source->co_maker_id, 'income_source_id' => $source->id]);
    }

    private function assertPairActive(IncomeSource $source, BusinessReport $report, BusinessCheck $check): void
    {
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'client_folder_id' => $source->client_folder_id, 'co_maker_id' => $source->co_maker_id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['id' => $report->id, 'income_source_id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id, 'client_folder_id' => $source->client_folder_id, 'co_maker_id' => $source->co_maker_id, 'income_source_id' => $source->id, 'deleted_at' => null]);
    }
}
