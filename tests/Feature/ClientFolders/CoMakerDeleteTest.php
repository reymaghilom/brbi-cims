<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Enums\ActivityStatus;
use App\Enums\UserRole;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\ResidenceCheckPhoto;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderEditingPresence;
use App\Services\Media\ClientMediaUploader;
use App\Support\ClientFolders\MissingCoMakerResponse;
use Closure;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Permanent Co-Maker delete, scoped to one exact Co-Maker (client_folder_id + co_maker_id): a Credit
 * Investigator may only delete a Co-Maker that is still empty; a Senior CI or Administrator may also
 * delete one with saved investigation records (unlike a Client Folder, where a Senior CI is still
 * limited to empty folders); and another user's live unsaved/saving work on that Co-Maker blocks
 * every role. Users left on a stale
 * Co-Maker page are answered gracefully and nothing is ever written to the Applicant or another
 * Co-Maker. Storage is faked, the CI Team root is a throwaway directory, Cloudinary is mocked.
 */
class CoMakerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const HAS_SAVED_RECORDS = 'This Co-Maker already has saved records. Only a Senior CI or Administrator can delete it.';

    private string $documentsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->documentsRoot = storage_path('framework/testing/ci-team-co-maker-'.uniqid());
        File::ensureDirectoryExists($this->documentsRoot);
        config(['cims.documents_root' => $this->documentsRoot, 'cims.media_disk' => 'local', 'cims.report_disk' => 'local']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->documentsRoot);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Role matrix

    public function test_every_role_can_delete_an_empty_co_maker_even_after_identity_edits(): void
    {
        foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create(), User::factory()->administrator()->create()] as $actor) {
            $folder = ClientFolder::factory()->create();
            $this->actingAs($actor)->postJson(route('client-folders.co-maker.store', $folder), ['first_name' => 'Empty', 'last_name' => 'Maker'])->assertOk();
            $coMaker = CoMaker::query()->where('client_folder_id', $folder->id)->sole();
            $this->actingAs($actor)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMaker->id, 'expected_revision' => $coMaker->fresh()?->revision ?? $coMaker->revision, 'first_name' => 'Renamed', 'last_name' => 'Maker'])->assertOk();

            $this->assertRemoved($actor, $folder, $coMaker);
        }
    }

    /** @return array<string, array{0: Closure(ClientFolder, CoMaker, User): void}> */
    public static function coMakerWork(): array
    {
        return [
            'CI/BI Report' => [fn (ClientFolder $f, CoMaker $c, User $u) => CibiReport::factory()->create(['client_folder_id' => $f->id, 'co_maker_id' => $c->id, 'ci_in_charge_id' => $u->id])],
            'Residence Check' => [fn (ClientFolder $f, CoMaker $c, User $u) => app(SaveResidenceCheck::class)->execute($u, $f, ['check_id' => null, 'co_maker_id' => $c->id, 'ci_date' => '2026-09-02', 'location' => 'Address'])],
            'Barangay Check' => [fn (ClientFolder $f, CoMaker $c, User $u) => self::activity($f, $u, ActivityDefinition::BARANGAY_CHECK_CODE, $c->id)],
            'Neighbor Check' => [fn (ClientFolder $f, CoMaker $c, User $u) => self::activity($f, $u, ActivityDefinition::NEIGHBOR_CHECK_CODE, $c->id)],
            'other CI Activity' => [fn (ClientFolder $f, CoMaker $c, User $u) => self::activity($f, $u, ActivityDefinition::BANK_COOP_CHECK_CODE, $c->id)],
            'Supporting Proof / media' => [fn (ClientFolder $f, CoMaker $c, User $u) => MediaReference::factory()->create(['client_folder_id' => $f->id, 'co_maker_id' => $c->id, 'uploaded_by' => $u->id])],
            'history only (work later removed)' => [function (ClientFolder $f, CoMaker $c, User $u): void {
                AuditLog::create(['user_id' => $u->id, 'client_folder_id' => $f->id, 'action' => 'ci_activity.updated', 'module' => 'ci_activities', 'description' => 'Saved.', 'metadata' => ['activity_id' => 999, 'co_maker_id' => $c->id]]);
            }],
        ];
    }

    #[DataProvider('coMakerWork')]
    public function test_exact_co_maker_work_blocks_a_credit_investigator_but_a_senior_ci_deletes_only_that_co_maker(Closure $saveWork): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Twin Name']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Other Maker']);
        $otherFolder = ClientFolder::factory()->create();
        $twinElsewhere = CoMaker::create(['client_folder_id' => $otherFolder->id, 'full_name' => 'Twin Name']);
        $saveWork($folder, $coMakerA, $ci);

        // Applicant and Co-Maker B work never makes A non-empty (and is never touched below).
        $applicantActivity = self::activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, null);
        $bActivity = self::activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $coMakerB->id);
        $twinActivity = self::activity($otherFolder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $twinElsewhere->id);
        $before = $this->ownedCounts($folder, $coMakerA);

        // Credit Investigator — including a forged direct DELETE — is refused.
        $this->actingAs($ci)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMakerA]))
            ->assertStatus(422)->assertExactJson(['message' => self::HAS_SAVED_RECORDS]);
        $this->actingAs($ci)->delete(route('client-folders.co-maker.destroy', [$folder, $coMakerA]))
            ->assertRedirect(route('client-folders.show', $folder))->assertSessionHas('status', self::HAS_SAVED_RECORDS);
        $this->assertSame($before, $this->ownedCounts($folder, $coMakerA), 'A refused delete changes nothing.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'co_maker.removed']);

        // Senior CI: may delete a Co-Maker with saved investigation records (unlike a Client Folder).
        $senior = User::factory()->seniorCreditInvestigator()->create();
        $this->assertRemoved($senior, $folder, $coMakerA);

        $this->assertSame(array_fill_keys(array_keys($before), 0), $this->ownedCounts($folder, $coMakerA), 'Only the exact Co-Maker graph is removed.');
        $this->assertNotNull($applicantActivity->fresh());
        $this->assertNull($applicantActivity->fresh()->co_maker_id);
        $this->assertSame($coMakerB->id, $bActivity->fresh()->co_maker_id);
        $this->assertNotNull($coMakerB->fresh());
        $this->assertNotNull($twinElsewhere->fresh());
        $this->assertNotNull($twinActivity->fresh());
        $audit = AuditLog::query()->where('action', 'co_maker.removed')->sole();
        $this->assertSame([$folder->id, $coMakerA->id, 'Twin Name', $senior->id, true], [$audit->client_folder_id, $audit->metadata['co_maker_id'], $audit->metadata['full_name'], $audit->metadata['deleted_by'], $audit->metadata['had_saved_records']]);
    }

    public function test_administrator_deletes_a_non_empty_co_maker_and_senior_ci_still_cannot_delete_a_non_empty_client_folder(): void
    {
        $ci = User::factory()->create();
        $senior = User::factory()->seniorCreditInvestigator()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $applicantActivity = self::activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, null);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Admin Target']);
        self::activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE, $coMaker->id);

        $this->assertRemoved($admin, $folder, $coMaker);
        $this->assertNotNull($applicantActivity->fresh());

        // Separate rule: a Senior CI may NOT permanently delete a non-empty Applicant / Client Folder.
        $this->actingAs($senior)->deleteJson(route('client-folders.destroy', $folder))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This Client Folder can no longer be deleted because it already contains saved records.']);
        $this->assertNotNull($folder->fresh());
        $this->assertNotNull($applicantActivity->fresh());
    }

    public function test_another_users_work_on_a_non_empty_co_maker_blocks_a_senior_ci(): void
    {
        $editor = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $senior = User::factory()->seniorCreditInvestigator()->create();
        $presence = app(ClientFolderEditingPresence::class);

        foreach (['dirty', 'saving'] as $state) {
            $folder = ClientFolder::factory()->create();
            $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Busy Non Empty']);
            $activity = self::activity($folder, $editor, ActivityDefinition::BARANGAY_CHECK_CODE, $coMaker->id);
            $presence->record($folder->id, 'ci_activity', $activity->id, $editor, $state, $coMaker->id);

            $this->actingAs($senior)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker]))
                ->assertStatus(422)
                ->assertExactJson(['message' => 'This Co-Maker cannot be deleted because Juan Dela Cruz is currently working on it. Please try again after they finish.']);
            $this->assertNotNull($coMaker->fresh());
            $this->assertNotNull($activity->fresh());
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'co_maker.removed']);
    }

    public function test_another_co_makers_or_the_applicants_work_never_makes_a_co_maker_non_empty(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $emptyA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Empty A']);
        $busyB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Busy B']);
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $busyB->id, 'ci_in_charge_id' => $ci->id]);
        app(SaveResidenceCheck::class)->execute($ci, $folder, ['check_id' => null, 'co_maker_id' => null, 'ci_date' => '2026-09-02', 'location' => 'Applicant']);

        $this->actingAs($ci)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $busyB]))->assertStatus(422);
        $this->assertRemoved($ci, $folder, $emptyA);
    }

    public function test_senior_ci_delete_retires_only_the_exact_co_makers_files_after_commit(): void
    {
        // Senior CI and Administrator share the same Co-Maker purge; exercised here as a Senior CI.
        $admin = User::factory()->seniorCreditInvestigator()->create();
        $folder = ClientFolder::factory()->create();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Files Maker']);
        $other = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Kept Maker']);
        $disk = Storage::disk('local');
        foreach (['own', 'shared', 'applicant', 'other', 'residence'] as $name) {
            $disk->put("client-media/{$name}.jpg", 'x');
        }
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/own.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/shared.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/shared.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/applicant.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $other->id, 'uploaded_by' => $admin->id, 'temporary_local_path' => 'client-media/other.jpg']);
        MediaReference::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id, 'uploaded_by' => $admin->id, 'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY, 'cloudinary_public_id' => 'proofs/co-maker', 'cloudinary_resource_type' => 'image']);
        $check = app(SaveResidenceCheck::class)->execute($admin, $folder, ['check_id' => null, 'co_maker_id' => $coMaker->id, 'ci_date' => '2026-09-02', 'location' => 'Address']);
        ResidenceCheckPhoto::query()->create(['residence_check_id' => $check->id, 'file_name' => 'r.jpg', 'path' => 'client-media/residence.jpg', 'uploaded_by' => $admin->id]);

        $retired = [];
        $this->mock(ClientMediaUploader::class, function (MockInterface $mock) use (&$retired, $coMaker): void {
            $mock->shouldReceive('retireCloudAsset')->andReturnUsing(function (?string $publicId, ?string $type, ?string $delivery) use (&$retired, $coMaker): void {
                $this->assertNull(CoMaker::query()->find($coMaker->id), 'Files are retired only after the delete committed.');
                $retired[] = [$publicId, $type, $delivery];
            });
        });

        $this->assertRemoved($admin, $folder, $coMaker);

        $disk->assertMissing(['client-media/own.jpg', 'client-media/residence.jpg']);
        $disk->assertExists(['client-media/shared.jpg', 'client-media/applicant.jpg', 'client-media/other.jpg']);
        $this->assertSame([['proofs/co-maker', 'image', 'upload']], $retired);
        $this->assertSame(2, MediaReference::query()->where('client_folder_id', $folder->id)->whereNull('co_maker_id')->count());
        $this->assertSame(1, MediaReference::query()->where('co_maker_id', $other->id)->count());
    }

    // ---------------------------------------------------------------- Multi-user

    public function test_another_users_unsaved_or_saving_work_on_that_co_maker_blocks_every_role(): void
    {
        $editor = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        $presence = app(ClientFolderEditingPresence::class);

        foreach (['dirty', 'saving'] as $state) {
            foreach ([User::factory()->create(), User::factory()->seniorCreditInvestigator()->create(), User::factory()->administrator()->create()] as $actor) {
                $folder = ClientFolder::factory()->create();
                $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Busy Maker']);
                $presence->record($folder->id, 'ci_activity', 1, $editor, $state, $coMaker->id);

                $this->actingAs($actor)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker]))
                    ->assertStatus(422)
                    ->assertExactJson(['message' => 'This Co-Maker cannot be deleted because Juan Dela Cruz is currently working on it. Please try again after they finish.']);
                $this->assertNotNull($coMaker->fresh());
            }
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'co_maker.removed']);
    }

    public function test_presence_blocks_only_the_exact_co_maker_and_never_viewing_own_or_expired_work(): void
    {
        $editor = User::factory()->create();
        $admin = User::factory()->administrator()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $editor->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Edited A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Free B']);
        $coMakerC = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Viewed C']);
        $coMakerD = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Expiring D']);

        // Real heartbeat for A's activity: the entry carries A's co_maker_id.
        $activityA = self::activity($folder, $editor, ActivityDefinition::BARANGAY_CHECK_CODE, $coMakerA->id);
        $this->actingAs($editor)->postJson(route('editing-presence.heartbeat'), ['type' => 'ci_activity', 'id' => $activityA->id, 'state' => 'dirty'])->assertOk();
        $presence = app(ClientFolderEditingPresence::class);
        $presence->record($folder->id, 'ci_activity', 900, $editor, 'dirty', null); // Applicant work
        $presence->record($folder->id, 'ci_activity', 901, $editor, 'viewing', $coMakerC->id);
        $presence->record($folder->id, 'ci_activity', 902, $admin, 'dirty', $coMakerC->id); // the deleter's own
        $presence->record($folder->id, 'ci_activity', 903, $editor, 'saving', $coMakerD->id);

        $this->actingAs($admin)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMakerA]))->assertStatus(422);
        $this->assertRemoved($admin, $folder, $coMakerB);
        $this->assertRemoved($admin, $folder, $coMakerC);
        $this->actingAs($admin)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMakerD]))->assertStatus(422);

        $this->travel(91)->seconds();
        $this->assertRemoved($admin, $folder, $coMakerD);
    }

    // ---------------------------------------------------------------- Stale Co-Maker pages

    public function test_refreshing_a_stale_co_maker_page_returns_to_the_client_folder_with_a_friendly_notice(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Gone Maker']);
        $pages = [
            route('client-folders.show', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]),
            route('client-folders.activities.index', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]),
        ];
        $this->actingAs($ci)->get($pages[1])->assertOk();
        $this->assertRemoved(User::factory()->administrator()->create(), $folder, $coMaker);

        foreach ($pages as $page) {
            $this->actingAs($ci)->get($page)
                ->assertRedirect(route('client-folders.show', $folder))
                ->assertSessionHas('status', MissingCoMakerResponse::VIEW_MESSAGE)
                ->assertSessionHas('statusType', 'error');
        }
        $this->actingAs($ci)->followingRedirects()->get($pages[1])
            ->assertOk()
            ->assertSee(MissingCoMakerResponse::VIEW_MESSAGE)
            ->assertDontSee('No query results')
            ->assertDontSee('App\\Models\\CoMaker', false);

        $this->actingAs($ci)->getJson($pages[1])->assertNotFound()->assertJsonPath('co_maker_missing', true)->assertJsonPath('message', MissingCoMakerResponse::VIEW_MESSAGE);
        // Deleting it again from a stale dialog is "no longer available", not a lost save.
        $this->actingAs($ci)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker->id]))
            ->assertNotFound()->assertJsonPath('co_maker_missing', true)->assertJsonPath('message', MissingCoMakerResponse::VIEW_MESSAGE);
    }

    public function test_a_stale_co_maker_save_never_writes_to_the_applicant_or_another_co_maker(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Removed A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Kept B']);
        $this->assertRemoved(User::factory()->administrator()->create(), $folder, $coMakerA);
        $audits = AuditLog::query()->count();
        $barangay = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();

        // HTML form saves.
        $this->actingAs($ci)->from(route('client-folders.show', $folder))
            ->post(route('client-folders.residence-checks.store', $folder), ['check_id' => '', 'co_maker_id' => $coMakerA->id, 'location' => 'Stale edit'])
            ->assertRedirect(route('client-folders.show', $folder))
            ->assertSessionHas('status', MissingCoMakerResponse::SAVE_MESSAGE);
        $this->actingAs($ci)->from(route('client-folders.show', $folder))
            ->post(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMakerA->id, 'expected_revision' => $coMakerA->fresh()?->revision ?? $coMakerA->revision, 'first_name' => 'Stale', 'last_name' => 'Rename'])
            ->assertRedirect(route('client-folders.show', $folder))
            ->assertSessionHas('status', MissingCoMakerResponse::SAVE_MESSAGE);

        // AJAX / fetch save.
        $this->actingAs($ci)->postJson(route('client-folders.activities.store', $folder), [
            'co_maker_id' => $coMakerA->id, 'activity_definition_id' => $barangay->id, 'create_new_activity_type' => false,
        ])->assertNotFound()
            ->assertJsonPath('co_maker_missing', true)
            ->assertJsonPath('message', 'Your changes were not saved because this Co-Maker has already been deleted.')
            ->assertDontSee('No query results')
            ->assertDontSee('selected co maker');

        $this->assertNull(CoMaker::query()->find($coMakerA->id), 'The Co-Maker is not recreated.');
        $this->assertSame(1, CoMaker::query()->where('client_folder_id', $folder->id)->count());
        foreach (['residence_checks', 'ci_activities'] as $table) {
            $this->assertSame(0, DB::table($table)->where('client_folder_id', $folder->id)->count(), "No {$table} row for the Applicant, Co-Maker B or anyone.");
        }
        $this->assertSame('Kept B', $coMakerB->fresh()->full_name);
        $this->assertSame($audits, AuditLog::query()->count(), 'No successful-save audit.');
    }

    public function test_normal_co_maker_saves_and_validation_are_unchanged(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'first_name' => 'Live', 'last_name' => 'Maker', 'full_name' => 'Live Maker']);

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMaker->id, 'expected_revision' => $coMaker->fresh()?->revision ?? $coMaker->revision, 'first_name' => '', 'last_name' => 'Maker'])
            ->assertStatus(422)->assertJsonValidationErrors('first_name')->assertJsonMissingPath('co_maker_missing');
        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), ['co_maker_id' => $coMaker->id, 'expected_revision' => $coMaker->fresh()?->revision ?? $coMaker->revision, 'first_name' => 'Still', 'last_name' => 'Here'])->assertOk();
        $this->assertSame('Still', $coMaker->fresh()->first_name);
    }

    // ---------------------------------------------------------------- UI

    public function test_delete_dialog_states_depend_on_role_and_the_exact_co_makers_records(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $empty = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Empty Maker']);
        $busy = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Busy Maker']);
        self::activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, $busy->id);

        foreach ([$ci, User::factory()->seniorCreditInvestigator()->create(), User::factory()->administrator()->create()] as $actor) {
            $html = $this->actingAs($actor)->get(route('client-folders.show', $folder))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/data-co-maker-id="'.$empty->id.'"(?:(?!<\/button>).)*data-co-maker-has-saved-records="0"/s', $html);
            $this->assertMatchesRegularExpression('/data-co-maker-id="'.$busy->id.'"(?:(?!<\/button>).)*data-co-maker-has-saved-records="1"/s', $html);

            $dialog = substr($html, strpos($html, 'id="co-maker-remove-dialog"'));
            $dialog = substr($dialog, 0, strpos($dialog, '</dialog>'));
            // Co-Maker rule: Senior CI and Administrator get the destructive confirmation for a Co-Maker
            // with saved records; only a Credit Investigator gets "Cannot Delete Co-Maker".
            $mayDeleteSaved = in_array($actor->role, [UserRole::Administrator, UserRole::SeniorCreditInvestigator], true);
            $this->assertSame($actor->role !== UserRole::CreditInvestigator, $mayDeleteSaved);
            $this->assertStringContainsString('data-co-maker-delete-saved-allowed="'.($mayDeleteSaved ? '1' : '0').'"', $dialog);
            $this->assertStringContainsString('Are you sure you want to delete this Co-Maker? This action cannot be undone.', $dialog);
            $this->assertStringContainsString('This will permanently delete this Co-Maker and all records linked to them. This action cannot be undone.', $dialog);
            $this->assertStringContainsString('This Co-Maker already has saved records. Only a Senior CI or Administrator can delete it.', $dialog);
            $this->assertMatchesRegularExpression('/role="alert"[^>]*data-co-maker-delete-error hidden/', $dialog);
            $this->assertStringContainsString('<div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:justify-end" data-co-maker-delete-actions>', $dialog);
            preg_match('/class="ui-button-secondary( [^"]*)">(?:(?!<\/button>).)*Cancel/s', $dialog, $cancel);
            preg_match('/class="ui-button-danger( [^"]*)" data-co-maker-remove-submit>(?:(?!<\/button>).)*Delete\s*<\/button>/s', $dialog, $delete);
            $this->assertSame($cancel[1] ?? null, $delete[1] ?? 'missing', 'Equal-width, equal-height phone columns.');
            $this->assertStringContainsString('w-full', $delete[1] ?? '');
        }

        $js = file_get_contents(resource_path('js/app.js'));
        $opener = substr($js, strpos($js, "const hasSavedRecords = removeTrigger.dataset.coMakerHasSavedRecords === '1';"), 1600);
        $this->assertStringContainsString("const unavailable = hasSavedRecords && removeDialog.dataset.coMakerDeleteSavedAllowed !== '1';", $opener);
        $this->assertStringContainsString("title.textContent = unavailable ? 'Cannot Delete Co-Maker' : 'Delete Co-Maker?';", $opener);
        $this->assertStringContainsString("removeDialog.querySelector('[data-co-maker-delete-actions]')?.toggleAttribute('hidden', unavailable);", $opener);
        $this->assertStringContainsString('if (submitButton instanceof HTMLButtonElement) submitButton.disabled = unavailable;', $opener);

        $submitStart = strpos($js, "const form = event.target.closest('[data-co-maker-remove-form]');");
        $submit = substr($js, $submitStart, strpos($js, "\n});\n", $submitStart) - $submitStart);
        $refusedStart = strpos($submit, 'if (!response.ok) {');
        $refused = substr($submit, $refusedStart, strpos($submit, 'return;', $refusedStart) + 7 - $refusedStart);
        $this->assertStringContainsString('setCoMakerDeleteError(dialog, response.status === 422 && payload.message ? payload.message : CO_MAKER_DELETE_FAILED_MESSAGE);', $refused);
        $this->assertStringNotContainsString('showToast', $refused);
        $this->assertStringNotContainsString('close()', $refused);
        $this->assertStringContainsString('const payload = await response.json().catch(() => ({}));', $submit);
        $this->assertMatchesRegularExpression('/} catch \{\s*setCoMakerDeleteError\(dialog, CO_MAKER_DELETE_FAILED_MESSAGE\);\s*} finally \{\s*submit\?\.removeAttribute\(\'disabled\'\);/', $submit);
    }

    // ---------------------------------------------------------------- Helpers

    private function assertRemoved(User $actor, ClientFolder $folder, CoMaker $coMaker): void
    {
        $this->actingAs($actor)->deleteJson(route('client-folders.co-maker.destroy', [$folder, $coMaker]))
            ->assertOk()->assertJsonPath('message', 'Co-Maker removed successfully.');

        $this->assertNull(CoMaker::query()->find($coMaker->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'co_maker.removed')->where('metadata->co_maker_id', $coMaker->id)->count());
    }

    /** @return array<string, int> */
    private function ownedCounts(ClientFolder $folder, CoMaker $coMaker): array
    {
        return collect(['co_makers' => 'id', 'cibi_reports' => 'co_maker_id', 'residence_checks' => 'co_maker_id', 'ci_activities' => 'co_maker_id', 'media_references' => 'co_maker_id'])
            ->map(fn (string $column, string $table): int => DB::table($table)->where($column, $coMaker->id)->count())
            ->all();
    }

    private static function activity(ClientFolder $folder, User $creator, string $code, ?int $coMakerId): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'activity_definition_id' => $definition->id,
            'name' => $definition->name, 'creator_id' => $creator->id, 'status' => ActivityStatus::Pending,
        ]);
    }
}
