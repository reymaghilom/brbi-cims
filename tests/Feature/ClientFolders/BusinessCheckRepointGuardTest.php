<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * One exact IncomeSource may be linked to at most one Business Check — on EDIT (repointing an
 * existing check) as well as on create. The FormRequest answers early; SaveBusinessCheck re-checks
 * authoritatively under the target income_sources row lock, which the direct-action tests below
 * exercise by bypassing the FormRequest (the state a concurrent, already-committed link leaves).
 *
 * SQLite :memory: runs a single connection, so these tests cannot schedule two genuinely
 * overlapping transactions; they prove the rule is enforced inside the locked transaction itself.
 */
class BusinessCheckRepointGuardTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'This business is already linked to another Business Check.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_editing_a_check_while_keeping_its_own_business_succeeds(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $checkA = $this->createCheck($ci, $folder, $sourceA);

        $this->update($ci, $folder, $checkA, $sourceA->id, ['remarks' => 'Kept business'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $checkA->fresh();
        $this->assertSame($sourceA->id, $fresh->income_source_id);
        $this->assertSame('Kept business', $fresh->remarks);
        $this->assertSame(2, $fresh->revision);
    }

    public function test_repointing_to_a_business_linked_to_another_check_is_blocked_and_changes_nothing(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $sourceB = $this->businessSource($folder, 'B Store', 'B Address');
        $checkA = $this->createCheck($ci, $folder, $sourceA);
        $checkB = $this->createCheck($ci, $folder, $sourceB);
        $beforeA = $checkA->fresh()->getAttributes();
        $beforeB = $checkB->fresh()->getAttributes();

        $this->update($ci, $folder, $checkB, $sourceA->id, ['remarks' => 'Hijack attempt'])
            ->assertSessionHasErrors(['income_source_id' => self::MESSAGE]);

        $response = $this->update($ci, $folder, $checkB, $sourceA->id, ['remarks' => 'Hijack attempt'], json: true)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['income_source_id' => self::MESSAGE]);
        $this->assertNoLeaks($response->getContent());

        $this->assertSame($beforeA, $checkA->fresh()->getAttributes());
        $this->assertSame($beforeB, $checkB->fresh()->getAttributes());
    }

    public function test_the_action_itself_rejects_the_repoint_under_the_target_lock(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $sourceB = $this->businessSource($folder, 'B Store', 'B Address');
        $checkA = $this->createCheck($ci, $folder, $sourceA);
        $checkB = $this->createCheck($ci, $folder, $sourceB);

        try {
            app(SaveBusinessCheck::class)->execute($ci, $folder, $this->payload($checkB, $sourceA->id, ['remarks' => 'Forged']));
            $this->fail('The repoint should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertSame([self::MESSAGE], $e->errors()['income_source_id']);
        }

        $this->assertSame($sourceA->id, $checkA->fresh()->income_source_id);
        $this->assertSame($sourceB->id, $checkB->fresh()->income_source_id);
        $this->assertNull($checkB->fresh()->remarks);
        $this->assertSame(1, $checkB->fresh()->revision);
    }

    public function test_a_link_committed_after_validation_still_blocks_the_create_inside_the_action(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $this->createCheck($ci, $folder, $sourceA);

        try {
            app(SaveBusinessCheck::class)->execute($ci, $folder, [
                'income_source_id' => $sourceA->id,
                'ci_date' => now()->toDateString(),
                'location' => 'A Address',
            ]);
            $this->fail('The second link should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('income_source_id', $e->errors());
        }

        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $sourceA->id)->count());
    }

    public function test_create_cannot_link_a_second_check_to_an_already_linked_business(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $this->createCheck($ci, $folder, $sourceA);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $sourceA->id,
            'ci_date' => now()->toDateString(),
            'location' => 'A Address',
            'allow_similar_duplicate' => 1,
            'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasErrors('income_source_id');

        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $sourceA->id)->count());
    }

    public function test_same_business_name_on_different_income_sources_is_not_a_false_block(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'Sari-Sari Store', 'Same Address');
        $sourceB = $this->businessSource($folder, 'Sari-Sari Store', 'Same Address');
        $sourceC = $this->businessSource($folder, 'Sari-Sari Store', 'Same Address');
        $this->createCheck($ci, $folder, $sourceA);
        $checkB = $this->createCheck($ci, $folder, $sourceB);

        $this->update($ci, $folder, $checkB, $sourceC->id)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($sourceC->id, $checkB->fresh()->income_source_id);
    }

    public function test_repointing_to_a_free_business_succeeds(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $sourceFree = $this->businessSource($folder, 'Free Store', 'Free Address');
        $checkA = $this->createCheck($ci, $folder, $sourceA);

        $this->update($ci, $folder, $checkA, $sourceFree->id)->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $checkA->fresh();
        $this->assertSame($sourceFree->id, $fresh->income_source_id);
        $this->assertSame('Free Store', $fresh->business_name);
        $this->assertSame('Free Address', $fresh->location);
    }

    public function test_the_business_released_by_a_repoint_can_be_linked_again(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $sourceFree = $this->businessSource($folder, 'Free Store', 'Free Address');
        $checkA = $this->createCheck($ci, $folder, $sourceA);
        $this->update($ci, $folder, $checkA, $sourceFree->id)->assertSessionHasNoErrors();

        $this->createCheck($ci, $folder, $sourceA);

        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $sourceA->id)->count());
    }

    public function test_manual_checks_with_no_business_remain_supported(): void
    {
        [$ci, $folder] = $this->scope();
        $sourceA = $this->businessSource($folder, 'A Store', 'A Address');
        $this->createCheck($ci, $folder, $sourceA);

        foreach (['Manual One', 'Manual Two'] as $name) {
            $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
                'business_name' => $name,
                'ci_date' => now()->toDateString(),
                'location' => $name.' Address',
                'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
            ])->assertSessionHasNoErrors()->assertRedirect();
        }

        $manual = $folder->businessChecks()->whereNull('income_source_id')->where('business_name', 'Manual One')->sole();
        $this->update($ci, $folder, $manual, null, ['remarks' => 'Still manual'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($manual->fresh()->income_source_id);
        $this->assertSame('Still manual', $manual->fresh()->remarks);
        $this->assertSame(2, $folder->businessChecks()->whereNull('income_source_id')->count());

        // A manual check can never be pointed at a business another check already owns.
        $this->update($ci, $folder, $manual->fresh(), $sourceA->id)->assertSessionHasErrors(['income_source_id' => self::MESSAGE]);
        $this->assertNull($manual->fresh()->income_source_id);
    }

    public function test_person_and_folder_isolation_is_preserved_on_repoint(): void
    {
        [$ci, $folder] = $this->scope();
        $otherFolder = $this->folderFor($ci);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicantSource = $this->businessSource($folder, 'Applicant Store', 'Applicant Address');
        $coMakerASource = $this->businessSource($folder, 'Co-Maker A Store', 'CMA Address', $coMakerA->id);
        $coMakerBSource = $this->businessSource($folder, 'Co-Maker B Store', 'CMB Address', $coMakerB->id);
        $otherFolderSource = $this->businessSource($otherFolder, 'Other Store', 'Other Address');
        $freeApplicantSource = $this->businessSource($folder, 'Free Applicant Store', 'Free Address');

        $coMakerACheck = $this->createCheck($ci, $folder, $coMakerASource, $coMakerA);
        $coMakerBCheck = $this->createCheck($ci, $folder, $coMakerBSource, $coMakerB);
        $applicantCheck = $this->createCheck($ci, $folder, $applicantSource);

        // Applicant's (unlinked-to-this-person) source used by a Co-Maker check.
        $this->update($ci, $folder, $coMakerACheck, $freeApplicantSource->id)->assertSessionHasErrors('income_source_id');
        // Co-Maker A's source used by Co-Maker B's check.
        $this->update($ci, $folder, $coMakerBCheck, $coMakerASource->id)->assertSessionHasErrors('income_source_id');
        // Another folder's source used in this folder.
        $this->update($ci, $folder, $applicantCheck, $otherFolderSource->id)->assertSessionHasErrors('income_source_id');

        $this->assertSame($coMakerASource->id, $coMakerACheck->fresh()->income_source_id);
        $this->assertSame($coMakerBSource->id, $coMakerBCheck->fresh()->income_source_id);
        $this->assertSame($applicantSource->id, $applicantCheck->fresh()->income_source_id);

        // The action refuses a cross-person target even with the FormRequest bypassed.
        $this->expectException(ModelNotFoundException::class);
        app(SaveBusinessCheck::class)->execute($ci, $folder, $this->payload($coMakerACheck, $freeApplicantSource->id));
    }

    private function assertNoLeaks(string $body): void
    {
        foreach (['SQLSTATE', 'App\\Models', 'BusinessCheck #', 'exception', 'trace'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
    }

    /** @return array<string, mixed> */
    private function payload(BusinessCheck $check, ?int $sourceId, array $overrides = []): array
    {
        return array_merge([
            'check_id' => $check->id,
            'co_maker_id' => $check->co_maker_id,
            'expected_revision' => $check->fresh()->revision,
            'income_source_id' => $sourceId,
            'business_name' => $check->business_name,
            'ci_date' => now()->toDateString(),
            'location' => $check->location,
        ], $overrides);
    }

    private function update(User $ci, ClientFolder $folder, BusinessCheck $check, ?int $sourceId, array $overrides = [], bool $json = false)
    {
        $headers = $json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [];

        return $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->payload($check, $sourceId, $overrides), $headers);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function scope(): array
    {
        $ci = User::factory()->create();

        return [$ci, $this->folderFor($ci)];
    }

    private function createCheck(User $ci, ClientFolder $folder, IncomeSource $source, ?CoMaker $coMaker = null): BusinessCheck
    {
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Fallback Address',
            'photo_groups' => [['photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();

        return BusinessCheck::query()->where('income_source_id', $source->id)->sole();
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $name,
            'business_name' => $name,
        ]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
