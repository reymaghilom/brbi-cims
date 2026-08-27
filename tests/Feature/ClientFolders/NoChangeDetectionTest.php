<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NoChangeDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    // --- Co-Maker edit ------------------------------------------------------------------------

    public function test_real_co_maker_edit_creates_recent_activity_immediately(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'first_name' => 'Pedro', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'full_name' => 'Pedro Dela Cruz', 'address' => 'Existing Address']);

        $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Pedro', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'address' => 'Existing Address',
        ])->assertOk()->assertJsonMissing(['no_change' => true]);

        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'co_maker.updated']);
        $content = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();
        $this->assertStringContainsString('Co-Maker updated', $content);
    }

    public function test_unchanged_co_maker_update_creates_no_activity_and_shows_no_changes_notification(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'first_name' => 'Pedro', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'full_name' => 'Pedro Dela Cruz', 'address' => 'Existing Address']);

        $response = $this->actingAs($ci)->postJson(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Pedro', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'address' => 'Existing Address',
        ])->assertOk();

        $this->assertTrue($response->json('no_change'));
        $this->assertStringContainsString('No changes detected', $response->json('message'));
        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'co_maker.updated']);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.show', $folder).'?person=co-maker&co_maker_id='.$coMaker->id)
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Co-Maker updated', $content);
    }

    // --- Business / Income Source -------------------------------------------------------------

    public function test_unchanged_applicant_business_update_creates_no_activity(): void
    {
        [$ci, $folder, $source, $payload] = $this->createFullBusiness();
        $revisionBefore = $source->revision;
        AuditLog::query()->where('client_folder_id', $folder->id)->delete();

        $response = $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertRedirect();
        $response->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');

        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'business_report.updated']);
        $this->assertSame($revisionBefore, $source->fresh()->revision);
    }

    public function test_unchanged_co_maker_business_update_creates_no_activity(): void
    {
        [$ci, $folder, $source, $payload] = $this->createFullBusiness(withCoMaker: true);
        $revisionBefore = $source->revision;
        AuditLog::query()->where('client_folder_id', $folder->id)->delete();

        $response = $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertRedirect();
        $response->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');

        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'business_report.updated']);
        $this->assertSame($revisionBefore, $source->fresh()->revision);
    }

    public function test_real_business_edit_still_updates_normally(): void
    {
        [$ci, $folder, $source, $payload] = $this->createFullBusiness();
        $revisionBeforeEdit = $source->revision;
        $payload['business_name'] = 'Renamed Business';

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertRedirect()->assertSessionHas('status', 'Business Report updated successfully.');

        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'business_report.updated']);
        $this->assertSame($revisionBeforeEdit + 1, $source->fresh()->revision);
        $this->assertSame('Renamed Business', $source->fresh()->business_name);
    }

    // --- Residence Check ------------------------------------------------------------------------

    public function test_unchanged_residence_check_update_shows_nothing_changed_and_creates_no_activity(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), ['remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)]]);
        $check = $folder->residenceChecks()->firstOrFail();
        AuditLog::query()->where('client_folder_id', $folder->id)->delete();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.',
        ])->assertRedirect();

        $response->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');
        $response->assertSessionHas('statusType', 'info');
        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'residence_check.updated']);
    }

    public function test_no_change_residence_check_update_does_not_modify_updated_at_or_updated_by(): void
    {
        Storage::fake('local');
        $creator = User::factory()->create();
        $editor = User::factory()->create();
        $folder = $this->residenceCheckFolder($creator);
        $this->actingAs($creator)->post(route('client-folders.residence-checks.store', $folder), ['remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)]]);
        $check = $folder->residenceChecks()->firstOrFail();
        $updatedAtBefore = $check->updated_at;

        $this->travel(1)->minutes();
        $this->actingAs($editor)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.',
        ])->assertSessionHas('statusType', 'info');

        $check->refresh();
        $this->assertTrue($updatedAtBefore->equalTo($check->updated_at));
        $this->assertSame($creator->id, $check->updated_by);
    }

    public function test_no_change_residence_check_update_does_not_alter_photos_or_map_screenshot(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $photo = UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500);
        $screenshot = UploadedFile::fake()->image('Map.png', 800, 600)->size(400);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.', 'photos' => [$photo], 'map_screenshot' => $screenshot,
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $storedPhotoPath = $check->photos()->firstOrFail()->path;
        $storedScreenshotPath = $check->map_screenshot_path;

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.',
        ])->assertSessionHas('statusType', 'info');

        $check->refresh();
        $this->assertSame(1, $check->photos()->count());
        $this->assertSame($storedPhotoPath, $check->photos()->firstOrFail()->path);
        $this->assertSame($storedScreenshotPath, $check->map_screenshot_path);
        Storage::disk('local')->assertExists($storedPhotoPath);
        Storage::disk('local')->assertExists($storedScreenshotPath);
    }

    public function test_real_residence_check_remarks_edit_still_updates_normally(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), ['remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)]]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified with barangay confirmation.',
        ])->assertSessionHas('status', 'Residence Check updated successfully.');

        $this->assertDatabaseHas('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'residence_check.updated']);
        $this->assertSame('Residence verified with barangay confirmation.', $check->fresh()->remarks);
    }

    public function test_adding_a_residence_photo_counts_as_a_real_change(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), ['remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)]]);
        $check = $folder->residenceChecks()->firstOrFail();
        $photo = UploadedFile::fake()->image('Added.jpg', 900, 700)->size(500);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.', 'photos' => [$photo],
        ])->assertSessionHas('status', 'Residence Check updated successfully.');

        $this->assertSame(2, $check->photos()->count());
    }

    public function test_removing_a_residence_photo_counts_as_a_real_change(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $photo = UploadedFile::fake()->image('ToRemove.jpg', 900, 700)->size(500);
        $keptPhoto = UploadedFile::fake()->image('Kept.jpg', 900, 700)->size(500);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.', 'photos' => [$photo, $keptPhoto],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        // Rows are already ordered by sort_order (upload order), so the first row is 'ToRemove.jpg'.
        $photoId = $check->photos()->first()->id;

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.', 'removed_photo_ids' => [$photoId],
        ])->assertSessionHas('status', 'Residence Check updated successfully.');

        $this->assertSame(1, $check->photos()->count());
    }

    public function test_replacing_the_map_screenshot_counts_as_a_real_change(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Original.png', 800, 600)->size(400),
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $originalPath = $check->map_screenshot_path;

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.', 'map_screenshot' => UploadedFile::fake()->image('Replacement.png', 800, 600)->size(400),
        ])->assertSessionHas('status', 'Residence Check updated successfully.');

        $this->assertNotSame($originalPath, $check->fresh()->map_screenshot_path);
    }

    public function test_removing_the_map_screenshot_counts_as_a_real_change(): void
    {
        Storage::fake('local');
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Original.png', 800, 600)->size(400),
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.', 'remove_map_screenshot' => '1',
        ])->assertSessionHas('status', 'Residence Check updated successfully.');

        $this->assertNull($check->fresh()->map_screenshot_path);
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    // --- Folder Rename -------------------------------------------------------------------------

    public function test_unchanged_rename_creates_no_audit_or_history_event(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MICABALO, RONILO CABIGAS']);

        $response = $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), [
            'display_name' => 'micabalo, ronilo cabigas',
        ])->assertRedirect();

        $response->assertSessionHas('status');
        $this->assertStringContainsString('No changes detected', session('status'));
        $this->assertSame('info', session('statusType'));
        $this->assertDatabaseMissing('audit_logs', ['client_folder_id' => $folder->id, 'action' => 'client_folder.renamed']);
        $this->assertSame('MICABALO, RONILO CABIGAS', $folder->fresh()->display_name);
    }

    public function test_real_rename_creates_exactly_one_event_and_folder_history_refreshes_immediately(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MICABALO']);

        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), [
            'display_name' => 'MICABALO, RONILO CABIGAS',
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::where('client_folder_id', $folder->id)->where('action', 'client_folder.renamed')->count());
        $this->assertSame('MICABALO, RONILO CABIGAS', $folder->fresh()->display_name);

        // Folder History is a live query (no cache to invalidate) — the very next Dashboard
        // render must already show the fresh rename event.
        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Folder Updated', $content);
        $this->assertStringContainsString('MICABALO', $content);
        $this->assertStringContainsString('MICABALO, RONILO CABIGAS', $content);
        $this->assertStringContainsString('REY C. MAGHILOM', $content);
    }

    public function test_folder_created_and_prior_rename_events_remain_preserved_after_a_new_rename(): void
    {
        $ci = User::factory()->create(['full_name' => 'REY C. MAGHILOM']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'display_name' => 'MICABALO']);
        AuditLog::create(['user_id' => $ci->id, 'client_folder_id' => $folder->id, 'action' => 'client_folder.created', 'module' => 'client_folders', 'description' => 'A client folder was created.', 'metadata' => []]);
        DB::table('audit_logs')->where('client_folder_id', $folder->id)->update(['created_at' => '2026-08-20 09:00:00']);

        $this->actingAs($ci)->patch(route('client-folders.update-name', $folder), ['display_name' => 'MICABALO, RONILO CABIGAS'])->assertRedirect();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Folder Created', $content);
        $this->assertStringContainsString('Folder Updated', $content);
        $this->assertSame(2, AuditLog::where('client_folder_id', $folder->id)->whereIn('action', ['client_folder.created', 'client_folder.renamed'])->count());
    }

    /**
     * A real Add Business flow always runs CreateIncomeSource and SaveBusinessIncomeSource
     * together (the controller's store() calls both) — replicating that here so the fixture's
     * businessReport genuinely exists before the "unchanged" PUT, matching real usage instead of
     * an artificial half-created record.
     *
     * @return array{0: User, 1: ClientFolder, 2: IncomeSource, 3: array}
     */
    private function createFullBusiness(bool $withCoMaker = false): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $withCoMaker ? CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']) : null;
        $template = IncomeSourceTemplate::where('template_type', 'leasing_truck_equipment')->firstOrFail();

        $createData = ['income_source_template_id' => $template->id, 'source_name' => 'Sample Business', 'business_name' => 'Sample Business'];
        if ($coMaker) {
            $createData['co_maker_id'] = $coMaker->id;
        }
        $source = $this->app->make(CreateIncomeSource::class)->execute($ci, $folder, $createData);

        // The very first save (through the real HTTP flow, exactly like store() does) establishes
        // a genuinely-persisted, fully-normalized baseline — the payload below is built from that
        // same saved/normalized state so a resubmission is a true apples-to-apples comparison,
        // not a hand-built payload that could drift from what validation actually normalizes to.
        $payload = [
            'intent' => 'stay', 'source_name' => $source->source_name, 'business_name' => $source->business_name,
            'report_category' => 'Other', 'main_business_address' => 'Main Street', 'start_date' => '2026-01-01', 'year_established' => 2020,
        ];
        if ($coMaker) {
            $payload['co_maker_id'] = $coMaker->id;
        }
        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)->assertRedirect();
        $source->refresh();

        return [$ci, $folder, $source, $payload];
    }
}
