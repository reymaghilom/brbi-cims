<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FolderBrowserInteractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_search_requires_authentication_and_a_valid_browser_context(): void
    {
        $this->get(route('client-folders.live-search', ['search' => 'MAR', 'context' => 'dashboard']))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('client-folders.live-search', ['context' => 'invalid']))
            ->assertSessionHasErrors('context');
    }

    public function test_live_search_returns_reflowable_authorized_folder_markup_without_folder_numbers(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MARIA SANTOS', 'folder_number' => 'PRIVATE-NUMBER-1']);
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'JUAN REYES', 'folder_number' => 'PRIVATE-NUMBER-2']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'MARICEL PRIVATE', 'folder_number' => 'PRIVATE-NUMBER-3']);

        $this->actingAs($ci)
            ->get(route('client-folders.live-search', ['search' => 'MAR', 'context' => 'dashboard']))
            ->assertOk()
            ->assertSee('MARIA SANTOS')
            ->assertDontSee('JUAN REYES')
            ->assertDontSee('MARICEL PRIVATE')
            ->assertDontSee('PRIVATE-NUMBER-1')
            ->assertDontSee('PRIVATE-NUMBER-2')
            ->assertDontSee('PRIVATE-NUMBER-3')
            ->assertSee('data-folder-browser-layout', false)
            ->assertSee('data-folder-browser-artifacts', false);
    }

    public function test_ajax_rename_reuses_existing_action_and_returns_without_navigation(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'OLD NAME']);

        $this->actingAs($ci)
            ->patchJson(route('client-folders.update-name', $folder), ['display_name' => 'new browser name'])
            ->assertOk()
            ->assertJsonPath('message', 'Client folder renamed successfully.')
            ->assertJsonPath('folder.display_name', 'NEW BROWSER NAME')
            ->assertHeaderMissing('Location');

        $this->assertSame('NEW BROWSER NAME', $folder->fresh()->display_name);
    }

    public function test_ajax_create_reuses_existing_action_and_returns_folder_data_without_navigation(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)
            ->postJson(route('client-folders.store'), [
                'last_name' => 'Reyes',
                'first_name' => 'Maria',
                'middle_name' => 'Santos',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Client folder created successfully.')
            ->assertJsonPath('folder.display_name', 'REYES, MARIA SANTOS')
            ->assertJsonPath('folder.status', 'on_progress')
            ->assertJsonPath('folder.open_url', route('client-folders.show', ClientFolder::sole()))
            ->assertHeaderMissing('Location');

        $this->assertSame($ci->id, ClientFolder::sole()->assigned_ci_id);
    }

    public function test_ajax_create_preserves_administrator_assignment_rules_and_validation_payloads(): void
    {
        $administrator = User::factory()->administrator()->create();
        $assignedCi = User::factory()->create();

        $this->actingAs($administrator)
            ->postJson(route('client-folders.store'), [
                'last_name' => 'Santos',
                'first_name' => 'Ana',
                'middle_name' => 'Reyes',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_ci_id');

        $this->actingAs($administrator)
            ->postJson(route('client-folders.store'), [
                'last_name' => 'Santos',
                'first_name' => 'Ana',
                'middle_name' => 'Reyes',
                'assigned_ci_id' => $assignedCi->id,
            ])
            ->assertCreated()
            ->assertHeaderMissing('Location');

        $this->assertSame($assignedCi->id, ClientFolder::sole()->assigned_ci_id);
    }

    public function test_ajax_recycle_reuses_soft_delete_action_and_returns_without_navigation(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)
            ->deleteJson(route('client-folders.destroy', $folder))
            ->assertOk()
            ->assertJsonPath('message', 'Client folder moved to the Recycle Bin.')
            ->assertHeaderMissing('Location');

        $this->assertTrue(ClientFolder::withTrashed()->findOrFail($folder->id)->trashed());
    }

    public function test_ajax_actions_preserve_cross_ci_authorization(): void
    {
        $assigned = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($otherCi)
            ->patchJson(route('client-folders.update-name', $folder), ['display_name' => 'FORGED'])
            ->assertForbidden();
        $this->actingAs($otherCi)
            ->deleteJson(route('client-folders.destroy', $folder))
            ->assertForbidden();
    }
}
