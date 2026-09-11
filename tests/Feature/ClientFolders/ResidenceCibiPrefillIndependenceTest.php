<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\SaveCibiReport;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PREFILL BEFORE SAVE, INDEPENDENT AFTER SAVE — the Co-Maker side of the Residence Check ↔ CI/BI
 * Report relationship (the Applicant side is covered by ApplicantCibiResidencePrefillTest and
 * ResidenceCheckCiDateTest). Also covers cross-cutting rules that apply to both roles: no paired
 * delete, no audit entries from prefill-only page loads, and saved output snapshots surviving a
 * counterpart change.
 */
class ResidenceCibiPrefillIndependenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_co_maker_residence_first_prefills_the_still_unsaved_cibi_form(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'ci_date' => '2026-06-03',
            'location' => 'Zone 3, Igpit, Opol, Misamis Oriental',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk();

        $xpath = $this->xpath($response->getContent());
        $this->assertSame('2026-06-03', $xpath->query("//*[@name='start_date']")->item(0)->getAttribute('value'));
        $this->assertSame('Zone 3, Igpit, Opol, Misamis Oriental', trim($xpath->query("//*[@name='personal_snapshot[present_address]']")->item(0)->textContent));

        $folderContents = $this->actingAs($ci)
            ->get(route('client-folders.show', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk()
            ->getContent();
        $cardStart = strpos($folderContents, 'id="open-cibi-report"');
        $cardEnd = strpos($folderContents, '</article>', $cardStart);
        $cibiCard = substr($folderContents, $cardStart, $cardEnd - $cardStart);
        $this->assertStringContainsString('Add</a>', $cibiCard);
        $this->assertStringContainsString('d="M12 5v14M5 12h14"', $cibiCard);
        $this->assertStringNotContainsString('Open</a>', $cibiCard);
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    public function test_co_maker_residence_update_before_cibi_save_changes_the_next_unsaved_prefill(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'ci_date' => '2026-06-01',
            'location' => 'Purok 1, Opol',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'check_id' => $check->id,
            'ci_date' => '2026-06-03',
            'location' => 'Purok 5, Opol',
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame('2026-06-03', $xpath->query("//*[@name='start_date']")->item(0)->getAttribute('value'));
        $this->assertSame('Purok 5, Opol', trim($xpath->query("//*[@name='personal_snapshot[present_address]']")->item(0)->textContent));
    }

    public function test_co_maker_residence_delete_before_cibi_save_removes_the_derived_prefill(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person']);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'ci_date' => '2026-06-03',
            'location' => 'Deleted Source Address',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $check, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertRedirect();

        // The Residence-derived prefill disappears — this is not "delete the unsaved CIBI record"
        // (no CIBI record was ever created), only its prefill source going away.
        $response = $this->actingAs($ci)
            ->get(route('client-folders.cibi-report.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame('', $xpath->query("//*[@name='start_date']")->item(0)->getAttribute('value'));
        $this->assertSame('', trim($xpath->query("//*[@name='personal_snapshot[present_address]']")->item(0)->textContent));
        $this->assertDatabaseCount('cibi_reports', 0);
        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_saved_cibi_survives_completely_unchanged_when_its_residence_check_is_deleted(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => '2026-06-01', 'location' => 'Purok 5, Opol',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->app->make(SaveCibiReport::class)->execute($ci, $folder, [
            'co_maker_id' => null,
            'personal_snapshot' => ['present_address' => 'Purok 5, Opol'],
            'start_date' => '2026-06-01',
        ]);
        $cibi = $folder->cibiReports()->whereNull('co_maker_id')->firstOrFail();

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $check]))->assertRedirect();

        $cibi->refresh();
        $this->assertSame('Purok 5, Opol', $cibi->personal_snapshot['present_address']);
        $this->assertSame('2026-06-01', $cibi->start_date->toDateString());
        $this->assertDatabaseCount('cibi_reports', 1);
        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_deleting_a_residence_check_never_writes_a_cibi_audit_entry(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => '2026-06-01', 'location' => 'Purok 5, Opol',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $check]))->assertRedirect();

        $this->assertSame(0, AuditLog::where('module', 'cibi_report')->count());
        $this->assertSame(1, AuditLog::where('action', 'residence_check.deleted')->count());
    }

    public function test_opening_the_prefilled_residence_create_form_writes_no_audit_log(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-06-02']);

        $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_opening_the_prefilled_cibi_form_writes_no_audit_log(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => '2026-06-01', 'location' => 'Purok 5, Opol',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        AuditLog::query()->delete();

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_another_client_folders_residence_check_never_leaks_into_this_folders_cibi_prefill(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $otherFolder), [
            'ci_date' => '2026-01-01', 'location' => 'Other Folder Address',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();

        $response->assertDontSee('Other Folder Address');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame('', $xpath->query("//*[@name='start_date']")->item(0)->getAttribute('value'));
    }

    public function test_another_client_folders_cibi_never_leaks_into_this_folders_residence_prefill(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $otherFolder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Other Folder Address']);
        $otherFolder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => '2026-01-01', 'personal_snapshot' => ['present_address' => 'Other Folder CIBI Address']]);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();

        $response->assertDontSee('Other Folder CIBI Address');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
