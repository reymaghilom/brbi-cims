<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Two narrow concerns:
 *
 *  - the "Available from CIBI Report" panel wording and what a candidate button still carries;
 *  - a Bank / Coop target that cannot be resolved never showing Laravel's technical text.
 *
 * The second is deliberately generic. A missing target covers deletion, a stale page, a target
 * under another parent activity, an Applicant/Co-Maker mismatch, a cross-folder substitution and a
 * forged id — all keep the same 404 and the same wording, so the message can never imply that a
 * forged request found something real.
 */
class CiActivityBankTargetUnavailableAndCibiPanelTest extends TestCase
{
    use RefreshDatabase;

    private const UNAVAILABLE = 'This Bank / Coop target is no longer available. It may have been deleted or changed by another user. Please return to the Bank / Coop Check page.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->bankDefinition();
    }

    public function test_the_cibi_panel_shows_the_new_heading_helper_text_and_inquiry_typed_candidates(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $this->cibiBankAccount($folder, $ci, 'FICCO');

        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Available from CIBI Report', $html);
        $this->assertStringContainsString('These institutions are in this person’s CIBI report but are not currently added to this Bank / Coop Check.', $html);
        $this->assertStringContainsString('If a tracker record is deleted, the CIBI record remains and becomes available here again.', $html);

        // The Inquiry Type stays part of the candidate label — never bare "FICCO".
        $this->assertStringContainsString('data-bank-target-prefill', $html);
        $this->assertMatchesRegularExpression('/(Loan Inquiry|Bank \/ Coop Check)\s*(&middot;|·)\s*FICCO/u', $html);

        // Autofill data is unchanged: the button still carries all three fields.
        $this->assertStringContainsString('data-institution="FICCO"', $html);
        $this->assertStringContainsString('data-inquiry-type=', $html);
        $this->assertStringContainsString('data-branch=', $html);
    }

    public function test_the_cibi_panel_is_hidden_when_no_candidate_is_available(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        // No CIBI report at all.
        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Available from CIBI Report', $html);
        $this->assertStringNotContainsString('data-bank-target-prefill-list', $html);
    }

    public function test_an_unresolvable_bank_target_never_exposes_the_model_or_id_and_stays_a_404(): void
    {
        Notification::fake();
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO', ActivityStatus::Pending);
        $targetId = $target->id;

        $this->actingAs($ci)
            ->delete(route('client-folders.activities.bank-targets.destroy', [$folder, $activity, $target]), ['co_maker_id' => ''])
            ->assertRedirect();
        $auditsBefore = AuditLog::query()->count();

        // A stale page saving the now-deleted target.
        $response = $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $targetId]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'Stale Save']),
        )->assertNotFound();

        $body = $response->getContent();
        $this->assertStringContainsString(self::UNAVAILABLE, $body);
        $this->assertStringNotContainsString('No query results for model', $body);
        $this->assertStringNotContainsString('CiActivityBankTarget', $body);
        $this->assertStringNotContainsString('ModelNotFoundException', $body);

        // Nothing recreated, nothing changed, no side effect of any kind.
        $this->assertDatabaseMissing('ci_activity_bank_targets', ['id' => $targetId]);
        $this->assertDatabaseMissing('ci_activity_bank_targets', ['institution_name' => 'Stale Save']);
        $this->assertSame(0, $activity->bankTargets()->count());
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->assertSame($auditsBefore, AuditLog::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_same_friendly_answer_covers_every_unresolvable_case_including_forged_and_cross_scope(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $activity = $this->bankActivity($folder, $ci);
        $otherActivity = $this->bankActivity($folder, $ci);
        $coMakerActivity = $this->bankActivity($folder, $ci, $coMaker->id);
        $otherFolderActivity = $this->bankActivity($otherFolder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO', ActivityStatus::Pending);

        $payload = $this->payload(['expected_revision' => 1, 'institution_name' => 'Substitution']);

        $cases = [
            // Forged id that never existed.
            [$folder, $activity, 999999, $payload],
            // Cross-parent substitution.
            [$folder, $otherActivity, $target->id, $payload],
            // Applicant target pushed at a Co-Maker activity.
            [$folder, $coMakerActivity, $target->id, ['co_maker_id' => $coMaker->id] + $payload],
            // Cross-folder substitution.
            [$otherFolder, $otherFolderActivity, $target->id, $payload],
        ];

        $this->actingAs($ci);
        foreach ($cases as [$caseFolder, $caseActivity, $caseTargetId, $casePayload]) {
            $response = $this->put(
                route('client-folders.activities.bank-targets.update', [$caseFolder, $caseActivity, $caseTargetId]),
                $casePayload,
            )->assertNotFound();

            $body = $response->getContent();
            $this->assertStringNotContainsString('No query results for model', $body);
            $this->assertStringNotContainsString('CiActivityBankTarget', $body);
        }

        // The real record is untouched by every one of them.
        $this->assertSame('BDO', $target->fresh()->institution_name);
        $this->assertSame(1, $target->fresh()->revision);
        $this->assertSame(0, $otherActivity->bankTargets()->count());
        $this->assertSame(0, $coMakerActivity->bankTargets()->count());
        $this->assertSame(0, $otherFolderActivity->bankTargets()->count());
    }

    public function test_json_requests_get_a_404_not_available_payload(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $response = $this->actingAs($ci)->putJson(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, 999999]),
            $this->payload(['expected_revision' => 1]),
        )->assertStatus(404);

        $response->assertJsonPath('result', 'not_available');
        $response->assertJsonPath('status_type', 'error');
        $response->assertJsonPath('message', self::UNAVAILABLE);
    }

    public function test_the_409_stale_revision_conflict_is_untouched_and_stays_distinct_from_404(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO', ActivityStatus::Pending);

        $this->actingAs($ci)->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'BDO Carmen']),
        )->assertRedirect();

        // Still exists, merely stale: 409 with the conflict wording, not the 404 wording.
        $response = $this->putJson(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'Stale']),
        )->assertStatus(409);

        $response->assertJsonPath('result', 'conflict');
        $this->assertStringContainsString('updated by another user while you were working on it', $response->json('message'));
        $this->assertStringNotContainsString('no longer available', $response->json('message'));
        $this->assertSame('BDO Carmen', $target->fresh()->institution_name);
    }

    public function test_a_missing_model_of_another_type_keeps_laravels_own_handling(): void
    {
        $ci = User::factory()->create();

        // A missing ClientFolder is not a Bank / Coop target: it must not borrow this wording. It has
        // its own folder-specific handling (back to Client Folders with a friendly notice).
        $this->actingAs($ci)
            ->get(route('client-folders.activities.index', 999999))
            ->assertRedirect(route('client-folders.index'));
        $response = $this->actingAs($ci)->followingRedirects()->get(route('client-folders.activities.index', 999999))->assertOk();

        $this->assertStringNotContainsString(self::UNAVAILABLE, $response->getContent());
        $this->assertStringNotContainsString('data-bank-target-unavailable', $response->getContent());
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'branch_location' => null,
            'status' => ActivityStatus::Pending->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => null,
        ];
    }

    private function cibiBankAccount(ClientFolder $folder, User $ci, string $institution): void
    {
        $report = CibiReport::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'ci_in_charge_id' => $ci->id,
            'created_by' => $ci->id,
            'last_edited_by' => $ci->id,
            'revision' => 1,
            'state' => 'complete',
        ]);

        $report->bankAccounts()->create([
            'institution' => $institution,
            'branch' => 'Carmen Branch',
            'sort_order' => 1,
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
        return tap($activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $name,
            'status' => $status,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]))->refresh();
    }

    private function bankDefinition(): ActivityDefinition
    {
        return ActivityDefinition::query()->updateOrCreate(
            ['code' => ActivityDefinition::BANK_COOP_CHECK_CODE],
            ['name' => 'Bank / Coop Check', 'sort_order' => 35, 'is_required' => false, 'is_active' => true],
        );
    }
}
