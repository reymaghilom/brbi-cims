<?php

namespace Tests\Feature\Reports;

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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Business Check — Pending" in the Reports workspace is ONE generic create entry point per exact
 * person — never one row per Business Report.
 *
 * Business Report and Business Check are independent workflows, so having two, three or ten
 * Business Reports must not manufacture that many Pending Business Check rows. The generic row
 * carries no income_source_id and no business name; the CI picks the business inside the Business
 * Check form. Saved business_checks rows stay business-specific and are never collapsed into it.
 */
class GenericBusinessCheckPendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // TEST A — two Business Reports, no checks
    // ---------------------------------------------------------------------

    public function test_two_completed_business_reports_produce_exactly_one_generic_pending_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'BUSINESS A');
        $b = $this->business($folder, 'BUSINESS B');

        $checks = $this->businessChecks($ci, $folder);

        $this->assertCount(1, $checks, 'One generic entry point, never one Pending row per business.');
        $this->assertFalse($checks[0]->isCompleted);
        $this->assertNull($checks[0]->incomeSourceId, 'The generic row belongs to no particular business.');
        $this->assertNull($checks[0]->businessName, 'No business name is attached to the generic row.');
        $this->assertNull($checks[0]->sourceId, 'It is derived, never a stored row.');
        $this->assertSame(route('client-folders.business-checks.create', $folder->id), $checks[0]->continueUrl());

        // Business Report rows are untouched: still one per exact business.
        $reports = $this->itemsFor($ci, $folder, 'business_report');
        $this->assertSame([$a->id, $b->id], $reports->pluck('incomeSourceId')->sort()->values()->all());
        $this->assertTrue($reports->every(fn (ReportWorkItem $row): bool => $row->isCompleted));

        // The page itself never renders a per-business Pending Business Check.
        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'business_check', 'tab' => 'pending']))->assertOk()->getContent();
        foreach (['BUSINESS A', 'BUSINESS B'] as $name) {
            $this->assertStringNotContainsString($name, $html);
        }
    }

    // ---------------------------------------------------------------------
    // TEST B — one check saved, one business still eligible
    // ---------------------------------------------------------------------

    public function test_one_saved_check_leaves_the_single_generic_pending_entry_point(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'BUSINESS A');
        $b = $this->business($folder, 'BUSINESS B');
        $checkA = $this->checkFor($ci, $folder, $a);

        $checks = $this->businessChecks($ci, $folder);

        $completed = $checks->filter(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $completed);
        $this->assertSame($a->id, $completed[0]->incomeSourceId);
        $this->assertSame($checkA->id, $completed[0]->sourceId);
        $this->assertSame('BUSINESS A', $completed[0]->businessName);

        // Business B stays eligible, so ONE generic entry point remains — not a row named after B.
        $pending = $checks->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]->incomeSourceId);
        $this->assertNull($pending[0]->businessName);
        $this->assertNull($checks->firstWhere('incomeSourceId', $b->id));
    }

    // ---------------------------------------------------------------------
    // TEST C — every eligible business checked
    // ---------------------------------------------------------------------

    public function test_the_generic_pending_disappears_once_every_eligible_business_is_checked(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'BUSINESS A');
        $b = $this->business($folder, 'BUSINESS B');
        $this->checkFor($ci, $folder, $a);
        $this->checkFor($ci, $folder, $b);

        $checks = $this->businessChecks($ci, $folder);

        $this->assertCount(2, $checks);
        $this->assertTrue($checks->every(fn (ReportWorkItem $row): bool => $row->isCompleted));
        $this->assertSame([$a->id, $b->id], $checks->pluck('incomeSourceId')->sort()->values()->all());
        $this->assertCount(0, $checks->reject(fn (ReportWorkItem $row): bool => $row->isCompleted), 'No entry point is needed.');

        // A new unchecked business brings the single entry point back.
        $this->business($folder, 'BUSINESS C');
        $after = $this->businessChecks($ci, $folder);
        $pending = $after->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]->incomeSourceId);
    }

    // ---------------------------------------------------------------------
    // TEST D — zero-business / manual
    // ---------------------------------------------------------------------

    public function test_the_zero_business_entry_point_is_answered_by_a_manual_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $before = $this->businessChecks($ci, $folder);
        $this->assertCount(1, $before);
        $this->assertFalse($before[0]->isCompleted);
        $this->assertNull($before[0]->incomeSourceId);

        BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => null, 'ci_user_id' => $ci->id,
            'business_name' => 'MANUAL STORE', 'location' => 'Manual Address', 'ci_date' => now()->toDateString(),
        ]);

        $after = $this->businessChecks($ci, $folder);
        $this->assertCount(1, $after, 'The manual check answers the entry point rather than sitting beside it.');
        $this->assertTrue($after[0]->isCompleted);
        $this->assertSame('MANUAL STORE', $after[0]->businessName);
        $this->assertNull($after[0]->incomeSourceId);

        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    public function test_a_business_created_after_a_manual_check_brings_the_entry_point_back(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => null, 'ci_user_id' => $ci->id,
            'business_name' => 'MANUAL STORE', 'location' => 'Manual Address', 'ci_date' => now()->toDateString(),
        ]);
        $this->business($folder, 'LATER BUSINESS');

        $checks = $this->businessChecks($ci, $folder);
        $pending = $checks->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();

        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]->incomeSourceId);
        $this->assertCount(1, $checks->filter(fn (ReportWorkItem $row): bool => $row->isCompleted), 'The manual check stays Completed on its own row.');
    }

    // ---------------------------------------------------------------------
    // TEST E — person isolation
    // ---------------------------------------------------------------------

    public function test_each_person_gets_at_most_one_generic_pending_of_their_own(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        // Applicant: 2 unchecked. Co-Maker A: 3 unchecked. Co-Maker B: 1, already checked.
        $this->business($folder, 'APPLICANT ONE');
        $this->business($folder, 'APPLICANT TWO');
        foreach (['A ONE', 'A TWO', 'A THREE'] as $name) {
            $this->business($folder, $name, $coMakerA->id);
        }
        $bBusiness = $this->business($folder, 'B ONE', $coMakerB->id);
        $this->checkFor($ci, $folder, $bBusiness);

        $checks = $this->businessChecks($ci, $folder);
        $pending = $checks->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();

        $this->assertCount(2, $pending, 'Exactly one generic Pending for the Applicant and one for Co-Maker A.');
        $this->assertSame([null, $coMakerA->id], $pending->pluck('coMakerId')->sort()->values()->all());
        $this->assertTrue($pending->every(fn (ReportWorkItem $row): bool => $row->incomeSourceId === null));
        $this->assertCount(0, $pending->where('coMakerId', $coMakerB->id), 'Co-Maker B has nothing left to check.');

        // Each entry point carries its own exact person forward into the form.
        $applicantRow = $pending->firstWhere('coMakerId', null);
        $aRow = $pending->firstWhere('coMakerId', $coMakerA->id);
        $this->assertSame(route('client-folders.business-checks.create', $folder->id), $applicantRow->continueUrl());
        $this->assertSame(
            route('client-folders.business-checks.create', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]),
            $aRow->continueUrl(),
        );

        // Co-Maker B's saved check stays theirs alone.
        $completed = $checks->filter(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $completed);
        $this->assertSame($coMakerB->id, $completed[0]->coMakerId);
        $this->assertSame($bBusiness->id, $completed[0]->incomeSourceId);
    }

    // ---------------------------------------------------------------------
    // TEST F — mixed linked + manual completed checks
    // ---------------------------------------------------------------------

    public function test_linked_and_manual_completed_checks_coexist_and_stay_distinct(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'BUSINESS A');
        $linked = $this->checkFor($ci, $folder, $a);
        $manual = BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => null, 'ci_user_id' => $ci->id,
            'business_name' => 'MANUAL STORE', 'location' => 'Manual Address', 'ci_date' => now()->toDateString(),
        ]);

        $checks = $this->businessChecks($ci, $folder);

        // Both saved checks are Completed and keep their own identity; eligibility is computed
        // independently of them, and Business A is the only business, already checked.
        $this->assertCount(2, $checks);
        $this->assertTrue($checks->every(fn (ReportWorkItem $row): bool => $row->isCompleted));
        $this->assertSame([$linked->id, $manual->id], $checks->pluck('sourceId')->sort()->values()->all());
        $this->assertSame('BUSINESS A', $checks->firstWhere('sourceId', $linked->id)->businessName);
        $this->assertSame('MANUAL STORE', $checks->firstWhere('sourceId', $manual->id)->businessName);
        $this->assertNull($checks->firstWhere('sourceId', $manual->id)->incomeSourceId);
        $this->assertCount(2, $checks->map(fn (ReportWorkItem $row): string => $row->key())->unique());
    }

    public function test_business_report_rows_are_never_made_generic(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->business($folder, 'BUSINESS A');
        $b = $this->business($folder, 'BUSINESS B');

        $reports = $this->itemsFor($ci, $folder, 'business_report');

        $this->assertCount(2, $reports, 'Two completed Business Reports remain two separate rows.');
        $this->assertSame([$a->id, $b->id], $reports->pluck('incomeSourceId')->sort()->values()->all());
        $this->assertSame(['BUSINESS A', 'BUSINESS B'], $reports->pluck('businessName')->sort()->values()->all());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return Collection<int, ReportWorkItem> */
    private function itemsFor(User $ci, ClientFolder $folder, string $kind): Collection
    {
        return collect($this->actingAs($ci)
            ->get(route('reports.index', ['report_type' => $kind]))
            ->assertOk()
            ->viewData('items')
            ->items())
            ->filter(fn (ReportWorkItem $row): bool => $row->clientFolderId === $folder->id)
            ->values();
    }

    /** @return Collection<int, ReportWorkItem> */
    private function businessChecks(User $ci, ClientFolder $folder): Collection
    {
        return $this->itemsFor($ci, $folder, 'business_check');
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function checkFor(User $ci, ClientFolder $folder, IncomeSource $business): BusinessCheck
    {
        return BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $business->co_maker_id,
            'income_source_id' => $business->id,
            'business_name' => $business->business_name,
            'location' => $business->businessReport?->main_business_address,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $ci->id,
        ]);
    }

    /** A genuinely saved, completed business (revision > 1 with its own Business Report). */
    private function business(ClientFolder $folder, string $name, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
            'state' => RecordState::Complete,
            'completed_at' => now(),
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'report_category' => 'Retail',
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }
}
