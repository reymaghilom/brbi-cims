<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Enums\OfficialReportType;
use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderOverview;
use App\Services\Reports\OfficialReportDataBuilder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Business Report and Business Check share identity through income_source_id but, once each has
 * been explicitly saved, own their own data independently: prefill flows one direction only, at
 * creation time, and is never live synchronization (see CLAUDE.md's Business Report ↔ Business
 * Check lifecycle rules). This file is the authoritative coverage for that lifecycle; see also
 * PairedBusinessRecycleBinTest (hard-delete isolation matrix) and DeleteCheckCloudCleanupTest
 * (Business Check media cleanup).
 */
class BusinessReportBusinessCheckIndependenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_report_first_prefills_new_check_and_stays_independent_after_save(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen, CDO', '2026-09-02'));

        // Report saved first — opening a brand-new Business Check for this business prefills from it.
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('data-location="Carmen, CDO"', false)
            ->assertSee('data-ci-date="2026-09-02"', false)
            ->assertSee('Rey Store');

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen, CDO',
        ]);
        $this->assertSame('Rey Store', $check->business_name);
        $this->assertSame('Carmen, CDO', $check->location);

        // Report is later renamed/moved — the saved Check must not follow it.
        $source = $source->fresh();
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Mini Mart', 'Lapasan', '2026-09-05'));

        $check = $check->fresh();
        $this->assertSame('Rey Store', $check->business_name);
        $this->assertSame('Carmen, CDO', $check->location);
        $this->assertSame('2026-09-02', $check->ci_date->format('Y-m-d'));

        $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('value="Carmen, CDO"', false)
            ->assertDontSee('value="Lapasan"', false);
    }

    public function test_applicant_check_first_never_prefills_the_draft_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // The shape the retired Check-first flow left behind: CreateIncomeSource only, no
        // SaveBusinessIncomeSource — the Business Report stays an unfinalized, revision-1 shell.
        $source = $this->createBusiness($ci, $folder, null, 'Store A');
        $this->assertSame(1, $source->revision);

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'main_business_address' => null]);

        // Business Check data never reaches the Business Report form: the still-unfinalized draft
        // opens blank, exactly as it would if no Business Check existed at all.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertDontSee('value="Carmen"', false)
            ->assertDontSee('value="2026-09-02"', false);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'main_business_address' => null]);

        // A later Check edit changes nothing on that form either.
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-05', 'location' => 'Lapasan',
        ]);
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertDontSee('value="Lapasan"', false)
            ->assertDontSee('value="Carmen"', false);

        // Explicitly saving the Report finalizes it — it becomes its own snapshot from now on.
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source->fresh(), $this->reportPayload($source, null, 'Store A', 'Lapasan', '2026-09-05'));
        $this->assertSame(2, $source->fresh()->revision);

        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-09', 'location' => 'Bulua',
        ]);
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('value="Lapasan"', false)
            ->assertDontSee('value="Bulua"', false);
    }

    public function test_hard_deleting_check_preserves_report_and_a_new_check_prefills_from_it(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseMissing('business_checks', ['id' => $check->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'main_business_address' => 'Carmen']);

        // Business Report survives and needs no Business Check to render.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->assertSee('Rey Store');

        // A brand-new Check for the same business prefills from the surviving Report again.
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('data-location="Carmen"', false);
    }

    public function test_hard_deleting_report_preserves_check_and_the_recreated_report_starts_blank(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source);

        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id, 'business_name' => 'Rey Store']);

        // Business Check survives and needs no Business Report to render.
        $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()->assertSee('value="Carmen"', false);

        // The Business Report no longer exists at all — reopening it starts from the normal
        // template workflow, with none of the surviving Check's values carried over.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertDontSee('value="Carmen"', false)
            ->assertDontSee('value="2026-09-02"', false);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);

        // Saving recreates a real, independent BusinessReport row on the same income_source_id.
        $source = $source->fresh();
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store Recreated', 'Carmen', '2026-09-02'));
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'business_name' => 'Rey Store Recreated']);
        $this->assertSame(1, $source->businessReport()->count());
    }

    public function test_co_maker_lifecycle_mirrors_applicant_and_stays_isolated(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);

        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store', 'A Address', '2026-09-02'));
        $checkA = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMakerA->id, 'income_source_id' => $sourceA->id,
            'ci_date' => '2026-09-02', 'location' => 'A Address',
        ]);

        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Store', 'B Address', '2026-09-03'));

        // Renaming Co-Maker A's Report must not touch Co-Maker A's saved Check or Co-Maker B's business at all.
        $sourceA = $sourceA->fresh();
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store Renamed', 'A Address Renamed', '2026-09-10'));
        $checkA = $checkA->fresh();
        $this->assertSame('A Store', $checkA->business_name);
        $this->assertSame('A Address', $checkA->location);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceB->id, 'business_name' => 'B Store']);

        // Co-Maker A's Check delete must not touch Co-Maker B's business or Co-Maker A's own Report.
        app(DeleteBusinessCheck::class)->execute($ci, $folder, $checkA);
        $this->assertDatabaseMissing('business_checks', ['id' => $checkA->id]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceA->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $sourceB->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceB->id]);
    }

    public function test_cross_folder_business_check_delete_is_rejected(): void
    {
        $ci = User::factory()->create();
        $folderA = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folderB = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $sourceA = $this->createBusiness($ci, $folderA, null, 'Folder A Business');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folderA, $sourceA, $this->reportPayload($sourceA, null, 'Folder A Business', 'Address A', '2026-09-02'));
        $checkA = app(SaveBusinessCheck::class)->execute($ci, $folderA, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $sourceA->id,
            'ci_date' => '2026-09-02', 'location' => 'Address A',
        ]);

        $this->actingAs($ci)->delete(route('client-folders.business-checks.destroy', [$folderB, $checkA]))->assertNotFound();
        $this->assertDatabaseHas('business_checks', ['id' => $checkA->id]);
    }

    public function test_deleting_business_report_and_then_its_check_keeps_the_income_source_via_http(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Orphan Business');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Orphan Business', 'Address', '2026-09-02'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Address',
        ]);

        $this->actingAs($ci)
            ->delete(route('client-folders.income-sources.business-report.destroy', [$folder, $source]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Business Report permanently deleted.');
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);

        $this->actingAs($ci)
            ->delete(route('client-folders.business-checks.destroy', [$folder, $check]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Business Check permanently deleted.');

        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);
        $this->assertDatabaseMissing('business_checks', ['income_source_id' => $source->id]);
    }

    public function test_saved_business_check_output_snapshot_survives_a_later_business_report_rename(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        $source = $source->fresh();
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Mart', 'Lapasan', '2026-09-05'));

        $section = app(OfficialReportDataBuilder::class)->businessCheckSection($check->fresh(), 'Applicant');
        $this->assertSame('Rey Store', $section['business_name']);
        $this->assertSame('Carmen', $section['location']);
        $this->assertSame('September 2, 2026', $section['ci_date']);

        $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))
            ->assertOk()
            ->assertSee('Rey Store')
            ->assertSee('Carmen')
            ->assertDontSee('Rey Mart')
            ->assertDontSee('Lapasan');
    }

    public function test_saved_business_report_output_snapshot_survives_a_later_business_check_edit(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        // Business Check is later edited (its own saved snapshot changes, not the Report's).
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-05', 'location' => 'Lapasan',
        ]);

        $data = app(OfficialReportDataBuilder::class)->build($folder, OfficialReportType::BusinessIncomeSource, $source->fresh());
        $this->assertSame('Rey Store', $data['business']['business_name']);
        $this->assertSame('Carmen', $data['business']['main_business_address']);
        $this->assertSame('9/2/2026', $data['business']['start_date']);
    }

    public function test_co_maker_saved_check_output_snapshot_is_isolated_from_co_maker_b_and_survives_report_rename(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);

        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store', 'A Address', '2026-09-02'));
        $checkA = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMakerA->id, 'income_source_id' => $sourceA->id,
            'ci_date' => '2026-09-02', 'location' => 'A Address',
        ]);

        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Store', 'B Address', '2026-09-03'));
        $checkB = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMakerB->id, 'income_source_id' => $sourceB->id,
            'ci_date' => '2026-09-03', 'location' => 'B Address',
        ]);

        $sourceA = $sourceA->fresh();
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store Renamed', 'A Address Renamed', '2026-09-10'));

        $builder = app(OfficialReportDataBuilder::class);
        $sectionA = $builder->businessCheckSection($checkA->fresh(), 'Co-Maker A');
        $this->assertSame('A Store', $sectionA['business_name']);
        $this->assertSame('A Address', $sectionA['location']);

        // Co-Maker A's rename must never leak into Co-Maker B's own saved snapshot.
        $sectionB = $builder->businessCheckSection($checkB->fresh(), 'Co-Maker B');
        $this->assertSame('B Store', $sectionB['business_name']);
        $this->assertSame('B Address', $sectionB['location']);
    }

    public function test_cross_folder_business_check_output_never_leaks(): void
    {
        $ci = User::factory()->create();
        $folderA = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folderB = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $sourceA = $this->createBusiness($ci, $folderA, null, 'Folder A Business');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folderA, $sourceA, $this->reportPayload($sourceA, null, 'Folder A Business', 'Address A', '2026-09-02'));
        $checkA = app(SaveBusinessCheck::class)->execute($ci, $folderA, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $sourceA->id,
            'ci_date' => '2026-09-02', 'location' => 'Address A',
        ]);

        $this->createBusiness($ci, $folderB, null, 'Folder B Business');

        // Folder B's own preview never resolves Folder A's Business Check — it has none of its own yet.
        $this->assertSame(0, $folderB->businessChecks()->count());

        $section = app(OfficialReportDataBuilder::class)->businessCheckSection($checkA->fresh(), 'Applicant');
        $this->assertSame('Folder A Business', $section['business_name']);
        $this->assertSame($folderA->id, $checkA->client_folder_id);
    }

    public function test_saved_businesses_page_lists_a_business_report_then_drops_it_after_hard_delete(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Rey Store');

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source->fresh());

        // The saved-table row is gone (Recent Activity may still legitimately mention the name as
        // real audit history — that is not the same as an active saved-table row).
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('data-business-name="Rey Store"', false)
            ->assertDontSee('Recreate Business Report', false);
    }

    public function test_ghost_row_disappears_when_a_business_check_survives_the_report_delete(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source->fresh());

        // Internally: IncomeSource and Business Check both survive (the Check still needs it).
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id, 'income_source_id' => $source->id]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);

        // But the Saved Businesses page must show no trace of it.
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('data-business-name="Rey Store"', false)
            ->assertSee('Add Business') // Empty-state label, since no saved report remains.
            ->assertDontSee('Recreate Business Report', false)
            ->assertDontSee('data-modal-open="delete-business-'.$source->id.'"', false);
    }

    public function test_saved_business_row_disappears_but_income_source_remains_after_report_delete(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source->fresh());

        // A child delete never owns the parent IncomeSource, even when the other child is absent.
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('data-business-name="Rey Store"', false);
    }

    public function test_co_maker_ghost_row_disappears_without_affecting_applicant_or_other_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);

        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSource, $this->reportPayload($applicantSource, null, 'Applicant Store', 'Applicant Address', '2026-09-01'));

        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store', 'A Address', '2026-09-02'));

        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Store', 'B Address', '2026-09-03'));

        app(DeleteBusinessReport::class)->execute($ci, $folder, $sourceA->fresh());

        $personParamsA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParamsA))
            ->assertOk()
            ->assertDontSee('data-business-name="A Store"', false);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Applicant Store');

        $personParamsB = ['person' => 'co-maker', 'co_maker_id' => $coMakerB->id];
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParamsB))
            ->assertOk()
            ->assertSee('B Store');
    }

    public function test_dashboard_business_count_tracks_saved_business_reports_not_bare_income_sources(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $sourceA = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, null, 'Rey Store', 'Carmen', '2026-09-02'));
        $sourceB = $this->createBusiness($ci, $folder, null, 'Second Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, null, 'Second Store', 'Lapasan', '2026-09-03'));
        $checkB = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $sourceB->id,
            'ci_date' => '2026-09-03', 'location' => 'Lapasan',
        ]);

        // The Business / Income Sources badge is asserted with a tight ">N Business(es)<" anchor —
        // the Residence & Business Report module's own description text ("N Business Check(s)
        // saved.") also legitimately contains the word "Business" and must never be confused with it.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee('>2 Businesses<', false);

        // First Business Report hard-deleted with no surviving Check — its IncomeSource remains,
        // while the dashboard still counts only actually saved Business Reports.
        app(DeleteBusinessReport::class)->execute($ci, $folder, $sourceA->fresh());
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee('>1 Business<', false)->assertDontSee('>2 Businesses<', false);

        // Second Business Report hard-deleted, but its Business Check survives — IncomeSource
        // must survive internally, yet the dashboard count must drop to zero.
        app(DeleteBusinessReport::class)->execute($ci, $folder, $sourceB->fresh());
        $this->assertDatabaseHas('income_sources', ['id' => $sourceB->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_checks', ['id' => $checkB->id, 'income_source_id' => $sourceB->id]);

        $overview = app(ClientFolderOverview::class)->for($folder->fresh());
        $this->assertSame(0, $overview['clientFolder']->income_sources_count);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertDontSee('>1 Business<', false)
            ->assertDontSee('>2 Businesses<', false);

        // Residence & Business Report's own Business Check count must remain unaffected.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('0 Residence, 1 Business Check saved.');
    }

    public function test_co_maker_dashboard_business_count_is_isolated_from_applicant_and_other_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);

        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSource, $this->reportPayload($applicantSource, null, 'Applicant Store', 'Applicant Address', '2026-09-01'));

        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store', 'A Address', '2026-09-02'));
        $sourceA2 = $this->createBusiness($ci, $folder, $coMakerA, 'A Store 2');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA2, $this->reportPayload($sourceA2, $coMakerA, 'A Store 2', 'A Address 2', '2026-09-03'));

        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Store', 'B Address', '2026-09-04'));

        app(DeleteBusinessReport::class)->execute($ci, $folder, $sourceA->fresh());

        $personParamsA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $this->actingAs($ci)->get(route('client-folders.show', $folder).'?'.http_build_query($personParamsA))
            ->assertOk()
            ->assertSee('>1 Business<', false)
            ->assertDontSee('>2 Businesses<', false);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('>1 Business<', false)
            ->assertDontSee('>2 Businesses<', false);

        $personParamsB = ['person' => 'co-maker', 'co_maker_id' => $coMakerB->id];
        $this->actingAs($ci)->get(route('client-folders.show', $folder).'?'.http_build_query($personParamsB))
            ->assertOk()
            ->assertSee('>1 Business<', false);
    }

    public function test_check_first_business_appears_as_a_report_pending_row_with_no_prefill_in_the_form(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // The shape the retired Check-first flow left behind: CreateIncomeSource only, so the
        // Business Report is still an unfinalized, revision-1 shell — it must appear as a
        // Report Pending row in the unified business list, never as a "Saved" row (see
        // IncomeSourceController::checkFirstCandidates()).
        $source = $this->createBusiness($ci, $folder, null, 'FARMING: CORN PRODUCTION');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Baikingon',
        ]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('FARMING: CORN PRODUCTION')
            ->assertSee('Report Pending')
            ->assertSee('Complete Business Report');

        // That candidate still opens on its own exact template with no re-selection, but the
        // Business Report form itself carries none of the Business Check's values.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertDontSee('value="Baikingon"', false)
            ->assertDontSee('value="2026-09-02"', false);

        // Never persisted merely by viewing either page.
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'main_business_address' => null, 'start_date' => null]);
    }

    public function test_check_first_candidate_form_never_follows_the_business_check_ci_date(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->assertDontSee('value="2026-09-02"', false);

        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-05', 'location' => 'Carmen',
        ]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertDontSee('value="2026-09-05"', false)
            ->assertDontSee('value="2026-09-02"', false);
    }

    public function test_check_first_candidate_disappears_but_its_income_source_and_report_shell_remain(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->assertSee('Rey Store');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        // The candidate must disappear entirely — no stale candidate section, no Check-derived
        // prefill left behind in the (now check-less) Business Report form either. "Rey Store" may
        // still legitimately appear in Recent Activity as real audit history — that is not the same
        // as an active candidate.
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('Business Reports to Create');

        // Deleting the Check does not own either the parent source or its Business Report shell.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk();

        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id]);
        $this->assertDatabaseMissing('business_checks', ['income_source_id' => $source->id]);
    }

    public function test_check_first_ghost_never_appears_as_a_selectable_business_check_candidate_after_its_check_is_deleted(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Micabalo Trucking Services');
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        // This is the exact reported bug: a business whose only Check was deleted (and whose
        // Business Report was never explicitly saved) must not linger as a ghost, selectable
        // "Existing Business" option on a brand-new Business Check form.
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Micabalo Trucking Services');
    }

    public function test_check_first_business_survives_when_its_saved_check_is_not_deleted(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Surviving Check Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        // A meaningful saved Business Check means this IncomeSource is NOT orphaned, even though
        // its Business Report was never explicitly saved (revision stays 1) — this must never be
        // treated as a cleanup candidate while the Check is still alive.
        $this->assertDatabaseHas('income_sources', ['id' => $source->id]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id]);
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Report Pending')
            ->assertSee('Surviving Check Store');
    }

    public function test_saved_report_with_no_check_appears_in_the_business_check_dropdown(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Dropdown Ready Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Dropdown Ready Store', 'Address', '2026-09-01'));

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Dropdown Ready Store');
    }

    public function test_deleted_saved_report_with_no_check_disappears_from_the_business_check_dropdown(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Deleted Report Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Deleted Report Store', 'Address', '2026-09-01'));

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source->fresh());

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Deleted Report Store');
    }

    public function test_deleted_saved_report_with_surviving_check_disappears_from_the_dropdown_while_check_survives(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Micabalo Trucking Services');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Micabalo Trucking Services', 'Address', '2026-09-01'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source->fresh());

        $this->assertDatabaseHas('business_checks', ['id' => $check->id, 'income_source_id' => $source->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id]);
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Micabalo Trucking Services');
    }

    public function test_saved_report_with_saved_check_is_absent_from_the_new_check_dropdown(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Already Checked Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Already Checked Store', 'Address', '2026-09-01'));
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Already Checked Store');
    }

    public function test_deleting_the_check_lets_the_saved_report_business_reappear_in_the_dropdown(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Reappearing Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Reappearing Store', 'Address', '2026-09-01'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Reappearing Store');

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id]);
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Reappearing Store');
    }

    public function test_a_never_saved_revision_one_income_source_is_absent_from_the_business_check_dropdown(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // createBusiness() alone (no SaveBusinessIncomeSource, no SaveBusinessCheck) leaves the
        // IncomeSource at revision 1 — exactly the same state a fresh "+ Add Business" quick-add
        // from the Business Check form itself produces (see ApplicantBusinessQuickAddTest). The
        // dropdown's source of truth is EXPLICITLY SAVED Business Reports (revision > 1) only, so
        // this must not appear here even though it isn't an orphan and isn't deleted — a quick-add
        // continues the CURRENT Business Check purely client-side (see app.js), never through this
        // server-rendered candidate list.
        $this->createBusiness($ci, $folder, null, 'Freshly Added Store');

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Freshly Added Store');
    }

    public function test_check_first_business_with_surviving_check_is_absent_from_the_existing_business_dropdown(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Check First Survivor Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        // The Check survives and the IncomeSource is correctly not orphaned, but its draft Report
        // shell was never explicitly saved — it must not appear as an "Existing Business" candidate
        // for a brand-new Business Check either (it already has one).
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertDontSee('Check First Survivor Store');
    }

    public function test_dropdown_candidate_isolation_across_applicant_co_makers_and_folders(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);

        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Dropdown Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSource, $this->reportPayload($applicantSource, null, 'Applicant Dropdown Store', 'Address', '2026-09-01'));
        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Dropdown Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Dropdown Store', 'Address', '2026-09-02'));
        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Dropdown Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Dropdown Store', 'Address', '2026-09-03'));
        $otherFolderSource = $this->createBusiness($ci, $otherFolder, null, 'Other Folder Dropdown Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $otherFolder, $otherFolderSource, $this->reportPayload($otherFolderSource, null, 'Other Folder Dropdown Store', 'Address', '2026-09-04'));

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Applicant Dropdown Store')
            ->assertDontSee('A Dropdown Store')
            ->assertDontSee('B Dropdown Store')
            ->assertDontSee('Other Folder Dropdown Store');

        $personParamsA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', [$folder] + $personParamsA))
            ->assertOk()
            ->assertSee('A Dropdown Store')
            ->assertDontSee('Applicant Dropdown Store')
            ->assertDontSee('B Dropdown Store');

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $otherFolder))
            ->assertOk()
            ->assertSee('Other Folder Dropdown Store')
            ->assertDontSee('Applicant Dropdown Store')
            ->assertDontSee('A Dropdown Store')
            ->assertDontSee('B Dropdown Store');
    }

    public function test_co_maker_check_first_delete_keeps_its_exact_parent_source(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Orphan Co-Maker']);
        $source = $this->createBusiness($ci, $folder, $coMaker, 'Co-Maker Ghost Store');
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMaker->id, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $this->assertDatabaseHas('income_sources', [
            'id' => $source->id,
            'co_maker_id' => $coMaker->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('business_checks', ['id' => $check->id]);
        $personParams = ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', [$folder] + $personParams))
            ->assertOk()
            ->assertDontSee('Co-Maker Ghost Store');
    }

    public function test_saved_businesses_table_ci_date_stays_on_the_saved_report_value_after_finalization(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);
        $check = $folder->businessChecks()->firstOrFail();

        // Business Report is explicitly saved/finalized — Sep 2 becomes its own real value.
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source->fresh(), $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));

        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-05', 'location' => 'Lapasan',
        ]);

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->getContent();
        $panelStart = strpos($html, 'data-business-panel-body');
        $panelEnd = strpos($html, '</section>', $panelStart);
        $savedBusinessesPanel = substr($html, $panelStart, $panelEnd - $panelStart);

        $this->assertStringContainsString('Sep 2, 2026', $savedBusinessesPanel);
        $this->assertStringNotContainsString('Sep 5, 2026', $savedBusinessesPanel);
    }

    public function test_co_maker_check_first_candidate_is_isolated(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMakerA->id, 'income_source_id' => $sourceA->id,
            'ci_date' => '2026-09-02', 'location' => 'A Address',
        ]);
        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMakerB->id, 'income_source_id' => $sourceB->id,
            'ci_date' => '2026-09-03', 'location' => 'B Address',
        ]);
        $otherSource = $this->createBusiness($ci, $otherFolder, null, 'Other Folder Store');
        app(SaveBusinessCheck::class)->execute($ci, $otherFolder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $otherSource->id,
            'ci_date' => '2026-09-04', 'location' => 'Other Address',
        ]);

        $personParamsA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParamsA))
            ->assertOk()
            ->assertSee('A Store')
            ->assertDontSee('B Store')
            ->assertDontSee('Other Folder Store');

        $personParamsB = ['person' => 'co-maker', 'co_maker_id' => $coMakerB->id];
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParamsB))
            ->assertOk()
            ->assertSee('B Store')
            ->assertDontSee('A Store');

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $otherFolder))
            ->assertOk()
            ->assertSee('Other Folder Store')
            ->assertDontSee('A Store')
            ->assertDontSee('B Store');
    }

    public function test_report_first_check_reuses_the_exact_same_template_with_no_reselection(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'trucking_services')->firstOrFail();
        $source = $this->createBusiness($ci, $folder, null, 'RCM Trucking', 'trucking_services');
        $this->assertSame($template->id, $source->income_source_template_id);
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'RCM Trucking', 'Opol, Misamis Oriental', '2026-09-02'));

        // The Business Check form's business selector already lists this exact business — no
        // separate template-selection step exists there at all (see business-checks/form.blade.php).
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('RCM Trucking')
            ->assertSee('data-location="Opol, Misamis Oriental"', false)
            ->assertSee('data-ci-date="2026-09-02"', false);

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Opol, Misamis Oriental',
        ]);

        // Exact same identity reused — no second IncomeSource, same template.
        $this->assertSame($source->id, $check->income_source_id);
        $this->assertSame(1, IncomeSource::where('client_folder_id', $folder->id)->count());
        $this->assertSame($template->id, $source->fresh()->income_source_template_id);

        // Independence holds both ways after save.
        $source = $source->fresh();
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'RCM Logistics', 'CDO', '2026-09-05'));
        $this->assertSame('RCM Trucking', $check->fresh()->business_name);
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-09', 'location' => 'Bulua',
        ]);
        $this->assertSame('RCM Logistics', $source->fresh()->businessReport->business_name);

        // Delete the Check, then recreate one — the surviving business/template is reused again,
        // no template search needed.
        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check->fresh());
        $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('RCM Logistics');
        $newCheck = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-10', 'location' => 'Bulua',
        ]);
        $this->assertSame($source->id, $newCheck->income_source_id);
        $this->assertSame(1, IncomeSource::where('client_folder_id', $folder->id)->count());
    }

    public function test_check_first_report_reuses_the_exact_same_template_and_becomes_independent_after_save(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'trucking_services')->firstOrFail();
        // Business Check first — the template is chosen once, at IncomeSource creation.
        $source = $this->createBusiness($ci, $folder, null, 'RCM Trucking', 'trucking_services');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Opol, Misamis Oriental',
        ]);

        // No genuine Saved Business Report yet — a Report Pending row, not counted on the dashboard.
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Report Pending')
            ->assertSee('Trucking Services');
        $overview = app(ClientFolderOverview::class)->for($folder->fresh());
        $this->assertSame(0, $overview['clientFolder']->income_sources_count);

        // Opening the Report goes straight to the correct template — no generic picker — and the
        // form starts empty: Business Check data never prefills a Business Report.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('TRUCKING SERVICES')
            ->assertDontSee('value="Opol, Misamis Oriental"', false)
            ->assertDontSee('value="2026-09-02"', false);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'main_business_address' => null]);

        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source->fresh(), $this->reportPayload($source, null, 'RCM Trucking', 'Opol, Misamis Oriental', '2026-09-02'));

        $this->assertSame($template->id, $source->fresh()->income_source_template_id);
        $this->assertSame(1, IncomeSource::where('client_folder_id', $folder->id)->count());
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->assertSee('RCM Trucking');
        $overview = app(ClientFolderOverview::class)->for($folder->fresh());
        $this->assertSame(1, $overview['clientFolder']->income_sources_count);

        // Later Check edit/delete must not alter the now-saved, independent Report.
        $check = $folder->businessChecks()->firstOrFail();
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-09', 'location' => 'CDO',
        ]);
        $this->assertSame('Opol, Misamis Oriental', $source->fresh()->businessReport->main_business_address);
    }

    public function test_multiple_report_first_businesses_each_reused_for_their_own_check_with_correct_template(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $trucking = $this->createBusiness($ci, $folder, null, 'RCM Trucking', 'trucking_services');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $trucking, $this->reportPayload($trucking, null, 'RCM Trucking', 'Opol', '2026-09-02'));
        $farming = $this->createBusiness($ci, $folder, null, 'Rey Corn Farm', 'farming_corn');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $farming, $this->reportPayload($farming, null, 'Rey Corn Farm', 'Baikingon', '2026-09-03'));

        $truckingCheck = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $trucking->id,
            'ci_date' => '2026-09-02', 'location' => 'Opol',
        ]);
        $farmingCheck = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $farming->id,
            'ci_date' => '2026-09-03', 'location' => 'Baikingon',
        ]);

        $this->assertSame($trucking->id, $truckingCheck->income_source_id);
        $this->assertSame($farming->id, $farmingCheck->income_source_id);
        $this->assertNotSame($trucking->income_source_template_id, $farming->income_source_template_id);
        $this->assertSame(2, IncomeSource::where('client_folder_id', $folder->id)->count());
    }

    public function test_multiple_check_first_candidates_each_open_the_correct_exact_template(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $trucking = $this->createBusiness($ci, $folder, null, 'RCM Trucking', 'trucking_services');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $trucking->id,
            'ci_date' => '2026-09-02', 'location' => 'Opol',
        ]);
        $sariSari = $this->createBusiness($ci, $folder, null, 'Rey Store', 'retail_grocery_water_refilling');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $sariSari->id,
            'ci_date' => '2026-09-03', 'location' => 'Carmen',
        ]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('RCM Trucking')
            ->assertSee('Trucking Services')
            ->assertSee('Rey Store')
            ->assertSee('Retail: Grocery Store / Supermarket / Sari-Sari Store / Water Refilling');

        // Each candidate opens on its own exact template, and neither Business Check's values
        // appear on either Business Report form.
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $trucking]))
            ->assertOk()
            ->assertSee('TRUCKING SERVICES')
            ->assertDontSee('value="Opol"', false)
            ->assertDontSee('value="Carmen"', false);

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $sariSari]))
            ->assertOk()
            ->assertDontSee('value="Carmen"', false)
            ->assertDontSee('value="Opol"', false);

        $this->assertSame(2, IncomeSource::where('client_folder_id', $folder->id)->count());
    }

    public function test_recent_activity_view_all_renders_at_the_bottom_of_the_preview_panel_not_the_header(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        // View All must not sit beside the header — the header line itself must not mention it.
        $headerPos = strpos($content, 'id="business-recent-activity-title"');
        $headerLineEnd = strpos($content, '</div>', $headerPos);
        $this->assertStringNotContainsString('data-modal-open="business-recent-activity-dialog"', substr($content, $headerPos, $headerLineEnd - $headerPos));

        // It must render after the activity list instead, at the bottom of the panel.
        $listEndPos = strpos($content, '</ul>', $headerPos);
        $viewAllPos = strpos($content, 'data-modal-open="business-recent-activity-dialog"', $headerPos);
        $this->assertNotFalse($viewAllPos);
        $this->assertGreaterThan($listEndPos, $viewAllPos, 'View All must render after the activity list, at the bottom of the panel.');
    }

    public function test_recent_activity_preview_shows_real_audit_entries_and_view_all_opens_the_shared_modal(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Rey Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Rey Store', 'Carmen', '2026-09-02'));
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Carmen',
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk();
        $response->assertSee('Business Report saved')
            ->assertSee('Business Check added')
            ->assertSee('id="business-recent-activity-dialog"', false)
            ->assertSee('Newest first.');

        // The exact same shared modal component CI Activities/the dashboard already use — no
        // second Recent Activity modal implementation.
        $overview = app(ClientFolderOverview::class)->businessActivity($folder, null);
        $this->assertGreaterThanOrEqual(2, $overview->count());
    }

    public function test_recent_activity_empty_state_still_shows_view_all_button(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('No recent business activity yet.')
            ->assertSee('data-modal-open="business-recent-activity-dialog"', false);
    }

    public function test_recent_activity_isolation_across_applicant_co_makers_and_folders(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSource, $this->reportPayload($applicantSource, null, 'Applicant Store', 'Applicant Address', '2026-09-01'));
        $sourceA = $this->createBusiness($ci, $folder, $coMakerA, 'A Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, $coMakerA, 'A Store', 'A Address', '2026-09-02'));
        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Store', 'B Address', '2026-09-03'));
        $otherSource = $this->createBusiness($ci, $otherFolder, null, 'Other Folder Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $otherFolder, $otherSource, $this->reportPayload($otherSource, null, 'Other Folder Store', 'Other Address', '2026-09-04'));

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Applicant Store')
            ->assertDontSee('A Store')
            ->assertDontSee('B Store')
            ->assertDontSee('Other Folder Store');

        $personParamsA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParamsA))
            ->assertOk()
            ->assertSee('A Store')
            ->assertDontSee('Applicant Store')
            ->assertDontSee('B Store');

        $personParamsB = ['person' => 'co-maker', 'co_maker_id' => $coMakerB->id];
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParamsB))
            ->assertOk()
            ->assertSee('B Store')
            ->assertDontSee('A Store')
            ->assertDontSee('Applicant Store');

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $otherFolder))
            ->assertOk()
            ->assertSee('Other Folder Store')
            ->assertDontSee('Applicant Store')
            ->assertDontSee('A Store')
            ->assertDontSee('B Store');
    }

    public function test_business_report_summary_and_page_header_remain_absent(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('Business Report Summary')
            ->assertDontSee('Pending Reports')
            ->assertDontSee('Manage, review, print, and download saved Business Reports for this client.')
            ->assertSee('Businesses / Income Sources');
    }

    public function test_add_business_button_lives_inside_the_saved_businesses_panel_header_only_once(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'data-modal-open="add-business-template-dialog"'), 'Exactly one Add Business trigger should exist.');

        $panelHeadingPos = strpos($content, 'id="saved-businesses-title"');
        $buttonPos = strpos($content, 'data-modal-open="add-business-template-dialog"');
        $this->assertNotFalse($panelHeadingPos);
        $this->assertNotFalse($buttonPos);
        $this->assertGreaterThan($panelHeadingPos, $buttonPos, 'Add Business must render after the Saved Businesses heading, inside the same panel header.');
        $this->assertLessThan(900, $buttonPos - $panelHeadingPos, 'Add Business must sit in the same header row as the heading, not further down the panel.');
    }

    public function test_recent_activity_timeline_renders_a_connector_between_items_but_not_after_the_last(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $sourceA = $this->createBusiness($ci, $folder, null, 'Timeline Store A');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, null, 'Timeline Store A', 'Address A', '2026-09-01'));
        $sourceB = $this->createBusiness($ci, $folder, null, 'Timeline Store B');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, null, 'Timeline Store B', 'Address B', '2026-09-02'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $previewItemCount = substr_count($content, 'relative grid grid-cols-[1.75rem_1fr]');
        $this->assertGreaterThanOrEqual(2, $previewItemCount, 'At least two activity rows should render.');

        $modalStart = strpos($content, 'id="business-recent-activity-dialog"');
        $modalEnd = strpos($content, '</dialog>', $modalStart);
        $modalHtml = substr($content, $modalStart, $modalEnd - $modalStart);
        $modalItemCount = substr_count($modalHtml, 'relative grid min-w-0 grid-cols-[1rem_minmax(0,1fr)]');
        $this->assertGreaterThanOrEqual(2, $modalItemCount, 'The full history should render the activity rows.');
        $this->assertSame($modalItemCount - 1, substr_count($modalHtml, 'border-l border-dashed border-ui-border-strong'), 'There must be exactly one fewer connector than full-history activity rows.');
    }

    public function test_recent_activity_rows_show_full_date_and_time_not_time_only(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Full Timestamp Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Full Timestamp Store', 'Some Address', '2026-09-02'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/[A-Z][a-z]{2} \d{1,2}, \d{4} · \d{1,2}:\d{2} (AM|PM)/', $content, 'Activity rows must show a full date and time, not time alone.');
    }

    public function test_recent_activity_panel_has_a_hide_control_and_saved_businesses_panel_has_a_hidden_show_control(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-business-history-hide', $content);
        $this->assertStringContainsString('aria-label="Hide Recent Activity panel"', $content);
        $this->assertStringContainsString('data-business-history-show', $content);
        $this->assertStringContainsString('aria-label="Show Recent Activity panel"', $content);

        $showButtonPos = strpos($content, 'data-business-history-show');
        $this->assertNotFalse($showButtonPos);
        $tagEnd = strpos($content, '>', $showButtonPos);
        $this->assertStringContainsString('hidden', substr($content, $showButtonPos, $tagEnd - $showButtonPos), 'Show control must start hidden while the Recent Activity panel is visible.');

        $panelHeadingPos = strpos($content, 'id="saved-businesses-title"');
        $this->assertLessThan($showButtonPos, $panelHeadingPos, 'Show control belongs inside the Saved Businesses panel header.');
    }

    public function test_recent_activity_layout_wrapper_and_shell_carry_the_expected_toggle_hooks(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-business-activities-layout', $content);
        $this->assertStringContainsString('data-history-state="expanded"', $content);
        $this->assertStringContainsString('data-business-history-shell', $content);
        $this->assertStringContainsString('id="business-history-panel"', $content);
        $this->assertStringContainsString('data-business-history-panel', $content);
    }

    public function test_recent_activity_view_all_still_renders_at_the_bottom_after_the_timeline_and_reuses_the_shared_modal(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'View All Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'View All Store', 'Some Address', '2026-09-02'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $lastActivityRowPos = strrpos($content, 'relative grid grid-cols-[1.75rem_1fr]');
        $viewAllPos = strpos($content, 'data-modal-open="business-recent-activity-dialog"');
        $panelClosePos = strpos($content, '</aside>', $viewAllPos);
        $this->assertNotFalse($lastActivityRowPos);
        $this->assertNotFalse($viewAllPos);
        $this->assertNotFalse($panelClosePos);
        $this->assertGreaterThan($lastActivityRowPos, $viewAllPos, 'View All must render after the activity timeline.');
        $this->assertLessThan($panelClosePos, $viewAllPos, 'View All must still be inside the Recent Activity panel.');
        $this->assertStringContainsString('id="business-recent-activity-dialog"', $content);
    }

    public function test_hiding_and_showing_recent_activity_does_not_touch_audit_log_data(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Audit Stable Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Audit Stable Store', 'Some Address', '2026-09-02'));

        $before = AuditLog::count();

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk();

        $this->assertSame($before, AuditLog::count(), 'Simply rendering the page (which carries the hide/show controls) must never write AuditLog rows.');
    }

    public function test_saved_businesses_address_column_uses_the_saved_business_reports_own_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Address Source Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Address Source Store', 'Carmen, Cagayan de Oro', '2026-09-02'));

        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Lapasan, Cagayan de Oro',
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Carmen, Cagayan de Oro', $content, 'The Address column must show the saved Business Report address.');
        $this->assertStringNotContainsString('Lapasan, Cagayan de Oro', $content, 'The Address column must never show the Business Check location.');
    }

    public function test_saved_businesses_address_column_shows_empty_dash_when_report_has_no_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'No Address Store');
        $payload = $this->reportPayload($source, null, 'No Address Store', '', '2026-09-02');
        $payload['main_business_address'] = '';
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $payload);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $rowStart = strpos($content, 'data-business-row-name');
        $this->assertNotFalse($rowStart);
        $rowEnd = strpos($content, '</tr>', $rowStart);
        $row = substr($content, $rowStart, $rowEnd - $rowStart);
        $this->assertStringContainsString('>—<', $row, 'A saved Business Report with no address must fall back to the project\'s empty-value dash, never to Check data.');
    }

    public function test_updating_business_check_location_does_not_change_the_saved_businesses_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Stable Address Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Stable Address Store', 'Original Report Address', '2026-09-02'));

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'First Check Location',
        ]);

        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-03', 'location' => 'Updated Check Location',
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Original Report Address', $content);
        $this->assertStringNotContainsString('Updated Check Location', $content);
    }

    public function test_deleting_business_check_does_not_erase_the_saved_report_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Surviving Address Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Surviving Address Store', 'Report Address That Must Survive', '2026-09-02'));

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Doomed Check Location',
        ]);
        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Report Address That Must Survive', $content);
    }

    public function test_saved_businesses_address_is_isolated_between_applicant_and_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Address Co-Maker']);

        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Address Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSource, $this->reportPayload($applicantSource, null, 'Applicant Address Store', 'Applicant Only Address', '2026-09-01'));
        $coMakerSource = $this->createBusiness($ci, $folder, $coMaker, 'Co-Maker Address Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $coMakerSource, $this->reportPayload($coMakerSource, $coMaker, 'Co-Maker Address Store', 'Co-Maker Only Address', '2026-09-02'));

        $applicantContent = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Applicant Only Address', $applicantContent);
        $this->assertStringNotContainsString('Co-Maker Only Address', $applicantContent);

        $coMakerContent = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + ['person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Co-Maker Only Address', $coMakerContent);
        $this->assertStringNotContainsString('Applicant Only Address', $coMakerContent);
    }

    public function test_recent_activity_panel_reuses_the_exact_ci_activities_side_panel_width(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('xl:grid-cols-[minmax(0,4fr)_minmax(15rem,1fr)]', $content, 'The Business page layout must reuse the exact CI Activities side-panel width class.');
        $this->assertStringNotContainsString('lg:grid-cols-[minmax(0,3fr)_minmax(18rem,1fr)]', $content, 'The old Business-specific approximate width must no longer be present.');
    }

    public function test_add_business_button_uses_the_compact_primary_toolbar_token(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $buttonPos = strpos($content, 'data-modal-open="add-business-template-dialog"');
        $this->assertNotFalse($buttonPos);
        $tagStart = strrpos(substr($content, 0, $buttonPos), '<button');
        $tagEnd = strpos($content, '>', $buttonPos);
        $tag = substr($content, $tagStart, $tagEnd - $tagStart);
        $this->assertStringContainsString('ui-button-primary-compact', $tag, 'Add Business must use the compact primary button token, not the large page-level CTA size.');
        $this->assertStringNotContainsString('class="ui-button-primary shrink-0"', $tag);
    }

    public function test_recent_activity_shows_the_real_actor_for_add_and_update(): void
    {
        $adder = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $editor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $adder->id]);
        $source = $this->createBusiness($adder, $folder, null, 'Actor Store');
        app(SaveBusinessIncomeSource::class)->execute($adder, $folder, $source, $this->reportPayload($source, null, 'Actor Store', 'Some Address', '2026-09-01'));

        $source = $source->fresh();
        app(SaveBusinessIncomeSource::class)->execute($editor, $folder, $source, $this->reportPayload($source, null, 'Actor Store', 'A New Address', '2026-09-02'));

        $content = $this->actingAs($adder)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('by '.$adder->full_name, $content);
        $this->assertStringContainsString('by '.$editor->full_name, $content);
    }

    public function test_recent_activity_delete_shows_the_actor_who_deleted_it(): void
    {
        $adder = User::factory()->create();
        $deleter = User::factory()->create(['full_name' => 'Rey Maghilom']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $adder->id]);
        $source = $this->createBusiness($adder, $folder, null, 'Deleted Actor Store');
        app(SaveBusinessIncomeSource::class)->execute($adder, $folder, $source, $this->reportPayload($source, null, 'Deleted Actor Store', 'Some Address', '2026-09-01'));
        app(DeleteBusinessReport::class)->execute($deleter, $folder, $source->fresh());

        $content = $this->actingAs($adder)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('by '.$deleter->full_name, $content);
        $this->assertStringContainsString('Deleted Actor Store', $content);
    }

    public function test_update_activity_shows_only_the_fields_that_actually_changed(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Changed Fields Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Changed Fields Store', 'Original Address', '2026-09-01'));

        $source = $source->fresh();
        $payload = $this->reportPayload($source, null, 'Changed Fields Store', 'Updated Address', '2026-09-01');
        $payload['year_established'] = 2021;
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $payload);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Updated: Main Business Address, Year Established', $content);
    }

    public function test_no_op_save_does_not_create_a_fake_updated_fields_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'No Op Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'No Op Store', 'Same Address', '2026-09-01'));

        $before = AuditLog::where('action', 'business_report.updated')->count();
        $source = $source->fresh();
        try {
            app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'No Op Store', 'Same Address', '2026-09-01'));
        } catch (NoChangesDetectedException) {
            // Expected — a genuine no-op save must throw rather than silently writing an audit row.
        }

        $this->assertSame($before, AuditLog::where('action', 'business_report.updated')->count());
    }

    public function test_delete_selected_control_starts_disabled_and_confirmation_modal_is_present(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Bulk Delete Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Bulk Delete Store', 'Some Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $buttonPos = strpos($content, 'data-business-delete-selected');
        $this->assertNotFalse($buttonPos);
        $tagEnd = strpos($content, '>', $buttonPos);
        $this->assertStringContainsString('disabled', substr($content, $buttonPos, $tagEnd - $buttonPos), 'Delete Selected must start disabled when nothing is selected.');
        $this->assertStringContainsString('id="delete-selected-businesses-dialog"', $content);
        $this->assertStringContainsString('Permanently delete selected Business Reports?', $content);
        $this->assertStringContainsString(route('client-folders.income-sources.business-report.destroy-selected', $folder), $content);
    }

    public function test_delete_selected_permanently_deletes_only_the_chosen_reports_and_keeps_checks(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $keep = $this->createBusiness($ci, $folder, null, 'Keep Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $keep, $this->reportPayload($keep, null, 'Keep Store', 'Keep Address', '2026-09-01'));
        $deleteA = $this->createBusiness($ci, $folder, null, 'Delete Store A');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $deleteA, $this->reportPayload($deleteA, null, 'Delete Store A', 'A Address', '2026-09-02'));
        $deleteB = $this->createBusiness($ci, $folder, null, 'Delete Store B');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $deleteB, $this->reportPayload($deleteB, null, 'Delete Store B', 'B Address', '2026-09-03'));
        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $deleteA->id,
            'ci_date' => '2026-09-02', 'location' => 'A Check Location',
        ]);

        $response = $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => '',
            'income_source_ids' => [$deleteA->id, $deleteB->id],
        ]);

        $response->assertOk()->assertJson(['deleted' => 2]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $deleteA->id]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $deleteB->id]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $keep->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $deleteA->id]);
        $this->assertSame(2, AuditLog::where('action', 'business_report.deleted')->count());

        $payload = $response->json();
        $this->assertStringContainsString('Keep Store', $payload['panel']);
        $this->assertStringNotContainsString('Delete Store A', $payload['panel']);
        $this->assertStringNotContainsString('Delete Store B', $payload['panel']);
    }

    public function test_delete_selected_deletes_nothing_when_one_id_belongs_to_another_folder(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $applicantSourceA = $this->createBusiness($ci, $folder, null, 'Applicant Scoped Store A');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSourceA, $this->reportPayload($applicantSourceA, null, 'Applicant Scoped Store A', 'Address', '2026-09-01'));
        $applicantSourceB = $this->createBusiness($ci, $folder, null, 'Applicant Scoped Store B');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSourceB, $this->reportPayload($applicantSourceB, null, 'Applicant Scoped Store B', 'Address', '2026-09-02'));
        $otherFolderSource = $this->createBusiness($ci, $otherFolder, null, 'Other Folder Scoped Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $otherFolder, $otherFolderSource, $this->reportPayload($otherFolderSource, null, 'Other Folder Scoped Store', 'Address', '2026-09-03'));

        $before = AuditLog::where('action', 'business_report.deleted')->count();

        // Two genuinely valid Applicant reports plus one belonging to a completely different
        // folder must fail the WHOLE request — the two valid ones must NOT be deleted either.
        $response = $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => '',
            'income_source_ids' => [$applicantSourceA->id, $applicantSourceB->id, $otherFolderSource->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $applicantSourceA->id]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $applicantSourceB->id]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $otherFolderSource->id]);
        $this->assertSame($before, AuditLog::where('action', 'business_report.deleted')->count(), 'A failed ownership check must create zero delete AuditLogs.');
    }

    public function test_delete_selected_deletes_nothing_when_one_id_belongs_to_a_different_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $folder->coMakers()->create(['full_name' => 'Co-Maker A']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'Co-Maker B']);

        $sourceA1 = $this->createBusiness($ci, $folder, $coMakerA, 'A Store 1');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA1, $this->reportPayload($sourceA1, $coMakerA, 'A Store 1', 'Address', '2026-09-01'));
        $sourceB = $this->createBusiness($ci, $folder, $coMakerB, 'B Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, $coMakerB, 'B Store', 'Address', '2026-09-02'));

        // Acting as Co-Maker A, attempt to bulk-delete Co-Maker B's business alongside one's own.
        $response = $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => $coMakerA->id,
            'income_source_ids' => [$sourceA1->id, $sourceB->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceA1->id]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $sourceB->id]);
    }

    public function test_delete_selected_deletes_nothing_when_co_maker_targets_the_applicants_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Boundary Co-Maker']);

        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Boundary Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantSource, $this->reportPayload($applicantSource, null, 'Applicant Boundary Store', 'Address', '2026-09-01'));

        $response = $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => $coMaker->id,
            'income_source_ids' => [$applicantSource->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $applicantSource->id]);
    }

    public function test_delete_selected_deletes_nothing_when_applicant_targets_a_co_makers_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Reverse Boundary Co-Maker']);

        $coMakerSource = $this->createBusiness($ci, $folder, $coMaker, 'Co-Maker Boundary Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $coMakerSource, $this->reportPayload($coMakerSource, $coMaker, 'Co-Maker Boundary Store', 'Address', '2026-09-01'));

        $response = $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => '',
            'income_source_ids' => [$coMakerSource->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $coMakerSource->id]);
    }

    public function test_delete_selected_all_valid_ids_still_deletes_successfully(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $sourceA = $this->createBusiness($ci, $folder, null, 'All Valid Store A');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceA, $this->reportPayload($sourceA, null, 'All Valid Store A', 'Address', '2026-09-01'));
        $sourceB = $this->createBusiness($ci, $folder, null, 'All Valid Store B');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $sourceB, $this->reportPayload($sourceB, null, 'All Valid Store B', 'Address', '2026-09-02'));

        $response = $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => '',
            'income_source_ids' => [$sourceA->id, $sourceA->id, $sourceB->id],
        ]);

        $response->assertOk()->assertJson(['deleted' => 2]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $sourceA->id]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $sourceB->id]);
    }

    public function test_single_delete_confirmation_dialog_carries_the_ajax_auto_update_hook(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Ajax Hook Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Ajax Hook Store', 'Some Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $dialogPos = strpos($content, 'id="delete-business-'.$source->id.'"');
        $this->assertNotFalse($dialogPos);
        $tagEnd = strpos($content, '>', $dialogPos);
        $this->assertStringContainsString('data-business-delete-form', substr($content, $dialogPos, $tagEnd - $dialogPos));
    }

    public function test_manage_refresh_route_no_longer_exists(): void
    {
        $this->assertFalse(Route::has('client-folders.income-sources.manage-refresh'), 'The second-fetch refresh endpoint must be removed now that saves carry their own authoritative payload.');
    }

    public function test_saved_business_report_carries_the_authoritative_refresh_payload_for_the_parent_to_apply_directly(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        $storeResponse = $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id,
            'co_maker_id' => '',
            'source_name' => 'Payload Store',
            'business_name' => 'Payload Store',
            'report_category' => 'Retail',
            'main_business_address' => 'Payload Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
        ]);
        $storeResponse->assertRedirect();

        $editPage = $this->get($storeResponse->headers->get('Location'));
        $content = $editPage->assertOk()->getContent();

        $notifyPos = strpos($content, 'data-business-saved-notify');
        $this->assertNotFalse($notifyPos, 'The edit page landed on after a save must carry the notify element.');
        $tagEnd = strpos($content, '>', $notifyPos);
        $tag = substr($content, $notifyPos, $tagEnd - $notifyPos);
        $this->assertStringContainsString('data-business-saved-payload="', $tag);

        preg_match('/data-business-saved-payload="([^"]*)"/', $tag, $matches);
        $this->assertNotEmpty($matches, 'data-business-saved-payload attribute must be present with a value.');
        $decoded = json_decode(html_entity_decode($matches[1]), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('panel', $decoded);
        $this->assertArrayHasKey('activity', $decoded);
        $this->assertArrayHasKey('modal', $decoded);
        $this->assertStringContainsString('Payload Store', $decoded['panel']);
        $this->assertStringContainsString('Payload Store', $decoded['activity']);

        // The canonical success message/status-type the parent's toast reads on the persistent
        // page — never a second, invented toast wording.
        $this->assertStringContainsString('data-business-saved-message="Business Report saved successfully."', $tag);
        $this->assertStringContainsString('data-business-saved-status-type="success"', $tag);
    }

    public function test_saved_business_action_menu_shows_preview_report_not_print(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Preview Label Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Preview Label Store', 'Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Preview Report', $content);
        $this->assertStringNotContainsString('class="client-folder-menu-item">Print<', $content);
    }

    public function test_preview_report_link_points_to_the_authoritative_preview_route(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Preview Route Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Preview Route Store', 'Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $expectedHref = route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'business_income_source', 'income_source_id' => $source->id]);
        $this->assertStringContainsString(htmlspecialchars($expectedHref, ENT_QUOTES), $content);
    }

    public function test_saved_business_pdf_links_use_the_exact_authoritative_get_route_for_each_person(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $applicantBusiness = $this->createBusiness($ci, $folder, null, 'Applicant PDF Store');
        $coMakerBusiness = $this->createBusiness($ci, $folder, $coMaker, 'Co-Maker PDF Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $applicantBusiness, $this->reportPayload($applicantBusiness, null, 'Applicant PDF Store', 'Applicant Address', '2026-09-01'));
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $coMakerBusiness, $this->reportPayload($coMakerBusiness, $coMaker, 'Co-Maker PDF Store', 'Co-Maker Address', '2026-09-01'));

        $applicantUrl = route('client-folders.income-sources.export-pdf', [$folder, $applicantBusiness]);
        $coMakerUrl = route('client-folders.income-sources.export-pdf', [
            $folder, $coMakerBusiness, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);
        $applicantHtml = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        $coMakerHtml = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [
            $folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.htmlspecialchars($applicantUrl, ENT_QUOTES).'"', $applicantHtml);
        $this->assertStringContainsString('href="'.htmlspecialchars($coMakerUrl, ENT_QUOTES).'"', $coMakerHtml);
        $this->assertStringNotContainsString('business-'.$applicantBusiness->id.'-export-pdf-form', $applicantHtml);
        $this->assertStringNotContainsString('business-'.$coMakerBusiness->id.'-export-pdf-form', $coMakerHtml);
        $this->assertStringNotContainsString((string) $coMakerBusiness->id.'/export-pdf', $applicantHtml);
        $this->assertStringNotContainsString((string) $applicantBusiness->id.'/export-pdf', $coMakerHtml);
    }

    public function test_preview_report_page_is_read_only_and_never_auto_triggers_browser_print(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Read Only Preview Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Read Only Preview Store', 'Preview Address', '2026-09-01'));
        $auditCountBefore = AuditLog::count();
        $reportCountBefore = BusinessReport::count();

        $content = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'business_income_source', 'income_source_id' => $source->id]))
            ->assertOk()
            ->getContent();

        // The print control is a manual onclick affordance only — nothing on the page invokes it
        // automatically (no window.onload/auto-invoking script), and viewing it must never write
        // anything (no AuditLog, no Business Report row change — this is a pure read of the
        // already-saved snapshot).
        $this->assertStringContainsString('onclick="window.print()"', $content);
        $this->assertStringNotContainsString('window.onload', $content);
        $this->assertSame($auditCountBefore, AuditLog::count(), 'Opening the preview must never write an AuditLog entry.');
        $this->assertSame($reportCountBefore, BusinessReport::count());
    }

    public function test_preview_report_uses_the_saved_report_snapshot_not_a_later_business_check_change(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Snapshot Preview Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Snapshot Preview Store', 'Saved Report Address', '2026-09-01'));
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Newer Check Address',
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.generated-reports.preview', [$folder, 'report_type' => 'business_income_source', 'income_source_id' => $source->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Saved Report Address', $content);
        $this->assertStringNotContainsString('Newer Check Address', $content);
    }

    public function test_business_report_save_message_handler_applies_the_update_before_auto_closing_the_dialog(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $messageHandlerStart = strpos($script, "data?.type !== 'brbi:business-saved') return;");
        $this->assertNotFalse($messageHandlerStart, 'The brbi:business-saved message handler must still exist.');

        $applyPos = strpos($script, 'applyBusinessManageRefresh(event.data.payload);', $messageHandlerStart);
        $toastPos = strpos($script, 'showToast(event.data.message, event.data.statusType', $messageHandlerStart);
        $closePos = strpos($script, 'dialog.close();', $messageHandlerStart);

        $this->assertNotFalse($applyPos, 'The handler must apply the authoritative payload to the Business panel/activity.');
        $this->assertNotFalse($toastPos, 'The handler must show the canonical success toast on the persistent parent page before closing.');
        $this->assertNotFalse($closePos, 'The handler must auto-close the Business Report dialog after a confirmed save.');
        $this->assertLessThan($toastPos, $applyPos, 'The table/activity update must be applied BEFORE the success toast, never after.');
        $this->assertLessThan($closePos, $toastPos, 'The success toast must be shown BEFORE the dialog auto-closes — it lives on the parent page and must already be visible once the dialog disappears.');
        $this->assertLessThan(1500, $closePos - $applyPos, 'The close() call should immediately follow the update/toast, not some unrelated later handler.');
    }

    public function test_business_report_save_handler_never_reloads_or_fetches_a_second_endpoint(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('fetch(returnUrl', $script);
        $this->assertStringNotContainsString('manage-refresh', $script);
    }

    public function test_ci_activities_panel_toggle_shows_panel_with_a_label_and_hides_with_icon_only(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('data-ci-history-show', $content);
        $this->assertStringContainsString('aria-label="Show Panel"', $content);
        $this->assertStringContainsString('data-ci-history-hide', $content);
        $this->assertStringContainsString('aria-label="Hide Panel"', $content);

        // Show Panel keeps its icon AND visible text.
        $showPos = strpos($content, 'data-ci-history-show');
        $showTagEnd = strpos($content, '>', $showPos);
        $showInnerEnd = strpos($content, '</button>', $showPos);
        $showButtonInner = substr($content, $showTagEnd + 1, $showInnerEnd - $showTagEnd - 1);
        $this->assertStringContainsString('Show Panel', $showButtonInner);

        // Hide Panel is icon-only — the X/close glyph with NO visible "Hide Panel" text, even
        // though the accessible name (title/aria-label, already asserted above) still says so.
        $hidePos = strpos($content, 'data-ci-history-hide');
        $this->assertNotFalse($hidePos);
        $hideTagEnd = strpos($content, '>', $hidePos);
        $hideInnerEnd = strpos($content, '</button>', $hidePos);
        $hideButtonInner = substr($content, $hideTagEnd + 1, $hideInnerEnd - $hideTagEnd - 1);
        $this->assertStringContainsString('m6 6 12 12M18 6 6 18', $hideButtonInner);
        $this->assertStringNotContainsString('Hide Panel', $hideButtonInner);
    }

    public function test_report_pending_row_shows_creator_line_when_a_different_ci_created_the_business_check(): void
    {
        $viewer = User::factory()->create(['full_name' => 'CI B']);
        $creator = User::factory()->create(['full_name' => 'CI A']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $viewer->id]);
        $source = $this->createBusiness($creator, $folder, null, 'Creator Hint Store');
        app(SaveBusinessCheck::class)->execute($creator, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        $content = $this->actingAs($viewer)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Business Check created by: CI A', $content);
    }

    public function test_report_pending_row_hides_creator_line_when_the_current_ci_created_the_business_check(): void
    {
        $ci = User::factory()->create(['full_name' => 'CI A']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Own Creation Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Own Creation Store', $content);
        $this->assertStringNotContainsString('Business Check created by:', $content);
    }

    public function test_creator_line_uses_the_actual_business_check_creator_not_updated_by(): void
    {
        $creator = User::factory()->create(['full_name' => 'Original Creator']);
        $editor = User::factory()->create(['full_name' => 'Later Editor']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id]);
        $source = $this->createBusiness($creator, $folder, null, 'Updated By Store');
        $check = app(SaveBusinessCheck::class)->execute($creator, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);
        // A different CI later edits (but does not re-create) the same Check — updated_by changes,
        // ci_user_id (the actual creator) must not.
        app(SaveBusinessCheck::class)->execute($editor, $folder, [
            'check_id' => $check->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Address',
        ]);
        $this->assertSame($creator->id, $check->fresh()->ci_user_id);
        $this->assertSame($editor->id, $check->fresh()->updated_by);

        $content = $this->actingAs($editor)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Business Check created by: Original Creator', $content);
        $this->assertStringNotContainsString('Business Check created by: Later Editor', $content);
    }

    public function test_creator_line_never_renders_for_a_saved_row(): void
    {
        $creator = User::factory()->create(['full_name' => 'CI A']);
        $viewer = User::factory()->create(['full_name' => 'CI B']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $viewer->id]);
        $source = $this->createBusiness($creator, $folder, null, 'Now Saved Store');
        app(SaveBusinessCheck::class)->execute($creator, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);
        app(SaveBusinessIncomeSource::class)->execute($creator, $folder, $source->fresh(), $this->reportPayload($source, null, 'Now Saved Store', 'Address', '2026-09-01'));

        $content = $this->actingAs($viewer)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Now Saved Store', $content);
        $this->assertStringNotContainsString('Business Check created by:', $content);
    }

    public function test_creator_line_is_scoped_to_the_exact_applicant_or_co_maker(): void
    {
        $ci = User::factory()->create(['full_name' => 'CI A']);
        $viewer = User::factory()->create(['full_name' => 'CI B']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $viewer->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Isolation Co-Maker']);
        $applicantSource = $this->createBusiness($ci, $folder, null, 'Applicant Creator Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $applicantSource->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);
        $coMakerSource = $this->createBusiness($ci, $folder, $coMaker, 'Co-Maker Creator Store');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMaker->id, 'income_source_id' => $coMakerSource->id,
            'ci_date' => '2026-09-02', 'location' => 'Address',
        ]);

        $applicantContent = $this->actingAs($viewer)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Applicant Creator Store', $applicantContent);
        $this->assertStringNotContainsString('Co-Maker Creator Store', $applicantContent);

        $coMakerContent = $this->actingAs($viewer)->get(route('client-folders.income-sources.manage', [$folder] + ['person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Co-Maker Creator Store', $coMakerContent);
        $this->assertStringNotContainsString('Applicant Creator Store', $coMakerContent);
    }

    // ==================================================
    // Business table column sizing — Address must clearly outweigh Business
    // ==================================================

    public function test_business_table_still_shows_all_five_labeled_columns(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Column Check Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Column Check Store', 'Address', '2026-09-01'));

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('>Business<', false)
            ->assertSee('>Address<', false)
            ->assertSee('>CI Date<', false)
            ->assertSee('>Status<', false)
            ->assertSee('>Action<', false);
    }

    public function test_business_table_checkbox_hooks_remain_after_column_sizing_change(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Checkbox Hook Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Checkbox Hook Store', 'Address', '2026-09-01'));

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('data-business-select-all', false)
            ->assertSee('data-business-select', false)
            ->assertSee('data-business-selected-count', false);
    }

    public function test_business_table_uses_deliberate_column_sizing_with_address_wider_than_business(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Sizing Check Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Sizing Check Store', 'Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('<colgroup>', $content);
        $this->assertMatchesRegularExpression('/<colgroup>.*?<\/colgroup>/s', $content);
        preg_match('/<colgroup>(.*?)<\/colgroup>/s', $content, $matches);
        preg_match_all('/<col class="w-\[?(\d+)%?\]?">/', $matches[1] ?? '', $colMatches);
        $widths = array_map('intval', $colMatches[1] ?? []);
        // [checkbox(px), business%, address%, ci_date%, status%, action%] — business is index 1, address index 2.
        $this->assertCount(6, $widths, 'Expected exactly 6 <col> definitions matching the 6 table columns.');
        $this->assertGreaterThan($widths[1], $widths[2], 'Address column must receive a larger share of table width than Business.');
    }

    public function test_business_column_does_not_retain_an_excessively_large_width_share(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Narrow Business Column Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Narrow Business Column Store', 'Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        preg_match('/<colgroup>(.*?)<\/colgroup>/s', $content, $matches);
        preg_match_all('/<col class="w-\[?(\d+)%?\]?">/', $matches[1] ?? '', $colMatches);
        $widths = array_map('intval', $colMatches[1] ?? []);
        $this->assertLessThanOrEqual(25, $widths[1] ?? 100, 'Business column should stay moderate, not dominate the row.');
    }

    public function test_long_address_wraps_naturally_instead_of_being_truncated(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Long Address Store');
        $longAddress = 'Purok 7, Zone 3, Barangay Carmen, Along the National Highway near the old rice mill, Cagayan de Oro City, Misamis Oriental, Philippines 9000';
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Long Address Store', $longAddress, '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString($longAddress, $content, 'A long address must render in full, never truncated.');
        $this->assertStringNotContainsString('max-w-64 break-words text-xs leading-5 text-text-muted">'.$longAddress, $content);
        $this->assertMatchesRegularExpression('/class="px-3 py-3 break-words text-xs leading-5 text-text-muted">'.preg_quote($longAddress, '/').'/', $content);
    }

    public function test_business_check_creator_line_still_renders_alongside_the_resized_columns(): void
    {
        $creator = User::factory()->create(['full_name' => 'Other CI']);
        $viewer = User::factory()->create(['full_name' => 'Viewer CI']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $viewer->id]);
        $source = $this->createBusiness($creator, $folder, null, 'Pending Sizing Store');
        app(SaveBusinessCheck::class)->execute($creator, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-01', 'location' => 'Address',
        ]);

        $content = $this->actingAs($viewer)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('Business Check created by: Other CI', $content);
    }

    public function test_business_table_responsive_horizontal_scroll_wrapper_remains(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->createBusiness($ci, $folder, null, 'Responsive Store');
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, $this->reportPayload($source, null, 'Responsive Store', 'Address', '2026-09-01'));

        $content = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('overflow-x-auto overflow-y-hidden rounded-card border border-ui-border', $content);
        $this->assertStringContainsString('min-w-[52rem]', $content);
    }

    private function createBusiness(User $ci, ClientFolder $folder, ?CoMaker $coMaker, string $name, string $templateType = 'retail_grocery_water_refilling'): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', $templateType)->firstOrFail();

        return app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ])->fresh();
    }

    private function reportPayload(IncomeSource $source, ?CoMaker $coMaker, string $businessName, string $address, string $startDate): array
    {
        return [
            'intent' => 'stay',
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->revision,
            'source_name' => $source->source_name ?: $businessName,
            'business_name' => $businessName,
            'report_category' => 'Retail',
            'main_business_address' => $address,
            'start_date' => $startDate,
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
        ];
    }
}
