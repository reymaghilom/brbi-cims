<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\ClientFolders\BankInstitutionPrefill;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CibiBankCoopPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_cibi_bank_and_loan_sources_become_conservative_bank_target_candidates(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $report = $this->reportFor($folder, $ci);
        $this->bankAccount($report, 'MCCB', 'Manticao Branch', 1);
        $this->bankAccount($report, '  mccb  ', 'Manticao    Branch', 2);
        $this->bankAccount($report, 'FICCO', null, 3);
        $this->bankAccount($report, 'BDO', 'Carmen Branch', 4);
        $this->bankAccount($report, 'BDO', 'Lapasan Branch', 5);
        $this->loan($report, 'MCCB', 1, ['original_amount' => 50000, 'remarks' => 'Loan-only finding']);
        $this->loan($report, 'FICCO', 2);
        $this->loan($report, 'BDO', 3);
        $this->loan($report, 'OIC', 4);

        $candidates = app(BankInstitutionPrefill::class)->bankTargetsFromCibi($folder, null);

        $this->assertSame([
            ['inquiry_type' => 'bank_coop_check', 'institution_name' => 'MCCB', 'branch_location' => 'Manticao Branch', 'source' => 'CIBI Bank / Financial Institution'],
            ['inquiry_type' => 'bank_coop_check', 'institution_name' => 'FICCO', 'branch_location' => null, 'source' => 'CIBI Bank / Financial Institution'],
            ['inquiry_type' => 'bank_coop_check', 'institution_name' => 'BDO', 'branch_location' => 'Carmen Branch', 'source' => 'CIBI Bank / Financial Institution'],
            ['inquiry_type' => 'bank_coop_check', 'institution_name' => 'BDO', 'branch_location' => 'Lapasan Branch', 'source' => 'CIBI Bank / Financial Institution'],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'MCCB', 'branch_location' => null, 'source' => 'CIBI Credit / Loan'],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'FICCO', 'branch_location' => null, 'source' => 'CIBI Credit / Loan'],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'BDO', 'branch_location' => null, 'source' => 'CIBI Credit / Loan'],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'OIC', 'branch_location' => null, 'source' => 'CIBI Credit / Loan'],
        ], $candidates);

        $index = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $index->assertOk()
            ->assertDontSee('Available from this person&rsquo;s CIBI report', false)
            ->assertDontSee('populate automatically when this section is first selected')
            ->assertDontSee('data-bank-prefill-candidate', false)
            ->assertSee('const bankPrefillCandidates =', false)
            ->assertSee('let bankPrefillInitialized = false;', false)
            ->assertSee('if (bankPrefillInitialized || bankTargetSection.hidden) return;', false)
            ->assertSee('bankPrefillCandidates.forEach(applyBankPrefillCandidate);', false)
            ->assertSee('initializeBankTargetsFromCibi();', false)
            ->assertSee("if (name.value.trim() === '') name.value = institution;", false)
            ->assertSee("if (location.value.trim() === '') location.value = branch;", false)
            ->assertDontSee('Loan-only finding');
    }

    public function test_add_activity_prefill_initializes_once_preserves_user_rows_and_never_persists_on_open(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $report = $this->reportFor($folder, $ci);
        $this->bankAccount($report, 'BDO', 'CDO Branch', 1);
        $this->bankAccount($report, 'Metrobank', 'Divisoria', 2);
        $this->bankAccount($report, 'FICCO', null, 3);
        $this->loan($report, 'Loan Cooperative', 1, ['remarks' => 'Never map this remark']);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()
            ->assertSee('BDO')
            ->assertSee('CDO Branch')
            ->assertSee('Metrobank')
            ->assertSee('Divisoria')
            ->assertSee('FICCO')
            ->assertSee('Loan Cooperative')
            ->assertDontSee('data-bank-prefill-candidate', false)
            ->assertSee('const bankTargetCollectionIsPristine = () =>', false)
            ->assertSee('bankPrefillInitialized = true;', false)
            ->assertSee('bankPrefillCandidates.forEach(applyBankPrefillCandidate);', false)
            ->assertSee('data-bank-target-add', false)
            ->assertDontSee('Never map this remark');

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);

        $oldInput = [
            'activity_definition_id' => $this->bankDefinition()->id,
            'create_new_activity_type' => false,
            'bank_targets' => [
                4 => [
                    'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
                    'institution_name' => 'User Edited Bank',
                    'branch_location' => 'User Edited Branch',
                    'status' => ActivityStatus::Pending->value,
                    'scheduled_at' => '',
                    'scheduled_time' => '',
                    'remarks' => 'Manual row stays authoritative.',
                ],
            ],
        ];
        $roundTrip = $this->withSession(['_old_input' => $oldInput])->get(route('client-folders.activities.index', $folder));
        $roundTrip->assertOk()
            ->assertSee('name="bank_targets[4][institution_name]" value="User Edited Bank"', false)
            ->assertSee('name="bank_targets[4][inquiry_type]"', false)
            ->assertSee('name="bank_targets[4][branch_location]" value="User Edited Branch"', false)
            ->assertSee('Manual row stays authoritative.')
            ->assertSee('let bankPrefillInitialized = true;', false);

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
    }

    public function test_add_activity_has_no_hard_coded_institution_candidates_without_exact_cibi_data(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()
            ->assertDontSee('data-bank-prefill-candidate', false)
            ->assertSee('const bankPrefillCandidates = [];', false)
            ->assertDontSee('MCCB')
            ->assertDontSee('FICCO')
            ->assertDontSee('OIC')
            ->assertDontSee('BDO')
            ->assertDontSee('PNB')
            ->assertDontSee('Metrobank');
    }

    public function test_existing_bank_target_candidates_are_excluded_from_the_add_target_dialog(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $report = $this->reportFor($folder, $ci);
        $this->bankAccount($report, 'MCCB', 'Manticao Branch', 1);
        $this->bankAccount($report, 'OIC', null, 2);
        $activity = $this->bankActivity($folder, $ci);
        $this->target($activity, $ci, 'MCCB', 'Manticao Branch');

        $page = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $activity]));

        $page->assertOk()
            ->assertSee('data-bank-target-prefill data-inquiry-type="bank_coop_check" data-institution="OIC" data-branch=""', false)
            ->assertDontSee('data-bank-target-prefill data-institution="MCCB"', false)
            ->assertSee("if (institution.value.trim() !== '' && normalize(institution.value) !== normalize(candidateInstitution)) return;", false)
            ->assertSee("if (institution.value.trim() === '') institution.value = candidateInstitution;", false)
            ->assertSee("if (branch.value.trim() === '') branch.value = button.dataset.branch ?? '';", false);
    }

    public function test_bank_targets_render_as_unsaved_cibi_bank_and_loan_rows_without_cross_mapping(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $first = $this->target($activity, $ci, 'MCCB', 'Manticao Branch');
        $first->update([
            'status' => ActivityStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
            'scheduled_has_time' => true,
            'remarks' => 'Workflow-only secret remark',
        ]);
        $this->target($activity, $ci, 'FICCO', null, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);

        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder));

        $page->assertOk()
            // The prefilled rows themselves are the whole signal now — the informational banner
            // that used to announce them was removed, and must not come back.
            ->assertDontSee('data-cibi-bank-prefill-note', false)
            ->assertDontSee('prefilled from Bank / Coop Check')
            ->assertSee('name="bank_accounts[0][institution]" value="MCCB"', false)
            ->assertSee('name="bank_accounts[0][branch]" value="Manticao Branch"', false)
            ->assertDontSee('name="bank_accounts[1][institution]" value="FICCO"', false)
            ->assertSee('name="loan_records[0][institution]" value="FICCO"', false)
            ->assertSee('name="loan_records[0][original_amount]" value=""', false)
            ->assertSee('name="loan_records[0][remaining_balance]" value=""', false)
            ->assertSee('name="loan_records[0][amortization_amount]" value=""', false)
            ->assertDontSee('name="loan_records[0][branch]"', false)
            ->assertDontSee('Workflow-only secret remark');
        $this->assertDatabaseCount('cibi_reports', 0);
        $this->assertDatabaseCount('cibi_bank_accounts', 0);
        $this->assertDatabaseCount('cibi_loan_records', 0);
    }

    public function test_existing_cibi_values_are_untouched_while_only_missing_pairs_are_appended(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $report = $this->reportFor($folder, $ci);
        $this->bankAccount($report, 'Manual Bank', 'Saved Branch', 1);
        $this->loan($report, 'Manual Bank', 1, ['original_amount' => 125000]);
        $activity = $this->bankActivity($folder, $ci);
        $this->target($activity, $ci, 'Manual Bank', 'Saved Branch');
        $this->target($activity, $ci, 'Manual Bank', 'Other Branch');
        $this->target($activity, $ci, 'Another Bank', null, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);

        $page = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder));

        $page->assertOk()
            ->assertSee('name="bank_accounts[0][institution]" value="Manual Bank"', false)
            ->assertSee('name="bank_accounts[0][branch]" value="Saved Branch"', false)
            ->assertSee('name="bank_accounts[1][institution]" value="Manual Bank"', false)
            ->assertSee('name="bank_accounts[1][branch]" value="Other Branch"', false)
            ->assertDontSee('name="bank_accounts[2][institution]" value="Another Bank"', false)
            ->assertSee('name="loan_records[0][institution]" value="Manual Bank"', false)
            ->assertSee('name="loan_records[0][original_amount]" value="125000.00"', false)
            ->assertSee('name="loan_records[1][institution]" value="Another Bank"', false);
        $this->assertSame('Saved Branch', $report->bankAccounts()->where('institution', 'Manual Bank')->sole()->branch);
        $this->assertSame('125000.00', $report->loanRecords()->where('institution', 'Manual Bank')->sole()->original_amount);
        $this->assertSame(1, $report->bankAccounts()->count());
        $this->assertSame(1, $report->loanRecords()->count());
    }

    public function test_validation_round_trip_keeps_unsaved_manual_cibi_rows_authoritative(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $this->target($activity, $ci, 'Source Target', 'Source Branch');

        $page = $this->actingAs($ci)->withSession(['_old_input' => [
            'bank_accounts' => [[
                'institution' => 'Manual Draft Bank',
                'branch' => 'Manual Draft Branch',
            ]],
            'loan_records' => [[
                'institution' => 'Manual Draft Loan',
            ]],
        ]])->get(route('client-folders.cibi-report.edit', $folder));

        $page->assertOk()
            ->assertSee('name="bank_accounts[0][institution]" value="Manual Draft Bank"', false)
            ->assertSee('name="bank_accounts[0][branch]" value="Manual Draft Branch"', false)
            ->assertSee('name="loan_records[0][institution]" value="Manual Draft Loan"', false)
            ->assertDontSee('data-cibi-bank-prefill-note', false)
            ->assertDontSee('prefilled from Bank / Coop Check')
            ->assertDontSee('value="Source Target"', false);
    }

    public function test_prefill_candidates_are_isolated_by_folder_and_exact_applicant_or_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $otherFolder = $this->folderFor($ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $otherMaker = $this->coMaker($otherFolder, 'Maker Other');

        foreach ([
            [$folder, null, 'Applicant CIBI'],
            [$folder, $makerA, 'Maker A CIBI'],
            [$folder, $makerB, 'Maker B CIBI'],
            [$otherFolder, $otherMaker, 'Other Folder CIBI'],
        ] as [$ownerFolder, $person, $name]) {
            $report = $this->reportFor($ownerFolder, $ci, $person?->id);
            $this->bankAccount($report, $name, null, 1);
        }

        $this->target($this->bankActivity($folder, $ci), $ci, 'Applicant Target', null);
        $this->target($this->bankActivity($folder, $ci, $makerA->id), $ci, 'Maker A Target', null);
        $this->target($this->bankActivity($folder, $ci, $makerB->id), $ci, 'Maker B Target', null, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);
        $this->target($this->bankActivity($otherFolder, $ci, $otherMaker->id), $ci, 'Other Folder Target', null);

        $prefill = app(BankInstitutionPrefill::class);
        $this->assertSame(['Applicant CIBI'], array_column($prefill->bankTargetsFromCibi($folder, null), 'institution_name'));
        $this->assertSame(['Maker A CIBI'], array_column($prefill->bankTargetsFromCibi($folder, $makerA), 'institution_name'));
        $this->assertSame(['Maker B CIBI'], array_column($prefill->bankTargetsFromCibi($folder, $makerB), 'institution_name'));
        $this->assertSame(['Applicant Target'], array_column($prefill->cibiBankAccountsFromTargets($folder, null), 'institution'));
        $this->assertSame(['Maker A Target'], array_column($prefill->cibiBankAccountsFromTargets($folder, $makerA), 'institution'));
        $this->assertSame(['Maker B Target'], array_column($prefill->cibiLoanRecordsFromTargets($folder, $makerB), 'institution'));

        $makerPage = $this->actingAs($ci)->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $makerA->id,
        ]));
        $makerPage->assertOk()
            ->assertSee('Maker A CIBI')
            ->assertDontSee('Applicant CIBI')
            ->assertDontSee('Maker B CIBI')
            ->assertDontSee('Other Folder CIBI');
    }

    private function reportFor(ClientFolder $folder, User $ci, ?int $coMakerId = null): CibiReport
    {
        return CibiReport::create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'ci_in_charge_id' => $ci->id,
            'created_by' => $ci->id,
            'last_edited_by' => $ci->id,
            'revision' => 1,
            'state' => 'complete',
        ]);
    }

    private function bankAccount(CibiReport $report, string $institution, ?string $branch, int $sortOrder): void
    {
        $report->bankAccounts()->create([
            'institution' => $institution,
            'branch' => $branch,
            'sort_order' => $sortOrder,
        ]);
    }

    private function loan(CibiReport $report, string $institution, int $sortOrder, array $attributes = []): void
    {
        $report->loanRecords()->create($attributes + [
            'institution' => $institution,
            'sort_order' => $sortOrder,
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

    private function target(CiActivity $activity, User $actor, string $institution, ?string $branch, string $inquiryType = CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'inquiry_type' => $inquiryType,
            'institution_name' => $institution,
            'branch_location' => $branch,
            'status' => ActivityStatus::Pending,
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
