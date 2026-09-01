<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateClientFolder;
use App\Actions\ClientFolders\SaveCoMaker;
use App\Actions\ClientFolders\SeedCiActivities;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityDefaultActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_folder_and_co_maker_creation_seed_only_the_two_exact_pending_defaults_once(): void
    {
        $actor = User::factory()->administrator()->create();
        $assignedCi = User::factory()->create();
        $folder = $this->createFolder($actor, $assignedCi);

        $this->assertExactDefaults($folder, null, $actor, $assignedCi);

        $coMakerA = app(SaveCoMaker::class)->execute($actor, $folder, [
            'co_maker_id' => null,
            'first_name' => 'Alpha',
            'middle_name' => null,
            'last_name' => 'Maker',
            'suffix' => null,
            'address' => 'Alpha address',
        ]);
        $coMakerB = app(SaveCoMaker::class)->execute($actor, $folder, [
            'co_maker_id' => null,
            'first_name' => 'Beta',
            'middle_name' => null,
            'last_name' => 'Maker',
            'suffix' => null,
            'address' => 'Beta address',
        ]);

        $this->assertExactDefaults($folder, $coMakerA, $actor, $assignedCi);
        $this->assertExactDefaults($folder, $coMakerB, $actor, $assignedCi);

        $seed = app(SeedCiActivities::class);
        $seed->execute($folder, actor: $actor);
        $seed->execute($folder, $coMakerA, $actor);
        $seed->execute($folder, $coMakerB, $actor);
        $this->assertSame(6, $folder->activities()->count());

        $otherFolder = $this->createFolder($actor, $assignedCi, 'Other');
        $this->assertExactDefaults($otherFolder, null, $actor, $assignedCi);
        $this->expectException(ModelNotFoundException::class);
        $seed->execute($otherFolder, $coMakerA, $actor);
    }

    public function test_default_activities_complete_through_existing_update_audit_and_history_flow(): void
    {
        $creator = User::factory()->administrator()->create();
        $updater = User::factory()->create();
        $folder = $this->createFolder($creator, $updater);
        $coMaker = app(SaveCoMaker::class)->execute($creator, $folder, [
            'co_maker_id' => null,
            'first_name' => 'Exact',
            'middle_name' => null,
            'last_name' => 'Maker',
            'suffix' => null,
            'address' => 'Exact address',
        ]);

        $barangay = $this->activity($folder, null, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $coMaker, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $this->actingAs($updater)->put(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => null,
            'status' => ActivityStatus::Completed->value,
            'remarks' => 'Barangay verification completed.',
            'intent' => 'return',
        ])->assertRedirect();
        $this->put(route('client-folders.activities.update', [$folder, $neighbor]), [
            'co_maker_id' => $coMaker->id,
            'status' => ActivityStatus::Completed->value,
            'remarks' => 'Neighbor verification completed.',
            'intent' => 'return',
        ])->assertRedirect();

        foreach ([$barangay, $neighbor] as $activity) {
            $activity->refresh();
            $this->assertSame(ActivityStatus::Completed, $activity->status);
            $this->assertSame($creator->id, $activity->creator_id);
            $this->assertSame($updater->id, $activity->updated_by);
            $this->assertNotNull($activity->completed_at);
            $this->assertDatabaseHas('audit_logs', [
                'user_id' => $updater->id,
                'client_folder_id' => $folder->id,
                'action' => 'ci_activity.completed',
            ]);
        }

        $this->assertSame(2, AuditLog::query()->where('action', 'ci_activity.completed')->count());
        $this->get(route('client-folders.activities.index', [$folder, 'status' => 'completed']))
            ->assertOk()
            ->assertSee('Barangay Check completed')
            ->assertDontSee('Neighbor Check completed');
        $this->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
            'status' => 'completed',
        ]))->assertOk()
            ->assertSee('Neighbor Check completed')
            ->assertDontSee('Barangay Check completed');
    }

    public function test_pending_filter_and_definition_sorting_treat_defaults_as_normal_activities(): void
    {
        $ci = User::factory()->create();
        $folder = $this->createFolder($ci, $ci);

        $response = $this->actingAs($ci)->get(route('client-folders.activities.index', [
            $folder,
            'status' => 'pending',
        ]));

        $response->assertOk()
            ->assertSeeInOrder(['Barangay Check', 'Neighbor Check'])
            ->assertViewHas('filter', 'pending');
        $this->assertSame(2, $response->viewData('counts')['pending']);
        $this->assertSame(2, $response->viewData('activities')->count());
    }

    private function createFolder(User $actor, User $assignedCi, string $prefix = 'Default'): ClientFolder
    {
        return app(CreateClientFolder::class)->execute($actor, [
            'last_name' => $prefix.' Applicant',
            'first_name' => 'Test',
            'middle_name' => null,
            'suffix' => null,
            'assigned_ci_id' => $assignedCi->id,
        ]);
    }

    private function assertExactDefaults(ClientFolder $folder, ?CoMaker $person, User $actor, User $assignedCi): void
    {
        $activities = $folder->activities()
            ->where('co_maker_id', $person?->id)
            ->with('definition:id,code')
            ->orderBy('id')
            ->get();

        $this->assertSame([
            ActivityDefinition::BARANGAY_CHECK_CODE,
            ActivityDefinition::NEIGHBOR_CHECK_CODE,
        ], $activities->pluck('definition.code')->all());
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->status === ActivityStatus::Pending));
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->scheduled_at === null));
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->remarks === null));
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->completed_at === null));
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->creator_id === $actor->id));
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->updated_by === $actor->id));
        $this->assertTrue($activities->every(fn (CiActivity $activity): bool => $activity->assigned_ci_id === $assignedCi->id));
        $this->assertSame(0, $activities->sum(fn (CiActivity $activity): int => $activity->bankTargets()->count()));
        $this->assertSame(0, $activities->sum(fn (CiActivity $activity): int => $activity->assetTargets()->count()));
    }

    private function activity(ClientFolder $folder, ?CoMaker $person, string $code): CiActivity
    {
        return $folder->activities()
            ->where('co_maker_id', $person?->id)
            ->whereHas('definition', fn ($query) => $query->where('code', $code))
            ->sole();
    }
}
