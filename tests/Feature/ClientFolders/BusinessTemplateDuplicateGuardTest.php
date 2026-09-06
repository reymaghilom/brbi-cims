<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Http\Requests\ClientFolders\StoreIncomeSourceRequest;
use App\Models\BusinessCheck;
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
 * A second business may not be created under an identity this exact person already uses.
 *
 * Identity is ClientFolder + exact person + exact template, except for "Other Business/Source of
 * Income", which may legitimately repeat and is therefore compared one level deeper: by the exact
 * set of income-source keys ticked on it. The guard lives in validation, so a rejected attempt
 * writes nothing at all rather than being created and cleaned up afterwards.
 */
class BusinessTemplateDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One wording for a standard duplicate template, wherever it surfaces: inline in the Select
     * Business Template modal, and from the server when the picker is bypassed.
     */
    private const TEMPLATE_MESSAGE = 'This business template already exists for this client. Please select another template.';

    private const COMBINATION_MESSAGE = 'A Business Report with these selected business categories already exists. Please select a different combination or add another category to continue.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_a_second_business_on_the_same_standard_template_is_refused_before_anything_is_written(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->standardTemplate();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'FIRST STORE'))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, IncomeSource::query()->count());

        $before = [IncomeSource::query()->count(), BusinessReport::query()->count()];

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'SECOND STORE'))
            ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);

        // Nothing was created and then removed — nothing was created at all.
        $this->assertSame($before[0], IncomeSource::query()->count());
        $this->assertSame($before[1], BusinessReport::query()->count());
        $this->assertSame('FIRST STORE', IncomeSource::query()->sole()->business_name, 'The existing business is untouched.');
    }

    public function test_a_different_standard_template_is_still_allowed(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($this->standardTemplate(), 'RETAIL STORE'))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($this->standardTemplate('leasing_non_agricultural'), 'LEASING BUSINESS'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, IncomeSource::query()->count(), 'Different templates are different businesses.');
    }

    public function test_one_persons_template_never_blocks_another_person(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $template = $this->standardTemplate();

        // Applicant first.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'APPLICANT STORE'))
            ->assertSessionHasNoErrors();

        // The same template is still free for each Co-Maker.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CO-MAKER A STORE', coMakerId: $coMakerA->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CO-MAKER B STORE', coMakerId: $coMakerB->id))
            ->assertSessionHasNoErrors();

        // ...but each person is blocked on their own second attempt, and only their own.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CO-MAKER A AGAIN', coMakerId: $coMakerA->id))
            ->assertSessionHasErrors('income_source_template_id');

        $this->assertSame(3, IncomeSource::query()->count());
        $this->assertSame(1, IncomeSource::query()->whereNull('co_maker_id')->count());
        $this->assertSame(1, IncomeSource::query()->where('co_maker_id', $coMakerA->id)->count());
        $this->assertSame(1, IncomeSource::query()->where('co_maker_id', $coMakerB->id)->count());
    }

    public function test_other_business_repeats_only_when_the_ticked_set_really_differs(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->otherBusinessTemplate();

        // [A, B]
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER ONE', ['farming', 'livestock']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, IncomeSource::query()->count());

        // The identical set is a duplicate.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER DUP', ['farming', 'livestock']))
            ->assertSessionHasErrors([StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY => self::COMBINATION_MESSAGE]);

        // So is the same set in a different order — order carries no meaning — and it is refused in
        // the very same wording, so [A, B] and [B, A] can never read as two different problems.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER REORDERED', ['livestock', 'farming']))
            ->assertSessionHasErrors([StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY => self::COMBINATION_MESSAGE]);

        // ...and so is the same set with duplicates or stray casing/whitespace in the request.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER NOISY', [' Livestock ', 'FARMING', 'farming']))
            ->assertSessionHasErrors(StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY);

        $this->assertSame(1, IncomeSource::query()->count(), 'None of the duplicate attempts created anything.');

        // A genuinely different set is a different business.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER SWAPPED', ['farming', 'transportation']))
            ->assertSessionHasNoErrors();
        // Adding a category makes it different too.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER WIDER', ['farming', 'livestock', 'online_selling']))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, IncomeSource::query()->count());
        $this->assertSame(3, IncomeSource::query()->where('income_source_template_id', $template->id)->count());
    }

    public function test_a_narrower_other_business_set_is_a_different_business(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->otherBusinessTemplate();

        // [A, B, C] then [A, B] — exact-set equality, never overlap, so this is allowed.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER WIDE', ['farming', 'livestock', 'online_selling']))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER NARROW', ['farming', 'livestock']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, IncomeSource::query()->count());

        // But repeating the narrower set now is a duplicate of that second business.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER NARROW AGAIN', ['livestock', 'farming']))
            ->assertSessionHasErrors(StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY);
        $this->assertSame(2, IncomeSource::query()->count());
    }

    public function test_editing_an_existing_business_is_never_compared_against_itself(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->standardTemplate();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'EDITABLE STORE'))
            ->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();

        // Saving the same business again through its own edit route stays allowed, repeatedly.
        foreach (['EDITABLE STORE RENAMED', 'EDITABLE STORE FINAL'] as $name) {
            $this->actingAs($ci)
                ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->payload($template, $name) + [
                    'expected_revision' => $source->fresh()->revision,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, IncomeSource::query()->count(), 'Editing never creates a second business.');
        $this->assertSame('EDITABLE STORE FINAL', $source->fresh()->business_name);
    }

    public function test_a_business_check_first_business_is_reused_not_re_created(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->standardTemplate();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CHECK FIRST STORE'))
            ->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();
        BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Check Location',
            'ci_user_id' => $ci->id,
        ]);

        // Continuing that same business through its own route is unaffected by the guard.
        $this->actingAs($ci)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $this->payload($template, 'CHECK FIRST STORE') + [
                'expected_revision' => $source->fresh()->revision,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, IncomeSource::query()->count());
        $this->assertSame(1, BusinessReport::query()->count());
        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $source->id)->count());
    }

    public function test_next_refuses_a_standard_template_this_person_already_uses(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->standardTemplate('leasing_truck_equipment');

        // First time through: Next opens the encoding form normally.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertOk();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'TRUCKING SERVICES'))
            ->assertSessionHasNoErrors();
        $before = [IncomeSource::query()->count(), BusinessReport::query()->count()];

        // Second time: Next is turned away before the encoding form is ever rendered.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertRedirect(route('client-folders.income-sources.manage', $folder))
            ->assertSessionHas('status', self::TEMPLATE_MESSAGE)
            ->assertSessionHas('statusType', 'error');

        $this->assertSame($before[0], IncomeSource::query()->count());
        $this->assertSame($before[1], BusinessReport::query()->count());

        // A different standard template still opens.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $this->standardTemplate()->id]))
            ->assertOk();
    }

    public function test_next_is_scoped_to_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER B']);
        $template = $this->standardTemplate('leasing_truck_equipment');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'APPLICANT TRUCKING'))
            ->assertSessionHasNoErrors();

        // The Applicant is now blocked, but neither Co-Maker is.
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertRedirect();
        foreach ([$coMakerA, $coMakerB] as $coMaker) {
            $this->actingAs($ci)
                ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
                ->assertOk();
        }

        // Co-Maker A takes it; Co-Maker B is still free.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CO-MAKER A TRUCKING', coMakerId: $coMakerA->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id]))
            ->assertRedirect();
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id]))
            ->assertOk();
    }

    public function test_next_always_lets_other_business_through_so_save_can_judge_the_categories(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->otherBusinessTemplate();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER ONE', ['farming', 'livestock']))
            ->assertSessionHasNoErrors();

        // Even with one already saved, Next must open the form — the identity is the ticked set,
        // which cannot be known until Save.
        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->assertOk();

        // And Save is where the exact-set duplicate is caught.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER DUP', ['livestock', 'farming']))
            ->assertSessionHasErrors(StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY);
        $this->assertSame(1, IncomeSource::query()->count());
    }

    public function test_the_template_picker_lists_the_templates_next_will_refuse(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $standard = $this->standardTemplate('leasing_truck_equipment');
        $other = $this->otherBusinessTemplate();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($standard, 'TRUCKING SERVICES'))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($other, 'OTHER ONE', ['farming']))
            ->assertSessionHasNoErrors();

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();

        // The standard template is advertised to the picker; Other Business never is, because it
        // may legitimately repeat.
        // The list holds that one standard template and nothing else — Other Business is absent by
        // construction, because it may legitimately repeat.
        $this->assertStringContainsString('data-used-template-ids="['.$standard->id.']"', $html);
        $this->assertNotSame($standard->id, $other->id);
        // The picker states the refusal inline, right under the selector, in the very same wording
        // the server uses when the picker is bypassed (IncomeSourceController::launch()).
        $this->assertStringContainsString(e(self::TEMPLATE_MESSAGE), $html);
    }

    public function test_an_unrelated_business_never_makes_another_template_look_taken(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $remittance = $this->standardTemplate('remittance_income');

        // Nothing exists yet: Remittance is free.
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertOk();

        // An unrelated business exists: Remittance is still free — only its own template counts.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($this->standardTemplate('trucking_services'), 'TRUCKING SERVICES'))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertOk();

        // Only an existing Remittance report takes Remittance.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE INCOME'))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertRedirect()->assertSessionHas('status', self::TEMPLATE_MESSAGE);
    }

    public function test_a_template_is_free_again_once_its_business_report_was_intentionally_deleted(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $remittance = $this->standardTemplate('remittance_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE INCOME'))
            ->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();

        // While the report exists the template is taken.
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertRedirect();

        // Deleting that Business Report leaves the IncomeSource behind but no report — so the
        // person genuinely has no Remittance report and the template is free again.
        app(DeleteBusinessReport::class)->execute($ci, $folder, $source);

        $this->assertNotNull(IncomeSource::query()->find($source->id), 'The business itself survives.');
        $this->assertNotNull($source->fresh()->business_report_deleted_at);
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertOk();

        // The picker agrees with the server — it no longer advertises that template as taken.
        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-used-template-ids="[]"', $html);
    }

    public function test_the_duplicate_category_error_is_stated_once_at_the_top_of_the_form(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->otherBusinessTemplate();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER ONE', ['farming', 'livestock']))
            ->assertSessionHasNoErrors();

        // Follow the validation redirect so the form renders exactly as the CI would see it.
        $html = $this->actingAs($ci)
            ->from(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id]))
            ->followingRedirects()
            ->post(route('client-folders.income-sources.store', $folder), $this->otherPayload($template, 'OTHER DUP', ['livestock', 'farming']))
            ->assertOk()
            ->getContent();

        // Stated exactly once, in the top banner, and the generic wording is not used for it.
        $this->assertSame(1, substr_count($html, self::COMBINATION_MESSAGE));
        $this->assertStringContainsString('data-business-duplicate-categories-error', $html);
        $this->assertStringNotContainsString('Please correct the highlighted Business Report fields.', $html);
        $this->assertLessThan(
            strpos($html, 'business-report-official-title'),
            strpos($html, 'data-business-duplicate-categories-error'),
            'The duplicate message sits above the report body.'
        );

        $this->assertSame(1, IncomeSource::query()->count(), 'The rejected save created nothing.');
    }

    /**
     * The proven cause of the false "already exists" on Save: the Save-time sibling query counted
     * any surviving IncomeSource, while Next counted only ones holding a live Business Report. A
     * template freed by deleting its report passed Next and was then refused by Save, and because
     * income_source_template_id is a hidden field the form could only show the generic banner.
     */
    public function test_a_template_freed_by_deleting_its_report_passes_next_and_save_alike(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $remittance = $this->standardTemplate('remittance_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE ONE'))
            ->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source);
        $this->assertNotNull($source->fresh()->business_report_deleted_at);
        $this->assertNotNull(IncomeSource::query()->find($source->id), 'The business itself survives the report delete.');

        // Next allows it...
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertOk();

        // ...and Save agrees, rather than refusing on the hidden template field.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE TWO'))
            ->assertSessionHasNoErrors()
            ->assertSessionDoesntHaveErrors('income_source_template_id');

        $this->assertSame(2, IncomeSource::query()->count());
        $this->assertSame(1, BusinessReport::query()->count(), 'Only the new report exists; the deleted one stayed deleted.');
    }

    public function test_a_business_check_business_with_no_report_yet_never_blocks_its_own_report(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = $this->standardTemplate('remittance_income');

        // A business created by the Business Check flow: a real IncomeSource with no report row.
        $business = app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => 'CHECK FIRST',
            'business_name' => 'CHECK FIRST',
        ]);
        BusinessReport::query()->where('income_source_id', $business->id)->delete();
        $this->assertSame(0, BusinessReport::query()->count());

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($template, 'CHECK FIRST REPORT'))
            ->assertSessionHasNoErrors();
    }

    public function test_an_unrelated_template_never_blocks_another_on_save(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($this->standardTemplate('restaurant_food_stall'), 'RESTAURANT'))
            ->assertSessionHasNoErrors();

        // Restaurant existing says nothing about Remittance, at Next or at Save.
        $remittance = $this->standardTemplate('remittance_income');
        $this->actingAs($ci)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $remittance->id]))
            ->assertOk();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, IncomeSource::query()->count());
    }

    /** The protection itself is unchanged: a template genuinely in use is still refused at Save. */
    public function test_a_live_report_still_blocks_the_same_template_on_save(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $remittance = $this->standardTemplate('remittance_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE ONE'))
            ->assertSessionHasNoErrors();

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE TWO'))
            ->assertSessionHasErrors(['income_source_template_id' => self::TEMPLATE_MESSAGE]);

        $this->assertSame(1, IncomeSource::query()->count(), 'The refused attempt wrote nothing.');
    }

    public function test_one_persons_deleted_report_never_frees_or_blocks_another_persons_template(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER']);
        $remittance = $this->standardTemplate('remittance_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'APPLICANT REMITTANCE'))
            ->assertSessionHasNoErrors();

        // The Applicant's live report never blocks the Co-Maker.
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'CO MAKER REMITTANCE', $coMaker->id))
            ->assertSessionHasNoErrors();

        // Deleting the Applicant's report frees the Applicant only - the Co-Maker stays blocked.
        app(DeleteBusinessReport::class)->execute($ci, $folder, IncomeSource::query()->whereNull('co_maker_id')->sole());

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'APPLICANT AGAIN'))
            ->assertSessionHasNoErrors();
        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'CO MAKER AGAIN', $coMaker->id))
            ->assertSessionHasErrors('income_source_template_id');
    }

    /**
     * The picker is a page-level <dialog> outside the refreshed panel, so its duplicate list used
     * to freeze at first render and then refuse templates the server would have allowed. The
     * refresh payload now restates it from the same server-computed value the page uses.
     */
    public function test_the_refresh_payload_restates_the_pickers_used_template_list(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $remittance = $this->standardTemplate('remittance_income');

        $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), $this->payload($remittance, 'REMITTANCE ONE'))
            ->assertSessionHasNoErrors();
        $source = IncomeSource::query()->sole();

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-used-template-ids="['.$remittance->id.']"', $html);

        // Deleting the report through the same endpoint the page uses returns the corrected list,
        // so the picker stops advertising that template without any reload.
        $payload = $this->actingAs($ci)
            ->deleteJson(route('client-folders.income-sources.business-report.destroy', [$folder, $source]))
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('usedTemplateIds', $payload);
        $this->assertSame([], $payload['usedTemplateIds']);

        // ...and a fresh page load agrees with what the refresh handed the picker.
        $this->assertStringContainsString(
            'data-used-template-ids="[]"',
            $this->actingAs($ci)->get(route('client-folders.income-sources.manage', $folder))->getContent()
        );
    }

    /**
     * The picker's list is state, not a one-off render, so it is re-stated from the authoritative
     * refresh payload rather than left to drift - and never patched over with a delay or a reload.
     */
    public function test_the_refresh_handler_restates_the_picker_dataset_without_timers_or_reloads(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $start = strpos($script, 'function applyBusinessManageRefresh(');
        $this->assertNotFalse($start, 'The refresh handler must still exist.');
        $body = substr($script, $start, strpos($script, "\n}", $start) - $start);

        $this->assertStringContainsString('payload.usedTemplateIds', $body);
        $this->assertStringContainsString('[data-add-business-template-select]', $body);
        $this->assertStringContainsString('select.dataset.usedTemplateIds = JSON.stringify(payload.usedTemplateIds);', $body);

        foreach (['setTimeout', 'setInterval', 'location.reload', 'window.location.href'] as $shortcut) {
            $this->assertStringNotContainsString($shortcut, $body, 'The picker state is corrected directly, never by '.$shortcut.'.');
        }

        // The duplicate check itself still reads that dataset at click time, so a corrected list
        // takes effect on the very next Next - including the first click after a hard refresh.
        $guardAt = strpos($script, 'usedTemplateIds = JSON.parse(templateSelect.dataset.usedTemplateIds');
        $this->assertNotFalse($guardAt, 'The Next guard must still read the live dataset.');
    }

    private function standardTemplate(string $templateType = 'retail_grocery_water_refilling'): IncomeSourceTemplate
    {
        return IncomeSourceTemplate::query()->where('template_type', $templateType)->firstOrFail();
    }

    private function otherBusinessTemplate(): IncomeSourceTemplate
    {
        return IncomeSourceTemplate::query()->where('template_type', 'other_business_source_of_income')->firstOrFail();
    }

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
    private function otherPayload(IncomeSourceTemplate $template, string $name, array $incomeSources, ?int $coMakerId = null): array
    {
        return $this->payload($template, $name, $coMakerId) + [
            'template_data' => ['fields' => ['income_sources' => $incomeSources]],
            'report_remarks' => $name.' details.',
        ];
    }
}
