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
use Tests\TestCase;

/**
 * Permanent Client Folder delete vs. another user's live work. Viewing (including an open but
 * unchanged form) is informational only; another user's live dirty or saving heartbeat in the same
 * folder blocks the delete server-side, and stops blocking once it is cleared or its TTL expires.
 *
 * Only an EMPTY folder can be deleted at all (see ClientFolderDeleteWarningTest), so every delete
 * outcome here is exercised on an otherwise-empty folder: the heartbeat is sent through the real
 * endpoint for a record in the folder, and that record is then hard-removed (creating it wrote no
 * operational history), leaving an empty folder with the live presence entry still registered.
 */
class ClientFolderDeleteEditingPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCKED = 'unsaved work in it. Please try again after they finish.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_viewing_only_presence_from_another_user_does_not_block_delete(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity)->assertOk();
        $this->emptyFolder($activity);

        $this->assertDeleted($ci2, $folder);
    }

    public function test_clean_edit_presence_does_not_block_delete(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'viewing')->assertOk();
        $this->emptyFolder($activity);

        $this->assertDeleted($ci2, $folder);
    }

    public function test_dirty_unsaved_presence_from_another_user_blocks_delete_and_leaves_the_folder_intact(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'dirty')->assertOk();
        $this->emptyFolder($activity);
        $auditsBefore = AuditLog::query()->count();

        $this->actingAs($ci2)->deleteJson(route('client-folders.destroy', $folder))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This Client Folder cannot be deleted because '.$ci1->full_name.' currently has '.self::BLOCKED);

        $this->assertSame($folder->folder_number, ClientFolder::query()->findOrFail($folder->id)->folder_number);
        $this->assertSame($auditsBefore, AuditLog::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted', 'metadata->folder_id' => $folder->id]);
    }

    public function test_saving_presence_from_another_user_blocks_a_direct_form_delete(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'saving')->assertOk();
        $this->emptyFolder($activity);

        // A plain (non-JSON) forged DELETE goes through the same server-side check.
        $this->actingAs($ci2)->from(route('client-folders.show', $folder))->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.show', $folder))
            ->assertSessionHasErrors('confirmation');

        $this->assertNotNull(ClientFolder::query()->find($folder->id));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted', 'metadata->folder_id' => $folder->id]);
    }

    public function test_the_deleting_users_own_dirty_presence_does_not_block_their_delete(): void
    {
        [, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci2, $activity, 'dirty')->assertOk();
        $this->emptyFolder($activity);

        $this->assertDeleted($ci2, $folder);
    }

    public function test_expired_dirty_presence_does_not_block_delete(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'dirty')->assertOk();
        $this->emptyFolder($activity);

        $this->travel(91)->seconds();

        $this->assertDeleted($ci2, $folder);
    }

    public function test_dirty_presence_in_folder_a_does_not_block_folder_b(): void
    {
        [$ci1, $ci2, $folderA, $activityA] = $this->scenario();
        $folderB = ClientFolder::factory()->create(['assigned_ci_id' => $ci1->id]);
        $this->heartbeat($ci1, $activityA, 'dirty')->assertOk();
        $this->emptyFolder($activityA);

        $this->assertDeleted($ci2, $folderB);
        $this->actingAs($ci2)->deleteJson(route('client-folders.destroy', $folderA))->assertStatus(422);
        $this->assertNotNull($folderA->fresh());
    }

    public function test_delete_succeeds_after_the_dirty_state_is_saved_clean(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'dirty')->assertOk();
        // Saved successfully: the page reports clean again.
        $this->heartbeat($ci1, $activity, 'viewing')->assertOk();
        $this->emptyFolder($activity);

        $this->assertDeleted($ci2, $folder);
    }

    public function test_release_clears_blocking_presence(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'saving')->assertOk();
        $this->actingAs($ci1)->postJson(route('editing-presence.release'), ['type' => 'ci_activity', 'id' => $activity->id])->assertOk();
        $this->emptyFolder($activity);

        $this->assertDeleted($ci2, $folder);
    }

    public function test_blocking_is_per_folder_not_per_person_or_record_and_names_never_expose_ids(): void
    {
        [$ci1, $ci2, $folder, $applicantActivity] = $this->scenario();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Isolated Co Maker']);
        $coMakerActivity = $this->activity($folder, $ci1, $coMaker->id);

        // Dirty work on the Co-Maker's activity is recorded under the folder, and releasing a
        // different record (the Applicant's) or another user's release never clears it.
        $this->heartbeat($ci1, $coMakerActivity, 'dirty')->assertOk();
        $this->heartbeat($ci1, $applicantActivity, 'viewing')->assertOk();
        $this->actingAs($ci1)->postJson(route('editing-presence.release'), ['type' => 'ci_activity', 'id' => $applicantActivity->id])->assertOk();
        $this->actingAs($ci2)->postJson(route('editing-presence.release'), ['type' => 'ci_activity', 'id' => $coMakerActivity->id])->assertOk();
        $this->assertSame($coMaker->id, $coMakerActivity->fresh()->co_maker_id);
        $this->assertNull($applicantActivity->fresh()->co_maker_id);

        $this->emptyFolder($applicantActivity, $coMakerActivity);
        $coMaker->delete();

        $message = $this->actingAs($ci2)->deleteJson(route('client-folders.destroy', $folder))->assertStatus(422)->json('message');
        $this->assertSame('This Client Folder cannot be deleted because '.$ci1->full_name.' currently has '.self::BLOCKED, $message);
        $this->assertStringNotContainsString((string) $ci1->id, str_replace($ci1->full_name, '', $message));
    }

    public function test_multiple_blocking_users_are_named_once_each(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $ci3 = User::factory()->create();
        $second = $this->activity($folder, $ci1);
        $this->heartbeat($ci1, $activity, 'dirty')->assertOk();
        $this->heartbeat($ci1, $second, 'saving')->assertOk();
        $this->heartbeat($ci3, $second, 'dirty')->assertOk();
        $this->emptyFolder($activity, $second);

        $this->actingAs($ci2)->deleteJson(route('client-folders.destroy', $folder))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This Client Folder cannot be deleted because '.$ci1->full_name.' and '.$ci3->full_name.' currently have '.self::BLOCKED);
    }

    public function test_a_non_empty_folder_is_refused_by_the_saved_records_rule_alone(): void
    {
        [$ci1, $ci2, $folder, $activity] = $this->scenario();
        $this->heartbeat($ci1, $activity, 'viewing')->assertOk();

        $this->actingAs($ci2)->deleteJson(route('client-folders.destroy', $folder))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This Client Folder can no longer be deleted because it already contains saved records.');
        $this->assertNotNull($activity->fresh());
    }

    public function test_existing_authorization_and_heartbeat_contract_are_unchanged(): void
    {
        [$ci1, , $folder, $activity] = $this->scenario();

        $this->heartbeat($ci1, $activity, 'locked')->assertStatus(422);
        // A heartbeat without a state is still accepted and is informational only.
        $this->actingAs($ci1)->postJson(route('editing-presence.heartbeat'), ['type' => 'ci_activity', 'id' => $activity->id])
            ->assertOk()->assertJson(['other_editors' => []]);
        $this->emptyFolder($activity);

        auth()->logout();
        $this->deleteJson(route('client-folders.destroy', $folder))->assertUnauthorized();

        // Any CI in the shared workspace (not only the assigned CI) may still permanently delete.
        $this->assertDeleted(User::factory()->create(), $folder);
    }

    public function test_front_end_reports_dirty_and_saving_state_from_the_existing_unsaved_form_tracking(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $presence = substr($js, strpos($js, "document.querySelectorAll('[data-editing-presence]')"));
        $presence = substr($presence, 0, strpos($presence, '// Header "Scheduled Today" bell'));

        $this->assertStringContainsString('form.hasUnsavedEdits = () => baseline !== null && snapshotForm() !== baseline;', $js);
        $this->assertStringContainsString("node.closest('[data-unsaved-form]') ?? document.querySelector('[data-unsaved-form]')", $presence);
        $this->assertStringContainsString('body: JSON.stringify({ type, id, state })', $presence);
        $this->assertStringContainsString("if (saving) return 'saving';", $presence);
        $this->assertStringContainsString("if (failedSave || form?.hasUnsavedEdits?.()) return 'dirty';", $presence);
        $this->assertStringContainsString("return 'viewing';", $presence);
        $this->assertStringContainsString("attributeFilter: ['data-submitting']", $presence);
        $this->assertStringContainsString("form.addEventListener('unsaved-form-reset'", $presence);
        $this->assertStringContainsString("window.addEventListener('pagehide'", $presence);
        $this->assertStringNotContainsString("addEventListener('beforeunload'", $presence);

        // The CI/BI AJAX save exposes the same in-flight flag and clears its dirty baseline on success.
        $cibi = substr($js, strpos($js, "document.querySelectorAll('[data-cibi-form]')"));
        $cibi = substr($cibi, 0, strpos($cibi, "document.addEventListener('click'"));
        $this->assertStringContainsString("form.dataset.submitting = 'true';", $cibi);
        $this->assertStringContainsString('delete form.dataset.submitting;', $cibi);
        $this->assertStringContainsString("form.dispatchEvent(new Event('unsaved-form-reset'));", $cibi);
    }

    // ---------------------------------------------------------------- Helpers

    private function heartbeat(User $user, CiActivity $activity, ?string $state = null)
    {
        return $this->actingAs($user)->postJson(route('editing-presence.heartbeat'), array_filter(['type' => 'ci_activity', 'id' => $activity->id, 'state' => $state]));
    }

    /** Hard-removes the heartbeat's records so the folder is empty while presence stays live. */
    private function emptyFolder(CiActivity ...$activities): void
    {
        foreach ($activities as $activity) {
            CiActivity::withTrashed()->whereKey($activity->id)->forceDelete();
        }
    }

    private function assertDeleted(User $actor, ClientFolder $folder): void
    {
        $this->actingAs($actor)->deleteJson(route('client-folders.destroy', $folder))
            ->assertOk()
            ->assertJsonPath('message', 'Client folder permanently deleted.');

        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'client_folder.permanently_deleted')->where('metadata->folder_id', $folder->id)->count());
    }

    /** @return array{0: User, 1: User, 2: ClientFolder, 3: CiActivity} */
    private function scenario(): array
    {
        $ci1 = User::factory()->create();
        $ci2 = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci1->id]);

        return [$ci1, $ci2, $folder, $this->activity($folder, $ci1)];
    }

    private function activity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'status' => ActivityStatus::Pending,
        ]);
    }
}
