<?php

namespace Tests\Feature\Reports;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Reports\ReportWorkItem;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Focused coverage for the global Reports workspace: a central work queue and completed-report
 * library over the four report-capable modules' own authoritative records. No second table, no
 * second status engine, no GeneratedReport requirement, and no mutation from opening the page.
 */
class GlobalReportsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_page_renders_the_approved_workspace_composition(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $response = $this->actingAs($ci)->get(route('reports.index'))->assertOk();

        $response->assertDontSee('is not implemented yet')->assertDontSee('no later-phase business logic has been introduced', false);

        foreach ([
            'Reports', 'View pending and completed credit investigation reports.',
            'Total Reports', 'All report records', 'Pending', 'Reports awaiting completion',
            'Completed', 'Completed reports available to view', 'Completed This Month', 'Reports completed this month',
            'All Reports', 'All Statuses', 'Open Client Folders', 'All Report Types', 'All Client Types',
            'Client Type', 'Last Updated', 'CI / BI Report', 'Residence Check',
            'Search client name...',
        ] as $text) {
            $response->assertSee($text, false);
        }

        // "Person" was replaced everywhere it was visible; only the query parameter keeps the name.
        $this->assertStringNotContainsString('All Persons', $response->getContent());
        $this->assertStringNotContainsString('>Person<', $response->getContent());

        // The client/folder number is not part of this workspace at all.
        $this->assertStringNotContainsString('Client No.', $response->getContent());
        $this->assertStringNotContainsString('client no.', $response->getContent());

        // The filter actions are compact toolbar controls, not page-level CTAs, and they sit on
        // the same row as the controls they apply rather than on a second line of their own.
        $html = $response->getContent();
        $this->assertStringContainsString('class="ui-button-primary-compact shrink-0 px-3">Apply Filters', $html);
        $this->assertStringContainsString('xl:flex-nowrap', $html, 'The desktop toolbar is one line.');
        $this->assertStringNotContainsString('lg:grid-cols-12', $html, 'The wrapping grid toolbar is gone.');
        // Search is the one flexible control; every other one keeps a fixed compact width.
        $this->assertStringContainsString('flex-1 sm:min-w-56 xl:min-w-60', $html);
        foreach (['sm:w-40', 'sm:w-32', 'sm:w-44'] as $fixed) {
            $this->assertStringContainsString($fixed, $html);
        }

        // The retired KPI subtitle wording is gone.
        foreach (['All report work items', 'Reports pending completion'] as $retired) {
            $this->assertStringNotContainsString($retired, $response->getContent());
        }

        // The three tabs, and only the final status vocabulary.
        $response->assertSee('tab=pending', false)->assertSee('tab=completed', false);
        foreach (['Needs Completion', 'Ready Reports', 'Ready for Download', 'needs-completion',
            'Reports being generated', 'Reports with errors', 'Generated At', 'Processing', 'Failed'] as $retired) {
            $response->assertDontSee($retired, false);
        }
        // "Ready" must not survive as a status badge anywhere on the page.
        $this->assertStringNotContainsString('>Ready<', $response->getContent());
    }

    public function test_only_the_four_report_capable_modules_produce_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $this->business($folder);

        $kinds = $this->items($ci)->pluck('kind')->unique()->values()->all();

        sort($kinds);
        $this->assertSame(['business_check', 'business_report', 'cibi', 'residence_check'], $kinds);

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();
        foreach (['Barangay Check', 'Neighbor Check', 'Asset Check', 'Bank/Coop Check', 'Bank Check'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $html, $excluded.' has no report output workflow and must not be listed.');
        }
    }

    public function test_an_incomplete_cibi_report_is_pending_and_a_complete_one_is_completed(): void
    {
        $ci = User::factory()->create();
        $draftFolder = $this->folder($ci, 'DRAFT, CLIENT');
        $readyFolder = $this->folder($ci, 'READY, CLIENT');
        CibiReport::factory()->create(['client_folder_id' => $draftFolder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);
        CibiReport::factory()->create(['client_folder_id' => $readyFolder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);

        $draft = $this->item($ci, 'cibi', $draftFolder);
        $ready = $this->item($ci, 'cibi', $readyFolder);

        $this->assertFalse($draft->isCompleted);
        $this->assertSame('Pending', $draft->statusLabel());
        $this->assertSame('Continue Report', $draft->continueLabel());
        $this->assertNull($draft->previewAction(), 'An unfinished CI / BI report must not advertise a preview.');
        $this->assertSame(route('client-folders.cibi-report.edit', $draftFolder->id), $draft->continueUrl());

        $this->assertTrue($ready->isCompleted);
        $this->assertSame('Completed', $ready->statusLabel());
        $this->assertSame(
            route('client-folders.generated-reports.preview', [$readyFolder->id, 'report_type' => 'cibi']),
            $ready->previewAction()['url'],
        );
        // Only the formats the CI / BI routes really produce, each as one menu entry.
        $this->assertSame(['PDF', 'Excel'], array_column($ready->downloadActions(), 'format'));
        $this->assertSame(
            [route('client-folders.cibi-report.export-pdf', $readyFolder->id), route('client-folders.cibi-report.export-excel', $readyFolder->id)],
            array_column($ready->downloadActions(), 'url'),
        );
        $this->assertNotContains('Word', array_column($ready->downloadActions(), 'format'), 'The CI / BI report has no Word export.');
    }

    public function test_a_completed_row_renders_preview_one_download_menu_and_open_client_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);

        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'completed']))->assertOk()->getContent();

        // All three controls are icon-only and carry their label through title + aria-label.
        // Each appears twice: once in the desktop table row, once in the mobile card.
        foreach (['Preview Report', 'Download report', 'Open Client Folder'] as $label) {
            $this->assertSame(2, substr_count($html, 'aria-label="'.$label.'"'), $label.' is labelled for assistive tech.');
            $this->assertSame(2, substr_count($html, 'title="'.$label.'"'), $label.' has a tooltip.');
        }

        // Not one of those labels is rendered as visible text inside the listing itself. (The
        // page-level "Open Client Folders" CTA above the table is a different, deliberate control.)
        $listing = strip_tags(substr($html, strpos($html, 'reports-table-title')));
        foreach (['Preview Report', 'Download report', 'Open Client Folder', 'Open Folder'] as $label) {
            $this->assertStringNotContainsString($label, $listing, $label.' must not appear as visible text.');
        }

        // The Download menu lists only the formats the CI / BI routes really produce.
        $this->assertSame(2, substr_count($html, 'aria-label="Download PDF"'));
        $this->assertSame(2, substr_count($html, 'aria-label="Download Excel"'));
        $this->assertSame(2, substr_count($html, 'data-report-format-icon="pdf"'));
        $this->assertSame(2, substr_count($html, 'data-report-format-icon="excel"'));
        $this->assertStringNotContainsString('Download Word', $html, 'An unsupported format must never appear in the menu.');
        $this->assertSame(2, substr_count(strip_tags($html), 'PDF'), 'The format name stays visible inside the menu.');
        $this->assertSame(2, substr_count(strip_tags($html), 'Excel'));

        // The menu reuses the project's existing <details> menu, so no new dropdown JS is added.
        $this->assertStringContainsString('data-context-menu', $html);
        $this->assertStringContainsString('role="menuitem"', $html);

        // The folder action still points at this exact Client Folder.
        $this->assertStringContainsString(e(route('client-folders.show', $folder->id)), $html);
    }

    public function test_a_not_started_row_offers_create_and_a_started_one_offers_continue(): void
    {
        $ci = User::factory()->create();
        $fresh = $this->folder($ci, 'FRESH, CLIENT');
        $started = $this->folder($ci, 'STARTED, CLIENT');
        CibiReport::factory()->create(['client_folder_id' => $started->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);

        $create = $this->item($ci, 'cibi', $fresh);
        $continue = $this->item($ci, 'cibi', $started);

        $this->assertSame('Create Report', $create->continueLabel());
        $this->assertSame('Continue Report', $continue->continueLabel());
        $this->assertStringNotContainsString('Start Report', $this->actingAs($ci)->get(route('reports.index'))->getContent());

        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'pending']))->assertOk()->getContent();

        // Both share one compact outlined style — neither may look visually heavier than the other,
        // and neither is a full-size primary CTA. Scoped to the listing, since the toolbar above it
        // legitimately owns primary buttons of its own.
        $listing = substr($html, strpos($html, 'reports-table-title'));
        $this->assertSame(4, substr_count($listing, 'ui-button-secondary-compact px-2.5 text-brand-primary'), 'Two rows, each as a table row and a card.');
        $this->assertStringNotContainsString('ui-button-primary-compact', $listing, 'Continue Report must not carry a stronger filled treatment.');
        $this->assertStringNotContainsString('ui-button-primary ', $listing);

        // The folder icon sits alongside both, at the same size as everywhere else.
        $this->assertSame(4, substr_count($listing, 'aria-label="Open Client Folder"'), 'Two rows, each rendered as a table row and a card.');
    }

    public function test_a_pending_row_offers_only_continue_and_open_client_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'PENDING, CLIENT');

        $html = $this->actingAs($ci)->get(route('reports.index', ['tab' => 'pending']))->assertOk()->getContent();

        $this->assertStringContainsString('Create Report', $html);
        $this->assertStringContainsString('aria-label="Open Client Folder"', $html);
        $this->assertStringNotContainsString('Preview Report', $html, 'A pending report must not expose Preview.');
        $this->assertStringNotContainsString('Download report', $html, 'A pending report must not expose Download.');
        $this->assertStringNotContainsString('Download PDF', $html);
        $this->assertStringNotContainsString('Download Word', $html);
        $this->assertStringNotContainsString('Download Excel', $html);
        $this->assertNotNull($folder->id);
    }

    public function test_the_check_download_menu_offers_pdf_and_word_but_never_excel(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CHECKED, CLIENT');
        $check = ResidenceCheck::create(['client_folder_id' => $folder->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id]);
        $item = $this->item($ci, 'residence_check', $folder);

        $this->assertSame(['PDF', 'Word'], array_column($item->downloadActions(), 'format'));
        $this->assertSame(['file-pdf', 'file-word'], array_column($item->downloadActions(), 'icon'));
        $this->assertNotContains('Excel', array_column($item->downloadActions(), 'format'), 'The photo checks have no Excel export.');

        // Both formats still address this one check, under this exact person.
        foreach ($item->downloadActions() as $download) {
            $this->assertSame(['residence_check_ids[]' => $check->id], $download['fields']);
            $this->assertSame('POST', $download['method']);
        }
        $this->assertSame(
            [route('client-folders.residence-business-checks.batch-export-pdf', $folder->id), route('client-folders.residence-business-checks.batch-export-docx', $folder->id)],
            array_column($item->downloadActions(), 'url'),
        );
    }

    public function test_the_mobile_card_uses_the_same_compact_action_model(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);

        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'completed']))->assertOk()->getContent();
        $cardStart = strpos($html, 'data-report-card');
        $table = substr($html, 0, $cardStart);
        $card = substr($html, $cardStart);

        // The card carries the identical three icon triggers as the desktop row.
        foreach (['Preview Report', 'Download report', 'Open Client Folder'] as $label) {
            $this->assertStringContainsString('aria-label="'.$label.'"', $card);
            $this->assertStringContainsString('aria-label="'.$label.'"', $table);
        }
        // Only the menu's format names remain visible text, on both.
        $this->assertStringContainsString('PDF', strip_tags($card));
        $this->assertStringContainsString('Excel', strip_tags($card));
        $this->assertStringNotContainsString('Download report', strip_tags($card));
        $this->assertStringNotContainsString('Download report', strip_tags($table));
    }

    public function test_a_not_started_cibi_report_is_a_derived_row_with_a_start_action(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');

        $item = $this->item($ci, 'cibi', $folder);

        $this->assertNull($item->sourceId, 'A not-started work item is derived, never stored.');
        $this->assertFalse($item->isCompleted);
        $this->assertSame('Pending', $item->statusLabel());
        $this->assertSame('Create Report', $item->continueLabel());
        $this->assertSame(route('client-folders.cibi-report.edit', $folder->id), $item->continueUrl());
    }

    public function test_each_business_keeps_its_own_business_report_row_and_never_opens_another(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $businessA = $this->business($folder, 'BUSINESS A');
        $businessB = $this->business($folder, 'BUSINESS B', complete: true);

        $rows = $this->items($ci)->where('kind', 'business_report')->values();

        $this->assertCount(2, $rows, 'Two businesses are two independent Business Report work items.');

        $rowA = $rows->firstWhere('incomeSourceId', $businessA->id);
        $rowB = $rows->firstWhere('incomeSourceId', $businessB->id);

        $this->assertFalse($rowA->isCompleted, 'An unsubmitted Business Report stays Pending.');
        $this->assertTrue($rowB->isCompleted);
        $this->assertSame('BUSINESS A', $rowA->businessName);
        $this->assertSame(route('client-folders.income-sources.edit', [$folder->id, $businessA->id]), $rowA->continueUrl());
        $this->assertStringContainsString('income_source_id='.$businessB->id, $rowB->previewAction()['url']);
        $this->assertSame(route('client-folders.income-sources.export-pdf', [$folder->id, $businessB->id]), $rowB->downloadActions()[0]['url']);

        // Business A's own action can never reach Business B.
        $this->assertStringNotContainsString('/'.$businessB->id, $rowA->continueUrl());
    }

    public function test_a_saved_residence_check_is_completed_and_a_missing_one_is_pending(): void
    {
        $ci = User::factory()->create();
        $empty = $this->folder($ci, 'EMPTY, CLIENT');
        $checked = $this->folder($ci, 'CHECKED, CLIENT');
        $check = ResidenceCheck::create(['client_folder_id' => $checked->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id]);

        $missing = $this->item($ci, 'residence_check', $empty);
        $ready = $this->item($ci, 'residence_check', $checked);

        $this->assertFalse($missing->isCompleted, 'No ResidenceCheck row yet means Pending.');
        $this->assertSame('Create Report', $missing->continueLabel());
        $this->assertNull($missing->previewAction(), 'A pending Residence Check must not expose Preview.');
        $this->assertSame(route('client-folders.residence-checks.create', $empty->id), $missing->continueUrl());

        $this->assertTrue($ready->isCompleted, 'Saving the check through its own workflow completes it.');
        $preview = $ready->previewAction();
        $this->assertSame(route('client-folders.residence-business-checks.batch-print', $checked->id), $preview['url']);
        $this->assertSame(['residence_check_ids[]' => $check->id], $preview['fields']);
        $this->assertSame(['Download PDF', 'Download Word'], array_column($ready->downloadActions(), 'label'));
    }

    public function test_a_business_check_keeps_its_exact_income_source_and_never_opens_another(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $businessA = $this->business($folder, 'BUSINESS A');
        $businessB = $this->business($folder, 'BUSINESS B');
        $checkA = BusinessCheck::create(['client_folder_id' => $folder->id, 'income_source_id' => $businessA->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id]);

        $rows = $this->items($ci)->where('kind', 'business_check')->values();
        $rowA = $rows->firstWhere('incomeSourceId', $businessA->id);

        $this->assertTrue($rowA->isCompleted, 'Business A own saved check completes Business A.');
        $this->assertSame(['business_check_ids[]' => $checkA->id], $rowA->previewAction()['fields']);
        $this->assertSame('BUSINESS A', $rowA->businessName);

        // Business B is still unchecked, but that does NOT earn it a Business Check row of its own:
        // Business A's saved check never completes it, and the only Pending row is the single
        // generic create entry point for this person, carrying no business identity at all.
        $this->assertNull($rows->firstWhere('incomeSourceId', $businessB->id));
        $pending = $rows->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]->incomeSourceId);
        $this->assertNull($pending[0]->businessName);
        $this->assertNull($pending[0]->previewAction());
        $this->assertSame(
            route('client-folders.business-checks.create', $folder->id),
            $pending[0]->continueUrl(),
        );
    }

    public function test_applicant_and_co_maker_rows_stay_separate_and_carry_their_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'co_maker_id' => $coMaker->id, 'state' => RecordState::Complete, 'completed_at' => now()]);

        $rows = $this->items($ci)->where('kind', 'cibi')->values();
        $applicant = $rows->firstWhere('coMakerId', null);
        $theirs = $rows->firstWhere('coMakerId', $coMaker->id);

        $this->assertNotNull($applicant);
        $this->assertNotNull($theirs);
        $this->assertFalse($applicant->isCompleted, "The Co-Maker's completed report must never mark the Applicant complete.");
        $this->assertTrue($theirs->isCompleted);

        // Person context survives every action.
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $theirs->previewAction()['url']);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $theirs->continueUrl());
        foreach ($theirs->downloadActions() as $download) {
            $this->assertSame(['co_maker_id' => $coMaker->id], $download['fields'], 'Every format keeps the exact Co-Maker.');
        }
        $this->assertStringNotContainsString('co_maker_id', $applicant->continueUrl());

        // Another Co-Maker's id can never reach this one's report.
        $other = CoMaker::create(['client_folder_id' => $this->folder($ci, 'BRAVO, CLIENT')->id, 'full_name' => 'Other Co-Maker']);
        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $other->id]))->assertNotFound();
    }

    public function test_the_list_covers_the_shared_workspace_but_never_a_recycled_folder(): void
    {
        $ci = User::factory()->create();
        $colleague = User::factory()->create();

        $mine = $this->folder($ci, 'ALPHA, CLIENT');
        $theirs = $this->folder($colleague, 'BRAVO, COLLEAGUE');
        $trashed = $this->folder($ci, 'ZULU, RECYCLED');
        $trashed->delete();

        $folderIds = $this->items($ci)->pluck('clientFolderId')->unique();

        $this->assertTrue($folderIds->contains($mine->id));
        $this->assertTrue($folderIds->contains($theirs->id), 'Active folders are a shared CI team workspace.');
        $this->assertFalse($folderIds->contains($trashed->id), 'A recycled folder contributes no work items.');

        auth()->logout();
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_the_kpi_cards_count_the_real_work_queue(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        ResidenceCheck::create(['client_folder_id' => $folder->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id]);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);

        $summary = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->viewData('summary');
        $items = $this->items($ci);

        $this->assertSame($items->count(), $summary['total']);
        $this->assertSame($items->where('isCompleted', false)->count(), $summary['pending']);
        $this->assertSame($items->where('isCompleted', true)->count(), $summary['completed']);
        $this->assertSame(2, $summary['completed'], 'The saved Residence Check and the completed CI / BI report are both completed.');
        $this->assertSame(2, $summary['completed_this_month'], 'Both were completed in the current month.');
    }

    public function test_the_tabs_split_the_queue_into_pending_and_completed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        ResidenceCheck::create(['client_folder_id' => $folder->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id]);

        $all = $this->items($ci);
        $pending = $this->items($ci, ['tab' => 'pending']);
        $completed = $this->items($ci, ['tab' => 'completed']);

        $this->assertSame($all->count(), $pending->count() + $completed->count());
        $this->assertTrue($pending->every(fn ($item) => $item->isCompleted === false));
        $this->assertTrue($completed->every(fn ($item) => $item->isCompleted === true));
        $this->assertSame(1, $completed->count());
    }

    public function test_search_report_type_person_and_date_filters_each_narrow_the_queue(): void
    {
        $ci = User::factory()->create();
        $wanted = $this->folder($ci, 'DELA CRUZ, JUAN', 'BRBI-CI-2026-00777');
        $other = $this->folder($ci, 'SANTOS, MARIA', 'BRBI-CI-2026-00888');
        $coMaker = CoMaker::create(['client_folder_id' => $wanted->id, 'full_name' => 'Filter Co-Maker']);
        $business = $this->business($other, 'SARI-SARI STORE');

        $folders = fn (array $query) => $this->items($ci, $query)->pluck('clientFolderId')->unique();

        // Client name — full and partial — is the one thing this box searches.
        $this->assertSame([$wanted->id], $folders(['search' => 'DELA CRUZ'])->all());
        $this->assertSame([$wanted->id], $folders(['search' => 'DELA'])->all());
        $this->assertSame([$other->id], $folders(['search' => 'SANTOS'])->all());

        // Nothing else is searched: not the client number, not the Co-Maker, not the business,
        // not the report type.
        foreach (['00888', 'BRBI-CI-2026', 'Filter Co-Maker', 'SARI-SARI', 'Business Report', 'cibi'] as $offScope) {
            $this->assertCount(0, $this->items($ci, ['search' => $offScope]), $offScope.' is outside the search scope.');
        }
        $this->assertNotNull($business->id);

        $this->assertTrue($this->items($ci, ['report_type' => 'business_report'])->every(fn ($item) => $item->kind === 'business_report'));
        $businessReportItems = $this->items($ci, ['report_type' => 'business_report']);
        $this->assertSame(2, $businessReportItems->whereNull('incomeSourceId')->count(), 'The two zero-business people keep their virtual rows.');
        $this->assertSame([$business->id], $businessReportItems->whereNotNull('incomeSourceId')->pluck('incomeSourceId')->all());

        $this->assertTrue($this->items($ci, ['person' => 'applicant'])->every(fn ($item) => $item->coMakerId === null));
        $this->assertTrue($this->items($ci, ['person' => 'co_maker'])->every(fn ($item) => $item->coMakerId === $coMaker->id));

        // A window that ends before anything was touched excludes every row.
        $this->assertCount(0, $this->items($ci, ['to' => now(config('cims.display_timezone'))->subYear()->toDateString()]));
        $this->assertGreaterThan(0, $this->items($ci, ['from' => now(config('cims.display_timezone'))->toDateString()])->count());
    }

    public function test_the_toolbar_uses_one_compact_date_range_control_instead_of_two_fields(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();
        $toolbar = substr($html, strpos($html, 'Filter reports'), strpos($html, 'Apply Filters') - strpos($html, 'Filter reports'));

        // One trigger, with its default label.
        $this->assertSame(1, substr_count($toolbar, 'data-reports-date-range'));
        $this->assertStringContainsString('Select Date Range', $toolbar);

        // Both date inputs still exist under their unchanged names, but only inside the popover.
        $this->assertStringContainsString('name="from"', $toolbar);
        $this->assertStringContainsString('name="to"', $toolbar);
        $popover = substr($toolbar, strpos($toolbar, 'data-reports-date-range'));
        $this->assertStringContainsString('name="from"', $popover, 'The From field lives inside the popover, not the toolbar row.');
        $this->assertStringContainsString('name="to"', $popover);
        $this->assertStringContainsString('Clear', $popover);
        $this->assertStringContainsString('Apply', $popover);

        // The retired always-visible toolbar fields are gone.
        $this->assertStringNotContainsString('aria-label="Updated from"', $html);
        $this->assertStringNotContainsString('aria-label="Updated to"', $html);
    }

    public function test_the_trigger_reads_back_whichever_half_of_the_range_is_set(): void
    {
        $ci = User::factory()->create();

        $label = fn (array $query): string => $this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->getContent();

        $this->assertStringContainsString('Sep 1 – Sep 5, 2026', $label(['from' => '2026-09-01', 'to' => '2026-09-05']));
        $this->assertStringContainsString('Dec 30, 2025 – Jan 2, 2026', $label(['from' => '2025-12-30', 'to' => '2026-01-02']));
        $this->assertStringContainsString('From Sep 1, 2026', $label(['from' => '2026-09-01']));
        $this->assertStringContainsString('Until Sep 5, 2026', $label(['to' => '2026-09-05']));
        $this->assertStringContainsString('Select Date Range', $label([]));
    }

    public function test_the_client_type_filter_keeps_its_person_parameter_and_behavior(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);

        // The visible label changed; the query parameter and the filtering did not.
        $this->assertTrue($this->items($ci, ['person' => 'applicant'])->every(fn ($item) => $item->coMakerId === null));
        $this->assertTrue($this->items($ci, ['person' => 'co_maker'])->every(fn ($item) => $item->coMakerId === $coMaker->id));
        $this->assertGreaterThan(0, $this->items($ci, ['person' => 'co_maker'])->count());

        $html = $this->actingAs($ci)->get(route('reports.index', ['person' => 'co_maker']))->assertOk()->getContent();
        $this->assertStringContainsString('name="person"', $html);
        $this->assertStringContainsString('Client Type', $html);

        // Clear stays compact and lighter than Apply Filters, on the same baseline.
        $this->assertStringContainsString('aria-label="Clear filters">Clear', $html);
        $this->assertStringContainsString('class="ui-button-secondary-compact shrink-0 px-2.5"', $html);
        $this->assertStringContainsString('class="ui-button-primary-compact shrink-0 px-3">Apply Filters', $html);
        $this->assertStringNotContainsString('>Reset<', $html, 'The toolbar action reads Clear now.');
    }

    public function test_the_date_range_filters_survives_other_filters_and_can_be_cleared(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $today = now(config('cims.display_timezone'))->toDateString();

        // A valid range still narrows the queue through the unchanged from/to parameters.
        $this->assertGreaterThan(0, $this->items($ci, ['from' => $today, 'to' => $today])->count());
        $this->assertCount(0, $this->items($ci, ['to' => now(config('cims.display_timezone'))->subYear()->toDateString()]));

        $response = $this->actingAs($ci)->get(route('reports.index', [
            'tab' => 'pending', 'search' => 'ALPHA', 'report_type' => 'cibi', 'person' => 'applicant',
            'from' => $today, 'to' => $today,
        ]))->assertOk();
        $html = $response->getContent();

        // Clear drops only the two date parameters and keeps everything else.
        $clearUrl = route('reports.index', ['tab' => 'pending', 'search' => 'ALPHA', 'report_type' => 'cibi', 'person' => 'applicant']);
        $this->assertStringContainsString(e($clearUrl), $html);

        // Switching tabs carries the range along.
        $this->assertStringContainsString(e('from='.$today), $html);
        $this->assertStringContainsString('tab=completed', str_replace('&amp;', '&', $html));
        $this->assertNotNull($folder->id);
    }

    public function test_a_reversed_date_range_is_rejected_cleanly(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $this->actingAs($ci)
            ->get(route('reports.index', ['from' => '2026-09-05', 'to' => '2026-09-01']))
            ->assertRedirect()
            ->assertSessionHasErrors('to');
    }

    public function test_every_listed_column_but_the_actions_one_is_sortable_in_both_directions(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT', 'BRBI-CI-2026-00111');
        $this->folder($ci, 'ZULU, CLIENT', 'BRBI-CI-2026-00999');

        $response = $this->actingAs($ci)->get(route('reports.index'))->assertOk();
        $html = $response->getContent();

        // The header markup follows the CI Activities sortable pattern: an aria-sort <th> wrapping
        // a control that carries the column label and the stacked asc/desc arrows.
        foreach (['client', 'client_type', 'report_type', 'status', 'updated'] as $key) {
            $this->assertStringContainsString('data-reports-sort-header="'.$key.'"', $html);
            $this->assertStringContainsString('data-reports-sort="'.$key.'"', $html);
        }
        $this->assertSame(5, substr_count($html, 'aria-sort='), 'Only the five data columns sort.');
        $this->assertSame(5, substr_count($html, 'data-sort-arrow="asc"'));
        $this->assertStringContainsString('<span class="sr-only">Actions</span>', $html);

        // The actions column, the row-number column and the retired client number never sort.
        $this->assertStringNotContainsString('data-reports-sort="actions"', $html);
        $this->assertStringNotContainsString('data-reports-sort="client_no"', $html);
        $this->assertStringNotContainsString('data-reports-sort-header="client_no"', $html);

        // Sorting never drops or duplicates rows, whichever column and direction is chosen.
        $total = $this->items($ci)->count();
        foreach (['client', 'client_type', 'report_type', 'status', 'updated'] as $key) {
            foreach (['asc', 'desc'] as $direction) {
                $this->assertCount($total, $this->items($ci, ['sort' => $key, 'direction' => $direction]), $key.' '.$direction.' returns the same rows.');
            }
        }
    }

    public function test_each_sortable_column_orders_by_its_own_value_in_both_directions(): void
    {
        $ci = User::factory()->create();
        $alpha = $this->folder($ci, 'ALPHA, CLIENT', 'BRBI-CI-2026-00111');
        $zulu = $this->folder($ci, 'ZULU, CLIENT', 'BRBI-CI-2026-00999');
        // A Co-Maker gives Client Type two distinct values, and a completed report gives Status two.
        CoMaker::create(['client_folder_id' => $zulu->id, 'full_name' => 'Sort Co-Maker']);
        CibiReport::factory()->create(['client_folder_id' => $alpha->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()->subDay()]);

        $first = fn (string $key, string $direction) => $this->items($ci, ['sort' => $key, 'direction' => $direction])->first();

        // Client: plain alphabetical, both ways.
        $this->assertSame('ALPHA, CLIENT', $first('client', 'asc')->clientName);
        $this->assertSame('ZULU, CLIENT', $first('client', 'desc')->clientName);

        // The retired client-number key is rejected rather than silently ignored.
        $this->actingAs($ci)->get(route('reports.index', ['sort' => 'client_no']))
            ->assertRedirect()->assertSessionHasErrors('sort');

        // Client Type: Applicant rows first ascending, Co-Maker rows first descending.
        $this->assertNull($first('client_type', 'asc')->coMakerId);
        $this->assertNotNull($first('client_type', 'desc')->coMakerId);

        // Report Type: by the type itself, not by client.
        $this->assertSame('business_check', $first('report_type', 'asc')->kind);
        $this->assertSame('residence_check', $first('report_type', 'desc')->kind);

        // Status: Pending first ascending (the actionable end), Completed first descending.
        $this->assertFalse($first('status', 'asc')->isCompleted);
        $this->assertTrue($first('status', 'desc')->isCompleted);

        // Last Updated: oldest first ascending, newest first descending.
        $this->assertTrue($first('updated', 'asc')->lastUpdatedAt->lessThanOrEqualTo($first('updated', 'desc')->lastUpdatedAt));
        $this->assertTrue($first('updated', 'desc')->lastUpdatedAt->greaterThanOrEqualTo($first('updated', 'asc')->lastUpdatedAt));
        $this->assertSame('cibi', $first('updated', 'asc')->kind, 'The day-old completion is the oldest row.');
    }

    public function test_an_unknown_sort_key_is_rejected_rather_than_reaching_the_query(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $this->actingAs($ci)->get(route('reports.index', ['sort' => 'client_name; drop table users']))
            ->assertRedirect()->assertSessionHasErrors('sort');
        $this->actingAs($ci)->get(route('reports.index', ['sort' => 'client', 'direction' => 'sideways']))
            ->assertRedirect()->assertSessionHasErrors('direction');
    }

    public function test_sorting_keeps_every_filter_and_returns_to_the_first_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $today = now(config('cims.display_timezone'))->toDateString();

        $query = [
            'tab' => 'pending', 'search' => 'ALPHA', 'report_type' => 'cibi', 'person' => 'applicant',
            'from' => $today, 'to' => $today, 'sort' => 'client', 'direction' => 'desc',
        ];
        $response = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk();
        $html = str_replace('&amp;', '&', $response->getContent());

        // The sort really applied alongside the filters.
        $this->assertTrue($response->viewData('items')->every(fn ($item) => $item->kind === 'cibi'));
        $this->assertSame('client', $response->viewData('sort'));
        $this->assertSame('desc', $response->viewData('direction'));

        // Every sort link carries the whole filter set forward, and never a page number — so
        // changing the sort always lands back on page 1.
        foreach (['tab=pending', 'search=ALPHA', 'report_type=cibi', 'person=applicant', 'from='.$today, 'to='.$today] as $carried) {
            $this->assertStringContainsString($carried, $html, $carried.' survives a sort click.');
        }
        $this->assertStringNotContainsString('sort=client&direction=asc&page=', $html);
        $this->assertNotNull($folder->id);
    }

    public function test_pagination_carries_the_active_sort(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        foreach (range(1, 17) as $index) {
            $this->business($folder, 'BUSINESS '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        $page = $this->actingAs($ci)->get(route('reports.index', [
            'report_type' => 'business_report', 'sort' => 'client', 'direction' => 'desc', 'page' => 2,
        ]))->assertOk();

        $this->assertSame(17, $page->viewData('items')->total());
        $this->assertSame(2, $page->viewData('items')->currentPage());
        $page->assertSee('sort=client', false)->assertSee('direction=desc', false);
    }

    public function test_a_sort_click_can_fetch_the_listing_alone_without_a_full_page_reload(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $fragment = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('reports.index', ['sort' => 'client', 'direction' => 'asc']))
            ->assertOk();
        $html = $fragment->getContent();

        // The listing arrives on its own — no layout, no header, no KPI cards to re-render.
        $this->assertStringNotContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('Total Reports', $html);
        $this->assertStringNotContainsString('Open Client Folders', $html);
        $this->assertStringContainsString('data-reports-sort="client"', $html);
        $this->assertStringContainsString('data-report-card', $html);
    }

    public function test_client_suggestions_are_bounded_scoped_and_carry_their_exact_folder(): void
    {
        $ci = User::factory()->create();
        $wanted = $this->folder($ci, 'DELA CRUZ, JUAN');
        $namesake = $this->folder($ci, 'DELA CRUZ, JUAN');
        $other = $this->folder($ci, 'SANTOS, MARIA');
        $recycled = $this->folder($ci, 'DELA CRUZ, RECYCLED');
        $recycled->delete();

        $suggestions = $this->actingAs($ci)
            ->getJson(route('reports.client-suggestions', ['q' => 'DELA']))
            ->assertOk()->json('suggestions');

        $ids = array_column($suggestions, 'id');
        $names = array_column($suggestions, 'name');

        // Two folders share a display name and both survive as separate authoritative results.
        $this->assertContains($wanted->id, $ids);
        $this->assertContains($namesake->id, $ids);
        $this->assertSame(['DELA CRUZ, JUAN', 'DELA CRUZ, JUAN'], $names);
        $this->assertNotContains($other->id, $ids);
        $this->assertNotContains($recycled->id, $ids, 'A recycled folder must never be suggested.');

        // Bounded, and quiet until there is something to match on.
        foreach (range(1, 12) as $index) {
            $this->folder($ci, 'MATCHY CLIENT '.$index);
        }
        $this->assertCount(8, $this->actingAs($ci)->getJson(route('reports.client-suggestions', ['q' => 'MATCHY']))->assertOk()->json('suggestions'));
        $this->assertSame([], $this->actingAs($ci)->getJson(route('reports.client-suggestions', ['q' => 'D']))->assertOk()->json('suggestions'));
        $this->assertSame([], $this->actingAs($ci)->getJson(route('reports.client-suggestions'))->assertOk()->json('suggestions'));

        auth()->logout();
        $this->getJson(route('reports.client-suggestions', ['q' => 'DELA']))->assertUnauthorized();
    }

    public function test_selecting_an_exact_client_never_pulls_in_a_namesake(): void
    {
        $ci = User::factory()->create();
        $wanted = $this->folder($ci, 'DELA CRUZ, JUAN');
        $namesake = $this->folder($ci, 'DELA CRUZ, JUAN');

        // Free-typing the shared name matches both folders...
        $byName = $this->items($ci, ['search' => 'DELA CRUZ, JUAN'])->pluck('clientFolderId')->unique();
        $this->assertTrue($byName->contains($wanted->id));
        $this->assertTrue($byName->contains($namesake->id));

        // ...while selecting one from the suggestions pins that exact folder.
        $selected = $this->items($ci, ['search' => 'DELA CRUZ, JUAN', 'client_folder_id' => $wanted->id])
            ->pluck('clientFolderId')->unique();
        $this->assertSame([$wanted->id], $selected->all());

        // A recycled or unknown folder id is rejected outright, never silently ignored.
        $namesake->delete();
        $this->actingAs($ci)->get(route('reports.index', ['client_folder_id' => $namesake->id]))
            ->assertRedirect()->assertSessionHasErrors('client_folder_id');
        $this->actingAs($ci)->get(route('reports.index', ['client_folder_id' => 999999]))
            ->assertRedirect()->assertSessionHasErrors('client_folder_id');
    }

    public function test_a_selected_client_keeps_the_tab_filters_and_sort_and_starts_at_page_one(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);
        $today = now(config('cims.display_timezone'))->toDateString();

        // The tab narrows the same client's rows rather than being dropped.
        $pending = $this->items($ci, ['client_folder_id' => $folder->id, 'tab' => 'pending']);
        $completed = $this->items($ci, ['client_folder_id' => $folder->id, 'tab' => 'completed']);
        $all = $this->items($ci, ['client_folder_id' => $folder->id]);
        $this->assertTrue($pending->every(fn ($item) => $item->isCompleted === false));
        $this->assertTrue($completed->every(fn ($item) => $item->isCompleted === true));
        $this->assertSame($all->count(), $pending->count() + $completed->count());
        // All Reports keeps every eligible type for that client, not just one.
        $this->assertGreaterThan(1, $all->pluck('kind')->unique()->count());

        // Every other filter and the active sort ride along, and the search form never carries a page.
        $query = [
            'search' => 'DELA CRUZ, JUAN', 'client_folder_id' => $folder->id, 'tab' => 'all',
            'report_type' => 'cibi', 'person' => 'applicant', 'from' => $today, 'to' => $today,
            'sort' => 'client', 'direction' => 'desc',
        ];
        $response = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk();
        $html = str_replace('&amp;', '&', $response->getContent());

        $this->assertSame('client', $response->viewData('sort'));
        $this->assertSame('desc', $response->viewData('direction'));
        $this->assertTrue($response->viewData('items')->every(fn ($item) => $item->clientFolderId === $folder->id));

        // The filter form carries the sort and the exact client, and no page number.
        $this->assertStringContainsString('name="client_folder_id" value="'.$folder->id.'"', $html);
        $this->assertStringContainsString('name="sort" value="client"', $html);
        $this->assertStringContainsString('name="direction" value="desc"', $html);
        $this->assertStringNotContainsString('name="page"', $html);

        // Pagination links keep the selected client.
        $this->assertStringContainsString('client_folder_id='.$folder->id, $html);
    }

    public function test_the_clear_action_drops_every_filter_but_keeps_the_current_tab(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $today = now(config('cims.display_timezone'))->toDateString();

        $query = [
            'tab' => 'pending', 'search' => 'DELA', 'client_folder_id' => $folder->id,
            'report_type' => 'cibi', 'person' => 'applicant', 'from' => $today, 'to' => $today,
        ];
        $html = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->getContent();

        // Clear points at the same tab with nothing else attached — search, exact client, report
        // type, client type and the date range all go.
        $this->assertStringContainsString(e(route('reports.index', ['tab' => 'pending'])).'" class="ui-button-secondary-compact', $html);

        // And following it really does restore the unfiltered (but still Pending) list.
        $filtered = $this->items($ci, $query);
        $cleared = $this->items($ci, ['tab' => 'pending']);
        $this->assertGreaterThan($filtered->count(), $cleared->count());
        $this->assertTrue($cleared->every(fn ($item) => $item->isCompleted === false), 'The tab survives Clear.');
    }

    public function test_clearing_the_client_search_restores_the_rest_of_the_view(): void
    {
        $ci = User::factory()->create();
        $wanted = $this->folder($ci, 'DELA CRUZ, JUAN');
        $this->folder($ci, 'SANTOS, MARIA');

        $filtered = $this->items($ci, ['search' => 'DELA CRUZ', 'client_folder_id' => $wanted->id, 'tab' => 'pending']);
        $cleared = $this->items($ci, ['tab' => 'pending']);

        $this->assertTrue($filtered->every(fn ($item) => $item->clientFolderId === $wanted->id));
        $this->assertGreaterThan($filtered->count(), $cleared->count(), 'Clearing the client restores the wider list.');
        $this->assertTrue($cleared->every(fn ($item) => $item->isCompleted === false), 'The tab is still respected.');
    }

    public function test_the_search_box_is_an_accessible_combobox_and_mutates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $before = DB::table('client_folders')->count();

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();

        foreach (['role="combobox"', 'aria-expanded="false"', 'aria-controls="reports-client-suggestions"',
            'aria-autocomplete="list"', 'role="listbox"', 'data-reports-client-search',
            'data-reports-client-suggestions', 'data-reports-client-id'] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
        $this->assertStringContainsString(e(route('reports.client-suggestions')), $html);

        // Asking for suggestions is a read, and so is searching.
        $this->actingAs($ci)->getJson(route('reports.client-suggestions', ['q' => 'DELA']))->assertOk();
        $this->actingAs($ci)->get(route('reports.index', ['search' => 'DELA', 'client_folder_id' => $folder->id]))->assertOk();
        $this->assertSame($before, DB::table('client_folders')->count());
        $this->assertSame(0, GeneratedReport::query()->count());
    }

    public function test_one_typed_name_drives_both_the_suggestions_and_the_live_listing(): void
    {
        $ci = User::factory()->create();
        $juan = $this->folder($ci, 'DELA CRUZ, JUAN');
        $maria = $this->folder($ci, 'DELA CRUZ, MARIA');
        $other = $this->folder($ci, 'SANTOS, PEDRO');

        // The half-typed name the live search sends while the user is still typing.
        $names = array_column(
            $this->actingAs($ci)->getJson(route('reports.client-suggestions', ['q' => 'DELA']))->assertOk()->json('suggestions'),
            'name'
        );
        $this->assertSame(['DELA CRUZ, JUAN', 'DELA CRUZ, MARIA'], $names);

        // The very same term, on the fragment the results region swaps in — no suggestion picked.
        $fragment = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('reports.index', ['search' => 'DELA']))
            ->assertOk();
        $html = $fragment->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $html, 'Only the listing is replaced.');
        $this->assertStringContainsString('DELA CRUZ, JUAN', $html);
        $this->assertStringContainsString('DELA CRUZ, MARIA', $html);
        $this->assertStringNotContainsString('SANTOS, PEDRO', $html);

        // A single character already filters the table, matching the Client Folders live search.
        $folders = $this->items($ci, ['search' => 'S'])->pluck('clientFolderId')->unique();
        $this->assertTrue($folders->contains($other->id));
        $this->assertNotNull($juan->id);
        $this->assertNotNull($maria->id);
    }

    public function test_live_search_keeps_every_other_filter_the_tab_and_the_sort(): void
    {
        $ci = User::factory()->create();
        $juan = $this->folder($ci, 'DELA CRUZ, JUAN');
        $this->folder($ci, 'SANTOS, PEDRO');
        $business = $this->business($juan, 'JUAN SARI-SARI', complete: true);

        // Exactly the query the live search builds from the form: the typed name plus everything
        // already applied, and never a page number.
        $query = [
            'search' => 'DELA', 'tab' => 'completed', 'report_type' => 'business_report',
            'person' => 'applicant', 'sort' => 'client', 'direction' => 'desc',
        ];
        $items = $this->items($ci, $query);

        $this->assertGreaterThan(0, $items->count());
        $this->assertTrue($items->every(fn ($item) => $item->clientFolderId === $juan->id), 'The typed name still applies.');
        $this->assertTrue($items->every(fn ($item) => $item->isCompleted), 'The Completed tab survives typing.');
        $this->assertTrue($items->every(fn ($item) => $item->kind === 'business_report'), 'The Report Type filter survives typing.');
        $this->assertSame([$business->id], $items->pluck('incomeSourceId')->unique()->all());

        $response = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk();
        $this->assertSame('client', $response->viewData('sort'));
        $this->assertSame('desc', $response->viewData('direction'));

        // Clearing the typed name restores the wider set, with the same filters still applied.
        $cleared = $this->items($ci, collect($query)->except('search')->all());
        $this->assertGreaterThanOrEqual($items->count(), $cleared->count());
        $this->assertTrue($cleared->every(fn ($item) => $item->isCompleted && $item->kind === 'business_report'));
    }

    public function test_the_live_search_field_is_wired_for_immediate_filtering(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $before = DB::table('client_folders')->count();

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();

        // The search sits inside the filter form but drives the results region directly, so it
        // never needs the Apply Filters button.
        $this->assertStringContainsString('data-reports-client-search', $html);
        $this->assertStringContainsString('data-reports-client-input', $html);
        $this->assertStringContainsString('data-reports-listing', $html);
        $this->assertStringContainsString('placeholder="Search client name..."', $html);

        // Typing is a read: nothing is created by searching or by asking for suggestions.
        $this->actingAs($ci)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('reports.index', ['search' => 'DE']))->assertOk();
        $this->actingAs($ci)->getJson(route('reports.client-suggestions', ['q' => 'DE']))->assertOk();

        $this->assertSame($before, DB::table('client_folders')->count());
        $this->assertSame(0, GeneratedReport::query()->count());
        $this->assertNotNull($folder->id);
    }

    public function test_create_report_opens_the_existing_cibi_page_in_the_shared_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);

        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'tab' => 'pending']))->assertOk()->getContent();

        // The shared <dialog>+iframe modal is present, flagged not to navigate away on close.
        $this->assertStringContainsString('data-cibi-report-dialog', $html);
        $this->assertStringContainsString('data-cibi-report-stay', $html);
        $this->assertStringContainsString('data-cibi-report-frame', $html);

        // Create Report opens it at the exact existing CI / BI page — the same URL the Client
        // Folder's own CI / BI card uses, so there is one form, one save endpoint, one record.
        $applicantUrl = route('client-folders.cibi-report.edit', $folder->id);
        $coMakerUrl = route('client-folders.cibi-report.edit', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $this->assertStringContainsString('data-cibi-report-url="'.e($applicantUrl).'"', $html);
        $this->assertStringContainsString('data-cibi-report-url="'.e($coMakerUrl).'"', $html);
        $this->assertStringContainsString('data-modal-open="cibi-report-dialog"', $html);

        // It stays a real link, so the same page still opens with JavaScript unavailable.
        $this->assertStringContainsString('href="'.e($applicantUrl).'"', $html);

        // One modal serves the whole page — Create and Continue share the single dialog instance.
        $this->assertSame(1, substr_count($html, 'data-cibi-report-frame'));
        $this->assertSame(1, substr_count($html, 'data-cibi-report-dialog'));
    }

    public function test_continue_report_opens_the_same_modal_on_the_same_existing_record(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'STARTED, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        // One existing, unfinished report per person — continuing must edit these, never add more.
        $applicantReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Draft]);
        $coMakerReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'co_maker_id' => $coMaker->id, 'state' => RecordState::Draft]);
        $before = CibiReport::query()->count();

        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'search' => 'STARTED']))->assertOk()->getContent();

        // The label is unchanged, and it now opens the very same shared modal Create Report uses.
        $this->assertStringContainsString('Continue Report', $html);
        $this->assertStringNotContainsString('Create Report', $html, 'Continuing must never be relabelled as creating.');
        // Two rows (Applicant and the Co-Maker), each rendered as a table row and a mobile card.
        $this->assertSame(4, substr_count($html, 'data-modal-open="cibi-report-dialog"'));

        // Each row points at the exact existing edit URL for its own person — the same URL the
        // action already navigated to, so the record identity is untouched.
        $applicantUrl = route('client-folders.cibi-report.edit', $folder->id);
        $coMakerUrl = route('client-folders.cibi-report.edit', [$folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $this->assertStringContainsString('data-cibi-report-url="'.e($applicantUrl).'"', $html);
        $this->assertStringContainsString('data-cibi-report-url="'.e($coMakerUrl).'"', $html);
        // Still a real link, so the same page opens with JavaScript unavailable.
        $this->assertStringContainsString('href="'.e($applicantUrl).'"', $html);

        // The DTO backing each row still carries its own exact person and source record.
        $rows = $this->items($ci, ['report_type' => 'cibi', 'search' => 'STARTED']);
        $applicantRow = $rows->firstWhere('coMakerId', null);
        $coMakerRow = $rows->firstWhere('coMakerId', $coMaker->id);
        $this->assertSame('Continue Report', $applicantRow->continueLabel());
        $this->assertSame('Continue Report', $coMakerRow->continueLabel());
        $this->assertSame($applicantReport->id, $applicantRow->sourceId);
        $this->assertSame($coMakerReport->id, $coMakerRow->sourceId);
        $this->assertStringNotContainsString('co_maker_id', $applicantRow->continueUrl(), 'The Applicant row carries no Co-Maker.');

        // Rendering the workspace creates nothing.
        $this->assertSame($before, CibiReport::query()->count());
    }

    public function test_business_report_continue_opens_the_existing_business_modal_for_that_exact_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $storeA = $this->business($folder, 'JUAN STORE');
        $farmB = $this->business($folder, 'JUAN FARMING');
        BusinessReport::factory()->create(['income_source_id' => $storeA->id, 'business_name' => 'JUAN STORE']);
        BusinessReport::factory()->create(['income_source_id' => $farmB->id, 'business_name' => 'JUAN FARMING']);
        $before = IncomeSource::query()->count();
        $beforeReports = BusinessReport::query()->count();

        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'business_report']))->assertOk()->getContent();

        // Every Business Report work item is a continuation by construction — the income source
        // already exists — so the label stays Continue Report and now opens the shared modal.
        $this->assertStringContainsString('Continue Report', $html);
        $this->assertStringNotContainsString('Create Report', $html);
        $this->assertSame(5, substr_count($html, 'data-modal-open="business-report-dialog"'), 'Two businesses render as a row and card; the fifth is the shared hidden template-flow handoff.');

        // One modal instance on the page, and it is the existing Business Report dialog.
        $this->assertSame(1, substr_count($html, 'data-business-report-dialog'));
        $this->assertSame(1, substr_count($html, 'data-business-report-frame'));
        $this->assertStringContainsString('data-business-report-stay', $html);

        // Each row addresses its own exact income source — the identical existing edit URL.
        $urlA = route('client-folders.income-sources.edit', [$folder->id, $storeA->id]);
        $urlB = route('client-folders.income-sources.edit', [$folder->id, $farmB->id]);
        $this->assertStringContainsString('data-business-report-url="'.e($urlA).'"', $html);
        $this->assertStringContainsString('data-business-report-url="'.e($urlB).'"', $html);
        // Still a real link for the no-JavaScript path.
        $this->assertStringContainsString('href="'.e($urlA).'"', $html);

        // Business A's row can never address Business B.
        $rows = $this->items($ci, ['report_type' => 'business_report']);
        $rowA = $rows->firstWhere('incomeSourceId', $storeA->id);
        $rowB = $rows->firstWhere('incomeSourceId', $farmB->id);
        $this->assertSame($urlA, $rowA->continueUrl());
        $this->assertSame($urlB, $rowB->continueUrl());
        $this->assertNotSame($rowA->continueUrl(), $rowB->continueUrl());
        $this->assertSame('JUAN STORE', $rowA->businessName);
        $this->assertSame('JUAN FARMING', $rowB->businessName);

        // Rendering the workspace creates no income source and no business report.
        $this->assertSame($before, IncomeSource::query()->count());
        $this->assertSame($beforeReports, BusinessReport::query()->count());
    }

    public function test_the_business_modal_url_keeps_its_exact_person_and_rejects_a_foreign_one(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $applicantBusiness = $this->business($folder, 'APPLICANT STORE');
        $coMakerBusiness = $this->business($folder, 'CO-MAKER STORE');
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);

        $rows = $this->items($ci, ['report_type' => 'business_report']);
        $applicantRow = $rows->firstWhere('incomeSourceId', $applicantBusiness->id);
        $coMakerRow = $rows->firstWhere('incomeSourceId', $coMakerBusiness->id);

        $this->assertNull($applicantRow->coMakerId);
        $this->assertStringNotContainsString('co_maker_id', $applicantRow->continueUrl());
        $this->assertSame($coMaker->id, $coMakerRow->coMakerId);
        $this->assertStringContainsString('co_maker_id='.$coMaker->id, $coMakerRow->continueUrl());

        // The route the modal loads still enforces ownership: a Co-Maker from another folder is
        // refused, and one business's id cannot be opened under another folder.
        $otherFolder = $this->folder($ci, 'SANTOS, MARIA');
        $foreign = CoMaker::create(['client_folder_id' => $otherFolder->id, 'full_name' => 'Foreign Co-Maker']);
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [
            $folder->id, $applicantBusiness->id, 'person' => 'co-maker', 'co_maker_id' => $foreign->id,
        ]))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$otherFolder->id, $applicantBusiness->id]))->assertNotFound();
    }

    public function test_the_business_row_status_follows_the_backend_completion_rule(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $pending = $this->business($folder, 'STILL PENDING');
        $done = $this->business($folder, 'ALREADY DONE', complete: true);

        $rows = $this->items($ci, ['report_type' => 'business_report']);

        // Pending keeps Continue and the modal; Completed swaps to the read-only actions instead.
        $pendingRow = $rows->firstWhere('incomeSourceId', $pending->id);
        $doneRow = $rows->firstWhere('incomeSourceId', $done->id);
        $this->assertFalse($pendingRow->isCompleted);
        $this->assertSame('Create Report', $pendingRow->continueLabel());
        $this->assertTrue($doneRow->isCompleted, 'Completion is decided by the existing rule, not by the UI.');
        $this->assertNotNull($doneRow->previewAction());

        $completedHtml = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'business_report', 'tab' => 'completed']))->assertOk()->getContent();
        $this->assertStringNotContainsString('title="Continue Report"', $completedHtml, 'A completed row offers preview and downloads, not Continue.');
        $this->assertStringNotContainsString('title="Create Report"', $completedHtml, 'A completed row offers preview and downloads, not Create.');
        $this->assertStringContainsString('aria-label="Preview Report"', $completedHtml);
    }

    public function test_a_completed_business_preview_link_keeps_the_exact_official_preview_query(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $exactBusiness = $this->business($folder, 'EXACT STORE', complete: true);
        $otherBusiness = $this->business($folder, 'OTHER STORE', complete: true);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER STORE', complete: true);
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);
        $previewUrl = route('client-folders.generated-reports.preview', [
            $folder->id,
            'report_type' => 'business_income_source',
            'income_source_id' => $exactBusiness->id,
        ]);
        $coMakerPreviewUrl = route('client-folders.generated-reports.preview', [
            $folder->id,
            'report_type' => 'business_income_source',
            'income_source_id' => $coMakerBusiness->id,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
        ]);
        $applicantPdfUrl = route('client-folders.income-sources.export-pdf', [$folder->id, $exactBusiness->id]);
        $applicantExcelUrl = route('client-folders.income-sources.export-excel', [$folder->id, $exactBusiness->id]);
        $coMakerPdfUrl = route('client-folders.income-sources.export-pdf', [
            $folder->id, $coMakerBusiness->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);
        $coMakerExcelUrl = route('client-folders.income-sources.export-excel', [
            $folder->id, $coMakerBusiness->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);
        $countsBeforePreview = [IncomeSource::query()->count(), BusinessReport::query()->count()];

        $html = $this->actingAs($ci)->get(route('reports.index', [
            'report_type' => 'business_report',
            'tab' => 'completed',
        ]))->assertOk()->getContent();

        // The desktop row and mobile card both use a real navigation link. A GET form would let
        // the browser rebuild (and lose) the embedded report identity before it reaches Laravel.
        $this->assertSame(2, substr_count($html, 'href="'.e($previewUrl).'"'));
        $this->assertSame(2, substr_count($html, 'href="'.e($coMakerPreviewUrl).'"'));
        $this->assertStringNotContainsString('method="GET" action="'.e($previewUrl).'"', $html);
        $this->assertNotSame(route('client-folders.income-sources.edit', [$folder->id, $exactBusiness->id]), $previewUrl);

        // Following that exact href reaches the existing read-only official document and never
        // falls back to the Business Report encoding page or another business in the same folder.
        $applicantPreview = $this->actingAs($ci)->get($previewUrl)
            ->assertOk()
            ->assertSee('aria-label="Official Business Report"', false)
            ->assertSee('aria-label="Business Report preview actions"', false)
            ->assertSee('Download PDF')
            ->assertSee('Download Excel')
            ->assertSee('Print')
            ->assertSee('EXACT STORE')
            ->assertDontSee('OTHER STORE')
            ->assertDontSee('CO-MAKER STORE');
        $applicantPreviewHtml = $applicantPreview->getContent();
        $this->assertStringContainsString('href="'.e($applicantPdfUrl).'"', $applicantPreviewHtml);
        $this->assertStringContainsString('action="'.e($applicantExcelUrl).'"', $applicantPreviewHtml);
        $this->assertStringNotContainsString((string) $otherBusiness->id.'/export-pdf', $applicantPreviewHtml);
        $this->assertSame(1, substr_count($applicantPreviewHtml, 'onclick="window.print()"'));
        $this->assertStringNotContainsString('onload="window.print()', $applicantPreviewHtml);
        $this->assertStringContainsString('@media print { .preview-toolbar { display:none; }', $applicantPreviewHtml);
        $coMakerPreview = $this->actingAs($ci)->get($coMakerPreviewUrl)
            ->assertOk()
            ->assertSee('aria-label="Official Business Report"', false)
            ->assertSee('CO-MAKER STORE')
            ->assertSee('( ✓ ) CO-MAKER')
            ->assertDontSee('EXACT STORE');
        $coMakerPreviewHtml = $coMakerPreview->getContent();
        $this->assertStringContainsString('href="'.e($coMakerPdfUrl).'"', $coMakerPreviewHtml);
        $this->assertStringContainsString('action="'.e($coMakerExcelUrl).'"', $coMakerPreviewHtml);
        $this->assertStringContainsString('name="co_maker_id" value="'.$coMaker->id.'"', $coMakerPreviewHtml);
        $this->assertNotSame($exactBusiness->id, $otherBusiness->id);
        $this->assertSame($countsBeforePreview, [IncomeSource::query()->count(), BusinessReport::query()->count()]);
    }

    public function test_completed_business_downloads_stream_the_exact_applicant_and_co_maker_reports_without_writes(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELA CRUZ, JUAN');
        $businessA = $this->business($folder, 'BUSINESS A', complete: true);
        $businessB = $this->business($folder, 'BUSINESS B', complete: true);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER BUSINESS', complete: true);
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);

        $applicantUrl = route('client-folders.income-sources.export-pdf', [$folder->id, $businessA->id]);
        $businessBUrl = route('client-folders.income-sources.export-pdf', [$folder->id, $businessB->id]);
        $coMakerUrl = route('client-folders.income-sources.export-pdf', [
            $folder->id, $coMakerBusiness->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);
        $applicantExcelUrl = route('client-folders.income-sources.export-excel', [$folder->id, $businessA->id]);
        $coMakerExcelUrl = route('client-folders.income-sources.export-excel', [
            $folder->id, $coMakerBusiness->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);

        $rows = $this->items($ci, ['report_type' => 'business_report'])->where('kind', 'business_report');
        $applicantPdf = $rows->firstWhere('incomeSourceId', $businessA->id)->downloadActions()[0];
        $coMakerPdf = $rows->firstWhere('incomeSourceId', $coMakerBusiness->id)->downloadActions()[0];
        $applicantExcel = $rows->firstWhere('incomeSourceId', $businessA->id)->downloadActions()[1];
        $coMakerExcel = $rows->firstWhere('incomeSourceId', $coMakerBusiness->id)->downloadActions()[1];

        $this->assertSame(['url' => $applicantUrl, 'method' => 'GET', 'fields' => []], collect($applicantPdf)->only(['url', 'method', 'fields'])->all());
        $this->assertSame(['url' => $coMakerUrl, 'method' => 'GET', 'fields' => []], collect($coMakerPdf)->only(['url', 'method', 'fields'])->all());
        $this->assertNotSame($applicantUrl, $businessBUrl, 'Business A and Business B keep different route-bound income_source_id values.');
        $this->assertSame('POST', $applicantExcel['method'], 'Excel keeps its existing POST implementation.');
        $this->assertSame(route('client-folders.income-sources.export-excel', [$folder->id, $businessA->id]), $applicantExcel['url']);
        $this->assertSame([], $applicantExcel['fields']);
        $this->assertSame('POST', $coMakerExcel['method']);
        $this->assertSame(route('client-folders.income-sources.export-excel', [
            $folder->id, $coMakerBusiness->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]), $coMakerExcel['url']);
        $this->assertSame(['co_maker_id' => $coMaker->id], $coMakerExcel['fields']);

        $html = $this->actingAs($ci)->get(route('reports.index', [
            'report_type' => 'business_report', 'tab' => 'completed',
        ]))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'href="'.e($applicantUrl).'"'));
        $this->assertSame(2, substr_count($html, 'href="'.e($coMakerUrl).'"'));

        $countsBefore = [IncomeSource::count(), BusinessReport::count(), BusinessCheck::count(), GeneratedReport::count(), AuditLog::count()];
        foreach ([$applicantUrl, $businessBUrl, $coMakerUrl] as $url) {
            $response = $this->actingAs($ci)->get($url)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
            $this->assertStringStartsWith('%PDF-', $response->streamedContent());
        }
        foreach ([
            [$applicantExcelUrl, []],
            [$coMakerExcelUrl, ['co_maker_id' => $coMaker->id]],
        ] as [$url, $fields]) {
            $response = $this->actingAs($ci)->post($url, $fields)->assertOk();
            $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
            $this->assertStringStartsWith('PK', $response->streamedContent());
        }

        $this->actingAs($ci)->get(route('client-folders.income-sources.export-pdf', [
            $folder->id, $businessA->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.income-sources.export-pdf', [
            $folder->id, $coMakerBusiness->id,
        ]))->assertNotFound();
        $this->actingAs($ci)->post($applicantExcelUrl, ['co_maker_id' => $coMaker->id])->assertNotFound();
        $this->actingAs($ci)->post(route('client-folders.income-sources.export-excel', [
            $folder->id, $coMakerBusiness->id,
        ]))->assertNotFound();

        $this->assertSame($countsBefore, [IncomeSource::count(), BusinessReport::count(), BusinessCheck::count(), GeneratedReport::count(), AuditLog::count()]);
        $this->assertSame(
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'business_income_source', 'income_source_id' => $businessA->id]),
            $rows->firstWhere('incomeSourceId', $businessA->id)->previewAction()['url'],
            'The working Eye Preview route stays unchanged.',
        );
    }

    public function test_pending_residence_checks_open_the_existing_form_for_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'RESIDENCE, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $before = ResidenceCheck::query()->count();

        $html = $this->actingAs($ci)->get(route('reports.index', [
            'report_type' => 'residence_check', 'tab' => 'pending', 'search' => 'RESIDENCE',
        ]))->assertOk()->getContent();
        $applicantUrl = route('client-folders.residence-checks.create', $folder->id);
        $coMakerUrl = route('client-folders.residence-checks.create', [
            $folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);

        $this->assertStringContainsString('data-check-report-dialog', $html);
        $this->assertStringContainsString('data-check-report-stay', $html);
        $this->assertStringContainsString('data-check-report-frame', $html);
        $this->assertStringContainsString('data-check-report-url="'.e($applicantUrl).'"', $html);
        $this->assertStringContainsString('data-check-report-url="'.e($coMakerUrl).'"', $html);
        $this->assertStringContainsString('href="'.e($applicantUrl).'"', $html);
        $this->assertSame(1, substr_count($html, 'data-check-report-dialog'));
        $this->assertSame($coMaker->id, $this->actingAs($ci)->get($coMakerUrl)->assertOk()->viewData('activePerson')->id);
        $this->assertSame($before, ResidenceCheck::query()->count(), 'Opening the modal form must not create a Residence Check.');
    }

    public function test_pending_business_checks_open_the_existing_form_for_the_exact_business_and_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'BUSINESS CHECK, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $businessA = $this->business($folder, 'BUSINESS A', complete: true);
        $businessB = $this->business($folder, 'BUSINESS B', complete: true);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER BUSINESS', complete: true);
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);
        $before = [IncomeSource::count(), BusinessReport::count(), BusinessCheck::count()];

        $html = $this->actingAs($ci)->get(route('reports.index', [
            'report_type' => 'business_check', 'tab' => 'pending', 'search' => 'BUSINESS CHECK',
        ]))->assertOk()->getContent();
        $urlA = route('client-folders.business-checks.create', [$folder->id, 'income_source_id' => $businessA->id]);
        $urlB = route('client-folders.business-checks.create', [$folder->id, 'income_source_id' => $businessB->id]);
        $coMakerUrl = route('client-folders.business-checks.create', [
            $folder->id, 'income_source_id' => $coMakerBusiness->id,
            'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]);

        // The Pending row is one generic entry point per person — it opens the Business Check form
        // for that exact person with nothing preselected, and the CI chooses the business there.
        $this->assertStringContainsString('data-check-report-url="'.e(route('client-folders.business-checks.create', $folder->id)).'"', $html);
        $this->assertStringContainsString('data-check-report-url="'.e(route('client-folders.business-checks.create', [
            $folder->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ])).'"', $html);
        foreach ([$urlA, $urlB] as $url) {
            $this->assertStringNotContainsString('data-check-report-url="'.e($url).'"', $html, 'No per-business Pending Business Check row exists.');
        }
        $this->assertNotSame($urlA, $urlB);

        // Those exact per-business URLs remain valid entry points into the form itself (they are how
        // the Business / Income Sources module deep-links one business) — only Reports stopped
        // generating a Pending row for each of them.
        $this->assertSame($businessA->id, $this->actingAs($ci)->get($urlA)->assertOk()->viewData('selectedIncomeSourceId'));
        $this->assertSame($businessB->id, $this->actingAs($ci)->get($urlB)->assertOk()->viewData('selectedIncomeSourceId'));
        $coMakerForm = $this->actingAs($ci)->get($coMakerUrl)->assertOk();
        $this->assertSame($coMaker->id, $coMakerForm->viewData('activePerson')->id);
        $this->assertSame($coMakerBusiness->id, $coMakerForm->viewData('selectedIncomeSourceId'));
        $this->assertSame([$coMakerBusiness->id], $coMakerForm->viewData('businesses')->pluck('id')->all());
        $this->assertSame($before, [IncomeSource::count(), BusinessReport::count(), BusinessCheck::count()]);

        $this->actingAs($ci)->get(route('client-folders.business-checks.create', [
            $folder->id, 'income_source_id' => $businessA->id,
            'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]))->assertNotFound();
    }

    public function test_check_completion_refreshes_authoritative_rows_actions_and_kpis_with_current_filters(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'REFRESH, CLIENT');
        $business = $this->business($folder, 'REFRESH BUSINESS', complete: true);
        $filters = [
            'tab' => 'all', 'search' => 'REFRESH', 'client_folder_id' => $folder->id,
            'person' => 'applicant', 'sort' => 'client', 'direction' => 'desc',
        ];
        $before = $this->actingAs($ci)->get(route('reports.index', $filters))->assertOk()->viewData('summary');

        ResidenceCheck::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id,
        ]);
        $this->businessCheckFor($ci, $folder, $business);

        $fragment = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Reports-Summary' => '1'])
            ->get(route('reports.index', $filters))->assertOk()->getContent();
        $after = $this->actingAs($ci)->get(route('reports.index', $filters))->assertOk()->viewData('summary');

        $this->assertStringContainsString('data-reports-summary-html', $fragment);
        $this->assertStringContainsString('>Completed<', $fragment);
        $this->assertStringContainsString('title="Preview Report"', $fragment);
        $this->assertStringContainsString('title="Download report"', $fragment);
        $this->assertStringContainsString('search=REFRESH', str_replace('&amp;', '&', $fragment));
        $this->assertSame($before['total'], $after['total']);
        $this->assertSame($before['pending'] - 2, $after['pending']);
        $this->assertSame($before['completed'] + 2, $after['completed']);
        $this->assertSame($before['completed_this_month'] + 2, $after['completed_this_month']);
    }

    public function test_reports_check_success_handler_toasts_closes_and_refreshes_only_after_success_message(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $handlerStart = strpos($script, "event.data?.type !== 'brbi:check-saved'");
        $navigationBranch = strpos($script, 'dialog.dataset.checkSavedReturnUrl', $handlerStart);
        $reportsBranch = substr($script, $handlerStart, $navigationBranch - $handlerStart);

        $this->assertStringContainsString("dialog.matches('[data-check-report-stay]')", $reportsBranch);
        $this->assertStringContainsString('showToast(event.data.message', $reportsBranch);
        $this->assertStringContainsString('refreshReportsWorkspace();', $reportsBranch);
        $this->assertStringContainsString('dialog.close();', $reportsBranch);
        $this->assertStringNotContainsString('sessionStorage', $reportsBranch);
        $this->assertStringNotContainsString('window.location.assign', $reportsBranch);
        $this->assertStringNotContainsString('window.location.reload', $reportsBranch);
    }

    public function test_each_report_type_keeps_its_own_existing_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'BUSINESS, CLIENT');
        $this->business($folder, 'A BUSINESS');

        foreach (['business_report', 'residence_check', 'business_check'] as $kind) {
            $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => $kind, 'tab' => 'pending']))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-cibi-report-url', $html, $kind.' keeps its own workflow.');
            $this->assertStringNotContainsString('data-modal-open="cibi-report-dialog"', $html, $kind.' keeps its own workflow.');
        }

        // The two photo checks are untouched by the modal work — they still navigate.
        foreach (['residence_check', 'business_check'] as $kind) {
            $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => $kind, 'tab' => 'pending']))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/data-business-report-url="[^"]+"/', $html, $kind.' keeps its own workflow.');
            $this->assertStringContainsString('data-modal-open="check-report-dialog"', $html);
            $this->assertStringContainsString('data-check-report-url', $html);
        }
    }

    public function test_the_modal_cannot_reach_another_persons_cibi_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $foreign = CoMaker::create(['client_folder_id' => $this->folder($ci, 'BRAVO, CLIENT')->id, 'full_name' => 'Foreign Co-Maker']);

        // The URL the modal loads is the existing route, and it still refuses a Co-Maker that does
        // not belong to the folder — the modal changes presentation, never ownership.
        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', [
            $folder->id, 'person' => 'co-maker', 'co_maker_id' => $foreign->id,
        ]))->assertNotFound();

        // The Applicant row never carries a Co-Maker in its modal URL.
        $html = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'cibi', 'person' => 'applicant', 'search' => 'ALPHA']))->assertOk()->getContent();
        $this->assertStringContainsString('data-cibi-report-url="'.e(route('client-folders.cibi-report.edit', $folder->id)).'"', $html);
        $this->assertStringNotContainsString('co_maker_id', $html);
    }

    public function test_the_post_save_refresh_returns_both_the_rows_and_the_recounted_kpis(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now()]);

        // Sorting and pagination ask for rows only — the counts are unfiltered and cannot move.
        $rowsOnly = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('reports.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-reports-summary-html', $rowsOnly);
        $this->assertStringNotContainsString('Total Reports', $rowsOnly);

        // A save asks for both, so the KPI row can AUTO-UPDATE from the same authoritative state.
        $withCounts = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Reports-Summary' => '1'])
            ->get(route('reports.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-reports-summary-html', $withCounts);
        foreach (['Total Reports', 'All report records', 'Pending', 'Reports awaiting completion',
            'Completed', 'Completed reports available to view', 'Completed This Month'] as $kpi) {
            $this->assertStringContainsString($kpi, $withCounts);
        }
        // Still only the fragment — never the whole shell.
        $this->assertStringNotContainsString('<!DOCTYPE html>', $withCounts);

        // The refreshed counts are the real ones, and the row really reads Completed.
        $summary = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->viewData('summary');
        $this->assertGreaterThan(0, $summary['completed'], 'The counts come from the query, not from the UI.');
        $this->assertStringContainsString('>'.$summary['completed'].'<', $withCounts);
        $this->assertStringContainsString('>Completed<', $withCounts);
    }

    public function test_the_page_carries_no_second_cibi_form_or_save_endpoint(): void
    {
        $ci = User::factory()->create();
        $this->folder($ci, 'ALPHA, CLIENT');

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();

        // The modal is an iframe onto the existing page: no CI / BI fields and no CI / BI submit
        // target are duplicated into the Reports document itself.
        $this->assertStringNotContainsString('name="party_type"', $html);
        $this->assertStringNotContainsString('name="amount_applied"', $html);
        $this->assertStringNotContainsString('cibi-encoding-page', $html);
        $this->assertSame(1, substr_count($html, 'data-cibi-report-frame'));

        // And nothing retired is reintroduced alongside it.
        foreach (['Photos &amp; Videos', 'Google Drive', 'Telegram History', 'Attachments / Documents'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
        }
    }

    public function test_check_first_business_reads_as_a_completed_check_and_a_pending_report(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        // The Check-first shape the "+Add Business" quick-create leaves behind: an IncomeSource and
        // its BusinessReport shell at revision 1, then the saved check against that same business.
        $business = $this->business($folder, 'CHECK FIRST STORE');
        BusinessReport::factory()->create(['income_source_id' => $business->id, 'business_name' => 'CHECK FIRST STORE']);
        $check = $this->businessCheckFor($ci, $folder, $business);

        $rows = $this->items($ci)->whereIn('kind', ['business_report', 'business_check'])
            ->keyBy(fn ($item) => $item->kind);

        // The check is done; the report has not been through its own form yet.
        $this->assertTrue($rows['business_check']->isCompleted);
        $this->assertSame($check->id, $rows['business_check']->sourceId);
        $this->assertFalse($rows['business_report']->isCompleted);
        $this->assertSame('Continue Report', $rows['business_report']->continueLabel());
        $this->assertNull($rows['business_report']->previewAction(), 'A pending report exposes no preview.');

        // Both rows are the SAME business — one income source, one report record, one check.
        $this->assertSame($business->id, $rows['business_report']->incomeSourceId);
        $this->assertSame($business->id, $rows['business_check']->incomeSourceId);
        $this->assertSame(route('client-folders.income-sources.edit', [$folder->id, $business->id]), $rows['business_report']->continueUrl());
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());
        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $business->id)->count());
    }

    public function test_completing_the_report_of_a_check_first_business_leaves_the_check_completed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'CHECK FIRST STORE');
        BusinessReport::factory()->create(['income_source_id' => $business->id, 'business_name' => 'CHECK FIRST STORE']);
        $this->businessCheckFor($ci, $folder, $business);

        // What a successful submit of the Business Report form persists: state complete and the
        // revision advanced past the never-submitted shell. No new business, no new report row.
        $business->update(['state' => RecordState::Complete, 'revision' => 2, 'completed_at' => now()]);

        $rows = $this->items($ci)->whereIn('kind', ['business_report', 'business_check'])
            ->keyBy(fn ($item) => $item->kind);

        $this->assertTrue($rows['business_report']->isCompleted);
        $this->assertTrue($rows['business_check']->isCompleted, 'Completing the report never disturbs the check.');

        // Completed exposes exactly the outputs the backend really supports for a business.
        $this->assertSame(
            route('client-folders.generated-reports.preview', [$folder->id, 'report_type' => 'business_income_source', 'income_source_id' => $business->id]),
            $rows['business_report']->previewAction()['url'],
        );
        $this->assertSame(['PDF', 'Excel'], array_column($rows['business_report']->downloadActions(), 'format'));
        $this->assertSame(
            [route('client-folders.income-sources.export-pdf', [$folder->id, $business->id]), route('client-folders.income-sources.export-excel', [$folder->id, $business->id])],
            array_column($rows['business_report']->downloadActions(), 'url'),
        );
        $this->assertNotContains('Word', array_column($rows['business_report']->downloadActions(), 'format'));

        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());
    }

    public function test_report_first_business_reads_as_completed_with_its_check_still_pending(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'REPORT FIRST STORE', complete: true);

        $before = $this->items($ci)->whereIn('kind', ['business_report', 'business_check'])
            ->keyBy(fn ($item) => $item->kind);

        $this->assertTrue($before['business_report']->isCompleted);
        $this->assertFalse($before['business_check']->isCompleted, 'No check saved yet.');
        $this->assertNull($before['business_check']->sourceId, 'The pending check is a derived row, never a stored one.');
        // A completed Business Report never binds the Pending Business Check to itself: the row is
        // the person's single generic create entry point, with no business identity of its own.
        $this->assertNull($before['business_check']->incomeSourceId);
        $this->assertNull($before['business_check']->businessName);
        $this->assertSame(
            route('client-folders.business-checks.create', $folder->id),
            $before['business_check']->continueUrl(),
        );

        // Saving the check later reuses that exact business — it can never mint a second one.
        $check = $this->businessCheckFor($ci, $folder, $business);

        $after = $this->items($ci)->whereIn('kind', ['business_report', 'business_check'])
            ->keyBy(fn ($item) => $item->kind);

        $this->assertTrue($after['business_check']->isCompleted);
        $this->assertSame($check->id, $after['business_check']->sourceId);
        $this->assertTrue($after['business_report']->isCompleted, 'The report stays completed.');
        $this->assertSame($business->id, $after['business_check']->incomeSourceId);
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $business->id)->count());
    }

    public function test_report_first_http_submission_is_immediately_completed_in_reports(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'REPORT FIRST HTTP CLIENT');
        // Mirrors the real browser failure: trucking_services has a schema, but every schema field
        // is optional. A valid final submission with the required profile completed must not be
        // downgraded to Draft merely because template_data itself is empty.
        $template = IncomeSourceTemplate::query()->where('template_type', 'trucking_services')->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id,
            'intent' => 'complete',
            'source_name' => 'HTTP REPORT FIRST STORE',
            'business_name' => 'HTTP REPORT FIRST STORE',
            'report_category' => 'Trucking',
            'main_business_address' => 'Carmen, Cagayan de Oro',
            'start_date' => '2026-09-02',
            'registered_owner' => 'HTTP Report Owner',
            'year_established' => 2020,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $business = IncomeSource::query()->where('client_folder_id', $folder->id)->sole();
        $this->assertSame(RecordState::Complete, $business->state);
        $this->assertGreaterThan(1, $business->revision);
        $this->assertNotNull($business->completed_at);
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());

        $report = $this->items($ci, ['client_folder_id' => $folder->id, 'report_type' => 'business_report'])->sole();
        $this->assertTrue($report->isCompleted);
        $this->assertSame($business->id, $report->sourceId);
        $this->assertSame($business->id, $report->incomeSourceId);
        $this->assertSame(['PDF', 'Excel'], array_column($report->downloadActions(), 'format'));
        $this->assertNotNull($report->previewAction());

        $completedHtml = $this->actingAs($ci)->get(route('reports.index', [
            'client_folder_id' => $folder->id,
            'report_type' => 'business_report',
            'tab' => 'completed',
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Completed', $completedHtml);
        $this->assertStringNotContainsString('Continue Report', $completedHtml);

        $pendingFragment = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Reports-Summary' => '1'])
            ->get(route('reports.index', [
                'client_folder_id' => $folder->id,
                'report_type' => 'business_report',
                'tab' => 'pending',
            ]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-reports-summary-html', $pendingFragment);
        $this->assertStringContainsString('No reports match these filters', $pendingFragment);
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());
    }

    public function test_an_unchanged_saved_draft_can_be_finalized_and_immediately_leaves_pending_reports(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DRAFT FINALIZATION CLIENT');
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $payload = [
            'income_source_template_id' => $template->id,
            'intent' => 'stay',
            'source_name' => 'UNCHANGED DRAFT STORE',
            'business_name' => 'UNCHANGED DRAFT STORE',
            'report_category' => 'Retail',
            'main_business_address' => 'Lapasan, Cagayan de Oro',
            'start_date' => '2026-09-02',
            'registered_owner' => 'Draft Report Owner',
            'year_established' => 2020,
            'branches' => [['location' => 'Main Branch']],
            'products' => [['product_name' => 'Rice']],
        ];

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $business = IncomeSource::query()->where('client_folder_id', $folder->id)->sole();
        $report = $business->businessReport;
        $this->assertSame(RecordState::Draft, $business->state);
        $this->assertFalse($this->items($ci, ['client_folder_id' => $folder->id, 'report_type' => 'business_report'])->sole()->isCompleted);

        $payload['intent'] = 'complete';
        $payload['expected_revision'] = $business->revision;
        $payload['branches'][0]['id'] = $report->branches()->sole()->id;
        $payload['products'][0]['id'] = $report->products()->sole()->id;

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $business]), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Business Report updated successfully.');

        $business->refresh();
        $this->assertSame(RecordState::Complete, $business->state);
        $this->assertGreaterThan(2, $business->revision);
        $this->assertNotNull($business->completed_at);
        $this->assertTrue($this->items($ci, ['client_folder_id' => $folder->id, 'report_type' => 'business_report'])->sole()->isCompleted);
        $this->assertCount(0, $this->items($ci, ['client_folder_id' => $folder->id, 'report_type' => 'business_report', 'tab' => 'pending']));
        $this->assertCount(1, $this->items($ci, ['client_folder_id' => $folder->id, 'report_type' => 'business_report', 'tab' => 'completed']));
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());
    }

    public function test_two_businesses_keep_independent_report_and_check_states(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $businessA = $this->business($folder, 'BUSINESS A', complete: true);
        $businessB = $this->business($folder, 'BUSINESS B');
        $checkA = $this->businessCheckFor($ci, $folder, $businessA);

        $reports = $this->items($ci, ['report_type' => 'business_report'])->keyBy('incomeSourceId');
        $checks = $this->items($ci, ['report_type' => 'business_check'])->values();

        // Business Report rows stay one per business, exactly as before.
        $this->assertTrue($reports[$businessA->id]->isCompleted);
        $this->assertFalse($reports[$businessB->id]->isCompleted);

        // Business Check: A's own saved check is business-specific and Completed; B does not get a
        // Pending row of its own — the one remaining Pending row is the generic entry point.
        $checkRowA = $checks->firstWhere('incomeSourceId', $businessA->id);
        $this->assertTrue($checkRowA->isCompleted);
        $this->assertNull($checks->firstWhere('incomeSourceId', $businessB->id));
        $pending = $checks->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $pending);
        $this->assertNull($pending[0]->incomeSourceId);

        // No action on A can address B, and vice versa.
        $this->assertStringContainsString('income_source_id='.$businessA->id, $reports[$businessA->id]->previewAction()['url']);
        $this->assertStringNotContainsString('income_source_id='.$businessB->id, $reports[$businessA->id]->previewAction()['url']);
        $this->assertSame(['business_check_ids[]' => $checkA->id], $checkRowA->previewAction()['fields']);
        $this->assertNull($pending[0]->previewAction());
        $this->assertSame(route('client-folders.income-sources.edit', [$folder->id, $businessB->id]), $reports[$businessB->id]->continueUrl());
    }

    public function test_child_deletes_reset_only_their_exact_reports_rows_and_never_delete_the_parent_or_sibling(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CHILD DELETE CLIENT');
        $businessA = $this->business($folder, 'BUSINESS A', complete: true);
        $businessB = $this->business($folder, 'BUSINESS B', complete: true);
        $checkA = $this->businessCheckFor($ci, $folder, $businessA);
        $checkB = $this->businessCheckFor($ci, $folder, $businessB);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $businessA);

        $reports = $this->items($ci, ['client_folder_id' => $folder->id])->whereIn('kind', ['business_report', 'business_check']);
        $reportA = $reports->first(fn (ReportWorkItem $item): bool => $item->kind === 'business_report' && $item->incomeSourceId === $businessA->id);
        $remainingCheckA = $reports->first(fn (ReportWorkItem $item): bool => $item->kind === 'business_check' && $item->incomeSourceId === $businessA->id);

        // Deleting a Business Report is intentional and permanent: the work item does not come
        // back as a fresh "Create Report" for the same business. Its saved Business Check is
        // separate investigation data and survives untouched.
        $this->assertNull($reportA, 'A deliberately deleted Business Report must not be re-synthesised.');
        $this->assertTrue($remainingCheckA->isCompleted);
        $this->assertSame($checkA->id, $remainingCheckA->sourceId);
        $this->assertDatabaseHas('income_sources', ['id' => $businessA->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $businessA->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $checkA->id]);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $checkB);

        $reports = $this->items($ci, ['client_folder_id' => $folder->id])->whereIn('kind', ['business_report', 'business_check']);
        $reportB = $reports->first(fn (ReportWorkItem $item): bool => $item->kind === 'business_report' && $item->incomeSourceId === $businessB->id);
        $pendingCheckB = $reports->first(fn (ReportWorkItem $item): bool => $item->kind === 'business_check' && $item->incomeSourceId === $businessB->id);

        // Deleting a Business Check is intentional and permanent too: it does not come back as a
        // fresh "Create Report" for the same business. Its Business Report is a separate decision
        // and is left exactly as it was.
        $this->assertTrue($reportB->isCompleted);
        $this->assertNull($pendingCheckB, 'A deliberately deleted Business Check must not be re-synthesised.');
        $this->assertDatabaseHas('income_sources', ['id' => $businessB->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $businessB->id]);
        $this->assertDatabaseMissing('business_checks', ['id' => $checkB->id]);
        $this->assertSame(2, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessReport::query()->count());
        $this->assertSame(1, BusinessCheck::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_parent_deletes_hide_only_the_exact_business_then_restore_unbound_rows_for_each_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'PARENT DELETE CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $applicantBusiness = $this->business($folder, 'APPLICANT BUSINESS', complete: true);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER BUSINESS', complete: true);
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);
        $this->businessCheckFor($ci, $folder, $applicantBusiness);
        $this->businessCheckFor($ci, $folder, $coMakerBusiness);
        $cibi = CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'state' => RecordState::Complete,
            'completed_at' => now(),
        ]);
        $residence = ResidenceCheck::create([
            'client_folder_id' => $folder->id,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $ci->id,
        ]);

        app(DeleteIncomeSource::class)->execute($ci, $folder, $applicantBusiness);

        $afterApplicantDelete = $this->items($ci, ['client_folder_id' => $folder->id]);
        $this->assertFalse($afterApplicantDelete->contains(fn (ReportWorkItem $item): bool => $item->incomeSourceId === $applicantBusiness->id));
        $this->assertCount(2, $afterApplicantDelete->where('incomeSourceId', $coMakerBusiness->id));
        $this->assertCount(2, $afterApplicantDelete->whereStrict('coMakerId', null)->filter->isUnboundBusiness());
        $this->assertNotNull($afterApplicantDelete->first(fn (ReportWorkItem $item): bool => $item->kind === 'cibi' && $item->sourceId === $cibi->id));
        $this->assertNotNull($afterApplicantDelete->first(fn (ReportWorkItem $item): bool => $item->kind === 'residence_check' && $item->sourceId === $residence->id));
        $this->assertDatabaseHas('income_sources', ['id' => $applicantBusiness->id]);
        $this->assertNotNull(IncomeSource::withTrashed()->findOrFail($applicantBusiness->id)->deleted_at);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $applicantBusiness->id]);
        $this->assertDatabaseMissing('business_checks', ['income_source_id' => $applicantBusiness->id]);

        app(DeleteIncomeSource::class)->execute($ci, $folder, $coMakerBusiness->fresh());

        $final = $this->items($ci, ['client_folder_id' => $folder->id]);
        $businessItems = $final->whereIn('kind', ['business_report', 'business_check']);
        $this->assertCount(4, $businessItems);
        $this->assertTrue($businessItems->every(fn (ReportWorkItem $item): bool => $item->isUnboundBusiness() && ! $item->isCompleted));
        $this->assertCount(2, $businessItems->whereStrict('coMakerId', null));
        $this->assertCount(2, $businessItems->where('coMakerId', $coMaker->id));
        $this->assertNotNull($final->first(fn (ReportWorkItem $item): bool => $item->kind === 'cibi' && $item->sourceId === $cibi->id));
        $this->assertNotNull($final->first(fn (ReportWorkItem $item): bool => $item->kind === 'residence_check' && $item->sourceId === $residence->id));
        $this->assertSame(0, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(2, IncomeSource::withTrashed()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(0, BusinessReport::query()->count());
        $this->assertSame(0, BusinessCheck::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_business_rows_never_cross_between_the_applicant_and_a_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);

        $applicantBusiness = $this->business($folder, 'APPLICANT STORE', complete: true);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER STORE');
        $coMakerBusiness->update(['co_maker_id' => $coMakerA->id]);
        $this->businessCheckFor($ci, $folder, $applicantBusiness);

        $reports = $this->items($ci, ['report_type' => 'business_report'])->keyBy('incomeSourceId');
        $checks = $this->items($ci, ['report_type' => 'business_check'])->values();

        // Each business stays on its own person, and its saved check inherits that same person.
        $this->assertNull($reports[$applicantBusiness->id]->coMakerId);
        $this->assertSame($coMakerA->id, $reports[$coMakerBusiness->id]->coMakerId);
        $this->assertNull($checks->firstWhere('incomeSourceId', $applicantBusiness->id)->coMakerId);
        // The Co-Maker's unchecked business gets no named Pending row — only their own generic one.
        $this->assertNull($checks->firstWhere('incomeSourceId', $coMakerBusiness->id));
        $coMakerPending = $checks->where('coMakerId', $coMakerA->id)->reject(fn (ReportWorkItem $row): bool => $row->isCompleted)->values();
        $this->assertCount(1, $coMakerPending);
        $this->assertNull($coMakerPending[0]->incomeSourceId);

        // The Applicant's completed business never completes the Co-Maker's.
        $this->assertTrue($reports[$applicantBusiness->id]->isCompleted);
        $this->assertFalse($reports[$coMakerBusiness->id]->isCompleted);
        $this->assertStringNotContainsString('co_maker_id', $reports[$applicantBusiness->id]->continueUrl());
        $this->assertStringContainsString('co_maker_id='.$coMakerA->id, $reports[$coMakerBusiness->id]->continueUrl());
    }

    public function test_completing_a_business_report_moves_it_between_the_tabs_and_the_counts(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'MOVING STORE');
        BusinessReport::factory()->create(['income_source_id' => $business->id, 'business_name' => 'MOVING STORE']);

        $pendingBefore = $this->items($ci, ['report_type' => 'business_report', 'tab' => 'pending']);
        $completedBefore = $this->items($ci, ['report_type' => 'business_report', 'tab' => 'completed']);
        $countsBefore = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->viewData('summary');
        $this->assertCount(1, $pendingBefore);
        $this->assertCount(0, $completedBefore);

        // The authoritative transition a successful Business Report submit performs.
        $business->update(['state' => RecordState::Complete, 'revision' => 2, 'completed_at' => now()]);

        $pendingAfter = $this->items($ci, ['report_type' => 'business_report', 'tab' => 'pending']);
        $completedAfter = $this->items($ci, ['report_type' => 'business_report', 'tab' => 'completed']);
        $countsAfter = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->viewData('summary');

        $this->assertCount(0, $pendingAfter, 'The row leaves Pending because the query says so.');
        $this->assertCount(1, $completedAfter);
        $this->assertSame($countsBefore['total'], $countsAfter['total'], 'The work item never disappears, it changes state.');
        $this->assertSame($countsBefore['pending'] - 1, $countsAfter['pending']);
        $this->assertSame($countsBefore['completed'] + 1, $countsAfter['completed']);
        $this->assertSame($countsBefore['completed_this_month'] + 1, $countsAfter['completed_this_month']);

        // The post-save fragment the modal triggers carries the same authoritative counts.
        $fragment = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Reports-Summary' => '1'])
            ->get(route('reports.index', ['report_type' => 'business_report', 'tab' => 'pending']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-reports-summary-html', $fragment);
        // Nothing is left on the filtered Pending page once the row completed.
        $this->assertStringContainsString('No reports match these filters', $fragment);
        $this->assertStringNotContainsString('data-report-card', $fragment);
    }

    public function test_a_business_that_never_had_a_report_still_offers_one(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'UNTOUCHED STORE');

        $rows = $this->items($ci)->whereIn('kind', ['business_report', 'business_check'])->keyBy(fn ($item) => $item->kind);

        // Nothing has been deleted here, so both work items are legitimately offered.
        $this->assertFalse($rows['business_report']->isCompleted);
        $this->assertSame($business->id, $rows['business_report']->incomeSourceId);
        $this->assertFalse($rows['business_check']->isCompleted);
        $this->assertNull($business->fresh()->business_report_deleted_at);
    }

    public function test_deleting_a_business_report_keeps_it_out_of_reports_without_touching_the_business(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'DELETED REPORT STORE', complete: true);
        $reportId = BusinessReport::query()->where('income_source_id', $business->id)->value('id');

        app(DeleteBusinessReport::class)->execute($ci, $folder, $business);

        // The report row is hard-deleted, and the business itself survives untouched.
        $this->assertNull(BusinessReport::query()->find($reportId));
        $this->assertSame(0, BusinessReport::query()->where('income_source_id', $business->id)->count());
        $this->assertNotNull(IncomeSource::query()->find($business->id), 'The IncomeSource stays active.');
        $this->assertNotNull($business->fresh()->business_report_deleted_at, 'The deletion is recorded on that exact business.');

        // Neither work item is re-synthesised — and repeated reads never resurrect them.
        foreach (range(1, 3) as $ignored) {
            $rows = $this->items($ci)->where('incomeSourceId', $business->id);
            $this->assertCount(0, $rows->where('kind', 'business_report'), 'A deleted Business Report must not come back as Pending.');
            $this->assertCount(0, $rows->where('kind', 'business_check'), 'Its never-saved Business Check goes with it.');
        }
        // Reading Reports is not what cleared or set anything.
        $this->assertNotNull($business->fresh()->business_report_deleted_at);
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_deleting_a_business_report_never_removes_a_saved_business_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'CHECKED STORE', complete: true);
        $check = $this->businessCheckFor($ci, $folder, $business);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $business);

        $rows = $this->items($ci)->where('incomeSourceId', $business->id);

        // The report is gone; the saved check is protected investigation data and stays Completed.
        $this->assertCount(0, $rows->where('kind', 'business_report'));
        $checkRow = $rows->firstWhere('kind', 'business_check');
        $this->assertNotNull($checkRow, 'A saved Business Check survives the report deletion.');
        $this->assertTrue($checkRow->isCompleted);
        $this->assertSame($check->id, $checkRow->sourceId);
        $this->assertSame($business->id, $checkRow->incomeSourceId);
        $this->assertNotNull(BusinessCheck::query()->find($check->id));
        $this->assertNotNull(IncomeSource::query()->find($business->id));
    }

    public function test_saving_a_business_report_again_lifts_the_suppression(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'RECREATED STORE', complete: true);
        app(DeleteBusinessReport::class)->execute($ci, $folder, $business);
        $this->assertCount(0, $this->items($ci)->where('kind', 'business_report'));

        // The explicit recreate path: a real save for this same business clears the marker and
        // reuses the exact IncomeSource rather than minting a second one.
        BusinessReport::factory()->create(['income_source_id' => $business->id, 'business_name' => 'RECREATED STORE']);
        $business->forceFill(['business_report_deleted_at' => null, 'state' => RecordState::Complete, 'revision' => 3])->save();

        $row = $this->items($ci)->firstWhere('kind', 'business_report');
        $this->assertNotNull($row, 'An explicitly recreated Business Report returns to Reports.');
        $this->assertSame($business->id, $row->incomeSourceId);
        $this->assertTrue($row->isCompleted);
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());
    }

    public function test_suppressing_one_business_leaves_the_others_and_the_other_person_alone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);

        $businessA = $this->business($folder, 'BUSINESS A', complete: true);
        $businessB = $this->business($folder, 'BUSINESS B', complete: true);
        $businessC = $this->business($folder, 'BUSINESS C', complete: true);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER BUSINESS', complete: true);
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);

        app(DeleteBusinessReport::class)->execute($ci, $folder, $businessB);

        $reports = $this->items($ci, ['report_type' => 'business_report'])->keyBy('incomeSourceId');
        // Never keyBy('incomeSourceId') here: every generic Pending row carries a null one, which
        // would silently collapse the two people's rows into a single entry.
        $checks = $this->items($ci, ['report_type' => 'business_check'])->values();

        // Only B is suppressed on its Business Report row.
        $this->assertFalse($reports->has($businessB->id));
        foreach ([$businessA, $businessC, $coMakerBusiness] as $untouched) {
            $this->assertTrue($reports->has($untouched->id), 'Business '.$untouched->id.' is unaffected.');
            $this->assertNull($untouched->fresh()->business_report_deleted_at);
        }
        // Business Check Pending is generic per person, so no business — suppressed or not — owns a
        // Pending row of its own; each person keeps exactly one entry point while work remains.
        $this->assertTrue($checks->every(fn (ReportWorkItem $row): bool => $row->incomeSourceId === null));
        $this->assertSame([null, $coMaker->id], $checks->pluck('coMakerId')->sort()->values()->all());

        // Person scoping survives: the Co-Maker's business keeps its own person, the Applicant's keep theirs.
        $this->assertNull($reports[$businessA->id]->coMakerId);
        $this->assertSame($coMaker->id, $reports[$coMakerBusiness->id]->coMakerId);
        $this->assertSame(4, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_suppressing_the_only_business_does_not_bring_back_the_zero_business_placeholders(): void
    {
        $ci = User::factory()->create();
        $withBusiness = $this->folder($ci, 'HAS BUSINESS');
        $business = $this->business($withBusiness, 'ONLY STORE', complete: true);
        $empty = $this->folder($ci, 'NO BUSINESS');

        app(DeleteBusinessReport::class)->execute($ci, $withBusiness, $business);

        // A person who genuinely has no business still gets the generic virtual placeholders.
        $emptyRows = $this->items($ci)->where('clientFolderId', $empty->id)->keyBy(fn ($item) => $item->kind);
        $this->assertTrue($emptyRows->has('business_report'));
        $this->assertTrue($emptyRows->has('business_check'));
        $this->assertNull($emptyRows['business_report']->incomeSourceId, 'The placeholder is unbound.');

        // The suppressed folder still HAS a business, so it must not fall back to a placeholder.
        $suppressedRows = $this->items($ci)->where('clientFolderId', $withBusiness->id);
        $this->assertCount(0, $suppressedRows->where('kind', 'business_report'));
        $this->assertCount(0, $suppressedRows->where('kind', 'business_check'));
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $withBusiness->id)->count());
    }

    public function test_deleting_a_business_check_keeps_it_out_of_reports_and_leaves_the_report_alone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'CHECK DELETED STORE', complete: true);
        $check = $this->businessCheckFor($ci, $folder, $business);

        // Before: the saved check reads Completed alongside its completed report.
        $before = $this->items($ci)->where('incomeSourceId', $business->id)->keyBy(fn ($item) => $item->kind);
        $this->assertTrue($before['business_check']->isCompleted);
        $this->assertTrue($before['business_report']->isCompleted);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        // The check row is hard-deleted and the deletion is recorded on that exact business.
        $this->assertNull(BusinessCheck::query()->find($check->id));
        $this->assertSame(0, BusinessCheck::query()->where('income_source_id', $business->id)->count());
        $this->assertNotNull($business->fresh()->business_check_deleted_at);
        $this->assertNull($business->fresh()->business_report_deleted_at, 'Deleting a check never suppresses the report.');

        // It stays gone across repeated reads, and never comes back as Pending.
        foreach (range(1, 3) as $ignored) {
            $rows = $this->items($ci)->where('incomeSourceId', $business->id);
            $this->assertCount(0, $rows->where('kind', 'business_check'), 'A deleted Business Check must not be re-synthesised.');

            // The Business Report is completely untouched: same state, same actions.
            $reportRow = $rows->firstWhere('kind', 'business_report');
            $this->assertNotNull($reportRow);
            $this->assertTrue($reportRow->isCompleted);
            $this->assertSame($business->id, $reportRow->incomeSourceId);
            $this->assertSame(['PDF', 'Excel'], array_column($reportRow->downloadActions(), 'format'));
            $this->assertNotNull($reportRow->previewAction());
        }

        $this->assertNotNull(IncomeSource::query()->find($business->id), 'The IncomeSource stays active.');
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $business->id)->count());
    }

    public function test_deleting_a_business_check_leaves_a_pending_business_report_pending(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'PENDING REPORT STORE');
        BusinessReport::factory()->create(['income_source_id' => $business->id, 'business_name' => 'PENDING REPORT STORE']);
        $check = $this->businessCheckFor($ci, $folder, $business);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);

        $rows = $this->items($ci)->where('incomeSourceId', $business->id);
        $reportRow = $rows->firstWhere('kind', 'business_report');

        $this->assertCount(0, $rows->where('kind', 'business_check'));
        $this->assertNotNull($reportRow);
        $this->assertFalse($reportRow->isCompleted, 'A pending report stays pending.');
        $this->assertSame('Continue Report', $reportRow->continueLabel());
    }

    public function test_saving_a_business_check_again_lifts_its_own_suppression_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'RECREATED CHECK STORE', complete: true);
        $check = $this->businessCheckFor($ci, $folder, $business);
        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);
        $this->assertCount(0, $this->items($ci)->where('kind', 'business_check'));

        // The explicit recreate path: saving a Business Check for this same business clears its own
        // marker and reuses the exact IncomeSource rather than minting a second one.
        $recreated = $this->businessCheckFor($ci, $folder, $business);
        $business->forceFill(['business_check_deleted_at' => null])->save();

        $rows = $this->items($ci)->where('incomeSourceId', $business->id)->keyBy(fn ($item) => $item->kind);
        $this->assertTrue($rows['business_check']->isCompleted, 'The recreated check returns with its saved state.');
        $this->assertSame($recreated->id, $rows['business_check']->sourceId);
        $this->assertTrue($rows['business_report']->isCompleted, 'The report was never disturbed.');
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $business->id)->count());
    }

    public function test_deleting_one_business_check_leaves_the_other_businesses_and_persons_alone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);

        $businessA = $this->business($folder, 'BUSINESS A', complete: true);
        $businessB = $this->business($folder, 'BUSINESS B', complete: true);
        $businessC = $this->business($folder, 'BUSINESS C', complete: true);
        $coMakerBusiness = $this->business($folder, 'CO-MAKER BUSINESS', complete: true);
        $coMakerBusiness->update(['co_maker_id' => $coMaker->id]);

        $this->businessCheckFor($ci, $folder, $businessA);
        $checkB = $this->businessCheckFor($ci, $folder, $businessB);
        $this->businessCheckFor($ci, $folder, $businessC);
        $this->businessCheckFor($ci, $folder, $coMakerBusiness);

        app(DeleteBusinessCheck::class)->execute($ci, $folder, $checkB);

        $reports = $this->items($ci, ['report_type' => 'business_report'])->keyBy('incomeSourceId');
        $checks = $this->items($ci, ['report_type' => 'business_check'])->keyBy('incomeSourceId');

        // Only B's check is gone. Every report — B's included — is untouched.
        $this->assertFalse($checks->has($businessB->id));
        $this->assertTrue($reports->has($businessB->id));
        $this->assertTrue($reports[$businessB->id]->isCompleted);

        foreach ([$businessA, $businessC, $coMakerBusiness] as $untouched) {
            $this->assertTrue($checks->has($untouched->id), 'Business '.$untouched->id.' keeps its check.');
            $this->assertTrue($checks[$untouched->id]->isCompleted);
            $this->assertNull($untouched->fresh()->business_check_deleted_at);
        }

        // Person scoping survives on both sides.
        $this->assertNull($checks[$businessA->id]->coMakerId);
        $this->assertSame($coMaker->id, $checks[$coMakerBusiness->id]->coMakerId);
        $this->assertSame(4, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(3, BusinessCheck::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_the_two_suppression_markers_stay_independent(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $business = $this->business($folder, 'BOTH DELETED STORE', complete: true);
        $check = $this->businessCheckFor($ci, $folder, $business);

        // Deleting the check suppresses only the check.
        app(DeleteBusinessCheck::class)->execute($ci, $folder, $check);
        $afterCheckDelete = $this->items($ci)->where('incomeSourceId', $business->id);
        $this->assertCount(0, $afterCheckDelete->where('kind', 'business_check'));
        $this->assertCount(1, $afterCheckDelete->where('kind', 'business_report'));

        // Deleting the report afterwards suppresses the report too — both markers now set, both
        // independently, and the business itself is still active.
        app(DeleteBusinessReport::class)->execute($ci, $folder, $business);
        $fresh = $business->fresh();
        $this->assertNotNull($fresh->business_check_deleted_at);
        $this->assertNotNull($fresh->business_report_deleted_at);
        $this->assertCount(0, $this->items($ci)->where('incomeSourceId', $business->id));
        $this->assertNotNull(IncomeSource::query()->find($business->id));

        // A person with no business at all still gets the generic unbound placeholders — the
        // suppressed business must not fall back to them.
        $empty = $this->folder($ci, 'NO BUSINESS');
        $emptyRows = $this->items($ci)->where('clientFolderId', $empty->id)->keyBy(fn ($item) => $item->kind);
        $this->assertTrue($emptyRows->has('business_report'));
        $this->assertTrue($emptyRows->has('business_check'));
        $this->assertNull($emptyRows['business_check']->incomeSourceId);
    }

    public function test_pagination_is_server_side_and_keeps_the_active_filters(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        foreach (range(1, 17) as $index) {
            $this->business($folder, 'BUSINESS '.$index);
        }

        $page = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'business_report', 'page' => 2]))->assertOk();
        $items = $page->viewData('items');

        $this->assertSame(17, $items->total(), 'Only the filtered type is paginated.');
        $this->assertSame(2, $items->currentPage());
        $this->assertCount(2, $items->items(), 'The page is bounded server-side, never sliced in PHP.');
        $page->assertSee('report_type=business_report', false);
        $page->assertSee('Showing 16 to 17 of 17 reports');

        // The date range rides through pagination on the same query string.
        $today = now(config('cims.display_timezone'))->toDateString();
        $dated = $this->actingAs($ci)->get(route('reports.index', ['report_type' => 'business_report', 'from' => $today, 'page' => 2]))->assertOk();
        $this->assertSame(17, $dated->viewData('items')->total());
        $dated->assertSee('from='.$today, false);
    }

    public function test_pagination_links_carry_every_filter_sort_and_tab(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT', 'BRBI-CI-2026-00111');
        foreach (range(1, 17) as $index) {
            $this->business($folder, 'BUSINESS '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }
        $today = now(config('cims.display_timezone'))->toDateString();

        $query = [
            'tab' => 'pending', 'search' => 'ALPHA', 'report_type' => 'business_report',
            'person' => 'applicant', 'from' => $today, 'to' => $today,
            'sort' => 'client', 'direction' => 'desc',
        ];
        $response = $this->actingAs($ci)->get(route('reports.index', $query))->assertOk();
        $html = str_replace('&amp;', '&', $response->getContent());

        // Every pagination link rebuilds the whole authoritative query, not just ?page=.
        foreach (['tab=pending', 'search=ALPHA', 'report_type=business_report', 'person=applicant',
            'from='.$today, 'to='.$today, 'sort=client', 'direction=desc', 'page=2'] as $carried) {
            $this->assertStringContainsString($carried, $html, $carried.' survives pagination.');
        }

        // The results region and pagination hook the async handler looks for, with real hrefs.
        $this->assertStringContainsString('data-reports-listing', $html);
        $this->assertStringContainsString('data-reports-pagination', $html);
        $this->assertStringContainsString('aria-label="Reports pagination"', $html);
    }

    public function test_page_two_returns_the_correct_server_side_rows_for_table_and_cards(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        foreach (range(1, 17) as $index) {
            $this->business($folder, 'BUSINESS '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        $query = ['report_type' => 'business_report', 'sort' => 'client', 'direction' => 'asc'];
        $pageOne = $this->items($ci, $query + ['page' => 1]);
        $pageTwo = $this->items($ci, $query + ['page' => 2]);

        $this->assertCount(15, $pageOne);
        $this->assertCount(2, $pageTwo, 'The second page is produced by the database, not by slicing in the view.');
        $this->assertEmpty(array_intersect($pageOne->pluck('incomeSourceId')->all(), $pageTwo->pluck('incomeSourceId')->all()));

        // The mobile cards render exactly the same paginated rows as the desktop table.
        $html = $this->actingAs($ci)->get(route('reports.index', $query + ['page' => 2]))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'data-report-card'));
        $this->assertStringContainsString('Showing 16 to 17 of 17 reports', $html);
    }

    public function test_a_pagination_click_can_fetch_the_listing_alone(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        foreach (range(1, 17) as $index) {
            $this->business($folder, 'BUSINESS '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        $before = DB::table('income_sources')->count();

        $fragment = $this->actingAs($ci)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('reports.index', ['report_type' => 'business_report', 'page' => 2]))
            ->assertOk();
        $html = $fragment->getContent();

        // Only the listing comes back — the shell, KPI cards and toolbar are never re-rendered.
        $this->assertStringNotContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('Total Reports', $html);
        // And the replacement content still carries working pagination for the next click.
        $this->assertStringContainsString('data-reports-pagination', $html);
        $this->assertStringContainsString('page=1', str_replace('&amp;', '&', $html));

        $this->assertSame($before, DB::table('income_sources')->count(), 'Paginating mutates nothing.');
    }

    public function test_opening_the_page_creates_nothing_and_generates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');
        $this->business($folder);

        $before = [
            'cibi_reports' => DB::table('cibi_reports')->count(),
            'business_reports' => DB::table('business_reports')->count(),
            'residence_checks' => DB::table('residence_checks')->count(),
            'business_checks' => DB::table('business_checks')->count(),
            'income_sources' => DB::table('income_sources')->count(),
            'generated_reports' => DB::table('generated_reports')->count(),
        ];

        $this->actingAs($ci)->get(route('reports.index'))->assertOk();
        $this->actingAs($ci)->get(route('reports.index', ['tab' => 'pending']))->assertOk();
        $this->actingAs($ci)->get(route('reports.index', ['tab' => 'completed']))->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must be untouched by a read-only listing.');
        }
        $this->assertSame(0, GeneratedReport::query()->count(), 'The workspace never requires or creates a GeneratedReport.');
    }

    public function test_the_listing_query_count_does_not_grow_with_the_number_of_work_items(): void
    {
        $ci = User::factory()->create();
        $measure = function (int $businesses) use ($ci): int {
            $folder = $this->folder($ci, 'LOAD, CLIENT '.$businesses);
            CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Load Co-Maker '.$businesses]);
            foreach (range(1, $businesses) as $index) {
                $this->business($folder, 'LOAD BUSINESS '.$index);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($ci)->get(route('reports.index'))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame($measure(1), $measure(6), 'Rows carry their own display data — no per-row queries.');
    }

    public function test_the_empty_states_are_professional_and_carry_no_sample_data(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('reports.index'))->assertOk()->assertSee('No report work items available.');
        $this->actingAs($ci)->get(route('reports.index', ['tab' => 'pending']))->assertOk()
            ->assertSee('No pending reports.')->assertSee('All available report work items are completed.');
        $response = $this->actingAs($ci)->get(route('reports.index', ['tab' => 'completed']))->assertOk()
            ->assertSee('No completed reports yet.')->assertSee('Completed reports will appear here once report work is finished.');

        foreach (['Dela Cruz, Juan', 'Santos, Maria', 'Lim, Pedro', 'Garcia, Anna', 'FF, FFF FF'] as $sample) {
            $response->assertDontSee($sample);
        }
        $this->assertSame(['total' => 0, 'pending' => 0, 'completed' => 0, 'completed_this_month' => 0], $response->viewData('summary'));
    }

    public function test_neither_the_table_row_nor_the_mobile_card_shows_a_client_number(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT', 'BRBI-CI-2026-00777');

        $html = $this->actingAs($ci)->get(route('reports.index'))->assertOk()->getContent();
        $cardStart = strpos($html, 'data-report-card');

        $this->assertStringNotContainsString('BRBI-CI-2026-00777', substr($html, 0, $cardStart), 'The desktop row drops the client number.');
        $this->assertStringNotContainsString('BRBI-CI-2026-00777', substr($html, $cardStart), 'The mobile card drops it too.');
        $this->assertStringNotContainsString('Client No.', $html);

        // The folder itself of course keeps its number — this change is presentation only.
        $this->assertSame('BRBI-CI-2026-00777', $folder->fresh()->folder_number);
    }

    public function test_the_page_renders_a_mobile_card_for_every_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ALPHA, CLIENT');

        $response = $this->actingAs($ci)->get(route('reports.index'))->assertOk();
        $html = $response->getContent();

        $this->assertSame($response->viewData('items')->count(), substr_count($html, 'data-report-card'));
        $this->assertStringContainsString('lg:hidden', $html);
        $this->assertNotNull($folder->id);
    }

    public function test_a_zero_business_person_has_four_non_persistent_pending_work_items(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ZERO BUSINESS CLIENT');

        $first = $this->items($ci, ['client_folder_id' => $folder->id]);
        $second = $this->items($ci, ['client_folder_id' => $folder->id]);

        $this->assertSame(
            ['business_check', 'business_report', 'cibi', 'residence_check'],
            $first->pluck('kind')->sort()->values()->all(),
        );
        $this->assertCount(4, $first);
        $this->assertTrue($first->every(fn (ReportWorkItem $item): bool => ! $item->isCompleted));
        $this->assertCount(4, $second, 'Repeated Reports reads must remain idempotent.');

        $businessItems = $first->whereIn('kind', ['business_report', 'business_check'])->values();
        $this->assertTrue($businessItems->every(fn (ReportWorkItem $item): bool => $item->isUnboundBusiness()));
        $this->assertTrue($businessItems->every(fn (ReportWorkItem $item): bool => $item->sourceId === null && $item->incomeSourceId === null));
        $this->assertCount(2, $businessItems->pluck(fn (ReportWorkItem $item): string => $item->key())->unique());

        $this->assertDatabaseCount('income_sources', 0);
        $this->assertDatabaseCount('business_reports', 0);
        $this->assertDatabaseCount('business_checks', 0);
    }

    public function test_unbound_business_actions_reuse_the_existing_first_entry_flows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FIRST ENTRY CLIENT');
        $auditCount = AuditLog::query()->count();
        $generatedCount = GeneratedReport::query()->count();
        $items = $this->items($ci, ['client_folder_id' => $folder->id])->keyBy('kind');

        $this->assertSame(route('client-folders.income-sources.index', $folder->id), $items['business_report']->continueUrl());
        $this->assertSame(route('client-folders.business-checks.create', $folder->id), $items['business_check']->continueUrl());

        $html = $this->actingAs($ci)->get(route('reports.index', ['client_folder_id' => $folder->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-modal-open="add-business-template-dialog"', $html);
        $this->assertStringContainsString('data-business-template-base-url="'.e(route('client-folders.income-sources.index', $folder->id)).'"', $html);
        $this->assertStringContainsString('data-add-business-template-select', $html);
        $this->assertStringContainsString('data-add-business-next', $html);
        // The Add Business modal's own Cancel — now carrying the shared close icon before its label.
        $this->assertMatchesRegularExpression(
            '/<button type="button" class="ui-button-secondary" data-modal-close><svg[^>]*>.*?<\/svg>\s*Cancel<\/button>/s',
            $html,
        );
        $this->assertStringContainsString('data-check-report-url="'.e(route('client-folders.business-checks.create', $folder->id)).'"', $html);

        $template = IncomeSourceTemplate::query()->activeBusiness()->firstOrFail();
        $this->assertStringContainsString('<option value="'.$template->id.'">'.$template->name.'</option>', $html);

        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [
            $folder->id, 'income_source_template_id' => $template->id,
        ]))
            ->assertOk()
            ->assertSee($template->name);
        $this->actingAs($ci)->get($items['business_check']->continueUrl())
            ->assertOk()
            ->assertSee('Add Business');

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('dialog.dataset.businessReportBaseUrl = modalTrigger.dataset.businessTemplateBaseUrl', $script);
        $this->assertStringContainsString('encodeURIComponent(templateSelect.value)', $script);

        $this->assertDatabaseCount('income_sources', 0);
        $this->assertDatabaseCount('business_reports', 0);
        $this->assertDatabaseCount('business_checks', 0);
        $this->assertSame($generatedCount, GeneratedReport::query()->count());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_unbound_business_placeholders_transition_to_exact_bound_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'PLACEHOLDER TRANSITION CLIENT');
        $this->assertCount(2, $this->items($ci, ['client_folder_id' => $folder->id])->filter->isUnboundBusiness());

        $business = $this->business($folder, 'BOUND BUSINESS');
        $businessItems = $this->items($ci, ['client_folder_id' => $folder->id])
            ->whereIn('kind', ['business_report', 'business_check'])
            ->values();

        $this->assertCount(2, $businessItems);

        // The Business Report placeholder becomes an exact bound row, exactly as before.
        $report = $businessItems->firstWhere('kind', 'business_report');
        $this->assertFalse($report->isUnboundBusiness());
        $this->assertSame($business->id, $report->incomeSourceId);
        $this->assertSame('BOUND BUSINESS', $report->businessName);
        $this->assertFalse($report->isCompleted);

        // The Business Check entry point stays generic — creating a business gives the CI somewhere
        // to record a check, it does not turn that business into a Pending Business Check row.
        $check = $businessItems->firstWhere('kind', 'business_check');
        $this->assertNull($check->incomeSourceId);
        $this->assertNull($check->businessName);
        $this->assertFalse($check->isCompleted);
    }

    public function test_multiple_similarly_named_businesses_keep_exact_independent_rows(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'MULTIPLE BUSINESS CLIENT');
        $businessA = $this->business($folder, 'SAME BUSINESS NAME');
        $businessB = $this->business($folder, 'SAME BUSINESS NAME');

        $businessItems = $this->items($ci, ['client_folder_id' => $folder->id])
            ->whereIn('kind', ['business_report', 'business_check'])
            ->values();

        // Two Business Report rows — one per exact business, never collapsed by their shared name —
        // plus the single generic Business Check entry point for this person.
        $this->assertCount(3, $businessItems);
        $this->assertSame([$businessA->id, $businessB->id], $businessItems->where('kind', 'business_report')->pluck('incomeSourceId')->sort()->values()->all());
        $this->assertNotSame(
            $businessItems->first(fn (ReportWorkItem $item): bool => $item->kind === 'business_report' && $item->incomeSourceId === $businessA->id)->continueUrl(),
            $businessItems->first(fn (ReportWorkItem $item): bool => $item->kind === 'business_report' && $item->incomeSourceId === $businessB->id)->continueUrl(),
        );

        $checks = $businessItems->where('kind', 'business_check')->values();
        $this->assertCount(1, $checks);
        $this->assertNull($checks[0]->incomeSourceId);
    }

    public function test_unbound_business_placeholders_are_isolated_for_applicant_and_each_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'PERSON PLACEHOLDER CLIENT');
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $businessItems = $this->items($ci, ['client_folder_id' => $folder->id])
            ->whereIn('kind', ['business_report', 'business_check'])
            ->values();

        $this->assertCount(6, $businessItems);
        $this->assertCount(2, $businessItems->whereStrict('coMakerId', null));
        $this->assertCount(2, $businessItems->where('coMakerId', $coMakerA->id));
        $this->assertCount(2, $businessItems->where('coMakerId', $coMakerB->id));
        $this->assertCount(6, $businessItems->pluck(fn (ReportWorkItem $item): string => $item->key())->unique());

        foreach ([$coMakerA, $coMakerB] as $coMaker) {
            $this->assertTrue($businessItems->where('coMakerId', $coMaker->id)->every(
                fn (ReportWorkItem $item): bool => str_contains($item->continueUrl(), 'co_maker_id='.$coMaker->id),
            ));
        }
        $this->assertTrue($businessItems->whereStrict('coMakerId', null)->every(
            fn (ReportWorkItem $item): bool => ! str_contains($item->continueUrl(), 'co_maker_id='),
        ));
    }

    /** @return Collection<int, ReportWorkItem> */
    private function items(User $ci, array $query = []): Collection
    {
        return collect($this->actingAs($ci)->get(route('reports.index', $query))->assertOk()->viewData('items')->items());
    }

    private function item(User $ci, string $kind, ClientFolder $folder): ReportWorkItem
    {
        $item = $this->items($ci)->first(fn ($row) => $row->kind === $kind && $row->clientFolderId === $folder->id);
        $this->assertNotNull($item, "Expected a {$kind} work item for folder {$folder->id}.");

        return $item;
    }

    private function folder(User $ci, string $name, ?string $number = null): ClientFolder
    {
        return ClientFolder::factory()->create(array_filter([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name, 'folder_number' => $number,
        ]));
    }

    /** A saved Business Check against one exact existing business, on that business's own person. */
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

    /** A business income source, optionally with an actually-submitted (revision > 1) Business Report. */
    private function business(ClientFolder $folder, string $name = 'TEST BUSINESS', bool $complete = false): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('is_fallback', false)->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'state' => $complete ? RecordState::Complete : RecordState::Draft,
            'completed_at' => $complete ? now() : null,
            'revision' => $complete ? 2 : 1,
        ]);

        if ($complete) {
            BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => $name]);
        }

        return $source;
    }
}
