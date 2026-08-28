<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantCibiResidencePrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_applicant_cibi_created_first_prefills_new_residence_for_a_different_ci(): void
    {
        $firstCi = User::factory()->create(['full_name' => 'First CIBI Investigator']);
        $secondCi = User::factory()->create(['full_name' => 'Second Residence Investigator']);
        $folder = $this->applicantFolder($firstCi, 'MICABALO, RONILO CABIGAS');
        $cibi = $folder->cibiReports()->create([
            'ci_in_charge_id' => $firstCi->id,
            'start_date' => '2026-07-14',
            'personal_snapshot' => ['present_address' => 'CIBI Verified Applicant Address'],
        ]);
        $folder->update(['assigned_ci_id' => $secondCi->id]);

        $response = $this->actingAs($secondCi)->get(route('client-folders.residence-checks.create', $folder))->assertOk();
        $response->assertSee('MICABALO, RONILO CABIGAS')
            ->assertSee('CIBI Verified Applicant Address')
            ->assertSee('July 14, 2026')
            ->assertDontSee('name="ci_date"', false);

        $xpath = $this->xpath($response->getContent());
        $location = $xpath->query("//*[@id='residence-location']")->item(0);
        $this->assertSame('Second Residence Investigator', trim($xpath->query('//*[@data-ci-primary-name]')->item(0)->textContent));
        $this->assertFalse($location->hasAttribute('readonly'));
        $this->assertSame('location', $location->getAttribute('name'));
        $this->assertTrue($location->hasAttribute('required'));
        $this->assertSame('CIBI Verified Applicant Address', $location->getAttribute('value'));

        $this->actingAs($secondCi)->post(route('client-folders.residence-checks.store', $folder), [
            'location' => 'Residence CI Corrected Address',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->whereNull('co_maker_id')->firstOrFail();
        $this->assertSame('Residence CI Corrected Address', $check->location);
        $this->assertSame($secondCi->id, $check->ci_user_id);
        $this->assertSame('CIBI Verified Applicant Address', $cibi->fresh()->personal_snapshot['present_address']);
        $this->assertSame('2026-07-14', $cibi->fresh()->start_date->toDateString());
        $this->assertSame($firstCi->id, $cibi->fresh()->ci_in_charge_id);
    }

    public function test_applicant_residence_can_be_created_before_cibi_with_its_ci_date(): void
    {
        $ci = User::factory()->create();
        $folder = $this->applicantFolder($ci, 'RESIDENCE FIRST APPLICANT');
        $folder->addresses()->delete();

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()
            ->assertSee('name="ci_date"', false)
            ->assertSee('name="location"', false)
            ->assertDontSee('No Start Date of CI available. Please update the CI/BI Report before creating a Residence Check.');
        $location = $this->xpath($response->getContent())->query("//*[@id='residence-location']")->item(0);
        $this->assertFalse($location->hasAttribute('readonly'));
        $this->assertTrue($location->hasAttribute('required'));

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => '2026-08-10',
            'location' => 'Residence First Manual Address',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->whereNull('co_maker_id')->firstOrFail();
        $this->assertSame('2026-08-10', $check->ci_date->toDateString());
        $this->assertSame('Residence First Manual Address', $check->location);
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    public function test_applicant_residence_created_first_prefills_later_new_cibi_without_copying_ci_identity(): void
    {
        $residenceCi = User::factory()->create(['full_name' => 'Residence First Investigator']);
        $cibiCi = User::factory()->create(['full_name' => 'Later CIBI Investigator']);
        $folder = $this->applicantFolder($residenceCi, 'PREFILL, APPLICANT NAME');
        $folder->addresses()->delete();
        $this->actingAs($residenceCi)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => '2026-06-21',
            'location' => 'Residence Snapshot Applicant Address',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();
        $originalCheck = $check->getRawOriginal();

        // Prove the new CIBI form falls back to the manually saved Residence snapshot;
        // merely opening the form must not alter it.
        $folder->update(['assigned_ci_id' => $cibiCi->id]);
        $response = $this->actingAs($cibiCi)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame('2026-06-21', $xpath->query("//*[@name='start_date']")->item(0)->getAttribute('value'));
        $this->assertSame('PREFILL, APPLICANT NAME', $xpath->query("//*[@name='personal_snapshot[name]']")->item(0)->getAttribute('value'));
        $this->assertSame('Residence Snapshot Applicant Address', trim($xpath->query("//*[@name='personal_snapshot[present_address]']")->item(0)->textContent));
        $this->assertSame('Later CIBI Investigator', trim($xpath->query("(//*[contains(concat(' ', normalize-space(@class), ' '), ' cibi-readonly-field ')])[1]")->item(0)->textContent));
        $this->assertSame($originalCheck, $check->fresh()->getRawOriginal());
        $this->assertDatabaseCount('cibi_reports', 0);
    }

    public function test_co_maker_report_data_never_prefills_applicant_forms(): void
    {
        $ci = User::factory()->create();
        $folder = $this->applicantFolder($ci, 'APPLICANT ONLY');
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Private Address']);
        $folder->cibiReports()->create([
            'co_maker_id' => $coMaker->id,
            'ci_in_charge_id' => $ci->id,
            'start_date' => '2025-01-02',
            'personal_snapshot' => ['present_address' => 'Co-Maker CIBI Address'],
        ]);
        $folder->residenceChecks()->create([
            'co_maker_id' => $coMaker->id,
            'ci_user_id' => $ci->id,
            'updated_by' => $ci->id,
            'ci_date' => '2025-01-02',
            'location' => 'Co-Maker Residence Address',
        ]);

        $residenceResponse = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk();
        $residenceResponse->assertSee('Applicant Shared Address')
            ->assertSee('name="ci_date"', false)
            ->assertDontSee('Co-Maker CIBI Address')
            ->assertDontSee('Co-Maker Residence Address');

        $cibiResponse = $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))->assertOk();
        $cibiResponse->assertSee('Applicant Shared Address')
            ->assertDontSee('Co-Maker CIBI Address')
            ->assertDontSee('Co-Maker Residence Address');
        $this->assertSame('', $this->xpath($cibiResponse->getContent())->query("//*[@name='start_date']")->item(0)->getAttribute('value'));
        $this->assertDatabaseCount('cibi_reports', 1);
        $this->assertDatabaseCount('residence_checks', 1);
    }

    private function applicantFolder(User $ci, string $name): ClientFolder
    {
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => $name,
        ]);
        $folder->addresses()->create([
            'address_type' => 'present',
            'address_line_1' => 'Applicant Shared Address',
        ]);

        return $folder;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
