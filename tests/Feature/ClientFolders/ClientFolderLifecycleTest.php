<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\UserStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFolderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_a_folder_for_an_active_credit_investigator(): void
    {
        $administrator = User::factory()->administrator()->create();
        $investigator = User::factory()->create(['full_name' => 'Active Investigator']);
        ActivityDefinition::factory()->count(2)->create(['is_active' => true]);

        $response = $this->actingAs($administrator)->post(route('client-folders.store'), [
            'last_name' => ' dela cruz ',
            'first_name' => ' juan ',
            'middle_name' => ' santos ',
            'suffix' => ' jr. ',
            'assigned_ci_id' => $investigator->id,
        ]);

        $folder = ClientFolder::sole();
        $response->assertRedirect(route('client-folders.show', $folder))->assertSessionHas('status');
        $this->assertSame('DELA CRUZ, JUAN SANTOS JR.', $folder->display_name);
        $this->assertSame($investigator->id, $folder->assigned_ci_id);
        $this->assertSame($administrator->id, $folder->created_by);
        $this->assertMatchesRegularExpression('/^BRBI-CI-\d{4}-00001$/', $folder->folder_number);
        $this->assertCount(2, $folder->activities);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_folder.created', 'client_folder_id' => $folder->id, 'user_id' => $administrator->id]);
    }

    public function test_credit_investigator_creation_is_self_assigned_and_forged_assignment_is_rejected(): void
    {
        $investigator = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($investigator)->post(route('client-folders.store'), [
            'last_name' => 'Reyes',
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'assigned_ci_id' => $other->id,
        ])->assertSessionHasErrors('assigned_ci_id');
        $this->assertDatabaseCount('client_folders', 0);

        $this->actingAs($investigator)->post(route('client-folders.store'), [
            'last_name' => 'Reyes',
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
        ])->assertRedirect();

        $this->assertSame($investigator->id, ClientFolder::sole()->assigned_ci_id);
    }

    public function test_administrator_assignment_rejects_missing_disabled_and_non_ci_users(): void
    {
        $administrator = User::factory()->administrator()->create();
        $disabledCi = User::factory()->create(['status' => UserStatus::Disabled]);
        $otherAdministrator = User::factory()->administrator()->create();
        $payload = ['last_name' => 'Santos', 'first_name' => 'Ana', 'middle_name' => 'Reyes'];

        $this->actingAs($administrator)->post(route('client-folders.store'), $payload)
            ->assertSessionHasErrors('assigned_ci_id');
        $this->actingAs($administrator)->post(route('client-folders.store'), $payload + ['assigned_ci_id' => $disabledCi->id])
            ->assertSessionHasErrors('assigned_ci_id');
        $this->actingAs($administrator)->post(route('client-folders.store'), $payload + ['assigned_ci_id' => $otherAdministrator->id])
            ->assertSessionHasErrors('assigned_ci_id');

        $this->assertDatabaseCount('client_folders', 0);
    }

    public function test_create_form_lists_only_active_credit_investigators_for_administrator(): void
    {
        $administrator = User::factory()->administrator()->create();
        $activeCi = User::factory()->create(['full_name' => 'VISIBLE ACTIVE CI']);
        User::factory()->create(['full_name' => 'HIDDEN DISABLED CI', 'status' => UserStatus::Disabled]);
        User::factory()->administrator()->create(['full_name' => 'HIDDEN ADMINISTRATOR']);

        $this->actingAs($administrator)->get(route('client-folders.create'))
            ->assertOk()
            ->assertSee('VISIBLE ACTIVE CI')
            ->assertDontSee('HIDDEN DISABLED CI')
            ->assertDontSee('HIDDEN ADMINISTRATOR')
            ->assertSee('name="assigned_ci_id"', false);

        $this->actingAs($activeCi)->get(route('client-folders.create'))
            ->assertOk()
            ->assertSee('This folder will be assigned to your account.')
            ->assertDontSee('name="assigned_ci_id"', false);
    }

    public function test_folder_number_generator_skips_collisions_and_allocates_monotonic_numbers(): void
    {
        $this->travelTo('2026-08-08 08:00:00');
        $investigator = User::factory()->create();
        ClientFolder::factory()->create([
            'folder_number' => 'BRBI-CI-2026-00001',
            'assigned_ci_id' => $investigator->id,
        ]);

        foreach (['Alpha', 'Beta'] as $lastName) {
            $this->actingAs($investigator)->post(route('client-folders.store'), [
                'last_name' => $lastName,
                'first_name' => 'Client',
                'middle_name' => 'Santos',
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('client_folders', ['folder_number' => 'BRBI-CI-2026-00002']);
        $this->assertDatabaseHas('client_folders', ['folder_number' => 'BRBI-CI-2026-00003']);
        $this->assertDatabaseHas('folder_number_sequences', ['year' => 2026, 'last_number' => 3]);
    }

    public function test_middle_name_is_required_and_rejects_initials_when_creating_a_folder(): void
    {
        $investigator = User::factory()->create();
        $base = ['last_name' => 'Reyes', 'first_name' => 'Maria'];

        $this->actingAs($investigator)->post(route('client-folders.store'), $base)
            ->assertSessionHasErrors(['middle_name' => 'Middle name is required.']);
        $this->actingAs($investigator)->post(route('client-folders.store'), $base + ['middle_name' => 'Q.'])
            ->assertSessionHasErrors(['middle_name' => 'Middle name must be written in full, not as an initial.']);

        $this->assertDatabaseCount('client_folders', 0);
    }

    public function test_assigned_ci_can_rename_without_changing_identity_ownership_or_children(): void
    {
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id, 'display_name' => 'ORIGINAL NAME']);
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
        $original = $folder->only(['id', 'folder_number', 'assigned_ci_id', 'last_name', 'first_name']);

        $this->actingAs($investigator)->patch(route('client-folders.update-name', $folder), [
            'display_name' => ' updated client folder ',
        ])->assertRedirect(route('client-folders.show', $folder));

        $folder->refresh();
        $this->assertSame('UPDATED CLIENT FOLDER', $folder->display_name);
        $this->assertSame($original, $folder->only(array_keys($original)));
        $this->assertSame($folder->id, $information->fresh()->client_folder_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_folder.renamed', 'client_folder_id' => $folder->id]);
    }

    public function test_other_ci_cannot_open_or_submit_rename_and_cannot_recycle_folder(): void
    {
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($other)->get(route('client-folders.edit-name', $folder))->assertForbidden();
        $this->actingAs($other)->patch(route('client-folders.update-name', $folder), ['display_name' => 'FORGED'])->assertForbidden();
        $this->actingAs($other)->delete(route('client-folders.destroy', $folder))->assertForbidden();

        $this->assertFalse($folder->fresh()->trashed());
    }

    public function test_assigned_ci_can_recycle_folder_while_children_and_audit_history_remain(): void
    {
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id, 'display_name' => 'RECYCLE CLIENT']);
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);

        $this->actingAs($investigator)->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.index'));

        $recycled = ClientFolder::withTrashed()->findOrFail($folder->id);
        $this->assertTrue($recycled->trashed());
        $this->assertSame($investigator->id, $recycled->deleted_by);
        $this->assertDatabaseHas('client_information', ['id' => $information->id, 'client_folder_id' => $folder->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_folder.recycled', 'client_folder_id' => $folder->id]);
        $this->actingAs($investigator)->get(route('client-folders.index'))->assertDontSee('RECYCLE CLIENT');
        $this->actingAs($investigator)->get(route('home'))->assertDontSee('RECYCLE CLIENT');
    }

    public function test_administrator_can_recycle_any_active_folder(): void
    {
        $administrator = User::factory()->administrator()->create();
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id]);

        $this->actingAs($administrator)->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.index'));

        $this->assertTrue(ClientFolder::withTrashed()->findOrFail($folder->id)->trashed());
    }

    public function test_recycle_bin_is_policy_scoped_and_ci_has_no_restore_or_purge_actions(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $own = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'OWN RECYCLED']);
        $other = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'OTHER RECYCLED']);
        $own->delete();
        $other->delete();

        $this->actingAs($ci)->get(route('recycle-bin.index'))
            ->assertOk()
            ->assertSee('OWN RECYCLED')
            ->assertDontSee('OTHER RECYCLED')
            ->assertDontSee('Restore folder')
            ->assertDontSee('Delete permanently');

        $this->actingAs($administrator)->get(route('recycle-bin.index'))
            ->assertSee('OWN RECYCLED')
            ->assertSee('OTHER RECYCLED')
            ->assertSee('Restore folder')
            ->assertSee('Delete permanently');
    }

    public function test_only_administrator_can_restore_and_original_identity_is_preserved(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'progress_percent' => 60]);
        $id = $folder->id;
        $number = $folder->folder_number;
        $folder->delete();

        $this->actingAs($ci)->patch(route('recycle-bin.restore', $folder))->assertForbidden();
        $this->actingAs($administrator)->patch(route('recycle-bin.restore', $folder))
            ->assertRedirect(route('client-folders.show', $folder));

        $restored = ClientFolder::findOrFail($id);
        $this->assertSame($id, $restored->id);
        $this->assertSame($number, $restored->folder_number);
        $this->assertSame('60.00', $restored->progress_percent);
        $this->assertNull($restored->deleted_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_folder.restored', 'client_folder_id' => $id]);
    }

    public function test_permanent_delete_is_admin_only_requires_typed_confirmation_and_only_accepts_recycled_folder(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $active = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $recycled = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $information = ClientInformation::factory()->create(['client_folder_id' => $recycled->id]);
        $recycled->delete();

        $this->actingAs($ci)->delete(route('recycle-bin.destroy', $recycled), ['confirmation' => $recycled->folder_number])->assertForbidden();
        $this->actingAs($administrator)->delete(route('recycle-bin.destroy', $recycled), ['confirmation' => 'wrong'])->assertSessionHasErrors('confirmation');
        $this->actingAs($administrator)->delete(route('recycle-bin.destroy', $active), ['confirmation' => $active->folder_number])->assertNotFound();
        $this->actingAs($administrator)->delete(route('recycle-bin.destroy', $recycled), ['confirmation' => $recycled->folder_number])
            ->assertRedirect(route('recycle-bin.index'));

        $this->assertNull(ClientFolder::withTrashed()->find($recycled->id));
        $this->assertDatabaseMissing('client_information', ['id' => $information->id]);
        $audit = AuditLog::where('action', 'client_folder.permanently_deleted')->sole();
        $this->assertNull($audit->client_folder_id);
        $this->assertSame($recycled->folder_number, $audit->metadata['folder_number']);
    }

    public function test_permanent_delete_is_safely_deferred_when_external_cleanup_is_required(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $ci->id]);
        $folder->delete();

        $this->actingAs($administrator)->from(route('recycle-bin.index'))
            ->delete(route('recycle-bin.destroy', $folder), ['confirmation' => $folder->folder_number])
            ->assertRedirect(route('recycle-bin.index'))
            ->assertSessionHasErrors('confirmation');

        $this->assertNotNull(ClientFolder::withTrashed()->find($folder->id));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted', 'client_folder_id' => $folder->id]);
    }
}
