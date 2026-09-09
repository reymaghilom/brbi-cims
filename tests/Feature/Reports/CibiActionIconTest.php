<?php

namespace Tests\Feature\Reports;

use App\Enums\RecordState;
use App\Models\BusinessReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Reports\ReportWorkItem;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * CI / BI ACTION ICON in the global Reports workspace.
 *
 * Every Pending CI / BI row reads "Create Report" — that wording never varies. Only the icon says
 * whether there is already something to carry on from: a plus for a genuinely blank start, a pencil
 * when the exact person either has a saved (unfinished) CI / BI row or a Residence Check the CI / BI
 * form will runtime-prefill from.
 *
 * The Residence half is derived per render from current data. Saving a Residence Check still writes
 * no cibi_reports row, and deleting it simply turns the pencil back into a plus on the next render.
 */
class CibiActionIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** REQUIRED TEST 1 — nothing saved, nothing to prefill from. */
    public function test_a_fresh_cibi_row_shows_a_plus_with_create_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FRESH, CLIENT');

        $item = $this->cibiItem($ci, $folder, null);

        $this->assertSame('Create Report', $item->continueLabel());
        $this->assertSame('plus', $item->continueIcon());
        $this->assertFalse($item->hasPartialData());
        $this->assertNull($item->sourceId);
    }

    /** REQUIRED TEST 2 — Residence Check with both fields, and still no persisted CI / BI row. */
    public function test_a_residence_check_makes_the_icon_a_pencil_without_creating_a_cibi_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'PREFILLED, CLIENT');
        $this->residenceCheck($ci, $folder, null, 'Purok 6, Opol, Misamis Oriental');

        $item = $this->cibiItem($ci, $folder, null);

        $this->assertSame('Create Report', $item->continueLabel());
        $this->assertSame('edit', $item->continueIcon());
        $this->assertTrue($item->hasPartialData());
        $this->assertTrue($item->hasResidencePrefill);

        // Derived, never written: the row is still the "not started" synthesised one.
        $this->assertNull($item->sourceId);
        $this->assertDatabaseCount('cibi_reports', 0);
        // And it is still Pending, opening the same exact-person CI / BI form.
        $this->assertFalse($item->isCompleted);
        $this->assertSame('Pending', $item->statusLabel());
        $this->assertSame(route('client-folders.cibi-report.edit', $folder->id), $item->continueUrl());
    }

    /** REQUIRED TEST 3 — Location present (CI Date is NOT NULL on every check, so both are set). */
    public function test_a_residence_location_alone_is_enough_for_the_pencil(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'LOCATION ONLY, CLIENT');
        $this->residenceCheck($ci, $folder, null, 'Zone 3, Igpit, Opol');

        $item = $this->cibiItem($ci, $folder, null);

        $this->assertSame('Create Report', $item->continueLabel());
        $this->assertSame('edit', $item->continueIcon());
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    /** REQUIRED TEST 4 — the CI Date alone, with a blank Location, still prefills the Start Date. */
    public function test_a_residence_ci_date_alone_is_enough_for_the_pencil(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CI DATE ONLY, CLIENT');
        $check = $this->residenceCheck($ci, $folder, null, null);

        $this->assertNull($check->location);
        $this->assertNotNull($check->ci_date);

        $item = $this->cibiItem($ci, $folder, null);

        $this->assertSame('Create Report', $item->continueLabel());
        $this->assertSame('edit', $item->continueIcon());
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    /** REQUIRED TEST 5 — the prefill is runtime state, so removing its source removes the pencil. */
    public function test_deleting_the_residence_check_returns_the_icon_to_a_plus(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELETED SOURCE, CLIENT');
        $check = $this->residenceCheck($ci, $folder, null, 'Soon Deleted Address');

        $this->assertSame('edit', $this->cibiItem($ci, $folder, null)->continueIcon());

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $check]))->assertRedirect();

        $after = $this->cibiItem($ci, $folder, null);
        $this->assertSame('Create Report', $after->continueLabel());
        $this->assertSame('plus', $after->continueIcon());
        $this->assertFalse($after->hasPartialData());
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    /** REQUIRED TEST 6 — a saved but unfinished CI / BI row is real work, and is left untouched. */
    public function test_a_persisted_draft_shows_a_pencil_with_create_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DRAFT, CLIENT');
        $draft = CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft,
        ]);
        $before = $draft->fresh()->getRawOriginal();

        $item = $this->cibiItem($ci, $folder, null);

        $this->assertSame('Create Report', $item->continueLabel());
        $this->assertSame('edit', $item->continueIcon());
        $this->assertSame($draft->id, $item->sourceId);
        $this->assertSame('Pending', $item->statusLabel());

        // Rendering the workspace never touches the draft.
        $this->assertSame($before, $draft->fresh()->getRawOriginal());
        $this->assertDatabaseCount('cibi_reports', 1);
    }

    /** REQUIRED TEST 7 — a completed report offers outputs, never a start action of either icon. */
    public function test_a_completed_cibi_offers_preview_and_download_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'COMPLETED, CLIENT');
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete, 'completed_at' => now(),
        ]);

        $item = $this->cibiItem($ci, $folder, null);

        $this->assertTrue($item->isCompleted);
        $this->assertSame('Completed', $item->statusLabel());
        $this->assertNotNull($item->previewAction());
        $this->assertNotSame([], $item->downloadActions());

        $html = $this->listing($ci, ['report_type' => 'cibi', 'tab' => 'completed']);
        $this->assertStringNotContainsString('Create Report', $html);
        $this->assertStringNotContainsString('Continue Report', $html);
    }

    /** REQUIRED TEST 8 — an Applicant's Residence Check never lights up a Co-Maker's row. */
    public function test_an_applicant_residence_check_does_not_affect_a_co_makers_icon(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'APPLICANT ONLY, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Untouched Co Maker']);
        $this->residenceCheck($ci, $folder, null, 'Applicant Residence Address');

        $this->assertSame('edit', $this->cibiItem($ci, $folder, null)->continueIcon());
        $this->assertSame('plus', $this->cibiItem($ci, $folder, $coMaker)->continueIcon());
        $this->assertSame('Create Report', $this->cibiItem($ci, $folder, $coMaker)->continueLabel());
    }

    /** REQUIRED TEST 9 — one Co-Maker's Residence Check reaches neither the Applicant nor a sibling. */
    public function test_a_co_maker_residence_check_lights_up_only_that_exact_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CO MAKER ISOLATION, CLIENT');
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co Maker B']);
        $this->residenceCheck($ci, $folder, $coMakerA, 'Co-Maker A Residence Address');

        $this->assertSame('edit', $this->cibiItem($ci, $folder, $coMakerA)->continueIcon());
        $this->assertSame('plus', $this->cibiItem($ci, $folder, $coMakerB)->continueIcon());
        $this->assertSame('plus', $this->cibiItem($ci, $folder, null)->continueIcon());

        // The exact-person URL each row carries is unchanged by any of this.
        $this->assertSame(
            route('client-folders.cibi-report.edit', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]),
            $this->cibiItem($ci, $folder, $coMakerA)->continueUrl(),
        );
        $this->assertStringNotContainsString('co_maker_id', $this->cibiItem($ci, $folder, null)->continueUrl());
    }

    /** REQUIRED TEST 10 — the CI / BI icon rule is scoped to CI / BI and nothing else. */
    public function test_other_report_types_keep_their_existing_label_and_icon_behaviour(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'OTHER TYPES, CLIENT');
        $business = $this->business($folder, 'JUAN STORE');
        // An existing, not-yet-submitted Business Report is what makes this row a real continuation.
        BusinessReport::factory()->create(['income_source_id' => $business->id, 'business_name' => 'JUAN STORE']);
        // A Residence Check for this same person, to prove it changes nothing outside CI / BI.
        $this->residenceCheck($ci, $folder, null, 'Shared Person Address');

        $businessRow = $this->items($ci, ['report_type' => 'business_report'])
            ->first(fn (ReportWorkItem $row): bool => $row->incomeSourceId === $business->id);
        $this->assertNotNull($businessRow);

        // An existing income source with no submitted report is a genuine continuation.
        $this->assertSame('Continue Report', $businessRow->continueLabel());
        $this->assertSame('edit', $businessRow->continueIcon());

        // A Residence Check work item is completed by the check itself, so it keeps its own rules.
        $residenceRow = $this->items($ci, ['report_type' => 'residence_check'])
            ->first(fn (ReportWorkItem $row): bool => $row->clientFolderId === $folder->id);
        $this->assertNotNull($residenceRow);
        $this->assertTrue($residenceRow->isCompleted, 'A saved Residence Check is its own completed report.');
    }

    /** REQUIRED TEST 11 — the rendered Pending listing carries both icons and neither Continue. */
    public function test_the_pending_listing_renders_a_plus_row_and_a_pencil_row_and_no_continue(): void
    {
        $ci = User::factory()->create();
        $fresh = $this->folder($ci, 'AAA FRESH, CLIENT');
        $prefilled = $this->folder($ci, 'BBB PREFILLED, CLIENT');
        $this->residenceCheck($ci, $prefilled, null, 'Prefilled Residence Address');

        $freshRow = $this->cibiItem($ci, $fresh, null);
        $prefilledRow = $this->cibiItem($ci, $prefilled, null);
        $this->assertSame('plus', $freshRow->continueIcon());
        $this->assertSame('edit', $prefilledRow->continueIcon());

        $html = $this->listing($ci, ['report_type' => 'cibi', 'tab' => 'pending']);

        // Both rows read Create Report, and no CI / BI row advertises Continue Report.
        $this->assertStringContainsString('Create Report', $html);
        $this->assertStringNotContainsString('Continue Report', $html);

        // Each icon is actually rendered. x-ui.icon emits its glyph inline, so these are the exact
        // path definitions icon.blade.php uses for 'plus' and 'edit'. Two CI / BI rows, each drawn
        // as a table row and a mobile card.
        $listing = substr($html, strpos($html, 'reports-table-title'));
        $plusPath = '<path d="M12 5v14M5 12h14"/>';
        $editPath = '<path d="m4 20 4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/>';
        $this->assertSame(2, substr_count($listing, $plusPath), 'The fresh row, as a row and a card.');
        $this->assertSame(2, substr_count($listing, $editPath), 'The prefilled row, as a row and a card.');

        // Rendering created nothing.
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name,
        ]);
    }

    private function residenceCheck(User $ci, ClientFolder $folder, ?CoMaker $person, ?string $location): ResidenceCheck
    {
        return ResidenceCheck::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $person?->id,
            'ci_date' => now()->toDateString(),
            'location' => $location,
            'ci_user_id' => $ci->id,
        ]);
    }

    /** A business income source with no submitted report yet — a genuine Continue Report row. */
    private function business(ClientFolder $folder, string $name): IncomeSource
    {
        return IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('is_fallback', false)->firstOrFail()->id,
            'source_name' => $name,
            'business_name' => $name,
            'state' => RecordState::Draft,
            'revision' => 1,
        ]);
    }

    private function listing(User $ci, array $query): string
    {
        return $this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->getContent();
    }

    private function items(User $ci, array $query = []): Collection
    {
        return collect($this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->viewData('items')->items());
    }

    private function cibiItem(User $ci, ClientFolder $folder, ?CoMaker $person): ReportWorkItem
    {
        $item = $this->items($ci)->first(fn (ReportWorkItem $row): bool => $row->kind === 'cibi'
            && $row->clientFolderId === $folder->id
            && $row->coMakerId === $person?->id);
        $this->assertNotNull($item, 'Expected a cibi work item for this exact person.');

        return $item;
    }
}
