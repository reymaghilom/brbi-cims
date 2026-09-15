<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateClientFolder;
use App\Actions\ClientFolders\RemoveCoMaker;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Actions\ClientFolders\SaveCoMaker;
use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderEditingPresence;
use Closure;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only a truly empty / new Client Folder may be permanently deleted, by any role. Folder creation
 * no longer seeds Barangay / Neighbor Checks, so a new folder holds only its own row and its
 * creation history. Any saved operational record — or operational history — makes the folder
 * non-deletable: the dialog says so, and the DELETE endpoint refuses independently.
 */
class ClientFolderDeleteWarningTest extends TestCase
{
    use RefreshDatabase;

    private const HAS_SAVED_RECORDS = 'This Client Folder can no longer be deleted because it already contains saved records.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        // Administrator purges run here: never let file cleanup resolve the real CI Team folder.
        config(['cims.documents_root' => storage_path('framework/testing/ci-team-warning')]);
        Storage::fake('local');
    }

    public function test_brand_new_folder_with_only_identity_metadata_and_creation_history_is_deletable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);

        $this->assertSame(0, $folder->activities()->count(), 'Barangay / Neighbor Checks are not auto-generated.');
        $this->assertTrue(AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'client_folder.created')->exists());

        $modal = $this->deleteModal($ci, $folder);
        $this->assertStringContainsString('Delete Client Folder Permanently?', $modal);
        $this->assertStringContainsString('This Client Folder does not contain any saved records yet. Deleting it will permanently remove the folder. This action cannot be undone.', $modal);
        $this->assertStringContainsString('data-folder-delete-form', $modal);
        $this->assertMatchesRegularExpression('/Cancel\s*<\/button>/', $modal);
        $this->assertMatchesRegularExpression('/Delete Permanently\s*<\/button>/', $modal);
        $this->assertStringNotContainsString('data-folder-delete-unavailable', $modal);

        $this->assertDeletes($ci, $folder);
    }

    public function test_renaming_the_folder_identity_does_not_make_it_non_empty(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);

        $this->actingAs($ci)->patchJson(route('client-folders.update-name', $folder), ['last_name' => 'Renamed', 'first_name' => 'Client'])->assertOk();

        $this->assertDeletes($ci, $folder);
    }

    /**
     * @return array<string, array{0: Closure(ClientFolder, User): void}>
     */
    public static function savedRecords(): array
    {
        return [
            'CI/BI Report' => [fn (ClientFolder $folder, User $ci) => CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $ci->id])],
            'Business Report / Income Source' => [fn (ClientFolder $folder) => IncomeSource::factory()->create([
                'client_folder_id' => $folder->id,
                'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
            ])],
            'Residence Check' => [fn (ClientFolder $folder, User $ci) => app(SaveResidenceCheck::class)->execute($ci, $folder, self::checkData())],
            'Business Check' => [fn (ClientFolder $folder, User $ci) => app(SaveBusinessCheck::class)->execute($ci, $folder, self::checkData() + ['income_source_id' => null])],
            'Co-Maker' => [fn (ClientFolder $folder, User $ci) => app(SaveCoMaker::class)->execute($ci, $folder, ['first_name' => 'Maria', 'last_name' => 'Santos'])],
            'manually added CI Activity' => [fn (ClientFolder $folder, User $ci) => self::activityFor($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE)],
            'Neighbor Check' => [fn (ClientFolder $folder, User $ci) => self::activityFor($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE)],
            'Bank/Coop target' => [function (ClientFolder $folder, User $ci): void {
                self::activityFor($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE)->bankTargets()->create([
                    'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => 'BDO',
                    'status' => ActivityStatus::Pending, 'scheduled_has_time' => false, 'created_by' => $ci->id, 'updated_by' => $ci->id,
                ]);
            }],
            'Asset target' => [function (ClientFolder $folder, User $ci): void {
                self::activityFor($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE)->assetTargets()->create([
                    'assessor_type' => 'city_assessor', 'office_location' => 'Land',
                    'status' => ActivityStatus::Pending, 'scheduled_has_time' => false, 'created_by' => $ci->id, 'updated_by' => $ci->id,
                ]);
            }],
            'Supporting Proof / media' => [fn (ClientFolder $folder, User $ci) => MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'uploaded_by' => $ci->id])],
            'generated report' => [fn (ClientFolder $folder, User $ci) => GeneratedReport::factory()->create(['client_folder_id' => $folder->id, 'generated_by' => $ci->id])],
        ];
    }

    #[DataProvider('savedRecords')]
    public function test_any_saved_operational_record_makes_the_folder_non_deletable_and_leaves_everything_intact(Closure $save): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $kept = $this->newFolder($ci, 'Kept');
        $save($folder, $ci);
        // Prove the record alone blocks (not only the history it may have written).
        AuditLog::query()->where('client_folder_id', $folder->id)->whereNotIn('action', ['client_folder.created', 'client_folder.renamed'])->delete();
        $snapshot = $this->snapshot($folder);
        $auditCount = AuditLog::query()->count();

        $modal = $this->deleteModal($ci, $folder);
        $this->assertStringContainsString('Deletion Not Available', $modal);
        $this->assertStringContainsString('This Client Folder already contains saved records and can no longer be permanently deleted.', $modal);
        $this->assertStringNotContainsString('data-folder-delete-form', $modal);
        $this->assertStringNotContainsString('file or external-integration', $modal);

        // Credit Investigator and Senior CI — including forged direct DELETEs — are refused.
        foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create()] as $actor) {
            $this->actingAs($actor)->deleteJson(route('client-folders.destroy', $folder))
                ->assertStatus(422)
                ->assertExactJson(['message' => self::HAS_SAVED_RECORDS]);
            $this->actingAs($actor)->from(route('client-folders.index'))->delete(route('client-folders.destroy', $folder))
                ->assertSessionHasErrors(['confirmation' => self::HAS_SAVED_RECORDS]);
        }

        $this->assertSame($snapshot, $this->snapshot($folder), 'A refused delete changes nothing.');
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted']);

        // The empty folder next to it stays deletable; an Administrator may delete the non-empty one.
        $this->assertDeletes($ci, $kept);
        $this->assertDeletes(User::factory()->administrator()->create(), $folder);
        $this->assertSame(0, array_sum(array_diff_key($this->snapshot($folder), ['bank_targets' => 0, 'asset_targets' => 0])), 'The whole folder graph is removed.');
    }

    public function test_a_folder_stays_non_deletable_after_its_saved_work_is_removed_again(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);

        // A Co-Maker is added and then removed again through the real actions: no child row
        // remains, only the operational history that it happened.
        $coMaker = app(SaveCoMaker::class)->execute($ci, $folder, ['first_name' => 'Removed', 'last_name' => 'Later']);
        app(RemoveCoMaker::class)->execute($ci, $folder, $coMaker);
        $this->assertSame(0, $folder->coMakers()->count());
        $this->assertSame(0, CiActivity::withTrashed()->where('client_folder_id', $folder->id)->count());

        $this->assertStringContainsString('Deletion Not Available', $this->deleteModal($ci, $folder));
        $this->actingAs($ci)->deleteJson(route('client-folders.destroy', $folder))->assertStatus(422)->assertJsonPath('message', self::HAS_SAVED_RECORDS);
        $this->assertNotNull($folder->fresh());
    }

    public function test_a_soft_deleted_activity_still_counts_as_saved_records(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        self::activityFor($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE)->delete();

        $this->actingAs($ci)->deleteJson(route('client-folders.destroy', $folder))->assertStatus(422);
        $this->assertNotNull($folder->fresh());
    }

    public function test_a_record_saved_after_the_dialog_rendered_as_empty_is_refused_at_delete_time(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $this->assertStringContainsString('data-folder-delete-form', $this->deleteModal($ci, $folder));

        // Another CI saves a record between page load and the delete click.
        $coMaker = app(SaveCoMaker::class)->execute(User::factory()->create(), $folder, ['first_name' => 'Late', 'last_name' => 'Save']);

        $this->actingAs($ci)->deleteJson(route('client-folders.destroy', $folder))->assertStatus(422)->assertJsonPath('message', self::HAS_SAVED_RECORDS);
        $this->assertNotNull($folder->fresh());
        $this->assertDatabaseHas('co_makers', ['id' => $coMaker->id, 'client_folder_id' => $folder->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted']);
    }

    public function test_presence_rules_still_apply_to_an_empty_folder(): void
    {
        $ci1 = User::factory()->create();
        $ci2 = User::factory()->create();
        $presence = app(ClientFolderEditingPresence::class);

        $viewed = $this->newFolder($ci1, 'Viewed');
        $presence->record($viewed->id, 'cibi_report', 1, $ci1, 'viewing');
        $this->assertDeletes($ci2, $viewed);

        $dirty = $this->newFolder($ci1, 'Dirty');
        $presence->record($dirty->id, 'cibi_report', 2, $ci1, 'dirty');
        $this->actingAs($ci2)->deleteJson(route('client-folders.destroy', $dirty))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This Client Folder cannot be deleted because '.$ci1->full_name.' currently has unsaved work in it. Please try again after they finish.');
        $this->assertNotNull($dirty->fresh());
    }

    public function test_the_dialogs_have_an_inline_error_region_and_equal_width_mobile_actions(): void
    {
        $ci = User::factory()->create();
        $empty = $this->newFolder($ci, 'Empty');
        $filled = $this->newFolder($ci, 'Filled');
        self::activityFor($filled, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $modal = $this->deleteModal($ci, $empty);
        $this->assertMatchesRegularExpression('/<p [^>]*role="alert"[^>]*data-folder-delete-error hidden><\/p>/', $modal);
        $this->assertStringContainsString('<div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:justify-end" data-folder-delete-actions>', $modal);
        $this->assertMatchesRegularExpression('/<button type="button" data-modal-close class="ui-button-secondary w-full [^"]*sm:w-auto[^"]*">/', $modal);
        $this->assertMatchesRegularExpression('/<form [^>]*class="flex w-full sm:w-auto"[^>]*data-folder-delete-form/', $modal);
        $this->assertMatchesRegularExpression('/<button class="ui-button-danger w-full [^"]*sm:w-auto[^"]*">/', $modal);
        // Same padding/wrapping on both, so the two columns stay equal width and height on phones.
        preg_match('/class="ui-button-secondary( [^"]*)">(?:(?!<\/button>).)*Cancel/s', $modal, $cancel);
        preg_match('/class="ui-button-danger( [^"]*)">(?:(?!<\/button>).)*Delete Permanently/s', $modal, $delete);
        $this->assertSame($cancel[1] ?? null, $delete[1] ?? 'missing');
        $this->assertStringNotContainsString(' whitespace-nowrap', str_replace('sm:whitespace-nowrap', '', $delete[1] ?? ''), 'Text may wrap on narrow phones.');

        $unavailable = $this->deleteModal($ci, $filled);
        $this->assertMatchesRegularExpression('/Close\s*<\/button>/', $unavailable);
        $this->assertStringNotContainsString('ui-button-danger', $unavailable);
    }

    public function test_delete_ui_shows_refusals_inline_and_never_surfaces_raw_not_found_text(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $folderId = $folder->id;
        $this->assertDeletes($ci, $folder);

        // A stale second delete gets clean JSON, never the model class...
        $this->actingAs($ci)->deleteJson(route('client-folders.destroy', $folderId))
            ->assertNotFound()
            ->assertJsonPath('folder_missing', true)
            ->assertJsonPath('message', 'This Client Folder is no longer available.')
            ->assertDontSee('No query results')
            ->assertDontSee('App\\Models', false);

        // ...and the Client Folder UI never shows response text for a 404 or a failed parse anyway.
        $js = file_get_contents(resource_path('js/app.js'));
        $start = strpos($js, "const form = event.target.closest('[data-folder-create-form], [data-folder-rename-form], [data-folder-delete-form]');");
        $handler = substr($js, $start, strpos($js, 'const contextMenuClosing', $start) - $start);

        $this->assertStringContainsString('const payload = await response.json().catch(() => ({}));', $handler);
        $notFound = substr($handler, strpos($handler, 'if (response.status === 404) {'), 700);
        $this->assertStringContainsString("showToast(FOLDER_UNAVAILABLE_MESSAGE, 'error');", $notFound);
        $this->assertStringNotContainsString('payload.message', $notFound);
        $this->assertLessThan(strpos($handler, 'throw new Error(payload.message'), strpos($handler, 'if (response.status === 404)'));
        $this->assertStringContainsString('setFolderDeleteError(form, response.status === 422 && payload.message ? payload.message : FOLDER_DELETE_FAILED_MESSAGE);', $handler);
        $this->assertMatchesRegularExpression('/if \(isDelete\) \{\s*setFolderDeleteError\(form, FOLDER_DELETE_FAILED_MESSAGE\);/', $handler);
        $this->assertStringContainsString("const error = form?.closest('dialog')?.querySelector('[data-folder-delete-error]');", $js);
        $this->assertStringNotContainsString('No query results', $js);
    }

    // ---------------------------------------------------------------- Helpers

    private function assertDeletes(User $actor, ClientFolder $folder): void
    {
        $this->actingAs($actor)->deleteJson(route('client-folders.destroy', $folder))
            ->assertOk()
            ->assertJsonPath('message', 'Client folder permanently deleted.');

        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'client_folder.permanently_deleted')->where('metadata->folder_id', $folder->id)->count());
    }

    /** @return array<string, int> */
    private function snapshot(ClientFolder $folder): array
    {
        $tables = ['client_folders' => 'id', 'co_makers' => 'client_folder_id', 'cibi_reports' => 'client_folder_id', 'income_sources' => 'client_folder_id',
            'residence_checks' => 'client_folder_id', 'business_checks' => 'client_folder_id', 'ci_activities' => 'client_folder_id',
            'media_references' => 'client_folder_id', 'generated_reports' => 'client_folder_id'];

        return collect($tables)->map(fn (string $column, string $table): int => DB::table($table)->where($column, $folder->id)->count())->all()
            + ['bank_targets' => DB::table('ci_activity_bank_targets')->count(), 'asset_targets' => DB::table('ci_activity_asset_targets')->count()];
    }

    private static function activityFor(ClientFolder $folder, User $creator, string $code): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => ActivityStatus::Pending, 'creator_id' => $creator->id,
        ]);
    }

    private static function checkData(): array
    {
        return ['check_id' => null, 'co_maker_id' => null, 'ci_date' => '2026-09-02', 'location' => 'Check Address'];
    }

    private function newFolder(User $actor, string $prefix = 'Warning'): ClientFolder
    {
        return app(CreateClientFolder::class)->execute($actor, [
            'last_name' => $prefix.' Applicant',
            'first_name' => 'Test',
            'middle_name' => null,
            'suffix' => null,
            'assigned_ci_id' => $actor->id,
        ]);
    }

    private function deleteModal(User $user, ClientFolder $folder): string
    {
        $html = $this->actingAs($user)->get(route('client-folders.index', ['search' => $folder->display_name]))->assertOk()->getContent();
        $start = strpos($html, 'id="dashboard-delete-dialog-'.$folder->id.'"');
        $this->assertNotFalse($start, 'Delete dialog not rendered for folder '.$folder->id);
        $end = strpos($html, '</dialog>', $start);

        return substr($html, $start, $end + 9 - $start);
    }
}
