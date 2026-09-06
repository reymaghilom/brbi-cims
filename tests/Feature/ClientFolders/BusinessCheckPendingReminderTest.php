<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business Check and Business Report are now independent modules, so the old reminder that told a
 * CI a Business Report could be "completed" from Business Check information no longer describes
 * how the system works and has been removed everywhere it used to render. Business Check data
 * never reaches a Business Report at all.
 *
 * This file keeps the exact scenarios that used to raise that reminder and asserts it is gone from
 * every one of them, so the removal cannot silently come back.
 */
class BusinessCheckPendingReminderTest extends TestCase
{
    use RefreshDatabase;

    private const NOTICE = 'Business Check information is available. Please review and complete the remaining details to finalize this report.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_a_report_pending_business_with_a_check_no_longer_shows_the_reminder(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $business = $this->business($ci, $folder, 'PENDING STORE');
        $this->check($ci, $folder, $business, 'Pending Store Address');

        $html = $this->manage($ci, $folder);

        $this->assertStringNotContainsString(self::NOTICE, $html);
        // The Report Pending row itself is untouched — historical Check-first businesses stay
        // readable and completable, only the reminder text is gone.
        $this->assertStringContainsString('Report Pending', $html);
        $this->assertStringContainsString('PENDING STORE', $html);
    }

    public function test_the_reminder_is_absent_for_a_co_maker_too(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER A']);
        $business = $this->business($ci, $folder, 'CO MAKER STORE', $coMaker);
        $this->check($ci, $folder, $business, 'Co Maker Store Address', $coMaker);

        $this->assertStringNotContainsString(self::NOTICE, $this->manage($ci, $folder, $coMaker));
    }

    public function test_the_reminder_is_absent_from_the_business_report_page_and_nothing_is_written(): void
    {
        [$ci, $folder] = $this->folderWithCi();
        $business = $this->business($ci, $folder, 'REPORT PAGE STORE');
        $check = $this->check($ci, $folder, $business, 'Report Page Address');

        $before = [
            'sources' => IncomeSource::query()->count(),
            'checks' => BusinessCheck::query()->count(),
            'revision' => $business->fresh()->revision,
        ];

        $this->actingAs($ci)
            ->get(route('client-folders.income-sources.edit', [$folder, $business]))
            ->assertOk()
            ->assertDontSee(self::NOTICE);

        $this->assertSame($before['sources'], IncomeSource::query()->count());
        $this->assertSame($before['checks'], BusinessCheck::query()->count());
        $this->assertSame($before['revision'], $business->fresh()->revision);
        $this->assertNotNull(BusinessCheck::query()->find($check->id));
    }

    public function test_no_view_still_renders_the_retired_reminder_markup(): void
    {
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($views as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString(self::NOTICE, $contents, $file->getPathname());
            $this->assertStringNotContainsString('data-business-check-pending-notice', $contents, $file->getPathname());
        }
    }

    /** @return array{User, ClientFolder} */
    private function folderWithCi(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id])];
    }

    private function manage(User $ci, ClientFolder $folder, ?CoMaker $coMaker = null): string
    {
        $person = $coMaker ? ['person' => 'co-maker', 'co_maker_id' => $coMaker->id] : [];

        return $this->actingAs($ci)
            ->get(route('client-folders.income-sources.manage', [$folder] + $person))
            ->assertOk()
            ->getContent();
    }

    private function business(User $ci, ClientFolder $folder, string $name, ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        return app(CreateIncomeSource::class)->execute($ci, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ])->fresh();
    }

    private function check(User $ci, ClientFolder $folder, IncomeSource $business, string $location, ?CoMaker $coMaker = null): BusinessCheck
    {
        return BusinessCheck::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => $business->id,
            'ci_date' => now()->toDateString(),
            'location' => $location,
            'business_name' => $business->business_name,
            'ci_user_id' => $ci->id,
        ]);
    }
}
