<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillDefaultCiActivitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_command_backfills_every_exact_existing_context_without_resetting_or_defaulting_anything_else(): void
    {
        $assignedCi = User::factory()->create();
        $firstFolder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        $secondFolder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        $firstMaker = $this->coMaker($firstFolder, 'First Maker');
        $secondMaker = $this->coMaker($firstFolder, 'Second Maker');
        $otherFolderMaker = $this->coMaker($secondFolder, 'Other Folder Maker');

        $barangayDefinition = ActivityDefinition::query()
            ->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)
            ->sole();
        $existingBarangay = CiActivity::create([
            'client_folder_id' => $secondFolder->id,
            'activity_definition_id' => $barangayDefinition->id,
            'name' => $barangayDefinition->name,
            'status' => ActivityStatus::Completed,
            'remarks' => 'Existing completion must remain unchanged.',
            'completed_at' => now()->subDay(),
            'assigned_ci_id' => $assignedCi->id,
            'creator_id' => $assignedCi->id,
            'updated_by' => $assignedCi->id,
        ]);

        $bankDefinition = ActivityDefinition::query()
            ->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)
            ->sole();
        $unrelatedSchedule = now()->addDays(2)->startOfHour();
        $unrelatedActivity = CiActivity::create([
            'client_folder_id' => $firstFolder->id,
            'activity_definition_id' => $bankDefinition->id,
            'name' => $bankDefinition->name,
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => $unrelatedSchedule,
            'scheduled_has_time' => true,
            'remarks' => 'Unrelated activity must remain unchanged.',
            'assigned_ci_id' => $assignedCi->id,
            'creator_id' => $assignedCi->id,
            'updated_by' => $assignedCi->id,
        ]);

        $this->artisan('cims:backfill-default-ci-activities', ['--chunk' => 1])
            ->expectsOutput('Default CI activity backfill completed.')
            ->expectsOutput('ClientFolders processed: 2')
            ->expectsOutput('Applicant contexts processed: 2')
            ->expectsOutput('Co-Maker contexts processed: 3')
            ->expectsOutput('Activities created: 9')
            ->expectsOutput('Activities restored: 0')
            ->expectsOutput('Activities already active: 1')
            ->assertSuccessful();

        foreach ([
            [$firstFolder, null],
            [$firstFolder, $firstMaker],
            [$firstFolder, $secondMaker],
            [$secondFolder, null],
            [$secondFolder, $otherFolderMaker],
        ] as [$folder, $person]) {
            $defaults = $folder->activities()
                ->where('co_maker_id', $person?->id)
                ->whereHas('definition', fn ($query) => $query->whereIn('code', [
                    ActivityDefinition::BARANGAY_CHECK_CODE,
                    ActivityDefinition::NEIGHBOR_CHECK_CODE,
                ]))
                ->with('definition:id,code')
                ->orderBy('id')
                ->get();

            $this->assertCount(2, $defaults);
            $this->assertSame([
                ActivityDefinition::BARANGAY_CHECK_CODE,
                ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ], $defaults->pluck('definition.code')->sort()->values()->all());
        }

        $firstFolderDefaults = $firstFolder->activities()
            ->whereNull('co_maker_id')
            ->whereHas('definition', fn ($query) => $query->whereIn('code', [
                ActivityDefinition::BARANGAY_CHECK_CODE,
                ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ]))
            ->get();
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->status === ActivityStatus::Pending));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->scheduled_at === null));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->scheduled_has_time === false));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->remarks === null));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->completed_at === null));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->creator_id === null));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->updated_by === null));
        $this->assertTrue($firstFolderDefaults->every(fn (CiActivity $activity): bool => $activity->assigned_ci_id === $assignedCi->id));

        $existingBarangay->refresh();
        $this->assertSame(ActivityStatus::Completed, $existingBarangay->status);
        $this->assertSame('Existing completion must remain unchanged.', $existingBarangay->remarks);
        $this->assertSame($assignedCi->id, $existingBarangay->creator_id);
        $this->assertSame($assignedCi->id, $existingBarangay->updated_by);

        $unrelatedActivity->refresh();
        $this->assertSame(ActivityStatus::Scheduled, $unrelatedActivity->status);
        $this->assertTrue($unrelatedActivity->scheduled_at->equalTo($unrelatedSchedule));
        $this->assertSame('Unrelated activity must remain unchanged.', $unrelatedActivity->remarks);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
        $this->assertDatabaseCount('ci_activity_asset_targets', 0);
        $this->assertSame(1, CiActivity::query()->where('activity_definition_id', $bankDefinition->id)->count());
        $this->assertSame(0, CiActivity::query()->whereHas('definition', fn ($query) => $query->where('code', ActivityDefinition::ASSET_CHECK_CODE))->count());
        $this->assertSame(0, CiActivity::query()->whereIn('name', ['MCCB', 'FICCO', 'OIC'])->count());
    }

    public function test_command_is_idempotent_and_reports_every_default_as_already_present_on_rerun(): void
    {
        $assignedCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        $this->coMaker($folder, 'Existing Maker');

        $this->artisan('cims:backfill-default-ci-activities')
            ->expectsOutput('Activities created: 4')
            ->expectsOutput('Activities restored: 0')
            ->expectsOutput('Activities already active: 0')
            ->assertSuccessful();
        $activityIds = $folder->activities()->orderBy('id')->pluck('id')->all();

        $this->artisan('cims:backfill-default-ci-activities')
            ->expectsOutput('ClientFolders processed: 1')
            ->expectsOutput('Applicant contexts processed: 1')
            ->expectsOutput('Co-Maker contexts processed: 1')
            ->expectsOutput('Activities created: 0')
            ->expectsOutput('Activities restored: 0')
            ->expectsOutput('Activities already active: 4')
            ->assertSuccessful();

        $this->assertSame($activityIds, $folder->activities()->orderBy('id')->pluck('id')->all());
        $this->assertSame(4, $folder->activities()->count());
    }

    public function test_command_restores_only_soft_deleted_mandatory_defaults_in_place_as_fresh_pending_rows(): void
    {
        $assignedCi = User::factory()->create();
        $historicalActor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);
        $coMaker = $this->coMaker($folder, 'Scoped Maker');
        $barangayDefinition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        $neighborDefinition = ActivityDefinition::query()->where('code', ActivityDefinition::NEIGHBOR_CHECK_CODE)->sole();
        $bankDefinition = ActivityDefinition::query()->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)->sole();
        $assetDefinition = ActivityDefinition::query()->where('code', ActivityDefinition::ASSET_CHECK_CODE)->sole();

        $completedBarangay = $this->activity($folder, null, $barangayDefinition, [
            'status' => ActivityStatus::Completed,
            'remarks' => 'Historical completed Barangay workflow.',
            'scheduled_at' => now()->subDays(5)->startOfHour(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now()->subDays(4),
            'completed_at' => now()->subDays(3),
            'submitted_at' => now()->subDays(2),
            'submitted_by' => $historicalActor->id,
            'submission_note' => 'Historical Barangay submission.',
            'assigned_ci_id' => $assignedCi->id,
            'creator_id' => $historicalActor->id,
            'updated_by' => $historicalActor->id,
        ]);
        $completedBarangay->delete();

        $activeApplicantNeighbor = $this->activity($folder, null, $neighborDefinition, [
            'status' => ActivityStatus::Completed,
            'remarks' => 'Active completed default remains untouched.',
            'completed_at' => now()->subDay(),
            'assigned_ci_id' => $assignedCi->id,
        ]);
        $activeCoMakerBarangay = $this->activity($folder, $coMaker, $barangayDefinition, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDays(2)->startOfHour(),
            'scheduled_has_time' => true,
            'remarks' => 'Active scheduled default remains untouched.',
            'assigned_ci_id' => $assignedCi->id,
        ]);
        $completedNeighbor = $this->activity($folder, $coMaker, $neighborDefinition, [
            'status' => ActivityStatus::Completed,
            'scheduled_at' => now()->subDays(5)->startOfHour(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now()->subDays(4),
            'remarks' => 'Historical completed Co-Maker workflow.',
            'completed_at' => now()->subDays(3),
            'submitted_at' => now()->subDays(2),
            'submitted_by' => $historicalActor->id,
            'submission_note' => 'Historical Neighbor submission.',
            'assigned_ci_id' => $assignedCi->id,
            'creator_id' => $historicalActor->id,
            'updated_by' => $historicalActor->id,
        ]);
        $completedNeighbor->delete();

        $trashedBank = $this->activity($folder, null, $bankDefinition, ['status' => ActivityStatus::Pending]);
        $trashedBank->delete();
        $trashedAsset = $this->activity($folder, $coMaker, $assetDefinition, ['status' => ActivityStatus::Pending]);
        $trashedAsset->delete();

        $this->artisan('cims:backfill-default-ci-activities')
            ->expectsOutput('Activities created: 0')
            ->expectsOutput('Activities restored: 2')
            ->expectsOutput('Activities already active: 2')
            ->assertSuccessful();

        $restoredBarangay = CiActivity::query()->findOrFail($completedBarangay->id);
        $this->assertSame($completedBarangay->id, $restoredBarangay->id);
        $this->assertNull($restoredBarangay->deleted_at);
        $this->assertFreshPendingWorkflow($restoredBarangay);
        $this->assertSame($historicalActor->id, $restoredBarangay->creator_id);
        $this->assertSame($historicalActor->id, $restoredBarangay->updated_by);
        $this->assertSame($assignedCi->id, $restoredBarangay->assigned_ci_id);
        $this->assertNull($restoredBarangay->co_maker_id);

        $restoredNeighbor = CiActivity::query()->findOrFail($completedNeighbor->id);
        $this->assertSame($completedNeighbor->id, $restoredNeighbor->id);
        $this->assertNull($restoredNeighbor->deleted_at);
        $this->assertFreshPendingWorkflow($restoredNeighbor);
        $this->assertSame($historicalActor->id, $restoredNeighbor->creator_id);
        $this->assertSame($historicalActor->id, $restoredNeighbor->updated_by);
        $this->assertSame($assignedCi->id, $restoredNeighbor->assigned_ci_id);
        $this->assertSame($coMaker->id, $restoredNeighbor->co_maker_id);

        $activeApplicantNeighbor->refresh();
        $this->assertSame(ActivityStatus::Completed, $activeApplicantNeighbor->status);
        $this->assertSame('Active completed default remains untouched.', $activeApplicantNeighbor->remarks);
        $this->assertNotNull($activeApplicantNeighbor->completed_at);
        $activeCoMakerBarangay->refresh();
        $this->assertSame(ActivityStatus::Scheduled, $activeCoMakerBarangay->status);
        $this->assertNotNull($activeCoMakerBarangay->scheduled_at);
        $this->assertTrue($activeCoMakerBarangay->scheduled_has_time);
        $this->assertSame('Active scheduled default remains untouched.', $activeCoMakerBarangay->remarks);
        $this->assertTrue(CiActivity::onlyTrashed()->whereKey($trashedBank->id)->exists());
        $this->assertTrue(CiActivity::onlyTrashed()->whereKey($trashedAsset->id)->exists());

        $applicantPage = $this->actingAs($assignedCi)->get(route('client-folders.activities.index', $folder));
        $this->assertSame(
            [$restoredBarangay->id, $activeApplicantNeighbor->id],
            $applicantPage->viewData('activities')->pluck('id')->all(),
        );
        $coMakerPage = $this->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
        ]));
        $this->assertSame(
            [$activeCoMakerBarangay->id, $restoredNeighbor->id],
            $coMakerPage->viewData('activities')->pluck('id')->all(),
        );

        $this->artisan('cims:backfill-default-ci-activities')
            ->expectsOutput('Activities created: 0')
            ->expectsOutput('Activities restored: 0')
            ->expectsOutput('Activities already active: 4')
            ->assertSuccessful();
        $this->assertSame(4, CiActivity::query()->whereIn('activity_definition_id', [
            $barangayDefinition->id,
            $neighborDefinition->id,
        ])->count());
    }

    public function test_command_rejects_an_invalid_chunk_size_without_writing(): void
    {
        ClientFolder::factory()->create();

        $this->artisan('cims:backfill-default-ci-activities', ['--chunk' => 0])
            ->expectsOutput('The --chunk option must be an integer between 1 and 5000.')
            ->assertFailed();

        $this->assertDatabaseCount('ci_activities', 0);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $name, 2);

        return CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'address' => $name.' address',
        ]);
    }

    private function activity(ClientFolder $folder, ?CoMaker $person, ActivityDefinition $definition, array $attributes): CiActivity
    {
        return CiActivity::create($attributes + [
            'client_folder_id' => $folder->id,
            'co_maker_id' => $person?->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
        ]);
    }

    private function assertFreshPendingWorkflow(CiActivity $activity): void
    {
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);
        $this->assertNull($activity->reminder_sent_at);
        $this->assertNull($activity->remarks);
        $this->assertNull($activity->completed_at);
        $this->assertNull($activity->submitted_at);
        $this->assertNull($activity->submitted_by);
        $this->assertNull($activity->submission_note);
    }
}
