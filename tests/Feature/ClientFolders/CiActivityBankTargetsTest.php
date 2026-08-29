<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateCiActivity;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CiActivityBankTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->bankDefinition();
    }

    public function test_add_activity_ui_contains_stable_bank_detection_one_initial_target_and_repeater(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = $this->bankDefinition();

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-code="'.ActivityDefinition::BANK_COOP_CHECK_CODE.'"', false)
            ->assertSee('data-bank-targets-section', false)
            ->assertSee('data-bank-target-row', false)
            ->assertSee('Add Another Bank / Coop')
            ->assertSee('Schedule / Follow-up Date')
            ->assertSee('Short Remarks')
            ->assertSee('addingBankCoopCheck', false)
            ->assertSee('const addingBankActivity = ! addingActivityType && ! bankTargetSection.hidden;', false)
            ->assertDontSee('const addingBankActivity = ! addingActivityType && addingBankCoopCheck();', false)
            ->assertSee('data-value="'.$definition->id.'"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-bank-target-index="0"'));
        $this->assertSame(0, substr_count($page->getContent(), 'data-standard-activity-field hidden'));

        $indexUrl = route('client-folders.activities.index', $folder);
        $invalidBankPayload = $this->bankActivityPayload([
            $this->target('', ActivityStatus::Pending),
        ]);
        $this->from($indexUrl)
            ->post(route('client-folders.activities.store', $folder), $invalidBankPayload)
            ->assertRedirect($indexUrl)
            ->assertSessionHasErrors('bank_targets.0.institution_name');

        $bankPage = $this->withSession(['_old_input' => $invalidBankPayload])->get($indexUrl);
        $bankPage->assertOk()
            ->assertSee('data-bank-targets-section', false)
            ->assertSee('Bank / Coop Name')
            ->assertSee('Add Another Bank / Coop');
        $this->assertSame(3, preg_match_all('/data-standard-activity-field[^>]*\shidden(?:\s|>)/', $bankPage->getContent()));

        $completedBankPage = $this->withSession(['_old_input' => $this->bankActivityPayload([
            $this->target('BDO', ActivityStatus::Completed),
            $this->target('BPI', ActivityStatus::Completed),
        ])])->get($indexUrl);
        $completedBankPage->assertOk();
        $this->assertDoesNotMatchRegularExpression('/data-activity-create-only[^>]*\shidden(?:\s|>)/', $completedBankPage->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-ci-activity-attachment[^>]*\sdisabled(?:\s|>)/', $completedBankPage->getContent());
    }

    public function test_one_three_and_sparse_index_bank_target_payloads_create_every_target(): void
    {
        $ci = User::factory()->create();

        $oneFolder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.activities.store', $oneFolder), $this->bankActivityPayload([
            $this->target('Single Bank', ActivityStatus::Pending),
        ]), [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk()->assertJson(['activity_created' => true]);
        $oneActivity = $oneFolder->activities()->sole();
        $this->assertSame(1, $oneActivity->bankTargets()->count());
        $this->assertSame(ActivityStatus::Pending, $oneActivity->status);

        $threeFolder = $this->folderFor($ci);
        $threeResponse = $this->post(route('client-folders.activities.store', $threeFolder), $this->bankActivityPayload([
            $this->target('BDO Carmen', ActivityStatus::Scheduled, '2026-08-31', '10:00'),
            $this->target('BPI Lapasan', ActivityStatus::Completed),
            $this->target('LandBank CDO', ActivityStatus::FollowUp, '2026-09-01'),
        ]), [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        $threeResponse->assertOk()
            ->assertJson(['activity_created' => true])
            ->assertJsonStructure(['redirect']);

        $threeActivity = $threeFolder->activities()->sole();
        $this->assertSame(3, $threeActivity->bankTargets()->count());
        $this->assertSame(ActivityStatus::FollowUp, $threeActivity->status);
        $this->assertNull($threeActivity->scheduled_at);
        $this->assertFalse($threeActivity->scheduled_has_time);
        $this->assertNull($threeActivity->remarks);

        $scheduled = $threeActivity->bankTargets()->where('institution_name', 'BDO Carmen')->sole();
        $this->assertSame('BDO Carmen Branch', $scheduled->branch_location);
        $this->assertSame(ActivityStatus::Scheduled, $scheduled->status);
        $this->assertTrue($scheduled->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-08-31 10:00', 'Asia/Manila')->utc()));
        $this->assertTrue($scheduled->scheduled_has_time);
        $this->assertSame('BDO Carmen remarks.', $scheduled->remarks);

        $completed = $threeActivity->bankTargets()->where('institution_name', 'BPI Lapasan')->sole();
        $this->assertSame('BPI Lapasan Branch', $completed->branch_location);
        $this->assertSame(ActivityStatus::Completed, $completed->status);
        $this->assertNull($completed->scheduled_at);
        $this->assertFalse($completed->scheduled_has_time);
        $this->assertSame('BPI Lapasan remarks.', $completed->remarks);

        $followUp = $threeActivity->bankTargets()->where('institution_name', 'LandBank CDO')->sole();
        $this->assertSame('LandBank CDO Branch', $followUp->branch_location);
        $this->assertSame(ActivityStatus::FollowUp, $followUp->status);
        $this->assertTrue($followUp->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-01 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($followUp->scheduled_has_time);
        $this->assertSame('LandBank CDO remarks.', $followUp->remarks);

        $sparseFolder = $this->folderFor($ci);
        $this->post(route('client-folders.activities.store', $sparseFolder), $this->bankActivityPayload([
            0 => $this->target('First Remaining Bank', ActivityStatus::Pending),
            2 => $this->target('Third Remaining Bank', ActivityStatus::Completed),
        ]), [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk()->assertJson(['activity_created' => true]);

        $sparseActivity = $sparseFolder->activities()->sole();
        $this->assertSame(2, $sparseActivity->bankTargets()->count());
        $this->assertEqualsCanonicalizing(
            ['First Remaining Bank', 'Third Remaining Bank'],
            $sparseActivity->bankTargets()->pluck('institution_name')->all(),
        );
        $this->assertSame(ActivityStatus::Pending, $sparseActivity->status);
    }

    public function test_invalid_third_target_rejects_the_whole_create_without_partial_records(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)
            ->from(route('client-folders.activities.index', $folder))
            ->post(route('client-folders.activities.store', $folder), $this->bankActivityPayload([
                $this->target('Valid First Bank', ActivityStatus::Pending),
                $this->target('Valid Second Bank', ActivityStatus::Completed),
                $this->target('', ActivityStatus::FollowUp, '2026-09-01'),
            ]))
            ->assertRedirect(route('client-folders.activities.index', $folder))
            ->assertSessionHasErrors('bank_targets.2.institution_name');

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
    }

    public function test_bank_check_creates_exactly_one_parent_and_all_independent_targets_with_manila_timezone_normalization(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $payload = $this->bankActivityPayload([
            $this->target('Pending Bank', ActivityStatus::Pending, '2026-08-31', '11:00'),
            $this->target('Scheduled Date Only', ActivityStatus::Scheduled, '2026-08-31'),
            $this->target('Scheduled With Time', ActivityStatus::Scheduled, '2026-09-01', '10:15'),
            $this->target('Follow-up Bank', ActivityStatus::FollowUp),
            $this->target('Follow-up Date Only', ActivityStatus::FollowUp, '2026-09-02'),
            $this->target('Follow-up With Time', ActivityStatus::FollowUp, '2026-09-03', '14:45'),
            $this->target('Completed Bank', ActivityStatus::Completed, '2026-09-02', '14:00'),
        ]) + [
            'status' => ActivityStatus::Completed->value,
            'scheduled_at' => '2026-12-31',
            'scheduled_time' => '23:59',
            'remarks' => 'Forged parent values must be ignored.',
        ];
        $response = $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $payload, [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertOk()->assertJson(['activity_created' => true]);
        $this->assertDatabaseCount('ci_activities', 1);
        $this->assertDatabaseCount('ci_activity_bank_targets', 7);
        $activity = CiActivity::sole();
        $this->assertSame(ActivityStatus::FollowUp, $activity->status);
        $this->assertSame(ActivityDefinition::BANK_COOP_CHECK_CODE, $activity->definition->code);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);
        $this->assertNull($activity->remarks);

        $pending = $activity->bankTargets()->where('institution_name', 'Pending Bank')->sole();
        $this->assertNull($pending->scheduled_at);
        $this->assertFalse($pending->scheduled_has_time);

        $dateOnly = $activity->bankTargets()->where('institution_name', 'Scheduled Date Only')->sole();
        $this->assertTrue($dateOnly->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-08-31 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($dateOnly->scheduled_has_time);

        $withTime = $activity->bankTargets()->where('institution_name', 'Scheduled With Time')->sole();
        $this->assertTrue($withTime->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-01 10:15', 'Asia/Manila')->utc()));
        $this->assertTrue($withTime->scheduled_has_time);

        $followUp = $activity->bankTargets()->where('institution_name', 'Follow-up Bank')->sole();
        $this->assertNull($followUp->scheduled_at);
        $this->assertFalse($followUp->scheduled_has_time);

        $followUpDateOnly = $activity->bankTargets()->where('institution_name', 'Follow-up Date Only')->sole();
        $this->assertTrue($followUpDateOnly->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-02 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($followUpDateOnly->scheduled_has_time);

        $followUpWithTime = $activity->bankTargets()->where('institution_name', 'Follow-up With Time')->sole();
        $this->assertTrue($followUpWithTime->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-03 14:45', 'Asia/Manila')->utc()));
        $this->assertTrue($followUpWithTime->scheduled_has_time);

        $completed = $activity->bankTargets()->where('institution_name', 'Completed Bank')->sole();
        $this->assertNull($completed->scheduled_at);
        $this->assertFalse($completed->scheduled_has_time);
        $this->assertTrue($activity->bankTargets->every(fn (CiActivityBankTarget $target): bool => $target->created_by === $ci->id && $target->updated_by === $ci->id));
    }

    public function test_parent_status_uses_target_precedence_without_parent_schedule(): void
    {
        $ci = User::factory()->create();
        $cases = [
            [ActivityStatus::Pending, [ActivityStatus::Pending, ActivityStatus::Pending]],
            [ActivityStatus::Scheduled, [ActivityStatus::Pending, ActivityStatus::Scheduled]],
            [ActivityStatus::FollowUp, [ActivityStatus::Scheduled, ActivityStatus::FollowUp]],
            [ActivityStatus::Completed, [ActivityStatus::Completed, ActivityStatus::Completed]],
        ];

        foreach ($cases as [$expected, $statuses]) {
            $folder = $this->folderFor($ci);
            $targets = array_map(
                fn (ActivityStatus $status, int $index): array => $this->target('Target '.($index + 1), $status, '2026-09-01', '09:30'),
                $statuses,
                array_keys($statuses),
            );

            $this->actingAs($ci)
                ->post(route('client-folders.activities.store', $folder), $this->bankActivityPayload($targets))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $activity = $folder->activities()->sole();
            $this->assertSame($expected, $activity->status);
            $this->assertNull($activity->scheduled_at);
            $this->assertFalse($activity->scheduled_has_time);
            $this->assertNull($activity->remarks);
        }
    }

    public function test_scheduled_and_follow_up_targets_allow_empty_schedules_but_time_without_date_and_malformed_input_are_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)
            ->from(route('client-folders.activities.index', $folder))
            ->post(route('client-folders.activities.store', $folder), $this->bankActivityPayload([
                $this->target('Scheduled Without Date', ActivityStatus::Scheduled),
                $this->target('Follow-up Without Date', ActivityStatus::FollowUp),
            ]))
            ->assertRedirect(route('client-folders.activities.index', [$folder, 'status' => 'all']))
            ->assertSessionHasNoErrors();

        $activity = $folder->activities()->sole();
        $this->assertSame(ActivityStatus::FollowUp, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);
        $this->assertTrue($activity->bankTargets->every(fn (CiActivityBankTarget $target): bool => $target->scheduled_at === null && ! $target->scheduled_has_time));

        $timeWithoutDateFolder = $this->folderFor($ci);
        $this->actingAs($ci)
            ->post(route('client-folders.activities.store', $timeWithoutDateFolder), $this->bankActivityPayload([
                $this->target('Time Without Date', ActivityStatus::Scheduled, null, '09:30'),
            ]))
            ->assertSessionHasErrors([
                'bank_targets.0.scheduled_time' => 'Select a date to use a specific time.',
            ]);
        $this->assertDatabaseCount('ci_activities', 1);

        $malformedFolder = $this->folderFor($ci);
        $this->actingAs($ci)
            ->post(route('client-folders.activities.store', $malformedFolder), $this->bankActivityPayload(['malformed-target']))
            ->assertSessionHasErrors('bank_targets.0');
        $this->assertDatabaseCount('ci_activities', 1);
    }

    public function test_child_exception_rolls_back_parent_and_previously_created_children(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $data = $this->bankActivityPayload([
            $this->target('First Valid Bank', ActivityStatus::Pending),
            ['institution_name' => 'Invalid Bank', 'status' => 'invalid-status'],
        ]);

        try {
            app(CreateCiActivity::class)->execute($ci, $folder, $data);
            $this->fail('The invalid child status did not fail creation.');
        } catch (\ValueError) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
    }

    public function test_non_bank_activity_creation_remains_unchanged_and_has_no_targets(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $definition = ActivityDefinition::query()->where('code', '!=', ActivityDefinition::BANK_COOP_CHECK_CODE)->where('is_active', true)->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $definition->id,
            'create_new_activity_type' => false,
            'status' => ActivityStatus::Pending->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ci_activities', 1);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
    }

    public function test_table_shows_progress_and_only_name_subtitle_links_to_detail(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $this->createTarget($activity, $ci, 'BDO', ActivityStatus::Completed);
        $this->createTarget($activity, $ci, 'BPI', ActivityStatus::Completed);
        $followUpTarget = $this->createTarget($activity, $ci, 'LandBank', ActivityStatus::FollowUp);
        $activity->update(['status' => ActivityStatus::FollowUp]);
        $detailUrl = route('client-folders.activities.bank-coop.show', [$folder, $activity]);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('3 institutions · 2 completed')
            ->assertSee('href="'.$detailUrl.'"', false)
            ->assertSee('data-bank-coop-open="'.$activity->id.'"', false)
            ->assertSee('data-bank-coop-url="'.$detailUrl.'"', false)
            ->assertSee('id="bank-coop-tracker-modal"', false)
            ->assertSee('data-bank-coop-modal-body', false)
            ->assertSee('event.preventDefault();', false)
            ->assertSee("headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }", false);
        $this->assertSame(1, substr_count($page->getContent(), 'href="'.$detailUrl.'"'));
        $this->assertDoesNotMatchRegularExpression('/<tr[^>]*href="'.preg_quote($detailUrl, '/').'"/i', $page->getContent());

        $detail = $this->get($detailUrl);
        $detail->assertOk()
            ->assertSee('data-bank-coop-modal-source', false)
            ->assertSee('data-bank-coop-activity-id="'.$activity->id.'"', false)
            ->assertSee('data-bank-coop-context="Applicant: '.$folder->display_name.'"', false)
            ->assertSee('data-bank-coop-target-count="3"', false)
            ->assertSee('data-bank-coop-completed-count="2"', false)
            ->assertSee('data-bank-coop-status="follow_up"', false)
            ->assertSee('2 of 3 Completed')
            ->assertSee('BDO')
            ->assertSee('BPI')
            ->assertSee('LandBank')
            ->assertSee('id="add-bank-target"', false)
            ->assertSee('data-bank-target-follow-up="'.$followUpTarget->id.'"', false)
            ->assertSee('id="edit-bank-target-'.$followUpTarget->id.'"', false)
            ->assertSee('id="delete-bank-target-'.$followUpTarget->id.'"', false);
    }

    public function test_detail_add_edit_and_delete_preserve_parent_and_track_actual_actors(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->bankActivity($folder, $creator);
        $detailUrl = route('client-folders.activities.bank-coop.show', [$folder, $activity]);

        $this->actingAs($creator)->get($detailUrl)
            ->assertOk()
            ->assertSee('Applicant: '.$folder->display_name)
            ->assertSee('0 of 0 Completed')
            ->assertSee('Add Bank / Coop');

        $this->from($detailUrl)->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), [
            'co_maker_id' => '',
            'institution_name' => 'Invalid Time Bank',
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => '',
            'scheduled_time' => '09:30',
        ])->assertRedirect($detailUrl)->assertSessionHasErrors([
            'scheduled_time' => 'Select a date to use a specific time.',
        ]);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);

        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), [
            'co_maker_id' => '',
            'institution_name' => 'BDO',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => 'Scheduled with bank staff.',
        ])->assertRedirect($detailUrl);

        $target = $activity->bankTargets()->sole();
        $this->assertSame($creator->id, $target->created_by);
        $this->assertSame($creator->id, $target->updated_by);
        $this->assertNull($target->scheduled_at);
        $this->assertFalse($target->scheduled_has_time);
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        $this->assertNull($activity->fresh()->scheduled_at);
        $this->assertFalse($activity->fresh()->scheduled_has_time);
        $this->get($detailUrl)
            ->assertOk()
            ->assertSee('No schedule set')
            ->assertSee('Schedule Date')
            ->assertSee('Date and time are optional. Select a date to enable a specific time.');

        $folder->update(['assigned_ci_id' => $updater->id]);
        $this->actingAs($updater)->put(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), [
            'co_maker_id' => '',
            'institution_name' => 'BDO Updated',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => 'Bank staff was busy.',
        ])->assertRedirect($detailUrl);

        $target->refresh();
        $this->assertSame('BDO Updated', $target->institution_name);
        $this->assertSame($creator->id, $target->created_by);
        $this->assertSame($updater->id, $target->updated_by);
        $this->assertNull($target->scheduled_at);
        $this->assertFalse($target->scheduled_has_time);
        $this->assertSame(ActivityStatus::FollowUp, $activity->fresh()->status);
        $this->get($detailUrl)->assertOk()->assertSee('No follow-up date set');

        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), [
            'co_maker_id' => '',
            'institution_name' => 'BDO Updated',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => '2026-09-02',
            'scheduled_time' => '',
            'remarks' => 'Bank staff was busy.',
        ])->assertRedirect($detailUrl);
        $target->refresh();
        $this->assertFalse($target->scheduled_has_time);
        $this->assertTrue($target->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-02 08:00', 'Asia/Manila')->utc()));
        $this->get($detailUrl)->assertOk()->assertSee('No specific time');

        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), [
            'co_maker_id' => '',
            'institution_name' => 'BDO Updated',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::FollowUp->value,
            'scheduled_at' => '2026-09-03',
            'scheduled_time' => '14:45',
            'remarks' => 'Bank staff was busy.',
        ])->assertRedirect($detailUrl);
        $target->refresh();
        $this->assertTrue($target->scheduled_has_time);
        $this->assertTrue($target->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-03 14:45', 'Asia/Manila')->utc()));

        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]), [
            'co_maker_id' => '',
            'institution_name' => 'BDO Updated',
            'branch_location' => 'Carmen Branch',
            'status' => ActivityStatus::Completed->value,
            'remarks' => 'Result obtained.',
        ])->assertRedirect($detailUrl);
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
        $this->assertNotNull($activity->fresh()->completed_at);

        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), [
            'co_maker_id' => '',
            'institution_name' => 'LandBank',
            'status' => ActivityStatus::Pending->value,
        ])->assertRedirect($detailUrl);
        $pendingTarget = $activity->bankTargets()->where('institution_name', 'LandBank')->sole();
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->assertNull($activity->fresh()->completed_at);

        $this->delete(route('client-folders.activities.bank-targets.destroy', [$folder, $activity, $pendingTarget]), ['co_maker_id' => ''])
            ->assertRedirect($detailUrl);
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);

        $this->delete(route('client-folders.activities.bank-targets.destroy', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect($detailUrl);
        $this->assertDatabaseMissing('ci_activity_bank_targets', ['id' => $target->id]);
        $this->assertDatabaseHas('ci_activities', ['id' => $activity->id]);
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->assertNull($activity->fresh()->scheduled_at);
        $this->assertFalse($activity->fresh()->scheduled_has_time);
    }

    public function test_target_checkboxes_confirm_and_complete_exact_target_with_audit_and_parent_sync(): void
    {
        $creator = User::factory()->create();
        $completer = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->bankActivity($folder, $creator);
        $pending = $this->createTarget($activity, $creator, 'Pending Bank', ActivityStatus::Pending);
        $scheduled = $this->createTarget($activity, $creator, 'BDO', ActivityStatus::Scheduled);
        $scheduled->update([
            'branch_location' => 'Carmen Branch',
            'scheduled_at' => Carbon::createFromFormat('!Y-m-d H:i', '2026-08-31 10:00', 'Asia/Manila')->utc(),
            'scheduled_has_time' => true,
        ]);
        $followUp = $this->createTarget($activity, $creator, 'Follow-up Bank', ActivityStatus::FollowUp);
        $completed = $this->createTarget($activity, $creator, 'Completed Bank', ActivityStatus::Completed);
        $activity->update(['status' => ActivityStatus::FollowUp]);
        $detailUrl = route('client-folders.activities.bank-coop.show', [$folder, $activity]);

        $detail = $this->actingAs($creator)->get($detailUrl);
        $detail->assertOk()
            ->assertSee('data-bank-target-complete', false)
            ->assertSee('id="complete-bank-target-'.$scheduled->id.'"', false)
            ->assertSee('Mark as Completed?')
            ->assertSee('BDO – Carmen Branch')
            ->assertSee('This confirms that the Bank / Coop check for this institution has been completed.')
            ->assertSee('Mark Completed');
        foreach ([$pending, $scheduled, $followUp] as $incompleteTarget) {
            $this->assertMatchesRegularExpression(
                '/<input(?=[^>]*data-bank-target-checkbox="'.$incompleteTarget->id.'")(?![^>]*\schecked(?:\s|=))[^>]*>/i',
                $detail->getContent(),
            );
        }
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*data-bank-target-checkbox="'.$completed->id.'")(?=[^>]*\schecked(?:\s|=))(?=[^>]*\sdisabled(?:\s|=))[^>]*>/i',
            $detail->getContent(),
        );
        $detail->assertDontSee('id="complete-bank-target-'.$completed->id.'"', false);
        $this->assertSame(ActivityStatus::Scheduled, $scheduled->fresh()->status);

        $pending->update(['status' => ActivityStatus::Completed]);
        $followUp->update(['status' => ActivityStatus::Completed]);
        $folder->update(['assigned_ci_id' => $completer->id]);
        $completeRoute = route('client-folders.activities.bank-targets.complete', [$folder, $activity, $scheduled]);
        $this->actingAs($completer)->patch($completeRoute, ['co_maker_id' => ''])
            ->assertRedirect($detailUrl);

        $scheduled->refresh();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $scheduled->status);
        $this->assertNull($scheduled->scheduled_at);
        $this->assertFalse($scheduled->scheduled_has_time);
        $this->assertSame($creator->id, $scheduled->created_by);
        $this->assertSame($completer->id, $scheduled->updated_by);
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNotNull($activity->completed_at);
        $this->assertSame($creator->id, $activity->creator_id);

        $audit = AuditLog::query()->where('action', 'ci_activity.bank_target_completed')->sole();
        $this->assertSame($completer->id, $audit->user_id);
        $this->assertSame($folder->id, $audit->client_folder_id);
        $this->assertSame($activity->id, (int) data_get($audit->metadata, 'activity_id'));
        $this->assertSame($scheduled->id, (int) data_get($audit->metadata, 'bank_target_id'));
        $this->assertSame('BDO – Carmen Branch', data_get($audit->metadata, 'bank_target_label'));
        $this->assertStringContainsString($completer->full_name, $audit->description);

        $completedDetail = $this->get($detailUrl);
        $completedDetail->assertOk()
            ->assertSee('4 of 4 Completed')
            ->assertSee('BDO')
            ->assertDontSee('id="complete-bank-target-'.$scheduled->id.'"', false);
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*data-bank-target-checkbox="'.$scheduled->id.'")(?=[^>]*\schecked(?:\s|=))(?=[^>]*\sdisabled(?:\s|=))[^>]*>/i',
            $completedDetail->getContent(),
        );

        $index = $this->get(route('client-folders.activities.index', $folder));
        $index->assertOk()
            ->assertSee('4 institutions · 4 completed')
            ->assertSee('BDO – Carmen Branch completed')
            ->assertSee($completer->full_name)
            ->assertSee('Mark as Submitted');

        $this->patch($completeRoute, ['co_maker_id' => ''])->assertRedirect($detailUrl);
        $this->assertSame(1, AuditLog::query()->where('action', 'ci_activity.bank_target_completed')->count());
    }

    public function test_target_id_substitution_and_applicant_co_maker_scope_crossing_are_rejected(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMakerA = $this->coMaker($folder, 'Maker Alpha');
        $coMakerB = $this->coMaker($folder, 'Maker Beta');
        $applicantActivity = $this->bankActivity($folder, $ci);
        $otherApplicantActivity = $this->bankActivity($folder, $ci);
        $coMakerActivityA = $this->bankActivity($folder, $ci, $coMakerA->id);
        $coMakerActivityB = $this->bankActivity($folder, $ci, $coMakerB->id);
        $otherFolderActivity = $this->bankActivity($otherFolder, $ci);
        $applicantTarget = $this->createTarget($applicantActivity, $ci, 'Applicant Bank', ActivityStatus::Pending);
        $coMakerTarget = $this->createTarget($coMakerActivityA, $ci, 'Maker A Bank', ActivityStatus::Pending);

        $payload = [
            'co_maker_id' => '',
            'institution_name' => 'Substitution',
            'status' => ActivityStatus::Pending->value,
        ];

        $this->actingAs($ci)
            ->put(route('client-folders.activities.bank-targets.update', [$folder, $otherApplicantActivity, $applicantTarget]), $payload)
            ->assertNotFound();
        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $coMakerActivityB, $coMakerTarget]), $payload + ['co_maker_id' => $coMakerB->id])
            ->assertNotFound();
        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $coMakerActivityA, $applicantTarget]), $payload + ['co_maker_id' => $coMakerA->id])
            ->assertNotFound();
        $this->put(route('client-folders.activities.bank-targets.update', [$otherFolder, $otherFolderActivity, $applicantTarget]), $payload)
            ->assertNotFound();
        $this->patch(route('client-folders.activities.bank-targets.complete', [$folder, $otherApplicantActivity, $applicantTarget]), ['co_maker_id' => ''])
            ->assertNotFound();
        $this->patch(route('client-folders.activities.bank-targets.complete', [$folder, $coMakerActivityB, $coMakerTarget]), ['co_maker_id' => $coMakerB->id])
            ->assertNotFound();
        $this->patch(route('client-folders.activities.bank-targets.complete', [$folder, $coMakerActivityA, $applicantTarget]), ['co_maker_id' => $coMakerA->id])
            ->assertNotFound();
        $this->patch(route('client-folders.activities.bank-targets.complete', [$otherFolder, $otherFolderActivity, $applicantTarget]), ['co_maker_id' => ''])
            ->assertNotFound();

        $this->get(route('client-folders.activities.bank-coop.show', [$folder, $coMakerActivityA]))->assertNotFound();
        $this->get(route('client-folders.activities.bank-coop.show', [
            $folder,
            $coMakerActivityA,
            'person' => 'co-maker',
            'co_maker_id' => $coMakerA->id,
        ]))->assertOk()
            ->assertSee('Co-Maker: '.$coMakerA->full_name)
            ->assertSee('data-bank-coop-context="Co-Maker: '.$coMakerA->full_name.'"', false)
            ->assertSee('Maker A Bank')
            ->assertDontSee('Applicant Bank');

        $this->assertSame('Applicant Bank', $applicantTarget->fresh()->institution_name);
        $this->assertSame('Maker A Bank', $coMakerTarget->fresh()->institution_name);
    }

    private function bankActivityPayload(array $targets): array
    {
        return [
            'activity_definition_id' => $this->bankDefinition()->id,
            'create_new_activity_type' => false,
            'bank_targets' => $targets,
        ];
    }

    private function target(string $name, ActivityStatus $status, ?string $date = null, ?string $time = null): array
    {
        return [
            'institution_name' => $name,
            'branch_location' => $name.' Branch',
            'status' => $status->value,
            'scheduled_at' => $date,
            'scheduled_time' => $time,
            'remarks' => $name.' remarks.',
        ];
    }

    private function bankActivity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $this->bankDefinition()->id,
            'name' => 'Bank / Coop Check',
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createTarget(CiActivity $activity, User $actor, string $name, ActivityStatus $status): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'institution_name' => $name,
            'status' => $status,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function bankDefinition(): ActivityDefinition
    {
        return ActivityDefinition::query()->updateOrCreate(
            ['code' => ActivityDefinition::BANK_COOP_CHECK_CODE],
            ['name' => 'Bank / Coop Check', 'sort_order' => 35, 'is_required' => false, 'is_active' => true],
        );
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $name, 2);

        return CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }
}
