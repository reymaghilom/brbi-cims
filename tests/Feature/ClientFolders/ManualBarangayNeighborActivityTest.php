<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\Progress\MandatoryInvestigationRequirements;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Barangay Check and Neighbor Check are ordinary built-in Activity Types: nobody generates them.
 * A CI adds one through CI Activities -> Add Activity, and that CI becomes its Creator - which is
 * what makes the existing creator-based scheduled reminder reach the right person.
 *
 * They remain mandatory investigation requirements throughout: a person with no row simply has an
 * outstanding requirement, reported as missing by the shared progress projection without any
 * placeholder row being fabricated to represent it.
 */
class ManualBarangayNeighborActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function folder(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN',
        ]);
    }

    private function definition(string $code): ActivityDefinition
    {
        return ActivityDefinition::query()->where('code', $code)->sole();
    }

    /** The Add Activity request the existing UI submits. */
    private function addActivity(User $actor, ClientFolder $folder, string $code, ?int $coMakerId = null)
    {
        return $this->actingAs($actor)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerId ?? '',
            'create_new_activity_type' => '0',
            'activity_definition_id' => $this->definition($code)->id,
            'status' => ActivityStatus::Pending->value,
            'intent' => 'return',
        ]);
    }

    private function activityFor(ClientFolder $folder, string $code, ?int $coMakerId = null): ?CiActivity
    {
        return CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $coMakerId)
            ->whereHas('definition', fn ($query) => $query->where('code', $code))
            ->first();
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name]);
    }

    // =====================================================================================
    // Nothing is generated automatically
    // =====================================================================================

    public function test_no_barangay_or_neighbor_is_generated_by_creation_or_by_opening_the_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $coMaker = $this->coMaker($folder, 'MARIA DELA CRUZ');

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk();

        $this->assertSame(0, CiActivity::query()->count(), 'Viewing authors nothing.');
        $this->assertNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE));
        $this->assertNull($this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE));
        $this->assertNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $coMaker->id));
        $this->assertNull($this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id));
    }

    // =====================================================================================
    // The Add Activity dropdown
    // =====================================================================================

    public function test_both_types_are_offered_as_built_ins_and_marked_already_added_once_they_exist(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-code="'.ActivityDefinition::BARANGAY_CHECK_CODE.'"', $content);
        $this->assertStringContainsString('data-code="'.ActivityDefinition::NEIGHBOR_CHECK_CODE.'"', $content);
        $this->assertStringContainsString('>Barangay Check<', $content);
        $this->assertStringContainsString('Built-in Activity Types', $content);
        $this->assertStringNotContainsString('Already Added', $content);

        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();

        // The option is still listed for context but can no longer be chosen for this person.
        $after = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('Already Added', $after);
    }

    public function test_an_applicant_row_never_blocks_a_co_maker_and_one_co_maker_never_blocks_another(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $maria = $this->coMaker($folder, 'MARIA DELA CRUZ');
        $pedro = $this->coMaker($folder, 'PEDRO DELA CRUZ');

        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id)->assertSessionHasNoErrors();
        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE, $pedro->id)->assertSessionHasNoErrors();

        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $maria->id));
        $this->assertNotNull($this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $pedro->id));
        $this->assertSame(3, CiActivity::query()->count(), 'Three people, three separate rows.');
    }

    // =====================================================================================
    // Manual creation, Creator and duplicates
    // =====================================================================================

    public function test_the_user_who_adds_the_activity_becomes_its_creator_and_stays_its_creator(): void
    {
        $juan = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $maria = User::factory()->create(['full_name' => 'Maria Santos']);
        $folder = $this->folder($juan);

        $this->addActivity($juan, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $this->addActivity($maria, $folder, ActivityDefinition::NEIGHBOR_CHECK_CODE)->assertSessionHasNoErrors();

        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        // Different Creators inside the same folder — the whole point of the manual workflow.
        $this->assertSame($juan->id, $barangay->creator_id);
        $this->assertSame($maria->id, $neighbor->creator_id);
        $this->assertSame(ActivityStatus::Pending, $barangay->status);

        // Created By renders each activity's own creator.
        $content = $this->actingAs($juan)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('>Created By<', $content);
        $this->assertStringContainsString('Juan Dela Cruz', $content);
        $this->assertStringContainsString('Maria Santos', $content);
        $this->assertStringNotContainsString('Assigned CI', $content);

        // A later update by someone else never rewrites provenance.
        $this->actingAs($maria)->put(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '', 'status' => ActivityStatus::Completed->value, 'intent' => 'return',
        ])->assertSessionHasNoErrors();
        $this->assertSame($juan->id, $barangay->fresh()->creator_id);
        $this->assertSame($maria->id, $barangay->fresh()->updated_by);
    }

    public function test_creation_is_recorded_once_against_the_exact_user_and_folder(): void
    {
        $juan = User::factory()->create();
        $folder = $this->folder($juan);

        $this->addActivity($juan, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $this->addActivity($juan, $folder, ActivityDefinition::NEIGHBOR_CHECK_CODE)->assertSessionHasNoErrors();

        $created = AuditLog::query()->where('action', 'ci_activity.created')->get();
        $this->assertCount(2, $created, 'One event per activity, no duplicates.');
        $this->assertTrue($created->every(fn (AuditLog $log): bool => $log->user_id === $juan->id));
        $this->assertTrue($created->every(fn (AuditLog $log): bool => $log->client_folder_id === $folder->id));
    }

    public function test_a_forged_duplicate_is_rejected_for_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $maria = $this->coMaker($folder, 'MARIA DELA CRUZ');

        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $this->addActivity($ci, $folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $maria->id)->assertSessionHasNoErrors();

        // A second request for the same exact person is refused even though the UI hid the option.
        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)
            ->assertSessionHasErrors('activity_definition_id');
        $this->addActivity($ci, $folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, $maria->id)
            ->assertSessionHasErrors('activity_definition_id');

        $this->assertSame(2, CiActivity::query()->count(), 'Nothing extra was written.');
    }

    // =====================================================================================
    // Mandatory progress is unaffected by the change of workflow
    // =====================================================================================

    public function test_a_missing_row_still_reports_the_requirement_as_missing_without_fabricating_one(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->coMaker($folder, 'MARIA DELA CRUZ');

        $labels = app(MandatoryInvestigationRequirements::class)->evaluate($folder);

        // The denominators are untouched: the Applicant's seven plus four for the Co-Maker.
        $this->assertSame(7, count(MandatoryInvestigationRequirements::APPLICANT));
        $this->assertSame(4, count(MandatoryInvestigationRequirements::CO_MAKER));
        $this->assertCount(11, $labels);
        // Every Barangay/Neighbor requirement reads unmet while no row exists.
        foreach ($labels as $label => $met) {
            if (str_contains($label, 'Barangay Check') || str_contains($label, 'Neighbor Check')) {
                $this->assertFalse($met, $label.' must be missing while nothing has been added.');
            }
        }
        $this->assertSame(0, CiActivity::query()->count(), 'No placeholder row was fabricated.');
    }

    public function test_only_a_completed_activity_satisfies_the_requirement(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci);
        $this->addActivity($ci, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        $barangayLabel = 'Applicant: '.MandatoryInvestigationRequirements::APPLICANT['barangay_check'];
        $met = fn (): bool => app(MandatoryInvestigationRequirements::class)->evaluate($folder->fresh())[$barangayLabel];

        $this->assertFalse($met(), 'Pending is not completed.');

        $barangay->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $this->assertFalse($met(), 'Scheduled is not completed.');

        $barangay->update(['status' => ActivityStatus::FollowUp]);
        $this->assertFalse($met(), 'For Follow-up is not completed.');

        $barangay->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
        $this->assertTrue($met(), 'Completed satisfies the requirement.');
    }

    // =====================================================================================
    // Scheduled reminder stays creator-based
    // =====================================================================================

    public function test_a_scheduled_manually_added_activity_reminds_its_creator(): void
    {
        Notification::fake();
        $juan = User::factory()->create();
        $other = User::factory()->create();
        $folder = $this->folder($other);

        $this->addActivity($juan, $folder, ActivityDefinition::BARANGAY_CHECK_CODE)->assertSessionHasNoErrors();
        $barangay = $this->activityFor($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($other)->put(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => now(config('cims.display_timezone'))->toDateString(),
            'intent' => 'return',
        ])->assertSessionHasNoErrors();

        // Creator receives it, even though a different CI did the scheduling.
        Notification::assertSentTo($juan, CiActivityScheduledReminder::class);
        Notification::assertNotSentTo($other, CiActivityScheduledReminder::class);
    }

    public function test_for_follow_up_still_sends_nothing(): void
    {
        Notification::fake();
        $juan = User::factory()->create();
        $folder = $this->folder($juan);

        $this->addActivity($juan, $folder, ActivityDefinition::NEIGHBOR_CHECK_CODE)->assertSessionHasNoErrors();
        $neighbor = $this->activityFor($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $this->actingAs($juan)->put(route('client-folders.activities.update', [$folder, $neighbor]), [
            'co_maker_id' => '',
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => now(config('cims.display_timezone'))->toDateString(),
            'intent' => 'return',
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }
}
