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
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Advisory duplicate warning when adding an Asset Check target.
 *
 * Adding the same assessor + office twice is a WARNING, never a hard block: only an explicit
 * Continue Anyway (allow_duplicate) creates the second row. There is deliberately no unique
 * constraint and no migration — the guarantee comes from re-reading the parent's own targets while
 * the parent CiActivity row is locked inside the create transaction.
 *
 * LIMITATION: these tests prove the LOGICAL race outcome (first create wins, an identical second
 * create warns, nothing is inserted without Continue Anyway), not true simultaneous MySQL/InnoDB
 * lock scheduling. The suite runs on SQLite :memory:, which serializes writes at the connection
 * level and has no meaningful FOR UPDATE, so genuine concurrent interleaving is NOT exercised here.
 */
class CiActivityAssetTargetDuplicateWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->assetDefinition();
    }

    public function test_the_first_target_is_created_and_an_identical_second_add_only_warns(): void
    {
        Notification::fake();
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $this->actingAs($ci)
            ->post($this->storeUrl($folder, $activity), $this->payload([
                'status' => ActivityStatus::Scheduled->value,
                'scheduled_at' => '2026-09-20',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $activity->assetTargets()->count());
        Notification::assertSentToTimes($ci, CiActivityScheduledReminder::class, 1);
        $auditsBefore = AuditLog::query()->count();

        // The accidental second submit: same identity, no Continue Anyway.
        $response = $this->post($this->storeUrl($folder, $activity), $this->payload([
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => '2026-09-20',
        ]))->assertRedirect();

        $response->assertSessionHas('statusType', 'warning');
        $response->assertSessionHas('asset_target_duplicate');
        $this->assertStringContainsString(
            'same Assessor Type and Office / Location already exists',
            session('asset_target_duplicate'),
        );

        // Zero insert, zero parent change, zero notification, zero audit.
        $this->assertSame(1, $activity->assetTargets()->count());
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        Notification::assertSentToTimes($ci, CiActivityScheduledReminder::class, 1);
        $this->assertSame($auditsBefore, AuditLog::query()->count());
    }

    public function test_the_warning_survives_casing_and_whitespace_differences(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $this->actingAs($ci)
            ->post($this->storeUrl($folder, $activity), $this->payload(['office_location' => 'City Hall']))
            ->assertRedirect()->assertSessionHasNoErrors();

        foreach ([' city hall ', 'CITY   HALL', 'city   Hall'] as $variation) {
            $this->post($this->storeUrl($folder, $activity), $this->payload(['office_location' => $variation]))
                ->assertRedirect()
                ->assertSessionHas('asset_target_duplicate');
        }

        $this->assertSame(1, $activity->assetTargets()->count());
        // The stored value keeps the CI's own capitalisation, merely tidied.
        $this->assertSame('City Hall', $activity->assetTargets()->sole()->office_location);
    }

    public function test_a_different_assessor_type_or_office_is_added_without_a_warning(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $this->actingAs($ci)
            ->post($this->storeUrl($folder, $activity), $this->payload(['office_location' => 'City Hall']))
            ->assertRedirect()->assertSessionHasNoErrors();

        // Nothing fuzzy: a different assessor type at the same address, and an office that merely
        // starts with the same words, are both distinct targets.
        foreach ([
            ['assessor_type' => 'provincial_assessor', 'office_location' => 'City Hall'],
            ['office_location' => 'City Hall Annex'],
            ['office_location' => 'Provincial Capitol'],
        ] as $variation) {
            $this->post($this->storeUrl($folder, $activity), $this->payload($variation))
                ->assertRedirect()
                ->assertSessionMissing('asset_target_duplicate');
        }

        $this->assertSame(4, $activity->assetTargets()->count());
    }

    public function test_duplicate_detection_never_reaches_another_parent_person_or_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMakerA = $this->coMaker($folder, 'Maker Alpha');
        $coMakerB = $this->coMaker($folder, 'Maker Beta');
        $applicantActivity = $this->assetActivity($folder, $ci);
        $secondApplicantActivity = $this->assetActivity($folder, $ci);
        $coMakerAActivity = $this->assetActivity($folder, $ci, $coMakerA->id);
        $coMakerBActivity = $this->assetActivity($folder, $ci, $coMakerB->id);
        $otherFolderActivity = $this->assetActivity($otherFolder, $ci);

        $this->actingAs($ci)
            ->post($this->storeUrl($folder, $applicantActivity), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        // The same assessor and office under a different parent activity, either Co-Maker, or
        // another folder is a different target entirely — scope is the exact parent activity.
        $this->post($this->storeUrl($folder, $secondApplicantActivity), $this->payload())
            ->assertRedirect()->assertSessionMissing('asset_target_duplicate');
        $this->post($this->storeUrl($folder, $coMakerAActivity), $this->payload(['co_maker_id' => $coMakerA->id]))
            ->assertRedirect()->assertSessionMissing('asset_target_duplicate');
        $this->post($this->storeUrl($folder, $coMakerBActivity), $this->payload(['co_maker_id' => $coMakerB->id]))
            ->assertRedirect()->assertSessionMissing('asset_target_duplicate');
        $this->post($this->storeUrl($otherFolder, $otherFolderActivity), $this->payload())
            ->assertRedirect()->assertSessionMissing('asset_target_duplicate');

        foreach ([$applicantActivity, $secondApplicantActivity, $coMakerAActivity, $coMakerBActivity, $otherFolderActivity] as $parent) {
            $this->assertSame(1, $parent->assetTargets()->count());
        }
    }

    public function test_continue_anyway_creates_exactly_one_duplicate_without_looping(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $this->actingAs($ci)->post($this->storeUrl($folder, $activity), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post($this->storeUrl($folder, $activity), $this->payload())
            ->assertRedirect()->assertSessionHas('asset_target_duplicate');

        // The explicit choice: the same values plus the flag.
        $this->post($this->storeUrl($folder, $activity), $this->payload(['allow_duplicate' => '1']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('asset_target_duplicate');

        $this->assertSame(2, $activity->assetTargets()->where('office_location', 'City Hall')->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'ci_activity.asset_target_created')->count());
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);

        // The first target is untouched by the second create.
        $first = $activity->assetTargets()->oldest('id')->first();
        $this->assertSame(1, $first->revision);
        $this->assertSame(ActivityStatus::Pending, $first->status);
    }

    public function test_the_flag_is_narrow_and_cannot_bypass_validation_or_scope(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $activity = $this->assetActivity($folder, $ci);
        $coMakerActivity = $this->assetActivity($folder, $ci, $coMaker->id);

        $this->actingAs($ci);

        $this->postJson($this->storeUrl($folder, $activity), $this->payload([
            'allow_duplicate' => '1',
            'status' => ActivityStatus::Scheduled->value,
            'scheduled_at' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        $this->postJson($this->storeUrl($folder, $activity), $this->payload(['allow_duplicate' => 'not-a-boolean']))
            ->assertUnprocessable()->assertJsonValidationErrors(['allow_duplicate']);

        // It never crosses person or folder scope.
        $this->post($this->storeUrl($folder, $coMakerActivity), $this->payload(['allow_duplicate' => '1']))
            ->assertForbidden();
        $this->post($this->storeUrl($otherFolder, $activity), $this->payload(['allow_duplicate' => '1']))
            ->assertNotFound();

        $this->assertSame(0, $activity->assetTargets()->count());
        $this->assertSame(0, $coMakerActivity->assetTargets()->count());
    }

    public function test_the_duplicate_warning_returns_409_for_json_and_never_reads_as_a_system_error(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $this->actingAs($ci)->post($this->storeUrl($folder, $activity), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $existingId = $activity->assetTargets()->sole()->id;

        $response = $this->postJson($this->storeUrl($folder, $activity), $this->payload())->assertStatus(409);

        $response->assertJsonPath('result', 'duplicate_exists');
        $response->assertJsonPath('status_type', 'warning');
        $response->assertJsonPath('existing_target_id', $existingId);
        $this->assertStringContainsString('already exists', $response->json('message'));
        $this->assertStringNotContainsString('App\Models', $response->json('message'));
        $this->assertSame(1, $activity->assetTargets()->count());
    }

    public function test_the_tracker_reopens_the_add_dialog_with_the_entered_values_and_continue_anyway(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $this->actingAs($ci)->post($this->storeUrl($folder, $activity), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        // The real lifecycle: POST the duplicate and FOLLOW the redirect, so every assertion runs
        // against the page the CI actually ends up looking at. No session state is hand-seeded.
        $html = $this->followingRedirects()
            ->post($this->storeUrl($folder, $activity), $this->payload([
                'office_location' => ' city   hall ',
                'remarks' => 'Typed remarks kept.',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-asset-target-duplicate-warning', $html);
        $this->assertStringContainsString('already exists', $html);

        // Entered values come back — the CI retypes nothing. (Laravel's app-wide TrimStrings
        // middleware strips the surrounding spaces on the way in, exactly as it does for every
        // other form in the project; the CI's own text is otherwise untouched.)
        $this->assertStringContainsString('value="city   hall"', $html);
        $this->assertStringContainsString('Typed remarks kept.', $html);

        // Continue Anyway replaces Add Assessor as the one primary action in the footer.
        $footer = $this->addDialogFooter($html);
        $this->assertStringContainsString('Cancel', $footer);
        $this->assertStringContainsString('name="allow_duplicate" value="1"', $footer);
        $this->assertStringContainsString('Continue Anyway', $footer);
        $this->assertStringNotContainsString('Add Assessor', $footer);

        // Advisory, not destructive.
        $this->assertStringContainsString('bg-progress-soft', $html);
        $this->assertStringNotContainsString('ui-button-danger', $footer);

        $this->assertSame(1, $activity->assetTargets()->count());
    }

    public function test_the_normal_add_footer_offers_cancel_and_add_without_continue_anyway(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->assetActivity($folder, $ci);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.asset-check.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $footer = $this->addDialogFooter($html);
        $this->assertStringContainsString('Cancel', $footer);
        $this->assertStringContainsString('Add Assessor', $footer);
        $this->assertStringNotContainsString('Continue Anyway', $footer);
        $this->assertStringNotContainsString('allow_duplicate', $footer);
        $this->assertStringNotContainsString('data-asset-target-duplicate-warning', $html);
    }

    public function test_the_embedded_asset_handler_supports_duplicate_state_and_the_submitter_payload(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->assetActivity($folder, $ci);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->getContent();

        // Source-level guards only. PHPUnit renders Blade; it does not run this JavaScript, so the
        // embedded interaction still needs a manual browser check.
        $this->assertStringContainsString("payload.result === 'duplicate_exists'", $html);
        $this->assertStringContainsString('applyAssetDuplicateState', $html);
        $this->assertStringContainsString('event.submitter instanceof HTMLButtonElement', $html);
        $this->assertStringContainsString('requestBody.append(submitter.name, submitter.value)', $html);
        $this->assertStringContainsString("primary.name = 'allow_duplicate'", $html);
        // Duplicate state stays separate from the stale-edit conflict branch.
        $this->assertStringContainsString("payload.result === 'conflict'", $html);
    }

    /** The Add dialog's action bar: the block after the scrolling body, which never scrolls. */
    private function addDialogFooter(string $html): string
    {
        $dialogStart = strpos($html, 'id="add-asset-target"');
        $this->assertNotFalse($dialogStart, 'Expected the Add Assessor dialog to be rendered.');
        $dialogEnd = strpos($html, '</dialog>', $dialogStart);
        $dialog = substr($html, $dialogStart, $dialogEnd - $dialogStart);

        $footerStart = strrpos($dialog, '<div class="flex flex-col-reverse gap-3 border-t');
        $this->assertNotFalse($footerStart, 'Expected a non-scrolling footer in the Add dialog.');

        return substr($dialog, $footerStart);
    }

    private function storeUrl(ClientFolder $folder, CiActivity $activity): string
    {
        return route('client-folders.activities.asset-targets.store', [$folder, $activity]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'assessor_type' => 'city_assessor',
            'office_location' => 'City Hall',
            'status' => ActivityStatus::Pending->value,
            'scheduled_at' => '',
            'scheduled_time' => '',
            'remarks' => null,
        ];
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

    private function assetActivity(ClientFolder $folder, User $creator, ?int $coMakerId = null): CiActivity
    {
        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'activity_definition_id' => $this->assetDefinition()->id,
            'name' => 'Asset Check',
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function assetDefinition(): ActivityDefinition
    {
        return ActivityDefinition::query()->updateOrCreate(
            ['code' => ActivityDefinition::ASSET_CHECK_CODE],
            ['name' => 'Asset Check', 'sort_order' => 36, 'is_required' => false, 'is_active' => true],
        );
    }
}
