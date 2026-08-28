<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessCheckAjaxSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_ajax_update_returns_the_payload_used_to_complete_the_modal_flow(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();

        $response = $this->ajaxPost($ci, $folder, $this->updateData($source, $check->id, [
            'remarks' => 'Updated through the modal.',
        ]));

        $response->assertOk()->assertJson([
            'result' => 'success',
            'message' => 'Business Check updated successfully.',
            'status_type' => 'success',
            'return_url' => route('client-folders.residence-business.edit', $folder),
        ]);
        $this->assertSame('Updated through the modal.', $check->fresh()->remarks);
    }

    public function test_ajax_no_change_returns_an_in_place_outcome(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();

        $this->ajaxPost($ci, $folder, $this->updateData($source, $check->id))
            ->assertOk()
            ->assertJson([
                'result' => 'no_change',
                'message' => 'Nothing changed. No updates were saved to the database.',
                'status_type' => 'info',
            ]);
    }

    public function test_ajax_validation_failure_returns_json_and_does_not_update_the_check(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();

        $response = $this->ajaxPost($ci, $folder, $this->updateData($source, $check->id, [
            'location' => '',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('location');
        $this->assertSame('Poblacion, San Miguel, Bulacan', $check->fresh()->location);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource, 3: \App\Models\BusinessCheck} */
    private function createBusinessCheck(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Sari-Sari Store',
            'business_name' => 'Sari-Sari Store',
        ]);
        $source->businessReport()->create([
            'business_name' => 'Sari-Sari Store',
            'main_business_address' => 'Poblacion, San Miguel, Bulacan',
            'report_category' => 'retail_grocery_water_refilling',
        ]);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [[
                'caption' => 'Storefront',
                'photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)],
            ]],
        ])->assertSessionHasNoErrors();

        return [$ci, $folder, $source, $folder->businessChecks()->firstOrFail()];
    }

    private function updateData(IncomeSource $source, int $checkId, array $overrides = []): array
    {
        return array_replace([
            'check_id' => $checkId,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
        ], $overrides);
    }

    private function ajaxPost(User $ci, ClientFolder $folder, array $data)
    {
        return $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $data, [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }
}
