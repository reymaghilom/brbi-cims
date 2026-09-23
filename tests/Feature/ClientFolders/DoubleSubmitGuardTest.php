<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slow-network / double-submit protection for the full-page Create Client Folder form and the
 * Business Report edit form. The guard itself is browser JavaScript, so its behavior is pinned
 * here as a source/markup contract; the server-side flows it protects are exercised for real.
 * Real rapid-click behavior under throttling still needs a manual browser check.
 */
class DoubleSubmitGuardTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_CI_STALE = 'This Business Report was updated by another CI while you were editing it. Your changes were not saved. Please refresh the report to review the latest information before editing again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------- Guard contract (JS)

    public function test_submit_guard_is_opt_in_and_blocks_a_second_in_flight_submit(): void
    {
        $js = $this->guardSource();

        // Opt-in per form, never a blanket guard over every form.
        $this->assertStringContainsString("form.matches('[data-submit-guard]')", $js);
        // A repeated submit while the first is in flight is cancelled.
        $this->assertMatchesRegularExpression("/if \\(form\\.dataset\\.submitting === 'true'\\) \\{\\s*event\\.preventDefault\\(\\);\\s*return;/", $js);
        // Only an accepted submit locks the form: submits cancelled by other handlers never do,
        // and constraint validation failures never dispatch 'submit' at all.
        $this->assertStringContainsString("window.addEventListener('submit'", $js);
        $this->assertStringContainsString('if (event.defaultPrevented) return;', $js);
        $this->assertLessThan(strpos($js, "form.dataset.submitting = 'true';"), strpos($js, 'if (event.defaultPrevented) return;'));
        // Controls are disabled (including form="" associated toolbar buttons) after the entry
        // list is built, so the submitter's own name/value still reaches the server.
        $this->assertStringContainsString('[...form.elements]', $js);
        $this->assertStringContainsString('button.disabled = true;', $js);
        $this->assertStringContainsString("button.setAttribute('aria-busy', 'true');", $js);
        $this->assertStringContainsString('window.setTimeout(', $js);
        // A page restored after a failed navigation is usable again.
        $this->assertStringContainsString("window.addEventListener('pageshow'", $js);
        $this->assertStringContainsString('button.disabled = false;', $js);
        $this->assertStringContainsString('delete form.dataset.submitting;', $js);
        // The guard never rewrites the submitted data (no expected_revision/intent handling).
        $this->assertStringNotContainsString('expected_revision', $js);
        $this->assertStringNotContainsString('FormData', $js);
    }

    // ---------------------------------------------------------------- Full-page Create Client Folder

    public function test_full_page_create_form_opts_into_the_guard_and_keeps_native_validation(): void
    {
        $ci = User::factory()->create();

        $html = $this->actingAs($ci)->get(route('client-folders.create'))->assertOk()->getContent();
        $form = $this->formTag($html, route('client-folders.store'));

        $this->assertStringContainsString('data-submit-guard', $form);
        $this->assertStringNotContainsString('novalidate', $form);
        $this->assertMatchesRegularExpression('/<input id="last_name"[^>]*\brequired\b/', $html);
        $this->assertMatchesRegularExpression('/<a href="[^"]+" class="ui-button-secondary">\s*<svg[^>]*data-action-icon="close"[^>]*>.*?<\/svg>\s*<span>Cancel<\/span>\s*<\/a>/s', $html);
        $this->assertMatchesRegularExpression('/<button class="ui-button-primary">\s*<svg[^>]*data-action-icon="plus"[^>]*>.*?<\/svg>\s*<span>Create Client Folder<\/span>\s*<\/button>/s', $html);
    }

    public function test_first_full_page_create_is_allowed_and_creates_exactly_one_folder(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->post(route('client-folders.store'), ['last_name' => 'Reyes', 'first_name' => 'Maria'])
            ->assertRedirect(route('client-folders.show', ClientFolder::sole()));

        $this->assertDatabaseCount('client_folders', 1);
    }

    public function test_full_page_create_validation_failure_re_renders_an_unlocked_form(): void
    {
        $ci = User::factory()->create();

        $html = $this->actingAs($ci)
            ->from(route('client-folders.create'))
            ->followingRedirects()
            ->post(route('client-folders.store'), ['last_name' => '', 'first_name' => 'Maria'])
            ->assertOk()
            ->getContent();

        $this->assertDatabaseCount('client_folders', 0);
        $this->assertStringContainsString('last_name-error', $html);
        $form = $this->formTag($html, route('client-folders.store'));
        $this->assertStringContainsString('data-submit-guard', $form);
        $this->assertStringNotContainsString('data-submitting', $form);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*\bdisabled\b[^>]*>[\s\S]*?<span>Create Client Folder<\/span>/', $html);
    }

    // ---------------------------------------------------------------- Business Report edit

    public function test_business_report_edit_form_opts_into_the_guard_and_submits_revision_and_intent_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->savedBusiness($ci, $folder, 'Guarded Store');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();
        $form = $this->formTag($html, route('client-folders.income-sources.business.update', [$folder, $source]));

        $this->assertStringContainsString('id="business-report-form"', $form);
        $this->assertStringContainsString('data-submit-guard', $form);
        $this->assertStringContainsString('data-unsaved-form', $form);
        $this->assertStringContainsString('<input type="hidden" name="expected_revision" value="'.$source->revision.'">', $html);
        // Exact tag: rendered enabled, with its unchanged form/name/value submitter contract.
        $this->assertMatchesRegularExpression('/<button type="submit" form="business-report-form" name="intent" value="complete" class="ui-button-primary" data-business-save>/', $html);
    }

    public function test_first_business_report_save_is_allowed_and_a_replayed_old_revision_stays_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->savedBusiness($ci, $folder, 'Guarded Store');
        $openedRevision = $source->revision;

        $this->save($ci, $folder, $source, 'First Save', $openedRevision)->assertSessionHasNoErrors();
        $this->assertSame($openedRevision + 1, $source->fresh()->revision);

        // What the client guard prevents: the server stays authoritative and rejects the replay.
        $this->save($ci, $folder, $source, 'Duplicate Click', $openedRevision)->assertSessionHasErrors('expected_revision');
        $this->assertSame($openedRevision + 1, $source->fresh()->revision);
        $this->assertSame('First Save', $source->fresh()->businessReport->business_name);
    }

    public function test_a_genuine_stale_save_after_another_ci_still_shows_the_concurrency_warning(): void
    {
        $ci1 = User::factory()->create();
        $ci2 = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci1->id]);
        $source = $this->savedBusiness($ci1, $folder, 'Shared Store');
        $openedRevision = $source->revision;

        $this->save($ci2, $folder, $source, 'Other CI Save', $openedRevision)->assertSessionHasNoErrors();

        $this->save($ci1, $folder, $source, 'Stale Save', $openedRevision)
            ->assertSessionHasErrors(['expected_revision' => self::OTHER_CI_STALE]);
        $this->assertSame('Other CI Save', $source->fresh()->businessReport->business_name);
    }

    // ---------------------------------------------------------------- Helpers

    private function guardSource(): string
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $start = strpos($js, 'const submitGuardButtons = ');
        $end = strpos($js, '// A form marked [data-no-change-guard]', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($js, $start, $end - $start);
    }

    private function formTag(string $html, string $action): string
    {
        $this->assertMatchesRegularExpression('/<form[^>]*action="'.preg_quote(e($action), '/').'"[^>]*>/', $html);
        preg_match('/<form[^>]*action="'.preg_quote(e($action), '/').'"[^>]*>/', $html, $matches);

        return $matches[0];
    }

    private function save(User $ci, ClientFolder $folder, IncomeSource $source, string $name, int $revision)
    {
        return $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'intent' => 'stay',
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
            'expected_revision' => $revision,
            'co_maker_id' => null,
        ]);
    }

    private function savedBusiness(User $ci, ClientFolder $folder, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => null,
        ])->fresh();

        $this->save($ci, $folder, $source, $name, $source->revision)->assertSessionHasNoErrors();

        return $source->fresh();
    }
}
