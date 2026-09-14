<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Assigned CI choice is labelled with the investigator's full name only, and assignment stores
 * that exact users.id — there is no other user identifier anywhere in the flow.
 */
class AssignedCiOptionLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_ci_options_show_only_the_full_name_and_store_the_exact_user_id(): void
    {
        $administrator = User::factory()->administrator()->create();
        $investigator = User::factory()->create(['full_name' => 'Exact Investigator']);
        User::factory()->create(['full_name' => 'Another Investigator']);

        $html = $this->actingAs($administrator)->get(route('client-folders.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<option value="'.$investigator->id.'"[^>]*>Exact Investigator</option>#', $html);
        $this->assertStringNotContainsString('Exact Investigator — ', $html);

        $this->actingAs($administrator)->post(route('client-folders.store'), [
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
            'assigned_ci_id' => $investigator->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($investigator->id, ClientFolder::sole()->assigned_ci_id);
    }
}
