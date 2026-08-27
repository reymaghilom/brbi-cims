<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoMakerAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_new_co_maker_can_be_created_with_an_address_and_it_is_stored(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'address' => 'Zone 3, Barangay Puerto, Cagayan de Oro City, Misamis Oriental',
        ])->assertRedirect();

        $coMaker = $folder->coMakers()->firstOrFail();
        $this->assertSame('Zone 3, Barangay Puerto, Cagayan de Oro City, Misamis Oriental', $coMaker->address);
    }

    public function test_existing_co_maker_address_is_available_for_the_edit_modal_to_load(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->coMakers()->create(['full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Purok 5, Bulua']);

        // The edit modal is pre-filled entirely client-side from this data attribute (see
        // app.js's co-maker edit-trigger handler), so its presence in the rendered page is what
        // actually matters here, not any server-rendered form value.
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('data-co-maker-address="Purok 5, Bulua"', false);
    }

    public function test_address_only_update_is_saved(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Old Address']);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'New Corrected Address',
        ])->assertRedirect();

        $this->assertSame('New Corrected Address', $coMaker->fresh()->address);
    }

    public function test_name_only_update_does_not_clear_the_existing_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Keep This Address']);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz Jr.', 'address' => 'Keep This Address',
        ])->assertRedirect();

        $this->assertSame('Dela Cruz Jr.', $coMaker->fresh()->last_name);
        $this->assertSame('Keep This Address', $coMaker->fresh()->address);
    }

    public function test_blank_address_is_rejected_on_create_and_on_edit(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
        ])->assertSessionHasErrors('address');
        $this->assertDatabaseCount('co_makers', 0);

        // A legacy co-maker saved (before this feature existed) with a blank address must be
        // forced to get a real one the next time it's edited — the field cannot be skipped.
        $legacyCoMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Legacy Person']);
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $legacyCoMaker->id, 'first_name' => 'Legacy', 'last_name' => 'Person',
        ])->assertSessionHasErrors('address');
        $this->assertNull($legacyCoMaker->fresh()->address);
    }

    public function test_residence_check_resolves_the_newly_saved_co_maker_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Resolved Co-Maker Address',
        ])->assertRedirect();
        $coMaker = $folder->coMakers()->firstOrFail();
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(),
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertRedirect();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('Resolved Co-Maker Address', $check->location);
    }

    public function test_multiple_co_makers_can_each_keep_their_own_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'First', 'last_name' => 'Maker', 'address' => 'First Maker Address',
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Second', 'last_name' => 'Maker', 'address' => 'Second Maker Address',
        ])->assertRedirect();

        $this->assertSame(2, $folder->coMakers()->count());
        $addresses = $folder->coMakers()->orderBy('id')->pluck('address')->all();
        $this->assertSame(['First Maker Address', 'Second Maker Address'], $addresses);
    }
}
