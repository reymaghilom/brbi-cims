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

    public function test_live_search_returns_reflowable_folder_markup_across_the_shared_workspace_without_folder_numbers(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MARIA SANTOS', 'folder_number' => 'PRIVATE-NUMBER-1']);
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'JUAN REYES', 'folder_number' => 'PRIVATE-NUMBER-2']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'MARICEL SHARED', 'folder_number' => 'PRIVATE-NUMBER-3']);

        $this->actingAs($ci)
            ->get(route('client-folders.live-search', ['search' => 'MAR', 'context' => 'dashboard']))
            ->assertOk()
            ->assertSee('MARIA SANTOS')
            ->assertDontSee('JUAN REYES')
            ->assertSee('MARICEL SHARED')
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

        // No mandatory Primary CI at folder level — an Administrator may leave it unassigned.
        $this->actingAs($administrator)
            ->postJson(route('client-folders.store'), [
                'last_name' => 'Santos',
                'first_name' => 'Ana',
                'middle_name' => 'Reyes',
            ])
            ->assertCreated();
        $this->assertNull(ClientFolder::sole()->assigned_ci_id);
        ClientFolder::sole()->forceDelete();

        $this->actingAs($administrator)
            ->postJson(route('client-folders.store'), [
                'last_name' => 'Santos',
                'first_name' => 'Ana',
                'middle_name' => 'Reyes',
                'assigned_ci_id' => $administrator->id,
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

    public function test_ajax_delete_permanently_removes_the_folder_and_returns_without_navigation(): void
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

    public function test_the_search_box_offers_an_accessible_autosuggest_beside_the_live_grid(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);

        $html = $this->actingAs($ci)->get(route('client-folders.index'))->assertOk()->getContent();

        // The combobox sits on the existing live-search input, which keeps its own hooks intact.
        foreach (['data-client-search', 'data-client-search-input', 'data-client-search-clear',
            'data-client-search-suggestions', 'role="combobox"', 'aria-expanded="false"',
            'aria-controls="folder-search-suggestions"', 'aria-autocomplete="list"', 'role="listbox"'] as $hook) {
            $this->assertStringContainsString($hook, $html, $hook.' is part of the search control.');
        }
        // Both endpoints are wired: one keeps the grid moving, the other feeds the dropdown.
        $this->assertStringContainsString(e(route('client-folders.live-search')), $html);
        $this->assertStringContainsString(e(route('client-folders.suggestions')), $html);
    }

    public function test_typing_filters_the_grid_and_lists_suggestions_from_the_same_term(): void
    {
        $ci = User::factory()->create();
        $colleague = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);
        ClientFolder::factory()->create(['assigned_ci_id' => $colleague->id, 'display_name' => 'DELA CRUZ, MARIA']);
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'SANTOS, PEDRO']);
        $recycled = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, RECYCLED']);
        $recycled->delete();

        // The grid filters on the half-typed name, with no suggestion selected.
        $grid = $this->actingAs($ci)
            ->get(route('client-folders.live-search', ['search' => 'DELA', 'context' => 'client_folders']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('DELA CRUZ, JUAN', $grid);
        $this->assertStringContainsString('DELA CRUZ, MARIA', $grid, 'The shared workspace is included.');
        $this->assertStringNotContainsString('SANTOS, PEDRO', $grid);
        $this->assertStringNotContainsString('DELA CRUZ, RECYCLED', $grid, 'A recycled folder never returns.');

        // The very same term drives the dropdown.
        $suggestions = $this->actingAs($ci)
            ->getJson(route('client-folders.suggestions', ['q' => 'DELA']))
            ->assertOk()->json('suggestions');
        $this->assertSame(['DELA CRUZ, JUAN', 'DELA CRUZ, MARIA'], $suggestions);
        $this->assertNotContains('DELA CRUZ, RECYCLED', $suggestions);
        $this->assertNotContains('SANTOS, PEDRO', $suggestions);

        // Selecting a suggestion is only a shortcut to the exact name the grid already accepts.
        $selected = $this->actingAs($ci)
            ->get(route('client-folders.live-search', ['search' => 'DELA CRUZ, JUAN', 'context' => 'client_folders']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('DELA CRUZ, JUAN', $selected);
        $this->assertStringNotContainsString('DELA CRUZ, MARIA', $selected);
    }

    public function test_two_folders_sharing_a_name_both_survive_a_search(): void
    {
        $ci = User::factory()->create();
        $first = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);
        $second = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);

        $grid = $this->actingAs($ci)
            ->get(route('client-folders.live-search', ['search' => 'DELA CRUZ, JUAN', 'context' => 'client_folders']))
            ->assertOk()->getContent();

        // Two distinct folders, never collapsed into one because their names match.
        $this->assertStringContainsString((string) $first->id, $grid);
        $this->assertStringContainsString((string) $second->id, $grid);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ClientFolder::query()->where('display_name', 'DELA CRUZ, JUAN')->count());
    }

    public function test_searching_and_suggesting_never_touch_a_folder_record(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN']);
        $before = ClientFolder::query()->count();
        $touched = $folder->updated_at;

        $this->actingAs($ci)->get(route('client-folders.live-search', ['search' => 'DE', 'context' => 'client_folders']))->assertOk();
        $this->actingAs($ci)->getJson(route('client-folders.suggestions', ['q' => 'DE']))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.live-search', ['search' => '', 'context' => 'client_folders']))->assertOk();

        $this->assertSame($before, ClientFolder::query()->count());
        $this->assertTrue($touched->equalTo($folder->fresh()->updated_at), 'Searching is a read.');
    }

    public function test_ajax_actions_allow_any_ci_to_act_on_a_folder_assigned_to_another_ci(): void
    {
        $assigned = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($otherCi)
            ->patchJson(route('client-folders.update-name', $folder), ['display_name' => 'RENAMED BY OTHER CI'])
            ->assertOk();

        // Delete is the one folder action the shared workspace does NOT open up: it is permanent
        // (no Recycle Bin) and stays administrator-only, enforced server-side.
        $this->actingAs($otherCi)
            ->deleteJson(route('client-folders.destroy', $folder))
            ->assertForbidden();
        $this->assertNotNull(ClientFolder::find($folder->id));
    }
}
