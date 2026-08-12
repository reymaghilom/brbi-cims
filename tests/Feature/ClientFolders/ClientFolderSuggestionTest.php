<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientFolderSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_require_authentication(): void
    {
        $this->getJson(route('client-folders.suggestions', ['q' => 'rea']))
            ->assertUnauthorized();
    }

    public function test_credit_investigator_receives_only_assigned_matching_client_names(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'REASAN MARK GURA', 'folder_number' => 'SECRET-100']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'REALYN PRIVATE CRUZ', 'folder_number' => 'SECRET-200']);

        $this->actingAs($ci)
            ->getJson(route('client-folders.suggestions', ['q' => 'rea']))
            ->assertOk()
            ->assertExactJson(['suggestions' => ['REASAN MARK GURA']])
            ->assertDontSee('REALYN PRIVATE CRUZ')
            ->assertDontSee('SECRET-100')
            ->assertDontSee('SECRET-200');
    }

    public function test_administrator_receives_distinct_authorized_names_with_a_small_limit(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();

        ClientFolder::factory()->count(2)->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MATCH CLIENT A']);
        foreach (range('B', 'J') as $suffix) {
            ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => "MATCH CLIENT {$suffix}"]);
        }

        $response = $this->actingAs($administrator)
            ->getJson(route('client-folders.suggestions', ['q' => 'match']))
            ->assertOk();

        $suggestions = $response->json('suggestions');
        $this->assertCount(8, $suggestions);
        $this->assertSame($suggestions, array_values(array_unique($suggestions)));
    }

    public function test_short_or_empty_queries_return_no_suggestions(): void
    {
        $ci = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'REASAN MARK GURA']);

        $this->actingAs($ci)->getJson(route('client-folders.suggestions', ['q' => 'r']))
            ->assertExactJson(['suggestions' => []]);
        $this->actingAs($ci)->getJson(route('client-folders.suggestions'))
            ->assertExactJson(['suggestions' => []]);
    }
}
