<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\Progress\MandatoryInvestigationRequirements;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bank / Coop Check and Asset Check can never get stuck without their targets.
 *
 * Root cause of the reported stuck flow: the Add Activity type selector script read an undeclared
 * `submitButton`, so syncNewActivityType() threw before it showed the Bank / Coop or Asset target
 * section. Choosing either type left target entry hidden and disabled, every Add was refused for
 * missing targets, and nothing brought the section back. The server side was already consistent:
 * an Add must carry at least one target (so no zero-target parent is ever created), and an
 * existing activity whose targets were all removed stays open in its tracker with its Add control.
 */
class MultiTargetActivityRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    private ClientFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
        $this->folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
    }

    public function test_the_add_activity_type_script_declares_what_it_reads_so_the_target_sections_sync(): void
    {
        $html = $this->actingAs($this->ci)->get(route('client-folders.activities.index', $this->folder))->assertOk()->getContent();

        $start = strpos($html, "const select = document.querySelector('[data-activity-type-select]');");
        $this->assertNotFalse($start);
        $closure = substr($html, $start, strpos($html, 'syncNewActivityType();', $start) - $start);

        $declared = strpos($closure, "const submitButton = form?.querySelector('[data-ci-activity-submit]');");
        $this->assertNotFalse($declared, 'submitButton is declared inside the type-selector script.');
        $this->assertLessThan(strpos($closure, 'submitButton?.dataset.ciActivityDuplicateContinue'), $declared);
        // The syncs that reveal target entry run after that line inside syncNewActivityType().
        $sync = substr($closure, strpos($closure, 'const syncNewActivityType = () => {'));
        $this->assertLessThan(strpos($sync, 'syncBankTargetSection();'), strpos($sync, 'submitButton?.dataset.ciActivityDuplicateContinue'));
        $this->assertLessThan(strpos($sync, 'syncAssetTargetSection();'), strpos($sync, 'submitButton?.dataset.ciActivityDuplicateContinue'));
        $this->assertStringContainsString('data-ci-activity-submit', $html);

        // Both target sections, their Add controls and row templates are always rendered.
        foreach (['data-bank-targets-section', 'data-bank-target-add', 'data-bank-target-template', 'data-asset-targets-section', 'data-asset-target-add', 'data-asset-target-template'] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }
    }

    public function test_bank_coop_lifecycle_zero_targets_first_target_remove_last_and_recover(): void
    {
        $bank = $this->definition(ActivityDefinition::BANK_COOP_CHECK_CODE);

        // 1-2. Adding with zero targets is refused as intended; no parent is created.
        $this->actingAs($this->ci)->postJson(route('client-folders.activities.store', $this->folder), ['activity_definition_id' => $bank->id, 'create_new_activity_type' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['bank_targets' => 'Add at least one Bank / Coop target.']);
        $this->assertSame(0, CiActivity::query()->count());

        // 3-5. The Add Activity page still offers Bank / Coop Check with its target entry; a real add works.
        $page = $this->actingAs($this->ci)->get(route('client-folders.activities.index', $this->folder))->assertOk()->getContent();
        $this->assertStringNotContainsString('Bank/Coop Check<span class="ml-auto pl-3 text-xs font-normal">Already Added', $page);
        $this->assertStringContainsString('Add Another Bank / Coop', $page);
        $this->addBank($bank, 'BDO')->assertRedirect();
        $activity = CiActivity::query()->sole();
        $this->assertSame(1, $activity->bankTargets()->count());

        // 6-7. After a reload the target is there and editable in the tracker.
        $tracker = route('client-folders.activities.bank-coop.show', [$this->folder, $activity]);
        $this->get($tracker)->assertOk()->assertSee('BDO')->assertSee('Add Bank / Coop');

        // 8-10. Removing the last target keeps the activity open with its Add control.
        $this->delete(route('client-folders.activities.bank-targets.destroy', [$this->folder, $activity, $activity->bankTargets()->sole()]), ['co_maker_id' => ''])->assertRedirect();
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status, 'Zero targets never reads as complete.');
        $this->get($tracker)->assertOk()->assertSee('No Bank / Coop targets yet.')->assertSee('Add Bank / Coop');
        $this->post(route('client-folders.activities.bank-targets.store', [$this->folder, $activity]), [
            'co_maker_id' => '', 'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => 'Recovered Bank', 'status' => ActivityStatus::Completed->value,
        ])->assertRedirect();
        $this->assertSame(['Recovered Bank'], $activity->bankTargets()->pluck('institution_name')->all());
        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);

        // No duplicate parent: the type now shows Already Added, and a forced Add is only an advisory.
        $page = $this->get(route('client-folders.activities.index', $this->folder))->getContent();
        $this->assertMatchesRegularExpression('/data-code="'.ActivityDefinition::BANK_COOP_CHECK_CODE.'"[^>]*disabled[^>]*>Bank\/Coop Check<span[^>]*>Already Added/', $page);
        $this->addBank($bank, 'Second Bank', json: true)->assertStatus(409)->assertJsonPath('result', 'duplicate_exists');
        $this->assertSame(1, CiActivity::query()->where('activity_definition_id', $bank->id)->count());
    }

    public function test_asset_lifecycle_zero_targets_first_target_remove_last_and_recover(): void
    {
        $asset = $this->definition(ActivityDefinition::ASSET_CHECK_CODE);

        $this->actingAs($this->ci)->postJson(route('client-folders.activities.store', $this->folder), ['activity_definition_id' => $asset->id, 'create_new_activity_type' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['asset_targets' => 'Add at least one assessor target.']);
        $this->assertSame(0, CiActivity::query()->count());

        $this->assertStringContainsString('Add Assessor', $this->get(route('client-folders.activities.index', $this->folder))->getContent());
        $this->addAsset($asset, 'City Hall')->assertRedirect();
        $activity = CiActivity::query()->sole();

        $tracker = route('client-folders.activities.asset-check.show', [$this->folder, $activity]);
        $this->get($tracker)->assertOk()->assertSee('City Hall')->assertSee('Add Assessor');

        $this->delete(route('client-folders.activities.asset-targets.destroy', [$this->folder, $activity, $activity->assetTargets()->sole()]), ['co_maker_id' => ''])->assertRedirect();
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
        $this->get($tracker)->assertOk()->assertSee('Add Assessor');
        $this->post(route('client-folders.activities.asset-targets.store', [$this->folder, $activity]), [
            'co_maker_id' => '', 'assessor_type' => 'city_assessor', 'office_location' => 'Recovered Office', 'status' => ActivityStatus::Pending->value,
        ])->assertRedirect();
        $this->assertSame(['Recovered Office'], $activity->assetTargets()->pluck('office_location')->all());

        $this->addAsset($asset, 'Second Office', json: true)->assertStatus(409)->assertJsonPath('result', 'duplicate_exists');
        $this->assertSame(1, CiActivity::query()->where('activity_definition_id', $asset->id)->count());
    }

    public function test_applicant_and_each_co_maker_keep_their_own_bank_and_asset_checks(): void
    {
        $bank = $this->definition(ActivityDefinition::BANK_COOP_CHECK_CODE);
        $asset = $this->definition(ActivityDefinition::ASSET_CHECK_CODE);
        $makerA = CoMaker::create(['client_folder_id' => $this->folder->id, 'full_name' => 'Same Name', 'first_name' => 'Same', 'last_name' => 'Name']);
        $makerB = CoMaker::create(['client_folder_id' => $this->folder->id, 'full_name' => 'Same Name', 'first_name' => 'Same', 'last_name' => 'Name']);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id]);

        $this->addBank($bank, 'Applicant Bank')->assertRedirect();
        $this->addAsset($asset, 'Applicant Office')->assertRedirect();
        $this->addBank($bank, 'Maker A Bank', $makerA->id)->assertRedirect();

        // Maker B is untouched by A and by the Applicant, and can add its own.
        $pageB = $this->get(route('client-folders.activities.index', [$this->folder, 'person' => 'co-maker', 'co_maker_id' => $makerB->id]))->getContent();
        $this->assertStringNotContainsString('Already Added', $pageB);
        $this->addBank($bank, 'Maker B Bank', $makerB->id)->assertRedirect();
        $this->addAsset($asset, 'Maker B Office', $makerB->id)->assertRedirect();

        $banks = CiActivity::query()->where('activity_definition_id', $bank->id)->with('bankTargets')->get()->mapWithKeys(fn (CiActivity $a) => [(string) $a->co_maker_id => $a->bankTargets->pluck('institution_name')->all()]);
        $this->assertSame(['' => ['Applicant Bank'], (string) $makerA->id => ['Maker A Bank'], (string) $makerB->id => ['Maker B Bank']], $banks->all());
        $this->assertSame(2, CiActivity::query()->where('activity_definition_id', $asset->id)->count());

        // A target cannot be substituted across person, activity or folder.
        $applicantActivity = CiActivity::query()->where('activity_definition_id', $bank->id)->whereNull('co_maker_id')->sole();
        $makerAActivity = CiActivity::query()->where('activity_definition_id', $bank->id)->where('co_maker_id', $makerA->id)->sole();
        $this->delete(route('client-folders.activities.bank-targets.destroy', [$this->folder, $applicantActivity, $makerAActivity->bankTargets()->sole()]), ['co_maker_id' => ''])->assertNotFound();
        $this->delete(route('client-folders.activities.bank-targets.destroy', [$otherFolder, $applicantActivity, $applicantActivity->bankTargets()->sole()]), ['co_maker_id' => ''])->assertNotFound();
        $this->assertSame(3, CiActivityBankTarget::query()->count());
    }

    public function test_stale_target_revisions_are_still_refused_and_progress_rules_are_unchanged(): void
    {
        $bank = $this->definition(ActivityDefinition::BANK_COOP_CHECK_CODE);
        $this->addBank($bank, 'Revision Bank')->assertRedirect();
        $activity = CiActivity::query()->sole();
        $target = $activity->bankTargets()->sole();
        $opened = $target->revision;
        $payload = ['co_maker_id' => '', 'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'status' => ActivityStatus::Pending->value];

        $this->put(route('client-folders.activities.bank-targets.update', [$this->folder, $activity, $target]), $payload + ['institution_name' => 'First Save', 'expected_revision' => $opened])->assertRedirect()->assertSessionHasNoErrors();
        $this->putJson(route('client-folders.activities.bank-targets.update', [$this->folder, $activity, $target]), $payload + ['institution_name' => 'Stale Save', 'expected_revision' => $opened])->assertStatus(409);
        $this->assertSame('First Save', $target->fresh()->institution_name);

        // Applicant: Bank / Coop mandatory, Asset optional. Co-Maker: exactly the four.
        $this->assertArrayHasKey('bank_coop_check', MandatoryInvestigationRequirements::APPLICANT);
        $this->assertArrayNotHasKey('asset_check', MandatoryInvestigationRequirements::APPLICANT);
        $this->assertSame(['cibi', 'residence_check', 'barangay_check', 'neighbor_check'], array_keys(MandatoryInvestigationRequirements::CO_MAKER));
        $this->assertCount(7, MandatoryInvestigationRequirements::APPLICANT);
        $this->assertSame(0, AuditLog::query()->where('action', 'like', '%asset%')->count());
    }

    private function addBank(ActivityDefinition $bank, string $name, ?int $coMakerId = null, bool $json = false)
    {
        $payload = ['co_maker_id' => $coMakerId, 'activity_definition_id' => $bank->id, 'create_new_activity_type' => false, 'bank_targets' => [[
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => $name, 'branch_location' => null, 'status' => ActivityStatus::Pending->value, 'scheduled_at' => null, 'scheduled_time' => null, 'remarks' => null,
        ]]];

        return $json
            ? $this->actingAs($this->ci)->postJson(route('client-folders.activities.store', $this->folder), $payload)
            : $this->actingAs($this->ci)->post(route('client-folders.activities.store', $this->folder), $payload);
    }

    private function addAsset(ActivityDefinition $asset, string $location, ?int $coMakerId = null, bool $json = false)
    {
        $payload = ['co_maker_id' => $coMakerId, 'activity_definition_id' => $asset->id, 'create_new_activity_type' => false, 'asset_targets' => [[
            'assessor_type' => 'city_assessor', 'office_location' => $location, 'status' => ActivityStatus::Pending->value, 'scheduled_at' => null, 'scheduled_time' => null, 'remarks' => null,
        ]]];

        return $json
            ? $this->actingAs($this->ci)->postJson(route('client-folders.activities.store', $this->folder), $payload)
            : $this->actingAs($this->ci)->post(route('client-folders.activities.store', $this->folder), $payload);
    }

    private function definition(string $code): ActivityDefinition
    {
        return ActivityDefinition::query()->where('code', $code)->sole();
    }
}
