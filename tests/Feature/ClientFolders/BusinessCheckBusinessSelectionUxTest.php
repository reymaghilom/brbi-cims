<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessCheckBusinessSelectionUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_business_with_an_existing_check_is_fully_hidden_from_a_new_check_while_another_remains_selectable(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $retail = $this->createBusiness($folder, 'Retail Store');
        $rental = $this->createBusiness($folder, 'Commercial Rental');
        $this->createCheck($folder, $ci, $retail);

        $response = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk();
        // The dropdown is a NEW Business Check candidate list — a business that already has one is
        // no longer shown at all (not even disabled with a label); it simply is not a candidate.
        $response->assertDontSee('Retail Store — Business Check already exists.')
            ->assertSee('Commercial Rental');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $retailOption = $xpath->query("//select[@name='income_source_id']/option[@value='{$retail->id}']")->item(0);
        $rentalOption = $xpath->query("//select[@name='income_source_id']/option[@value='{$rental->id}']")->item(0);
        $this->assertNull($retailOption, 'A business with an existing Business Check must not render as an option at all.');
        $this->assertNotNull($rentalOption);
        $this->assertFalse($rentalOption->hasAttribute('disabled'));
    }

    public function test_editing_an_existing_check_still_shows_its_own_business_selected_even_though_it_has_a_check(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $retail = $this->createBusiness($folder, 'Retail Store');
        $check = $this->createCheck($folder, $ci, $retail);

        $response = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $retailOption = $xpath->query("//select[@name='income_source_id']/option[@value='{$retail->id}']")->item(0);
        $this->assertNotNull($retailOption, 'The Check currently being edited must still show its own business as an option.');
        $this->assertFalse($retailOption->hasAttribute('disabled'));
        $this->assertSame('selected', $retailOption->getAttribute('selected'));
    }

    public function test_forged_duplicate_create_is_rejected_without_modifying_the_existing_check(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $retail = $this->createBusiness($folder, 'Retail Store');
        $existingCheck = $this->createCheck($folder, $ci, $retail);
        $originalAttributes = $existingCheck->getAttributes();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $retail->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Forged duplicate address',
            'photo_groups' => [[
                'caption' => 'Forged duplicate',
                'photos' => [UploadedFile::fake()->image('duplicate.jpg', 900, 700)->size(500)],
            ]],
        ])->assertSessionHasErrors([
            'income_source_id' => 'A Business Check already exists for the selected business. Open the existing Business Check to view or edit it.',
        ]);

        $this->assertDatabaseCount('business_checks', 1);
        $freshCheck = $existingCheck->fresh();
        foreach ($originalAttributes as $attribute => $value) {
            $this->assertSame($value, $freshCheck->getRawOriginal($attribute));
        }
        $this->assertDatabaseCount('business_check_photos', 0);
    }

    public function test_applicant_and_co_maker_business_check_scopes_are_isolated(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $coMaker = $folder->coMakers()->create(['full_name' => 'Maria Santos', 'address' => 'Co-Maker Address']);
        $applicantRetail = $this->createBusiness($folder, 'Retail Store');
        $coMakerRetail = $this->createBusiness($folder, 'Retail Store', $coMaker->id);
        $this->createCheck($folder, $ci, $applicantRetail);

        $response = $this->actingAs($ci)->get(route('client-folders.business-checks.create', [
            $folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id,
        ]))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $option = $xpath->query("//select[@name='income_source_id']/option[@value='{$coMakerRetail->id}']")->item(0);
        $applicantOption = $xpath->query("//select[@name='income_source_id']/option[@value='{$applicantRetail->id}']")->item(0);

        $this->assertNotNull($option);
        $this->assertNull($applicantOption);
        $this->assertFalse($option->hasAttribute('disabled'));
        $response->assertDontSee('Retail Store — Business Check already exists');
        $this->assertDatabaseCount('business_checks', 1);
    }

    public function test_save_action_itself_rejects_a_duplicate_create(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $retail = $this->createBusiness($folder, 'Retail Store');
        $existingCheck = $this->createCheck($folder, $ci, $retail);

        try {
            app(SaveBusinessCheck::class)->execute($ci, $folder, [
                'income_source_id' => $retail->id,
                'ci_date' => now()->toDateString(),
                'location' => 'Forged action request',
            ]);
            $this->fail('The save action allowed a duplicate Business Check create.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['A Business Check already exists for the selected business. Open the existing Business Check to view or edit it.'],
                $exception->errors()['income_source_id'],
            );
        }

        $this->assertDatabaseCount('business_checks', 1);
        $this->assertDatabaseHas('business_checks', ['id' => $existingCheck->id, 'income_source_id' => $retail->id]);
    }

    /**
     * The placeholder states what the CI is actually being asked to do, and the two states never
     * borrow each other's wording: with businesses on hand the field prompts for a selection, and
     * with none it is replaced entirely by the zero-business message.
     */
    public function test_placeholder_prompts_for_a_selection_when_the_person_has_businesses(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $this->createBusiness($folder, 'ALPHA TRADING');

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="income_source_id"[^>]*>\s*(?:<!--.*?-->\s*)*<option value="">Select an existing business<\/option>/s',
            $html,
            'The empty option is the exact "Select an existing business" prompt.',
        );
        $this->assertStringContainsString('ALPHA TRADING', $html);

        // The manual-entry wording belongs to the zero-business state only.
        $this->assertStringNotContainsString('No existing business — enter details manually', $html);
        $this->assertStringNotContainsString('No existing business found. Enter the business details below.', $html);
    }

    public function test_zero_business_state_shows_only_the_approved_message_and_no_dropdown(): void
    {
        [$ci, $folder] = $this->folderWithCi();

        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('No existing business found. Enter the business details below.', $html);
        $this->assertStringNotContainsString('Select an existing business', $html);
        $this->assertStringNotContainsString('No existing business — enter details manually', $html);
        // No dropdown is rendered at all when there is nothing to select.
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="income_source_id"/', $html);

        // Still exactly one message under the field — no Business Report helper text alongside it.
        $this->assertSame(1, substr_count($html, 'data-business-source-helper'));
        foreach ([
            'Business Report available',
            'Business Report not yet created',
            'will not create a Business Report',
            'never changes that Business Report',
            'Business Check information is available',
        ] as $retired) {
            $this->assertStringNotContainsString($retired, $html, 'No Business Report helper text: '.$retired);
        }
    }

    public function test_each_person_sees_their_own_placeholder_state_and_only_their_own_businesses(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $coMakerA = $folder->coMakers()->create(['full_name' => 'CO MAKER A', 'address' => 'A Address']);
        $coMakerB = $folder->coMakers()->create(['full_name' => 'CO MAKER B', 'address' => 'B Address']);
        $this->createBusiness($folder, 'APPLICANT STORE');
        $this->createBusiness($folder, 'CO MAKER A STORE', $coMakerA->id);

        // Applicant and Co-Maker A both have a business: each prompts for a selection, and each
        // lists only their own.
        $applicantHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Select an existing business', $applicantHtml);
        $this->assertStringContainsString('APPLICANT STORE', $applicantHtml);
        $this->assertStringNotContainsString('CO MAKER A STORE', $applicantHtml);

        $aHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.create', [
            $folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerA->id,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('Select an existing business', $aHtml);
        $this->assertStringContainsString('CO MAKER A STORE', $aHtml);
        $this->assertStringNotContainsString('APPLICANT STORE', $aHtml);

        // Co-Maker B owns nothing, so another person's business never lifts them out of the
        // zero-business state.
        $bHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.create', [
            $folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerB->id,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('No existing business found. Enter the business details below.', $bHtml);
        $this->assertStringNotContainsString('Select an existing business', $bHtml);
        $this->assertStringNotContainsString('APPLICANT STORE', $bHtml);
        $this->assertStringNotContainsString('CO MAKER A STORE', $bHtml);
    }

    /** @return array{User, ClientFolder} */
    private function folderWithCi(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        return [$ci, $folder];
    }

    private function createBusiness(ClientFolder $folder, string $name, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
        $source->businessReport()->create([
            'business_name' => $name,
            'main_business_address' => $name.' Address',
            'report_category' => $template->template_type,
        ]);
        // Represents a genuinely, explicitly saved Business Report (revision > 1) — the Business
        // Check dropdown is now Saved-Report-based (see BusinessCheckController::form()), so a
        // revision-1 draft shell would no longer appear as a candidate at all, which is not what
        // these business-selection UX tests are about.
        $source->forceFill(['revision' => 2])->save();

        return $source;
    }

    private function createCheck(ClientFolder $folder, User $ci, IncomeSource $source): BusinessCheck
    {
        return $folder->businessChecks()->create([
            'co_maker_id' => $source->co_maker_id,
            'income_source_id' => $source->id,
            'ci_user_id' => $ci->id,
            'updated_by' => $ci->id,
            'ci_date' => now()->toDateString(),
            'location' => $source->businessReport->main_business_address,
        ]);
    }
}
