<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The duplicate-template rule proved over the WHOLE selectable catalogue, not a chosen few.
 *
 * BusinessTemplateDuplicateGuardTest states the rule with named examples. This file states the
 * same rule as a matrix driven by IncomeSourceTemplate::activeBusiness() itself, so a template
 * added to the catalogue later is covered the day it is seeded: if it ever fails to block itself,
 * or starts blocking a template it has nothing to do with, these tests fail without being edited.
 *
 * Cost is kept down by probing the shared Save-time guard with a payload that also fails one
 * unrelated rule (submitted_date before start_date). Validation therefore always rejects the
 * request — nothing is ever written by a probe — while the template guard still runs and reports
 * itself through the presence or absence of the income_source_template_id error. That is what
 * makes a full N x N sweep affordable; the diagonal is additionally proved through the real
 * Next (launch) and Save routes.
 */
class BusinessTemplateDuplicateMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_MESSAGE = 'This business template already exists for this client. Please select another template.';

    private const OTHER_BUSINESS = 'other_business_source_of_income';

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    /** The catalogue under audit: every template the Select Business Template modal can offer. */
    public function test_the_selectable_catalogue_is_exactly_the_active_business_templates(): void
    {
        $selectable = IncomeSourceTemplate::query()->activeBusiness()->get();
        $standard = $this->standardTemplates();

        $this->assertSame($standard->count() + 1, $selectable->count(), 'The catalogue is the standard templates plus Other Business/Source of Income.');
        $this->assertContains(self::OTHER_BUSINESS, $selectable->pluck('template_type')->all());

        // Retired/legacy templates stay out of the audit because users cannot select them.
        foreach (['business_source_validation', 'other_business_income_source'] as $retired) {
            $this->assertNotContains($retired, $selectable->pluck('template_type')->all(), "$retired is not selectable.");
        }
        // The general-income fallback is not a business template either.
        $this->assertNotContains('general_income_sources', $selectable->pluck('template_type')->all());

        $this->assertGreaterThan(1, $standard->count(), 'A cross-template matrix needs more than one standard template.');
    }

    /** TEST 1 + TEST 2, for every standard template: first creation allowed, its own repeat refused. */
    public function test_every_standard_template_allows_its_first_business_and_refuses_its_own_second(): void
    {
        foreach ($this->standardTemplates() as $template) {
            $folder = $this->folder();
            $label = $template->template_type;

            // TEST 1 — Next opens, Save creates.
            $this->next($folder, $template)->assertOk();
            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'FIRST '.$label))
                ->assertSessionHasNoErrors();
            $this->assertSame(1, $folder->incomeSources()->count(), "$label: the first business was created.");

            // TEST 2 — Next now refuses, before the encoding form is ever reached...
            foreach (['first attempt', 'repeat attempt'] as $attempt) {
                $this->next($folder, $template)
                    ->assertRedirect(route('client-folders.income-sources.manage', $folder))
                    ->assertSessionHas('status', self::TEMPLATE_MESSAGE)
                    ->assertSessionHas('statusType', 'error');
                $this->assertSame(1, $folder->incomeSources()->count(), "$label: the refused $attempt wrote nothing.");
            }

            // ...and Save refuses with the same message if the request is made directly.
            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'SECOND '.$label))
                ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);

            $this->assertSame(1, $folder->incomeSources()->count(), "$label: the refused save created nothing.");
            $this->assertSame(1, BusinessReport::query()->whereIn('income_source_id', $folder->incomeSources()->pluck('id'))->count());

            // The picker advertises that template, and only that template, as taken.
            $this->assertSame([$template->id], $this->usedTemplateIds($folder), "$label: the picker dataset holds exactly this template.");
        }
    }

    /** TEST 3, exhaustively: no standard template ever makes another one look occupied. */
    public function test_no_standard_template_occupies_any_other_standard_template(): void
    {
        $templates = $this->standardTemplates();

        foreach ($templates as $existing) {
            $folder = $this->folder();
            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($existing, 'EXISTING '.$existing->template_type))
                ->assertSessionHasNoErrors();

            foreach ($templates as $selected) {
                $pair = "existing {$existing->template_type} / selected {$selected->template_type}";
                if ($selected->is($existing)) {
                    $this->assertTrue($this->guardRefuses($folder, $selected), "$pair: a template must block itself.");

                    continue;
                }
                $this->assertFalse($this->guardRefuses($folder, $selected), "$pair: an unrelated template must never block.");
            }

            // Next agrees with that row: only the occupied template is turned away.
            $this->next($folder, $existing)->assertRedirect();
            $this->next($folder, $this->otherThan($templates, $existing))->assertOk();
            $this->assertSame([$existing->id], $this->usedTemplateIds($folder));
            $this->assertSame(1, $folder->incomeSources()->count(), 'No probe wrote anything.');
        }
    }

    /** A suppressed report frees its template again — for every standard template, not just one. */
    public function test_an_intentionally_deleted_report_frees_its_template_for_every_standard_template(): void
    {
        foreach ($this->standardTemplates() as $template) {
            $folder = $this->folder();
            $label = $template->template_type;

            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'FIRST '.$label))
                ->assertSessionHasNoErrors();
            $source = $folder->incomeSources()->sole();
            $this->next($folder, $template)->assertRedirect();

            app(DeleteBusinessReport::class)->execute($this->ci, $folder, $source);

            $this->assertNotNull(IncomeSource::query()->find($source->id), "$label: the business itself survives the report delete.");
            $this->assertNotNull($source->fresh()->business_report_deleted_at, "$label: the suppression marker is intact.");

            // The stale IncomeSource alone must not keep the template looking occupied.
            $this->assertFalse($this->guardRefuses($folder, $template), "$label: Save no longer treats the freed template as taken.");
            $this->next($folder, $template)->assertOk();
            $this->assertSame([], $this->usedTemplateIds($folder), "$label: the picker frees it too.");

            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'AGAIN '.$label))
                ->assertSessionDoesntHaveErrors('income_source_template_id');
            $this->assertSame(2, $folder->incomeSources()->count(), "$label: the business could be created again.");
        }
    }

    /** Occupancy is scoped to the exact person — for every standard template. */
    public function test_template_occupancy_never_crosses_between_the_applicant_and_co_makers(): void
    {
        foreach ($this->standardTemplates() as $template) {
            $folder = $this->folder();
            $label = $template->template_type;
            $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
            $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);

            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'APPLICANT '.$label))
                ->assertSessionHasNoErrors();

            $this->assertTrue($this->guardRefuses($folder, $template), "$label: the Applicant is blocked on their own second.");
            $this->assertFalse($this->guardRefuses($folder, $template, $coMakerA->id), "$label: the Applicant never blocks Co-Maker A.");
            $this->assertFalse($this->guardRefuses($folder, $template, $coMakerB->id), "$label: the Applicant never blocks Co-Maker B.");

            $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CO-MAKER A '.$label, $coMakerA->id))
                ->assertSessionHasNoErrors();

            $this->assertTrue($this->guardRefuses($folder, $template, $coMakerA->id), "$label: Co-Maker A is blocked on their own second.");
            $this->assertFalse($this->guardRefuses($folder, $template, $coMakerB->id), "$label: Co-Maker A never blocks Co-Maker B.");

            // Next is scoped the same way.
            $this->next($folder, $template)->assertRedirect();
            $this->next($folder, $template, $coMakerA)->assertRedirect();
            $this->next($folder, $template, $coMakerB)->assertOk();

            $this->assertSame(2, $folder->incomeSources()->count(), "$label: only the two legitimate businesses exist.");
        }
    }

    /**
     * A Business Check established the business first. Its own Business Report must still be
     * reachable on that exact IncomeSource — the shell the Check created may not report the
     * template as occupied against itself.
     */
    public function test_a_check_first_business_never_blocks_its_own_report_for_any_standard_template(): void
    {
        foreach ($this->standardTemplates() as $template) {
            $folder = $this->folder();
            $label = $template->template_type;

            $business = app(CreateIncomeSource::class)->execute($this->ci, $folder, [
                'income_source_template_id' => $template->id,
                'source_name' => 'CHECK FIRST '.$label,
                'business_name' => 'CHECK FIRST '.$label,
            ]);

            $this->assertSame(1, $business->revision, "$label: a Check-first business is an unencoded shell.");
            $this->assertSame([], $this->usedTemplateIds($folder), "$label: the picker does not treat the shell as an encoded report.");
            $this->assertFalse($this->guardRefuses($folder, $template), "$label: Save lets the shell be encoded.");
            $this->next($folder, $template)->assertOk("$label: Next lets the shell be encoded.");

            // Continuing that exact business reuses its IncomeSource rather than creating a second.
            $this->actingAs($this->ci)
                ->put(route('client-folders.income-sources.business.update', [$folder, $business]), $this->payload($template, 'CHECK FIRST '.$label) + [
                    'expected_revision' => $business->fresh()->revision,
                    'template_data' => $this->requiredTemplateData($template),
                ])
                ->assertSessionHasNoErrors();

            $this->assertSame(1, $folder->incomeSources()->count(), "$label: no second IncomeSource was created.");
            $this->assertSame($business->id, $folder->incomeSources()->sole()->id, "$label: the exact Check source was reused.");

            // Once encoded it behaves like any other business: its own template is now taken.
            $this->assertSame([$template->id], $this->usedTemplateIds($folder));
            $this->assertTrue($this->guardRefuses($folder, $template), "$label: the encoded business now occupies its template.");
        }
    }

    /** Other Business/Source of Income keeps its own rule: Next always opens, Save judges the set. */
    public function test_other_business_is_never_refused_by_the_standard_template_rule(): void
    {
        $folder = $this->folder();
        $other = IncomeSourceTemplate::query()->where('template_type', self::OTHER_BUSINESS)->sole();

        $this->actingAs($this->ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($other, 'OTHER ONE') + [
            'template_data' => ['fields' => ['income_sources' => ['farming', 'livestock']]],
            'report_remarks' => 'Other details.',
        ])->assertSessionHasNoErrors();

        // Next still opens the encoding form, and the picker never advertises it as taken.
        $this->next($folder, $other)->assertOk();
        $this->assertSame([], $this->usedTemplateIds($folder));

        // Nor does an existing Other Business occupy any standard template.
        foreach ($this->standardTemplates() as $template) {
            $this->assertFalse($this->guardRefuses($folder, $template), "{$template->template_type} is unaffected by an Other Business.");
        }
    }

    /**
     * Does the shared Save-time guard refuse this template for this person right now?
     *
     * The probe deliberately fails one unrelated rule (submitted_date before start_date) so the
     * request can never create anything, while the template guard still runs and answers.
     */
    private function guardRefuses(ClientFolder $folder, IncomeSourceTemplate $template, ?int $coMakerId = null): bool
    {
        $before = $folder->incomeSources()->count();
        $response = $this->actingAs($this->ci)->post(
            route('client-folders.income-sources.store', $folder),
            $this->payload($template, 'PROBE', $coMakerId) + ['submitted_date' => '2026-08-01'],
        );
        $response->assertSessionHasErrors('submitted_date');
        $this->assertSame($before, $folder->incomeSources()->count(), 'A probe must never write anything.');

        return session('errors')->has('income_source_template_id');
    }

    /** GET the "Next" destination — the launch route the Select Business Template modal opens. */
    private function next(ClientFolder $folder, IncomeSourceTemplate $template, ?CoMaker $coMaker = null)
    {
        $params = [$folder, 'income_source_template_id' => $template->id]
            + ($coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : []);

        return $this->actingAs($this->ci)->get(route('client-folders.income-sources.index', $params));
    }

    /** The exact list the rendered picker hands the "Next" handler. */
    private function usedTemplateIds(ClientFolder $folder): array
    {
        $html = $this->actingAs($this->ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        preg_match('/data-used-template-ids="(\[[^"]*\])"/', $html, $matches);
        $this->assertNotEmpty($matches, 'The picker must publish a used-template list.');

        return json_decode(html_entity_decode($matches[1]), true);
    }

    /**
     * The template-specific values that template's own schema insists on when saving an existing
     * business, so this test exercises the duplicate rule rather than a template's field rules.
     */
    private function requiredTemplateData(IncomeSourceTemplate $template): array
    {
        $schema = $template->businessReportSchema();
        $data = [];
        foreach ((array) ($schema['required_fields'] ?? []) as $key) {
            $field = collect($schema['fields'] ?? [])->firstWhere('key', $key);
            $data['fields'][$key] = ($field['type'] ?? 'text') === 'number' ? '1' : 'N/A';
        }
        foreach ((array) ($schema['required_questions'] ?? []) as $index) {
            $data['questions'][$index] = 'N/A';
        }

        return $data;
    }

    /** @return Collection<int, IncomeSourceTemplate> */
    private function standardTemplates(): Collection
    {
        return IncomeSourceTemplate::query()
            ->activeBusiness()
            ->where('template_type', '!=', self::OTHER_BUSINESS)
            ->orderBy('sort_order')
            ->get();
    }

    /** @param  Collection<int, IncomeSourceTemplate>  $templates */
    private function otherThan(Collection $templates, IncomeSourceTemplate $template): IncomeSourceTemplate
    {
        return $templates->first(fn (IncomeSourceTemplate $candidate): bool => ! $candidate->is($template));
    }

    private function folder(): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id]);
    }

    private function payload(IncomeSourceTemplate $template, string $name, ?int $coMakerId = null): array
    {
        return [
            'income_source_template_id' => $template->id,
            'co_maker_id' => $coMakerId,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => $template->business_category ?: $template->name,
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
            'intent' => 'stay',
        ];
    }
}
