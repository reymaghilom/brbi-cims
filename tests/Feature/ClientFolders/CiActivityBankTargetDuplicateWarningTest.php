<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Advisory duplicate warning when adding a Bank / Coop target.
 *
 * Adding the same target twice is a WARNING, never a hard block: only an explicit Continue Anyway
 * (allow_duplicate) creates the second row. There is deliberately no unique constraint and no
 * migration — the guarantee comes from re-reading the parent's own targets while the parent
 * CiActivity row is locked inside the create transaction.
 *
 * LIMITATION: these tests prove the LOGICAL race outcome (first create wins, an identical second
 * create warns, nothing is inserted without Continue Anyway), not true simultaneous MySQL/InnoDB
 * lock scheduling. The suite runs on SQLite :memory:, which serializes writes at the connection
 * level and has no meaningful FOR UPDATE, so genuine concurrent interleaving is NOT exercised here.
 */
class CiActivityBankTargetDuplicateWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->bankDefinition();
    }

    public function test_the_first_target_is_created_normally_and_an_identical_second_add_only_warns(): void
    {
        Notification::fake();
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload([
                'status' => ActivityStatus::Scheduled->value,
                'scheduled_at' => '2026-09-20',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $activity->bankTargets()->count());
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        Notification::assertSentToTimes($ci, CiActivityScheduledReminder::class, 1);
        $auditsBefore = AuditLog::query()->count();

        // The accidental second submit: same values, no Continue Anyway.
        $response = $this->post(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $this->payload(['status' => ActivityStatus::Scheduled->value, 'scheduled_at' => '2026-09-20']),
        )->assertRedirect();

        $response->assertSessionHas('statusType', 'warning');
        $response->assertSessionHas('bank_target_duplicate');
        $this->assertStringContainsString(
            'has already been added to this Bank / Coop Check',
            session('bank_target_duplicate'),
        );
        $this->assertStringContainsString('BDO – Carmen Branch', session('bank_target_duplicate'));

        // Zero insert, zero parent change, zero notification, zero audit.
        $this->assertSame(1, $activity->bankTargets()->count());
        $this->assertSame(ActivityStatus::Scheduled, $activity->fresh()->status);
        Notification::assertSentToTimes($ci, CiActivityScheduledReminder::class, 1);
        $this->assertSame($auditsBefore, AuditLog::query()->count());
    }

    public function test_the_warning_survives_casing_and_whitespace_differences(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        foreach ([
            ['institution_name' => 'bdo', 'branch_location' => 'carmen branch'],
            ['institution_name' => '  BDO  ', 'branch_location' => '  Carmen   Branch  '],
            ['institution_name' => 'BDO', 'branch_location' => 'CARMEN BRANCH'],
        ] as $variation) {
            $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload($variation))
                ->assertRedirect()
                ->assertSessionHas('bank_target_duplicate');
        }

        $this->assertSame(1, $activity->bankTargets()->count());
    }

    public function test_a_different_branch_institution_or_inquiry_type_is_added_without_a_warning(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        // Nothing fuzzy: a different branch, a different institution (including one that merely
        // starts with the same word) and a different inquiry type are all distinct targets.
        foreach ([
            ['branch_location' => 'Cogon Branch'],
            ['institution_name' => 'BPI'],
            ['institution_name' => 'BDO Network Bank'],
            ['inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY],
        ] as $variation) {
            $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload($variation))
                ->assertRedirect()
                ->assertSessionMissing('bank_target_duplicate');
        }

        $this->assertSame(5, $activity->bankTargets()->count());
    }

    public function test_loan_inquiry_duplicates_match_on_institution_alone_because_branch_is_null(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $loanInquiry = ['inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY];

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload($loanInquiry))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($activity->bankTargets()->sole()->branch_location);

        // A branch typed on a Loan Inquiry is normalized away, so it cannot dodge the warning.
        $response = $this->post(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $this->payload($loanInquiry + ['branch_location' => 'Some Other Branch']),
        )->assertRedirect();
        $response->assertSessionHas('bank_target_duplicate');

        // The label carries no dangling dash when there is no branch to show.
        $this->assertStringContainsString('BDO has already been added', session('bank_target_duplicate'));
        $this->assertSame(1, $activity->bankTargets()->count());
    }

    public function test_duplicate_detection_never_reaches_another_parent_person_or_folder(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $applicantActivity = $this->bankActivity($folder, $ci);
        $secondApplicantActivity = $this->bankActivity($folder, $ci);
        $coMakerActivity = $this->bankActivity($folder, $ci, $coMaker->id);
        $otherFolderActivity = $this->bankActivity($otherFolder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $applicantActivity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        // The same bank and branch under a different parent activity, a Co-Maker, or another
        // folder is a different target entirely — scoping is by exact parent activity only.
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $secondApplicantActivity]), $this->payload())
            ->assertRedirect()->assertSessionMissing('bank_target_duplicate');
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $coMakerActivity]), $this->payload(['co_maker_id' => $coMaker->id]))
            ->assertRedirect()->assertSessionMissing('bank_target_duplicate');
        $this->post(route('client-folders.activities.bank-targets.store', [$otherFolder, $otherFolderActivity]), $this->payload())
            ->assertRedirect()->assertSessionMissing('bank_target_duplicate');

        $this->assertSame(1, $applicantActivity->bankTargets()->count());
        $this->assertSame(1, $secondApplicantActivity->bankTargets()->count());
        $this->assertSame(1, $coMakerActivity->bankTargets()->count());
        $this->assertSame(1, $otherFolderActivity->bankTargets()->count());
    }

    public function test_continue_anyway_creates_the_duplicate_and_still_enforces_validation_and_scope(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $coMaker = $this->coMaker($folder, 'Maker Alpha');
        $activity = $this->bankActivity($folder, $ci);
        $coMakerActivity = $this->bankActivity($folder, $ci, $coMaker->id);
        $otherFolderActivity = $this->bankActivity($otherFolder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHas('bank_target_duplicate');

        // The explicit choice: the same values plus the flag.
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload(['allow_duplicate' => '1']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('bank_target_duplicate');
        $this->assertSame(2, $activity->bankTargets()->where('institution_name', 'BDO')->count());
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);

        // The flag is narrow: it waives only the duplicate warning.
        $this->postJson(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $this->payload(['allow_duplicate' => '1', 'status' => ActivityStatus::Scheduled->value, 'scheduled_at' => '']),
        )->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        $this->postJson(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $this->payload(['allow_duplicate' => 'not-a-boolean']),
        )->assertUnprocessable()->assertJsonValidationErrors(['allow_duplicate']);

        // It never crosses person or folder scope.
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $coMakerActivity]), $this->payload(['allow_duplicate' => '1']))
            ->assertForbidden();
        $this->post(route('client-folders.activities.bank-targets.store', [$otherFolder, $activity]), $this->payload(['allow_duplicate' => '1']))
            ->assertNotFound();

        $this->assertSame(2, $activity->bankTargets()->count());
        $this->assertSame(0, $coMakerActivity->bankTargets()->count());
        $this->assertSame(0, $otherFolderActivity->bankTargets()->count());
    }

    public function test_the_duplicate_warning_returns_409_for_json_and_never_reads_as_a_system_error(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $existingId = $activity->bankTargets()->sole()->id;

        $response = $this->postJson(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $this->payload(),
        )->assertStatus(409);

        $response->assertJsonPath('result', 'duplicate_exists');
        $response->assertJsonPath('status_type', 'warning');
        $response->assertJsonPath('existing_target_id', $existingId);
        $this->assertStringContainsString('Continue Anyway', $response->json('message'));
        $this->assertStringNotContainsString('App\Models', $response->json('message'));
        $this->assertSame(1, $activity->bankTargets()->count());
    }

    public function test_the_tracker_reopens_the_add_dialog_with_the_entered_values_and_a_continue_anyway_action(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $detailUrl = route('client-folders.activities.bank-coop.show', [$folder, $activity]);
        $this->from($detailUrl)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload(['remarks' => 'Typed remarks kept.']))
            ->assertRedirect($detailUrl);

        $html = $this->get($detailUrl)->assertOk()->getContent();

        // The CI does not retype anything: every entered value comes back.
        $this->assertStringContainsString('data-bank-target-duplicate-warning', $html);
        $this->assertStringContainsString('value="BDO"', $html);
        $this->assertStringContainsString('value="Carmen Branch"', $html);
        $this->assertStringContainsString('Typed remarks kept.', $html);

        // Continue Anyway replaces Add Bank / Coop as the one primary action, and it lives in the
        // footer — the sibling of the scrolling body — so it cannot be hidden below a long form.
        $footer = $this->addDialogFooter($html);
        $this->assertStringContainsString('Cancel', $footer);
        $this->assertStringContainsString('name="allow_duplicate" value="1"', $footer);
        $this->assertStringContainsString('Continue Anyway', $footer);
        $this->assertStringNotContainsString('Add Bank / Coop', $footer);

        // Advisory, not destructive: progress/advisory tokens, never the danger ones.
        $this->assertStringContainsString('bg-progress-soft', $html);
        $this->assertStringNotContainsString('ui-button-danger', $footer);
        $this->assertStringNotContainsString('bg-danger', $footer);
    }

    public function test_the_normal_add_footer_offers_cancel_and_add_without_continue_anyway(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $html = $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();

        $footer = $this->addDialogFooter($html);
        $this->assertStringContainsString('Cancel', $footer);
        $this->assertStringContainsString('Add Bank / Coop', $footer);
        $this->assertStringNotContainsString('Continue Anyway', $footer);
        $this->assertStringNotContainsString('allow_duplicate', $footer);
        $this->assertStringNotContainsString('data-bank-target-duplicate-warning', $html);
    }

    public function test_both_footer_actions_stack_full_width_on_mobile_and_sit_right_aligned_on_desktop(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $detailUrl = route('client-folders.activities.bank-coop.show', [$folder, $activity]);
        $this->from($detailUrl)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect($detailUrl);

        $footer = $this->addDialogFooter($this->get($detailUrl)->assertOk()->getContent());

        // Stacked and full width on a phone, auto width aligned right from sm up. min-h-11 on the
        // shared button classes keeps the tap target comfortable; nothing here can overflow
        // sideways because neither button is given a fixed or minimum width.
        $this->assertStringContainsString('flex flex-col-reverse gap-3', $footer);
        $this->assertStringContainsString('sm:flex-row sm:justify-end', $footer);
        $this->assertSame(2, substr_count($footer, 'w-full sm:w-auto'));
        $this->assertStringNotContainsString('whitespace-nowrap', $footer);
    }

    public function test_the_real_post_redirect_get_lifecycle_ends_on_a_page_offering_continue_anyway(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $detailUrl = route('client-folders.activities.bank-coop.show', [$folder, $activity]);

        $this->actingAs($ci)->get($detailUrl)->assertOk();
        $this->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        // The real browser lifecycle: POST the duplicate and FOLLOW the redirect, so every
        // assertion below runs against the page the CI actually ends up looking at. No session
        // state is seeded by hand anywhere in this test.
        $response = $this->followingRedirects()->post(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $this->payload(['remarks' => 'Typed remarks kept.']),
        )->assertOk();

        $html = $response->getContent();

        // Warning, restored values and the footer action must all be true of this ONE response.
        $this->assertStringContainsString('data-bank-target-duplicate-warning', $html);
        $this->assertStringContainsString('has already been added to this Bank / Coop Check', $html);
        $this->assertStringContainsString('value="BDO"', $html);
        $this->assertStringContainsString('value="Carmen Branch"', $html);
        $this->assertStringContainsString('Typed remarks kept.', $html);

        $footer = $this->addDialogFooter($html);
        $this->assertStringContainsString('Continue Anyway', $footer);
        $this->assertStringContainsString('name="allow_duplicate" value="1"', $footer);
        $this->assertStringNotContainsString('Add Bank / Coop', $footer);
        $this->assertSame(1, $activity->bankTargets()->count());

        // Continue Anyway resubmits the same values plus the flag, and the duplicate is created.
        $this->followingRedirects()
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload(['allow_duplicate' => '1']))
            ->assertOk();
        $this->assertSame(2, $activity->bankTargets()->where('institution_name', 'BDO')->count());
    }

    public function test_the_markup_the_embedded_tracker_modal_imports_carries_the_duplicate_footer(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $html = $this->followingRedirects()
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertOk()
            ->getContent();

        // The CI Activities page clones only [data-bank-coop-modal-source] into its tracker modal,
        // so the duplicate footer has to live INSIDE that wrapper or the embedded view would offer
        // the CI no way to proceed.
        $start = strpos($html, 'data-bank-coop-modal-source');
        $this->assertNotFalse($start, 'Expected the embedded modal source wrapper.');
        $source = substr($html, $start, strpos($html, '<script') - $start);

        $this->assertStringContainsString('data-bank-target-duplicate-warning', $source);
        $this->assertStringContainsString('name="allow_duplicate" value="1"', $source);
        $this->assertStringContainsString('Continue Anyway', $source);
    }

    /** The Add dialog's action bar: the block after the scrolling body, which never scrolls. */
    private function addDialogFooter(string $html): string
    {
        $dialogStart = strpos($html, 'id="add-bank-target"');
        $this->assertNotFalse($dialogStart, 'Expected the Add Bank / Coop dialog to be rendered.');
        $dialogEnd = strpos($html, '</dialog>', $dialogStart);
        $dialog = substr($html, $dialogStart, $dialogEnd - $dialogStart);

        $footerStart = strrpos($dialog, '<div class="flex flex-col-reverse gap-3 border-t');
        $this->assertNotFalse($footerStart, 'Expected a non-scrolling footer in the Add dialog.');

        return substr($dialog, $footerStart);
    }

    public function test_the_add_flow_leaves_edit_revision_concurrency_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $this->actingAs($ci)
            ->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();
        $target = $activity->bankTargets()->sole();
        $this->assertSame(1, $target->revision);

        // The edit form still requires its own token, and allow_duplicate is not part of an edit.
        $this->putJson(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['institution_name' => 'BDO Carmen', 'allow_duplicate' => '1']),
        )->assertUnprocessable()->assertJsonValidationErrors(['expected_revision']);

        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'BDO Carmen']),
        )->assertRedirect();
        $this->assertSame(2, $target->fresh()->revision);

        // A stale edit is still a conflict, not a duplicate warning.
        $this->put(
            route('client-folders.activities.bank-targets.update', [$folder, $activity, $target]),
            $this->payload(['expected_revision' => 1, 'institution_name' => 'Stale']),
        )->assertRedirect()->assertSessionHas('statusType', 'error');
        $this->assertSame('BDO Carmen', $target->fresh()->institution_name);
    }

    public function test_no_unique_constraint_backs_the_warning(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        // Written straight to the table, bypassing the action entirely: identical rows are still
        // perfectly legal at the schema level, which is what keeps Continue Anyway possible.
        $this->createTarget($activity, $ci, 'BDO', ActivityStatus::Pending);
        $this->createTarget($activity, $ci, 'BDO', ActivityStatus::Pending);

        $this->assertSame(2, $activity->bankTargets()->where('institution_name', 'BDO')->count());
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BDO',
            'branch_location' => 'Carmen Branch',
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
