<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\Contracts\HasCiParticipants;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 data-foundation coverage for multi-CI participants on Business Report (IncomeSource),
 * Business Check, and Residence Check. No UI/report-output behavior is exercised here — only
 * the CiParticipantService and the underlying schema/model contracts.
 */
class CiParticipantFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function participants(): CiParticipantService
    {
        return app(CiParticipantService::class);
    }

    private function folder(User $creator): ClientFolder
    {
        return ClientFolder::factory()->create(['created_by' => $creator->id, 'assigned_ci_id' => $creator->id]);
    }

    private function incomeSource(ClientFolder $folder, ?User $creator, ?CoMaker $coMaker = null): IncomeSource
    {
        $template = IncomeSourceTemplate::query()->where('is_active', true)->firstOrFail();

        return $folder->incomeSources()->create([
            'co_maker_id' => $coMaker?->id,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Test Source',
            'created_by' => $creator?->id,
        ]);
    }

    private function businessCheck(ClientFolder $folder, IncomeSource $source, User $ciUser, ?CoMaker $coMaker = null): BusinessCheck
    {
        return $folder->businessChecks()->create([
            'co_maker_id' => $coMaker?->id,
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $ciUser->id,
        ]);
    }

    private function residenceCheck(ClientFolder $folder, User $ciUser, ?CoMaker $coMaker = null): ResidenceCheck
    {
        return $folder->residenceChecks()->create([
            'co_maker_id' => $coMaker?->id,
            'ci_date' => now()->toDateString(),
            'ci_user_id' => $ciUser->id,
        ]);
    }

    public function test_business_report_primary_participant_is_the_income_source_creator(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        $this->assertSame([$creator->id], $this->participants()->orderedParticipantIds($source));
    }

    public function test_business_check_primary_participant_is_ci_user_id(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);
        $check = $this->businessCheck($folder, $source, $creator);

        $this->assertSame([$creator->id], $this->participants()->orderedParticipantIds($check));
    }

    public function test_residence_check_primary_participant_is_ci_user_id(): void
    {
        $creator = User::factory()->create();
        $folder = $this->folder($creator);
        $check = $this->residenceCheck($folder, $creator);

        $this->assertSame([$creator->id], $this->participants()->orderedParticipantIds($check));
    }

    public function test_primary_participant_cannot_be_removed_via_companion_sync(): void
    {
        $creator = User::factory()->create();
        $companion = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        // Even if a forged/incomplete request submits the primary's own id as a "companion",
        // it must not end up duplicated in the pivot or removable from the effective list.
        $this->participants()->syncCompanions($source, [$creator->id, $companion->id]);

        $this->assertSame([$creator->id, $companion->id], $this->participants()->orderedParticipantIds($source));
        $this->assertSame([$companion->id], $source->contributors()->pluck('users.id')->all());

        // Sync away every companion — primary is still there because it was never in the pivot.
        $this->participants()->syncCompanions($source, []);
        $this->assertSame([$creator->id], $this->participants()->orderedParticipantIds($source));
    }

    public function test_companion_participant_can_be_removed(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        $this->participants()->syncCompanions($source, [$a->id, $b->id]);
        $this->participants()->syncCompanions($source, [$a->id]);

        $this->assertSame([$creator->id, $a->id], $this->participants()->orderedParticipantIds($source));
    }

    public function test_multiple_companions_preserve_caller_defined_order_after_primary(): void
    {
        $creator = User::factory()->create();
        $mark = User::factory()->create();
        $yong = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        // Submitted out of id order on purpose — the service must not re-sort by id/name.
        $this->participants()->syncCompanions($source, [$yong->id, $mark->id]);

        $this->assertSame([$creator->id, $yong->id, $mark->id], $this->participants()->orderedParticipantIds($source));
    }

    public function test_duplicate_user_ids_are_deduplicated(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        $this->participants()->syncCompanions($source, [$a->id, $a->id, $a->id]);

        $this->assertSame([$creator->id, $a->id], $this->participants()->orderedParticipantIds($source));
        $this->assertSame(1, $source->contributors()->count());
    }

    public function test_database_prevents_duplicate_participant_row_for_same_owner_and_user(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);
        $source->contributors()->attach($a->id, ['position' => 1]);

        $this->expectException(QueryException::class);
        DB::table('income_source_contributors')->insert([
            'income_source_id' => $source->id, 'user_id' => $a->id, 'position' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_different_business_reports_have_independent_participant_lists(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $folder = $this->folder($creator);
        $sourceOne = $this->incomeSource($folder, $creator);
        $sourceTwo = $this->incomeSource($folder, $creator);

        $this->participants()->syncCompanions($sourceOne, [$a->id]);
        $this->participants()->syncCompanions($sourceTwo, [$b->id]);

        $this->assertSame([$creator->id, $a->id], $this->participants()->orderedParticipantIds($sourceOne->fresh()));
        $this->assertSame([$creator->id, $b->id], $this->participants()->orderedParticipantIds($sourceTwo->fresh()));
    }

    public function test_applicant_and_co_maker_business_report_participants_are_isolated(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $folder = $this->folder($creator);
        $coMaker = $folder->coMakers()->create(['full_name' => 'CO MAKER PERSON']);
        $applicantSource = $this->incomeSource($folder, $creator);
        $coMakerSource = $this->incomeSource($folder, $creator, $coMaker);

        $this->participants()->syncCompanions($applicantSource, [$a->id]);
        $this->participants()->syncCompanions($coMakerSource, [$b->id]);

        $this->assertSame([$creator->id, $a->id], $this->participants()->orderedParticipantIds($applicantSource->fresh()));
        $this->assertSame([$creator->id, $b->id], $this->participants()->orderedParticipantIds($coMakerSource->fresh()));
    }

    public function test_residence_applicant_and_co_maker_participants_are_isolated(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $folder = $this->folder($creator);
        $coMaker = $folder->coMakers()->create(['full_name' => 'CO MAKER PERSON']);
        $applicantCheck = $this->residenceCheck($folder, $creator);
        $coMakerCheck = $this->residenceCheck($folder, $creator, $coMaker);

        $this->participants()->syncCompanions($applicantCheck, [$a->id]);
        $this->participants()->syncCompanions($coMakerCheck, [$b->id]);

        $this->assertSame([$creator->id, $a->id], $this->participants()->orderedParticipantIds($applicantCheck->fresh()));
        $this->assertSame([$creator->id, $b->id], $this->participants()->orderedParticipantIds($coMakerCheck->fresh()));
    }

    public function test_legacy_income_source_without_created_by_falls_back_to_folder_creator(): void
    {
        $folderCreator = User::factory()->create();
        $folder = $this->folder($folderCreator);
        $legacySource = $this->incomeSource($folder, null);

        $this->assertNull($legacySource->created_by);
        $this->assertSame([$folderCreator->id], $this->participants()->orderedParticipantIds($legacySource));
    }

    public function test_updating_user_does_not_automatically_become_participant(): void
    {
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        $source->last_edited_by = $editor->id;
        $source->save();

        $this->assertSame([$creator->id], $this->participants()->orderedParticipantIds($source->fresh()));
    }

    public function test_sync_result_identifies_added_and_removed_participants(): void
    {
        $creator = User::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        $this->participants()->syncCompanions($source, [$a->id, $b->id]);
        $result = $this->participants()->syncCompanions($source, [$b->id, $c->id]);

        $this->assertSame([$c->id], $result->added);
        $this->assertSame([$a->id], $result->removed);
        $this->assertSame([$creator->id, $a->id, $b->id], $result->before);
        $this->assertSame([$creator->id, $b->id, $c->id], $result->after);
    }

    public function test_full_and_first_name_formatters_preserve_participant_order(): void
    {
        $creator = User::factory()->create(['full_name' => 'REYNALDO J. OBASA']);
        $mark = User::factory()->create(['full_name' => 'MARK S. DELA CRUZ']);
        $folder = $this->folder($creator);
        $source = $this->incomeSource($folder, $creator);

        $this->participants()->syncCompanions($source, [$mark->id]);

        $this->assertSame('REYNALDO J. OBASA / MARK S. DELA CRUZ', $this->participants()->fullNames($source));
        $this->assertSame('REYNALDO / MARK', $this->participants()->firstNames($source));
    }

    public function test_cibi_remains_single_ci_and_has_no_participant_relation(): void
    {
        $this->assertNotContains(HasCiParticipants::class, class_implements(CibiReport::class));
        $this->assertFalse(Schema::hasTable('cibi_report_contributors'));
    }
}
