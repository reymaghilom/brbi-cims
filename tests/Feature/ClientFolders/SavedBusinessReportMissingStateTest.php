<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedBusinessReportMissingStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_saved_business_with_active_report_opens_the_exact_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->business($ci, $folder, null, 'Applicant Active Business');
        $reportId = $source->businessReport->id;

        $managePage = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertSee('Update Business Report for Applicant Active Business')
            ->assertDontSee('Recreate Business Report for Applicant Active Business');

        $editUrl = $this->businessReportModalUrl($managePage->getContent(), 'Applicant Active Business');
        $this->assertSame(route('client-folders.income-sources.edit', [$folder, $source]), $editUrl);

        $this->actingAs($ci)->get($editUrl)
            ->assertOk()
            ->assertSee('Applicant Active Business')
            ->assertDontSee('No active Business Report');

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            $this->businessPayload($source, null, 'Applicant Updated Business'),
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_reports', ['id' => $reportId, 'income_source_id' => $source->id, 'business_name' => 'Applicant Updated Business']);
        $this->assertSame(1, $source->businessReport()->count());
    }

    public function test_applicant_saved_business_without_report_recreates_it_on_the_same_income_source(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->business($ci, $folder, null, 'Applicant Missing Business');
        $source->businessReport()->firstOrFail()->forceDelete();

        // A Report-less business is not a saved Business Report — it must not appear on the Saved
        // Businesses page at all (no ghost/leftover row, no "Recreate" action there either).
        $editUrl = route('client-folders.income-sources.edit', [$folder, $source]);
        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('data-business-name="Applicant Missing Business"', false)
            ->assertDontSee('Recreate Business Report', false)
            ->assertDontSee('Update Business Report for Applicant Missing Business');

        $this->actingAs($ci)->get($editUrl)
            ->assertOk()
            ->assertSee('No active Business Report')
            ->assertSee('Create Business Report');

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            $this->businessPayload($source),
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, IncomeSource::withTrashed()->whereKey($source->id)->count());
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'client_folder_id' => $folder->id, 'co_maker_id' => null, 'deleted_at' => null]);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'business_name' => 'Applicant Recreated Business']);
    }

    public function test_co_maker_active_and_missing_report_states_keep_exact_person_and_business_scope(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        $activeSource = $this->business($ci, $folder, $coMakerA, 'Co-Maker A Active Business');
        $activeReportId = $activeSource->businessReport->id;
        $missingSource = $this->business($ci, $folder, $coMakerA, 'Co-Maker A Missing Business');
        $otherSource = $this->business($ci, $folder, $coMakerB, 'Co-Maker B Business');
        $missingSource->businessReport()->firstOrFail()->forceDelete();
        $personParams = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];

        $managePage = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', [$folder] + $personParams))
            ->assertOk()
            ->assertSee('Update Business Report for Co-Maker A Active Business')
            ->assertDontSee('data-business-name="Co-Maker A Missing Business"', false)
            ->assertDontSee('Recreate Business Report', false)
            ->assertDontSee('Co-Maker B Business');

        $activeEditUrl = $this->businessReportModalUrl($managePage->getContent(), 'Co-Maker A Active Business');
        $this->assertSame(route('client-folders.income-sources.edit', [$folder, $activeSource] + $personParams), $activeEditUrl);
        $this->actingAs($ci)->get($activeEditUrl)
            ->assertOk()
            ->assertSee('Co-Maker A Active Business')
            ->assertDontSee('Co-Maker B Business');

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $activeSource]))
            ->assertNotFound();
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $otherSource] + $personParams))
            ->assertNotFound();

        $editUrl = route('client-folders.income-sources.edit', [$folder, $missingSource] + $personParams);
        $this->actingAs($ci)->get($editUrl)
            ->assertOk()
            ->assertSee('No active Business Report')
            ->assertSee('Create Business Report')
            ->assertDontSee('Co-Maker A Active Business')
            ->assertDontSee('Co-Maker B Business');

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $activeSource]),
            $this->businessPayload($activeSource, $coMakerA, 'Co-Maker A Updated Business'),
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $missingSource]),
            $this->businessPayload($missingSource, $coMakerA),
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_reports', ['income_source_id' => $missingSource->id, 'business_name' => 'Co-Maker Recreated Business']);
        $this->assertDatabaseHas('business_reports', ['id' => $activeReportId, 'income_source_id' => $activeSource->id, 'business_name' => 'Co-Maker A Updated Business']);
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $otherSource->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $missingSource->id, 'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id, 'deleted_at' => null]);
    }

    public function test_hard_deleted_report_content_never_leaks_and_a_fresh_save_recreates_it_cleanly(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->business($ci, $folder, null, 'Recycled Report Business');
        $report = $source->businessReport()->firstOrFail();
        $report->update(['report_remarks' => 'DELETED REPORT PRIVATE CONTENT']);
        $report->delete();

        $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))
            ->assertOk()
            ->assertDontSee('data-business-name="Recycled Report Business"', false)
            ->assertDontSee('Recreate Business Report', false)
            ->assertDontSee('Update Business Report for Recycled Report Business');

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()
            ->assertDontSee('DELETED REPORT PRIVATE CONTENT');

        $this->assertDatabaseMissing('business_reports', ['id' => $report->id]);

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            $this->businessPayload($source, null, 'Freshly Recreated Report'),
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'business_name' => 'Freshly Recreated Report']);
        $this->assertSame(1, $source->businessReport()->count());
    }

    public function test_hard_deleting_a_co_makers_report_never_touches_the_applicants_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $this->coMaker($folder, 'Recycled Co-Maker');
        $source = $this->business($ci, $folder, $coMaker, 'Co-Maker Recycled Business');
        $applicantSource = $this->business($ci, $folder, null, 'Unaffected Applicant Business');
        $report = $source->businessReport()->firstOrFail();
        $report->delete();
        $personParams = ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];

        $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source] + $personParams))
            ->assertOk()
            ->assertDontSee('Unaffected Applicant Business');

        $this->actingAs($ci)->put(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            $this->businessPayload($source, $coMaker, 'Co-Maker Report Recreated'),
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('business_reports', ['income_source_id' => $source->id, 'business_name' => 'Co-Maker Report Recreated']);
        $this->assertSame(1, $source->businessReport()->count());
        $this->assertDatabaseHas('business_reports', ['income_source_id' => $applicantSource->id]);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id, 'client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'deleted_at' => null]);
    }

    /**
     * A genuinely saved/active business — CreateIncomeSource alone only leaves a revision-1 shell
     * (see IncomeSourceController::dedicatedSources()'s $requireReport), which no longer counts as
     * an actual saved Business Report; this helper also runs SaveBusinessIncomeSource so callers
     * get the real "active report" state their assertions expect.
     */
    private function business(User $ci, ClientFolder $folder, ?CoMaker $coMaker, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        $source = app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ]);

        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, [
            'intent' => 'stay',
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->revision,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'properties' => [],
            'tenants' => [],
        ]);

        return $source->fresh();
    }

    private function businessReportModalUrl(string $html, string $businessName): string
    {
        $label = preg_quote('Update Business Report for '.$businessName, '/');
        $this->assertSame(1, preg_match('/<a[^>]+data-business-report-url="([^"]+)"[^>]+aria-label="'.$label.'"/', $html, $match));

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return $folder->coMakers()->create(['full_name' => $name]);
    }

    private function businessPayload(IncomeSource $source, ?CoMaker $coMaker = null, ?string $businessName = null): array
    {
        return [
            'intent' => 'stay',
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->revision,
            'source_name' => $source->source_name,
            'business_name' => $businessName ?? ($coMaker ? 'Co-Maker Recreated Business' : 'Applicant Recreated Business'),
            'report_category' => 'Retail',
            'main_business_address' => 'Exact Business Address',
            'start_date' => '2026-08-28',
            'registered_owner' => 'Exact Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
        ];
    }
}
