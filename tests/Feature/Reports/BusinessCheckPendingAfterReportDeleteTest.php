<?php

namespace Tests\Feature\Reports;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Enums\RecordState;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Reports\ReportWorkItem;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Business Report and Business Check are independent modules, and each suppression marker applies
 * only to its own module. A report-only Business Report delete keeps the IncomeSource, so a
 * surviving business with no Business Check still drives the person's Pending Business Check entry
 * in the Reports workspace. Only an intentional Business Check delete (business_check_deleted_at)
 * retires that requirement. Nothing here is ever written by reading Reports.
 *
 * Also covers the friendly response for Business Report links the workspace hands out once the
 * business has been fully deleted by another CI.
 */
class BusinessCheckPendingAfterReportDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const STALE_LINK = 'This report is no longer available. It may have been deleted or changed by another CI. Please refresh the Reports page and try again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_report_only_delete_keeps_the_applicants_pending_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'APPLICANT CLIENT');
        $business = $this->business($folder, 'SURVIVING STORE');

        // Before: Business Report Completed on the exact business, Business Check Pending for the person.
        $before = $this->rows($ci, $folder);
        $report = $before->where('kind', 'business_report')->sole();
        $this->assertTrue($report->isCompleted);
        $this->assertSame($business->id, $report->incomeSourceId);
        $this->assertFalse($this->pendingCheck($before, null)->isCompleted);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $business);

        $after = $this->rows($ci, $folder);
        $this->assertCount(0, $after->where('kind', 'business_report'), 'The deleted Business Report stays suppressed.');
        $pending = $this->pendingCheck($after, null);
        $this->assertNull($pending->sourceId);
        $this->assertNull($pending->coMakerId, 'Applicant context is exact.');
        $this->assertFalse($pending->isCompleted);

        $fresh = $business->fresh();
        $this->assertNotNull($fresh->business_report_deleted_at);
        $this->assertNull($fresh->business_check_deleted_at, 'A report delete never sets the Business Check marker.');
        $this->assertSame(0, BusinessCheck::query()->count(), 'Reading Reports creates nothing.');

        // The requirement is driven by that exact surviving business: once it gets its own
        // Business Check, the Pending entry is answered.
        $this->businessCheckFor($ci, $folder, $business);
        $answered = $this->rows($ci, $folder)->where('kind', 'business_check');
        $this->assertCount(0, $answered->where('isCompleted', false));
        $this->assertSame($business->id, $answered->sole()->incomeSourceId);
    }

    public function test_report_only_delete_is_exact_per_co_maker_and_per_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CO-MAKER CLIENT');
        $otherFolder = $this->folder($ci, 'OTHER CLIENT');
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicantBusiness = $this->business($folder, 'APPLICANT STORE');
        $this->businessCheckFor($ci, $folder, $applicantBusiness);
        $businessA = $this->business($folder, 'SAME NAME STORE', $coMakerA);
        $businessB = $this->business($folder, 'SAME NAME STORE', $coMakerB);
        $this->businessCheckFor($ci, $folder, $businessB);
        $otherBusiness = $this->business($otherFolder, 'SAME NAME STORE');

        $otherBefore = $this->rows($ci, $otherFolder)->map(fn (ReportWorkItem $i) => $i->key())->sort()->values()->all();

        app(DeleteBusinessReport::class)->execute($ci, $folder, $businessA);

        $rows = $this->rows($ci, $folder);
        // Co-Maker A: report suppressed, Business Check still Pending in A's exact context.
        $this->assertCount(0, $rows->where('kind', 'business_report')->where('incomeSourceId', $businessA->id));
        $this->assertFalse($this->pendingCheck($rows, $coMakerA->id)->isCompleted);
        // Co-Maker B (same business name, different income_source_id): untouched, check Completed, no Pending.
        $reportB = $rows->where('kind', 'business_report')->where('incomeSourceId', $businessB->id)->sole();
        $this->assertTrue($reportB->isCompleted);
        $this->assertSame($coMakerB->id, $reportB->coMakerId);
        $this->assertCount(0, $rows->where('kind', 'business_check')->where('coMakerId', $coMakerB->id)->where('isCompleted', false));
        // Applicant: already checked, so no Pending entry appears for them.
        $this->assertCount(0, $rows->where('kind', 'business_check')->whereStrict('coMakerId', null)->where('isCompleted', false));
        $this->assertNull($businessB->fresh()->business_report_deleted_at);

        // Another folder with a same-named business is unaffected.
        $this->assertSame($otherBefore, $this->rows($ci, $otherFolder)->map(fn (ReportWorkItem $i) => $i->key())->sort()->values()->all());
        $this->assertNull($otherBusiness->fresh()->business_report_deleted_at);
    }

    public function test_an_intentional_business_check_delete_still_suppresses_its_pending_entry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CHECK DELETE CLIENT');
        $business = $this->business($folder, 'CHECKED STORE');
        $check = $this->businessCheckFor($ci, $folder, $business);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $rows = $this->rows($ci, $folder);
        $this->assertCount(0, $rows->where('kind', 'business_check'), 'A deliberately deleted Business Check is not re-synthesised.');
        $this->assertTrue($rows->where('kind', 'business_report')->sole()->isCompleted, 'Its Business Report is unaffected.');

        // Deleting the report afterwards does not bring the check back either — both markers hold.
        app(DeleteBusinessReport::class)->execute($ci, $folder, $business->fresh());
        $this->assertCount(0, $this->rows($ci, $folder)->whereIn('kind', ['business_report', 'business_check']));
    }

    public function test_an_existing_business_check_survives_report_only_delete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'SURVIVING CHECK CLIENT');
        $business = $this->business($folder, 'CHECKED STORE');
        $check = $this->businessCheckFor($ci, $folder, $business);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $business);

        $checkRow = $this->rows($ci, $folder)->where('kind', 'business_check')->sole();
        $this->assertTrue($checkRow->isCompleted);
        $this->assertSame($check->id, $checkRow->sourceId);
        $this->assertSame($business->id, $checkRow->incomeSourceId);
    }

    public function test_full_business_delete_removes_both_exact_rows_and_restores_unbound_placeholders(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FULL DELETE CLIENT');
        $business = $this->business($folder, 'ONLY STORE');
        $this->businessCheckFor($ci, $folder, $business);

        app(DeleteIncomeSource::class)->execute($ci, $folder, $business);

        $businessRows = $this->rows($ci, $folder)->whereIn('kind', ['business_report', 'business_check']);
        $this->assertCount(0, $businessRows->where('incomeSourceId', $business->id));
        $this->assertCount(2, $businessRows);
        $this->assertTrue($businessRows->every(fn (ReportWorkItem $i) => $i->isUnboundBusiness() && ! $i->isCompleted));
        $this->assertNull(IncomeSource::withTrashed()->find($business->id));
        $this->assertSame(0, BusinessReport::query()->count());
        $this->assertSame(0, BusinessCheck::query()->count());
    }

    public function test_stale_business_report_links_are_friendly_after_full_delete(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'STALE LINK CLIENT');
        $business = $this->business($folder, 'GONE STORE');

        // Links exactly as the workspace rendered them before another CI deleted the business.
        $completed = $this->rows($ci, $folder)->where('kind', 'business_report')->sole();
        $previewUrl = $completed->previewAction()['url'];
        [$pdf, $excel] = $completed->downloadActions();
        $editUrl = route('client-folders.income-sources.edit', [$folder->id, $business->id]);

        app(DeleteIncomeSource::class)->execute($ci, $folder, $business);

        foreach ([
            $this->actingAs($ci)->get($editUrl),
            $this->actingAs($ci)->get($previewUrl),
            $this->actingAs($ci)->get($pdf['url']),
            $this->actingAs($ci)->post($excel['url'], $excel['fields']),
        ] as $response) {
            $response->assertNotFound()->assertSee(self::STALE_LINK);
            $this->assertNoLeak($response->getContent(), $business);
        }

        $json = $this->actingAs($ci)->getJson($previewUrl)->assertNotFound()->assertJson(['message' => self::STALE_LINK]);
        $this->assertNoLeak($json->getContent(), $business);

        // Other missing models and routes do not borrow this wording (scope is not global). A missing
        // Client Folder has its own handling: back to Client Folders with a folder-specific notice.
        $this->actingAs($ci)->get(route('client-folders.show', 999999))
            ->assertRedirect(route('client-folders.index'))
            ->assertSessionMissing('errors');
        $this->actingAs($ci)->followingRedirects()->get(route('client-folders.show', 999999))->assertDontSee(self::STALE_LINK);
    }

    private function assertNoLeak(string $body, IncomeSource $source): void
    {
        $this->assertStringNotContainsString('No query results', $body);
        $this->assertStringNotContainsString('App\\Models', $body);
        $this->assertStringNotContainsString('App\\\\Models', $body);
        $this->assertStringNotContainsString('] '.$source->id, $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }

    private function pendingCheck(Collection $rows, ?int $coMakerId): ReportWorkItem
    {
        return $rows->where('kind', 'business_check')->where('isCompleted', false)->filter(fn (ReportWorkItem $i) => $i->coMakerId === $coMakerId)->sole();
    }

    /** @return Collection<int, ReportWorkItem> */
    private function rows(User $ci, ClientFolder $folder): Collection
    {
        return collect($this->actingAs($ci)->get(route('reports.index', ['client_folder_id' => $folder->id]))->assertOk()->viewData('items')->items());
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name]);
    }

    /** A dedicated business with an actually-submitted (revision > 1, Complete) Business Report. */
    private function business(ClientFolder $folder, string $name, ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker?->id,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'source_name' => $name,
            'business_name' => $name,
            'state' => RecordState::Complete,
            'completed_at' => now(),
            'revision' => 2,
        ]);
        BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => $name]);

        return $source;
    }

    private function businessCheckFor(User $ci, ClientFolder $folder, IncomeSource $business): BusinessCheck
    {
        return BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $business->co_maker_id,
            'income_source_id' => $business->id,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $ci->id,
        ]);
    }
}
