<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CiActivityDefaultTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_barangay_and_neighbor_open_trackers_without_redundant_view_or_mandatory_delete_actions(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));

        $page->assertOk()
            ->assertSee('data-default-check-open="'.$barangay->id.'"', false)
            ->assertSee('data-default-check-open="'.$neighbor->id.'"', false)
            ->assertSee('id="default-check-tracker-modal"', false)
            ->assertSee('data-bank-coop-open="'.$bank->id.'"', false)
            ->assertSee('data-asset-check-open="'.$asset->id.'"', false)
            ->assertDontSee('title="View"', false)
            ->assertDontSee('aria-label="View '.$barangay->name.'"', false)
            ->assertDontSee('aria-label="View '.$neighbor->name.'"', false)
            ->assertDontSee('aria-label="View '.$bank->name.'"', false)
            ->assertDontSee('aria-label="View '.$asset->name.'"', false)
            ->assertDontSee('id="delete-activity-'.$barangay->id.'"', false)
            ->assertDontSee('id="delete-activity-'.$neighbor->id.'"', false)
            ->assertSee('id="delete-activity-'.$bank->id.'"', false)
            ->assertSee('id="delete-activity-'.$asset->id.'"', false);

        foreach ([$barangay, $neighbor] as $default) {
            $tracker = $this->get(route('client-folders.activities.default-check.show', [$folder, $default]));
            $tracker->assertOk()
                ->assertSee('data-default-check-modal-source', false)
                ->assertSee('data-default-check-activity-id="'.$default->id.'"', false)
                ->assertSee('Applicant: '.$folder->display_name)
                ->assertSee($default->name)
                ->assertSee('Schedule / Follow-up Date')
                ->assertSee('Short Remarks')
                ->assertDontSee('Updated By')
                ->assertDontSee('Creator')
                ->assertDontSee('Last Updated')
                ->assertSee('Mark '.$default->name.' as completed?');
        }
    }

    public function test_four_core_check_types_show_saved_remarks_in_compact_rows_and_full_edit_details(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $longRemarks = str_repeat('Long barangay verification remarks ', 15).'END';
        $neighborRemarks = 'Neighbor confirmed the applicant residence details.';
        $bankRemarks = 'Bank target remarks remain attached to this institution.';
        $assetRemarks = 'Asset remarks remain attached to this assessor office.';

        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $barangay]), $this->payload(ActivityStatus::Pending, remarks: $longRemarks))->assertRedirect();
        $this->put(route('client-folders.activities.update', [$folder, $neighbor]), $this->payload(ActivityStatus::Pending, remarks: $neighborRemarks))->assertRedirect();
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $bank]), [
            'co_maker_id' => null,
            'inquiry_type' => 'bank_coop_check',
            'institution_name' => 'Remarks Test Bank',
            'status' => 'pending',
            'remarks' => $bankRemarks,
        ])->assertRedirect();
        $this->post(route('client-folders.activities.asset-targets.store', [$folder, $asset]), [
            'co_maker_id' => null,
            'assessor_type' => 'city_assessor',
            'office_location' => 'Remarks Test City',
            'status' => 'pending',
            'remarks' => $assetRemarks,
        ])->assertRedirect();

        $page = $this->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))->assertOk();
        $html = $page->getContent();
        $activityRow = static function (CiActivity $activity) use ($html): string {
            $start = strpos($html, 'id="activity-'.$activity->id.'"');
            $end = strpos($html, '</tr>', $start);

            return substr($html, $start, $end + 5 - $start);
        };

        foreach ([
            $barangay->id => $longRemarks,
            $neighbor->id => $neighborRemarks,
            $bank->id => $bankRemarks,
            $asset->id => $assetRemarks,
        ] as $activityId => $remarks) {
            $row = $activityRow(CiActivity::findOrFail($activityId));
            $this->assertStringContainsString('data-ci-activity-remarks-preview="'.$activityId.'"', $row);
            $this->assertStringContainsString('max-w-52 truncate', $row);
            $this->assertStringContainsString('Remarks:', $row);
            $this->assertStringContainsString(e($remarks), $row);
        }

        foreach ([
            [$this->get(route('client-folders.activities.default-check.show', [$folder, $barangay]))->assertOk(), $longRemarks],
            [$this->get(route('client-folders.activities.default-check.show', [$folder, $neighbor]))->assertOk(), $neighborRemarks],
            [$this->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk(), $bankRemarks],
            [$this->get(route('client-folders.activities.asset-check.show', [$folder, $asset]))->assertOk(), $assetRemarks],
        ] as [$response, $remarks]) {
            $this->assertMatchesRegularExpression('/<textarea[^>]*name="remarks"[^>]*>'.preg_quote(e($remarks), '/').'<\/textarea>/', $response->getContent());
        }

        $this->assertStringContainsString('syncCiActivityRemarksPreview(activityId, source.dataset.bankCoopRemarksPreview', $html);
        $this->assertStringContainsString('syncCiActivityRemarksPreview(activityId, source.dataset.assetRemarksPreview', $html);
        $this->assertStringContainsString('syncCiActivityRemarksPreview(activityId, source.dataset.defaultCheckRemarks', $html);

        $this->assertSame(ActivityStatus::Pending, $barangay->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $neighbor->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $bank->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $asset->fresh()->status);
    }

    public function test_blank_remarks_stay_hidden_and_remarks_remain_person_scoped(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $makerAActivity = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $makerA->id);
        $makerBActivity = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $makerB->id);

        $applicant->update(['remarks' => null]);
        $makerAActivity->update(['remarks' => 'MAKER A REMARKS']);
        $makerBActivity->update(['remarks' => 'MAKER B REMARKS']);

        $applicantPage = $this->actingAs($ci)->get(route('client-folders.activities.index', [$folder, 'status' => 'all']))->assertOk();
        $applicantHtml = $applicantPage->getContent();
        $coreCodes = [
            ActivityDefinition::BARANGAY_CHECK_CODE,
            ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ActivityDefinition::ASSET_CHECK_CODE,
            ActivityDefinition::BANK_COOP_CHECK_CODE,
        ];
        foreach ($applicantPage->viewData('activities')->filter(fn (CiActivity $activity) => in_array($activity->definition->code, $coreCodes, true)) as $blankActivity) {
            $rowStart = strpos($applicantHtml, 'id="activity-'.$blankActivity->id.'"');
            $row = substr($applicantHtml, $rowStart, strpos($applicantHtml, '</tr>', $rowStart) + 5 - $rowStart);
            $this->assertMatchesRegularExpression('/data-ci-activity-remarks-preview="'.$blankActivity->id.'"[^>]*hidden/', $row);
            $this->assertStringNotContainsString('N/A', $row);
        }
        $this->assertStringNotContainsString('No remarks yet', $applicantHtml);
        $this->assertStringNotContainsString('MAKER A REMARKS', $applicantHtml);
        $this->assertStringNotContainsString('MAKER B REMARKS', $applicantHtml);

        $makerAHtml = $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $makerA->id, 'status' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('MAKER A REMARKS', $makerAHtml);
        $this->assertStringNotContainsString('MAKER B REMARKS', $makerAHtml);

        $makerBHtml = $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $makerB->id, 'status' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('MAKER B REMARKS', $makerBHtml);
        $this->assertStringNotContainsString('MAKER A REMARKS', $makerBHtml);
    }

    public function test_default_check_tracker_removes_redundant_footer_close_button_and_keeps_header_close(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        foreach ([$barangay, $neighbor] as $default) {
            $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $default]))
                ->assertOk()
                ->assertDontSee('data-default-check-close', false)
                ->assertSee('data-default-check-submit', false)
                ->assertSee('Save Changes')
                ->assertSee('data-default-check-success', false);
        }

        $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('data-default-check-modal-close', false)
            ->assertSee('aria-label="Close activity tracker"', false);
    }

    public function test_default_check_tracker_renders_cancel_button_no_changes_message_and_discard_confirmation(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        foreach ([$barangay, $neighbor] as $default) {
            $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $default]))
                ->assertOk()
                ->assertSee('data-default-check-cancel', false)
                ->assertSee('>Cancel<', false)
                ->assertSee('data-default-check-no-changes', false)
                ->assertSee('No changes detected. Nothing needs to be updated.')
                ->assertDontSee('No changes to save.')
                ->assertSee('data-default-check-discard-confirm', false)
                ->assertSee('Discard unsaved changes?')
                ->assertSee('Your changes have not been saved.')
                ->assertSee('data-default-check-discard-keep', false)
                ->assertSee('Keep Editing')
                ->assertSee('data-default-check-discard-confirm-button', false)
                ->assertSee('Discard Changes')
                ->assertDontSee('data-default-check-close', false);
        }
    }

    public function test_default_check_save_flow_js_skips_the_save_request_when_nothing_changed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->getContent();
        $page->assertOk()
            ->assertSee('const readValues = () => ({', false)
            ->assertSee('const isDirty = () => {', false)
            ->assertSee('currentIsDirty = isDirty;', false)
            ->assertSee('noChanges.hidden = false;', false)
            ->assertSee('baseline = readValues();', false);

        $blockStart = strpos($content, 'const bindSource = (source) => {');
        $blockEnd = strpos($content, 'const loadDetail = async () => {', $blockStart);
        $this->assertNotFalse($blockStart);
        $this->assertNotFalse($blockEnd);
        $block = substr($content, $blockStart, $blockEnd - $blockStart);

        $noChangesGuardPosition = strpos($block, 'if (! isDirty()) {');
        $fetchSavePosition = strpos($block, 'fetch(form.action, {');
        $this->assertNotFalse($noChangesGuardPosition);
        $this->assertNotFalse($fetchSavePosition);
        $this->assertLessThan(
            $fetchSavePosition,
            $noChangesGuardPosition,
            'The no-changes guard must be checked before the save request is issued.',
        );

        $baselineResetPosition = strpos($block, 'baseline = readValues();', $noChangesGuardPosition);
        $synchronizeCallPosition = strpos($block, 'synchronizeTable(freshSource);');
        $this->assertNotFalse($baselineResetPosition);
        $this->assertNotFalse($synchronizeCallPosition);
        $this->assertGreaterThan(
            $synchronizeCallPosition,
            $baselineResetPosition,
            'The baseline must only be reset to the newly saved values after a real save completes.',
        );
    }

    public function test_default_check_cancel_and_close_controls_share_the_same_dirty_guard(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()
            ->assertSee('const requestClose = () => {', false)
            ->assertSee("data-default-check-cancel]')?.addEventListener('click', () => requestClose());", false)
            ->assertSee("button.addEventListener('click', () => requestClose())", false)
            ->assertSee('if (event.target === modal) requestClose();', false)
            ->assertSee("modal.addEventListener('cancel', (event) => {", false)
            ->assertSee('discard.showModal();', false)
            ->assertSee('discard?.close();', false)
            ->assertSee('modal.close();', false);
    }

    public function test_default_check_save_flow_uses_a_lightweight_sync_instead_of_reloading_the_whole_modal_body(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()
            ->assertSee("submit.textContent = 'Saving Changes…';", false)
            ->assertSee("form.dataset.submitting === 'true'", false)
            ->assertSee('synchronizeTable(freshSource);', false);

        preg_match(
            "/form\.addEventListener\('submit', async \(event\) => \{(.*?)\n\s{16}\}\);/s",
            $page->getContent(),
            $submitHandler,
        );
        $this->assertNotEmpty($submitHandler[1] ?? '', 'Expected to locate the default-check tracker submit handler.');
        $this->assertStringNotContainsString('body.innerHTML = loadingMarkup', $submitHandler[1]);
        $this->assertStringNotContainsString('body.replaceChildren(', $submitHandler[1]);
        $this->assertStringNotContainsString('await loadDetail();', $submitHandler[1]);
    }

    public function test_default_tracker_status_and_optional_schedule_rules_use_timezone_and_reject_time_only(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $activity->update(['scheduled_at' => now()->addDay(), 'scheduled_has_time' => true]);
        $this->actingAs($ci)->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Pending, null, null, 'Pending remarks.'))->assertRedirect();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);

        // Scheduled requires a date — it is rejected as a whole, so the status never lands as Scheduled.
        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => 'Please select a scheduled date.']);
        $activity->refresh();
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled, '2026-09-05'))->assertRedirect();
        $activity->refresh();
        $this->assertTrue($activity->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-05 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled, '2026-09-06', '14:30'))->assertRedirect();
        $activity->refresh();
        $this->assertTrue($activity->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-06 14:30', 'Asia/Manila')->utc()));
        $this->assertTrue($activity->scheduled_has_time);

        $followUpBaseline = $activity->scheduled_at->copy();
        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::FollowUp))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => 'Please select a scheduled date.']);
        $activity->refresh();
        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
        $this->assertTrue($activity->scheduled_at->equalTo($followUpBaseline));
        $this->assertTrue($activity->scheduled_has_time);

        $this->put(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::FollowUp, '2026-09-07', '09:15'))->assertRedirect();
        $activity->refresh();
        $baseline = $activity->scheduled_at->copy();
        $this->assertTrue($activity->scheduled_has_time);

        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::FollowUp, null, '10:45'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_time');
        $this->assertTrue($activity->fresh()->scheduled_at->equalTo($baseline));
    }

    public function test_confirmed_default_completion_clears_schedule_and_records_actual_actor_audit_and_creator(): void
    {
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $folder = $this->folderFor($creator);
        $activity = $this->activity($folder, $creator, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $activity->update([
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
            'reminder_sent_at' => now(),
        ]);

        $this->actingAs($updater)->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(
            ActivityStatus::Completed,
            '2026-09-08',
            '15:00',
            'Completed after confirmation.',
        ))->assertOk()->assertJson(['updated' => true]);

        $activity->refresh();
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertFalse($activity->scheduled_has_time);
        $this->assertNull($activity->reminder_sent_at);
        $this->assertNotNull($activity->completed_at);
        $this->assertSame($creator->id, $activity->creator_id);
        $this->assertSame($updater->id, $activity->updated_by);
        $this->assertSame('Completed after confirmation.', $activity->remarks);

        $audit = AuditLog::query()->where('action', 'ci_activity.completed')->sole();
        $this->assertSame($updater->id, $audit->user_id);
        $this->assertSame($folder->id, $audit->client_folder_id);
        $this->assertSame($activity->id, (int) data_get($audit->metadata, 'activity_id'));
        $this->assertSame(ActivityStatus::Completed->value, data_get($audit->metadata, 'status'));

        $this->actingAs($updater)->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Neighbor Check completed')
            ->assertSee($updater->full_name);
    }

    public function test_default_tracker_and_updates_enforce_exact_applicant_co_maker_and_folder_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $makerActivity = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $makerA->id);

        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $applicant]))->assertOk();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $applicant, 'person' => 'co-maker', 'co_maker_id' => $makerA->id]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $makerActivity]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$folder, $makerActivity, 'person' => 'co-maker', 'co_maker_id' => $makerA->id]))
            ->assertOk()->assertSee('Co-Maker: '.$makerA->full_name);
        $this->get(route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $makerA->id, 'status' => 'all']))
            ->assertOk()
            ->assertSee('aria-label="Edit '.$makerActivity->display_name.'"', false)
            ->assertSee('data-default-check-url="'.e(route('client-folders.activities.default-check.show', [$folder, $makerActivity, 'person' => 'co-maker', 'co_maker_id' => $makerA->id])).'"', false);
        $this->get(route('client-folders.activities.default-check.show', [$folder, $makerActivity, 'person' => 'co-maker', 'co_maker_id' => $makerB->id]))->assertNotFound();
        $this->get(route('client-folders.activities.default-check.show', [$otherFolder, $applicant]))->assertNotFound();

        $this->put(route('client-folders.activities.update', [$folder, $applicant]), array_replace($this->payload(ActivityStatus::Completed), ['co_maker_id' => $makerA->id]))->assertForbidden();
        $this->put(route('client-folders.activities.update', [$folder, $makerActivity]), $this->payload(ActivityStatus::Completed))->assertForbidden();
        $this->put(route('client-folders.activities.update', [$folder, $makerActivity]), array_replace($this->payload(ActivityStatus::Completed), ['co_maker_id' => $makerB->id]))->assertForbidden();
        $this->put(route('client-folders.activities.update', [$otherFolder, $applicant]), $this->payload(ActivityStatus::Completed))->assertNotFound();
        $this->assertSame(ActivityStatus::Pending, $applicant->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $makerActivity->fresh()->status);
    }

    private function payload(ActivityStatus $status, ?string $date = null, ?string $time = null, ?string $remarks = null): array
    {
        return [
            'co_maker_id' => null,
            'status' => $status->value,
            'scheduled_at' => $date,
            'scheduled_time' => $time,
            'remarks' => $remarks,
        ];
    }

    private function activity(ClientFolder $folder, User $creator, string $code, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
        ]);
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
