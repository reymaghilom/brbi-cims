<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Co-Maker edit uses optimistic concurrency (revision) — never last-write-wins — and stays safe
 * against a concurrent delete: the exact Co-Maker's viewing / dirty / saving presence governs who
 * may delete it, and a save after a delete never recreates or misdirects anything.
 */
class CoMakerEditConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const STALE = 'Your changes were not saved because this Co-Maker was updated by another user. Please reload the latest information before trying again.';

    private const WORKING = 'This Co-Maker cannot be deleted because %s is currently working on it. Please try again after they finish.';

    private string $documentsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->documentsRoot = storage_path('framework/testing/ci-team-co-maker-edit-'.uniqid());
        File::ensureDirectoryExists($this->documentsRoot);
        config(['cims.documents_root' => $this->documentsRoot, 'cims.media_disk' => 'local', 'cims.report_disk' => 'local']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->documentsRoot);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Revision

    public function test_the_edit_trigger_and_form_carry_the_current_revision_and_creation_needs_none(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['first_name' => 'New', 'last_name' => 'Maker'])
            ->assertOk()->assertJsonPath('coMaker.revision', 1);
        $coMaker = CoMaker::query()->where('client_folder_id', $folder->id)->sole();
        $this->assertSame(1, $coMaker->revision);

        $html = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-co-maker-id="'.$coMaker->id.'"(?:(?!<\/button>).)*data-co-maker-revision="1"/s', $html);
        $this->assertStringContainsString('name="expected_revision" value="" data-co-maker-revision-field', $html);

        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('editBtn.dataset.coMakerRevision = coMakerRevision ??', $js);
        $this->assertStringContainsString("if (revisionField) revisionField.value = coMakerRevision ?? '';", $js);
    }

    public function test_two_users_on_the_same_co_maker_the_second_stale_save_cannot_overwrite_the_first(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->seniorCreditInvestigator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $userA->id, 'display_name' => 'APPLICANT NAME']);
        $coMaker = $this->coMaker($folder, 'Juan', 'Dela Cruz', 'Old Address');
        $opened = $coMaker->revision; // both users load the same revision

        $this->actingAs($userA)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'expected_revision' => $opened, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Address By A',
        ])->assertOk()->assertJsonPath('coMaker.revision', $opened + 1);
        $auditsAfterA = AuditLog::query()->where('action', 'co_maker.updated')->count();

        $this->actingAs($userB)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'expected_revision' => $opened, 'first_name' => 'Juan', 'last_name' => 'Last Name By B',
        ])->assertStatus(409)->assertExactJson(['result' => 'conflict', 'message' => self::STALE, 'status_type' => 'error']);

        $fresh = $coMaker->fresh();
        $this->assertSame('Address By A', $fresh->address);
        $this->assertSame('Dela Cruz', $fresh->last_name, 'B\'s change is not written, not even partially.');
        $this->assertSame($opened + 1, $fresh->revision);
        $this->assertSame($userA->id, $fresh->last_edited_by, 'The winning user stays the last editor.');
        $this->assertSame(1, $auditsAfterA);
        $this->assertSame($auditsAfterA, AuditLog::query()->where('action', 'co_maker.updated')->count(), 'No success audit for the stale save.');
        $this->assertSame('APPLICANT NAME', $folder->fresh()->display_name);

        // A plain form submit gets the same refusal, as a notice.
        $this->actingAs($userB)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'expected_revision' => $opened, 'first_name' => 'Juan', 'last_name' => 'Last Name By B',
        ])->assertRedirect(route('client-folders.show', $folder))->assertSessionHas('status', self::STALE);
        $this->assertSame('Dela Cruz', $coMaker->fresh()->last_name);

        // Reloading the latest revision lets B save.
        $this->actingAs($userB)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'expected_revision' => $opened + 1, 'first_name' => 'Juan', 'last_name' => 'Last Name By B', 'address' => 'Address By A',
        ])->assertOk();
        $this->assertSame(['Last Name By B', 'Address By A', $opened + 2, $userB->id], [$coMaker->fresh()->last_name, $coMaker->fresh()->address, $coMaker->fresh()->revision, $coMaker->fresh()->last_edited_by]);
    }

    public function test_an_edit_without_a_revision_is_rejected_and_a_validation_failure_writes_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $this->coMaker($folder, 'Juan', 'Dela Cruz');

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Changed'])
            ->assertStatus(422)->assertJsonValidationErrors('expected_revision');
        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMaker->id, 'expected_revision' => 1, 'first_name' => '', 'last_name' => 'Changed'])
            ->assertStatus(422)->assertJsonValidationErrors('first_name');

        $this->assertSame(['Dela Cruz', 1], [$coMaker->fresh()->last_name, $coMaker->fresh()->revision]);
        $this->assertSame(0, AuditLog::query()->where('action', 'co_maker.updated')->count());
    }

    public function test_revisions_are_independent_per_co_maker_same_name_and_folder(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $this->coMaker($folder, 'Same', 'Name');
        $coMakerB = $this->coMaker($folder, 'Same', 'Name');
        $elsewhere = $this->coMaker($otherFolder, 'Same', 'Name');

        // A and B are edited by different users from the same starting revision: both succeed.
        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMakerA->id, 'expected_revision' => 1, 'first_name' => 'Same', 'last_name' => 'Name A'])->assertOk();
        $this->actingAs(User::factory()->create())->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMakerB->id, 'expected_revision' => 1, 'first_name' => 'Same', 'last_name' => 'Name B'])->assertOk();

        $this->assertSame([2, 'Name A'], [$coMakerA->fresh()->revision, $coMakerA->fresh()->last_name]);
        $this->assertSame([2, 'Name B'], [$coMakerB->fresh()->revision, $coMakerB->fresh()->last_name]);
        $this->assertSame([1, 'Name'], [$elsewhere->fresh()->revision, $elsewhere->fresh()->last_name]);

        // A Co-Maker id from another folder is never edited through this folder.
        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $elsewhere->id, 'expected_revision' => 1, 'first_name' => 'Same', 'last_name' => 'Hijacked'])
            ->assertStatus(404);
        $this->assertSame('Name', $elsewhere->fresh()->last_name);
    }

    // ---------------------------------------------------------------- Edit presence vs delete

    public function test_edit_dialog_presence_governs_deleting_only_that_exact_co_maker(): void
    {
        $editor = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $editor->id]);
        [$viewed, $clean, $dirtyA, $b, $saving, $expired, $own] = collect(range(1, 7))->map(fn (int $i) => $this->coMaker($folder, 'Maker', 'No'.$i))->all();

        $this->heartbeat($editor, $viewed, null)->assertOk();
        $this->heartbeat($editor, $clean, 'viewing')->assertOk();
        $this->heartbeat($editor, $dirtyA, 'dirty')->assertOk();
        $this->heartbeat($editor, $saving, 'saving')->assertOk();
        $this->heartbeat($editor, $expired, 'dirty')->assertOk();
        $this->heartbeat($admin, $own, 'dirty')->assertOk();

        // Dirty and saving block their own Co-Maker, for every role, with the simple wording.
        foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create(), $admin] as $actor) {
            foreach ([$dirtyA, $saving] as $busy) {
                $this->actingAs($actor)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $busy]))
                    ->assertStatus(422)->assertExactJson(['message' => sprintf(self::WORKING, 'Juan Dela Cruz')]);
            }
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'co_maker.removed']);

        // Editing A never blocks B; viewing, a clean form and the deleter's own work never block.
        $this->assertRemoved($admin, $folder, $b);
        $this->assertRemoved($admin, $folder, $viewed);
        $this->assertRemoved($admin, $folder, $clean);
        $this->assertRemoved($admin, $folder, $own);

        // Expired presence stops blocking; the released dialog stops blocking immediately.
        $this->travel(91)->seconds();
        $this->assertRemoved($admin, $folder, $expired);
        $this->heartbeat($editor, $dirtyA, 'dirty')->assertOk();
        $this->actingAs($editor)->postJson(route('editing-presence.release'), ['type' => 'co_maker', 'id' => $dirtyA->id])->assertOk();
        $this->assertRemoved($admin, $folder, $dirtyA);
    }

    public function test_the_edit_dialog_reports_viewing_dirty_and_saving_and_keeps_a_refused_save_dirty(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $driver = substr($js, strpos($js, "const form = document.querySelector('[data-co-maker-form]');\n    const dialog = form?.closest('dialog');"), 2600);

        $this->assertStringContainsString("if (saving) return 'saving';", $driver);
        $this->assertStringContainsString("return snapshot() !== baseline ? 'dirty' : 'viewing';", $driver);
        $this->assertStringContainsString("post('/editing-presence/heartbeat', { type: 'co_maker', id: coMakerId, state: lastState });", $driver);
        $this->assertStringContainsString("dialog.addEventListener('close', release);", $driver);
        $this->assertStringContainsString('baseline = snapshot();', $driver, 'Opening the dialog is clean, not dirty.');

        $submitStart = strpos($js, "const form = event.target.closest('[data-co-maker-form]');");
        $submit = substr($js, $submitStart, strpos($js, "\n});\n", $submitStart) - $submitStart);
        $this->assertLessThan(strpos($submit, 'await fetch(submitUrl'), strpos($submit, 'form.coMakerPresence?.markSaving(true);'));
        $this->assertMatchesRegularExpression('/} finally \{(?:(?!\n\s*}\n).)*submit\?\.removeAttribute\(\'disabled\'\);(?:(?!\n\s*}\n).)*form\.coMakerPresence\?\.markSaving\(false\);/s', $submit);
        $this->assertStringContainsString('const payload = await response.json().catch(() => ({}));', $submit);
        $this->assertStringContainsString('payload.co_maker_missing', $submit);
    }

    public function test_refused_edit_saves_are_explained_inside_the_edit_dialog_not_only_by_a_toast(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->coMaker($folder, 'Juan', 'Dela Cruz');

        // The dialog has a dedicated alert region above the fields, hidden until needed.
        $html = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $form = substr($html, strpos($html, 'id="co-maker-form"'));
        $form = substr($form, 0, strpos($form, '</form>'));
        $this->assertMatchesRegularExpression('/<p [^>]*role="alert" aria-live="assertive" data-co-maker-form-error hidden><\/p>/', $form);
        $this->assertLessThan(strpos($form, 'name="last_name"'), strpos($form, 'data-co-maker-form-error'), 'The alert sits before the fields.');

        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("const CO_MAKER_DELETED_SAVE_MESSAGE = 'Your changes were not saved because this Co-Maker has already been deleted.';", $js);
        $this->assertStringContainsString("const CO_MAKER_CONFLICT_SAVE_MESSAGE = '".self::STALE."';", $js);

        $submitStart = strpos($js, "const form = event.target.closest('[data-co-maker-form]');");
        $submit = substr($js, $submitStart, strpos($js, "\n});\n", $submitStart) - $submitStart);
        $block = function (string $opening) use ($submit): string {
            $start = strpos($submit, $opening);
            $this->assertNotFalse($start, $opening);

            return substr($submit, $start, strpos($submit, 'return;', $start) + 7 - $start);
        };

        // Deleted: inline message, dialog stays open, the stale form can no longer be sent.
        $deleted = $block('if (response.status === 404 && (payload.co_maker_missing || payload.folder_missing)) {');
        $this->assertStringContainsString('setCoMakerFormError(form, payload.message || CO_MAKER_DELETED_SAVE_MESSAGE);', $deleted);
        $this->assertStringContainsString("form.dataset.coMakerSaveBlocked = 'true';", $deleted);
        $this->assertStringNotContainsString('close()', $deleted);
        $this->assertStringNotContainsString('showToast', $deleted);
        $this->assertStringContainsString("if (form.dataset.coMakerSaveBlocked === 'true') return;", $submit);
        $this->assertStringContainsString("if (form.dataset.coMakerSaveBlocked !== 'true') submit?.removeAttribute('disabled');", $submit);

        // Updated by another user: inline message, dialog and typed values stay, no refresh-and-retry.
        $conflict = $block("if (response.status === 409 && payload.result === 'conflict') {");
        $this->assertStringContainsString('setCoMakerFormError(form, payload.message || CO_MAKER_CONFLICT_SAVE_MESSAGE);', $conflict);
        $this->assertStringNotContainsString('close()', $conflict);
        $this->assertStringNotContainsString('showToast', $conflict);
        $this->assertStringNotContainsString('revisionField', $conflict);
        $this->assertStringNotContainsString('requestSubmit', $conflict);
        // The conflict is handled before the duplicate advisory, so a stale confirmed save never reopens it.
        $this->assertLessThan(strpos($submit, 'payload.duplicate_warning'), strpos($submit, "payload.result === 'conflict'"));
        // Ordinary field validation is unchanged.
        $this->assertStringContainsString('if (response.status === 422) {', $submit);
        $this->assertStringContainsString('form.querySelector(`[data-co-maker-error-for="${fieldName}"]`)', $submit);

        // Opening a fresh Add or Edit dialog clears the old alert and re-enables saving.
        $opener = substr($js, strpos($js, "const addTrigger = event.target.closest('[data-co-maker-add-trigger]');"), 1500);
        $this->assertStringContainsString('setCoMakerFormError(form, null);', $opener);
        $this->assertStringContainsString('delete form.dataset.coMakerSaveBlocked;', $opener);
    }

    public function test_the_edit_dialog_messages_match_the_server_for_conflict_and_deleted_saves(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'APPLICANT NAME']);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $this->coMaker($folder, 'Same', 'Name');
        $coMakerB = $this->coMaker($folder, 'Other', 'Maker');
        $elsewhere = $this->coMaker($otherFolder, 'Same', 'Name');

        // Conflict (also with the duplicate advisory confirmed): nothing written.
        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMakerA->id, 'expected_revision' => 1, 'first_name' => 'Same', 'last_name' => 'Name', 'address' => 'First save'])->assertOk();
        $this->actingAs(User::factory()->create())->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMakerA->id, 'expected_revision' => 1, 'first_name' => 'Other', 'last_name' => 'Maker', 'duplicate_confirmed' => '1',
        ])->assertStatus(409)->assertJsonPath('result', 'conflict')->assertJsonPath('message', self::STALE)->assertJsonMissingPath('duplicate_warning');
        $this->assertSame(['Same', 'Name', 'First save', 2], [$coMakerA->fresh()->first_name, $coMakerA->fresh()->last_name, $coMakerA->fresh()->address, $coMakerA->fresh()->revision]);

        // Deleted: nothing written anywhere, the record is not recreated.
        $this->actingAs(User::factory()->administrator()->create())->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMakerA]))->assertOk();
        $audits = AuditLog::query()->count();
        foreach ([1, 2] as $attempt) {
            $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), [
                'co_maker_id' => $coMakerA->id, 'expected_revision' => 2, 'first_name' => 'Retry '.$attempt, 'last_name' => 'Name',
            ])->assertNotFound()->assertJsonPath('co_maker_missing', true)
                ->assertJsonPath('message', 'Your changes were not saved because this Co-Maker has already been deleted.');
        }

        $this->assertNull(CoMaker::query()->find($coMakerA->id));
        $this->assertSame([$coMakerB->id], CoMaker::query()->where('client_folder_id', $folder->id)->pluck('id')->all());
        $this->assertSame(['Other', 'Maker', 1], [$coMakerB->fresh()->first_name, $coMakerB->fresh()->last_name, $coMakerB->fresh()->revision]);
        $this->assertSame(['Same', 'Name', 1], [$elsewhere->fresh()->first_name, $elsewhere->fresh()->last_name, $elsewhere->fresh()->revision]);
        $this->assertSame('APPLICANT NAME', $folder->fresh()->display_name);
        $this->assertSame($audits, AuditLog::query()->count(), 'No success audit for a deleted-record save.');
    }

    // ---------------------------------------------------------------- Delete vs save races

    public function test_delete_wins_a_stale_edit_save_is_refused_and_writes_nothing_anywhere(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'APPLICANT NAME']);
        $coMakerA = $this->coMaker($folder, 'Gone', 'Maker');
        $coMakerB = $this->coMaker($folder, 'Kept', 'Maker');
        $opened = $coMakerA->revision;
        $this->assertRemoved(User::factory()->administrator()->create(), $folder, $coMakerA);
        $audits = AuditLog::query()->count();

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMakerA->id, 'expected_revision' => $opened, 'first_name' => 'Stale', 'last_name' => 'Edit',
        ])->assertNotFound()->assertJsonPath('co_maker_missing', true)
            ->assertJsonPath('message', 'Your changes were not saved because this Co-Maker has already been deleted.');
        $this->actingAs($ci)->from(route('client-folders.show', $folder))->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMakerA->id, 'expected_revision' => $opened, 'first_name' => 'Stale', 'last_name' => 'Edit',
        ])->assertRedirect(route('client-folders.show', $folder))
            ->assertSessionHas('status', 'Your changes were not saved because this Co-Maker has already been deleted.');

        $this->assertNull(CoMaker::query()->find($coMakerA->id));
        $this->assertSame([$coMakerB->id], CoMaker::query()->where('client_folder_id', $folder->id)->pluck('id')->all());
        $this->assertSame(['Kept', 1], [$coMakerB->fresh()->first_name, $coMakerB->fresh()->revision]);
        $this->assertSame('APPLICANT NAME', $folder->fresh()->display_name);
        $this->assertSame(0, CiActivity::query()->where('client_folder_id', $folder->id)->count());
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_save_wins_delete_rechecks_the_server_state_not_the_old_page(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $this->coMaker($folder, 'Was', 'Empty');

        // The CI's page rendered this Co-Maker as empty...
        $html = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-co-maker-id="'.$coMaker->id.'"(?:(?!<\/button>).)*data-co-maker-has-saved-records="0"/s', $html);

        // ...then another user saves investigation work for it.
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id, 'status' => ActivityStatus::Pending]);

        $this->actingAs($ci)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker]))
            ->assertStatus(422)->assertExactJson(['message' => 'This Co-Maker already has saved records. Only a Senior CI or Administrator can delete it.']);
        $this->assertNotNull($coMaker->fresh());

        // Current role matrix: Senior CI and Administrator may still delete a non-empty Co-Maker...
        $second = $this->coMaker($folder, 'Second', 'Busy');
        CiActivity::create(['client_folder_id' => $folder->id, 'co_maker_id' => $second->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'creator_id' => $ci->id, 'status' => ActivityStatus::Pending]);
        $this->assertRemoved(User::factory()->seniorCreditInvestigator()->create(), $folder, $coMaker);
        $this->assertRemoved(User::factory()->administrator()->create(), $folder, $second);

        // ...while a Senior CI still cannot delete a non-empty Client Folder.
        $this->actingAs(User::factory()->seniorCreditInvestigator()->create())->deleteJson(route('client-folders.destroy', $folder))
            ->assertStatus(422)->assertExactJson(['message' => 'This Client Folder can no longer be deleted because it already contains saved records.']);
        $this->assertNotNull($folder->fresh());
    }

    // ---------------------------------------------------------------- Helpers

    private function coMaker(ClientFolder $folder, string $first, string $last, ?string $address = null): CoMaker
    {
        return CoMaker::create(['client_folder_id' => $folder->id, 'first_name' => $first, 'last_name' => $last, 'full_name' => $first.' '.$last, 'address' => $address])->fresh();
    }

    private function heartbeat(User $user, CoMaker $coMaker, ?string $state)
    {
        return $this->actingAs($user)->postJson(route('editing-presence.heartbeat'), array_filter(['type' => 'co_maker', 'id' => $coMaker->id, 'state' => $state]));
    }

    private function assertRemoved(User $actor, ClientFolder $folder, CoMaker $coMaker): void
    {
        $this->actingAs($actor)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker]))
            ->assertOk()->assertJsonPath('message', 'Co-Maker removed successfully.');

        $this->assertNull(CoMaker::query()->find($coMaker->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'co_maker.removed')->where('metadata->co_maker_id', $coMaker->id)->count());
    }
}
