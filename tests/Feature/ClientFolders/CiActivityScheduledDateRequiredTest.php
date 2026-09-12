<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CiActivityScheduledDateRequiredTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Please select a scheduled date.';

    private const HELP = 'Date is required for Scheduled and For Follow-up activities. Time is optional.';

    private const OLD_HELP = 'Date and time are optional. Select a date to enable a specific time.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_default_check_requires_a_date_when_scheduled_or_for_follow_up_and_time_stays_optional(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $url = route('client-folders.activities.update', [$folder, $activity]);

        $this->actingAs($ci)->putJson($url, $this->payload(ActivityStatus::Scheduled, null, null, 'Try without a date.'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE])
            ->assertJsonMissingValidationErrors('scheduled_time');
        $activity->refresh();
        $this->assertSame(ActivityStatus::Pending, $activity->status);
        $this->assertNull($activity->remarks);

        $this->putJson($url, $this->payload(ActivityStatus::Scheduled, '2026-09-15'))->assertOk();
        $activity->refresh();
        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
        $this->assertTrue($activity->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-15 08:00', 'Asia/Manila')->utc()));
        $this->assertFalse($activity->scheduled_has_time);

        $this->putJson($url, array_replace($this->payload(ActivityStatus::Scheduled, '2026-09-15', '09:30'), [
            'expected_updated_at' => $activity->updated_at->toISOString(),
        ]))->assertOk();
        $activity->refresh();
        $this->assertTrue($activity->scheduled_at->equalTo(Carbon::createFromFormat('!Y-m-d H:i', '2026-09-15 09:30', 'Asia/Manila')->utc()));
        $this->assertTrue($activity->scheduled_has_time);

        $this->putJson($url, $this->payload(ActivityStatus::FollowUp))->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);

        $this->putJson($url, $this->payload(ActivityStatus::FollowUp, '2026-09-16'))->assertOk();
        $this->assertSame(ActivityStatus::FollowUp, $activity->fresh()->status);
        $this->assertFalse($activity->fresh()->scheduled_has_time);

        foreach ([ActivityStatus::Pending, ActivityStatus::Completed] as $status) {
            $this->putJson($url, $this->payload($status))->assertOk();
            $this->assertSame($status, $activity->fresh()->status);
            $this->assertNull($activity->fresh()->scheduled_at);
        }
    }

    public function test_co_maker_default_check_requires_a_date_and_stays_isolated_from_other_people(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $applicant = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $forA = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $makerA->id);
        $forB = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $makerB->id);
        $urlA = route('client-folders.activities.update', [$folder, $forA]);

        $this->actingAs($ci)->putJson($urlA, array_replace($this->payload(ActivityStatus::FollowUp), ['co_maker_id' => $makerA->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->assertSame(ActivityStatus::Pending, $forA->fresh()->status);

        // A dated save aimed at Maker A's activity under Maker B's context is still refused.
        $this->putJson($urlA, array_replace($this->payload(ActivityStatus::FollowUp, '2026-09-15'), ['co_maker_id' => $makerB->id]))
            ->assertForbidden();
        $this->assertSame(ActivityStatus::Pending, $forA->fresh()->status);

        $this->putJson($urlA, array_replace($this->payload(ActivityStatus::FollowUp, '2026-09-15'), ['co_maker_id' => $makerA->id]))
            ->assertOk();

        $this->assertSame(ActivityStatus::FollowUp, $forA->fresh()->status);
        $this->assertNotNull($forA->fresh()->scheduled_at);
        foreach ([$applicant, $forB] as $untouched) {
            $untouched->refresh();
            $this->assertSame(ActivityStatus::Pending, $untouched->status);
            $this->assertNull($untouched->scheduled_at);
        }
    }

    public function test_historical_scheduled_activity_without_a_date_is_kept_as_is_but_needs_a_date_to_be_saved(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $activity->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => null, 'scheduled_has_time' => false]);

        $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $activity]))->assertOk();
        $this->assertNull($activity->fresh()->scheduled_at);

        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled, null, null, 'Remarks only.'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $activity->refresh();
        $this->assertSame(ActivityStatus::Scheduled, $activity->status);
        $this->assertNull($activity->scheduled_at);
        $this->assertNull($activity->remarks);
    }

    public function test_custom_activity_create_and_update_require_a_date_when_scheduled(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'create_new_activity_type' => true,
            'new_activity_type' => 'Employment Verification',
            'status' => ActivityStatus::Pending->value,
        ])->assertSessionHasNoErrors();
        $definition = ActivityDefinition::query()->where('name', 'Employment Verification')->sole();
        $store = fn (array $schedule) => $this->from(route('client-folders.activities.index', $folder))
            ->post(route('client-folders.activities.store', $folder), [
                'activity_definition_id' => $definition->id,
                'create_new_activity_type' => false,
                'status' => ActivityStatus::Scheduled->value,
            ] + $schedule);

        $store(['scheduled_at' => '', 'scheduled_time' => ''])->assertSessionHasErrors(['scheduled_at' => self::MESSAGE]);
        $this->assertSame(0, $folder->activities()->count());

        $store(['scheduled_at' => '2026-09-15', 'scheduled_time' => ''])->assertSessionHasNoErrors();
        $activity = $folder->activities()->sole();
        $this->assertFalse($activity->scheduled_has_time);

        $this->putJson(route('client-folders.activities.update', [$folder, $activity]), $this->payload(ActivityStatus::Scheduled))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->assertNotNull($activity->fresh()->scheduled_at);
    }

    public function test_bank_and_asset_target_updates_require_a_date_when_scheduled_or_for_follow_up(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $bankTarget = $bank->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);
        $assetTarget = $asset->assetTargets()->create([
            'assessor_type' => 'city_assessor',
            'office_location' => 'Cagayan de Oro',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);
        $otherBankTarget = $bank->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            'institution_name' => 'Untouched Loan Inquiry',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);
        $otherAssetTarget = $asset->assetTargets()->create([
            'assessor_type' => 'provincial_assessor',
            'office_location' => 'Untouched Provincial Office',
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);
        $bankPayload = ['co_maker_id' => '', 'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => 'BDO', 'status' => ActivityStatus::Scheduled->value, 'scheduled_at' => '', 'scheduled_time' => ''];
        $assetPayload = ['co_maker_id' => '', 'assessor_type' => 'city_assessor', 'office_location' => 'Cagayan de Oro', 'status' => ActivityStatus::Scheduled->value, 'scheduled_at' => '', 'scheduled_time' => ''];

        $this->actingAs($ci)->putJson(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankTarget]), $bankPayload)
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->putJson(route('client-folders.activities.asset-targets.update', [$folder, $asset, $assetTarget]), $assetPayload)
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->assertSame(ActivityStatus::Pending, $bankTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $assetTarget->fresh()->status);

        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankTarget]), ['scheduled_at' => '2026-09-15'] + $bankPayload)->assertSessionHasNoErrors();
        $this->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $assetTarget]), ['scheduled_at' => '2026-09-15', 'scheduled_time' => '09:30'] + $assetPayload)->assertSessionHasNoErrors();
        $this->assertSame(ActivityStatus::Scheduled, $bankTarget->fresh()->status);
        $this->assertFalse($bankTarget->fresh()->scheduled_has_time);
        $this->assertSame(ActivityStatus::Scheduled, $assetTarget->fresh()->status);
        $this->assertTrue($assetTarget->fresh()->scheduled_has_time);

        $followUpBankPayload = ['status' => ActivityStatus::FollowUp->value] + $bankPayload;
        $followUpAssetPayload = ['status' => ActivityStatus::FollowUp->value] + $assetPayload;
        $this->putJson(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankTarget]), $followUpBankPayload)
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->putJson(route('client-folders.activities.asset-targets.update', [$folder, $asset, $assetTarget]), $followUpAssetPayload)
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at' => self::MESSAGE]);
        $this->assertSame(ActivityStatus::Scheduled, $bankTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Scheduled, $assetTarget->fresh()->status);

        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankTarget]), ['scheduled_at' => '2026-09-16'] + $followUpBankPayload)->assertSessionHasNoErrors();
        $this->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $assetTarget]), ['scheduled_at' => '2026-09-16'] + $followUpAssetPayload)->assertSessionHasNoErrors();
        $this->assertSame(ActivityStatus::FollowUp, $bankTarget->fresh()->status);
        $this->assertFalse($bankTarget->fresh()->scheduled_has_time);
        $this->assertSame(ActivityStatus::FollowUp, $assetTarget->fresh()->status);
        $this->assertFalse($assetTarget->fresh()->scheduled_has_time);
        $this->assertSame(ActivityStatus::FollowUp, $bank->fresh()->status);
        $this->assertSame(ActivityStatus::FollowUp, $asset->fresh()->status);

        $this->put(route('client-folders.activities.bank-targets.update', [$folder, $bank, $bankTarget]), ['status' => ActivityStatus::Pending->value] + $bankPayload)->assertSessionHasNoErrors();
        $this->put(route('client-folders.activities.asset-targets.update', [$folder, $asset, $assetTarget]), ['status' => ActivityStatus::Pending->value] + $assetPayload)->assertSessionHasNoErrors();
        $this->assertSame(ActivityStatus::Pending, $bankTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $assetTarget->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $bank->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $asset->fresh()->status);
        $this->assertSame(CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY, $otherBankTarget->fresh()->inquiry_type);
        $this->assertSame('Untouched Loan Inquiry', $otherBankTarget->fresh()->institution_name);
        $this->assertSame(ActivityStatus::Pending, $otherBankTarget->fresh()->status);
        $this->assertSame('Untouched Provincial Office', $otherAssetTarget->fresh()->office_location);
        $this->assertSame(ActivityStatus::Pending, $otherAssetTarget->fresh()->status);
    }

    public function test_schedule_forms_show_the_required_wording_and_live_required_indicator(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $scheduled = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $scheduled->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
        $pending = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);
        $maker = $this->coMaker($folder, 'Follow Up Maker');
        $followUp = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $maker->id);
        $followUp->update(['status' => ActivityStatus::FollowUp, 'scheduled_at' => now()->addDays(2)]);

        $scheduledPage = $this->actingAs($ci)->get(route('client-folders.activities.default-check.show', [$folder, $scheduled]));
        $scheduledPage->assertOk()->assertSee(self::HELP)->assertDontSee(self::OLD_HELP)
            ->assertSee('data-schedule-status', false)
            ->assertSee('data-schedule-date', false);
        $this->assertMatchesRegularExpression('/data-schedule-date-optional\s+hidden\s*>\(optional\)/', $scheduledPage->getContent());
        $this->assertMatchesRegularExpression('/data-schedule-date-required\s*><span class="text-danger" aria-hidden="true">\*<\/span>/', $scheduledPage->getContent());

        $followUpPage = $this->get(route('client-folders.activities.default-check.show', [$folder, $followUp, 'person' => 'co-maker', 'co_maker_id' => $maker->id]));
        $followUpPage->assertOk()->assertSee(self::HELP)->assertSee('name="scheduled_at"', false)->assertSee('required', false);
        $this->assertMatchesRegularExpression('/data-schedule-date-required\s*><span class="text-danger" aria-hidden="true">\*<\/span>/', $followUpPage->getContent());

        $pendingPage = $this->get(route('client-folders.activities.default-check.show', [$folder, $pending]));
        $pendingPage->assertOk()->assertSee(self::HELP);
        $this->assertMatchesRegularExpression('/data-schedule-date-optional\s*>\(optional\)/', $pendingPage->getContent());
        $this->assertMatchesRegularExpression('/data-schedule-date-required\s+hidden\s*>/', $pendingPage->getContent());
        // Time stays visibly optional.
        $this->assertMatchesRegularExpression('/>Time <span class="font-normal text-text-muted">\(optional\)<\/span>/', $pendingPage->getContent());

        $index = $this->get(route('client-folders.activities.index', $folder));
        $index->assertOk()
            ->assertSee(self::HELP)
            ->assertDontSee(self::OLD_HELP)
            ->assertDontSee('Select a date to enable a specific time.')
            ->assertSee(self::MESSAGE)
            ->assertSee('data-schedule-scope', false);
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
