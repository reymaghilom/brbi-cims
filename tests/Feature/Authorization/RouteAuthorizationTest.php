<?php

namespace Tests\Feature\Authorization;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_investigator_cannot_access_another_investigators_folder_by_direct_url(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        $ownFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id]);

        $this->actingAs($ci)->get(route('client-folders.show', $ownFolder))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.show', $otherFolder))->assertForbidden();
    }

    public function test_ci_folder_index_does_not_leak_other_assignments(): void
    {
        $ci = User::factory()->create();
        $otherCi = User::factory()->create();
        ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'VISIBLE CLIENT']);
        ClientFolder::factory()->create(['assigned_ci_id' => $otherCi->id, 'display_name' => 'HIDDEN CLIENT']);

        $this->actingAs($ci)->get(route('client-folders.index'))
            ->assertOk()
            ->assertSee('VISIBLE CLIENT')
            ->assertDontSee('HIDDEN CLIENT');
    }

    public function test_administrator_can_access_active_folder_but_deleted_folder_is_not_in_normal_contents_route(): void
    {
        $administrator = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create();
        $deletedFolder = ClientFolder::factory()->create();
        $deletedFolder->delete();

        $this->actingAs($administrator)->get(route('client-folders.show', $folder))->assertOk();
        $this->actingAs($administrator)->get(route('client-folders.show', $deletedFolder->id))->assertNotFound();
    }

    public function test_scoped_binding_rejects_a_forged_nested_income_source_id(): void
    {
        $ci = User::factory()->create();
        $firstFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $secondFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $incomeSource = IncomeSource::factory()->create(['client_folder_id' => $secondFolder->id]);

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.show', [$firstFolder, $incomeSource]))
            ->assertNotFound();
    }

    public function test_credit_investigator_cannot_access_admin_routes(): void
    {
        $ci = User::factory()->create();
        $subject = User::factory()->create();

        $this->actingAs($ci)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($ci)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($ci)->get(route('admin.audit-logs.index'))->assertForbidden();
        $this->actingAs($ci)->get(route('admin.users.edit', $subject))->assertForbidden();
        $this->actingAs($ci)->post(route('admin.users.password.reset', $subject))->assertForbidden();
    }
}
