<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Borrower (Applicant, co_maker_id NULL) and Co-Maker coverage for the duplicate-submission
 * protections of Manage Business and Manage Residence & Business Report.
 *
 * Both person types share the same routes, forms, controllers and JavaScript; only co_maker_id (in
 * the body, or person=co-maker&co_maker_id in the query) differs. Every scenario therefore runs
 * once per person type, plus explicit cross-person cases proving that a retry, a replayed token or
 * a delete for one person can never create, reject or remove another person's record.
 */
class BorrowerCoMakerDuplicateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_MESSAGE = 'This business template already exists for this client. Please select another template.';

    private User $ci;

    private ClientFolder $folder;

    private CoMaker $coMakerA;

    private CoMaker $coMakerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        $this->ci = User::factory()->create();
        $this->folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $this->coMakerA = CoMaker::create(['client_folder_id' => $this->folder->id, 'full_name' => 'Co-Maker A']);
        $this->coMakerB = CoMaker::create(['client_folder_id' => $this->folder->id, 'full_name' => 'Co-Maker B']);
    }

    /** @return array<string, array{0: string}> */
    public static function people(): array
    {
        return ['borrower' => ['borrower'], 'co-maker' => ['co-maker']];
    }

    // ---------------------------------------------------------------- Manage Business

    #[DataProvider('people')]
    public function test_an_identical_business_add_retry_saves_once_for_that_exact_person(string $who): void
    {
        $person = $this->person($who);
        $payload = $this->businessPayload('RETRY STORE', $person) + ['submission_token' => (string) Str::uuid()];

        $this->actingAs($this->ci)->post($this->storeUrl(), $payload)->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();
        $editUrl = route('client-folders.income-sources.edit', [$this->folder, $source] + $this->personParams($person));

        for ($retry = 0; $retry < 2; $retry++) {
            $this->actingAs($this->ci)->post($this->storeUrl(), $payload)
                ->assertRedirect($editUrl)
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'Business Report saved successfully.');
        }

        $this->assertSame($person?->id, $source->co_maker_id);
        $this->assertSame(1, IncomeSource::query()->count());
        $this->assertSame(1, BusinessReport::query()->count());
    }

    public function test_the_borrower_and_each_co_maker_may_each_hold_the_same_business_template(): void
    {
        foreach ([null, $this->coMakerA, $this->coMakerB] as $person) {
            $this->actingAs($this->ci)->post($this->storeUrl(), $this->businessPayload('SAME TEMPLATE', $person) + ['submission_token' => (string) Str::uuid()])
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing([null, $this->coMakerA->id, $this->coMakerB->id], IncomeSource::query()->pluck('co_maker_id')->all());

        // A genuine second add for Co-Maker A alone meets the existing per-person template rule.
        $this->actingAs($this->ci)->post($this->storeUrl(), $this->businessPayload('SAME TEMPLATE', $this->coMakerA) + ['submission_token' => (string) Str::uuid()])
            ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);
        $this->assertSame(3, IncomeSource::query()->count());
    }

    public function test_a_token_used_for_one_person_is_never_replayed_for_another_person(): void
    {
        $token = (string) Str::uuid();

        $this->actingAs($this->ci)->post($this->storeUrl(), $this->businessPayload('SHARED TOKEN', $this->coMakerA) + ['submission_token' => $token])->assertSessionHasNoErrors();
        // Same token, other person: a different payload, so it runs on its own and is a legitimate
        // first business for Co-Maker B — never redirected to Co-Maker A's record.
        $this->actingAs($this->ci)->post($this->storeUrl(), $this->businessPayload('SHARED TOKEN', $this->coMakerB) + ['submission_token' => $token])->assertSessionHasNoErrors();
        $this->actingAs($this->ci)->post($this->storeUrl(), $this->businessPayload('SHARED TOKEN', null) + ['submission_token' => $token])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([null, $this->coMakerA->id, $this->coMakerB->id], IncomeSource::query()->pluck('co_maker_id')->all());
    }

    #[DataProvider('people')]
    public function test_business_edit_page_update_retry_and_delete_retry_stay_inside_that_person(string $who): void
    {
        $person = $this->person($who);
        $other = $person === null ? $this->coMakerA : null;
        $source = $this->savedBusiness('OWN STORE', $person);
        $otherSource = $this->savedBusiness('OTHER STORE', $other);
        $params = $this->personParams($person);

        // The edit page carries the active person, a fresh token and the pending labels.
        $page = $this->actingAs($this->ci)->get(route('client-folders.income-sources.edit', [$this->folder, $source] + $params))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form id="business-template-form"[^>]*data-submit-guard data-submit-pending-label="Saving\.\.\."/', $page);
        $this->assertMatchesRegularExpression('/<form id="business-report-form"[^>]*data-submit-guard data-submit-pending-label="Updating\.\.\."/', $page);
        $this->assertSame(2, preg_match_all('/name="submission_token" value="[0-9a-f-]{36}"/', $page));
        $this->assertSame(2, substr_count($page, 'name="co_maker_id" value="'.($person?->id ?? '').'"'));

        // Identical update retry: replayed success, saved exactly once.
        $token = (string) Str::uuid();
        $revision = $source->revision;
        $this->updateBusiness($source, 'OWN EDIT', $person, $revision, $token)->assertSessionHasNoErrors();
        $this->updateBusiness($source, 'OWN EDIT', $person, $revision, $token)
            ->assertRedirect(route('client-folders.income-sources.edit', [$this->folder, $source] + $params))
            ->assertSessionHas('status', 'Business Report updated successfully.');
        $this->assertSame($revision + 1, $source->fresh()->revision);

        // Delete, then the same delete again.
        $url = route('client-folders.income-sources.business-report.destroy', [$this->folder, $source] + $params);
        $this->actingAs($this->ci)->deleteJson($url, ['expected_revision' => $source->fresh()->revision])->assertOk()->assertJson(['deleted' => 1]);
        $this->actingAs($this->ci)->deleteJson($url, ['expected_revision' => $source->fresh()?->revision ?? 0])->assertNotFound();

        $this->assertSame('OTHER STORE', $otherSource->fresh()->businessReport->business_name, 'The other person\'s business is untouched.');
        $this->assertSame(1, BusinessReport::query()->count());
    }

    // ---------------------------------------------------------------- Residence & Business Report

    #[DataProvider('people')]
    public function test_a_residence_check_add_retry_saves_once_and_the_other_person_can_still_add_theirs(string $who): void
    {
        $person = $this->person($who);
        $other = $person === null ? $this->coMakerA : null;

        $this->residenceAjax($person, 'retry-token')->assertOk()->assertJson(['result' => 'success']);
        $this->residenceAjax($person, 'retry-token')->assertOk()->assertJson(['result' => 'success']);
        $this->assertSame(1, ResidenceCheck::query()->where('co_maker_id', $person?->id)->count());

        // A legitimately separate person is never rejected by this person's check.
        $this->residenceAjax($other, 'other-token')->assertOk()->assertJson(['result' => 'success']);
        $this->assertEqualsCanonicalizing([$person?->id, $other?->id], ResidenceCheck::query()->pluck('co_maker_id')->all());
    }

    #[DataProvider('people')]
    public function test_a_business_check_add_retry_saves_once_and_cannot_link_another_persons_business(string $who): void
    {
        $person = $this->person($who);
        $other = $person === null ? $this->coMakerA : null;
        $own = $this->businessSource('OWN CHECK STORE', $person);
        $foreign = $this->businessSource('FOREIGN CHECK STORE', $other);
        $token = (string) Str::uuid();

        $this->businessCheckPost($own, $person, $token)->assertSessionHasNoErrors();
        $this->businessCheckPost($own, $person, $token)->assertSessionHasNoErrors();
        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $own->id)->count());

        // Linking the other person's business while acting as this person creates nothing.
        $this->businessCheckPost($foreign, $person, (string) Str::uuid());
        $this->assertSame(0, BusinessCheck::query()->where('income_source_id', $foreign->id)->count());
        $this->assertSame([$person?->id], BusinessCheck::query()->pluck('co_maker_id')->all());
    }

    #[DataProvider('people')]
    public function test_check_deletes_are_guarded_and_a_retry_never_reaches_another_persons_checks(string $who): void
    {
        $person = $this->person($who);
        $other = $person === null ? $this->coMakerA : null;
        $params = $this->personParams($person);
        $residence = $this->residenceCheck($person);
        $business = $this->businessCheckRow($this->businessSource('OWN LISTED STORE', $person), $person);
        $otherResidence = $this->residenceCheck($other);
        $otherBusiness = $this->businessCheckRow($this->businessSource('OTHER LISTED STORE', $other), $other);

        $html = $this->actingAs($this->ci)->get(route('client-folders.residence-business.edit', [$this->folder] + $params))->assertOk()->getContent();
        $actions = [];
        foreach (['delete-residence-check-'.$residence->id, 'delete-business-check-'.$business->id] as $dialogId) {
            $start = strpos($html, 'id="'.$dialogId.'"');
            $this->assertNotFalse($start, $dialogId.' not rendered for this person.');
            $this->assertSame(1, preg_match('/<form method="POST" action="([^"]+)" class="w-full sm:w-auto" data-submit-guard data-submit-pending-label="Deleting\.\.\.">/', substr($html, $start, 3000), $form));
            $actions[] = html_entity_decode($form[1]);
        }
        $this->assertSame(route('client-folders.residence-checks.destroy', [$this->folder, $residence] + $params), $actions[0]);
        $this->assertSame(route('client-folders.business-checks.destroy', [$this->folder, $business] + $params), $actions[1]);
        $this->assertStringNotContainsString('id="delete-residence-check-'.$otherResidence->id.'"', $html, 'Only this person\'s checks are listed.');

        // A batch naming another person's check is refused whole.
        $batchUrl = route('client-folders.residence-business-checks.batch-delete', $this->folder);
        $this->actingAs($this->ci)->post($batchUrl, ['co_maker_id' => $person?->id, 'residence_check_ids' => [$residence->id, $otherResidence->id]])->assertNotFound();
        $this->assertModelExists($residence);

        foreach ($actions as $url) {
            $this->actingAs($this->ci)->delete($url)->assertRedirect(route('client-folders.residence-business.edit', [$this->folder] + $params));
            $this->actingAs($this->ci)->delete($url)->assertNotFound();
        }

        $this->assertModelMissing($residence);
        $this->assertModelMissing($business);
        $this->assertModelExists($otherResidence);
        $this->assertModelExists($otherBusiness);
    }

    // ---------------------------------------------------------------- Helpers

    private function person(string $who): ?CoMaker
    {
        return $who === 'co-maker' ? $this->coMakerA : null;
    }

    private function personParams(?CoMaker $person): array
    {
        return $person === null ? [] : ['person' => 'co-maker', 'co_maker_id' => $person->id];
    }

    private function storeUrl(): string
    {
        return route('client-folders.income-sources.store', $this->folder);
    }

    private function businessPayload(string $name, ?CoMaker $person): array
    {
        return [
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail()->id,
            'co_maker_id' => $person?->id,
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

    private function savedBusiness(string $name, ?CoMaker $person): IncomeSource
    {
        $this->actingAs($this->ci)->post($this->storeUrl(), $this->businessPayload($name, $person))->assertSessionHasNoErrors();

        return IncomeSource::query()->where('business_name', $name)->sole();
    }

    private function updateBusiness(IncomeSource $source, string $name, ?CoMaker $person, int $revision, string $token)
    {
        return $this->actingAs($this->ci)->put(
            route('client-folders.income-sources.business.update', [$this->folder, $source]),
            ['expected_revision' => $revision, 'submission_token' => $token] + $this->businessPayload($name, $person),
        );
    }

    private function residenceAjax(?CoMaker $person, string $token)
    {
        return $this->actingAs($this->ci)->post(route('client-folders.residence-checks.store', $this->folder), [
            'co_maker_id' => $person?->id,
            'request_token' => $token,
            'ci_date' => now()->toDateString(),
            'location' => 'Residence location',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function residenceCheck(?CoMaker $person): ResidenceCheck
    {
        $check = new ResidenceCheck;
        $check->forceFill([
            'client_folder_id' => $this->folder->id, 'co_maker_id' => $person?->id, 'ci_date' => now()->toDateString(),
            'location' => 'Saved location', 'ci_user_id' => $this->ci->id,
        ])->save();

        return $check;
    }

    private function businessCheckPost(IncomeSource $source, ?CoMaker $person, string $token)
    {
        return $this->actingAs($this->ci)->post(route('client-folders.business-checks.store', $this->folder), [
            'co_maker_id' => $person?->id,
            'request_token' => $token,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)],
        ]);
    }

    private function businessCheckRow(IncomeSource $source, ?CoMaker $person): BusinessCheck
    {
        $this->businessCheckPost($source, $person, (string) Str::uuid())->assertSessionHasNoErrors();

        return BusinessCheck::query()->where('income_source_id', $source->id)->sole();
    }

    private function businessSource(string $businessName, ?CoMaker $person): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $this->folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $businessName,
            'business_name' => $businessName,
            'co_maker_id' => $person?->id,
        ]);
        $source->businessReport()->create([
            'business_name' => $businessName,
            'main_business_address' => 'Poblacion, San Miguel, Bulacan',
            'report_category' => 'retail_grocery_water_refilling',
        ]);

        return $source;
    }
}
