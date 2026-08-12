<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Models\ActivityDefinition;
use App\Models\ActivityNote;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CiActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_admin_and_assigned_ci_can_access_but_other_ci_cannot(): void
    {
        $admin = User::factory()->administrator()->create();
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($assigned);
        [$otherFolder, $otherActivity] = $this->folderWithActivities($other);
        $otherActivity->update(['visited_by' => 'PRIVATE OTHER CI VISITOR']);

        $this->actingAs($admin)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->actingAs($assigned)->get(route('client-folders.activities.index', $folder))->assertOk()->assertDontSee('PRIVATE OTHER CI VISITOR');
        $this->actingAs($assigned)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $this->actingAs($other)->get(route('client-folders.activities.index', $folder))->assertForbidden();
        $this->actingAs($other)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload())->assertForbidden();
        $this->assertNotSame($folder->id, $otherFolder->id);
    }

    public function test_deleted_folder_is_unavailable_and_forged_nested_activity_is_rejected(): void
    {
        $admin = User::factory()->administrator()->create();
        [$folder, $activity] = $this->folderWithActivities();
        [$otherFolder, $otherActivity] = $this->folderWithActivities();

        $this->actingAs($admin)->get(route('client-folders.activities.edit', [$folder, $otherActivity]))->assertNotFound();
        $this->actingAs($admin)->put(route('client-folders.activities.update', [$folder, $otherActivity]), $this->payload())->assertNotFound();
        $folder->delete();
        $this->actingAs($admin)->get(route('client-folders.activities.index', $folder->id))->assertNotFound();
        $this->actingAs($admin)->get(route('client-folders.activities.edit', [$folder->id, $activity->id]))->assertNotFound();
        $this->assertNotSame($folder->id, $otherFolder->id);
    }

    public function test_activity_can_be_updated_and_completion_transition_is_audited_safely(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload())
            ->assertRedirect(route('client-folders.activities.edit', [$folder, $activity]))->assertSessionHas('status');

        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertSame('Assigned Investigator', $activity->visited_by);
        $this->assertNotNull($activity->completed_at);
        $this->assertSame($ci->id, $activity->updated_by);
        $audit = AuditLog::where('action', 'ci_activity.completed')->sole();
        $this->assertSame('completed', $audit->metadata['status']);
        $this->assertStringNotContainsString('Verified residence', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_not_started_requires_no_visit_details_but_completed_requires_minimum_fields(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'not_started'])->assertSessionHasNoErrors();
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'completed'])
            ->assertSessionHasErrors(['visit_date', 'visited_by', 'remarks']);
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), ['status' => 'invalid'])
            ->assertSessionHasErrors('status');
    }

    public function test_visit_date_and_time_order_are_validated(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $payload = $this->payload();
        $payload['visit_date'] = now()->addDay()->toDateString();
        $payload['time_in'] = '16:00';
        $payload['time_out'] = '08:00';

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $payload)
            ->assertSessionHasErrors(['visit_date', 'time_out']);
    }

    public function test_notes_are_append_only_chronological_and_audited_without_body(): void
    {
        $ci = User::factory()->create(['full_name' => 'Rey Investigator']);
        [$folder, $activity] = $this->folderWithActivities($ci);
        ActivityNote::create(['ci_activity_id' => $activity->id, 'user_id' => $ci->id, 'note' => 'Earlier note', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

        $this->actingAs($ci)->post(route('client-folders.activities.notes.store', [$folder, $activity]), ['note' => 'New factual note', 'follow_up_needed' => '1'])->assertRedirect();
        $this->assertSame(2, $activity->notes()->count());
        $response = $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk();
        $response->assertSeeInOrder(['Earlier note', 'New factual note'])->assertSee('Rey Investigator')->assertSee('Follow-up needed');

        $audit = AuditLog::where('action', 'activity_note.created')->sole();
        $this->assertStringNotContainsString('New factual note', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_existing_media_references_are_counted_and_displayed_without_upload_controls(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'uploaded_by' => $ci->id, 'file_name' => 'residence-front.jpg']);
        $activity->mediaReferences()->attach($media, ['label' => 'Residence frontage']);

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->assertSee('1 reference');
        $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))
            ->assertOk()->assertSee('Residence frontage')->assertDontSee('type="file"', false);
    }

    public function test_all_required_activities_completed_updates_progress_and_folder_overview(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $folder->activities()->whereKeyNot($activity->id)->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload())->assertRedirect();

        $this->assertDatabaseHas('client_completion_results', ['client_folder_id' => $folder->id, 'is_satisfied' => true, 'explanation_key' => 'required_activities.complete']);
        $folder->refresh();
        $this->assertEquals(16.67, $folder->progress_percent);
        $this->assertSame(ClientFolderStatus::OnProgress, $folder->status);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertSee(route('client-folders.activities.index', $folder), false)
            ->assertSee('6 of 6 required activities completed; 0 pending.');
    }

    public function test_activity_pages_use_responsive_field_checklist_markup_and_neutral_states(): void
    {
        $ci = User::factory()->create();
        [$folder, $activity] = $this->folderWithActivities($ci);
        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()
            ->assertSee('lg:grid-cols-2', false)->assertSee('No remarks recorded.')->assertSee('0 references')
            ->assertSeeInOrder(['Residence Check', 'Business Check', 'Barangay Check', 'Neighbor Check', 'Asset Check', 'Bank / Coop Check']);
        $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity]))->assertOk()
            ->assertSee('xl:grid-cols-', false)->assertSee('No notes recorded.')->assertSee('No supporting media references are linked.')
            ->assertSee(route('client-folders.media.index', $folder), false);
    }

    public function test_activity_list_query_count_stays_constant_with_related_records(): void
    {
        $ci = User::factory()->create();
        [$folder] = $this->folderWithActivities($ci);
        foreach ($folder->activities as $activity) {
            ActivityNote::create(['ci_activity_id' => $activity->id, 'user_id' => $ci->id, 'note' => 'Count-only note']);
        }

        $this->actingAs($ci);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('client-folders.activities.index', $folder))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(9, $queryCount);
    }

    private function folderWithActivities(?User $ci = null): array
    {
        $ci ??= User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $activities = ActivityDefinition::query()->orderBy('sort_order')->get()->map(fn (ActivityDefinition $definition) => CiActivity::create([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
        ]));

        return [$folder, $activities->first()];
    }

    private function payload(): array
    {
        return [
            'status' => 'completed', 'visit_date' => '2026-08-01', 'time_in' => '09:00', 'time_out' => '10:15',
            'visited_by' => ' Assigned  Investigator ', 'person_met_contact' => 'Juan Dela Cruz / 09170000000',
            'remarks' => 'Verified residence and neighborhood details.', 'supporting_reference' => 'Barangay reference BR-10',
            'intent' => 'stay',
        ];
    }
}
