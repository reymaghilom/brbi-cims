<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A CI Activity deleted by another user must never surface Laravel's own
 * "No query results for model [App\Models\CiActivity] 155" to a CI. That text reached the screen
 * because the quick-complete fetch handlers render `payload.message` straight from the response.
 *
 * Only the wording changes: the request still fails, the row stays deleted, nothing is recreated
 * and no audit or progress side effect occurs. This is deliberately distinct from the 409 revision
 * conflict, which means the record still exists and should be reviewed before saving again.
 */
class StaleCiActivityMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const DELETED_MESSAGE = 'This CI Activity was deleted by another user while you were working on it. Please return to the CI Activities page.';

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    public static function builtInCheckProvider(): array
    {
        return [
            'Barangay Check' => [ActivityDefinition::BARANGAY_CHECK_CODE],
            'Neighbor Check' => [ActivityDefinition::NEIGHBOR_CHECK_CODE],
        ];
    }

    #[DataProvider('builtInCheckProvider')]
    public function test_a_stale_ajax_save_after_delete_gets_the_friendly_message_and_no_exception_text(string $code): void
    {
        $staleEditor = User::factory()->create();
        $folder = $this->folder();
        $activity = $this->activity($folder, $code);
        $activityId = $activity->id;

        $this->actingAs($this->ci)->delete(route('client-folders.activities.destroy', [$folder, $activity]))->assertRedirect();
        $updateAudits = $this->auditCount($folder, 'ci_activity.updated');

        $response = $this->actingAs($staleEditor)->putJson(route('client-folders.activities.update', [$folder, $activityId]), [
            'co_maker_id' => '',
            'expected_revision' => 1,
            'status' => 'completed',
            'remarks' => 'Saved from a page opened before the delete',
        ]);

        $response->assertNotFound()->assertJson([
            'result' => 'deleted',
            'status_type' => 'error',
            'message' => self::DELETED_MESSAGE,
        ]);
        // No model class, no id, no Laravel exception wording anywhere in the payload.
        $body = $response->getContent();
        $this->assertStringNotContainsString('No query results', $body);
        $this->assertStringNotContainsString('CiActivity', $body);
        $this->assertStringNotContainsString('exception', $body);

        // The delete stands: nothing recreated, no update audit, no progress side effect.
        $this->assertSame(0, CiActivity::query()->count());
        $this->assertDatabaseMissing('ci_activities', ['id' => $activityId]);
        $this->assertSame($updateAudits, $this->auditCount($folder, 'ci_activity.updated'));
    }

    /**
     * A plain page request now gets a friendly 404 PAGE rather than Laravel's raw body, which in
     * debug mode could print "No query results for model [App\Models\CiActivity] 155".
     *
     * Its wording is deliberately GENERIC and different from the JSON branch's: a missing activity
     * and one belonging to another person or folder raise the same exception, so this page must not
     * assert a deletion, must not say whether the record ever existed, and must stay a 404 rather
     * than redirect a forged id onto a page that looks valid.
     */
    #[DataProvider('builtInCheckProvider')]
    public function test_a_stale_browser_page_request_after_delete_gets_a_friendly_404_page(string $code): void
    {
        $folder = $this->folder();
        $activity = $this->activity($folder, $code);
        $activityId = $activity->id;

        $this->actingAs($this->ci)->delete(route('client-folders.activities.destroy', [$folder, $activity]))->assertRedirect();

        $page = $this->actingAs($this->ci)
            ->get(route('client-folders.activities.edit', [$folder, $activityId]))
            ->assertNotFound();

        $body = $page->getContent();
        $this->assertStringContainsString('This CI Activity is no longer available.', $body);
        $this->assertStringNotContainsString('No query results for model', $body);
        $this->assertStringNotContainsString('App\Models\CiActivity', $body);
        // Never the JSON branch's certainty about a deletion.
        $this->assertStringNotContainsString('was deleted by another user', $body);

        $this->assertSame(0, CiActivity::query()->count());
    }

    /** The deleted case and the still-exists conflict case must stay clearly different. */
    public function test_the_deleted_message_is_not_confused_with_the_revision_conflict_message(): void
    {
        $staleEditor = User::factory()->create();
        $folder = $this->folder();
        $activity = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);

        // Someone else saves first: the row still exists, so this is a 409 conflict.
        $this->actingAs($this->ci)->put(route('client-folders.activities.update', [$folder, $activity]), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => 'completed', 'intent' => 'stay',
        ])->assertRedirect();

        $this->actingAs($staleEditor)->putJson(route('client-folders.activities.update', [$folder, $activity]), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => 'completed',
        ])->assertStatus(409)->assertJson([
            'result' => 'conflict',
            'message' => 'This CI Activity was updated by another user while you were working on it. Please review the latest information before saving again.',
        ]);
    }

    /** Exact-person scope is unchanged: a Co-Maker's activity is still unreachable from another person's context. */
    public function test_applicant_and_co_maker_scope_is_unaffected_by_the_friendly_message(): void
    {
        $folder = $this->folder();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $applicant = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE);
        $coMakerActivity = $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, $coMaker);

        $this->actingAs($this->ci)->putJson(route('client-folders.activities.update', [$folder, $coMakerActivity]), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => 'completed',
        ])->assertForbidden();

        $this->assertDatabaseHas('ci_activities', ['id' => $applicant->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $coMakerActivity->id]);
        $this->assertNull($coMakerActivity->fresh()->remarks);
    }

    /** Another model's missing row keeps Laravel's own 404 handling — this translation is scoped to CI Activities. */
    public function test_other_missing_models_are_not_rewritten(): void
    {
        $folder = $this->folder();

        $this->actingAs($this->ci)
            ->get(route('client-folders.activities.index', $folder->id + 999))
            ->assertNotFound();
    }

    private function auditCount(ClientFolder $folder, string $action): int
    {
        return AuditLog::query()->where('client_folder_id', $folder->id)->where('action', $action)->count();
    }

    private function activity(ClientFolder $folder, string $code, ?CoMaker $coMaker = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->firstOrFail();

        return $folder->activities()->create([
            'co_maker_id' => $coMaker?->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $this->ci->id,
            'updated_by' => $this->ci->id,
        ])->fresh();
    }

    private function folder(): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $this->ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
