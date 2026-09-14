<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateCiActivity;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Custom Activity Types end to end: creating the reusable definition, adding the activity for an
 * exact person, editing it, completing it and deleting it.
 *
 * BRBI-CIMS is collaborative, so adding an activity this person already has is an ADVISORY
 * WARNING, not a hard block: another CI may already be working it. Nothing is created until the CI
 * explicitly chooses Continue Anyway (allow_duplicate), and the second activity is then a fully
 * independent row with its own creator, status, revision and history. The re-check runs under the
 * ActivityDefinition lock CreateCiActivity already takes, so a concurrent second Add warns rather
 * than silently inserting.
 *
 * EDIT is deliberately the opposite: a stale save is refused outright with no Continue Anyway,
 * because overwriting another CI's newer work loses it.
 *
 * LIMITATION: these tests prove the LOGICAL outcomes — the duplicate block, person scope, revision
 * handling, side-effect suppression, definition normalization and stale-after-delete behaviour.
 * The suite runs on SQLite :memory:, which serializes writes at the connection level and has no
 * meaningful FOR UPDATE, so true simultaneous MySQL/InnoDB SELECT ... FOR UPDATE scheduling is NOT
 * exercised here and still needs manual verification.
 */
class CustomCiActivityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const APPLICANT_WARNING = 'A similar activity already exists for this Applicant. Another CI may already be working on it. Please review the existing entry before continuing.';

    private const CO_MAKER_WARNING = 'A similar activity already exists for this Co-Maker. Another CI may already be working on it. Please review the existing entry before continuing.';

    private const TYPE_UNAVAILABLE = 'This Activity Type is no longer available. It may have been deleted or changed. Please refresh the page and try again.';

    private const UNAVAILABLE = 'This CI Activity is no longer available. It may have been deleted or changed by another user. Please return to the CI Activities page.';

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
    }

    // ------------------------------------------------------------------ Add / Create

    public function test_a_second_identical_add_for_the_same_applicant_is_blocked_with_no_side_effects(): void
    {
        Notification::fake();
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Court Records Check');

        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->addPayload($definition, [
                'status' => ActivityStatus::Scheduled->value,
                'scheduled_at' => '2026-09-20',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
        Notification::assertSentToTimes($this->ci, CiActivityScheduledReminder::class, 1);
        $auditsBefore = AuditLog::query()->count();

        // The second submit for the very same person: advisory, not a hard block.
        $this->postJson($this->storeUrl($folder), $this->addPayload($definition))
            ->assertStatus(409)
            ->assertJsonPath('result', 'duplicate_exists')
            ->assertJsonPath('status_type', 'warning')
            ->assertJsonPath('message', self::APPLICANT_WARNING);

        // Zero insert, zero audit, zero reminder — the advisory leaves nothing behind.
        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
        $this->assertSame($auditsBefore, AuditLog::query()->count());
        Notification::assertSentToTimes($this->ci, CiActivityScheduledReminder::class, 1);
    }

    public function test_continue_anyway_creates_an_independent_second_activity(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Court Records Check');
        $secondCi = User::factory()->create();

        $first = $this->customActivity($folder, null, 'Court Records Check');
        $this->assertSame($this->ci->id, $first->creator_id);
        $definitionCount = ActivityDefinition::query()->count();
        $auditsBefore = AuditLog::query()->count();

        // A different CI deliberately proceeds.
        $folder->update(['assigned_ci_id' => $secondCi->id]);
        $this->actingAs($secondCi)
            ->post($this->storeUrl($folder), $this->addPayload($definition, ['allow_duplicate' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $this->activitiesFor($folder, $definition, null));

        // Two independent rows: the first is untouched and each keeps its own creator.
        $activities = $folder->activities()->where('activity_definition_id', $definition->id)->oldest('id')->get();
        $this->assertSame($first->id, $activities->first()->id);
        $this->assertSame($this->ci->id, $activities->first()->creator_id);
        $this->assertSame($secondCi->id, $activities->last()->creator_id);
        $this->assertNotSame($activities->first()->id, $activities->last()->id);
        $this->assertSame(ActivityStatus::Pending, $activities->first()->status);

        // Exactly one create audit, and the definition was reused, never duplicated.
        $this->assertSame(1, AuditLog::query()->count() - $auditsBefore);
        $this->assertSame($definitionCount, ActivityDefinition::query()->count());
        $this->assertSame($definition->id, $activities->last()->activity_definition_id);
    }

    public function test_the_full_page_duplicate_reopens_the_modal_in_continue_anyway_state(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Court Records Check');
        $this->customActivity($folder, null, 'Court Records Check');

        $html = $this->actingAs($this->ci)
            ->followingRedirects()
            ->post($this->storeUrl($folder), $this->addPayload($definition, ['remarks' => 'Typed remarks kept.']))
            ->assertOk()
            ->getContent();

        // Advisory visible, entries kept, and the one primary action swapped.
        $this->assertStringContainsString('data-ci-activity-duplicate-warning', $html);
        $this->assertStringContainsString(self::APPLICANT_WARNING, $html);
        $this->assertStringContainsString('Typed remarks kept.', $html);

        $footer = substr($html, strrpos($html, '<div class="flex shrink-0 flex-col-reverse gap-3 border-t'));
        $footer = substr($footer, 0, strpos($footer, '</div>') + 6);
        $this->assertStringContainsString('name="allow_duplicate" value="1"', $footer);
        $this->assertStringContainsString('Continue Anyway', $footer);
        $this->assertStringNotContainsString('>Add Activity<', $footer);

        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));

        // And Continue Anyway from that state creates the second activity.
        $this->post($this->storeUrl($folder), $this->addPayload($definition, ['allow_duplicate' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $this->activitiesFor($folder, $definition, null));
    }

    public function test_the_add_dialog_wires_the_duplicate_state_and_the_submitter_payload(): void
    {
        $folder = $this->folder();

        $html = $this->actingAs($this->ci)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->getContent();

        // Source-level guards: PHPUnit renders Blade, it does not run this JavaScript, so the
        // visible swap still needs a manual browser check.
        $this->assertStringContainsString("payload.result === 'duplicate_exists'", $html);
        $this->assertStringContainsString('enterDuplicateState', $html);
        $this->assertStringContainsString('leaveDuplicateState', $html);
        $this->assertStringContainsString('requestBody.append(submitter.name, submitter.value)', $html);
        // The advisory is present but hidden, and the footer still offers the normal Add action
        // until a duplicate is actually reported.
        $this->assertStringContainsString('data-ci-activity-duplicate-warning', $html);
        $footer = substr($html, strrpos($html, '<div class="flex shrink-0 flex-col-reverse gap-3 border-t'));
        $footer = substr($footer, 0, strpos($footer, '</div>') + 6);
        $this->assertStringNotContainsString('Continue Anyway', $footer);
        $this->assertStringNotContainsString('allow_duplicate', $footer);

        // A committed rename must never be reported as a failure by a later refresh problem.
        $this->assertStringContainsString('the list could not be refreshed', $html);
        $this->assertStringContainsString('// Past this point the rename is COMMITTED', $html);
    }

    public function test_a_second_identical_add_for_the_same_co_maker_is_blocked_with_person_aware_wording(): void
    {
        $folder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $definition = $this->customDefinition($folder, 'Court Records Check');

        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMaker->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->postJson($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMaker->id]))
            ->assertStatus(409)
            ->assertJsonPath('result', 'duplicate_exists')
            ->assertJsonPath('message', self::CO_MAKER_WARNING);

        $this->assertSame(1, $this->activitiesFor($folder, $definition, $coMaker->id));

        // Continue Anyway is scoped to this exact person, and creates one more row for them only.
        $this->post($this->storeUrl($folder), $this->addPayload($definition, [
            'co_maker_id' => $coMaker->id, 'allow_duplicate' => '1',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, $this->activitiesFor($folder, $definition, $coMaker->id));
        $this->assertSame(0, $this->activitiesFor($folder, $definition, null));
    }

    public function test_one_custom_type_lives_independently_under_the_applicant_and_each_co_maker(): void
    {
        $folder = $this->folder();
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $definition = $this->customDefinition($folder, 'Court Records Check');

        foreach ([null, $makerA->id, $makerB->id] as $person) {
            $this->actingAs($this->ci)
                ->post($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $person]))
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        // The block is per exact person, never a global block on the definition.
        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
        $this->assertSame(1, $this->activitiesFor($folder, $definition, $makerA->id));
        $this->assertSame(1, $this->activitiesFor($folder, $definition, $makerB->id));
    }

    public function test_the_same_custom_type_is_independent_in_another_client_folder(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $definition = $this->customDefinition($folder, 'Court Records Check');

        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->addPayload($definition))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->storeUrl($otherFolder), $this->addPayload($definition))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
        $this->assertSame(1, $this->activitiesFor($otherFolder, $definition, null));
    }

    public function test_the_second_create_rechecks_authoritative_rows_rather_than_a_stale_snapshot(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Court Records Check');
        $payload = $this->addPayload($definition);

        // Both requests are built from the same pre-create snapshot; the second still loses.
        // CreateCiActivity locks the ActivityDefinition row and re-reads the folder's activities
        // under that lock, so the duplicate is found even though this payload predates the first
        // insert. Logical ordering only — see the class docblock on the SQLite limitation.
        $this->actingAs($this->ci)->post($this->storeUrl($folder), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($this->storeUrl($folder), $payload)
            ->assertStatus(409)
            ->assertJsonPath('result', 'duplicate_exists');

        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
    }

    // ------------------------------------------------------------------ Custom definitions

    public function test_equivalent_type_names_reuse_one_definition_across_one_submit_adds(): void
    {
        $folder = $this->folder();
        $before = ActivityDefinition::query()->count();

        // First submit: creates the type AND its activity in the same request.
        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->newTypePayload('My Custom Check'))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Activity Type created and activity added successfully.');

        $this->assertSame($before + 1, ActivityDefinition::query()->count());
        $definition = ActivityDefinition::query()->where('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%')->sole();
        $this->assertFalse($definition->is_required);
        $this->assertTrue($definition->is_active);
        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));

        // Equivalent spellings reuse the SAME definition and then hit the collaborative advisory,
        // because this exact person already has the activity.
        foreach ([' my   custom   check ', 'MY CUSTOM CHECK'] as $variation) {
            $this->postJson($this->storeUrl($folder), $this->newTypePayload($variation))
                ->assertStatus(409)
                ->assertJsonPath('result', 'duplicate_exists')
                ->assertJsonPath('message', self::APPLICANT_WARNING);
        }

        $this->assertSame($before + 1, ActivityDefinition::query()->count());
        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));

        // Continue Anyway reuses the same definition and adds a second independent activity.
        $this->post($this->storeUrl($folder), $this->newTypePayload('MY CUSTOM CHECK', ['allow_duplicate' => '1']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 1, ActivityDefinition::query()->count());
        $this->assertSame(2, $this->activitiesFor($folder, $definition, null));
    }

    public function test_one_submit_creates_the_type_and_the_activity_for_the_exact_person(): void
    {
        $folder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Maker Alpha');

        // Applicant, scheduled — the newly typed type uses the ordinary custom-activity workflow,
        // not a weaker catalog-only one.
        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->newTypePayload('Employment Verification', [
                'status' => ActivityStatus::Scheduled->value,
                'scheduled_at' => '2026-09-20',
                'scheduled_time' => '10:30',
                'remarks' => 'Typed in one go.',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $definition = ActivityDefinition::query()->whereRaw('LOWER(name) = ?', ['employment verification'])->sole();
        $applicant = $folder->activities()->where('activity_definition_id', $definition->id)->whereNull('co_maker_id')->sole();
        $this->assertSame(ActivityStatus::Scheduled, $applicant->status);
        $this->assertNotNull($applicant->scheduled_at);
        $this->assertTrue($applicant->scheduled_has_time);
        $this->assertSame('Typed in one go.', $applicant->remarks);
        $this->assertSame($this->ci->id, $applicant->creator_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'ci_activity.created')->count());

        // The same typed name for a Co-Maker reuses the definition and is its own activity.
        $this->post($this->storeUrl($folder), $this->newTypePayload('employment verification', [
            'co_maker_id' => $coMaker->id,
        ]))->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Activity added successfully.');

        $this->assertSame(1, $this->activitiesFor($folder, $definition, $coMaker->id));
        $this->assertSame(1, ActivityDefinition::query()->whereRaw('LOWER(name) = ?', ['employment verification'])->count());
    }

    public function test_one_submit_enforces_the_same_schedule_rules_as_any_custom_activity(): void
    {
        $folder = $this->folder();

        // For Follow-up without a date is refused exactly as it is for an existing type.
        $this->actingAs($this->ci)
            ->postJson($this->storeUrl($folder), $this->newTypePayload('Court Records Check', [
                'status' => ActivityStatus::FollowUp->value,
                'scheduled_at' => '',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);

        // Nothing was created — not the type, not the activity.
        $this->assertSame(0, CiActivity::query()->count());
        $this->assertSame(0, ActivityDefinition::query()->where('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%')->count());

        $this->post($this->storeUrl($folder), $this->newTypePayload('Court Records Check', [
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => '2026-09-21',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, CiActivity::query()->count());
    }

    public function test_the_activity_type_manager_refresh_shows_a_rename_immediately(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Employment Verification');
        $activity = $this->customActivity($folder, null, 'Employment Verification');

        $this->actingAs($this->ci)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), [
                'co_maker_id' => '', 'name' => 'Employment Verification Updated',
            ])->assertOk();

        // The manager re-fetches the CI Activities page with these exact headers and copies
        // [data-activity-type-rows] out of it. If that request did not return the refreshed rows,
        // the rename would succeed in the database and still look broken on screen.
        $html = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->getContent();

        $rowsStart = strpos($html, 'data-activity-type-rows');
        $this->assertNotFalse($rowsStart, 'Expected the Activity Type manager rows in the refreshed page.');
        $rows = substr($html, $rowsStart, strpos($html, '</tbody>', $rowsStart) - $rowsStart);

        $this->assertStringContainsString('Employment Verification Updated', $rows);
        $this->assertStringNotContainsString('>Employment Verification<', $rows);
        $this->assertStringContainsString('data-activity-type-id="'.$definition->id.'"', $rows);

        // Same row, same code, and the linked activity still points at it.
        $renamed = $definition->fresh();
        $this->assertSame($definition->code, $renamed->code);
        $this->assertSame($definition->id, $activity->fresh()->activity_definition_id);
    }

    public function test_reserved_built_in_and_dedicated_module_names_are_still_refused(): void
    {
        $folder = $this->folder();
        $before = ActivityDefinition::query()->count();

        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), [
                'co_maker_id' => '',
                'create_new_activity_type' => '1',
                'new_activity_type' => 'Barangay Check',
            ])
            ->assertRedirect()->assertSessionHasErrors(['new_activity_type']);

        foreach (['Residence Check', 'Business Check'] as $dedicated) {
            $this->post($this->storeUrl($folder), [
                'co_maker_id' => '',
                'create_new_activity_type' => '1',
                'new_activity_type' => $dedicated,
            ])->assertRedirect()->assertSessionHasErrors(['new_activity_type']);
        }

        $this->assertSame($before, ActivityDefinition::query()->count());
    }

    // ------------------------------------------------------------------ Edit / Complete

    public function test_a_stale_custom_activity_edit_conflicts_instead_of_overwriting(): void
    {
        $folder = $this->folder();
        $activity = $this->customActivity($folder);
        $this->assertSame(1, $activity->revision);

        $this->actingAs($this->ci)->put($this->updateUrl($folder, $activity), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => ActivityStatus::Pending->value,
            'remarks' => 'Winner remarks.', 'intent' => 'stay',
        ])->assertRedirect();
        $this->assertSame(2, $activity->fresh()->revision);

        $this->putJson($this->updateUrl($folder, $activity), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => ActivityStatus::Pending->value,
            'remarks' => 'Stale remarks.',
        ])->assertStatus(409)->assertJsonPath('result', 'conflict');

        $this->assertSame('Winner remarks.', $activity->fresh()->remarks);
        $this->assertSame(2, $activity->fresh()->revision);
    }

    public function test_marking_a_custom_activity_completed_advances_the_revision_exactly_once(): void
    {
        $folder = $this->folder();
        $activity = $this->customActivity($folder);
        $auditsBefore = AuditLog::query()->count();

        $this->actingAs($this->ci)->put($this->updateUrl($folder, $activity), [
            'co_maker_id' => '', 'expected_revision' => 1,
            'status' => ActivityStatus::Completed->value, 'intent' => 'return',
        ])->assertRedirect();

        $completed = $activity->fresh();
        $this->assertSame(ActivityStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(2, $completed->revision);
        $this->assertSame($this->ci->id, $completed->updated_by);
        $this->assertSame(1, AuditLog::query()->count() - $auditsBefore);

        // A second form that still holds the old token cannot complete it again.
        $this->putJson($this->updateUrl($folder, $activity), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => ActivityStatus::Completed->value,
        ])->assertStatus(409)->assertJsonPath('result', 'conflict');

        $this->assertSame(2, $activity->fresh()->revision);
        $this->assertSame(1, AuditLog::query()->count() - $auditsBefore);
    }

    public function test_an_edit_opened_before_completion_cannot_reopen_the_completed_activity(): void
    {
        $folder = $this->folder();
        $activity = $this->customActivity($folder);
        $openedRevision = $activity->revision;

        $this->actingAs($this->ci)->put($this->updateUrl($folder, $activity), [
            'co_maker_id' => '', 'expected_revision' => $openedRevision,
            'status' => ActivityStatus::Completed->value, 'intent' => 'return',
        ])->assertRedirect();

        $this->putJson($this->updateUrl($folder, $activity), [
            'co_maker_id' => '', 'expected_revision' => $openedRevision,
            'status' => ActivityStatus::Pending->value,
        ])->assertStatus(409)->assertJsonPath('result', 'conflict');

        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    // ------------------------------------------------------------------ Delete / scope

    public function test_stale_work_against_a_deleted_custom_activity_is_a_safe_friendly_not_found(): void
    {
        $folder = $this->folder();
        $activity = $this->customActivity($folder);
        $activityId = $activity->id;

        $this->actingAs($this->ci)
            ->delete(route('client-folders.activities.destroy', [$folder, $activity]))
            ->assertRedirect();
        $auditsBefore = AuditLog::query()->count();

        // Full page: friendly wording, never the raw exception body.
        $page = $this->get(route('client-folders.activities.edit', [$folder, $activityId]))->assertNotFound();
        $body = $page->getContent();
        $this->assertStringContainsString(self::UNAVAILABLE, $body);
        $this->assertStringNotContainsString('No query results for model', $body);
        $this->assertStringNotContainsString('App\Models\CiActivity', $body);

        // JSON keeps its existing friendly semantics.
        $this->putJson($this->updateUrl($folder, $activityId), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => ActivityStatus::Completed->value,
        ])->assertNotFound()->assertJsonPath('result', 'deleted');

        $this->delete(route('client-folders.activities.destroy', [$folder, $activityId]))->assertNotFound();

        // Nothing recreated, no side effect.
        $this->assertSame(0, CiActivity::query()->count());
        $this->assertSame($auditsBefore, AuditLog::query()->count());
    }

    public function test_a_forged_activity_id_cannot_reach_another_person_or_folder(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $applicantActivity = $this->customActivity($folder);
        $makerAActivity = $this->customActivity($folder, $makerA->id, 'Court Records Check');

        // Another folder's route cannot address this activity at all.
        $this->actingAs($this->ci)
            ->putJson($this->updateUrl($otherFolder, $applicantActivity), [
                'co_maker_id' => '', 'expected_revision' => 1, 'status' => ActivityStatus::Completed->value,
            ])->assertNotFound();

        // A Co-Maker's activity cannot be driven from the Applicant's context, nor from another
        // Co-Maker's — the exact-person assertion rejects both.
        $this->putJson($this->updateUrl($folder, $makerAActivity), [
            'co_maker_id' => '', 'expected_revision' => 1, 'status' => ActivityStatus::Completed->value,
        ])->assertForbidden();
        $this->putJson($this->updateUrl($folder, $makerAActivity), [
            'co_maker_id' => $makerB->id, 'expected_revision' => 1, 'status' => ActivityStatus::Completed->value,
        ])->assertForbidden();

        $this->assertSame(ActivityStatus::Pending, $applicantActivity->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $makerAActivity->fresh()->status);
    }

    public function test_the_add_activity_handler_carries_the_submitter_forward_guard(): void
    {
        $folder = $this->folder();

        $html = $this->actingAs($this->ci)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->getContent();

        // Source-level guard only: PHPUnit renders Blade, it does not run this JavaScript.
        $this->assertStringContainsString('event.submitter instanceof HTMLButtonElement', $html);
        $this->assertStringContainsString('requestBody.append(submitter.name, submitter.value)', $html);
        // The existing double-submit guard is untouched.
        $this->assertStringContainsString("form.dataset.submitting === 'true'", $html);
        // No duplicate-bypass affordance exists for custom activities. Scoped to the Add Activity
        // form itself: the page also hosts the Bank / Coop and Asset tracker handlers, which do
        // legitimately carry allow_duplicate for their own advisory-duplicate flows.
        $formStart = strpos($html, 'data-ci-activity-create-form');
        $this->assertNotFalse($formStart, 'Expected the Add Activity form.');
        $createForm = substr($html, $formStart, strpos($html, '</form>', $formStart) - $formStart);
        // The collaborative advisory names Continue Anyway in its own copy, so this is scoped to
        // the footer: no Continue Anyway ACTION until a duplicate is actually reported.
        $footer = substr($createForm, strrpos($createForm, '<div class="flex shrink-0 flex-col-reverse gap-3 border-t'));
        $this->assertStringNotContainsString('allow_duplicate', $footer);
        $this->assertStringNotContainsString('Continue Anyway', $footer);
    }

    // ------------------------------------------------------------------ Activity Type manager

    public function test_renaming_a_custom_activity_type_keeps_its_id_and_every_linked_activity(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $definition = $this->customDefinition($folder, 'Employment Verification');

        // The same reusable definition used by three different people/folders.
        foreach ([[$folder, null], [$folder, $coMaker->id], [$otherFolder, null]] as [$target, $person]) {
            $this->actingAs($this->ci)
                ->post($this->storeUrl($target), $this->addPayload($definition, ['co_maker_id' => $person]))
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $linkedIds = CiActivity::query()->where('activity_definition_id', $definition->id)->pluck('id')->sort()->values();
        $this->assertCount(3, $linkedIds);

        $this->putJson(route('client-folders.activity-definitions.update', [$folder, $definition]), [
            'co_maker_id' => '', 'name' => 'Employment Verification (HR)',
        ])->assertOk()->assertJsonPath('message', 'Employment Verification (HR) activity type updated.');

        // Same row renamed in place — never recreated, and nothing relinked.
        $renamed = $definition->fresh();
        $this->assertSame($definition->id, $renamed->id);
        $this->assertSame('Employment Verification (HR)', $renamed->name);
        $this->assertSame($definition->code, $renamed->code);
        $this->assertFalse($renamed->is_required);
        $this->assertTrue(
            $linkedIds->diff(CiActivity::query()->where('activity_definition_id', $definition->id)->pluck('id'))->isEmpty(),
        );
    }

    public function test_a_built_in_type_cannot_be_renamed_and_reserved_names_are_refused(): void
    {
        $folder = $this->folder();
        $builtIn = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        $custom = $this->customDefinition($folder, 'Employment Verification');

        // Only custom definitions are manageable; a built-in 404s rather than renaming.
        $this->actingAs($this->ci)
            ->putJson(route('client-folders.activity-definitions.update', [$folder, $builtIn]), [
                'co_maker_id' => '', 'name' => 'Renamed Built In',
            ])->assertNotFound();
        $this->assertSame('Barangay Check', $builtIn->fresh()->name);

        // A custom type cannot take a reserved or dedicated-module name either.
        foreach (['Barangay Check', 'Residence Check', 'Business Check'] as $reserved) {
            $this->putJson(route('client-folders.activity-definitions.update', [$folder, $custom]), [
                'co_maker_id' => '', 'name' => $reserved,
            ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        }
        $this->assertSame('Employment Verification', $custom->fresh()->name);
    }

    public function test_deleting_one_persons_activity_leaves_the_shared_type_and_the_other_activities(): void
    {
        $folder = $this->folder();
        $otherFolder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $definition = $this->customDefinition($folder, 'Employment Verification');

        $applicant = $this->customActivity($folder, null, 'Employment Verification');
        $makerActivity = $this->customActivity($folder, $coMaker->id, 'Employment Verification');
        $otherFolderActivity = $this->customActivity($otherFolder, null, 'Employment Verification');

        $this->actingAs($this->ci)
            ->delete(route('client-folders.activities.destroy', [$folder, $applicant]))
            ->assertRedirect();

        // Only that exact CiActivity is gone; the reusable definition and its other links remain.
        $this->assertDatabaseMissing('ci_activities', ['id' => $applicant->id]);
        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $makerActivity->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $otherFolderActivity->id]);

        // The remaining activities are still editable, and the type can be added again.
        $this->put($this->updateUrl($folder, $makerActivity), [
            'co_maker_id' => $coMaker->id, 'expected_revision' => 1,
            'status' => ActivityStatus::Pending->value, 'remarks' => 'Still editable.', 'intent' => 'stay',
        ])->assertRedirect();
        $this->assertSame('Still editable.', $makerActivity->fresh()->remarks);

        $this->post($this->storeUrl($folder), $this->addPayload($definition))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
    }

    public function test_an_in_use_custom_type_cannot_be_permanently_deleted(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Employment Verification');
        $this->customActivity($folder, null, 'Employment Verification');

        $this->actingAs($this->ci)
            ->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]), ['co_maker_id' => ''])
            ->assertStatus(422);

        $this->assertDatabaseHas('activity_definitions', ['id' => $definition->id]);
    }

    public function test_a_stale_activity_type_never_exposes_the_model_class_or_id(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Employment Verification');
        $staleId = $definition->id;

        // Nothing references it, so the manager may permanently delete it — which is exactly how a
        // second open tab ends up holding a stale row.
        $this->actingAs($this->ci)
            ->deleteJson(route('client-folders.activity-definitions.destroy', [$folder, $definition]), ['co_maker_id' => ''])
            ->assertOk();
        $this->assertDatabaseMissing('activity_definitions', ['id' => $staleId]);

        // The stale tab's Edit and Activate both fail gracefully.
        $json = $this->putJson(route('client-folders.activity-definitions.update', [$folder, $staleId]), [
            'co_maker_id' => '', 'name' => 'Anything',
        ])->assertStatus(404);
        $json->assertJsonPath('result', 'not_available');
        $json->assertJsonPath('status_type', 'error');
        $json->assertJsonPath('message', self::TYPE_UNAVAILABLE);
        $this->assertStringNotContainsString('No query results for model', $json->getContent());
        $this->assertStringNotContainsString('ActivityDefinition', $json->getContent());
        $this->assertStringNotContainsString((string) $staleId, $json->json('message'));

        $this->patchJson(route('client-folders.activity-definitions.activation', [$folder, $staleId]), [
            'co_maker_id' => '', 'is_active' => '0',
        ])->assertStatus(404)->assertJsonPath('result', 'not_available');

        // A full page request gets the friendly page, not Laravel's raw body.
        $page = $this->put(route('client-folders.activity-definitions.update', [$folder, $staleId]), [
            'co_maker_id' => '', 'name' => 'Anything',
        ])->assertNotFound();
        $body = $page->getContent();
        $this->assertStringContainsString(self::TYPE_UNAVAILABLE, $body);
        $this->assertStringNotContainsString('No query results for model', $body);
        $this->assertStringNotContainsString('Models\ActivityDefinition', $body);
    }

    public function test_the_duplicate_add_advisory_is_person_aware_and_creates_nothing(): void
    {
        $folder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $definition = $this->customDefinition($folder, 'Employment Verification');

        $this->actingAs($this->ci)->post($this->storeUrl($folder), $this->addPayload($definition))
            ->assertRedirect()->assertSessionHasNoErrors();

        // 409 advisory, not a 422 validation failure — the CI may legitimately proceed.
        $this->postJson($this->storeUrl($folder), $this->addPayload($definition))
            ->assertStatus(409)
            ->assertJsonPath('result', 'duplicate_exists')
            ->assertJsonPath('status_type', 'warning')
            ->assertJsonPath('message', self::APPLICANT_WARNING);

        $this->post($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMaker->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMaker->id]))
            ->assertStatus(409)
            ->assertJsonPath('message', self::CO_MAKER_WARNING);

        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
        $this->assertSame(1, $this->activitiesFor($folder, $definition, $coMaker->id));
    }

    public function test_the_add_dialog_renders_the_duplicate_message_on_the_activity_type_field(): void
    {
        $folder = $this->folder();

        $html = $this->actingAs($this->ci)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->getContent();

        // Source-level guards: PHPUnit renders Blade, it does not run this JavaScript, so the
        // visible placement still needs a manual browser check.
        $this->assertStringContainsString("fieldError('activity_definition_id')", $html);
        $this->assertStringContainsString("fieldError('new_activity_type')", $html);
        $this->assertStringContainsString('revealFirstInvalid(activityTypeTrigger)', $html);
        $this->assertStringContainsString('data-ci-activity-type-error', $html);
    }

    // ------------------------------------------------------------------ Full-page error display

    public function test_a_full_page_applicant_duplicate_reopens_the_modal_showing_the_reason(): void
    {
        $folder = $this->folder();
        $definition = $this->customDefinition($folder, 'Employment Verification');
        $indexUrl = route('client-folders.activities.index', $folder);

        $this->actingAs($this->ci)->post($this->storeUrl($folder), $this->addPayload($definition))
            ->assertRedirect()->assertSessionHasNoErrors();

        // The non-JSON path: exactly what the browser does when the fetch handler is not in play.
        // followingRedirects() is the real browser lifecycle: the assertions below run against the
        // page the CI actually lands on, with this request's error bag in hand.
        $html = $this->from($indexUrl)
            ->followingRedirects()
            ->post($this->storeUrl($folder), $this->addPayload($definition))
            ->assertOk()
            ->getContent();

        // A same-person duplicate is now the collaborative advisory, not a field validation error,
        // so the modal reopens explaining itself and offering Continue Anyway.
        $this->assertStringContainsString(self::APPLICANT_WARNING, $html);
        $this->assertStringContainsString('data-ci-activity-duplicate-warning', $html);
        $this->assertStringContainsString('data-value="'.$definition->id.'"', $html);
        $this->assertSame(1, $this->activitiesFor($folder, $definition, null));
    }

    public function test_a_full_page_co_maker_duplicate_shows_the_co_maker_wording(): void
    {
        $folder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $definition = $this->customDefinition($folder, 'Employment Verification');
        $indexUrl = route('client-folders.activities.index', [$folder, 'co_maker_id' => $coMaker->id]);

        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMaker->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $html = $this->from($indexUrl)
            ->followingRedirects()
            ->post($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMaker->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::CO_MAKER_WARNING, $html);
        $this->assertStringContainsString('data-ci-activity-duplicate-warning', $html);
        $this->assertSame(1, $this->activitiesFor($folder, $definition, $coMaker->id));
    }

    public function test_a_full_page_reserved_or_dedicated_type_name_shows_its_reason(): void
    {
        $folder = $this->folder();
        $indexUrl = route('client-folders.activities.index', $folder);

        $cases = [
            'Barangay Check' => 'This name is reserved for a built-in Activity Type.',
            'Residence Check' => 'Residence Check and Business Check use their dedicated Client Folder modules.',
        ];

        foreach ($cases as $name => $expected) {
            $slot = $this->fieldErrorSlot(
                $this->actingAs($this->ci)->from($indexUrl)
                    ->followingRedirects()
                    ->post($this->storeUrl($folder), [
                        'co_maker_id' => '',
                        'create_new_activity_type' => '1',
                        'new_activity_type' => $name,
                    ])
                    ->assertOk()
                    ->getContent(),
                'data-ci-new-activity-type-error',
            );

            $this->assertStringContainsString($expected, $slot);
            $this->assertStringNotContainsString('hidden', $slot);
            $this->assertStringContainsString('data-default-message="Please enter an Activity Type name."', $slot);
        }
    }

    public function test_both_field_slots_stay_hidden_with_their_client_side_copy_when_nothing_failed(): void
    {
        $folder = $this->folder();

        $html = $this->actingAs($this->ci)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->getContent();

        foreach ([
            'data-ci-activity-type-error' => 'Please select an Activity Type.',
            'data-ci-new-activity-type-error' => 'Please enter an Activity Type name.',
        ] as $hook => $default) {
            $slot = $this->fieldErrorSlot($html, $hook);
            $this->assertStringContainsString('hidden', $slot);
            $this->assertStringContainsString($default, $slot);
        }

        // The banner is focused, never scrolled through a container it does not live in.
        $this->assertStringContainsString('requestError.focus({ preventScroll: true })', $html);
        $this->assertStringNotContainsString('revealFirstInvalid(requestError)', $html);
        // The JS default comes from the attribute, not from this request's rendered message.
        $this->assertStringContainsString('activityTypeError.dataset.defaultMessage', $html);
        $this->assertStringContainsString('newActivityTypeError.dataset.defaultMessage', $html);
    }

    /** One field error paragraph, isolated so attribute assertions cannot drift. */
    private function fieldErrorSlot(string $html, string $hook): string
    {
        $position = strpos($html, $hook);
        $this->assertNotFalse($position, 'Expected a field error slot marked '.$hook.'.');
        $start = strrpos(substr($html, 0, $position), '<p ');
        $this->assertNotFalse($start, 'Expected the slot to be a <p> element.');
        $end = strpos($html, '</p>', $position);

        return substr($html, $start, $end - $start);
    }

    // ------------------------------------------------------------------ helpers

    private function storeUrl(ClientFolder $folder): string
    {
        return route('client-folders.activities.store', $folder);
    }

    private function updateUrl(ClientFolder $folder, CiActivity|int $activity): string
    {
        return route('client-folders.activities.update', [$folder, $activity]);
    }

    /** One submit: type the new Activity Type name AND the activity's own fields together. */
    private function newTypePayload(string $name, array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => '1',
            'new_activity_type' => $name,
            'status' => ActivityStatus::Pending->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => null,
        ];
    }

    private function addPayload(ActivityDefinition $definition, array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'activity_definition_id' => $definition->id,
            'create_new_activity_type' => '0',
            'status' => ActivityStatus::Pending->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => null,
        ];
    }

    private function activitiesFor(ClientFolder $folder, ActivityDefinition $definition, ?int $coMakerId): int
    {
        return $folder->activities()
            ->where('activity_definition_id', $definition->id)
            ->where('co_maker_id', $coMakerId)
            ->count();
    }

    /**
     * Creates just the reusable definition. Adding is one submit now, so the HTTP route always
     * creates an activity too — this uses the same internal resolver the Add path uses when a test
     * needs the type to exist without an activity yet.
     */
    private function customDefinition(ClientFolder $folder, string $name): ActivityDefinition
    {
        return app(CreateCiActivity::class)
            ->createDefinition($this->ci, $folder, $name);
    }

    private function customActivity(ClientFolder $folder, ?int $coMakerId = null, string $name = 'Court Records Check'): CiActivity
    {
        $definition = ActivityDefinition::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? $this->customDefinition($folder, $name);

        $this->actingAs($this->ci)
            ->post($this->storeUrl($folder), $this->addPayload($definition, ['co_maker_id' => $coMakerId]))
            ->assertRedirect()->assertSessionHasNoErrors();

        return $folder->activities()
            ->where('activity_definition_id', $definition->id)
            ->where('co_maker_id', $coMakerId)
            ->sole()
            ->fresh();
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name]);
    }

    private function folder(): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);

        return $folder;
    }
}
