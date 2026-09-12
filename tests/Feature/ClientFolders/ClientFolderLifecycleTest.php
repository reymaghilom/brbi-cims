<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\UserStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFolderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_folder_header_hides_the_reference_number_but_keeps_status_and_client_name(): void
    {
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $investigator->id,
            'display_name' => 'VISIBLE CLIENT NAME',
            'folder_number' => 'BRBI-CI-2026-00023',
        ]);
        $originalFolderNumber = $folder->folder_number;

        $this->actingAs($investigator)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('On Progress')
            ->assertSee('VISIBLE CLIENT NAME')
            ->assertDontSee('BRBI-CI-2026-00023');

        $this->assertSame($originalFolderNumber, $folder->fresh()->folder_number);
    }

    public function test_administrator_can_create_a_folder_for_an_active_credit_investigator(): void
    {
        $administrator = User::factory()->administrator()->create();
        $investigator = User::factory()->create(['full_name' => 'Active Investigator']);
        ActivityDefinition::factory()->create([
            'code' => ActivityDefinition::BARANGAY_CHECK_CODE,
            'name' => 'Barangay Check',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ActivityDefinition::factory()->create([
            'code' => ActivityDefinition::NEIGHBOR_CHECK_CODE,
            'name' => 'Neighbor Check',
            'is_active' => true,
            'sort_order' => 2,
        ]);

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
        // Barangay Check and Neighbor Check are no longer generated on creation — they are
        // built-in Activity Types a CI adds manually, which is what makes the adding CI their
        // Creator. The folder starts with an empty CI Activities checklist.
        $this->assertCount(0, $folder->activities);
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

    public function test_administrator_assignment_is_optional_but_rejects_disabled_and_non_ci_users(): void
    {
        $administrator = User::factory()->administrator()->create();
        $disabledCi = User::factory()->create(['status' => UserStatus::Disabled]);
        $otherAdministrator = User::factory()->administrator()->create();
        $payload = ['last_name' => 'Santos', 'first_name' => 'Ana', 'middle_name' => 'Reyes'];

        // No mandatory Primary CI at folder level — an Administrator may leave it unassigned.
        $this->actingAs($administrator)->post(route('client-folders.store'), $payload)
            ->assertSessionDoesntHaveErrors('assigned_ci_id');
        $this->assertNull(ClientFolder::sole()->assigned_ci_id);

        $this->actingAs($administrator)->post(route('client-folders.store'), $payload + ['assigned_ci_id' => $disabledCi->id])
            ->assertSessionHasErrors('assigned_ci_id');
        $this->actingAs($administrator)->post(route('client-folders.store'), $payload + ['assigned_ci_id' => $otherAdministrator->id])
            ->assertSessionHasErrors('assigned_ci_id');

        $this->assertDatabaseCount('client_folders', 1);
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
            ->assertSee("You'll be listed as the creator of this folder.", false)
            ->assertDontSee("You'll be recorded as the creator of this folder.", false)
            // The supporting line names the signed-in CI dynamically — never a hard-coded name.
            ->assertSee($activeCi->full_name.' · All Credit Investigators can still access and work on this folder.', false)
            ->assertDontSee('every Credit Investigator can still open and work on it.', false)
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

    public function test_middle_name_is_optional_and_accepts_full_name_initial_or_blank(): void
    {
        $investigator = User::factory()->create();

        // Blank Middle Name — no fake N/A placeholder, stored/displayed cleanly with no double space.
        $this->actingAs($investigator)->post(route('client-folders.store'), [
            'last_name' => 'Reyes', 'first_name' => 'Maria',
        ])->assertRedirect();
        $folder = ClientFolder::query()->where('last_name', 'REYES')->sole();
        $this->assertNull($folder->middle_name);
        $this->assertSame('REYES, MARIA', $folder->display_name);
        $this->assertStringNotContainsString('  ', $folder->display_name);

        // Middle initial without a period.
        $this->actingAs($investigator)->post(route('client-folders.store'), [
            'last_name' => 'Santos', 'first_name' => 'Juan', 'middle_name' => 'M',
        ])->assertRedirect();
        $this->assertSame('M', ClientFolder::query()->where('last_name', 'SANTOS')->sole()->middle_name);

        // Middle initial with a period.
        $this->actingAs($investigator)->post(route('client-folders.store'), [
            'last_name' => 'Cruz', 'first_name' => 'Ana', 'middle_name' => 'M.',
        ])->assertRedirect();
        $this->assertSame('M.', ClientFolder::query()->where('last_name', 'CRUZ')->sole()->middle_name);

        // Full middle name.
        $this->actingAs($investigator)->post(route('client-folders.store'), [
            'last_name' => 'Garcia', 'first_name' => 'Pedro', 'middle_name' => 'Miguel',
        ])->assertRedirect();
        $this->assertSame('MIGUEL', ClientFolder::query()->where('last_name', 'GARCIA')->sole()->middle_name);

        $this->assertDatabaseCount('client_folders', 4);
    }

    public function test_first_and_last_name_remain_required_when_creating_a_folder(): void
    {
        $investigator = User::factory()->create();

        $this->actingAs($investigator)->post(route('client-folders.store'), ['first_name' => 'Maria'])
            ->assertSessionHasErrors(['last_name']);
        $this->actingAs($investigator)->post(route('client-folders.store'), ['last_name' => 'Reyes'])
            ->assertSessionHasErrors(['first_name']);

        $this->assertDatabaseCount('client_folders', 0);
    }

    public function test_assigned_ci_can_rename_without_changing_identity_ownership_or_children(): void
    {
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id]);
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
        $original = $folder->only(['id', 'folder_number', 'assigned_ci_id', 'created_by']);

        $this->actingAs($investigator)->patch(route('client-folders.update-name', $folder), [
            'last_name' => ' dela   cruz ',
            'first_name' => ' juan ',
            'middle_name' => ' santos ',
            'suffix' => ' jr. ',
        ])->assertRedirect(route('client-folders.show', $folder));

        $folder->refresh();
        $this->assertSame('DELA CRUZ, JUAN SANTOS JR.', $folder->display_name);
        $this->assertSame([
            'last_name' => 'DELA CRUZ',
            'first_name' => 'JUAN',
            'middle_name' => 'SANTOS',
            'suffix' => 'JR.',
        ], $folder->only(['last_name', 'first_name', 'middle_name', 'suffix']));
        $this->assertStringNotContainsString('  ', $folder->display_name);
        $this->assertSame($original, $folder->only(array_keys($original)));
        $this->assertSame($folder->id, $information->fresh()->client_folder_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_folder.renamed', 'client_folder_id' => $folder->id]);
    }

    public function test_rename_records_the_actual_authenticated_actor_not_the_folder_creator_or_assigned_ci(): void
    {
        $creator = User::factory()->create();
        $assignedCi = User::factory()->create();
        $actualRenamer = User::factory()->create();
        $folder = ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $assignedCi->id, 'display_name' => 'ORIGINAL NAME']);

        $this->actingAs($actualRenamer)->patch(route('client-folders.update-name', $folder), [
            'last_name' => 'Renamed',
            'first_name' => 'Actor',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'client_folder.renamed',
            'client_folder_id' => $folder->id,
            'user_id' => $actualRenamer->id,
        ]);
    }

    public function test_dashboard_rename_success_dispatches_the_existing_folder_browser_refresh_auto_update_event(): void
    {
        // The rename response only patches the folder's name text in place — the "Renamed"
        // Folder History entry it just persisted only exists in $folderHistoryByFolder on the
        // server. Rather than a second history-only endpoint, this must reuse the exact same
        // folder-browser:refresh AUTO-UPDATE event Create/Delete already dispatch, so the History
        // dialog re-render picks it up. Source-level check, matching the pattern already used
        // elsewhere in this test suite for verifying app.js wiring.
        $javascript = file_get_contents(resource_path('js/app.js'));

        $start = strpos($javascript, "form.matches('[data-folder-rename-form]')) {", strpos($javascript, 'if (!response.ok)'));
        $end = strpos($javascript, '} else {', $start);
        $renameSuccessSource = substr($javascript, $start, $end - $start);

        $this->assertStringContainsString("dispatchEvent(new CustomEvent('folder-browser:refresh'))", $renameSuccessSource);
    }

    public function test_other_ci_can_open_rename_and_delete_a_shared_active_folder(): void
    {
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($other)->get(route('client-folders.edit-name', $folder))->assertOk();
        $this->actingAs($other)->patch(route('client-folders.update-name', $folder), ['last_name' => 'Renamed', 'first_name' => 'Other CI'])->assertRedirect();
        $this->assertSame('RENAMED, OTHER CI', $folder->fresh()->display_name);

        $this->actingAs($other)->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.index'));
        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
    }

    public function test_assigned_ci_can_permanently_delete_folder_and_its_owned_children(): void
    {
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id, 'display_name' => 'KEPT CLIENT']);
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);

        $this->actingAs($investigator)->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.index'));

        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
        $this->assertDatabaseMissing('client_information', ['id' => $information->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.recycled', 'client_folder_id' => $folder->id]);
        $this->actingAs($investigator)->get(route('client-folders.index'))->assertDontSee('KEPT CLIENT');
    }

    public function test_senior_ci_can_permanently_delete_an_eligible_shared_folder(): void
    {
        $senior = User::factory()->seniorCreditInvestigator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => User::factory()->create()->id]);

        $this->actingAs($senior)->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.index'));

        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
    }

    public function test_administrator_permanently_deletes_an_active_folder_with_its_owned_records(): void
    {
        $administrator = User::factory()->administrator()->create();
        $investigator = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id]);
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'DELETED CO-MAKER']);
        $cibiReport = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $investigator->id]);
        $unrelatedFolder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id]);
        $unrelatedInformation = ClientInformation::factory()->create(['client_folder_id' => $unrelatedFolder->id]);
        $unrelatedCoMaker = CoMaker::create(['client_folder_id' => $unrelatedFolder->id, 'full_name' => 'PRESERVED CO-MAKER']);
        $number = $folder->folder_number;

        $this->actingAs($administrator)->delete(route('client-folders.destroy', $folder))
            ->assertRedirect(route('client-folders.index'));

        // Permanent: the row is gone outright, never parked as an unreachable soft-deleted record.
        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
        $this->assertDatabaseMissing('client_information', ['id' => $information->id]);
        $this->assertDatabaseMissing('co_makers', ['id' => $coMaker->id]);
        $this->assertDatabaseMissing('cibi_reports', ['id' => $cibiReport->id]);
        $this->assertDatabaseHas('client_folders', ['id' => $unrelatedFolder->id]);
        $this->assertDatabaseHas('client_information', ['id' => $unrelatedInformation->id, 'client_folder_id' => $unrelatedFolder->id]);
        $this->assertDatabaseHas('co_makers', ['id' => $unrelatedCoMaker->id, 'client_folder_id' => $unrelatedFolder->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.recycled']);

        // The audit row itself survives the folder it describes (client_folder_id nulls out).
        $audit = AuditLog::where('action', 'client_folder.permanently_deleted')->sole();
        $this->assertNull($audit->client_folder_id);
        $this->assertSame($number, $audit->metadata['folder_number']);
    }

    public function test_deleting_a_folder_over_ajax_reports_permanent_deletion_without_navigation(): void
    {
        $administrator = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => User::factory()->create()->id]);

        $this->actingAs($administrator)
            ->deleteJson(route('client-folders.destroy', $folder))
            ->assertOk()
            ->assertJsonPath('message', 'Client folder permanently deleted.')
            ->assertHeaderMissing('Location');

        $this->assertNull(ClientFolder::withTrashed()->find($folder->id));
    }

    public function test_permanent_delete_is_safely_blocked_when_external_cleanup_is_required(): void
    {
        $actors = [
            User::factory()->create(),
            User::factory()->seniorCreditInvestigator()->create(),
            User::factory()->administrator()->create(),
        ];

        foreach ($actors as $actor) {
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
            $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $actor->id]);

            $this->actingAs($actor)->from(route('client-folders.index'))
                ->delete(route('client-folders.destroy', $folder))
                ->assertRedirect(route('client-folders.index'))
                ->assertSessionHasErrors('confirmation');

            // Blocked for every authorized role, never silently force-deleted or half-removed.
            $this->assertNotNull(ClientFolder::find($folder->id));
            $this->assertDatabaseHas('media_references', ['id' => $media->id, 'client_folder_id' => $folder->id]);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted', 'client_folder_id' => $folder->id]);
        }
    }

    public function test_permanent_delete_is_safely_blocked_when_generated_report_cleanup_is_required(): void
    {
        $actors = [
            User::factory()->create(),
            User::factory()->seniorCreditInvestigator()->create(),
            User::factory()->administrator()->create(),
        ];

        foreach ($actors as $actor) {
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
            $report = GeneratedReport::factory()->create(['client_folder_id' => $folder->id, 'generated_by' => $actor->id]);

            $this->actingAs($actor)->from(route('client-folders.index'))
                ->delete(route('client-folders.destroy', $folder))
                ->assertRedirect(route('client-folders.index'))
                ->assertSessionHasErrors('confirmation');

            $this->assertNotNull(ClientFolder::find($folder->id));
            $this->assertDatabaseHas('generated_reports', ['id' => $report->id, 'client_folder_id' => $folder->id]);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted', 'client_folder_id' => $folder->id]);
        }
    }

    public function test_unauthenticated_request_cannot_bypass_permanent_delete(): void
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => User::factory()->create()->id]);

        $this->delete(route('client-folders.destroy', $folder))->assertRedirect(route('login'));

        $this->assertNotNull(ClientFolder::find($folder->id));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'client_folder.permanently_deleted', 'client_folder_id' => $folder->id]);
    }

    public function test_the_recycle_bin_feature_is_completely_gone(): void
    {
        $administrator = User::factory()->administrator()->create();

        $names = collect(app('router')->getRoutes())->map(fn ($route): string => (string) $route->getName().' '.$route->uri());
        $this->assertFalse($names->contains(fn (string $route): bool => str_contains($route, 'recycle')));

        $this->actingAs($administrator)->get('/recycle-bin')->assertNotFound();
        $this->actingAs($administrator)->get(route('home'))->assertOk()->assertDontSee('Recycle Bin');
    }
}
