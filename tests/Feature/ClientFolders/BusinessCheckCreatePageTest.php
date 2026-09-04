<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression cover for the Business Check business selector after the Photos & Videos removal: it
 * used to eager-load and filter on IncomeSource::businessDocumentation(), a relation that no longer
 * exists, so opening the form threw BadMethodCallException. Business Check belongs to the Business
 * Report / IncomeSource workflow and must never reach for the retired documentation feature again.
 */
class BusinessCheckCreatePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_create_page_loads_and_never_queries_the_removed_business_documentation_relation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $selectable = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', $folder))
            ->assertOk()
            ->assertSee('Business Check')
            ->assertSee('Sari-Sari Store');

        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'business_documentation') || str_contains($sql, 'residence_business_documentations'),
        )));

        $businesses = collect($this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->viewData('businesses'));
        $this->assertSame([$selectable->id], $businesses->pluck('id')->all());
        $this->assertSame('Poblacion, San Miguel, Bulacan', $businesses->firstOrFail()['location']);
    }

    public function test_an_existing_checks_own_business_remains_selectable_on_its_edit_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $source = $this->businessSource($folder, 'Checked Store', 'San Miguel, Bulacan');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Store.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        // A business that already owns a Check is filtered out of CREATE but must still appear on
        // its own EDIT page, or the form would have nothing selected.
        $create = collect($this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->viewData('businesses'));
        $this->assertSame([], $create->pluck('id')->all());

        $edit = collect($this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->viewData('businesses'));
        $this->assertSame([$source->id], $edit->pluck('id')->all());
    }

    public function test_the_selector_stays_isolated_between_the_applicant_and_each_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos']);
        $applicantSource = $this->businessSource($folder, 'Applicant Store', 'Applicant Address');
        $coMakerSource = $this->businessSource($folder, 'Co-Maker Store', 'Co-Maker Address', $coMaker->id);

        $applicantView = collect($this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->viewData('businesses'));
        $this->assertSame([$applicantSource->id], $applicantView->pluck('id')->all());

        $coMakerView = collect($this->actingAs($ci)
            ->get(route('client-folders.business-checks.create', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk()->viewData('businesses'));
        $this->assertSame([$coMakerSource->id], $coMakerView->pluck('id')->all());
    }

    private function folder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type,
            'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name, 'revision' => 2,
        ]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
