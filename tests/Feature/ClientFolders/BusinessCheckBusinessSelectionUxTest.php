<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCheckBusinessSelectionUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_existing_applicant_business_makes_selection_primary_and_gates_add_another(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $this->createBusiness($folder, 'Applicant Store');

        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Applicant Store')
            ->assertSee('An existing business is already available. Please select it first to avoid duplicate entries.')
            ->assertSee('Add New Business')
            ->assertSee('data-business-check-add-new', false)
            ->assertSee('disabled data-lock-when-existing="true"', false)
            ->assertSee('data-business-check-add-another', false)
            ->assertSee('Add another business');

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('Only add another business if it is a genuinely separate business or income source.', $script);
        $this->assertStringContainsString('if (!confirmed) return;', $script);
    }

    public function test_applicant_without_a_business_keeps_the_normal_add_new_business_flow(): void
    {
        [$ci, $folder] = $this->folderWithCi();

        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Add New Business')
            ->assertSee('data-business-check-add-new', false)
            ->assertDontSee('data-lock-when-existing', false)
            ->assertDontSee('data-business-check-add-another', false)
            ->assertDontSee('An existing business is already available. Please select it first to avoid duplicate entries.');
    }

    /** @return array{User, ClientFolder} */
    private function folderWithCi(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        return [$ci, $folder];
    }

    private function createBusiness(ClientFolder $folder, string $name, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $name.' Address',
            'report_category' => $template->template_type,
        ]);

        return $source;
    }
}
