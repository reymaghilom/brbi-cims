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
use Tests\Concerns\CreatesDefaultCiActivities;
use Tests\TestCase;

class ResetDefaultCiActivitiesCommandTest extends TestCase
{
    use CreatesDefaultCiActivities, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_reset_clears_only_exact_mandatory_defaults_in_place_and_is_idempotent(): void
    {
        $actor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
        $this->createDefaultCiActivities($folder, actor: $actor);
        $this->createDefaultCiActivities($otherFolder, actor: $actor);
        $defaults = $this->defaults($folder, null);
        $originalIds = $defaults->pluck('id')->all();
        $originalOwnership = $defaults->map->only(['client_folder_id', 'co_maker_id', 'activity_definition_id', 'creator_id', 'assigned_ci_id'])->all();

        foreach ($defaults as $activity) {
            $activity->forceFill([
                'status' => ActivityStatus::Completed,
                'scheduled_at' => now()->subDay(),
                'scheduled_has_time' => true,
                'reminder_sent_at' => now()->subHours(2),
                'remarks' => 'Stale workflow data',
                'completed_at' => now()->subHour(),
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'submission_note' => 'Stale submission',
            ])->save();
        }

        $bank = $this->unrelated($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, $actor);
        $asset = $this->unrelated($folder, ActivityDefinition::ASSET_CHECK_CODE, $actor);
        $otherFolderIds = $this->defaults($otherFolder, null)->pluck('id')->all();

        $this->artisan('cims:reset-default-ci-activities', ['client_folder_id' => $folder->id])
            ->expectsConfirmation("Reset Barangay Check and Neighbor Check for Applicant in ClientFolder {$folder->id}?", 'yes')
            ->expectsOutput('Mandatory defaults found: 2')
            ->expectsOutput('Mandatory defaults reset: 2')
            ->assertSuccessful();

        $fresh = $this->defaults($folder, null);
        $this->assertSame($originalIds, $fresh->pluck('id')->all());
        $this->assertSame($originalOwnership, $fresh->map->only(['client_folder_id', 'co_maker_id', 'activity_definition_id', 'creator_id', 'assigned_ci_id'])->all());
        $fresh->each(fn (CiActivity $activity) => $this->assertFreshPending($activity));
        $this->assertSame($otherFolderIds, $this->defaults($otherFolder, null)->pluck('id')->all());
        $this->assertSame(ActivityStatus::Completed, $bank->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $asset->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->artisan('cims:reset-default-ci-activities', ['client_folder_id' => $folder->id])
            ->expectsConfirmation("Reset Barangay Check and Neighbor Check for Applicant in ClientFolder {$folder->id}?", 'yes')
            ->expectsOutput('Mandatory defaults reset: 0')
            ->assertSuccessful();
        $this->assertSame($originalIds, $this->defaults($folder, null)->pluck('id')->all());
    }

    public function test_co_maker_reset_is_exact_and_foreign_co_maker_substitution_changes_nothing(): void
    {
        $actor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
        $makerA = $this->coMaker($folder, 'Alpha Maker');
        $makerB = $this->coMaker($folder, 'Beta Maker');
        $foreignMaker = $this->coMaker($otherFolder, 'Foreign Maker');
        $this->createDefaultCiActivities($folder, actor: $actor);
        $this->createDefaultCiActivities($folder, $makerA, $actor);
        $this->createDefaultCiActivities($folder, $makerB, $actor);

        CiActivity::query()->whereIn('id', $this->defaults($folder, null)->pluck('id')->merge($this->defaults($folder, $makerA)->pluck('id'))->merge($this->defaults($folder, $makerB)->pluck('id')))
            ->update(['status' => ActivityStatus::Completed->value, 'completed_at' => now(), 'remarks' => 'Scoped completion']);
        $allIds = $folder->activities()->orderBy('id')->pluck('id')->all();

        $this->artisan('cims:reset-default-ci-activities', ['client_folder_id' => $folder->id, '--co-maker' => $foreignMaker->id])
            ->expectsOutput('The selected Co-Maker does not belong to the selected ClientFolder.')
            ->assertFailed();
        $this->assertSame($allIds, $folder->activities()->orderBy('id')->pluck('id')->all());
        $this->assertSame(6, $folder->activities()->where('status', ActivityStatus::Completed)->count());

        $this->artisan('cims:reset-default-ci-activities', ['client_folder_id' => $folder->id, '--co-maker' => $makerA->id])
            ->expectsConfirmation("Reset Barangay Check and Neighbor Check for Co-Maker {$makerA->id} in ClientFolder {$folder->id}?", 'yes')
            ->expectsOutput('Mandatory defaults reset: 2')
            ->assertSuccessful();
        $this->defaults($folder, $makerA)->each(fn (CiActivity $activity) => $this->assertFreshPending($activity));
        $this->assertTrue($this->defaults($folder, null)->every(fn (CiActivity $activity) => $activity->status === ActivityStatus::Completed));
        $this->assertTrue($this->defaults($folder, $makerB)->every(fn (CiActivity $activity) => $activity->status === ActivityStatus::Completed));
        $this->assertSame($allIds, $folder->activities()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function defaults(ClientFolder $folder, ?CoMaker $coMaker)
    {
        return $folder->activities()->where('co_maker_id', $coMaker?->id)
            ->whereHas('definition', fn ($query) => $query->whereIn('code', [ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE]))
            ->orderBy('id')->get();
    }

    private function unrelated(ClientFolder $folder, string $code, User $actor): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name, 'status' => ActivityStatus::Completed, 'completed_at' => now(), 'remarks' => 'Untouched', 'creator_id' => $actor->id, 'assigned_ci_id' => $actor->id]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $name, 2);

        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name, 'first_name' => $firstName, 'last_name' => $lastName, 'address' => $name.' address']);
    }

    private function assertFreshPending(CiActivity $activity): void
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
