<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Activity Type Management writes to the same AuditLog / Activity History feed every CI Activity
 * mutation already uses. One event per management action, attributed to the acting user, and
 * readable from its own immutable metadata even after the definition is hard deleted.
 */
class ActivityTypeAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_creating_a_custom_type_logs_one_event_for_the_acting_user(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);

        $this->actingAs($userA)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Credit Verification',
        ])->assertRedirect();

        $definition = ActivityDefinition::query()->where('name', 'Credit Verification')->sole();
        $events = $this->definitionEvents();
        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertSame('activity_definition.created', $event->action);
        $this->assertSame($userA->id, $event->user_id);
        $this->assertSame('Credit Verification', data_get((array) $event->metadata, 'activity_title'));
        $this->assertSame($definition->id, data_get((array) $event->metadata, 'activity_definition_id'));
        $this->assertNotNull($event->created_at);

        $this->assertStringContainsString(
            e('Created Activity Type "Credit Verification"'),
            $this->historyPanel($userA, $folder),
        );
    }

    public function test_failed_creation_writes_no_event(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);

        $this->actingAs($userA)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Barangay Check',
        ])->assertSessionHasErrors('new_activity_type');

        $this->assertCount(0, $this->definitionEvents());
    }

    public function test_renaming_logs_one_event_carrying_the_old_and_new_names(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Credit Verification');

        $this->actingAs($userA)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Credit Background Verification'])
            ->assertOk();

        $this->assertSame($definition->id, $definition->fresh()->id);
        $event = $this->definitionEvents()->sole();
        $this->assertSame('activity_definition.renamed', $event->action);
        $this->assertSame($userA->id, $event->user_id);
        $metadata = (array) $event->metadata;
        $this->assertSame('Credit Verification', data_get($metadata, 'previous_name'));
        $this->assertSame('Credit Background Verification', data_get($metadata, 'activity_title'));

        $this->assertStringContainsString(
            e('Updated Activity Type from "Credit Verification" to "Credit Background Verification"'),
            $this->historyPanel($userA, $folder),
        );
    }

    public function test_a_no_op_save_writes_no_update_event(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Credit Verification');

        $this->actingAs($userA)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Credit Verification'])
            ->assertOk();

        $this->assertCount(0, $this->definitionEvents());
        $this->assertSame('Credit Verification', $definition->fresh()->name);
        $this->assertStringNotContainsString('Updated Activity Type', $this->historyPanel($userA, $folder));
    }

    public function test_deactivating_logs_one_event_for_the_acting_user(): void
    {
        [$userA, $userB] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Credit Verification');

        $this->actingAs($userB)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])
            ->assertOk();

        $this->assertFalse($definition->fresh()->is_active);
        $event = $this->definitionEvents()->sole();
        $this->assertSame('activity_definition.deactivated', $event->action);
        $this->assertSame($userB->id, $event->user_id);
        $this->assertStringContainsString(
            e('Deactivated Activity Type "Credit Verification"'),
            $this->historyPanel($userB, $folder),
        );
    }

    public function test_activating_logs_one_event_for_the_acting_user(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Credit Verification', false);

        $this->actingAs($userA)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => true])
            ->assertOk();

        $this->assertTrue($definition->fresh()->is_active);
        $event = $this->definitionEvents()->sole();
        $this->assertSame('activity_definition.activated', $event->action);
        $this->assertSame($userA->id, $event->user_id);
        $this->assertStringContainsString(
            e('Activated Activity Type "Credit Verification"'),
            $this->historyPanel($userA, $folder),
        );
    }

    public function test_permanent_deletion_logs_one_event_that_survives_the_deleted_definition(): void
    {
        [$userA, $userB] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Temporary Verification');
        $definitionId = $definition->id;

        $this->actingAs($userB)
            ->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]))
            ->assertOk();

        $this->assertDatabaseMissing('activity_definitions', ['id' => $definitionId]);
        $event = $this->definitionEvents()->sole();
        $this->assertSame('activity_definition.deleted', $event->action);
        $this->assertSame($userB->id, $event->user_id);
        // Readable from its own metadata — no live ActivityDefinition relationship needed.
        $this->assertSame('Temporary Verification', data_get((array) $event->metadata, 'activity_title'));

        $panel = $this->historyPanel($userB, $folder);
        $this->assertStringContainsString(e('Deleted Activity Type "Temporary Verification"'), $panel);
        $this->assertStringContainsString($userB->full_name, $panel);
    }

    public function test_a_rejected_deletion_writes_no_event(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Credit Verification');
        $activity = $this->customActivity($folder, $userA, $definition);

        $this->actingAs($userA)
            ->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]))
            ->assertStatus(422);

        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $activity->id]);
        $this->assertCount(0, $this->definitionEvents());
        $this->assertStringNotContainsString('Deleted Activity Type', $this->historyPanel($userA, $folder));
    }

    public function test_canonical_types_never_produce_a_management_event(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $this->actingAs($userA);

        foreach ([
            ActivityDefinition::BARANGAY_CHECK_CODE,
            ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ActivityDefinition::ASSET_CHECK_CODE,
            ActivityDefinition::BANK_COOP_CHECK_CODE,
        ] as $code) {
            $definition = ActivityDefinition::query()->where('code', $code)->sole();
            $this->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Hijacked Type'])->assertNotFound();
            $this->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])->assertNotFound();
            $this->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]))->assertNotFound();
        }

        $this->assertCount(0, $this->definitionEvents());
    }

    public function test_each_event_records_the_user_who_actually_performed_it(): void
    {
        [$userA, $userB] = $this->users();
        $folder = $this->folderFor($userA);

        $this->actingAs($userA)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Credit Verification',
        ])->assertRedirect();
        $definition = ActivityDefinition::query()->where('name', 'Credit Verification')->sole();

        $this->actingAs($userB)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])
            ->assertOk();
        $this->actingAs($userA)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => true])
            ->assertOk();

        $events = $this->definitionEvents()->keyBy('action');
        $this->assertCount(3, $events);
        $this->assertSame($userA->id, $events['activity_definition.created']->user_id);
        $this->assertSame($userB->id, $events['activity_definition.deactivated']->user_id);
        $this->assertSame($userA->id, $events['activity_definition.activated']->user_id);
    }

    public function test_renaming_a_heavily_used_type_still_writes_exactly_one_event(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $definition = $this->customDefinition('Credit Verification');
        $pending = $this->customActivity($folder, $userA, $definition);
        $scheduled = $this->customActivity($folder, $userA, $definition, [
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDays(5),
            'scheduled_has_time' => true,
            'remarks' => 'Keep this remark.',
        ]);
        $completed = $this->customActivity($folder, $userA, $definition, ['status' => ActivityStatus::Completed]);
        $auditCountBefore = AuditLog::query()->count();

        $this->actingAs($userA)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), ['name' => 'Credit Background Verification'])
            ->assertOk();

        // Exactly one new AuditLog row in total — never one per linked CI Activity.
        $this->assertSame($auditCountBefore + 1, AuditLog::query()->count());
        $this->assertCount(1, $this->definitionEvents());

        $this->assertSame(ActivityStatus::Pending, $pending->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $scheduled->fresh()->status);
        $this->assertSame(ActivityStatus::Completed, $completed->fresh()->status);
        $this->assertSame('Keep this remark.', $scheduled->fresh()->remarks);
        $this->assertTrue((bool) $scheduled->fresh()->scheduled_has_time);
    }

    public function test_management_events_do_not_disturb_the_existing_person_scoped_history(): void
    {
        [$userA] = $this->users();
        $folder = $this->folderFor($userA);
        $coMaker = $folder->coMakers()->create(['full_name' => 'ALPHA MAKER', 'first_name' => 'Alpha', 'last_name' => 'Maker']);
        $definition = $this->customDefinition('Credit Verification');

        $this->actingAs($userA)
            ->patchJson(route('client-folders.activity-definitions.activation', [$folder, $definition]), ['is_active' => false])
            ->assertOk();

        // Shared configuration, so the event is visible from either person context of the folder.
        $applicantPanel = $this->historyPanel($userA, $folder);
        $coMakerPanel = $this->historyPanel($userA, $folder, $coMaker->id);
        $this->assertStringContainsString(e('Deactivated Activity Type "Credit Verification"'), $applicantPanel);
        $this->assertStringContainsString(e('Deactivated Activity Type "Credit Verification"'), $coMakerPanel);

        // An unrelated folder's history is untouched.
        $otherFolder = $this->folderFor($userA);
        $this->assertStringNotContainsString('Deactivated Activity Type', $this->historyPanel($userA, $otherFolder));
    }

    /** @return array{0: User, 1: User} */
    private function users(): array
    {
        return [
            User::factory()->create(['full_name' => 'Rey Maghilom']),
            User::factory()->create(['full_name' => 'Bea Santos']),
        ];
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function customDefinition(string $name, bool $active = true): ActivityDefinition
    {
        return ActivityDefinition::create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.str()->uuid(),
            'name' => $name,
            'sort_order' => 500,
            'is_required' => false,
            'is_active' => $active,
        ]);
    }

    private function customActivity(ClientFolder $folder, User $creator, ActivityDefinition $definition, array $overrides = []): CiActivity
    {
        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
            'status' => ActivityStatus::Pending,
        ], $overrides));
    }

    /** @return Collection<int, AuditLog> */
    private function definitionEvents(): Collection
    {
        return AuditLog::query()->where('module', 'activity_definitions')->oldest('id')->get();
    }

    private function historyPanel(User $actor, ClientFolder $folder, ?int $coMakerId = null): string
    {
        $params = $coMakerId === null ? [$folder] : [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMakerId];

        return $this->actingAs($actor)->get(route('client-folders.activities.index', $params))->assertOk()->getContent();
    }
}
