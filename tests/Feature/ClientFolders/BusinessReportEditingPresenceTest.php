<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business Report collaborative editing: presence is an ADVISORY warning scoped to the exact
 * IncomeSource, never a lock. The existing expected_revision guard remains the authoritative
 * first-save-wins protection, and a stale save is explained with friendly wording.
 */
class BusinessReportEditingPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const ADVICE = 'You may continue reviewing the form, but if they save changes first, you will need to refresh before saving your changes.';

    private const STALE = 'This Business Report was updated by another CI while you were editing it. Your changes were not saved. Please refresh the report to review the latest information before editing again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_edit_form_renders_an_advisory_presence_banner_for_the_exact_report_without_disabling_anything(): void
    {
        [$ci, $folder] = $this->context();
        $source = $this->createReport($ci, $folder, 'Presence Store');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.edit', [$folder, $source]))->assertOk()->getContent();

        $this->assertStringContainsString('data-editing-type="income_source"', $html);
        $this->assertStringContainsString('data-editing-id="'.$source->id.'"', $html);
        $this->assertStringContainsString('data-editing-label="Business Report"', $html);
        $this->assertStringContainsString('data-editing-advice="'.self::ADVICE.'"', $html);
        $this->assertStringNotContainsStringIgnoringCase('locked', $html);

        // Advisory only: the Business Report form itself is neither disabled nor made read-only by presence.
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*data-business-report-form[^>]*\b(disabled|inert)\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*\bdisabled\b/', $html);
    }

    public function test_a_second_ci_on_the_same_report_sees_the_first_but_never_themselves(): void
    {
        [$first, $folder] = $this->context();
        $second = User::factory()->create(['full_name' => 'Second CI']);
        $source = $this->createReport($first, $folder, 'Shared Store');

        $this->heartbeat($first, $source)->assertOk()->assertJson(['other_editors' => []]);

        $response = $this->heartbeat($second, $source)->assertOk();
        $this->assertSame([$first->full_name], collect($response->json('other_editors'))->pluck('name')->all());
        $this->assertSame(['name', 'since'], array_keys($response->json('other_editors.0')));

        $response = $this->heartbeat($first, $source)->assertOk();
        $this->assertSame(['Second CI'], collect($response->json('other_editors'))->pluck('name')->all());
    }

    public function test_presence_never_crosses_reports_people_or_folders(): void
    {
        [$ci, $folder] = $this->context();
        $other = User::factory()->create();
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        // Presence identity is the exact IncomeSource id alone, so the rows are created directly —
        // the Business Report duplicate-category guard is not what is under test here.
        $applicant = $this->directSource($folder, 'Applicant Store');
        $applicantSecond = $this->directSource($folder, 'Applicant Store');
        $coMakerASource = $this->directSource($folder, 'Applicant Store', $coMakerA);
        $coMakerBSource = $this->directSource($folder, 'Applicant Store', $coMakerB);
        $otherFolderSource = $this->directSource($otherFolder, 'Applicant Store');

        $this->heartbeat($ci, $applicant)->assertOk();
        $this->heartbeat($ci, $coMakerASource)->assertOk();

        foreach ([$applicantSecond, $coMakerBSource, $otherFolderSource] as $source) {
            $this->heartbeat($other, $source)->assertOk()->assertJson(['other_editors' => []]);
        }

        // Applicant vs Co-Maker: each is its own exact report.
        $this->heartbeat($other, $coMakerBSource)->assertJson(['other_editors' => []]);
        $response = $this->heartbeat($other, $coMakerASource);
        $this->assertCount(1, $response->json('other_editors'));
    }

    public function test_presence_expires_after_the_existing_ttl(): void
    {
        [$first, $folder] = $this->context();
        $second = User::factory()->create();
        $source = $this->createReport($first, $folder, 'Expiring Store');

        $this->heartbeat($first, $source)->assertOk();
        $this->assertCount(1, $this->heartbeat($second, $source)->json('other_editors'));

        $this->travel(91)->seconds();
        $this->heartbeat($second, $source)->assertOk()->assertJson(['other_editors' => []]);
    }

    public function test_first_save_wins_and_the_second_stale_save_is_blocked_with_friendly_wording(): void
    {
        [$first, $folder] = $this->context();
        $second = User::factory()->create();
        $source = $this->createReport($first, $folder, 'Original Store');
        $openedRevision = $source->fresh()->revision;

        // Both CIs are "in" the form together — advisory only.
        $this->heartbeat($first, $source)->assertOk();
        $this->heartbeat($second, $source)->assertOk();

        $this->actingAs($first)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->updatePayload('First Save', $openedRevision))
            ->assertSessionHasNoErrors();
        $this->assertSame($openedRevision + 1, $source->fresh()->revision);

        $response = $this->actingAs($second)
            ->from(route('client-folders.income-sources.edit', [$folder, $source]))
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->updatePayload('Stale Save', $openedRevision))
            ->assertSessionHasErrors(['expected_revision' => self::STALE]);

        $fresh = $source->fresh();
        $this->assertSame('First Save', $fresh->business_name);
        $this->assertSame('First Save', $fresh->businessReport->business_name);
        $this->assertSame('First Save Address', $fresh->businessReport->main_business_address);
        $this->assertSame($openedRevision + 1, $fresh->revision);

        // The redirected form shows the friendly stale banner instead of the generic field banner,
        // leaks no technical detail, and keeps the rejected token so a re-click stays blocked.
        $html = $this->actingAs($second)
            ->from(route('client-folders.income-sources.edit', [$folder, $source]))
            ->followingRedirects()
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->updatePayload('Stale Save', $openedRevision))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-business-report-stale-error', $html);
        $this->assertStringContainsString(self::STALE, $html);
        $this->assertStringNotContainsString('Please correct the highlighted Business Report fields.', $html);
        foreach (['SQLSTATE', 'App\\Models', 'revision '.$openedRevision, 'Stack trace'] as $leak) {
            $this->assertStringNotContainsString($leak, $html);
        }
    }

    public function test_a_re_click_after_a_stale_rejection_stays_blocked_until_the_report_is_refreshed(): void
    {
        [$first, $folder] = $this->context();
        $second = User::factory()->create();
        $source = $this->createReport($first, $folder, 'Original Store');
        $openedRevision = $source->fresh()->revision;
        $editUrl = route('client-folders.income-sources.edit', [$folder, $source]);

        $this->actingAs($first)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->updatePayload('First Save', $openedRevision))->assertSessionHasNoErrors();
        // The form re-rendered with the stale CI's old input still carries the stale token.
        $this->actingAs($second)->from($editUrl)->followingRedirects()
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->updatePayload('Stale Save', $openedRevision))
            ->assertOk()
            ->assertSee('name="expected_revision" value="'.$openedRevision.'"', false);

        // Re-clicking Save from that re-rendered form is still refused.
        $this->actingAs($second)->from($editUrl)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->updatePayload('Stale Save', $openedRevision))
            ->assertSessionHasErrors('expected_revision');

        // The redirect lands back on the form (consuming the flashed input); a genuine refresh
        // after that picks up the latest token.
        $this->actingAs($second)->get($editUrl)->assertOk();
        $this->actingAs($second)->get($editUrl)->assertOk()->assertSee('name="expected_revision" value="'.($openedRevision + 1).'"', false);
        $this->assertSame('First Save', $source->fresh()->business_name);
    }

    private function heartbeat(User $user, IncomeSource $source)
    {
        return $this->actingAs($user)->postJson(route('editing-presence.heartbeat'), ['type' => 'income_source', 'id' => $source->id]);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function context(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id])];
    }

    private function createReport(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $before = IncomeSource::query()->pluck('id');

        $this->actingAs($ci)
            ->post(route('client-folders.income-sources.store', $folder), ['income_source_template_id' => $template->id, 'co_maker_id' => $coMaker?->id] + $this->fields($name))
            ->assertSessionHasNoErrors();

        return IncomeSource::query()->whereNotIn('id', $before)->sole();
    }

    private function directSource(ClientFolder $folder, string $name, ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        return $folder->incomeSources()->create([
            'co_maker_id' => $coMaker?->id,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
    }

    private function updatePayload(string $name, int $revision): array
    {
        return $this->fields($name) + ['expected_revision' => $revision];
    }

    private function fields(string $name): array
    {
        return [
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
}
