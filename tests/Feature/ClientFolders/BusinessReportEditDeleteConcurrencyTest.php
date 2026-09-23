<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business Report edit vs delete concurrency. No hard locks: every guard is the exact IncomeSource
 * revision compared under its row lock.
 *
 *  - A report-only delete advances the revision, so an edit form opened before it can never
 *    silently recreate the deleted report; a form reopened afterwards still can.
 *  - Both delete dialogs carry expected_revision, so a delete confirmed from a screen loaded before
 *    another CI's save deletes nothing.
 *  - A record that is already gone answers with friendly wording, never the model class or id.
 *
 * SQLite :memory: runs a single connection, so these are sequential stale requests, not genuinely
 * overlapping transactions.
 */
class BusinessReportEditDeleteConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const DELETED_WHILE_EDITING = 'This Business Report was deleted by another user while you were working on it. Please return to the Business Report page.';

    private const UNAVAILABLE_ON_SAVE = 'This Business Report is no longer available. It may have been deleted by another CI while you were editing it. Please return to the Business Reports page and review the latest information.';

    private const REPORT_STALE_DELETE = 'This Business Report was updated by another CI after you opened this page. The delete was not performed. Please refresh and review the latest information before deleting.';

    private const BUSINESS_STALE_DELETE = 'This business was updated by another CI after you opened this page. The delete was not performed. Please refresh and review the latest information before deleting.';

    private const REPORT_ALREADY_DELETED = 'This Business Report is no longer available. It may have already been deleted by another CI. Please refresh the Business Reports page.';

    private const BUSINESS_ALREADY_DELETED = 'This business is no longer available. It may have already been deleted by another CI. Please refresh the page.';

    private const PAGE_OUT_OF_DATE = 'This page is out of date. Nothing was deleted. Please refresh and review the latest information before deleting.';

    private const BULK_STALE_DELETE = 'One or more selected Business Reports were updated after you opened this page. Nothing was deleted. Please refresh and review the latest information before deleting again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------- Part A

    public function test_a_form_opened_before_a_report_only_delete_cannot_recreate_the_report(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Original Store');
        $check = $this->linkedCheck($ci1, $folder, $source);
        $openedRevision = $source->fresh()->revision;
        $checkBefore = $check->fresh()->getAttributes();

        $this->reportOnlyDelete($ci2, $folder, $source, $openedRevision)->assertOk();
        $afterDelete = $source->fresh();
        $this->assertSame($openedRevision + 1, $afterDelete->revision);
        $auditsBefore = AuditLog::query()->count();

        $this->save($ci1, $folder, $source, 'Stale Resurrection', $openedRevision)
            ->assertSessionHas('business_report_deleted.message', self::DELETED_WHILE_EDITING)
            ->assertSessionHasNoErrors();

        $fresh = $source->fresh();
        $this->assertSame(0, BusinessReport::query()->where('income_source_id', $source->id)->count());
        $this->assertNotNull($fresh->business_report_deleted_at);
        $this->assertSame($afterDelete->revision, $fresh->revision);
        $this->assertSame('Original Store', $fresh->business_name);
        $this->assertSame($afterDelete->state, $fresh->state);
        $this->assertSame($checkBefore, $check->fresh()->getAttributes());
        $this->assertSame($auditsBefore, AuditLog::query()->count());
    }

    public function test_the_stale_form_renders_the_terminal_deleted_notification(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Original Store');
        $openedRevision = $source->fresh()->revision;
        $this->reportOnlyDelete($ci2, $folder, $source, $openedRevision)->assertOk();

        $this->actingAs($ci1)
            ->from(route('client-folders.income-sources.edit', [$folder, $source]))
            ->followingRedirects()
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->fields('Stale Resurrection') + ['expected_revision' => $openedRevision])
            ->assertOk()
            ->assertSee('data-business-deleted-notify', false)
            ->assertSee(self::DELETED_WHILE_EDITING)
            ->assertDontSee('App\\Models', false);
    }

    public function test_a_fresh_reopen_after_report_only_delete_can_intentionally_recreate_on_the_same_source(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Original Store');
        $check = $this->linkedCheck($ci1, $folder, $source);
        $this->reportOnlyDelete($ci2, $folder, $source, $source->fresh()->revision)->assertOk();

        $currentRevision = $source->fresh()->revision;
        $this->actingAs($ci1)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertSee('name="expected_revision" value="'.$currentRevision.'"', false);

        $this->save($ci1, $folder, $source, 'Recreated Store', $currentRevision)->assertSessionHasNoErrors();

        $report = BusinessReport::query()->where('income_source_id', $source->id)->sole();
        $this->assertSame('Recreated Store', $report->business_name);
        $this->assertNull($source->fresh()->business_report_deleted_at);
        $this->assertSame($source->id, $check->fresh()->income_source_id);
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_report_only_delete_keeps_the_linked_business_check(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Check Keeper');
        $check = $this->linkedCheck($ci1, $folder, $source);

        $this->reportOnlyDelete($ci1, $folder, $source, $source->fresh()->revision)->assertOk()->assertJson(['deleted' => 1]);

        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);
        $this->assertDatabaseHas('business_checks', ['id' => $check->id, 'income_source_id' => $source->id]);
    }

    public function test_the_manage_page_delete_dialog_carries_the_current_revision(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Token Store');

        $this->actingAs($ci1)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('name="expected_revision" value="'.$source->fresh()->revision.'"', false);
    }

    // ---------------------------------------------------------------- Part B

    public function test_a_stale_save_after_full_delete_is_friendly_and_recreates_nothing(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Gone Store');
        $check = $this->linkedCheck($ci1, $folder, $source);
        $openedRevision = $source->fresh()->revision;

        $this->fullDelete($ci2, $folder, $source, $openedRevision)->assertRedirect();

        $html = $this->save($ci1, $folder, $source, 'Stale After Full Delete', $openedRevision)
            ->assertNotFound()
            ->assertSee(self::UNAVAILABLE_ON_SAVE)
            ->getContent();
        $this->assertNoLeak($html, $source);

        $json = $this->actingAs($ci1)
            ->putJson(route('client-folders.income-sources.business.update', [$folder, $source]), $this->fields('Stale JSON') + ['expected_revision' => $openedRevision])
            ->assertNotFound()
            ->assertJson(['message' => self::UNAVAILABLE_ON_SAVE])
            ->getContent();
        $this->assertNoLeak($json, $source);

        $this->assertSame(0, IncomeSource::withTrashed()->whereKey($source->id)->count());
        $this->assertSame(0, IncomeSource::withTrashed()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(0, BusinessReport::query()->where('income_source_id', $source->id)->count());
        $this->assertNull(BusinessCheck::query()->find($check->id));
    }

    // ---------------------------------------------------------------- Part C

    public function test_a_stale_report_only_delete_after_another_cis_save_deletes_nothing(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Original Store');
        $check = $this->linkedCheck($ci1, $folder, $source);
        $loadedRevision = $source->fresh()->revision;

        $this->save($ci1, $folder, $source, 'Newer Store', $loadedRevision)->assertSessionHasNoErrors();

        $this->reportOnlyDelete($ci2, $folder, $source, $loadedRevision)
            ->assertStatus(409)
            ->assertJson(['result' => 'conflict', 'message' => self::REPORT_STALE_DELETE]);

        // Browser (non-JSON) path: same refusal, friendly flash.
        $this->actingAs($ci2)
            ->delete(route('client-folders.income-sources.business-report.destroy', [$folder, $source]), ['expected_revision' => $loadedRevision])
            ->assertRedirect(route('client-folders.income-sources.manage', $folder))
            ->assertSessionHas('status', self::REPORT_STALE_DELETE);

        $fresh = $source->fresh();
        $this->assertSame($loadedRevision + 1, $fresh->revision);
        $this->assertNull($fresh->business_report_deleted_at);
        $this->assertSame('Newer Store', BusinessReport::query()->where('income_source_id', $source->id)->sole()->business_name);
        $this->assertNotNull($check->fresh());
    }

    public function test_a_stale_full_delete_after_another_cis_save_deletes_nothing(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Original Store');
        $check = $this->linkedCheck($ci1, $folder, $source);
        $loadedRevision = $source->fresh()->revision;
        $auditsBefore = AuditLog::query()->where('action', 'income_source.deleted')->count();

        $this->save($ci1, $folder, $source, 'Newer Store', $loadedRevision)->assertSessionHasNoErrors();

        $this->fullDelete($ci2, $folder, $source, $loadedRevision)
            ->assertRedirect(route('client-folders.income-sources.manage', $folder))
            ->assertSessionHas('status', self::BUSINESS_STALE_DELETE);
        $this->actingAs($ci2)
            ->deleteJson(route('client-folders.income-sources.destroy', [$folder, $source]), ['expected_revision' => $loadedRevision])
            ->assertStatus(409)
            ->assertJson(['message' => self::BUSINESS_STALE_DELETE]);

        $this->assertNotNull($source->fresh());
        $this->assertSame('Newer Store', BusinessReport::query()->where('income_source_id', $source->id)->sole()->business_name);
        $this->assertNotNull($check->fresh());
        $this->assertSame($auditsBefore, AuditLog::query()->where('action', 'income_source.deleted')->count());
    }

    public function test_fresh_deletes_succeed_in_both_modes(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $reportOnly = $this->savedBusiness($ci1, $folder, 'Report Only Store');
        $full = $this->savedBusiness($ci1, $folder, 'Full Delete Store', 'leasing_non_agricultural');
        $fullCheck = $this->linkedCheck($ci1, $folder, $full);

        $this->reportOnlyDelete($ci1, $folder, $reportOnly, $reportOnly->fresh()->revision)->assertOk();
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $reportOnly->id]);
        $this->assertNotNull($reportOnly->fresh());

        $this->fullDelete($ci1, $folder, $full, $full->fresh()->revision)
            ->assertRedirect()
            ->assertSessionHas('status', 'Business and linked Business Report and Business Check permanently deleted.');
        $this->assertNull(IncomeSource::withTrashed()->find($full->id));
        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $full->id]);
        $this->assertNull(BusinessCheck::query()->find($fullCheck->id));
    }

    // ---------------------------------------------------------------- Double / missing deletes

    public function test_a_double_report_only_delete_is_friendly(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Twice Store');
        $openedRevision = $source->fresh()->revision;

        $this->reportOnlyDelete($ci1, $folder, $source, $openedRevision)->assertOk();

        $json = $this->reportOnlyDelete($ci2, $folder, $source, $openedRevision)
            ->assertNotFound()
            ->assertJson(['message' => self::REPORT_ALREADY_DELETED])
            ->getContent();
        $this->assertNoLeak($json, $source);

        $this->actingAs($ci2)
            ->delete(route('client-folders.income-sources.business-report.destroy', [$folder, $source]), ['expected_revision' => $openedRevision])
            ->assertNotFound()
            ->assertSee(self::REPORT_ALREADY_DELETED);
    }

    public function test_deletes_against_an_already_fully_deleted_business_are_friendly(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Gone Store');
        $openedRevision = $source->fresh()->revision;
        $this->fullDelete($ci1, $folder, $source, $openedRevision)->assertRedirect();

        foreach ([
            $this->reportOnlyDelete($ci2, $folder, $source, $openedRevision),
            $this->actingAs($ci2)->deleteJson(route('client-folders.income-sources.destroy', [$folder, $source]), ['expected_revision' => $openedRevision]),
        ] as $response) {
            $response->assertNotFound()->assertJson(['message' => self::BUSINESS_ALREADY_DELETED]);
            $this->assertNoLeak($response->getContent(), $source);
        }

        $html = $this->fullDelete($ci2, $folder, $source, $openedRevision)
            ->assertNotFound()
            ->assertSee(self::BUSINESS_ALREADY_DELETED)
            ->getContent();
        $this->assertNoLeak($html, $source);
    }

    // ---------------------------------------------------------------- Isolation

    public function test_revision_and_delete_guards_never_cross_people_sources_or_folders(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci1->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicant = $this->savedBusiness($ci1, $folder, 'Applicant Store');
        $applicantOther = $this->savedBusiness($ci1, $folder, 'Applicant Leasing', 'leasing_non_agricultural');
        $sourceA = $this->savedBusiness($ci1, $folder, 'CMA Store', 'retail_grocery_water_refilling', $coMakerA);
        $sourceB = $this->savedBusiness($ci1, $folder, 'CMB Store', 'retail_grocery_water_refilling', $coMakerB);
        $otherFolderSource = $this->savedBusiness($ci1, $otherFolder, 'Other Folder Store');

        $untouched = collect([$applicantOther, $sourceB, $otherFolderSource])
            ->mapWithKeys(fn (IncomeSource $s) => [$s->id => [$s->fresh()->revision, $s->businessReport()->value('id')]]);

        // Report-only delete of the Applicant's business and Co-Maker A's business.
        $this->reportOnlyDelete($ci1, $folder, $applicant, $applicant->fresh()->revision)->assertOk();
        $this->reportOnlyDelete($ci1, $folder, $sourceA, $sourceA->fresh()->revision)->assertOk();

        foreach ($untouched as $id => [$revision, $reportId]) {
            $this->assertSame($revision, IncomeSource::query()->find($id)->revision);
            $this->assertSame($reportId, BusinessReport::query()->where('income_source_id', $id)->value('id'));
            $this->assertNull(IncomeSource::query()->find($id)->business_report_deleted_at);
        }

        // Co-Maker B's still-valid form keeps saving normally.
        $this->save($ci1, $folder, $sourceB, 'CMB Updated', $sourceB->fresh()->revision, $coMakerB)->assertSessionHasNoErrors();
        $this->assertSame('CMB Updated', $sourceB->fresh()->business_name);

        // A cross-folder delete id is rejected by the scoped binding, friendly and without deleting.
        $this->actingAs($ci1)
            ->deleteJson(route('client-folders.income-sources.destroy', [$folder, $otherFolderSource]), ['expected_revision' => $otherFolderSource->fresh()->revision])
            ->assertNotFound();
        $this->assertNotNull($otherFolderSource->fresh());
    }

    // ---------------------------------------------------------------- Mandatory single-delete token

    public function test_single_deletes_without_a_revision_token_are_rejected_and_delete_nothing(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $source = $this->savedBusiness($ci1, $folder, 'Tokenless Store');
        $check = $this->linkedCheck($ci1, $folder, $source);
        $revision = $source->fresh()->revision;

        $this->actingAs($ci1)->deleteJson(route('client-folders.income-sources.business-report.destroy', [$folder, $source]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_revision' => self::PAGE_OUT_OF_DATE]);
        $this->actingAs($ci1)->delete(route('client-folders.income-sources.business-report.destroy', [$folder, $source]))
            ->assertSessionHasErrors('expected_revision');
        $this->actingAs($ci1)->deleteJson(route('client-folders.income-sources.destroy', [$folder, $source]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_revision' => self::PAGE_OUT_OF_DATE]);
        $this->actingAs($ci1)->delete(route('client-folders.income-sources.destroy', [$folder, $source]))
            ->assertSessionHasErrors('expected_revision');
        $this->actingAs($ci1)->deleteJson(route('client-folders.income-sources.destroy', [$folder, $source]), ['expected_revision' => 'not-a-number'])
            ->assertUnprocessable();

        $this->assertSame($revision, $source->fresh()->revision);
        $this->assertNull($source->fresh()->business_report_deleted_at);
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $source->id)->count());
        $this->assertNotNull($check->fresh());
    }

    // ---------------------------------------------------------------- Bulk Delete Selected

    public function test_delete_selected_modal_actions_are_equal_width_on_mobile_and_auto_width_on_desktop(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $this->savedBusiness($ci1, $folder, 'Responsive Store');

        $html = $this->actingAs($ci1)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="ui-button-secondary w-full sm:w-auto"[^>]*data-business-delete-selected-cancel/', $html);
        $this->assertMatchesRegularExpression('/class="w-full sm:w-auto"[^>]*data-business-delete-selected-form/', $html);
        $this->assertMatchesRegularExpression('/class="ui-button-danger w-full sm:w-auto"[^>]*data-business-delete-selected-submit/', $html);
    }

    public function test_each_selectable_row_renders_its_current_revision(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $a = $this->savedBusiness($ci1, $folder, 'Bulk A');
        $b = $this->savedBusiness($ci1, $folder, 'Bulk B', 'leasing_non_agricultural');

        $html = $this->actingAs($ci1)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        foreach ([$a, $b] as $source) {
            $this->assertMatchesRegularExpression('/data-business-select value="'.$source->id.'" data-business-revision="'.$source->fresh()->revision.'"/', $html);
        }
    }

    public function test_bulk_delete_missing_one_or_all_revisions_is_rejected_and_deletes_nothing(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $a = $this->savedBusiness($ci1, $folder, 'Bulk A');
        $b = $this->savedBusiness($ci1, $folder, 'Bulk B', 'leasing_non_agricultural');

        foreach ([
            null,
            [$a->id => $a->fresh()->revision],
            [$a->id => $a->fresh()->revision, $b->id => ''],
            [$a->id => $a->fresh()->revision, 999999 => 5],
        ] as $revisions) {
            $payload = ['co_maker_id' => '', 'income_source_ids' => [$a->id, $b->id]];
            if ($revisions !== null) {
                $payload['expected_revisions'] = $revisions;
            }

            $response = $this->actingAs($ci1)
                ->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), $payload)
                ->assertUnprocessable();
            $this->assertStringContainsString(self::PAGE_OUT_OF_DATE, $response->getContent());
        }

        $this->assertSame(2, BusinessReport::query()->whereIn('income_source_id', [$a->id, $b->id])->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'business_report.deleted')->count());
    }

    public function test_fresh_bulk_delete_succeeds_and_keeps_linked_checks(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $a = $this->savedBusiness($ci1, $folder, 'Bulk A');
        $b = $this->savedBusiness($ci1, $folder, 'Bulk B', 'leasing_non_agricultural');
        $check = $this->linkedCheck($ci1, $folder, $a);

        $this->bulkDelete($ci1, $folder, [$a, $b])->assertOk()->assertJson(['deleted' => 2]);

        $this->assertSame(0, BusinessReport::query()->whereIn('income_source_id', [$a->id, $b->id])->count());
        $this->assertNotNull($a->fresh()->business_report_deleted_at);
        $this->assertNotNull($check->fresh());
    }

    public function test_a_stale_bulk_delete_is_rejected_whole_and_deletes_nothing(): void
    {
        [$ci1, $ci2, $folder] = $this->twoCis();
        $a = $this->savedBusiness($ci1, $folder, 'Bulk A');
        $b = $this->savedBusiness($ci1, $folder, 'Bulk B', 'leasing_non_agricultural');
        $c = $this->savedBusiness($ci1, $folder, 'Bulk C', 'retail_grocery_water_refilling', CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Unrelated Co-Maker']));
        // CI2's screen was rendered now, before CI1's save.
        $renderedRevisions = [$a->id => $a->fresh()->revision, $b->id => $b->fresh()->revision];
        $checkB = $this->linkedCheck($ci1, $folder, $b);

        // CI1 updates the SECOND selected report, so an earlier item in the batch is still current.
        $this->save($ci1, $folder, $b, 'Bulk B Newer', $renderedRevisions[$b->id])->assertSessionHasNoErrors();
        $revisionsBefore = [$a->id => $a->fresh()->revision, $b->id => $b->fresh()->revision, $c->id => $c->fresh()->revision];

        $json = $this->actingAs($ci2)
            ->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
                'co_maker_id' => '',
                'income_source_ids' => [$a->id, $b->id],
                'expected_revisions' => $renderedRevisions,
            ])
            ->assertStatus(409)
            ->assertJson(['result' => 'conflict', 'message' => self::BULK_STALE_DELETE])
            ->getContent();
        $this->assertStringNotContainsString((string) $a->id, $json);
        $this->assertStringNotContainsString('App\\', $json);

        // Browser path: same refusal.
        $this->actingAs($ci2)
            ->delete(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
                'co_maker_id' => '',
                'income_source_ids' => [$a->id, $b->id],
                'expected_revisions' => $renderedRevisions,
            ])
            ->assertRedirect(route('client-folders.income-sources.manage', $folder))
            ->assertSessionHas('status', self::BULK_STALE_DELETE);

        // Nothing deleted — including the still-current first item — and nothing else changed.
        foreach ([$a, $b, $c] as $source) {
            $fresh = $source->fresh();
            $this->assertSame(1, BusinessReport::query()->where('income_source_id', $source->id)->count());
            $this->assertNull($fresh->business_report_deleted_at);
            $this->assertSame($revisionsBefore[$source->id], $fresh->revision);
        }
        $this->assertSame('Bulk B Newer', BusinessReport::query()->where('income_source_id', $b->id)->value('business_name'));
        $this->assertNotNull($checkB->fresh());
        $this->assertSame(0, AuditLog::query()->where('action', 'business_report.deleted')->count());
    }

    public function test_bulk_delete_keeps_exact_person_and_folder_scoping(): void
    {
        [$ci1, , $folder] = $this->twoCis();
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci1->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $applicant = $this->savedBusiness($ci1, $folder, 'Applicant Store');
        $coMakerSource = $this->savedBusiness($ci1, $folder, 'CMA Store', 'retail_grocery_water_refilling', $coMaker);
        $otherFolderSource = $this->savedBusiness($ci1, $otherFolder, 'Other Folder Store');

        // Applicant scope with a Co-Maker id, and a cross-folder id — both with correct tokens.
        $this->bulkDelete($ci1, $folder, [$applicant, $coMakerSource])->assertUnprocessable();
        $this->bulkDelete($ci1, $folder, [$applicant, $otherFolderSource])->assertUnprocessable();
        $this->bulkDelete($ci1, $folder, [$applicant], $coMaker)->assertUnprocessable();

        $this->assertSame(3, BusinessReport::query()->whereIn('income_source_id', [$applicant->id, $coMakerSource->id, $otherFolderSource->id])->count());

        // The exact Co-Maker scope deletes only its own report.
        $this->bulkDelete($ci1, $folder, [$coMakerSource], $coMaker)->assertOk()->assertJson(['deleted' => 1]);
        $this->assertSame(0, BusinessReport::query()->where('income_source_id', $coMakerSource->id)->count());
        $this->assertSame(1, BusinessReport::query()->where('income_source_id', $applicant->id)->count());
    }

    // ---------------------------------------------------------------- Helpers

    /** @param  array<int, IncomeSource>  $sources */
    private function bulkDelete(User $ci, ClientFolder $folder, array $sources, ?CoMaker $coMaker = null)
    {
        return $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy-selected', $folder), [
            'co_maker_id' => $coMaker?->id ?? '',
            'income_source_ids' => array_map(fn (IncomeSource $s) => $s->id, $sources),
            'expected_revisions' => collect($sources)->mapWithKeys(fn (IncomeSource $s) => [$s->id => $s->fresh()->revision])->all(),
        ]);
    }

    private function assertNoLeak(string $body, IncomeSource $source): void
    {
        $this->assertStringNotContainsString('No query results', $body);
        $this->assertStringNotContainsString('App\\Models', $body);
        $this->assertStringNotContainsString('App\\\\Models', $body);
        $this->assertStringNotContainsString('] '.$source->id, $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }

    private function reportOnlyDelete(User $ci, ClientFolder $folder, IncomeSource $source, int $revision)
    {
        return $this->actingAs($ci)->deleteJson(route('client-folders.income-sources.business-report.destroy', [$folder, $source]), ['expected_revision' => $revision]);
    }

    private function fullDelete(User $ci, ClientFolder $folder, IncomeSource $source, int $revision)
    {
        return $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $source]), ['expected_revision' => $revision]);
    }

    private function save(User $ci, ClientFolder $folder, IncomeSource $source, string $name, int $revision, ?CoMaker $coMaker = null)
    {
        return $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            $this->fields($name) + ['expected_revision' => $revision, 'co_maker_id' => $coMaker?->id],
        );
    }

    /** @return array{0: User, 1: User, 2: ClientFolder} */
    private function twoCis(): array
    {
        $ci1 = User::factory()->create();
        $ci2 = User::factory()->create();

        return [$ci1, $ci2, ClientFolder::factory()->create(['assigned_ci_id' => $ci1->id])];
    }

    private function savedBusiness(User $ci, ClientFolder $folder, string $name, string $templateType = 'retail_grocery_water_refilling', ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', $templateType)->firstOrFail();
        $source = app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ])->fresh();

        $this->save($ci, $folder, $source, $name, $source->revision, $coMaker)->assertSessionHasNoErrors();
        $this->assertGreaterThan(1, $source->fresh()->revision);

        return $source->fresh();
    }

    private function linkedCheck(User $ci, ClientFolder $folder, IncomeSource $source): BusinessCheck
    {
        return app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $source->co_maker_id, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Check Address',
        ]);
    }

    private function fields(string $name): array
    {
        return [
            'intent' => 'stay',
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
        ];
    }
}
