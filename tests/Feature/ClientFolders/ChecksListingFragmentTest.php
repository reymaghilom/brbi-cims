<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The async listing fragment behind the Residence & Business Report page's in-place table refresh.
 *
 * A confirmed Residence/Business Check save closes the modal and re-fetches THIS fragment for the
 * page's current URL instead of reloading the whole page (app.js: refreshChecksListing). These
 * tests cover the server half of that contract — that the fragment is the listing alone, built from
 * the same authoritative query as the page, and scoped to the exact same active person.
 */
class ChecksListingFragmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_the_async_request_returns_only_the_checks_listing_without_the_app_layout(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $response = $this->fragment($ci, $folder);

        $response->assertOk();
        $response->assertSee('data-checks-listing', false);
        $response->assertSee('Residence Check', false);
        $response->assertSee('Business Checks', false);
        // The whole point of the fragment: no sidebar, no header, no <html> document around it.
        $response->assertDontSee('id="primary-sidebar"', false);
        $response->assertDontSee('<!DOCTYPE html', false);
        // The page-level batch forms and the shared modal deliberately live OUTSIDE the region, so
        // they survive the swap rather than being duplicated by it.
        $response->assertDontSee('id="check-batch-print-form"', false);
        $response->assertDontSee('data-check-report-dialog', false);
    }

    public function test_a_normal_browser_request_still_returns_the_full_page_containing_the_listing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder));

        $response->assertOk();
        $response->assertSee('id="primary-sidebar"', false);
        $response->assertSee('data-checks-listing', false);
        $response->assertSee('id="check-batch-print-form"', false);
        // Opting this page into the stay-on-page modal is what routes a confirmed save into the
        // in-place refresh instead of the old full-page reload.
        $response->assertSee('data-check-report-stay', false);
    }

    public function test_a_created_residence_check_appears_in_the_refreshed_fragment(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $this->fragment($ci, $folder)->assertSee('No Residence Check saved yet');

        $check = $this->createResidenceCheck($ci, $folder, 'Blk 7 Lot 12, Sta. Maria, Bulacan');

        $response = $this->fragment($ci, $folder);
        $response->assertDontSee('No Residence Check saved yet');
        $response->assertSee('Blk 7 Lot 12, Sta. Maria, Bulacan');
        $this->assertSame(1, $this->countRowsFor($response->getContent(), 'data-residence-check-select', $check->id));
    }

    public function test_an_edited_residence_check_keeps_its_row_id_and_is_not_duplicated(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->createResidenceCheck($ci, $folder, 'Old Residence Location');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'location' => 'New Residence Location',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJson(['result' => 'success']);

        $response = $this->fragment($ci, $folder);
        $response->assertSee('New Residence Location');
        $response->assertDontSee('Old Residence Location');
        $this->assertSame(1, $folder->residenceChecks()->count());
        $this->assertSame(1, $this->countRowsFor($response->getContent(), 'data-residence-check-select', $check->id));
    }

    public function test_a_linked_business_check_renders_its_exact_income_source_and_is_not_duplicated_after_an_edit(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $source = $this->incomeSource($folder);
        $check = $this->createBusinessCheck($ci, $folder, 'Poblacion Branch', $source);

        $this->assertSame($source->id, $check->income_source_id);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Edited Business Location',
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJson(['result' => 'success']);

        $response = $this->fragment($ci, $folder);
        $response->assertSee('Edited Business Location');
        $this->assertSame(1, $folder->businessChecks()->count());
        $this->assertSame($source->id, $check->fresh()->income_source_id);
        $this->assertSame(1, $this->countRowsFor($response->getContent(), 'data-business-check-select', $check->id));
    }

    public function test_a_standalone_business_check_with_a_null_income_source_renders_safely(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $check = $this->createBusinessCheck($ci, $folder, 'Standalone Stall Location', null, 'Standalone Carinderia');

        $this->assertNull($check->income_source_id);

        $response = $this->fragment($ci, $folder);
        $response->assertOk();
        $response->assertSee('Standalone Carinderia');
        $response->assertSee('Standalone Stall Location');
        $this->assertSame(1, $this->countRowsFor($response->getContent(), 'data-business-check-select', $check->id));
    }

    public function test_the_fragment_never_leaks_rows_across_the_applicant_and_a_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'address' => 'Co-Maker Address']);

        $applicantCheck = $this->createResidenceCheck($ci, $folder, 'Applicant Residence Location');
        $coMakerCheck = $this->createResidenceCheck($ci, $folder, 'Co-Maker Residence Location', $coMaker->id);

        $applicantFragment = $this->fragment($ci, $folder);
        $applicantFragment->assertSee('Applicant Residence Location');
        $applicantFragment->assertDontSee('Co-Maker Residence Location');
        $this->assertSame(0, $this->countRowsFor($applicantFragment->getContent(), 'data-residence-check-select', $coMakerCheck->id));

        $coMakerFragment = $this->fragment($ci, $folder, ['person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $coMakerFragment->assertSee('Co-Maker Residence Location');
        $coMakerFragment->assertDontSee('Applicant Residence Location');
        $this->assertSame(0, $this->countRowsFor($coMakerFragment->getContent(), 'data-residence-check-select', $applicantCheck->id));
    }

    /** Counts the rendered selection checkboxes carrying one exact record id — a duplicated row would count twice. */
    private function countRowsFor(string $html, string $marker, int $id): int
    {
        return preg_match_all('/'.preg_quote($marker, '/').' value="'.$id.'"/', $html);
    }

    private function fragment(User $ci, ClientFolder $folder, array $params = [])
    {
        return $this->actingAs($ci)->get(
            route('client-folders.residence-business.edit', [$folder] + $params),
            ['X-Requested-With' => 'XMLHttpRequest'],
        );
    }

    private function createResidenceCheck(User $ci, ClientFolder $folder, string $location, ?int $coMakerId = null): ResidenceCheck
    {
        $existing = $folder->residenceChecks()->pluck('id')->all();
        $personParams = $coMakerId ? ['person' => 'co-maker', 'co_maker_id' => $coMakerId] : [];

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', [$folder] + $personParams), [
            'co_maker_id' => $coMakerId,
            // Explicit rather than leaning on the Applicant-only CI/BI prefill — a Co-Maker has no
            // CI/BI Report of its own to default this from.
            'ci_date' => now()->toDateString(),
            'location' => $location,
            'photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->residenceChecks()->whereNotIn('id', $existing)->firstOrFail();
    }

    private function createBusinessCheck(User $ci, ClientFolder $folder, string $location, ?IncomeSource $source = null, ?string $businessName = null): BusinessCheck
    {
        $existing = $folder->businessChecks()->pluck('id')->all();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), array_filter([
            'income_source_id' => $source?->id,
            'business_name' => $businessName,
            'ci_date' => now()->toDateString(),
            'location' => $location,
            'photo_groups' => [[
                'caption' => 'Storefront',
                'photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)],
            ]],
        ]))->assertSessionHasNoErrors();

        return $folder->businessChecks()->whereNotIn('id', $existing)->firstOrFail();
    }

    private function incomeSource(ClientFolder $folder): IncomeSource
    {
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
            'main_business_address' => 'Poblacion Branch',
            'report_category' => 'retail_grocery_water_refilling',
        ]);

        return $source;
    }

    private function folder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
