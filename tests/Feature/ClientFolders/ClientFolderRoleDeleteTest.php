<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveCoMaker;
use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Enums\ActivityStatus;
use App\Enums\GenerationStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\MediaReference;
use App\Models\ResidenceCheckPhoto;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderEditingPresence;
use App\Services\Media\ClientMediaUploader;
use App\Support\ClientFolders\MissingClientFolderResponse;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Role-aware permanent Client Folder delete, the Administrator's safe purge of a folder with saved
 * records (files included), and graceful handling for users still inside a folder another user has
 * deleted. Storage is always faked here, and the CI Team documents root points at a throwaway test
 * directory — never the real CI Team folder. Cloudinary is mocked; no live call is made.
 */
class ClientFolderRoleDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const HAS_SAVED_RECORDS = 'This Client Folder can no longer be deleted because it already contains saved records.';

    private string $documentsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->documentsRoot = storage_path('framework/testing/ci-team-'.uniqid());
        File::ensureDirectoryExists($this->documentsRoot);
        config(['cims.documents_root' => $this->documentsRoot, 'cims.media_disk' => 'local', 'cims.report_disk' => 'local']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->documentsRoot);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Role matrix

    public function test_every_role_can_delete_an_empty_folder(): void
    {
        foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create(), User::factory()->administrator()->create()] as $actor) {
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => User::factory()->create()->id]);

            $this->assertDeleted($actor, $folder);
        }
    }

    public function test_ci_and_senior_are_refused_on_a_non_empty_folder_while_an_administrator_purges_it(): void
    {
        $folder = ClientFolder::factory()->create();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Kept Until Admin']);

        foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create()] as $actor) {
            $this->actingAs($actor)->deleteJson(route('client-folders.destroy', $folder))->assertStatus(422)->assertExactJson(['message' => self::HAS_SAVED_RECORDS]);
        }
        $this->assertDatabaseHas('co_makers', ['id' => $coMaker->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted']);

        $admin = User::factory()->administrator()->create();
        $this->assertDeleted($admin, $folder);
        $this->assertDatabaseMissing('co_makers', ['id' => $coMaker->id]);
        $audit = AuditLog::query()->where('action', 'client_folder.permanently_deleted')->sole();
        $this->assertSame(['folder_id', 'folder_number', 'display_name', 'deleted_by', 'had_saved_records'], array_keys($audit->metadata));
        $this->assertSame([$folder->id, $folder->folder_number, $folder->display_name, $admin->id, true], array_values($audit->metadata));
    }

    public function test_administrator_purge_retires_folder_owned_files_after_commit_and_keeps_shared_ones(): void
    {
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create();
        $other = ClientFolder::factory()->create();
        $disk = Storage::disk('local');

        // Supporting Proof / media: one local file, one Cloudinary proof, one file also used by another folder.
        $disk->put('client-media/own.jpg', 'x');
        $disk->put('client-media/own-thumb.jpg', 'x');
        $disk->put('client-media/shared.jpg', 'x');
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/own.jpg', 'thumbnail_path' => 'client-media/own-thumb.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/shared.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $other->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/shared.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $admin->id, 'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY, 'cloudinary_public_id' => 'proofs/own', 'cloudinary_resource_type' => 'image']);

        // Residence Check photo (local + Cloudinary with its own delivery type).
        $check = app(SaveResidenceCheck::class)->execute($admin, $folder, ['check_id' => null, 'co_maker_id' => null, 'ci_date' => '2026-09-02', 'location' => 'Address']);
        $disk->put('client-media/residence.jpg', 'x');
        ResidenceCheckPhoto::query()->create(['residence_check_id' => $check->id, 'file_name' => 'r.jpg', 'path' => 'client-media/residence.jpg', 'uploaded_by' => $admin->id, 'cloud_public_id' => 'checks/residence', 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated']);

        // Generated report file.
        $disk->put('generated-reports/cibi.pdf', 'pdf');
        GeneratedReport::factory()->create(['client_folder_id' => $folder->id, 'generated_by' => $admin->id, 'status' => GenerationStatus::Completed, 'private_file_reference' => 'generated-reports/cibi.pdf']);

        $retired = [];
        $this->mock(ClientMediaUploader::class, function (MockInterface $mock) use (&$retired): void {
            $mock->shouldReceive('retireCloudAsset')->andReturnUsing(function (?string $publicId, ?string $type, ?string $delivery) use (&$retired): void {
                $this->assertNull(ClientFolder::query()->find(request()->route('clientFolder')?->id), 'Files are retired only after the delete committed.');
                $retired[] = [$publicId, $type, $delivery];
            });
        });

        $this->assertDeleted($admin, $folder);

        $disk->assertMissing(['client-media/own.jpg', 'client-media/own-thumb.jpg', 'client-media/residence.jpg', 'generated-reports/cibi.pdf']);
        $disk->assertExists('client-media/shared.jpg');
        $this->assertEqualsCanonicalizing([['proofs/own', 'image', 'upload'], ['checks/residence', 'image', 'authenticated']], $retired);
        $this->assertSame(0, MediaReference::withTrashed()->where('client_folder_id', $folder->id)->count());
        $this->assertSame(1, MediaReference::query()->where('client_folder_id', $other->id)->count());
        $this->assertSame(0, GeneratedReport::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_a_failed_administrator_purge_changes_nothing_and_writes_no_success_audit(): void
    {
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Survives Failure']);
        Storage::disk('local')->put('client-media/kept.jpg', 'x');
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/kept.jpg']);
        ClientFolder::deleting(fn () => throw new RuntimeException('SQLSTATE[23000]: simulated failure in App\\Models\\ClientFolder'));

        $this->actingAs($admin)->deleteJson(route('client-folders.destroy', $folder))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Unable to delete this Client Folder. Please try again.']);

        ClientFolder::flushEventListeners();
        $this->assertNotNull(ClientFolder::query()->find($folder->id));
        $this->assertDatabaseHas('co_makers', ['id' => $coMaker->id]);
        $this->assertSame(1, MediaReference::query()->where('client_folder_id', $folder->id)->count());
        Storage::disk('local')->assertExists('client-media/kept.jpg');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted']);
    }

    // ---------------------------------------------------------------- Smart presence, every role

    public function test_another_users_dirty_or_saving_work_blocks_every_role_including_administrator(): void
    {
        $editor = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $presence = app(ClientFolderEditingPresence::class);

        foreach (['dirty', 'saving'] as $state) {
            foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create(), User::factory()->administrator()->create()] as $actor) {
                $folder = ClientFolder::factory()->create();
                $presence->record($folder->id, 'ci_activity', 1, $editor, $state);

                $this->actingAs($actor)->deleteJson(route('client-folders.destroy', $folder))
                    ->assertStatus(422)
                    ->assertExactJson(['message' => 'This Client Folder cannot be deleted because Juan Dela Cruz currently has unsaved work in it. Please try again after they finish.']);
                $this->assertNotNull($folder->fresh());
            }
        }

        // Administrator on a NON-empty folder is blocked too, and nothing is removed.
        $folder = ClientFolder::factory()->create();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Busy Folder']);
        $presence->record($folder->id, 'ci_activity', 2, $editor, 'dirty');
        $this->actingAs(User::factory()->administrator()->create())->deleteJson(route('client-folders.destroy', $folder))->assertStatus(422);
        $this->assertDatabaseHas('co_makers', ['id' => $coMaker->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted']);

        // Viewing, a clean form, expired work and the deleter's own work never block.
        $admin = User::factory()->administrator()->create();
        $viewed = ClientFolder::factory()->create();
        $presence->record($viewed->id, 'ci_activity', 3, $editor, 'viewing');
        $presence->record($viewed->id, 'ci_activity', 4, $admin, 'dirty');
        $this->assertDeleted($admin, $viewed);

        $expired = ClientFolder::factory()->create();
        $presence->record($expired->id, 'ci_activity', 5, $editor, 'saving');
        $this->travel(91)->seconds();
        $this->assertDeleted($admin, $expired);
    }

    // ---------------------------------------------------------------- UI

    public function test_delete_dialog_depends_on_role_and_saved_records(): void
    {
        $folder = ClientFolder::factory()->create(['display_name' => 'SAVED RECORDS CLIENT']);
        CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Makes It Non Empty']);

        foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create()] as $actor) {
            $modal = $this->deleteModal($actor, $folder);
            $this->assertStringContainsString('Deletion Not Available', $modal);
            $this->assertStringContainsString('This Client Folder already contains saved records and can no longer be permanently deleted.', $modal);
            $this->assertStringNotContainsString('data-folder-delete-form', $modal);
            $this->assertStringNotContainsString('Delete Permanently', $modal);
        }

        $modal = $this->deleteModal(User::factory()->administrator()->create(), $folder);
        $this->assertStringContainsString('Delete Client Folder Permanently?', $modal);
        $this->assertStringContainsString('This Client Folder contains saved records. Deleting it will permanently remove the folder, its investigation records, and related data. This action cannot be undone.', $modal);
        $this->assertStringContainsString('data-folder-delete-form', $modal);
        $this->assertStringContainsString('class="ui-button-danger w-full', $modal);
        $this->assertStringContainsString('data-folder-delete-actions', $modal);
        $this->assertMatchesRegularExpression('/role="alert"[^>]*data-folder-delete-error hidden/', $modal);
        $this->assertStringNotContainsString('Deletion Not Available', $modal);
    }

    public function test_failed_delete_stays_inline_in_the_dialog_without_a_duplicate_toast(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $start = strpos($js, "const form = event.target.closest('[data-folder-create-form], [data-folder-rename-form], [data-folder-delete-form]');");
        $handler = substr($js, $start, strpos($js, "\n});\n", $start) - $start);

        $refused = substr($handler, strpos($handler, 'if (isDelete && !response.ok) {'), 300);
        $this->assertStringContainsString('setFolderDeleteError(form, response.status === 422 && payload.message ? payload.message : FOLDER_DELETE_FAILED_MESSAGE);', $refused);
        $this->assertStringContainsString('return;', $refused);
        $this->assertStringNotContainsString('showToast', $refused);
        $this->assertStringNotContainsString('close()', $refused, 'The dialog stays open.');
        $this->assertMatchesRegularExpression('/} finally \{\s*submit\?\.removeAttribute\(\'disabled\'\);/', $handler, 'Delete re-enables after a failure.');
        $this->assertStringContainsString("const FOLDER_DELETE_FAILED_MESSAGE = 'Unable to delete this Client Folder. Please try again.';", $js);
    }

    // ---------------------------------------------------------------- User still inside a deleted folder

    public function test_refreshing_a_page_of_a_folder_deleted_by_another_user_redirects_with_a_friendly_notice(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $pages = [
            route('client-folders.show', $folder),
            route('client-folders.client-information.edit', $folder),
            route('client-folders.activities.index', $folder),
            route('client-folders.cibi-report.edit', $folder),
        ];
        // CI is viewing / has a clean edit page open.
        $this->actingAs($ci)->get($pages[0])->assertOk();
        $this->assertDeleted(User::factory()->administrator()->create(), $folder);

        foreach ($pages as $page) {
            $this->actingAs($ci)->get($page)
                ->assertRedirect(route('client-folders.index'))
                ->assertSessionHas('status', MissingClientFolderResponse::VIEW_MESSAGE)
                ->assertSessionHas('statusType', 'error');
        }

        $this->actingAs($ci)->followingRedirects()->get($pages[1])
            ->assertOk()
            ->assertSee(MissingClientFolderResponse::VIEW_MESSAGE)
            ->assertSee('data-client-folder-missing', false)
            ->assertDontSee('No query results')
            ->assertDontSee('App\\Models\\ClientFolder', false);
    }

    public function test_ajax_request_for_a_deleted_folder_returns_clean_json(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->assertDeleted(User::factory()->administrator()->create(), $folder);

        $this->actingAs($ci)->getJson(route('client-folders.show', $folder))
            ->assertNotFound()
            ->assertJsonPath('folder_missing', true)
            ->assertJsonPath('message', MissingClientFolderResponse::JSON_VIEW_MESSAGE)
            ->assertDontSee('No query results')
            ->assertDontSee('App\\\\Models', false);
    }

    public function test_a_stale_html_save_against_a_deleted_folder_writes_nothing_and_redirects(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->assertDeleted(User::factory()->administrator()->create(), $folder);
        $audits = AuditLog::query()->count();

        $this->actingAs($ci)->from(route('client-folders.show', $folder->id))
            ->post(route('client-folders.co-maker.store', $folder), ['first_name' => 'Stale', 'last_name' => 'Edit'])
            ->assertRedirect(route('client-folders.index'))
            ->assertSessionHas('status', 'Unable to save changes. This Client Folder has already been permanently deleted by another user.');

        $this->assertDatabaseCount('co_makers', 0);
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertDatabaseMissing('client_folders', ['id' => $folder->id]);
    }

    public function test_a_stale_ajax_save_against_a_deleted_folder_writes_nothing_and_returns_clean_json(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->assertDeleted(User::factory()->administrator()->create(), $folder);
        $audits = AuditLog::query()->count();

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['first_name' => 'Stale', 'last_name' => 'Edit'])
            ->assertNotFound()
            ->assertJsonPath('folder_missing', true)
            ->assertJsonPath('message', MissingClientFolderResponse::SAVE_MESSAGE)
            ->assertDontSee('No query results');

        $this->assertDatabaseCount('co_makers', 0);
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_a_save_racing_the_delete_is_answered_as_a_deleted_folder_not_a_database_error(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        // The folder is permanently deleted (committed by another request) after this request's
        // route binding resolved it, just before its save action writes the child row.
        $this->app->resolving(SaveCoMaker::class, function () use ($folder): void {
            ClientFolder::query()->whereKey($folder->id)->forceDelete();
        });

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['first_name' => 'Racing', 'last_name' => 'Save'])
            ->assertNotFound()
            ->assertJsonPath('folder_missing', true)
            ->assertJsonPath('message', MissingClientFolderResponse::SAVE_MESSAGE)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('FOREIGN KEY');

        $this->assertDatabaseCount('co_makers', 0);
    }

    // ---------------------------------------------------------------- Regressions

    public function test_normal_saves_unrelated_404s_and_guests_are_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['first_name' => 'Real', 'last_name' => 'Save'])->assertOk();
        $this->assertDatabaseHas('co_makers', ['client_folder_id' => $folder->id, 'first_name' => 'Real']);

        // A missing child record under an EXISTING folder keeps its normal (non-redirect) 404.
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        $activity = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => 'Barangay Check', 'creator_id' => $ci->id, 'status' => ActivityStatus::Pending]);
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, 999999]))->assertNotFound();
        $this->actingAs($ci)->getJson('/definitely-not-a-route')->assertNotFound()->assertJsonMissingPath('folder_missing');

        // Guests are still sent to login, deleted folder or not.
        $deletedId = ClientFolder::factory()->create()->id;
        ClientFolder::query()->whereKey($deletedId)->forceDelete();
        auth()->logout();
        $this->get(route('client-folders.show', $deletedId))->assertRedirect(route('login'));
        $this->post(route('client-folders.co-maker.store', $deletedId), ['first_name' => 'A', 'last_name' => 'B'])->assertRedirect(route('login'));
    }

    public function test_stale_iframe_redirect_is_handed_to_the_parent_page_once(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $bridge = substr($js, strpos($js, "const marker = document.querySelector('[data-client-folder-missing]');"), 1500);

        $this->assertStringContainsString("window.parent.postMessage({ type: 'brbi:client-folder-missing'", $bridge);
        $this->assertStringContainsString('window.location.origin', $bridge);
        $this->assertStringContainsString("event.origin !== window.location.origin || event.data?.type !== 'brbi:client-folder-missing' || handled", $bridge);
        $this->assertStringContainsString("document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());", $bridge);
        $this->assertStringContainsString("browser.dispatchEvent(new CustomEvent('folder-browser:refresh'));", $bridge);
    }

    // ---------------------------------------------------------------- Helpers

    private function assertDeleted(User $actor, ClientFolder $folder): void
    {
        $this->actingAs($actor)->deleteJson(route('client-folders.destroy', $folder))
            ->assertOk()
            ->assertJsonPath('message', 'Client folder permanently deleted.');

        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'client_folder.permanently_deleted')->where('metadata->folder_id', $folder->id)->count());
    }

    private function deleteModal(User $user, ClientFolder $folder): string
    {
        $html = $this->actingAs($user)->get(route('client-folders.index', ['search' => $folder->display_name]))->assertOk()->getContent();
        $start = strpos($html, 'id="dashboard-delete-dialog-'.$folder->id.'"');
        $this->assertNotFalse($start, 'Delete dialog not rendered for folder '.$folder->id);

        return substr($html, $start, strpos($html, '</dialog>', $start) + 9 - $start);
    }
}
