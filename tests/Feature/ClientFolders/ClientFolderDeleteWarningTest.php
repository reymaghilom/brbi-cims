<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateClientFolder;
use App\Actions\ClientFolders\SaveCoMaker;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderBrowser;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Client Folder permanent-delete dialog shows the stronger "already contains saved data"
 * warning only for real saved work. Pristine auto-seeded Barangay / Neighbor Checks (and the
 * automatic creation history) are not saved work, but they are still deleted with the folder.
 */
class ClientFolderDeleteWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_new_folder_with_only_pristine_defaults_and_creation_history_shows_the_concise_confirmation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);

        $this->assertSame(2, $folder->activities()->count());
        $this->assertTrue(AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'client_folder.created')->exists());

        $modal = $this->deleteModal($ci, $folder);
        $this->assertStringContainsString('Delete Client Folder Permanently?', $modal);
        $this->assertStringContainsString('data-folder-delete-empty-warning', $modal);
        $this->assertStringNotContainsString('data-folder-delete-data-warning', $modal);
        $this->assertMatchesRegularExpression('/Cancel\s*<\/button>/', $modal);
        $this->assertMatchesRegularExpression('/Delete Permanently\s*<\/button>/', $modal);
    }

    public function test_any_real_change_to_a_default_barangay_or_neighbor_check_counts_as_saved_data(): void
    {
        $ci = User::factory()->create();
        $changes = [
            'barangay remarks' => [ActivityDefinition::BARANGAY_CHECK_CODE, ['remarks' => 'Barangay captain confirmed residency.']],
            'neighbor remarks' => [ActivityDefinition::NEIGHBOR_CHECK_CODE, ['remarks' => 'Neighbor confirmed.']],
            'scheduled' => [ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay()]],
            'follow-up' => [ActivityDefinition::NEIGHBOR_CHECK_CODE, ['status' => ActivityStatus::FollowUp]],
            'completed' => [ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed, 'completed_at' => now()]],
            'visit details' => [ActivityDefinition::NEIGHBOR_CHECK_CODE, ['visited_by' => 'Field CI']],
            'submitted' => [ActivityDefinition::BARANGAY_CHECK_CODE, ['submitted_at' => now(), 'submitted_to' => 'Credit Head']],
        ];

        foreach ($changes as $label => [$code, $attributes]) {
            $folder = $this->newFolder($ci, $label);
            $this->defaultActivity($folder, $code)->update($attributes);

            $this->assertStringContainsString('data-folder-delete-data-warning', $this->deleteModal($ci, $folder), "{$label} must count as saved data.");
        }
    }

    public function test_supporting_proof_on_a_default_activity_counts_as_saved_data(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'uploaded_by' => $ci->id]);
        $this->defaultActivity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE)->mediaReferences()->attach($media->id);

        $this->assertStringContainsString('data-folder-delete-data-warning', $this->deleteModal($ci, $folder));
        $this->assertTrue($this->browsed($ci, $folder)->has_activity_data);
    }

    public function test_an_explicit_user_save_counts_even_when_the_default_ends_up_back_in_its_seeded_state(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $barangay = $this->defaultActivity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $url = route('client-folders.activities.update', [$folder, $barangay]);

        $this->actingAs($ci)->putJson($url, ['co_maker_id' => null, 'status' => ActivityStatus::FollowUp->value])->assertOk();
        $this->putJson($url, ['co_maker_id' => null, 'status' => ActivityStatus::Pending->value])->assertOk();

        $barangay->refresh();
        $this->assertSame(ActivityStatus::Pending, $barangay->status);
        $this->assertNull($barangay->scheduled_at);
        $this->assertNull($barangay->remarks);
        $this->assertTrue($this->browsed($ci, $folder)->has_activity_data);
        $this->assertStringContainsString('data-folder-delete-data-warning', $this->deleteModal($ci, $folder));
    }

    public function test_co_maker_work_is_not_hidden_by_pristine_applicant_defaults(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $coMaker = app(SaveCoMaker::class)->execute($ci, $folder, ['first_name' => 'Maria', 'last_name' => 'Santos']);
        $coMakerBarangay = CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $coMaker->id)
            ->whereHas('definition', fn ($query) => $query->where('code', ActivityDefinition::BARANGAY_CHECK_CODE))
            ->sole();

        // The Co-Maker record itself is saved data; its own seeded defaults are still pristine.
        $flags = $this->browsed($ci, $folder);
        $this->assertTrue($flags->has_co_maker_data);
        $this->assertFalse($flags->has_activity_data);
        $this->assertStringContainsString('data-folder-delete-data-warning', $this->deleteModal($ci, $folder));

        $coMakerBarangay->update(['remarks' => 'Co-Maker barangay visit.']);
        $this->assertTrue($this->browsed($ci, $folder)->has_activity_data);
        foreach ($folder->activities()->whereNull('co_maker_id')->get() as $applicantActivity) {
            $this->assertSame(ActivityStatus::Pending, $applicantActivity->status);
            $this->assertNull($applicantActivity->remarks);
        }
    }

    public function test_custom_activities_and_other_folder_records_still_count_as_saved_data(): void
    {
        $ci = User::factory()->create();

        $custom = $this->newFolder($ci, 'Custom');
        $definition = ActivityDefinition::query()->where('is_active', true)
            ->whereNotIn('code', ActivityDefinition::MANDATORY_DEFAULT_CODES)->orderBy('sort_order')->firstOrFail();
        CiActivity::create([
            'client_folder_id' => $custom->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => ActivityStatus::Pending, 'creator_id' => $ci->id,
        ]);
        $this->assertTrue($this->browsed($ci, $custom)->has_activity_data);

        $income = $this->newFolder($ci, 'Income');
        IncomeSource::factory()->create([
            'client_folder_id' => $income->id,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->value('id'),
        ]);
        $cibi = $this->newFolder($ci, 'Cibi');
        CibiReport::factory()->create(['client_folder_id' => $cibi->id, 'ci_in_charge_id' => $ci->id]);

        foreach ([$custom, $income, $cibi] as $folder) {
            $this->assertStringContainsString('data-folder-delete-data-warning', $this->deleteModal($ci, $folder));
        }
    }

    public function test_permanent_delete_still_removes_pristine_default_activities_with_the_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->newFolder($ci);
        $other = $this->newFolder($ci, 'Kept');
        $activityIds = $folder->activities()->pluck('id');
        $this->assertCount(2, $activityIds);

        $this->actingAs($ci)->delete(route('client-folders.destroy', $folder))->assertRedirect();

        $this->assertNull(ClientFolder::find($folder->id));
        $this->assertSame(0, CiActivity::withTrashed()->whereIn('id', $activityIds)->count());
        $this->assertSame(2, $other->activities()->count());
    }

    private function newFolder(User $actor, string $prefix = 'Warning'): ClientFolder
    {
        return app(CreateClientFolder::class)->execute($actor, [
            'last_name' => $prefix.' Applicant',
            'first_name' => 'Test',
            'middle_name' => null,
            'suffix' => null,
            'assigned_ci_id' => $actor->id,
        ]);
    }

    private function defaultActivity(ClientFolder $folder, string $code): CiActivity
    {
        return CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->whereNull('co_maker_id')
            ->whereHas('definition', fn ($query) => $query->where('code', $code))
            ->sole();
    }

    private function browsed(User $user, ClientFolder $folder): ClientFolder
    {
        return app(ClientFolderBrowser::class)
            ->browse($user, ['search' => $folder->display_name])
            ->getCollection()
            ->firstWhere('id', $folder->id);
    }

    private function deleteModal(User $user, ClientFolder $folder): string
    {
        $html = $this->actingAs($user)->get(route('client-folders.index', ['search' => $folder->display_name]))->assertOk()->getContent();
        $start = strpos($html, 'id="dashboard-delete-dialog-'.$folder->id.'"');
        $this->assertNotFalse($start, 'Delete dialog not rendered for folder '.$folder->id);
        $end = strpos($html, '</dialog>', $start);

        return substr($html, $start, $end + 9 - $start);
    }
}
