<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Business Report encoding form's Business Name input and its BRANCH header field.
 *
 * Business Name renders straight away on every template that owns one — no click, no checkbox, no
 * JavaScript gate — and it carries no placeholder. The six templates listed in
 * IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES deliberately have no Business Name input at all:
 * for those the template IS the business identity and the name is derived rather than typed, which
 * the save and read paths both depend on.
 *
 * BRANCH keeps its exact field name, readonly treatment and value binding; its alignment is a CSS
 * concern only (see .business-report-header-branch in app.css).
 */
class BusinessNameAndBranchFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** TESTS 9, 10, 11 — visible on load, behind no condition, and with no placeholder. */
    public function test_business_name_renders_immediately_without_a_placeholder(): void
    {
        [$ci, $folder, $source] = $this->businessOnDedicatedTemplate('MEATSHOP OF JUAN');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()->getContent();

        $field = $this->inputTag($html, 'business_name');

        // Present in the initial response — nothing has to be clicked to reveal it.
        $this->assertStringContainsString('name="business_name"', $field);
        $this->assertStringContainsString('id="business_name"', $field);
        $this->assertStringNotContainsString('placeholder', $field);
        $this->assertStringNotContainsString('hidden', $field);

        // Its visible label is intact.
        $this->assertStringContainsString('for="business_name">Business Name:', $html);
    }

    /** TEST 12 — the saved value still renders, and the field keeps its existing binding. */
    public function test_business_name_still_renders_its_saved_value(): void
    {
        [$ci, $folder, $source] = $this->businessOnDedicatedTemplate('MEATSHOP OF JUAN');

        $field = $this->inputTag(
            $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent(),
            'business_name',
        );

        $this->assertStringContainsString('value="MEATSHOP OF JUAN"', $field);
        $this->assertStringContainsString('required', $field);
    }

    /**
     * The six derived-name templates still have no Business Name input, by design — the stored name
     * comes from DEFAULT_BUSINESS_NAMES, so introducing an input here would change what is saved.
     */
    public function test_the_derived_name_templates_still_have_no_business_name_input(): void
    {
        $this->assertCount(6, IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES);

        foreach (array_keys(IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES) as $templateType) {
            $schema = config('business-report-templates.'.$templateType.'.schema', []);
            $this->assertFalse($schema['profile'] ?? false, $templateType.' derives its business name.');
        }
    }

    /** TEST 18 — BRANCH keeps its exact name, readonly treatment and saved value. */
    public function test_the_branch_field_keeps_its_existing_data_behaviour(): void
    {
        [$ci, $folder, $source] = $this->businessOnDedicatedTemplate('MEATSHOP OF JUAN', 'MAIN BRANCH');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()->getContent();

        $field = $this->inputTag($html, 'branch_name');

        $this->assertStringContainsString('name="branch_name"', $field);
        $this->assertStringContainsString('readonly', $field);
        $this->assertStringContainsString('value="MAIN BRANCH"', $field);
        $this->assertStringContainsString('class="business-report-header-control"', $field);

        // It sits in the same header grid, with the same label class as every neighbouring field.
        $this->assertStringContainsString('class="business-report-header-label" for="branch_name">BRANCH:', $html);
    }

    /**
     * TESTS 13-17 — BRANCH is balanced with the adjacent read-only applicant value without changing
     * the sizing or presentation of the other header fields. Asserted at the stylesheet level; the
     * on-screen result was not browser-verified.
     */
    public function test_branch_is_compact_and_centered_without_changing_other_header_controls(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $rule = $this->cssRule($css, '.business-report-header-branch {');
        $this->assertStringContainsString('display: flex', $rule);
        $this->assertStringContainsString('flex-direction: column', $rule);
        $this->assertStringContainsString('justify-content: center', $rule);
        $this->assertStringContainsString('padding: .3rem .45rem', $rule);

        // No border of its own, so the grid cell's border stays the single separator between
        // BRANCH and the row beneath it.
        $control = $this->cssRule($css, '.business-report-header-branch .business-report-header-control {');
        $this->assertStringContainsString('min-height: 1.4rem', $control);
        $this->assertStringContainsString('border-bottom: 0', $control);
        $this->assertStringContainsString('padding: 0', $control);

        // No ID-specific nudge was introduced to force it into place.
        $this->assertStringNotContainsString('#branch_name', $css);
    }

    /**
     * CI-IN CHARGE lists several investigators, so its type scales down responsively to keep them
     * on one line. Presentation only — which names appear, and in what form, is decided elsewhere.
     */
    public function test_the_ci_in_charge_names_use_a_smaller_responsive_type_scale(): void
    {
        [$ci, $folder, $source] = $this->businessOnDedicatedTemplate('MEATSHOP OF JUAN');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertOk()->getContent();

        // The names container carries the dedicated class and no longer the generic text-xs.
        $at = strpos($html, 'data-companion-ci-container');
        $this->assertNotFalse($at);
        $container = substr($html, strrpos(substr($html, 0, $at), '<div'), 260);
        $this->assertStringContainsString('business-report-ci-names', $container);
        $this->assertStringNotContainsString('text-xs', $container);

        $css = file_get_contents(resource_path('css/app.css'));
        $rule = $this->cssRule($css, '.business-report-ci-names {');

        // Responsive rather than a single hard-coded size, and bounded so it never becomes tiny
        // nor larger than the header's own .78rem body size.
        $this->assertStringContainsString('clamp(', $rule);
        $this->assertMatchesRegularExpression('/clamp\(\.5[0-9]rem/', $rule, 'The floor stays readable.');
        $this->assertStringContainsString('.72rem', $rule, 'The ceiling stays under the .78rem row text.');
        $this->assertStringContainsString('white-space: nowrap', $rule);

        // A single name is never broken mid-word.
        $this->assertStringContainsString('white-space: nowrap', $this->cssRule($css, '.business-report-ci-names [data-ci-primary-name]'));
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource} */
    private function businessOnDedicatedTemplate(string $name, ?string $branch = null): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        // meatshop_store owns a real Business Name input (its schema profile is true).
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'meatshop_store')->value('id'),
            'source_name' => $name,
            'business_name' => $name,
            'branch_name' => $branch,
        ]);
        BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => $name]);

        return [$ci, $folder, $source];
    }

    /** The single <input ...> tag carrying this id, so assertions cannot drift onto another field. */
    private function inputTag(string $html, string $id): string
    {
        $at = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($at, $id.' must be rendered.');

        $start = strrpos(substr($html, 0, $at), '<input');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '>', $at) - $start + 1);
    }

    /** One CSS declaration block, from its selector to the closing brace. */
    private function cssRule(string $css, string $selector): string
    {
        $at = strpos($css, $selector);
        $this->assertNotFalse($at, $selector.' must exist.');

        return substr($css, $at, strpos($css, '}', $at) - $at);
    }
}
