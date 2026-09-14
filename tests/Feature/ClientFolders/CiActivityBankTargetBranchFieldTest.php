<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branch / Location applicability in the Add and Edit Bank / Coop dialogs.
 *
 * Business rule, unchanged by any of this: a Bank / Coop Check may carry a branch or leave it
 * blank, and a Loan Inquiry always stores NULL. What changed is only how the form SAYS so — the
 * field is now disabled and labelled rather than hidden, and a typed branch is stashed instead of
 * destroyed when the Inquiry Type is toggled.
 *
 * LIMITATION: PHPUnit renders Blade, it does not run the page's JavaScript. Everything asserted
 * here is the server-rendered starting state plus the backend rules. The three interactive
 * behaviours — the branch stash/restore across a type toggle, the candidate click forcing its own
 * Inquiry Type, and the candidate branch prefill — run only in a browser and are listed in the
 * manual verification checklist. The two source-level assertions below are regression guards
 * against the exact patterns that caused the bugs; they are not proof that the JS behaves.
 */
class CiActivityBankTargetBranchFieldTest extends TestCase
{
    use RefreshDatabase;

    private const HINT_OPTIONAL = 'Optional — leave blank if not specified.';

    private const HINT_NOT_APPLICABLE = 'Not applicable for Loan Inquiry.';

    /** The helper line sits BELOW the input, not inside the label, so the label stays one line. */
    private const LABEL = '>Branch / Location</label>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->bankDefinition();
    }

    public function test_the_add_dialog_offers_branch_as_an_optional_enabled_field(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $field = $this->branchField($this->trackerHtml($ci, $folder, $activity), 'add-branch-location');

        // The label is the field name alone; the helper is a separate line under the input, which
        // is what keeps this control's top edge level with the Status select beside it.
        $this->assertStringContainsString(self::LABEL, $field);
        $this->assertStringContainsString('data-bank-target-branch-hint', $field);
        $this->assertStringContainsString(self::HINT_OPTIONAL, $field);
        $this->assertGreaterThan(strpos($field, '<input'), strpos($field, self::HINT_OPTIONAL));
        $this->assertStringNotContainsString('hidden', $field);
        $this->assertStringNotContainsString('disabled', $field);
        // Optional means optional: no required attribute and no required indicator.
        $this->assertStringNotContainsString('required', $field);
    }

    public function test_an_existing_bank_coop_check_keeps_its_branch_visible_and_editable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO', 'Carmen Branch');

        $field = $this->branchField($this->trackerHtml($ci, $folder, $activity), 'branch-location-'.$target->id);

        $this->assertStringContainsString('value="Carmen Branch"', $field);
        $this->assertStringContainsString(self::HINT_OPTIONAL, $field);
        $this->assertStringNotContainsString('disabled', $field);
        $this->assertStringNotContainsString('hidden', $field);
    }

    public function test_an_existing_bank_coop_check_without_a_branch_is_a_normal_blank_field(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'BDO', null);

        $field = $this->branchField($this->trackerHtml($ci, $folder, $activity), 'branch-location-'.$target->id);

        // A blank branch is valid, not an error state: still enabled, still labelled optional.
        $this->assertStringContainsString('value=""', $field);
        $this->assertStringContainsString(self::HINT_OPTIONAL, $field);
        $this->assertStringNotContainsString('disabled', $field);
    }

    public function test_a_loan_inquiry_shows_the_branch_field_disabled_and_marked_not_applicable(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $target = $this->createTarget($activity, $ci, 'FICCO', null, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);

        $field = $this->branchField($this->trackerHtml($ci, $folder, $activity), 'branch-location-'.$target->id);

        // Visible, so the CI can see WHY it cannot be filled — the field no longer just vanishes.
        // Same structure as Add: bare label, input, then the helper line underneath.
        $this->assertStringContainsString(self::LABEL, $field);
        $this->assertGreaterThan(strpos($field, '<input'), strpos($field, self::HINT_NOT_APPLICABLE));
        $this->assertStringNotContainsString('hidden', $field);
        $this->assertStringContainsString('disabled', $field);
        $this->assertStringContainsString(self::HINT_NOT_APPLICABLE, $field);
        $this->assertStringNotContainsString(self::HINT_OPTIONAL, $field);
        $this->assertStringContainsString('value=""', $field);
    }

    public function test_a_loan_inquiry_never_renders_a_historical_branch_value(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        // Written straight to the table to simulate a legacy row that predates the NULL rule.
        $target = $this->createTarget($activity, $ci, 'FICCO', 'Legacy Branch', CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);

        $field = $this->branchField($this->trackerHtml($ci, $folder, $activity), 'branch-location-'.$target->id);

        // The backend stores NULL for this type, so showing the stale value would display something
        // that is not the record. The row itself is left exactly as it is.
        $this->assertStringNotContainsString('Legacy Branch', $field);
        $this->assertStringContainsString('value=""', $field);
        $this->assertSame('Legacy Branch', $target->fresh()->branch_location);
    }

    public function test_cibi_candidates_still_carry_the_inquiry_type_institution_and_branch_the_click_needs(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $this->cibi($folder, $ci, 'BDO', 'Carmen Branch', 'FICCO');

        $html = $this->trackerHtml($ci, $folder, $activity);

        // The candidate payload is unchanged — BankInstitutionPrefill was not touched.
        $this->assertStringContainsString('data-inquiry-type="'.CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK.'" data-institution="BDO" data-branch="Carmen Branch"', $html);
        $this->assertStringContainsString('data-inquiry-type="'.CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY.'" data-institution="FICCO" data-branch=""', $html);
    }

    public function test_the_script_no_longer_blanks_the_branch_outright_and_settles_applicability_before_filling_it(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $html = $this->trackerHtml($ci, $folder, $activity);

        // Source-level regression guards for the two fixed defects. These prove the shipped code no
        // longer contains the broken constructs; they do NOT prove runtime behaviour, which is
        // covered by the manual checklist.
        $this->assertStringNotContainsString("if (isLoanInquiry) branch.value = '';", $html);
        $this->assertStringNotContainsString("if (inquiryType.value === '') inquiryType.value =", $html);
        $this->assertStringContainsString('let stashedBranch', $html);
        $this->assertStringContainsString('inquiryType.value = button.dataset.inquiryType ?? inquiryType.value;', $html);
        $this->assertStringContainsString("if (! branch.disabled && branch.value.trim() === '') branch.value = button.dataset.branch ?? '';", $html);
        // The field is no longer hidden by type.
        $this->assertStringNotContainsString('branchField.hidden = isLoanInquiry;', $html);
    }

    public function test_the_backend_branch_rules_are_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);

        $store = fn (array $overrides) => $this->post(
            route('client-folders.activities.bank-targets.store', [$folder, $activity]),
            $overrides + [
                'co_maker_id' => '',
                'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
                'institution_name' => 'BDO',
                'status' => ActivityStatus::Pending->value,
            ],
        );

        $this->actingAs($ci);

        // Bank / Coop Check: branch saved when given, NULL when blank — both valid.
        $store(['branch_location' => 'Carmen Branch'])->assertRedirect()->assertSessionHasNoErrors();
        $store(['institution_name' => 'BPI', 'branch_location' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Carmen Branch', $activity->bankTargets()->where('institution_name', 'BDO')->sole()->branch_location);
        $this->assertNull($activity->bankTargets()->where('institution_name', 'BPI')->sole()->branch_location);

        // Loan Inquiry: a forged branch is still discarded at both the request and the action.
        $store([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            'institution_name' => 'FICCO',
            'branch_location' => 'Forged Branch',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($activity->bankTargets()->where('institution_name', 'FICCO')->sole()->branch_location);
    }

    private function trackerHtml(User $ci, ClientFolder $folder, CiActivity $activity): string
    {
        return $this->actingAs($ci)
            ->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]))
            ->assertOk()
            ->getContent();
    }

    /** The single branch input plus its label, isolated so attribute assertions cannot drift. */
    private function branchField(string $html, string $inputId): string
    {
        $position = strpos($html, 'for="'.$inputId.'"');
        $this->assertNotFalse($position, 'Expected a Branch / Location field with id '.$inputId.'.');
        $start = strrpos(substr($html, 0, $position), '<div data-bank-target-detail-branch-field');
        $this->assertNotFalse($start, 'Expected the branch field wrapper.');
        $end = strpos($html, '</div>', $position);

        return substr($html, $start, $end - $start);
    }

    private function cibi(ClientFolder $folder, User $ci, string $bank, ?string $branch, string $loanInstitution): void
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
        $report->bankAccounts()->create(['institution' => $bank, 'branch' => $branch, 'sort_order' => 1]);
        $report->loanRecords()->create(['institution' => $loanInstitution, 'sort_order' => 1]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }

    private function bankActivity(ClientFolder $folder, User $creator): CiActivity
    {
        return CiActivity::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'activity_definition_id' => $this->bankDefinition()->id,
            'name' => 'Bank / Coop Check',
            'status' => ActivityStatus::Pending,
            'creator_id' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createTarget(
        CiActivity $activity,
        User $actor,
        string $institution,
        ?string $branch,
        string $inquiryType = CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
    ): CiActivityBankTarget {
        return tap($activity->bankTargets()->create([
            'inquiry_type' => $inquiryType,
            'institution_name' => $institution,
            'branch_location' => $branch,
            'status' => ActivityStatus::Pending,
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
