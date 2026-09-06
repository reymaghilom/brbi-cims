<?php

namespace Tests\Feature\Reports;

use App\Actions\ClientFolders\CreateIncomeSource;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reports integration for the INDEPENDENT Business Check architecture.
 *
 * A saved business_checks row is a Completed Business Check — that is the authoritative rule, and
 * it holds for both shapes the module allows: one that references an exact IncomeSource, and a
 * manual one that references none (income_source_id NULL) because the person has no business at
 * all. A manual check is built straight from its own row; it never mints an IncomeSource, a
 * BusinessReport or a Business Report work item to make itself representable.
 *
 * The zero-business Pending placeholder keeps working exactly as before a check exists, and stands
 * down for that one exact person once their manual check is saved — never both at once.
 */
class ManualBusinessCheckReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ---------------------------------------------------------------------
    // TEST A — Business Check empty-state text
    // ---------------------------------------------------------------------

    public function test_business_check_shows_only_the_approved_empty_state_text(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('No existing business found. Enter the business details below.', $html);

        foreach ([
            // Retired Business Check -> Business Report reminder.
            'Business Check information is available. Please review and complete the remaining details to finalize this report.',
            // Business Report availability / pending status messages.
            'Business Report available',
            'Business Report not yet created',
            'Report Pending',
            // Explanations of what Business Check does or does not create, and IncomeSource jargon.
            'this Business Check will not create a Business Report',
            'This Business Check never changes that Business Report',
            'This person has no Business / Income Source yet',
            'Please select the existing business from the dropdown list to avoid duplicate entries.',
            'Select an existing business for this person or add one if the Business Report has not been created yet.',
        ] as $retired) {
            $this->assertStringNotContainsString($retired, $html, 'Retired helper text must not render: '.$retired);
        }

        // Exactly one message under the business selector — no second helper alongside it.
        $this->assertSame(1, substr_count($html, 'data-business-source-helper'));
    }

    // ---------------------------------------------------------------------
    // TEST B — before any Business Check exists
    // ---------------------------------------------------------------------

    public function test_a_person_with_nothing_saved_still_shows_a_pending_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $checks = $this->businessChecks($ci, $folder);

        $this->assertCount(1, $checks->where('coMakerId', null));
        $applicant = $checks->firstWhere('coMakerId', null);
        $this->assertFalse($applicant->isCompleted);
        $this->assertSame('Pending', $applicant->statusLabel());
        $this->assertNull($applicant->sourceId);
        $this->assertNull($applicant->incomeSourceId);
    }

    // ---------------------------------------------------------------------
    // TEST C — after a manual Business Check is saved
    // ---------------------------------------------------------------------

    public function test_a_saved_manual_business_check_replaces_the_pending_placeholder_with_a_completed_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->manualCheck($ci, $folder, 'Sample Store');

        $checks = $this->businessChecks($ci, $folder)->where('coMakerId', null);

        // Exactly one Business Check row for this person: the saved one. Never Pending + Completed
        // side by side for the same manual check.
        $this->assertCount(1, $checks);
        $item = $checks->first();
        $this->assertTrue($item->isCompleted);
        $this->assertSame('Completed', $item->statusLabel());
        $this->assertSame('Business Check', $item->typeLabel());
        $this->assertSame('Sample Store', $item->businessName);
        $this->assertSame($check->id, $item->sourceId, 'The row is addressed by the actual BusinessCheck id.');
        $this->assertNull($item->incomeSourceId, 'A manual check is never given a fake business id.');

        // It renders on the page under its own saved name and status.
        $this->actingAs($ci)->get(route('reports.index'))->assertOk()->assertSee('Sample Store')->assertSee('Completed');

        // Nothing was created on the Business / Income Sources side to make this representable.
        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
        $this->assertSame(0, $this->items($ci)->where('kind', 'business_report')
            ->filter(fn (ReportWorkItem $row): bool => $row->isCompleted)->count());
    }

    public function test_saving_a_manual_business_check_through_its_own_form_lands_as_completed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'Sample Store',
            'location' => 'Purok 3, Poblacion',
            'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $this->assertNull($check->income_source_id);

        $items = $this->businessChecks($ci, $folder)->where('coMakerId', null);
        $this->assertCount(1, $items);
        $this->assertTrue($items->first()->isCompleted);
        $this->assertSame('Sample Store', $items->first()->businessName);
        $this->assertSame($check->id, $items->first()->sourceId);

        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());
    }

    // ---------------------------------------------------------------------
    // TEST D — a linked Business Check must not regress
    // ---------------------------------------------------------------------

    public function test_a_linked_business_check_stays_completed_and_leaves_its_report_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $business = $this->business($folder, 'ALPHA TRADING');
        $before = [
            'business_name' => $business->businessReport->business_name,
            'address' => $business->businessReport->main_business_address,
            'revision' => $business->revision,
            'state' => $business->state,
        ];

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $business->id,
            'business_name' => 'ALPHA TRADING', 'location' => 'Alpha Address', 'ci_date' => '2026-01-15',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->sole();
        $items = $this->businessChecks($ci, $folder)->where('coMakerId', null);

        $this->assertCount(1, $items);
        $item = $items->first();
        $this->assertTrue($item->isCompleted);
        $this->assertSame($check->id, $item->sourceId);
        $this->assertSame($business->id, $item->incomeSourceId, 'The exact business reference is preserved.');
        $this->assertSame('ALPHA TRADING', $item->businessName);

        $business->refresh();
        $this->assertSame($before['business_name'], $business->businessReport->business_name);
        $this->assertSame($before['address'], $business->businessReport->main_business_address);
        $this->assertSame($before['revision'], $business->revision);
        $this->assertSame($before['state'], $business->state);
    }

    // ---------------------------------------------------------------------
    // TEST E — person isolation
    // ---------------------------------------------------------------------

    public function test_a_manual_applicant_check_never_satisfies_a_co_makers_pending_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $this->manualCheck($ci, $folder, 'APPLICANT STORE');

        $checks = $this->businessChecks($ci, $folder);

        $applicant = $checks->where('coMakerId', null);
        $this->assertCount(1, $applicant);
        $this->assertTrue($applicant->first()->isCompleted);

        foreach ([$coMakerA, $coMakerB] as $coMaker) {
            $rows = $checks->where('coMakerId', $coMaker->id);
            $this->assertCount(1, $rows, 'Each Co-Maker keeps exactly their own Business Check row.');
            $this->assertFalse($rows->first()->isCompleted, 'The Applicant\'s manual check never completes a Co-Maker\'s.');
            $this->assertNull($rows->first()->sourceId);
        }
    }

    public function test_one_co_makers_manual_check_never_affects_another_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

        $checkA = $this->manualCheck($ci, $folder, 'A STORE', $coMakerA);

        $checks = $this->businessChecks($ci, $folder);

        $rowsA = $checks->where('coMakerId', $coMakerA->id);
        $this->assertCount(1, $rowsA);
        $this->assertTrue($rowsA->first()->isCompleted);
        $this->assertSame($checkA->id, $rowsA->first()->sourceId);
        $this->assertSame('A STORE', $rowsA->first()->businessName);

        $rowsB = $checks->where('coMakerId', $coMakerB->id);
        $this->assertCount(1, $rowsB);
        $this->assertFalse($rowsB->first()->isCompleted);
        $this->assertNull($rowsB->first()->sourceId);

        // The Applicant is likewise untouched by a Co-Maker's manual check.
        $applicant = $checks->where('coMakerId', null);
        $this->assertCount(1, $applicant);
        $this->assertFalse($applicant->first()->isCompleted);
    }

    // ---------------------------------------------------------------------
    // TEST F — multiple checks stay distinct
    // ---------------------------------------------------------------------

    public function test_multiple_manual_checks_stay_distinct_and_are_never_collapsed_by_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $a = $this->manualCheck($ci, $folder, 'BUSINESS A');
        $b = $this->manualCheck($ci, $folder, 'BUSINESS B');
        // Deliberately the same display name as the first: identity is the row id, never the name.
        $duplicateName = $this->manualCheck($ci, $folder, 'BUSINESS A');

        $rows = $this->businessChecks($ci, $folder)->where('coMakerId', null);

        $this->assertCount(3, $rows);
        $this->assertSame(
            [$a->id, $b->id, $duplicateName->id],
            $rows->pluck('sourceId')->sort()->values()->all(),
        );
        $this->assertTrue($rows->every(fn (ReportWorkItem $row): bool => $row->isCompleted));
        // Each row has its own stable key, so two same-named checks never render as one.
        $this->assertCount(3, $rows->map(fn (ReportWorkItem $row): string => $row->key())->unique());
    }

    public function test_a_linked_check_never_completes_another_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $businessA = $this->business($folder, 'BUSINESS A');
        $businessB = $this->business($folder, 'BUSINESS B');
        $check = BusinessCheck::create([
            'client_folder_id' => $folder->id, 'income_source_id' => $businessA->id, 'ci_user_id' => $ci->id,
            'business_name' => 'BUSINESS A', 'location' => 'A Address', 'ci_date' => '2026-01-15',
        ]);

        $rows = $this->businessChecks($ci, $folder)->where('coMakerId', null);

        $completed = $rows->filter(fn (ReportWorkItem $row): bool => $row->isCompleted);
        $this->assertCount(1, $completed);
        $this->assertSame($check->id, $completed->first()->sourceId);
        $this->assertSame($businessA->id, $completed->first()->incomeSourceId);

        // Business B is still unchecked, so ONE generic entry point remains for creating the next
        // Business Check — never a Pending row named after Business B.
        $pending = $rows->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]->incomeSourceId);
        $this->assertNull($pending[0]->businessName);
        $this->assertNull($rows->firstWhere('incomeSourceId', $businessB->id));
    }

    // ---------------------------------------------------------------------
    // TEST G — the Completed row's actions use the real BusinessCheck id
    // ---------------------------------------------------------------------

    public function test_a_completed_manual_check_previews_and_downloads_through_its_own_id(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->manualCheck($ci, $folder, 'Sample Store');

        $item = $this->businessChecks($ci, $folder)->where('coMakerId', null)->first();

        $preview = $item->previewAction();
        $this->assertNotNull($preview);
        $this->assertSame(route('client-folders.residence-business-checks.batch-print', $folder->id), $preview['url']);
        $this->assertSame($check->id, $preview['fields']['business_check_ids[]']);
        // Never an invented Business Report URL for a check that has no Business Report.
        $this->assertStringNotContainsString('generated-reports', $preview['url']);
        $this->assertStringNotContainsString('income-sources', $preview['url']);

        foreach ($item->downloadActions() as $download) {
            $this->assertSame($check->id, $download['fields']['business_check_ids[]']);
            $this->assertStringNotContainsString('income-sources', $download['url']);
        }

        $this->assertSame(route('client-folders.show', $folder->id), $item->folderUrl());

        // The existing batch endpoint really accepts this manual check by its own id.
        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-print', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk();
    }

    public function test_a_co_maker_manual_check_carries_its_exact_person_into_every_action(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $check = $this->manualCheck($ci, $folder, 'CO MAKER STORE', $coMaker);

        $item = $this->businessChecks($ci, $folder)->where('coMakerId', $coMaker->id)->first();

        $this->assertTrue($item->isCompleted);
        $this->assertSame($check->id, $item->previewAction()['fields']['business_check_ids[]']);
        $this->assertSame($coMaker->id, $item->previewAction()['fields']['co_maker_id']);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $item->folderUrl());
    }

    // ---------------------------------------------------------------------
    // TEST H — no old coupling comes back
    // ---------------------------------------------------------------------

    public function test_a_manual_check_creates_nothing_and_never_prefills_a_later_business_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'Sample Store', 'location' => 'Manual Address', 'ci_date' => '2026-02-10',
            'business_photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(400)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, IncomeSource::query()->count());
        $this->assertSame(0, BusinessReport::query()->count());

        // A Business Report created afterwards through the normal workflow starts blank.
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => 'BRAND NEW BUSINESS', 'business_name' => 'BRAND NEW BUSINESS',
        ]);

        $encoding = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Sample Store', $encoding);
        $this->assertStringNotContainsString('Manual Address', $encoding);
        $this->assertStringNotContainsString('2026-02-10', $encoding);

        $this->assertNull($source->businessReport?->main_business_address);
        $this->assertNull($source->businessReport?->start_date);
        $this->assertSame(1, $source->revision);
    }

    public function test_the_business_check_form_still_offers_no_add_business_control(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->business($folder, 'ALPHA TRADING');

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('Add Business</button>', $html);
        $this->assertStringNotContainsString('data-business-check-add-new', $html);
        $this->assertStringNotContainsString('business-check-quick-add-dialog', $html);
        $this->assertFalse(app('router')->has('client-folders.income-sources.quick-create'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return Collection<int, ReportWorkItem> */
    private function items(User $ci, array $query = []): Collection
    {
        return collect($this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->viewData('items')->items());
    }

    /** @return Collection<int, ReportWorkItem> */
    private function businessChecks(User $ci, ClientFolder $folder): Collection
    {
        return $this->items($ci, ['report_type' => 'business_check'])
            ->filter(fn (ReportWorkItem $row): bool => $row->clientFolderId === $folder->id)
            ->values();
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    /** A Business Check that references no business at all — the manual, independent shape. */
    private function manualCheck(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null): BusinessCheck
    {
        return BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => null,
            'business_name' => $name,
            'location' => $name.' Address',
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $ci->id,
        ]);
    }

    /** A genuinely saved business (revision > 1 with its own Business Report). */
    private function business(ClientFolder $folder, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $name === 'ALPHA TRADING' ? 'Alpha Address' : $name.' Address',
            'start_date' => '2026-01-15',
            'report_category' => 'Leasing',
            'year_established' => 2020,
        ]);
        $source->forceFill(['revision' => 2])->save();

        return $source->fresh();
    }
}
