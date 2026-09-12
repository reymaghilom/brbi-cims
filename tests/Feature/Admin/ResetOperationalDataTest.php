<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\ResetOperationalData;
use App\Enums\ActivityStatus;
use App\Enums\RecordState;
use App\Http\Controllers\Admin\ResetOperationalDataController;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin -> Settings -> Data Management -> Reset Operational Data.
 *
 * Clears the client/investigation workspace and nothing else. Every table it touches comes from an
 * explicit hand-classified allowlist, so a table introduced by a future migration is left alone
 * until somebody classifies it; there is no "delete everything except users" path to go wrong.
 */
class ResetOperationalDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->administrator()->create(['full_name' => 'System Administrator']);
    }

    /** A folder carrying at least one record in every operational domain the reset clears. */
    private function populatedFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => 'DELA CRUZ, JUAN',
        ]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'MARIA DELA CRUZ']);

        CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'ci_in_charge_id' => $ci->id, 'state' => RecordState::Complete, 'completed_at' => now(),
        ]);
        (new ResidenceCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id,
        ])->save();

        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'income_source_template_id' => IncomeSourceTemplate::query()->where('is_fallback', false)->value('id'),
            'state' => RecordState::Complete, 'revision' => 2,
        ]);
        (new BusinessReport)->forceFill(['income_source_id' => $source->id, 'business_name' => 'Sari-sari Store', 'report_category' => 'retail'])->save();
        (new BusinessCheck)->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(), 'ci_user_id' => $ci->id,
        ])->save();

        $definition = ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole();
        CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => ActivityStatus::Pending, 'creator_id' => $ci->id,
        ]);

        AuditLog::create([
            'user_id' => $ci->id, 'client_folder_id' => $folder->id,
            'action' => 'client_folder.created', 'module' => 'client_folders',
            'description' => 'A client folder was created.',
        ]);

        return $folder;
    }

    private function reset(User $actor, string $confirmation = 'RESET DATA')
    {
        return $this->actingAs($actor)->post(route('admin.settings.reset-operational-data'), [
            'confirmation' => $confirmation,
        ]);
    }

    // =====================================================================================
    // Authorization
    // =====================================================================================

    public function test_only_an_administrator_can_reach_data_management_or_run_the_reset(): void
    {
        $ci = User::factory()->create();
        $senior = User::factory()->seniorCreditInvestigator()->create();
        $folder = $this->populatedFolder($ci);

        // The page itself.
        $this->actingAs($this->admin())->get(route('admin.settings.index'))->assertOk()->assertSee('Data Management');
        $this->actingAs($ci)->get(route('admin.settings.index'))->assertForbidden();

        // The endpoint, including a forged request from a non-Administrator.
        $this->reset($ci)->assertForbidden();
        $this->reset($senior)->assertForbidden();
        // actingAs() persists for the rest of the test, so drop the authenticated guard first to
        // make this a genuinely unauthenticated request.
        $this->app['auth']->forgetGuards();
        $this->post(route('admin.settings.reset-operational-data'), ['confirmation' => 'RESET DATA'])
            ->assertRedirect(route('login'));

        // Nothing was touched by any of those attempts.
        $this->assertDatabaseHas('client_folders', ['id' => $folder->id]);
        $this->assertSame(1, ClientFolder::query()->count());
    }

    public function test_the_reset_is_not_reachable_by_get(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.settings.reset-operational-data'))
            ->assertMethodNotAllowed();
    }

    // =====================================================================================
    // Confirmation phrase
    // =====================================================================================

    public function test_the_exact_confirmation_phrase_is_required_on_the_server(): void
    {
        $ci = User::factory()->create();
        $this->populatedFolder($ci);
        $admin = $this->admin();

        foreach (['', 'reset data', 'RESET  DATA', 'DELETE EVERYTHING'] as $wrong) {
            $this->reset($admin, $wrong)->assertSessionHasErrors('confirmation');
        }
        $this->actingAs($admin)->post(route('admin.settings.reset-operational-data'))
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(1, ClientFolder::query()->count(), 'No failed attempt deleted anything.');

        // A rejected phrase must not fail silently: the settings page reopens the dialog so the
        // validation message is actually visible.
        $this->reset($admin, 'nope');
        $this->actingAs($admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('data-reset-reopen', false);

        // Surrounding whitespace is forgiven, the phrase itself is not case-insensitive.
        $this->reset($admin, '  RESET DATA  ')->assertSessionHasNoErrors();
        $this->assertSame(0, ClientFolder::query()->count());
    }

    public function test_viewing_settings_never_mutates_anything(): void
    {
        $ci = User::factory()->create();
        $this->populatedFolder($ci);

        $this->actingAs($this->admin())->get(route('admin.settings.index'))->assertOk();

        $this->assertSame(1, ClientFolder::query()->count());
        $this->assertSame(1, CiActivity::query()->count());
        // The summary itself is read-only.
        app(ResetOperationalData::class)->summary();
        $this->assertSame(1, ClientFolder::query()->count());
    }

    // =====================================================================================
    // What is cleared
    // =====================================================================================

    public function test_every_operational_domain_is_cleared(): void
    {
        $ci = User::factory()->create();
        $folder = $this->populatedFolder($ci);
        $admin = $this->admin();

        $this->reset($admin)->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('status', ResetOperationalDataController::SUCCESS_MESSAGE);

        foreach (ResetOperationalData::OPERATIONAL_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.' must be empty after the reset.');
        }
        $this->assertNotNull($folder->id);

        // Client-scoped history goes with the records; system-level history stays.
        $this->assertSame(0, AuditLog::query()->whereNotNull('client_folder_id')->count());
    }

    // =====================================================================================
    // What survives
    // =====================================================================================

    public function test_accounts_roles_master_data_and_settings_all_survive(): void
    {
        $ci = User::factory()->create(['full_name' => 'Field Investigator']);
        $senior = User::factory()->seniorCreditInvestigator()->create();
        $this->populatedFolder($ci);
        $admin = $this->admin();

        $settingsBefore = SystemSetting::query()->count();
        $templatesBefore = IncomeSourceTemplate::query()->count();
        $definitionsBefore = ActivityDefinition::query()->count();
        $reportTemplatesBefore = DB::table('report_templates')->count();
        $completionRulesBefore = DB::table('completion_rules')->count();

        $this->reset($admin)->assertSessionHasNoErrors();

        // Accounts, roles and authentication are untouched.
        $this->assertSame(3, User::query()->count());
        foreach ([$admin, $ci, $senior] as $user) {
            $fresh = User::query()->find($user->id);
            $this->assertNotNull($fresh);
            $this->assertSame($user->role, $fresh->role);
            $this->assertSame($user->status, $fresh->status);
            $this->assertSame($user->password, $fresh->password);
            $this->assertSame($user->full_name, $fresh->full_name);
        }

        // Master and reference data are untouched.
        $this->assertSame($definitionsBefore, ActivityDefinition::query()->count());
        $this->assertSame($templatesBefore, IncomeSourceTemplate::query()->count());
        $this->assertSame($settingsBefore, SystemSetting::query()->count());
        $this->assertSame($reportTemplatesBefore, DB::table('report_templates')->count());
        $this->assertSame($completionRulesBefore, DB::table('completion_rules')->count());
        $this->assertGreaterThan(0, DB::table('migrations')->count(), 'Migration history survives.');

        // The four canonical built-in Activity Types in particular.
        foreach ([
            ActivityDefinition::BARANGAY_CHECK_CODE,
            ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ActivityDefinition::ASSET_CHECK_CODE,
            ActivityDefinition::BANK_COOP_CHECK_CODE,
        ] as $code) {
            $this->assertTrue(
                ActivityDefinition::query()->where('code', $code)->where('is_active', true)->exists(),
                $code.' must survive the reset.'
            );
        }

        // The Administrator is still signed in and can still use the page.
        $this->assertAuthenticatedAs($admin);
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk();
    }

    // =====================================================================================
    // After the reset
    // =====================================================================================

    public function test_the_workspace_reads_empty_and_the_manual_workflow_still_works(): void
    {
        $ci = User::factory()->create();
        $this->populatedFolder($ci);
        $admin = $this->admin();

        $this->reset($admin)->assertSessionHasNoErrors();

        // Operational views resolve empty through their own authoritative queries.
        $this->actingAs($ci)->get(route('client-folders.index'))->assertOk()
            ->assertViewHas('clientFolders', fn ($folders): bool => $folders->total() === 0);
        $this->actingAs($ci)->get(route('ci-activities.index'))->assertOk()
            ->assertViewHas('counts', fn (array $counts): bool => $counts['all'] === 0);
        $this->actingAs($ci)->get(route('reports.index'))->assertOk()
            ->assertViewHas('items', fn ($items): bool => $items->total() === 0);

        $dashboard = $this->actingAs($ci)->get(route('home'))->assertOk();
        $summary = $dashboard->viewData('summary');
        $this->assertSame(0, $summary['assigned']);
        $this->assertSame(0, $summary['in_progress']);
        $this->assertSame(0, $summary['needs_attention']);
        $this->assertSame(0, $summary['reports_ready']);

        // A brand new folder still works, and Barangay Check is still manually addable — no
        // auto-generation came back with the reset.
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $this->assertSame(0, $folder->activities()->count(), 'Creation still seeds nothing.');

        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => '',
            'create_new_activity_type' => '0',
            'activity_definition_id' => ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->sole()->id,
            'status' => ActivityStatus::Pending->value,
            'intent' => 'return',
        ])->assertSessionHasNoErrors();

        $added = CiActivity::query()->sole();
        $this->assertSame($ci->id, $added->creator_id);
    }

    public function test_the_reset_records_one_system_audit_event_and_is_safe_to_repeat(): void
    {
        $ci = User::factory()->create();
        $this->populatedFolder($ci);
        $admin = $this->admin();

        $this->reset($admin)->assertSessionHasNoErrors();

        $events = AuditLog::query()->where('action', 'system.operational_data_reset')->get();
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertNull($event->client_folder_id, 'A system event needs no fabricated folder.');
        $this->assertSame('system', $event->module);
        $this->assertSame(1, $event->metadata['removed']['Client Folders']);

        // Running it again on an already-empty workspace succeeds and changes nothing else.
        $this->reset($admin)->assertSessionHas('status', ResetOperationalDataController::SUCCESS_MESSAGE);
        $this->assertCount(2, AuditLog::query()->where('action', 'system.operational_data_reset')->get());
        $this->assertSame(0, ClientFolder::query()->count());
        $this->assertTrue(ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->exists());
    }

    // =====================================================================================
    // The allowlist itself
    // =====================================================================================

    public function test_the_allowlist_is_explicit_and_never_touches_preserved_tables(): void
    {
        $preserved = [
            'users', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'migrations', 'system_settings', 'activity_definitions', 'income_source_templates',
            'report_templates', 'completion_rules', 'custom_business_categories',
            'folder_number_sequences', 'notifications', 'audit_logs',
        ];

        foreach ($preserved as $table) {
            $this->assertNotContains($table, ResetOperationalData::OPERATIONAL_TABLES, $table.' must never be in the reset allowlist.');
        }

        // Every listed table is a real table, so the plan cannot silently no-op on a typo.
        foreach (ResetOperationalData::OPERATIONAL_TABLES as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), $table.' is listed but does not exist.');
        }

        // The reset is a fixed list, not a schema sweep.
        $source = file_get_contents(base_path('app/Actions/Admin/ResetOperationalData.php'));
        $this->assertStringNotContainsString('getAllTables', $source);
        $this->assertStringNotContainsString('truncate', $source);
        $this->assertStringNotContainsString('SET FOREIGN_KEY_CHECKS', $source);
        // No external storage is reached from the reset.
        foreach (['Cloudinary', 'cloudinary', 'Storage::', 'Drive'] as $external) {
            $this->assertStringNotContainsString($external, $source);
        }
    }
}
