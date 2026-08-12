<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\AddressType;
use App\Enums\ClientFolderStatus;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientAddress;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientInformationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_access_is_limited_to_administrator_and_assigned_ci(): void
    {
        $admin = User::factory()->administrator()->create();
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assigned->id]);

        $this->actingAs($admin)->get(route('client-folders.client-information.edit', $folder))->assertOk();
        $this->actingAs($assigned)->get(route('client-folders.client-information.edit', $folder))->assertOk();
        $this->actingAs($other)->get(route('client-folders.client-information.edit', $folder))->assertForbidden();
        $this->actingAs($other)->put(route('client-folders.client-information.update', $folder), $this->payload())->assertForbidden();
    }

    public function test_soft_deleted_folder_is_unavailable(): void
    {
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create();
        $folder->delete();

        $this->actingAs($admin)->get(route('client-folders.client-information.edit', $folder->id))->assertNotFound();
        $this->actingAs($admin)->put(route('client-folders.client-information.update', $folder->id), $this->payload())->assertNotFound();
    }

    public function test_save_creates_one_record_normalizes_identity_and_creates_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload() + ['intent' => 'stay'];
        $payload['first_name'] = '  María   Luisa ';
        $payload['last_name'] = ' de la Cruz ';

        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $payload)
            ->assertRedirect(route('client-folders.client-information.edit', $folder))->assertSessionHas('status');

        $this->assertDatabaseCount('client_information', 1);
        $this->assertDatabaseHas('client_folders', ['id' => $folder->id, 'first_name' => 'MARÍA LUISA', 'last_name' => 'DE LA CRUZ', 'display_name' => 'DE LA CRUZ, MARÍA LUISA SANTOS']);
        $this->assertDatabaseHas('client_addresses', ['client_folder_id' => $folder->id, 'address_type' => 'present', 'city_municipality' => 'San Pablo City']);
    }

    public function test_repeated_save_updates_information_and_address_without_duplicates(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
        ClientAddress::create(['client_folder_id' => $folder->id, 'address_type' => AddressType::Present, 'address_line_1' => 'Old', 'country' => 'Philippines']);
        ClientAddress::create(['client_folder_id' => $folder->id, 'address_type' => AddressType::Present, 'address_line_1' => 'Duplicate old row', 'country' => 'Philippines']);
        $payload = $this->payload();
        $payload['addresses']['present']['address_line_1'] = 'Updated address';

        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $payload)->assertRedirect();

        $this->assertSame(1, ClientInformation::whereBelongsTo($folder)->count());
        $this->assertSame(1, ClientAddress::whereBelongsTo($folder)->where('address_type', AddressType::Present)->count());
        $this->assertDatabaseHas('client_addresses', ['client_folder_id' => $folder->id, 'address_line_1' => 'Updated address']);
        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'client_information.updated']);
    }

    public function test_disabled_address_is_removed_without_removing_enabled_type(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        foreach ([AddressType::Present, AddressType::Business] as $type) {
            ClientAddress::create(['client_folder_id' => $folder->id, 'address_type' => $type, 'address_line_1' => 'Address', 'country' => 'Philippines']);
        }
        $payload = $this->payload();
        $payload['addresses']['business'] = ['enabled' => '0'];

        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $payload)->assertRedirect();
        $this->assertDatabaseMissing('client_addresses', ['client_folder_id' => $folder->id, 'address_type' => 'business']);
        $this->assertDatabaseHas('client_addresses', ['client_folder_id' => $folder->id, 'address_type' => 'present']);
    }

    public function test_validation_rejects_invalid_domain_values(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        $payload['birth_date'] = now()->addDay()->toDateString();
        $payload['addresses']['present']['google_maps_link'] = 'not-a-url';
        $payload['addresses']['business'] = ['enabled' => '1', 'address_line_1' => 'Shop', 'is_primary' => '1'];

        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $payload)
            ->assertSessionHasErrors(['birth_date', 'addresses.present.google_maps_link', 'addresses']);
        $this->assertDatabaseCount('client_information', 0);
    }

    public function test_save_updates_completion_progress_overview_and_safe_audit(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $this->payload())->assertRedirect();

        $this->assertSame(RecordState::Complete, ClientInformation::whereBelongsTo($folder)->sole()->completion_state);
        $this->assertDatabaseHas('client_completion_results', ['client_folder_id' => $folder->id, 'is_satisfied' => true, 'explanation_key' => 'client_information.complete']);
        $folder->refresh();
        $this->assertEquals(16.67, $folder->progress_percent);
        $this->assertSame(ClientFolderStatus::OnProgress, $folder->status);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertDontSee('Client profile record available.')
            ->assertDontSee(route('client-folders.client-information.edit', $folder), false)
            ->assertSee('16.67')
            ->assertSee('CI / BI Report');

        $audit = AuditLog::where('action', 'client_information.created')->sole();
        $encoded = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertSame('client_information', $audit->module);
        $this->assertStringNotContainsString('09171234567', $encoded);
        $this->assertStringNotContainsString('123 Main Street', $encoded);
    }

    public function test_missing_required_data_remains_draft_and_form_is_responsive_semantic(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $payload = $this->payload();
        $payload['birth_date'] = null;
        $this->actingAs($ci)->put(route('client-folders.client-information.update', $folder), $payload)->assertRedirect();
        $this->assertSame(RecordState::Draft, ClientInformation::whereBelongsTo($folder)->sole()->completion_state);
        $this->assertSame(ClientFolderStatus::OnProgress, $folder->fresh()->status);

        $response = $this->actingAs($ci)->get(route('client-folders.client-information.edit', $folder))->assertOk();
        $response->assertSee('<fieldset', false)->assertSee('sm:grid-cols-2', false)->assertSee('sticky bottom-4', false)
            ->assertSee('Present / Current Address')->assertDontSee('Place of Birth')->assertDontSee('Nationality')->assertDontSee('Gender');
    }

    private function payload(): array
    {
        return [
            'first_name' => 'JUAN', 'middle_name' => 'SANTOS', 'last_name' => 'DELA CRUZ', 'suffix' => null,
            'birth_date' => '1990-05-12', 'civil_status' => 'Married', 'contact_number' => '09171234567', 'email' => 'juan@example.test',
            'spouse_name' => 'Maria Dela Cruz', 'dependents_count' => 2, 'length_of_stay_months' => 48,
            'home_ownership' => 'Owned', 'home_condition' => 'Good', 'material_cost_level' => 'Moderate',
            'living_condition' => 'Stable', 'reputation' => 'Good', 'lifestyle' => 'Modest', 'vehicles_owned' => 'One motorcycle',
            'other_residences' => null, 'barangay_findings' => 'Residency confirmed.', 'court_background_summary' => 'No declared cases.', 'other_remarks' => null,
            'addresses' => ['present' => [
                'enabled' => '1', 'address_line_1' => '123 Main Street', 'address_line_2' => null, 'barangay' => 'San Roque',
                'city_municipality' => 'San Pablo City', 'province' => 'Laguna', 'postal_code' => '4000', 'country' => 'Philippines',
                'google_maps_link' => 'https://maps.google.com/example', 'is_primary' => '1', 'length_of_stay_months' => 48,
            ]],
        ];
    }
}
