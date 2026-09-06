<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CI Date / "Start Date of CI" (start_date) is required for EVERY active selectable Business
 * Report template, without exception.
 *
 * Requiredness is deliberately not conditional on the template's schema profile flag, its type, or
 * whether it renders a visible Business Name input — a Business Report has no meaning without the
 * date the investigation was actually conducted. The catalogue is enumerated dynamically from
 * IncomeSourceTemplate::activeBusiness() so a template added later is audited automatically rather
 * than silently exempted.
 */
class BusinessReportCiDateRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_every_active_business_template_rejects_a_blank_ci_date_and_accepts_a_valid_one(): void
    {
        $ci = User::factory()->create();
        $templates = IncomeSourceTemplate::query()->activeBusiness()->get();

        $this->assertGreaterThan(0, $templates->count(), 'The active business catalogue must not be empty.');

        foreach ($templates as $template) {
            $folder = $this->folder($ci);

            // Blank CI Date is refused for this exact template, and nothing is created.
            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, null))
                ->assertSessionHasErrors('start_date');
            $this->assertSame(0, $folder->incomeSources()->count(), $template->template_type.' must not save without a CI Date.');

            // The same payload with a valid, non-future CI Date passes that rule.
            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, '2026-09-01'))
                ->assertSessionDoesntHaveErrors('start_date');
            $this->assertSame(
                '2026-09-01',
                $folder->incomeSources()->sole()->businessReport->start_date->toDateString(),
                $template->template_type.' stores the CI Date it was given.',
            );
        }
    }

    public function test_the_audited_catalogue_covers_the_standard_templates_and_other_business(): void
    {
        $types = IncomeSourceTemplate::query()->activeBusiness()->pluck('template_type');

        // Enumerated, never hardcoded as business logic — this only records what the catalogue
        // currently holds so an accidental drop-out of a template is visible.
        $this->assertContains('other_business_source_of_income', $types->all());
        $this->assertGreaterThanOrEqual(19, $types->count());
        $this->assertSame($types->count(), $types->unique()->count());
    }

    public function test_a_future_ci_date_is_rejected(): void
    {
        $ci = User::factory()->create();

        foreach (['retail_grocery_water_refilling', 'farming_corn', 'other_business_source_of_income'] as $templateType) {
            $folder = $this->folder($ci);
            $template = $this->template($templateType);

            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, now()->addDay()->toDateString()))
                ->assertSessionHasErrors('start_date');

            $this->assertSame(0, $folder->incomeSources()->count(), $templateType.' must not save with a future CI Date.');
        }
    }

    public function test_the_ci_date_input_renders_as_required_on_the_business_report_form(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('farming_corn');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, '2026-09-01'))
            ->assertSessionHasNoErrors();
        $source = $folder->incomeSources()->sole();

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();

        // The one existing shared header field — never a second CI Date input.
        $this->assertSame(1, substr_count($html, 'name="start_date"'));
        $this->assertMatchesRegularExpression('/<input id="start_date"[^>]*\srequired/', $html);
        $this->assertStringContainsString('START DATE OF CI:', $html);
    }

    // ---------------------------------------------------------------------
    // Business Name requiredness, alongside the same rule
    // ---------------------------------------------------------------------

    public function test_templates_with_a_visible_business_name_input_require_the_ci_to_enter_one(): void
    {
        $ci = User::factory()->create();
        $audited = 0;

        foreach (IncomeSourceTemplate::query()->activeBusiness()->get() as $template) {
            // The form renders a real Business Name input exactly when the template has no derived
            // default of its own — the same condition _business-form-body.blade.php branches on.
            if (IncomeSourceTemplate::defaultBusinessNameFor($template->template_type) !== null) {
                continue;
            }
            $audited++;
            $folder = $this->folder($ci);

            // Genuinely blank: the visible input feeds both source_name and business_name (see
            // StoreIncomeSourceRequest::prepareForValidation()'s approved fallback between the two),
            // so leaving the field empty submits both empty.
            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, '2026-09-01', businessName: null) + [])
                ->assertSessionHasErrors('business_name');
            $this->assertSame(0, $folder->incomeSources()->count());

            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, '2026-09-01', businessName: 'Reymark Store'))
                ->assertSessionHasNoErrors();
            $this->assertSame('Reymark Store', $folder->incomeSources()->sole()->business_name);
        }

        $this->assertGreaterThan(0, $audited, 'At least one template must render a visible Business Name input.');
    }

    public function test_the_six_derived_name_templates_still_save_their_mapped_default_and_still_require_a_ci_date(): void
    {
        $ci = User::factory()->create();

        foreach (IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES as $templateType => $expected) {
            $folder = $this->folder($ci);
            $template = $this->template($templateType);

            // Still required, even though these templates render no Business Name input at all.
            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, null))
                ->assertSessionHasErrors('start_date');

            $this->actingAs($ci)
                ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, '2026-09-01'))
                ->assertSessionHasNoErrors();

            $source = $folder->incomeSources()->sole();
            $this->assertSame($expected, $source->business_name, $templateType.' keeps its mapped default.');
            $this->assertSame($expected, $source->businessReport->business_name);
            $this->assertSame('2026-09-01', $source->businessReport->start_date->toDateString());
        }

        $this->assertSame('Remittance', IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES['remittance_income']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function template(string $templateType): IncomeSourceTemplate
    {
        return IncomeSourceTemplate::query()->where('template_type', $templateType)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(IncomeSourceTemplate $template, ?string $startDate, ?string $businessName = 'Audited Business'): array
    {
        $payload = [
            'income_source_template_id' => $template->id,
            'source_name' => $businessName,
            'business_name' => $businessName,
            'report_category' => 'Audited Category',
            'main_business_address' => 'Audited Address',
            'start_date' => $startDate,
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
            'intent' => 'stay',
        ];

        // Other Business is identified by its own category set, which its save legitimately requires.
        if ($template->template_type === 'other_business_source_of_income') {
            $payload['template_data'] = ['fields' => ['income_sources' => ['Sari-Sari']]];
            $payload['report_remarks'] = 'Audited details.';
        }

        return $payload;
    }
}
