<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditingPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_heartbeat_reports_no_other_editors_on_the_first_ping(): void
    {
        [$ci, $source] = $this->createGeneralSource();

        $this->actingAs($ci)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk()
            ->assertJson(['other_editors' => []]);
    }

    public function test_a_second_ci_heartbeat_reveals_the_first_ci_as_an_other_editor(): void
    {
        [$first, $source] = $this->createGeneralSource();
        $second = User::factory()->create();

        $this->actingAs($first)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk()->assertJson(['other_editors' => []]);

        $response = $this->actingAs($second)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk();

        $this->assertSame([$first->full_name], collect($response->json('other_editors'))->pluck('name')->all());

        // A CI's own heartbeat must never list themselves as an "other" editor.
        $this->actingAs($first)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk()
            ->assertJsonPath('other_editors.0.name', $second->full_name)
            ->assertJsonCount(1, 'other_editors');
    }

    public function test_three_concurrent_editors_are_each_shown_the_other_two_but_never_themselves(): void
    {
        [$first, $source] = $this->createGeneralSource();
        $second = User::factory()->create();
        $third = User::factory()->create();

        $this->actingAs($first)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $this->actingAs($second)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $response = $this->actingAs($third)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();

        $names = collect($response->json('other_editors'))->pluck('name')->sort()->values()->all();
        $this->assertSame(collect([$first->full_name, $second->full_name])->sort()->values()->all(), $names);
        $this->assertNotContains($third->full_name, $names);
    }

    public function test_repeated_heartbeats_from_the_same_user_do_not_duplicate_their_presence_entry(): void
    {
        [$first, $source] = $this->createGeneralSource();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $this->actingAs($first)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $this->actingAs($first)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();

        $this->actingAs($second)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk()
            ->assertJsonCount(1, 'other_editors');
    }

    public function test_presence_entries_expire_after_the_ttl_without_a_release(): void
    {
        [$first, $source] = $this->createGeneralSource();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();

        $this->travel(91)->seconds();

        $this->actingAs($second)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk()
            ->assertJson(['other_editors' => []]);
    }

    public function test_release_only_clears_the_requesting_users_own_presence(): void
    {
        [$first, $source] = $this->createGeneralSource();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();

        // A different CI releasing must not clear the first CI's presence entry.
        $this->actingAs($second)->postJson(route('editing-presence.release'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $response = $this->actingAs($second)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $this->assertSame([$first->full_name], collect($response->json('other_editors'))->pluck('name')->all());

        $this->actingAs($first)->postJson(route('editing-presence.release'), ['type' => 'income_source', 'id' => $source->id])->assertOk();
        $this->actingAs($second)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id])
            ->assertOk()->assertJson(['other_editors' => []]);
    }

    public function test_heartbeat_rejects_an_unknown_type_and_a_nonexistent_record(): void
    {
        [$ci, $source] = $this->createGeneralSource();

        $this->actingAs($ci)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'not_a_real_type', 'id' => $source->id])
            ->assertStatus(422);

        $this->actingAs($ci)
            ->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => 999999])
            ->assertNotFound();
    }

    /** @return array{0: User, 1: IncomeSource} */
    private function createGeneralSource(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'general_income_sources')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Income Source',
        ]);

        return [$ci, $source];
    }
}
