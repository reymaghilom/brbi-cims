<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityCompletionGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_completion_guide_renders_once_with_accurate_wording(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'data-ci-completion-guide'));
        $this->assertSame(1, substr_count($content, 'Completion Guide:'));
        $this->assertStringContainsString('Click the checkbox to mark Barangay or Neighbor checks as completed.', $content);
        $this->assertStringContainsString('Bank / Coop and Asset checks are completed through their tracker items.', $content);
    }

    public function test_completion_guide_is_placed_between_the_search_controls_and_the_table(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $searchPos = strpos($content, 'data-ci-activity-search-clear');
        $guidePos = strpos($content, 'data-ci-completion-guide');
        $tablePos = strpos($content, 'data-ci-activities-body');
        $this->assertNotFalse($searchPos);
        $this->assertNotFalse($guidePos);
        $this->assertNotFalse($tablePos);
        $this->assertGreaterThan($searchPos, $guidePos);
        $this->assertLessThan($tablePos, $guidePos);
    }

    public function test_completion_guide_uses_subtle_muted_styling_not_an_alert_box(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<p class="mt-3 flex items-start gap-1\.5 text-xs leading-5 text-text-muted" data-ci-completion-guide>/',
            $content,
        );
        $this->assertStringNotContainsString('bg-danger-soft" data-ci-completion-guide', $content);
        $this->assertStringNotContainsString('border border-progress" data-ci-completion-guide', $content);
        // Decorative icon only — the text itself must already carry the meaning.
        $this->assertMatchesRegularExpression('/data-ci-completion-guide>.*?aria-hidden="true"/s', $content);
    }

    public function test_completion_checkbox_and_quick_complete_markup_are_unaffected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertStringContainsString('data-ci-activity-completion="'.$barangay->id.'"', $content);
        $this->assertStringContainsString('data-completion-kind="default"', $content);
        $this->assertStringContainsString('data-ci-activity-completion="'.$bank->id.'"', $content);
        $this->assertStringContainsString('data-completion-kind="bank"', $content);
        $this->assertStringContainsString('id="quick-complete-activity-modal"', $content);
        $this->assertStringContainsString('data-bank-coop-open="'.$bank->id.'"', $content);
    }

    private function activity(ClientFolder $folder, User $creator, string $code): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
