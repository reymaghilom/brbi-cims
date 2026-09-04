<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business Report and Business Check delete are now permanent and independent (see
 * BusinessReportBusinessCheckIndependenceTest for the full prefill/independence lifecycle) — there
 * is no more paired soft-delete or Recycle Bin entry for either. This file keeps the isolation
 * matrix the old paired-delete tests proved (Applicant / Co-Maker A / Co-Maker B / another folder
 * never cross-contaminate), rebuilt against the new hard-delete actions.
 */
class PairedBusinessRecycleBinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_deleting_one_applicant_business_report_never_touches_another_business_or_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Applicant Folder');
        $otherFolder = $this->folderFor($ci, 'Other Folder');
        $coMaker = $this->coMaker($folder, 'Co-Maker A');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Applicant Business A');
        [$otherBusiness, $otherReport, $otherCheck] = $this->pair($folder, $ci, null, 'Applicant Business B');
        [$coMakerBusiness, $coMakerReport, $coMakerCheck] = $this->pair($folder, $ci, $coMaker, 'Co-Maker Business');
        [$otherFolderBusiness, $otherFolderReport, $otherFolderCheck] = $this->pair($otherFolder, $ci, null, 'Other Folder Business');

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source);

        $this->assertDatabaseMissing('business_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id]);
        $this->assertPairIntact($otherBusiness, $otherReport, $otherCheck);
        $this->assertPairIntact($coMakerBusiness, $coMakerReport, $coMakerCheck);
        $this->assertPairIntact($otherFolderBusiness, $otherFolderReport, $otherFolderCheck);
    }

    public function test_deleting_one_applicant_business_check_never_touches_another_business_or_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Applicant Check Folder');
        $otherFolder = $this->folderFor($ci, 'Unrelated Folder');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Applicant Check Business');
        [$otherSource, $otherReport, $otherCheck] = $this->pair($otherFolder, $ci, null, 'Unrelated Folder Business');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseMissing('business_checks', ['id' => $check->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['id' => $report->id]);
        $this->assertPairIntact($otherSource, $otherReport, $otherCheck);
    }

    public function test_co_maker_report_delete_never_touches_applicant_or_another_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Co-Maker Report Folder');
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        [$applicantSource, $applicantReport, $applicantCheck] = $this->pair($folder, $ci, null, 'Applicant Business');
        [$sourceA, $reportA, $checkA] = $this->pair($folder, $ci, $coMakerA, 'Co-Maker A Business');
        [$sourceB, $reportB, $checkB] = $this->pair($folder, $ci, $coMakerB, 'Co-Maker B Business');

        app(DeleteBusinessReport::class)->execute($ci, $folder, $sourceA);

        $this->assertDatabaseMissing('business_reports', ['id' => $reportA->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $sourceA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_checks', ['id' => $checkA->id]);
        $this->assertPairIntact($applicantSource, $applicantReport, $applicantCheck);
        $this->assertPairIntact($sourceB, $reportB, $checkB);
    }

    public function test_co_maker_check_delete_is_exactly_scoped(): void
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

        $this->assertDatabaseMissing('business_checks', ['id' => $checkA->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $sourceA->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['id' => $reportA->id]);
        $this->assertPairIntact($sourceB, $reportB, $checkB);
        $this->assertPairIntact($otherSource, $otherReport, $otherCheck);
    }

    public function test_a_failure_inside_report_delete_rolls_back_and_leaves_everything_intact(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Rollback Folder');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Rollback Business');

        $this->mock(IncomeSourcesCompletionEvaluator::class, function ($mock): void {
            $mock->shouldReceive('evaluateFolder')->once()->andThrow(new \RuntimeException('Simulated delete failure.'));
        });

        try {
            app(DeleteBusinessReport::class)->execute($ci, $folder, $source);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated delete failure.', $exception->getMessage());
        }

        $this->assertPairIntact($source, $report, $check);
    }

    public function test_deleting_both_report_and_check_removes_the_now_orphaned_income_source(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci, 'Orphan Cleanup Folder');
        [$source, $report, $check] = $this->pair($folder, $ci, null, 'Orphan Cleanup Business');

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id]);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseMissing('income_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('business_reports', ['id' => $report->id]);
        $this->assertDatabaseMissing('business_checks', ['id' => $check->id]);
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

    /** @return array{IncomeSource, BusinessReport, BusinessCheck} */
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
        // This isolation matrix is about cross-folder/cross-person delete leakage, not
        // orphan-vs-draft-shell semantics — bumping revision here represents a genuinely,
        // explicitly saved Business Report (the real-world state SaveBusinessIncomeSource would
        // leave behind), so DeleteIncomeSourceIfOrphaned's revision-aware check (see
        // BusinessReportBusinessCheckIndependenceTest's Check-first orphan-cleanup coverage) never
        // mistakes this fixture for a true orphan once its Check is deleted below.
        $source->forceFill(['revision' => 2])->save();
        $check = $folder->businessChecks()->create([
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => $source->id,
            'business_name' => $name,
            'ci_date' => now()->toDateString(),
            'location' => $name.' Address',
            'ci_user_id' => $ci->id,
        ]);

        return [$source, $report, $check];
    }

    private function assertPairIntact(IncomeSource $source, BusinessReport $report, BusinessCheck $check): void
    {
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'client_folder_id' => $source->client_folder_id, 'co_maker_id' => $source->co_maker_id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['id' => $report->id, 'income_source_id' => $source->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id, 'client_folder_id' => $source->client_folder_id, 'co_maker_id' => $source->co_maker_id, 'income_source_id' => $source->id]);
    }
}
