<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignedCiOptionLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_create_surfaces_hide_ci_assignment_and_creation_stays_unassigned(): void
    {
        $administrator = User::factory()->administrator()->create();
        $investigator = User::factory()->create(['full_name' => 'Exact Investigator']);
        $html = $this->actingAs($administrator)->get(route('client-folders.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="assigned_ci_id"', $html);
        $this->assertStringNotContainsString('Credit Investigator (optional)', $html);
        $this->assertStringNotContainsString('Leave unassigned', $html);
        $this->assertStringNotContainsString($investigator->full_name, $html);
        $this->assertStringNotContainsString('Client Folders are a shared workspace', $html);

        $modalHtml = $this->actingAs($administrator)->get(route('client-folders.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="assigned_ci_id"', $modalHtml);
        $this->assertStringNotContainsString('Leave unassigned', $modalHtml);
        $this->assertStringNotContainsString($investigator->full_name, $modalHtml);

        $this->actingAs($administrator)->post(route('client-folders.store'), [
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
        ])->assertSessionHasNoErrors();

        $folder = ClientFolder::sole();
        $this->assertNull($folder->assigned_ci_id);
        $this->assertSame($administrator->id, $folder->created_by);
    }
}
