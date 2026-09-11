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

        $this->assertDatabaseMissing('ci_activities', ['activity_definition_id' => $this->bankDefinition()->id]);
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

        $this->assertDatabaseMissing('ci_activities', ['activity_definition_id' => $this->bankDefinition()->id]);
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

    public function test_distinct_bank_and_loan_institutions_each_prefill_once_and_existing_targets_are_never_repeated(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $report = $this->reportFor($folder, $ci);
        // Two Bank / Financial rows and three Credit / Loan rows, no institution shared: five targets.
        $this->bankAccount($report, 'BPI', 'PUERTO', 1);
        $this->bankAccount($report, 'LANDBANK', 'PUERTO', 2);
        $this->loan($report, 'MCCB', 1);
        $this->loan($report, 'OIC', 2);
        $this->loan($report, 'FICCO', 3);
        $prefill = app(BankInstitutionPrefill::class);

        $this->assertSame(
            [['bank_coop_check', 'BPI'], ['bank_coop_check', 'LANDBANK'], ['loan_inquiry', 'MCCB'], ['loan_inquiry', 'OIC'], ['loan_inquiry', 'FICCO']],
            array_map(fn (array $candidate): array => [$candidate['inquiry_type'], $candidate['institution_name']], $prefill->bankTargetsFromCibi($folder, null)),
        );

        // Four already exist (one typed with different case/spacing): only the missing one is suggested.
        $activity = $this->bankActivity($folder, $ci);
        $this->target($activity, $ci, '  landbank ', 'puerto');
        foreach (['MCCB', 'OIC', 'FICCO'] as $loan) {
            $this->target($activity, $ci, $loan, null, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);
        }
        $remaining = $prefill->bankTargetsFromCibi($folder, null, $activity->bankTargets()->get());
        $this->assertSame(['BPI'], array_column($remaining, 'institution_name'));

        // Once it exists too, prefilling again suggests nothing.
        $this->target($activity, $ci, 'BPI', 'PUERTO');
        $this->assertSame([], $prefill->bankTargetsFromCibi($folder, null, $activity->bankTargets()->get()));
        $this->assertSame(5, $activity->bankTargets()->count());

        // Four distinct institutions give exactly four candidates.
        $report->bankAccounts()->where('institution', 'BPI')->delete();
        $this->assertCount(4, $prefill->bankTargetsFromCibi($folder, null));
    }

    public function test_add_activity_saves_completed_prefilled_rows_and_their_remarks_together_with_an_added_row(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $row = fn (string $type, string $name, ?string $branch, ActivityStatus $status, ?string $remarks = null): array => [
            'inquiry_type' => $type, 'institution_name' => $name, 'branch_location' => $branch,
            'status' => $status->value, 'scheduled_at' => '', 'scheduled_time' => '', 'remarks' => $remarks,
        ];

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $this->bankDefinition()->id,
            'create_new_activity_type' => false,
            'bank_targets' => [
                0 => $row(CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'BPI', 'PUERTO', ActivityStatus::Completed, 'Verified with branch.'),
                1 => $row(CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'LANDBANK', 'PUERTO', ActivityStatus::Pending),
                2 => $row(CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY, 'MCCB', null, ActivityStatus::Completed, 'No arrears.'),
                // The row added with "Add Another Bank / Coop" after the statuses above were changed.
                5 => $row(CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'Manual Coop', null, ActivityStatus::Pending),
            ],
        ])->assertSessionHasNoErrors();

        $activity = $folder->activities()->sole();
        $targets = $activity->bankTargets()->get()->keyBy('institution_name');
        $this->assertCount(4, $targets);
        $this->assertSame(ActivityStatus::Completed, $targets['BPI']->status);
        $this->assertSame('Verified with branch.', $targets['BPI']->remarks);
        $this->assertSame(ActivityStatus::Completed, $targets['MCCB']->status);
        $this->assertSame('No arrears.', $targets['MCCB']->remarks);
        $this->assertSame(ActivityStatus::Pending, $targets['Manual Coop']->status);
        $this->assertSame(CiActivityBankTarget::deriveParentStatus($targets->pluck('status')), $activity->status);
    }

    public function test_an_invalid_added_row_blocks_the_whole_add_and_saves_nothing(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->from(route('client-folders.activities.index', $folder))->post(route('client-folders.activities.store', $folder), [
            'activity_definition_id' => $this->bankDefinition()->id,
            'create_new_activity_type' => false,
            'bank_targets' => [
                ['inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => 'BPI', 'branch_location' => 'PUERTO', 'status' => ActivityStatus::Completed->value, 'remarks' => 'Verified.'],
                ['inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => '', 'status' => ActivityStatus::Pending->value],
            ],
        ])->assertSessionHasErrors('bank_targets.1.institution_name');

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
    }

    public function test_adding_a_target_in_the_tracker_leaves_an_existing_completed_target_untouched(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->bankActivity($folder, $ci);
        $completed = $this->target($activity, $ci, 'LANDBANK', 'PUERTO');
        $completed->update(['status' => ActivityStatus::Completed, 'remarks' => 'Account verified.']);
        $activity->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
        $completed->refresh();
        $this->travel(5)->minutes();

        $this->actingAs($ci)->post(route('client-folders.activities.bank-targets.store', [$folder, $activity]), [
            'co_maker_id' => '',
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => 'BPI',
            'branch_location' => 'PUERTO',
            'status' => ActivityStatus::Pending->value,
        ])->assertSessionHasNoErrors();

        $fresh = $completed->fresh();
        $this->assertSame(ActivityStatus::Completed, $fresh->status);
        $this->assertSame('Account verified.', $fresh->remarks);
        $this->assertTrue($fresh->updated_at->equalTo($completed->updated_at), 'The untouched target must not be re-written.');
        $this->assertSame(2, $activity->bankTargets()->count());
        // The parent follows the existing target-derived rule once an unfinished target joins.
        $this->assertSame(CiActivityBankTarget::deriveParentStatus($activity->bankTargets()->pluck('status')), $activity->fresh()->status);
        $this->assertNotSame(ActivityStatus::Completed, $activity->fresh()->status);
    }

    /**
     * Regression: the Add Activity repeater derives the next row index from each row's
     * data-bank-target-index. When that attribute was fused into another one, JS restarted at 0,
     * so the second prefilled row reused bank_targets[0] and overwrote the first (folder #34 lost BPI).
     */
    public function test_add_activity_rows_expose_their_index_so_prefilled_rows_never_share_a_name(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->folderThirtyFourCibi($folder, $ci);

        $html = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-schedule-scopedata-', $html);
        foreach (['bank', 'asset'] as $kind) {
            $this->assertMatchesRegularExpression('/<article[^>]*\sdata-'.$kind.'-target-row\s+data-schedule-scope\s+data-'.$kind.'-target-index="0"/', $html, "Server-rendered {$kind} row must carry its index.");
            $this->assertMatchesRegularExpression('/<article[^>]*\sdata-'.$kind.'-target-row\s+data-schedule-scope\s+data-'.$kind.'-target-index="__INDEX__"/', $html, "The {$kind} row template must carry its index.");
        }
        $this->assertStringContainsString("querySelectorAll('[data-bank-target-index]')", $html);

        // The JS receives all five CIBI candidates, in order, with their types and branches.
        // @js renders JSON.parse('…') with \uXXXX escapes, which are also valid JSON string escapes.
        preg_match("/const bankPrefillCandidates = JSON\\.parse\\('(.*?)'\\);/s", $html, $matches);
        $this->assertSame([
            ['inquiry_type' => 'bank_coop_check', 'institution_name' => 'BPI', 'branch_location' => 'PUERTO'],
            ['inquiry_type' => 'bank_coop_check', 'institution_name' => 'LANDBANK', 'branch_location' => 'PUERTO'],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'MCCB', 'branch_location' => null],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'OIC', 'branch_location' => null],
            ['inquiry_type' => 'loan_inquiry', 'institution_name' => 'FICCO', 'branch_location' => null],
        ], json_decode((string) json_decode('"'.($matches[1] ?? '[]').'"'), true));
    }

    public function test_every_submitted_prefilled_row_is_saved_with_its_own_type_branch_status_remarks_and_schedule(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->folderThirtyFourCibi($folder, $ci);

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), $this->bankPayload([
            0 => $this->row('bank_coop_check', 'BPI', 'PUERTO', ActivityStatus::Completed, remarks: 'Account verified.'),
            1 => $this->row('bank_coop_check', 'LANDBANK', 'PUERTO', ActivityStatus::Pending),
            2 => $this->row('loan_inquiry', 'MCCB', null, ActivityStatus::Completed, remarks: 'No arrears.'),
            3 => $this->row('loan_inquiry', 'OIC', null, ActivityStatus::Scheduled, '2026-09-15', '09:30'),
            4 => $this->row('loan_inquiry', 'FICCO', null, ActivityStatus::FollowUp, '2026-09-16'),
        ]))->assertSessionHasNoErrors();

        $activity = $folder->activities()->sole();
        $targets = $activity->bankTargets()->orderBy('id')->get();
        $this->assertSame(['BPI', 'LANDBANK', 'MCCB', 'OIC', 'FICCO'], $targets->pluck('institution_name')->all());
        $byName = $targets->keyBy('institution_name');
        $this->assertSame([CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'PUERTO'], [$byName['BPI']->inquiry_type, $byName['BPI']->branch_location]);
        $this->assertSame([CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'PUERTO'], [$byName['LANDBANK']->inquiry_type, $byName['LANDBANK']->branch_location]);
        foreach (['MCCB', 'OIC', 'FICCO'] as $loan) {
            $this->assertSame(CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY, $byName[$loan]->inquiry_type);
            $this->assertNull($byName[$loan]->branch_location);
        }
        $this->assertSame(
            [ActivityStatus::Completed, ActivityStatus::Pending, ActivityStatus::Completed, ActivityStatus::Scheduled, ActivityStatus::FollowUp],
            $targets->pluck('status')->all(),
        );
        $this->assertSame('Account verified.', $byName['BPI']->remarks);
        $this->assertSame('No arrears.', $byName['MCCB']->remarks);
        $this->assertTrue($byName['OIC']->scheduled_has_time);
        $this->assertSame('2026-09-15 09:30', $byName['OIC']->scheduled_at->timezone('Asia/Manila')->format('Y-m-d H:i'));
        $this->assertFalse($byName['FICCO']->scheduled_has_time);
        $this->assertSame('2026-09-16', $byName['FICCO']->scheduled_at->timezone('Asia/Manila')->format('Y-m-d'));
        $this->assertSame(CiActivityBankTarget::deriveParentStatus($targets->pluck('status')), $activity->status);
        // CIBI stays the untouched source.
        $this->assertSame(2, CibiReport::query()->where('client_folder_id', $folder->id)->sole()->bankAccounts()->count());
        $this->assertSame(3, CibiReport::query()->where('client_folder_id', $folder->id)->sole()->loanRecords()->count());
    }

    public function test_six_rows_save_six_a_removed_row_stays_removed_and_an_added_row_is_saved_too(): void
    {
        $ci = User::factory()->create();
        $six = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.activities.store', $six), $this->bankPayload([
            0 => $this->row('bank_coop_check', 'BPI', 'PUERTO'),
            1 => $this->row('bank_coop_check', 'Metrobank', null),
            2 => $this->row('bank_coop_check', 'BDO', null),
            3 => $this->row('loan_inquiry', 'MCCB', null),
            4 => $this->row('loan_inquiry', 'OIC', null),
            5 => $this->row('loan_inquiry', 'FICCO', null),
        ]))->assertSessionHasNoErrors();
        $this->assertSame(6, $six->activities()->sole()->bankTargets()->count());

        // BPI removed in the modal (index 0 gone) and one row added with "Add Another Bank / Coop".
        $edited = $this->folderFor($ci);
        $this->post(route('client-folders.activities.store', $edited), $this->bankPayload([
            1 => $this->row('bank_coop_check', 'LANDBANK', 'PUERTO', ActivityStatus::Completed),
            2 => $this->row('loan_inquiry', 'MCCB', null),
            3 => $this->row('loan_inquiry', 'OIC', null),
            4 => $this->row('loan_inquiry', 'FICCO', null),
            5 => $this->row('bank_coop_check', 'Manual Coop', 'Poblacion'),
        ]))->assertSessionHasNoErrors();
        $targets = $edited->activities()->sole()->bankTargets()->orderBy('id')->get();
        $this->assertSame(['LANDBANK', 'MCCB', 'OIC', 'FICCO', 'Manual Coop'], $targets->pluck('institution_name')->all());
        $this->assertSame(ActivityStatus::Completed, $targets->first()->status);
    }

    public function test_a_scheduled_row_without_a_date_still_rejects_the_whole_add(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->from(route('client-folders.activities.index', $folder))->post(route('client-folders.activities.store', $folder), $this->bankPayload([
            0 => $this->row('bank_coop_check', 'BPI', 'PUERTO'),
            1 => $this->row('loan_inquiry', 'MCCB', null, ActivityStatus::Scheduled),
        ]))->assertSessionHasErrors(['bank_targets.1.scheduled_at' => 'Please select a scheduled date.']);

        $this->assertDatabaseCount('ci_activities', 0);
        $this->assertDatabaseCount('ci_activity_bank_targets', 0);
    }

    public function test_folder_thirty_four_shaped_prefill_stays_inside_the_exact_person(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->folderThirtyFourCibi($folder, $ci);
        $makerA = $this->coMaker($folder, 'Maker Alpha');
        $makerB = $this->coMaker($folder, 'Maker Beta');
        $this->bankAccount($this->reportFor($folder, $ci, $makerA->id), 'Maker A Bank', 'Maker A Branch', 1);
        $prefill = app(BankInstitutionPrefill::class);

        $this->assertSame(['BPI', 'LANDBANK', 'MCCB', 'OIC', 'FICCO'], array_column($prefill->bankTargetsFromCibi($folder, null), 'institution_name'));
        $this->assertSame(['Maker A Bank'], array_column($prefill->bankTargetsFromCibi($folder, $makerA), 'institution_name'));
        $this->assertSame([], $prefill->bankTargetsFromCibi($folder, $makerB));
    }

    private function folderThirtyFourCibi(ClientFolder $folder, User $ci): void
    {
        $report = $this->reportFor($folder, $ci);
        $this->bankAccount($report, 'BPI', 'PUERTO', 1);
        $this->bankAccount($report, 'LANDBANK', 'PUERTO', 2);
        $this->loan($report, 'MCCB', 1);
        $this->loan($report, 'OIC', 2);
        $this->loan($report, 'FICCO', 3);
    }

    private function bankPayload(array $rows): array
    {
        return ['activity_definition_id' => $this->bankDefinition()->id, 'create_new_activity_type' => false, 'bank_targets' => $rows];
    }

    private function row(string $type, string $name, ?string $branch, ActivityStatus $status = ActivityStatus::Pending, ?string $date = null, ?string $time = null, ?string $remarks = null): array
    {
        return [
            'inquiry_type' => $type, 'institution_name' => $name, 'branch_location' => $branch, 'status' => $status->value,
            'scheduled_at' => $date, 'scheduled_time' => $time, 'remarks' => $remarks,
        ];
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
