<?php

namespace Tests\Feature\ClientFolders;

use App\Http\Controllers\IncomeSourceController;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Picking a Business Template the exact person already uses is reported INLINE, inside the Select
 * Business Template modal, right under the selector that caused it — not as a toast the CI has to
 * look away to read.
 *
 * The inline message is UX only, and it is the SAME string the server returns: modal, launch() and
 * StoreIncomeSourceRequest all read IncomeSourceController::DUPLICATE_TEMPLATE_MESSAGE. The
 * server-side duplicate guard is untouched and remains the authoritative protection — launch()
 * turns the template away before the encoding form opens and the save endpoint refuses it, each
 * scoped to the exact person. Other Business / Source of Income keeps its own category-set rule and
 * is never in the modal's used-template list at all.
 */
class BusinessTemplateDuplicateInlineErrorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one wording for a standard duplicate template. The modal shows it inline and the server
     * returns the very same string, so the CI reads the same sentence whichever path refuses them.
     */
    private const DUPLICATE_MESSAGE = 'This business template already exists for this client. Please select another template.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------------
    // TEST A — the inline duplicate error
    // ---------------------------------------------------------------------

    public function test_the_modal_carries_the_exact_inline_duplicate_message_and_the_used_template(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('retail_grocery_water_refilling');
        $this->saveBusiness($ci, $folder, $template, 'FIRST STORE');

        $html = $this->manage($ci, $folder);

        // The message the modal shows, attached to the exact selector it belongs to.
        $this->assertStringContainsString('data-duplicate-template-message="'.e(self::DUPLICATE_MESSAGE).'"', $html);
        $this->assertStringContainsString('data-used-template-ids="['.$template->id.']"', $html);

        // It renders through the existing inline validation element, not a new visual style.
        $this->assertStringContainsString('data-add-business-template-error', $html);
        $this->assertStringContainsString('data-add-business-template-error-text', $html);
        $this->assertMatchesRegularExpression('/<p class="[^"]*text-danger"[^>]*role="alert"[^>]*data-add-business-template-error/', $html);
    }

    public function test_selecting_a_used_template_blocks_next_and_creates_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('retail_grocery_water_refilling');
        $this->saveBusiness($ci, $folder, $template, 'FIRST STORE');
        $before = [IncomeSource::query()->count(), BusinessReport::query()->count()];

        // "Next" navigates to launch() with the chosen template — the server refuses it there, so
        // the encoding form never opens and nothing is written.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertRedirect(route('client-folders.income-sources.manage', $folder));

        $this->assertSame($before, [IncomeSource::query()->count(), BusinessReport::query()->count()]);
    }

    /** TEST B / TEST C, client side: one inline line, swapped per case, cleared as soon as the choice changes. */
    public function test_the_selector_reports_and_clears_the_duplicate_inline_without_a_toast(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $next = $this->nextHandler($script);

        // The duplicate branch shows the inline message; it no longer raises a toast.
        $this->assertStringContainsString('showAddBusinessTemplateError(templateError, templateSelect.dataset.duplicateTemplateMessage);', $next);
        $this->assertStringNotContainsString('showToast(', $next, 'The duplicate case must not fall back to a toast.');

        // Changing the selection re-evaluates immediately: a valid template clears the error and
        // unblocks Next, another used one re-states it.
        $this->assertStringContainsString("const select = event.target.closest('[data-add-business-template-select]');", $script);
        $this->assertStringContainsString('if (addBusinessTemplateIsDuplicate(select)) {', $script);
        $this->assertStringContainsString('hideAddBusinessTemplateError(errorElement);', $script);

        // The shared helpers restore the default wording, so the duplicate text can never linger
        // on the next "nothing selected yet" case.
        $this->assertStringContainsString('text.dataset.defaultMessage', $script);

        // The application's toast system itself is untouched.
        $this->assertStringContainsString('const showToast = ', $script);
    }

    public function test_the_next_guard_still_reads_the_live_used_template_list(): void
    {
        $next = $this->nextHandler(file_get_contents(resource_path('js/app.js')));

        $this->assertStringContainsString('JSON.parse(templateSelect.dataset.usedTemplateIds', $next);
        $this->assertStringContainsString('return;', $next, 'A duplicate selection returns before the encoding form is opened.');
        $this->assertStringNotContainsString('reportTrigger.click();', substr($next, 0, strpos($next, 'return;')));
    }

    // ---------------------------------------------------------------------
    // TEST D — person isolation
    // ---------------------------------------------------------------------

    public function test_a_template_used_by_one_person_is_free_for_every_other_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $template = $this->template('retail_grocery_water_refilling');

        $this->saveBusiness($ci, $folder, $template, 'APPLICANT STORE');

        // The Applicant's picker advertises it as used; neither Co-Maker's does.
        $this->assertStringContainsString('data-used-template-ids="['.$template->id.']"', $this->manage($ci, $folder));
        foreach ([$coMakerA, $coMakerB] as $coMaker) {
            $this->assertStringContainsString('data-used-template-ids="[]"', $this->manage($ci, $folder, $coMaker));
        }

        // And Co-Maker A may genuinely use the same template.
        $this->saveBusiness($ci, $folder, $template, 'CO MAKER A STORE', $coMakerA);
        $this->assertStringContainsString('data-used-template-ids="['.$template->id.']"', $this->manage($ci, $folder, $coMakerA));
        // Which still leaves Co-Maker B free.
        $this->assertStringContainsString('data-used-template-ids="[]"', $this->manage($ci, $folder, $coMakerB));

        $this->assertSame(2, IncomeSource::query()->count());
    }

    // ---------------------------------------------------------------------
    // TEST E — the revision rule
    // ---------------------------------------------------------------------

    public function test_a_revision_one_shell_never_advertises_its_template_as_used(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('retail_grocery_water_refilling');

        // An IncomeSource plus an unencoded Business Report shell — never explicitly saved, so it is
        // not yet a legitimate Business Report and its template stays free.
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'SHELL STORE',
            'business_name' => 'SHELL STORE',
        ]);
        $source->businessReport()->create(['business_name' => 'SHELL STORE', 'report_category' => 'Retail']);
        $this->assertSame(1, $source->fresh()->revision);

        $this->assertStringContainsString('data-used-template-ids="[]"', $this->manage($ci, $folder));

        // And the server agrees — the encoding form still opens for it.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // TEST F — Other Business keeps its own rule
    // ---------------------------------------------------------------------

    public function test_other_business_is_never_advertised_as_a_used_template(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $other = $this->template('other_business_source_of_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($other, ['Sari-Sari', 'Tricycle']))
            ->assertSessionHasNoErrors();

        // Never in the used list, so the modal never blocks it on template identity alone.
        $this->assertStringContainsString('data-used-template-ids="[]"', $this->manage($ci, $folder));
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $other->id]))
            ->assertOk();

        // Its own category-set rule is untouched: the same set in another order is still refused,
        // a different set is still allowed.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($other, ['Tricycle', 'Sari-Sari']))
            ->assertSessionHasErrors('duplicate_business_categories');
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($other, ['Sari-Sari', 'Farming']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, IncomeSource::query()->count());
    }

    // ---------------------------------------------------------------------
    // TEST G — the server still refuses a bypass
    // ---------------------------------------------------------------------

    public function test_the_server_still_rejects_an_exact_duplicate_submitted_without_the_modal(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $template = $this->template('retail_grocery_water_refilling');
        $this->saveBusiness($ci, $folder, $template, 'FIRST STORE');
        $before = [IncomeSource::query()->count(), BusinessReport::query()->count()];

        // Straight to launch(), as if the picker had been bypassed entirely.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertRedirect(route('client-folders.income-sources.manage', $folder))
            ->assertSessionHas('status', self::DUPLICATE_MESSAGE)
            ->assertSessionHas('statusType', 'error');

        // And straight to the save endpoint.
        $this->actingAs($ci)
            ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'SECOND STORE'))
            ->assertSessionHasErrors(['income_source_template_id' => self::DUPLICATE_MESSAGE]);

        $this->assertSame($before, [IncomeSource::query()->count(), BusinessReport::query()->count()]);

        // One source of truth: the constant the server flashes is the very same string the modal
        // renders inline, so the two can never drift apart again.
        $this->assertSame(self::DUPLICATE_MESSAGE, IncomeSourceController::DUPLICATE_TEMPLATE_MESSAGE);
        $this->assertStringContainsString(
            'data-duplicate-template-message="'.e(IncomeSourceController::DUPLICATE_TEMPLATE_MESSAGE).'"',
            $this->manage($ci, $folder),
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** The Select Business Template "Next" branch of the delegated click handler. */
    private function nextHandler(string $script): string
    {
        $start = strpos($script, "const addBusinessNext = event.target.closest('[data-add-business-next]');");
        $this->assertNotFalse($start);
        $end = strpos($script, "const modalClose = event.target.closest('[data-modal-close]');", $start);
        $this->assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function template(string $templateType): IncomeSourceTemplate
    {
        return IncomeSourceTemplate::query()->where('template_type', $templateType)->firstOrFail();
    }

    private function manage(User $ci, ClientFolder $folder, ?CoMaker $coMaker = null): string
    {
        $person = $coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : [];

        return $this->actingAs($ci)
            ->get(route('client-folders.income-sources.manage', [$folder] + $person))
            ->assertOk()
            ->getContent();
    }

    /** A genuinely saved Business Report through the normal workflow. */
    private function saveBusiness(User $ci, ClientFolder $folder, IncomeSourceTemplate $template, string $name, ?CoMaker $coMaker = null): void
    {
        $this->actingAs($ci)
            ->post(route('client-folders.income-sources.store', $folder), $this->payload($template, $name, $coMaker?->id))
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function payload(IncomeSourceTemplate $template, string $name, ?int $coMakerId = null): array
    {
        return [
            'income_source_template_id' => $template->id,
            'co_maker_id' => $coMakerId,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
            'intent' => 'stay',
        ];
    }

    /** @param  list<string>  $incomeSources */
    private function otherPayload(IncomeSourceTemplate $template, array $incomeSources): array
    {
        return $this->payload($template, 'Other Business') + [
            'template_data' => ['fields' => ['income_sources' => $incomeSources]],
            'report_remarks' => 'Other business details.',
        ];
    }
}
