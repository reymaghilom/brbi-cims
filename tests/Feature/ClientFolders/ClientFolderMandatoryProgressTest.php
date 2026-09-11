<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Actions\ClientFolders\RemoveCoMaker;
use App\Actions\ClientFolders\SaveCiActivityBankTarget;
use App\Actions\ClientFolders\SaveCoMaker;
use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Progress\ClientProgressService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Client Folder progress/status = completed mandatory requirements / applicable mandatory
 * requirements: the Applicant's 7 (CIBI, Business Report, Residence Check, Business Check, Barangay,
 * Neighbor, Bank / Coop) plus 4 per existing Co-Maker (CIBI, Residence Check, Barangay, Neighbor).
 * Asset Check and the Co-Maker's business/bank/asset work never count. 100% is the only Completed.
 */
class ClientFolderMandatoryProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        $this->ci = User::factory()->create();
    }

    public function test_applicant_progress_counts_seven_mandatory_requirements(): void
    {
        $folder = $this->folder();
        $this->assertProgress($folder, 0, ClientFolderStatus::OnProgress);

        $this->cibi($folder, null);
        $this->assertProgress($folder, 14.29, ClientFolderStatus::OnProgress);

        // Auto-generated-style Pending Barangay / Neighbor do not count.
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Scheduled);
        $this->assertProgress($folder, 14.29, ClientFolderStatus::OnProgress);
        $this->assertSame(7, $this->requirementCount($folder));
    }

    public function test_all_seven_complete_is_completed_whether_asset_check_is_pending_or_absent(): void
    {
        $folder = $this->completeFolder();
        $this->assertProgress($folder, 100, ClientFolderStatus::Completed);

        $this->activity($folder, ActivityDefinition::ASSET_CHECK_CODE, ActivityStatus::Pending);
        $this->assertProgress($folder, 100, ClientFolderStatus::Completed);
    }

    public function test_each_missing_applicant_requirement_keeps_the_folder_below_one_hundred(): void
    {
        $breakers = [
            'CIBI' => fn (ClientFolder $folder) => CibiReport::query()->where('client_folder_id', $folder->id)->delete(),
            'Business Report' => fn (ClientFolder $folder) => BusinessReport::query()->whereIn('income_source_id', $folder->incomeSources()->pluck('id'))->delete(),
            'Residence Check' => fn (ClientFolder $folder) => ResidenceCheck::query()->where('client_folder_id', $folder->id)->delete(),
            'Business Check' => fn (ClientFolder $folder) => BusinessCheck::query()->where('client_folder_id', $folder->id)->delete(),
            'Barangay Pending' => fn (ClientFolder $folder) => $this->setStatus($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending),
            'Neighbor Pending' => fn (ClientFolder $folder) => $this->setStatus($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending),
            'Bank / Coop Follow-up' => fn (ClientFolder $folder) => $this->setStatus($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::FollowUp),
        ];

        foreach ($breakers as $label => $break) {
            $folder = $this->completeFolder();
            $break($folder);
            $this->assertProgress($folder, 85.71, ClientFolderStatus::OnProgress, "Missing {$label}");
        }
    }

    public function test_each_co_maker_adds_exactly_four_requirements_and_only_those(): void
    {
        $folder = $this->completeFolder();
        $coMaker = $this->coMaker($folder, 'Co-Maker A');
        $this->assertProgress($folder, 63.64, ClientFolderStatus::OnProgress); // 7 / 11
        $this->assertSame(11, $this->requirementCount($folder));

        $this->completePerson($folder, $coMaker->id);
        // No Co-Maker Business Report, Business Check, Bank / Coop or Asset Check exists.
        $this->assertFalse(IncomeSource::query()->where('co_maker_id', $coMaker->id)->exists());
        $this->assertFalse(BusinessCheck::query()->where('co_maker_id', $coMaker->id)->exists());
        $this->assertProgress($folder, 100, ClientFolderStatus::Completed); // 11 / 11

        $this->setStatus($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending, $coMaker->id);
        $this->assertProgress($folder, 90.91, ClientFolderStatus::OnProgress); // 10 / 11
    }

    public function test_multiple_co_makers_are_separate_and_never_satisfy_each_other_or_the_applicant(): void
    {
        $folder = $this->completeFolder();
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        $this->completePerson($folder, $coMakerA->id);
        $this->completePerson($folder, $coMakerB->id);
        $this->assertProgress($folder, 100, ClientFolderStatus::Completed); // 15 / 15

        // Co-Maker B loses their Residence Check; A's and the Applicant's do not stand in for it.
        ResidenceCheck::query()->where('co_maker_id', $coMakerB->id)->delete();
        $this->assertProgress($folder, 93.33, ClientFolderStatus::OnProgress); // 14 / 15

        // A Co-Maker's complete CIBI never satisfies the Applicant's.
        (new ResidenceCheck)->forceFill(['client_folder_id' => $folder->id, 'co_maker_id' => $coMakerB->id, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id])->save();
        CibiReport::query()->where('client_folder_id', $folder->id)->whereNull('co_maker_id')->delete();
        $this->assertProgress($folder, 93.33, ClientFolderStatus::OnProgress);
    }

    public function test_barangay_and_neighbor_status_changes_refresh_the_stored_progress(): void
    {
        $folder = $this->completeFolder();
        $barangay = $this->activityQuery($folder, ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        $barangay->update(['status' => ActivityStatus::Pending, 'completed_at' => null]);
        $url = route('client-folders.activities.update', [$folder, $barangay]);

        $this->actingAs($this->ci)->putJson($url, ['co_maker_id' => null, 'status' => ActivityStatus::Completed->value])->assertOk();
        $this->assertStored($folder, 100, ClientFolderStatus::Completed);

        $this->putJson($url, ['co_maker_id' => null, 'status' => ActivityStatus::Pending->value])->assertOk();
        $this->assertStored($folder, 85.71, ClientFolderStatus::OnProgress);
    }

    public function test_residence_check_save_and_delete_refresh_the_stored_progress(): void
    {
        $folder = $this->completeFolder();
        ResidenceCheck::query()->where('client_folder_id', $folder->id)->delete();

        $this->actingAs($this->ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'remarks' => 'Residence visited.',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $this->assertStored($folder, 100, ClientFolderStatus::Completed);

        app(DeleteResidenceCheck::class)->execute($this->ci, $folder, $folder->residenceChecks()->sole());
        $this->assertStored($folder, 85.71, ClientFolderStatus::OnProgress);
    }

    public function test_business_check_save_refreshes_the_stored_progress(): void
    {
        $folder = $this->completeFolder();
        BusinessCheck::query()->where('client_folder_id', $folder->id)->delete();

        $this->actingAs($this->ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $folder->incomeSources()->value('id'),
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertStored($folder, 100, ClientFolderStatus::Completed);
    }

    public function test_bank_coop_target_completion_refreshes_the_stored_progress(): void
    {
        $folder = $this->completeFolder();
        $bank = $this->activityQuery($folder, ActivityDefinition::BANK_COOP_CHECK_CODE)->sole();
        $bank->update(['status' => ActivityStatus::Pending, 'completed_at' => null]);
        $target = $bank->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK, 'institution_name' => 'BDO',
            'status' => ActivityStatus::Pending, 'scheduled_has_time' => false, 'created_by' => $this->ci->id, 'updated_by' => $this->ci->id,
        ]);
        app(ClientProgressService::class)->recalculate($folder);
        $this->assertStored($folder, 85.71, ClientFolderStatus::OnProgress);

        app(SaveCiActivityBankTarget::class)->complete($this->ci, $folder, $bank, $target);

        $this->assertSame(ActivityStatus::Completed, $bank->fresh()->status);
        $this->assertStored($folder, 100, ClientFolderStatus::Completed);
    }

    public function test_adding_and_removing_a_co_maker_refreshes_the_stored_progress(): void
    {
        $folder = $this->completeFolder();
        app(ClientProgressService::class)->recalculate($folder);
        $this->assertStored($folder, 100, ClientFolderStatus::Completed);

        $coMaker = app(SaveCoMaker::class)->execute($this->ci, $folder, ['first_name' => 'Maria', 'last_name' => 'Santos']);
        $this->assertStored($folder, 63.64, ClientFolderStatus::OnProgress);

        app(RemoveCoMaker::class)->execute($this->ci, $folder, $coMaker);
        $this->assertStored($folder, 100, ClientFolderStatus::Completed);
    }

    private function requirementCount(ClientFolder $folder): int
    {
        $result = app(ClientProgressService::class)->calculate($folder);

        return count($result->completed) + count($result->incomplete);
    }

    private function assertProgress(ClientFolder $folder, float $percentage, ClientFolderStatus $status, string $message = ''): void
    {
        app(ClientProgressService::class)->recalculate($folder);
        $this->assertStored($folder, $percentage, $status, $message);
    }

    private function assertStored(ClientFolder $folder, float $percentage, ClientFolderStatus $status, string $message = ''): void
    {
        $folder->refresh();
        $this->assertEquals($percentage, (float) $folder->progress_percent, $message);
        $this->assertSame($status, $folder->status, $message);
        $this->assertSame($status === ClientFolderStatus::Completed, $folder->completed_at !== null, $message);
    }

    private function folder(): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
    }

    private function completeFolder(): ClientFolder
    {
        $folder = $this->folder();
        $this->completePerson($folder, null);

        $template = IncomeSourceTemplate::query()->where('template_type', 'business_source_validation')->where('version', 1)->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_template_id' => $template->id,
            'state' => RecordState::Complete, 'revision' => 2,
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => 'Sari-sari Store', 'report_category' => 'retail'])->save();
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
        $this->activity($folder, ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityStatus::Completed);

        return $folder;
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name]);
    }

    private function completePerson(ClientFolder $folder, ?int $coMakerId): void
    {
        $this->cibi($folder, $coMakerId);
        (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_date' => now()->toDateString(), 'ci_user_id' => $this->ci->id,
        ])->save();
        $this->activity($folder, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Completed, $coMakerId);
        $this->activity($folder, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Completed, $coMakerId);
    }

    private function cibi(ClientFolder $folder, ?int $coMakerId): void
    {
        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Complete,
        ]);
    }

    private function activity(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $definition->id,
            'name' => $definition->name, 'status' => $status, 'scheduled_at' => $status === ActivityStatus::Scheduled ? now()->addDay() : null,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null, 'creator_id' => $this->ci->id,
        ]);
    }

    private function activityQuery(ClientFolder $folder, string $code, ?int $coMakerId = null)
    {
        return CiActivity::query()->where('client_folder_id', $folder->id)->where('co_maker_id', $coMakerId)
            ->whereHas('definition', fn ($query) => $query->where('code', $code));
    }

    private function setStatus(ClientFolder $folder, string $code, ActivityStatus $status, ?int $coMakerId = null): void
    {
        $this->activityQuery($folder, $code, $coMakerId)->sole()->update([
            'status' => $status,
            'scheduled_at' => $status === ActivityStatus::Scheduled ? now()->addDay() : null,
            'completed_at' => $status === ActivityStatus::Completed ? now() : null,
        ]);
    }
}
