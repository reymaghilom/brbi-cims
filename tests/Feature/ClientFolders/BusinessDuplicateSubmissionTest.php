<?php

namespace Tests\Feature\ClientFolders;

use App\Http\Middleware\PreventDuplicateSubmission;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manage Business — Add / Edit / Delete duplicate-submission protection.
 *
 * Server side, three independent layers are exercised for real:
 *  - the per-render submission_token replay (PreventDuplicateSubmission) for an identical retry
 *    whose first response was lost or delayed,
 *  - the existing business-identity guard (one standard template per person; Other Business by
 *    exact category set) and the expected_revision stale guard, which stay authoritative for any
 *    request the replay does not cover,
 *  - the locked, revision-checked delete.
 *
 * The client-side pending state is browser JavaScript, so it is pinned here as a markup/source
 * contract; real rapid-click behavior on a throttled connection still needs a manual browser check.
 */
class BusinessDuplicateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_MESSAGE = 'This business template already exists for this client. Please select another template.';

    private const STALE_MESSAGE = 'This Business Report was updated by another CI while you were editing it. Your changes were not saved. Please refresh the report to review the latest information before editing again.';

    private const REPORT_ALREADY_DELETED = 'This Business Report is no longer available. It may have already been deleted by another CI. Please refresh the Business Reports page.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // ---------------------------------------------------------------- Add Business

    public function test_a_single_create_saves_exactly_one_business(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $token = (string) Str::uuid();

        $response = $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('ONE STORE') + ['submission_token' => $token]);

        $source = IncomeSource::query()->sole();
        $response->assertRedirect(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Business Report saved successfully.');
        $this->assertSame(1, BusinessReport::query()->count());
    }

    public function test_an_identical_retry_after_a_lost_response_lands_on_the_saved_business_without_a_second_record(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $payload = $this->payload('RETRY STORE') + ['submission_token' => (string) Str::uuid()];

        $first = $this->actingAs($ci)->post($this->storeUrl($folder), $payload);
        $source = IncomeSource::query()->sole();
        $editUrl = route('client-folders.income-sources.edit', [$folder, $source]);
        $first->assertRedirect($editUrl);

        // Rapid repeats of the very same request (double click, browser retry, reload of the POST).
        for ($retry = 0; $retry < 3; $retry++) {
            $this->actingAs($ci)->post($this->storeUrl($folder), $payload)
                ->assertRedirect($editUrl)
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'Business Report saved successfully.');
        }

        $this->assertSame(1, IncomeSource::query()->count());
        $this->assertSame(1, BusinessReport::query()->count());
        $this->assertSame(2, $source->fresh()->revision, 'The replay never ran the save a second time.');
    }

    public function test_duplicate_create_requests_without_a_token_are_still_refused_by_the_business_identity_guard(): void
    {
        [$ci, $folder] = $this->ciAndFolder();

        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('GUARDED STORE'))->assertSessionHasNoErrors();
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('GUARDED STORE'))
            ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);

        $this->assertSame(1, IncomeSource::query()->count());
        $this->assertSame(1, BusinessReport::query()->count());
    }

    public function test_a_reused_token_with_a_different_payload_is_never_replayed_and_meets_the_server_guard(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $token = (string) Str::uuid();

        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('FIRST STORE') + ['submission_token' => $token])->assertSessionHasNoErrors();

        // Same token, edited values (e.g. Back to an old page, then Save): it must not be reported
        // as saved — it runs normally and the duplicate-template guard answers it.
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('EDITED STORE') + ['submission_token' => $token])
            ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);

        $this->assertSame('FIRST STORE', IncomeSource::query()->sole()->business_name);
    }

    public function test_a_concurrent_request_with_the_same_token_waits_and_never_saves_twice(): void
    {
        $this->app->bind(PreventDuplicateSubmission::class, ImpatientDuplicateSubmissionGuard::class);
        [$ci, $folder] = $this->ciAndFolder();
        $token = (string) Str::uuid();

        // The first request is still in flight: its token lock is held.
        $lock = Cache::lock($this->lockKey($ci, 'POST', $this->storeUrl($folder), $token), 120);
        $this->assertTrue($lock->get());

        $this->actingAs($ci)->from(route('client-folders.income-sources.index', $folder))
            ->post($this->storeUrl($folder), $this->payload('RACING STORE') + ['submission_token' => $token])
            ->assertRedirect(route('client-folders.income-sources.index', $folder))
            ->assertSessionHas('status', PreventDuplicateSubmission::IN_PROGRESS_MESSAGE)
            ->assertSessionHas('statusType', 'error');
        $this->assertSame(0, IncomeSource::query()->count());

        // Once the first request completes, the same retry proceeds normally exactly once.
        $lock->release();
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('RACING STORE') + ['submission_token' => $token])->assertSessionHasNoErrors();
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('RACING STORE') + ['submission_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame(1, IncomeSource::query()->count());
    }

    public function test_a_failed_create_is_not_remembered_so_the_corrected_retry_saves(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $token = (string) Str::uuid();

        // Both names blank: the store request falls back from one to the other, so blanking only
        // business_name would still pass validation.
        $this->actingAs($ci)->post($this->storeUrl($folder), ['business_name' => '', 'source_name' => ''] + $this->payload('FIX STORE') + ['submission_token' => $token])
            ->assertSessionHasErrors('business_name');
        $this->assertSame(0, IncomeSource::query()->count());

        // The same request again is NOT replayed as a success: it is re-validated and still fails.
        $this->actingAs($ci)->post($this->storeUrl($folder), ['business_name' => '', 'source_name' => ''] + $this->payload('FIX STORE') + ['submission_token' => $token])
            ->assertSessionHasErrors('business_name');

        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload('FIX STORE') + ['submission_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame(1, IncomeSource::query()->count());
    }

    public function test_legitimately_separate_businesses_with_the_same_name_are_still_created(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $other = IncomeSourceTemplate::query()->where('template_type', 'other_business_source_of_income')->firstOrFail();

        $this->actingAs($ci)->post($this->storeUrl($folder), $this->otherPayload($other, 'SAME NAME', ['farming']) + ['submission_token' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->otherPayload($other, 'SAME NAME', ['livestock']) + ['submission_token' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->assertSame(2, IncomeSource::query()->count());
        $this->assertSame(2, BusinessReport::query()->count());
    }

    public function test_one_users_token_never_replays_for_another_user(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $other = User::factory()->create();
        $payload = $this->payload('OWNED STORE') + ['submission_token' => (string) Str::uuid()];

        $this->actingAs($ci)->post($this->storeUrl($folder), $payload)->assertSessionHasNoErrors();

        // A copied token gives another user nothing: their request runs on its own and is judged
        // by the normal guard.
        $this->actingAs($other)->post($this->storeUrl($folder), $payload)
            ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);
        $this->assertSame(1, IncomeSource::query()->count());
    }

    // ---------------------------------------------------------------- Edit Business

    public function test_a_successful_update_saves_once(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'EDIT STORE');

        $this->update($ci, $folder, $source, 'EDITED ONCE', $source->revision, (string) Str::uuid())
            ->assertRedirect(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('statusType', 'success');

        $this->assertSame('EDITED ONCE', $source->fresh()->businessReport->business_name);
        $this->assertSame(3, $source->fresh()->revision);
    }

    public function test_a_repeated_identical_update_replays_success_instead_of_a_false_stale_warning(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'REPEAT STORE');
        $openedRevision = $source->revision;
        $token = (string) Str::uuid();

        $this->update($ci, $folder, $source, 'REPEAT EDIT', $openedRevision, $token)->assertSessionHasNoErrors();
        $this->update($ci, $folder, $source, 'REPEAT EDIT', $openedRevision, $token)
            ->assertRedirect(route('client-folders.income-sources.edit', [$folder, $source]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Business Report updated successfully.')
            ->assertSessionHas('statusType', 'success');

        $this->assertSame($openedRevision + 1, $source->fresh()->revision, 'Saved exactly once.');
        $this->assertSame('REPEAT EDIT', $source->fresh()->businessReport->business_name);
    }

    public function test_a_stale_update_with_new_values_is_still_rejected_even_with_a_reused_token(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'STALE STORE');
        $openedRevision = $source->revision;
        $token = (string) Str::uuid();

        $this->update($ci, $folder, $source, 'FIRST EDIT', $openedRevision, $token)->assertSessionHasNoErrors();
        $this->update($ci, $folder, $source, 'DIFFERENT EDIT', $openedRevision, $token)
            ->assertSessionHasErrors(['expected_revision' => self::STALE_MESSAGE]);

        $this->assertSame('FIRST EDIT', $source->fresh()->businessReport->business_name);
    }

    public function test_an_update_validation_failure_saves_nothing_and_is_not_remembered(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'VALID STORE');
        $token = (string) Str::uuid();

        $this->update($ci, $folder, $source, '', $source->revision, $token)->assertSessionHasErrors('business_name');
        $this->assertSame('VALID STORE', $source->fresh()->businessReport->business_name);

        $this->update($ci, $folder, $source, 'CORRECTED', $source->revision, $token)->assertSessionHasNoErrors();
        $this->assertSame('CORRECTED', $source->fresh()->businessReport->business_name);
    }

    // ---------------------------------------------------------------- Delete Business

    public function test_a_delete_succeeds_once_and_a_repeated_delete_is_a_friendly_no_op(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'DELETE STORE');
        $other = IncomeSourceTemplate::query()->where('template_type', 'other_business_source_of_income')->firstOrFail();
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->otherPayload($other, 'KEEP STORE', ['farming']))->assertSessionHasNoErrors();
        // Other Business stores its template's mapped default name, not the typed one.
        $keep = IncomeSource::query()->whereKeyNot($source->id)->sole();
        $revision = $source->revision;

        $this->deleteReport($ci, $folder, $source, $revision)->assertOk()->assertJson(['deleted' => 1]);
        $this->assertNull(BusinessReport::query()->where('income_source_id', $source->id)->first());

        // Double click / retry of the same delete.
        $this->deleteReport($ci, $folder, $source, $revision)
            ->assertNotFound()
            ->assertJson(['message' => self::REPORT_ALREADY_DELETED]);

        $this->assertNotNull($keep->fresh()->businessReport, 'An unrelated business is never touched.');
        $this->assertSame(1, BusinessReport::query()->count());
    }

    // ---------------------------------------------------------------- Authorization

    public function test_guests_and_cross_folder_requests_are_refused(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'SCOPED STORE');
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $token = (string) Str::uuid();
        Auth::guard('web')->logout();

        $this->post($this->storeUrl($folder), $this->payload('GUEST STORE') + ['submission_token' => $token])->assertRedirect(route('login'));
        $this->put(route('client-folders.income-sources.business.update', [$folder, $source]), ['submission_token' => $token])->assertRedirect(route('login'));

        // The business cannot be reached beneath another folder (scoped binding).
        $this->update($ci, $otherFolder, $source, 'CROSS EDIT', $source->revision, $token)->assertNotFound();
        $this->deleteReport($ci, $otherFolder, $source, $source->revision)->assertNotFound();

        $this->assertSame(1, IncomeSource::query()->count());
        $this->assertSame('SCOPED STORE', $source->fresh()->businessReport->business_name);
    }

    // ---------------------------------------------------------------- Frontend contract

    public function test_create_and_update_forms_opt_into_the_pending_guard_with_a_fresh_token(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $source = $this->savedBusiness($ci, $folder, 'MARKUP STORE');

        $createPage = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form id="business-template-form"[^>]*\bdata-submit-guard\b[^>]*data-submit-pending-label="Saving\.\.\."/', $createPage);
        $this->assertMatchesRegularExpression('/<form id="business-template-form".*?name="submission_token" value="[0-9a-f-]{36}"/s', $createPage);

        $editPage = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form id="business-report-form"[^>]*\bdata-submit-guard\b[^>]*data-submit-pending-label="Updating\.\.\."/', $editPage);
        preg_match_all('/name="submission_token" value="([0-9a-f-]{36})"/', $editPage, $first);
        preg_match_all('/name="submission_token" value="([0-9a-f-]{36})"/', $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->getContent(), $second);
        $this->assertCount(2, $first[1], 'Both the create and the update form carry a token.');
        $this->assertSame([], array_intersect($first[1], $second[1]), 'Every page render issues new tokens.');

        // Required fields are never disabled by the guard, and the submitter markup is unchanged.
        $this->assertMatchesRegularExpression('/<button type="submit" form="business-report-form" name="intent" value="complete" class="ui-button-primary" data-business-save\s*>/', $editPage);
    }

    public function test_pending_state_script_contract(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        // Visible spinner + label, width preserved, exact restore.
        $this->assertStringContainsString('const setButtonPending = (button, label) => {', $js);
        $this->assertStringContainsString('button.style.minWidth = `${button.getBoundingClientRect().width}px`;', $js);
        $this->assertStringContainsString('animate-spin', $js);
        $this->assertStringContainsString('button.innerHTML = original.html;', $js);
        // The guard labels the actual submitter (Enter key reports the default button) and resets on bfcache restore.
        $this->assertStringContainsString('const pendingLabel = form.dataset.submitPendingLabel;', $js);
        $this->assertStringContainsString('event.submitter instanceof HTMLButtonElement ? event.submitter : submitGuardButtons(form)[0]', $js);
        $this->assertStringContainsString('clearButtonPending(button);', $js);

        // Delete: single-flight, matches the confirmation dialog's untyped button, re-enabled on failure.
        $start = strpos($js, "form.closest('[data-business-delete-form]'))) return;");
        $this->assertNotFalse($start);
        $delete = substr($js, $start, 2500);
        $this->assertStringContainsString("if (form.dataset.submitting === 'true') return;", $delete);
        $this->assertStringContainsString('button:not([type]), button[type="submit"]', $delete);
        $this->assertStringContainsString("setButtonPending(submitButton, 'Deleting...');", $delete);
        $this->assertStringContainsString("submitButton.setAttribute('aria-busy', 'true');", $delete);
        $this->assertStringContainsString('delete form.dataset.submitting;', $delete);
        $this->assertStringContainsString('clearButtonPending(submitButton);', $delete);
        $this->assertStringContainsString('submitButton.disabled = false;', $delete);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array{0: User, 1: ClientFolder} */
    private function ciAndFolder(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id])];
    }

    private function storeUrl(ClientFolder $folder): string
    {
        return route('client-folders.income-sources.store', $folder);
    }

    private function lockKey(User $user, string $method, string $url, string $token): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return 'submission:'.hash('sha256', $user->id.'|'.$method.'|'.$path.'|'.$token).':lock';
    }

    private function payload(string $name): array
    {
        return [
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail()->id,
            'co_maker_id' => null,
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
    private function otherPayload(IncomeSourceTemplate $template, string $name, array $incomeSources): array
    {
        return ['income_source_template_id' => $template->id] + $this->payload($name) + [
            'template_data' => ['fields' => ['income_sources' => $incomeSources]],
            'report_remarks' => $name.' details.',
        ];
    }

    private function savedBusiness(User $ci, ClientFolder $folder, string $name): IncomeSource
    {
        $this->actingAs($ci)->post($this->storeUrl($folder), $this->payload($name))->assertSessionHasNoErrors();

        return IncomeSource::query()->where('business_name', $name)->sole();
    }

    private function update(User $ci, ClientFolder $folder, IncomeSource $source, string $name, int $revision, string $token)
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
            'submission_token' => $token,
        ]);
    }

    private function deleteReport(User $ci, ClientFolder $folder, IncomeSource $source, int $revision)
    {
        return $this->actingAs($ci)->deleteJson(
            route('client-folders.income-sources.business-report.destroy', [$folder, $source]),
            ['expected_revision' => $revision],
        );
    }
}

/** Gives up on an in-flight duplicate immediately, so the waiting branch is testable without a real wait. */
class ImpatientDuplicateSubmissionGuard extends PreventDuplicateSubmission
{
    protected const WAIT_SECONDS = 0;
}
