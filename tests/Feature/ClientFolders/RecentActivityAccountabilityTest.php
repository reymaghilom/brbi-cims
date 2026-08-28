<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecentActivityAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    // --- A: business delete ---------------------------------------------------------------

    public function test_removing_a_business_creates_an_audit_event_with_correct_actor(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->business($folder, null, 'ABC STORE');

        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $source]))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'client_folder_id' => $folder->id,
            'action' => 'income_source.deleted',
            'user_id' => $ci->id,
        ]);
    }

    public function test_business_name_remains_available_in_recent_activity_after_deletion(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $source = $this->business($folder, null, 'ABC STORE');
        $unrelatedSource = $this->business($folder, null, 'UNAFFECTED STORE');

        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $source]));
        $this->assertSoftDeleted('income_sources', [
            'id' => $source->id,
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
        ]);
        $this->assertDatabaseHas('income_sources', [
            'id' => $unrelatedSource->id,
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'deleted_at' => null,
        ]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Business removed')
            ->assertSee('ABC STORE');
    }

    public function test_applicant_business_delete_appears_only_in_applicant_activity(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER X']);
        $source = $this->business($folder, null, 'APPLICANT STORE');
        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $source]));

        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee('APPLICANT STORE');
        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()
            ->assertDontSee('APPLICANT STORE');
    }

    public function test_co_maker_business_delete_appears_only_in_that_co_makers_scope(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $targetCoMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'TARGET CO MAKER']);
        $otherCoMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'OTHER CO MAKER']);
        $source = $this->business($folder, $targetCoMaker->id, 'COMAKER STORE');
        $this->actingAs($ci)->delete(route('client-folders.income-sources.destroy', [$folder, $source]).'?'.http_build_query(['person' => 'co-maker', 'co_maker_id' => $targetCoMaker->id]));

        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$targetCoMaker->id)
            ->assertOk()
            ->assertSee('COMAKER STORE');
        $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$otherCoMaker->id)
            ->assertOk()
            ->assertDontSee('COMAKER STORE');
        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertDontSee('COMAKER STORE');
    }

    // --- B: timezone -------------------------------------------------------------------------

    public function test_recent_activity_displays_audit_timestamp_correctly_in_asia_manila(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $log = AuditLog::create([
            'user_id' => $ci->id, 'client_folder_id' => $folder->id, 'action' => 'cibi_report.updated', 'module' => 'cibi_report',
            'description' => 'x', 'metadata' => ['report_id' => 1, 'co_maker_id' => null],
        ]);
        // Simulate exactly what production MySQL hands back for this column: a raw string
        // written directly (bypassing Eloquent's cast), representing genuine UTC — the DB
        // connection's session time_zone is forced to UTC (config/database.php), matching
        // APP_TIMEZONE, so a TIMESTAMP column's DB-level CURRENT_TIMESTAMP default returns UTC.
        // 14:07 UTC converts to 10:07 PM Manila (+8, same calendar day).
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => '2026-08-23 14:07:00']);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Aug 23, 2026')
            ->assertSee('10:07 PM');
    }

    public function test_no_double_timezone_conversion_occurs(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $log = AuditLog::create([
            'user_id' => $ci->id, 'client_folder_id' => $folder->id, 'action' => 'cibi_report.updated', 'module' => 'cibi_report',
            'description' => 'x', 'metadata' => ['report_id' => 1, 'co_maker_id' => null],
        ]);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => '2026-08-23 14:07:00']);

        $fresh = AuditLog::find($log->id);
        // AuditLog::createdAt() converts the raw UTC value to Manila exactly once (14:07 -> 22:07).
        // Re-applying the display timezone on top of that must be a no-op — proves no caller
        // shifts it a second time by also calling ->timezone().
        $this->assertSame('2026-08-23 22:07:00', $fresh->created_at->copy()->timezone(config('cims.display_timezone'))->toDateTimeString());
        $this->assertSame('Asia/Manila', $fresh->created_at->timezoneName);
    }

    public function test_dashboard_folder_history_timestamps_remain_correct_after_timezone_fix(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'BEFORE NAME']);
        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'AFTER NAME']);
        // Stored raw value is genuine UTC — 05:55 UTC converts to 1:55 PM Manila (+8).
        AuditLog::where('client_folder_id', $folder->id)->where('action', 'client_folder.renamed')
            ->update(['created_at' => '2026-08-23 05:55:00']);

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder(['Folder History', 'Aug 23, 2026', '1:55 PM']);
    }

    // --- C: co-maker lifecycle -----------------------------------------------------------------

    public function test_add_co_maker_creates_and_displays_co_maker_added(): void
    {
        $ci = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Co-Maker Address',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'co_maker.added', 'user_id' => $ci->id]);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Co-Maker added')
            ->assertSee('Juan Dela Cruz');
    }

    public function test_edit_co_maker_creates_and_displays_co_maker_updated(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Co-Maker Address']);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz Jr.', 'address' => 'Co-Maker Address',
        ])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'co_maker.updated', 'user_id' => $ci->id]);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Co-Maker updated')
            ->assertSee('Juan Dela Cruz Jr.');
    }

    public function test_remove_co_maker_creates_and_displays_co_maker_removed(): void
    {
        $ci = User::factory()->create(['full_name' => 'ANTHONY YONG']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz']);

        $this->actingAs($ci)->delete(route('client-folders.co-maker.destroy', [$folder, $coMaker]))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'co_maker.removed', 'user_id' => $ci->id]);
        $this->actingAs($ci)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertSee('Co-Maker removed')
            ->assertSee('Juan Dela Cruz');
    }

    public function test_removed_co_makers_name_remains_readable_from_audit_metadata(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'REMOVED PERSON']);
        $coMakerId = $coMaker->id;

        $this->actingAs($ci)->delete(route('client-folders.co-maker.destroy', [$folder, $coMaker]));
        $this->assertDatabaseMissing('co_makers', ['id' => $coMakerId]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->assertSee('REMOVED PERSON');
    }

    public function test_one_co_makers_lifecycle_activity_does_not_leak_into_another_co_maker_context(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $viewedCoMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'VIEWED PERSON']);
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Another', 'last_name' => 'Person', 'address' => 'Another Address',
        ]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$viewedCoMaker->id)
            ->assertOk()
            ->getContent();
        // "Another Person" legitimately appears in the unrelated person-switch tab list (every
        // co-maker gets a tab) — scope the leak check to the Recent Activity panel specifically.
        $asideStart = strpos($content, 'id="recent-activity-title"');
        $asideEnd = strpos($content, '</aside>', $asideStart);
        $asideHtml = substr($content, $asideStart, $asideEnd - $asideStart);

        $this->assertStringNotContainsString('Another Person', $asideHtml);
    }

    private function business(ClientFolder $folder, ?int $coMakerId, string $businessName): IncomeSource
    {
        $template = IncomeSourceTemplate::first() ?? IncomeSourceTemplate::factory()->create();

        return IncomeSource::factory()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => $coMakerId,
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'state' => RecordState::Draft,
            'business_name' => $businessName,
            'source_name' => $businessName,
        ]);
    }
}
