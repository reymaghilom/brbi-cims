<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\BusinessReportDuplicateGuard;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BusinessReportPhaseOneHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_initial_create_commits_the_source_and_complete_report_together(): void
    {
        [$ci, $folder, $template] = $this->context();

        $this->actingAs($ci)
            ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'Atomic Store'))
            ->assertSessionHasNoErrors();

        $source = IncomeSource::query()->sole();
        $report = BusinessReport::query()->sole();

        $this->assertSame($source->id, $report->income_source_id);
        $this->assertSame(2, $source->revision);
        $this->assertSame('Atomic Store', $report->business_name);
    }

    public function test_initial_save_failure_rolls_back_the_source_report_and_audits(): void
    {
        [$ci, $folder, $template] = $this->context();
        $beforeAudits = AuditLog::query()->count();

        $save = Mockery::mock(SaveBusinessIncomeSource::class);
        $save->shouldReceive('execute')->once()->andThrow(new RuntimeException('Forced initial report save failure.'));
        $this->instance(SaveBusinessIncomeSource::class, $save);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'Must Roll Back'));
            $this->fail('The forced save failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced initial report save failure.', $exception->getMessage());
        }

        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
        $this->assertSame($beforeAudits, AuditLog::query()->count());
    }

    public function test_locked_authoritative_recheck_can_reject_a_duplicate_that_appears_after_validation(): void
    {
        [$ci, $folder, $template] = $this->context();

        // SQLite cannot hold two truly interleaved write transactions in this in-memory test.
        // Returning a duplicate only on the second lookup models the state changing after request
        // validation and proves the controller repeats the check at its locked write boundary.
        $duplicates = Mockery::mock(BusinessReportDuplicateGuard::class);
        $duplicates->shouldReceive('duplicateError')
            ->twice()
            ->andReturn([], ['income_source_template_id' => BusinessReportDuplicateGuard::STANDARD_MESSAGE]);
        $this->instance(BusinessReportDuplicateGuard::class, $duplicates);

        $this->actingAs($ci)
            ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'Concurrent Attempt'))
            ->assertSessionHasErrors([
                'income_source_template_id' => BusinessReportDuplicateGuard::STANDARD_MESSAGE,
            ]);

        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    public function test_same_template_remains_independent_between_client_folders(): void
    {
        $ci = User::factory()->create();
        $folderA = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folderB = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->template();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folderA), $this->payload($template, 'Folder A'))->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folderB), $this->payload($template, 'Folder B'))->assertSessionHasNoErrors();

        $this->assertSame(2, IncomeSource::query()->count());
    }

    public function test_existing_edit_requires_revision_and_preserves_exact_identity_and_linked_check(): void
    {
        [$ci, $folder, $template] = $this->context();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'Original'))->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();
        $reportId = $source->businessReport()->sole()->id;
        $check = BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'income_source_id' => $source->id,
            'business_name' => 'Check Snapshot',
            'ci_date' => '2026-09-01',
            'location' => 'Check Address',
            'ci_user_id' => $ci->id,
        ]);

        $edit = $this->payload($template, 'Missing Revision');
        unset($edit['income_source_template_id']);
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $edit)
            ->assertSessionHasErrors('expected_revision');
        $this->assertSame('Original', $source->fresh()->business_name);

        $edit['business_name'] = 'Current Update';
        $edit['source_name'] = 'Current Update';
        $edit['expected_revision'] = $source->fresh()->revision;
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $edit)
            ->assertSessionHasNoErrors();

        $source->refresh();
        $this->assertSame(3, $source->revision);
        $this->assertSame($source->id, $source->businessReport()->sole()->income_source_id);
        $this->assertSame($reportId, $source->businessReport()->sole()->id);
        $this->assertSame('Check Snapshot', $check->fresh()->business_name);
        $this->assertSame('Check Address', $check->fresh()->location);
    }

    public function test_stale_revision_keeps_the_newer_report_unchanged(): void
    {
        [$ci, $folder, $template] = $this->context();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'Original'))->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();
        $staleRevision = $source->revision;

        $newer = $this->payload($template, 'Newer Save');
        unset($newer['income_source_template_id']);
        $newer['expected_revision'] = $staleRevision;
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $newer)->assertSessionHasNoErrors();

        $stale = $newer;
        $stale['business_name'] = 'Stale Overwrite';
        $stale['source_name'] = 'Stale Overwrite';
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $stale)
            ->assertSessionHasErrors([
                'expected_revision' => 'This Business Report was updated by another CI while you were editing it. Your changes were not saved. Please refresh the report to review the latest information before editing again.',
            ]);

        $this->assertSame('Newer Save', $source->fresh()->business_name);
        $this->assertSame('Newer Save', $source->fresh()->businessReport->business_name);
    }

    /** @return array{User, ClientFolder, IncomeSourceTemplate} */
    private function context(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]), $this->template()];
    }

    private function template(): IncomeSourceTemplate
    {
        return IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
    }

    private function payload(IncomeSourceTemplate $template, string $name): array
    {
        return [
            'income_source_template_id' => $template->id,
            'co_maker_id' => null,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
            'intent' => 'stay',
        ];
    }
}
