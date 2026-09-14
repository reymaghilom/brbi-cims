<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Reports\ReportWorkItem;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * After a REPORT-ONLY Business Report delete the IncomeSource survives and its independent Business
 * Check is still Pending in Reports. The Business Check create form must therefore still offer that
 * exact surviving business, so the generic Pending entry can be completed against the same
 * income_source_id — without admitting drafts, other people's or folders' businesses, businesses
 * that already have a check, or fully deleted ones.
 *
 * Saves go through SaveBusinessCheck without files, so nothing is written to any document root.
 */
class BusinessCheckSurvivingBusinessSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_surviving_business_is_selectable_and_links_back_to_the_same_income_source(): void
    {
        [$ci, $folder] = $this->folderWithCi('APPLICANT CLIENT');
        $source = $this->savedBusiness($ci, $folder, 'Surviving Store');

        $this->assertContains($source->id, $this->offeredIds($ci, $folder), 'A saved Business Report is offered.');

        app(DeleteBusinessReport::class)->execute($ci, $folder, $source->fresh());

        $this->assertNotNull(IncomeSource::query()->find($source->id), 'The IncomeSource survives.');
        $this->assertSame(1, $this->pendingChecks($ci, $folder, null), 'The generic Pending Business Check remains.');
        $this->assertContains($source->id, $this->offeredIds($ci, $folder), 'The surviving business is still selectable.');

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => '2026-09-02', 'location' => 'Typed Address',
        ]);

        $this->assertSame($source->id, $check->income_source_id);
        $this->assertSame(1, IncomeSource::query()->where('client_folder_id', $folder->id)->count(), 'No second business is created.');
        $this->assertSame(0, $this->pendingChecks($ci, $folder, null), 'The Pending entry is answered.');
        $this->assertNotContains($source->id, $this->offeredIds($ci, $folder), 'Once checked it is no longer a duplicate candidate.');
    }

    public function test_co_maker_surviving_business_is_offered_only_to_that_exact_co_maker(): void
    {
        [$ci, $folder] = $this->folderWithCi('CO-MAKER CLIENT');
        [, $otherFolder] = $this->folderWithCi('OTHER CLIENT');
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);

        $applicantSource = $this->savedBusiness($ci, $folder, 'Applicant Store');
        $sourceA = $this->savedBusiness($ci, $folder, 'Co-Maker A Store', $coMakerA);
        $otherFolderSource = $this->savedBusiness($ci, $otherFolder, 'Other Folder Store');
        foreach ([[$folder, $applicantSource], [$folder, $sourceA], [$otherFolder, $otherFolderSource]] as [$ownerFolder, $source]) {
            app(DeleteBusinessReport::class)->execute($ci, $ownerFolder, $source->fresh());
        }

        $this->assertSame(1, $this->pendingChecks($ci, $folder, $coMakerA->id));
        $this->assertSame([$sourceA->id], $this->offeredIds($ci, $folder, $coMakerA));
        $this->assertSame([$applicantSource->id], $this->offeredIds($ci, $folder));
        $this->assertSame([], $this->offeredIds($ci, $folder, $coMakerB), "Co-Maker A's business never reaches Co-Maker B.");
        $this->assertNotContains($otherFolderSource->id, [...$this->offeredIds($ci, $folder), ...$this->offeredIds($ci, $folder, $coMakerA)]);

        $check = app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => $coMakerA->id, 'income_source_id' => $sourceA->id,
            'ci_date' => '2026-09-02', 'location' => 'Co-Maker Address',
        ]);
        $this->assertSame($sourceA->id, $check->income_source_id);
        $this->assertSame($coMakerA->id, $check->co_maker_id);
    }

    public function test_drafts_already_checked_and_fully_deleted_businesses_are_still_excluded(): void
    {
        [$ci, $folder] = $this->folderWithCi('EXCLUSION CLIENT');

        // A revision-1 unsaved shell is not admitted merely because it exists.
        $draft = $this->draftBusiness($ci, $folder, 'Draft Shell Store');

        // A report-deleted business that already has its Business Check is not a duplicate candidate.
        $checked = $this->savedBusiness($ci, $folder, 'Checked Store', null, 'leasing_non_agricultural');
        app(SaveBusinessCheck::class)->execute($ci, $folder, [
            'check_id' => null, 'co_maker_id' => null, 'income_source_id' => $checked->id,
            'ci_date' => '2026-09-02', 'location' => 'Checked Address',
        ]);
        app(DeleteBusinessReport::class)->execute($ci, $folder, $checked->fresh());

        // A fully deleted business cannot appear at all.
        $gone = $this->savedBusiness($ci, $folder, 'Gone Store', null, 'leasing_agricultural');
        app(DeleteIncomeSource::class)->execute($ci, $folder, $gone->fresh());

        $offered = $this->offeredIds($ci, $folder);
        $this->assertNotContains($draft->id, $offered);
        $this->assertNotContains($checked->id, $offered);
        $this->assertNotContains($gone->id, $offered);
        $this->assertSame(1, BusinessCheck::query()->where('income_source_id', $checked->id)->count());
    }

    /** @return list<int> the income_source_id values the create form's Select Business dropdown renders */
    private function offeredIds(User $ci, ClientFolder $folder, ?CoMaker $coMaker = null): array
    {
        $query = $coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : [];
        $html = $this->actingAs($ci)->get(route('client-folders.business-checks.create', [$folder] + $query))->assertOk()->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $ids = [];
        foreach ((new \DOMXPath($document))->query("//select[@name='income_source_id']/option[@value!='']") as $option) {
            $ids[] = (int) $option->getAttribute('value');
        }
        sort($ids);

        return $ids;
    }

    private function pendingChecks(User $ci, ClientFolder $folder, ?int $coMakerId): int
    {
        return collect($this->actingAs($ci)->get(route('reports.index', ['client_folder_id' => $folder->id]))->assertOk()->viewData('items')->items())
            ->filter(fn (ReportWorkItem $item): bool => $item->kind === 'business_check' && ! $item->isCompleted && $item->coMakerId === $coMakerId)
            ->count();
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function folderWithCi(string $name): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name])];
    }

    private function draftBusiness(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null, string $templateType = 'retail_grocery_water_refilling'): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', $templateType)->firstOrFail();

        return app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ])->fresh();
    }

    private function savedBusiness(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null, string $templateType = 'retail_grocery_water_refilling'): IncomeSource
    {
        $source = $this->draftBusiness($ci, $folder, $name, $coMaker, $templateType);
        app(SaveBusinessIncomeSource::class)->execute($ci, $folder, $source, [
            'intent' => 'complete',
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->revision,
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
        ]);
        $this->assertGreaterThan(1, $source->fresh()->revision);

        return $source->fresh();
    }
}
