<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessReportCompanionOnlyUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_applicant_add_remove_replace_and_no_change_detection_use_only_companion_state(): void
    {
        $primary = User::factory()->create();
        $companionB = User::factory()->create();
        $companionC = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $primary->id]);
        $applicantSource = $this->business($primary, $folder, null, 'Applicant Business');
        $otherApplicantBusiness = $this->business($primary, $folder, null, 'Other Applicant Business');
        $coMaker = $this->coMaker($folder, 'Co-Maker A');
        $coMakerSource = $this->business($primary, $folder, $coMaker, 'Co-Maker Business');
        $payload = $this->establishBaseline($primary, $folder, $applicantSource, null);

        $this->saveCompanions($primary, $folder, $applicantSource, $payload, [$companionB->id])
            ->assertSessionHas('statusType', 'success')
            ->assertSessionMissing('status', 'Nothing changed. No updates were saved to the database.');
        $this->assertSame([$companionB->id], $applicantSource->refresh()->contributors()->pluck('users.id')->all());

        $this->saveCompanions($primary, $folder, $applicantSource, $payload, [])
            ->assertSessionHas('statusType', 'success');
        $this->assertSame([], $applicantSource->refresh()->contributors()->pluck('users.id')->all());

        $this->saveCompanions($primary, $folder, $applicantSource, $payload, [$companionB->id])
            ->assertSessionHas('statusType', 'success');
        $this->saveCompanions($primary, $folder, $applicantSource, $payload, [$companionC->id])
            ->assertSessionHas('statusType', 'success');
        $this->assertSame([$companionC->id], $applicantSource->refresh()->contributors()->pluck('users.id')->all());

        $revisionBeforeNoChange = $applicantSource->refresh()->revision;
        $this->saveCompanions($primary, $folder, $applicantSource, $payload, [$companionC->id])
            ->assertSessionHas('statusType', 'info')
            ->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');
        $this->assertSame($revisionBeforeNoChange, $applicantSource->refresh()->revision);

        $normalFieldPayload = $this->exactPayload($applicantSource, $payload, [$companionC->id]);
        $normalFieldPayload['report_remarks'] = 'Only a normal report field changed.';
        $this->actingAs($primary)
            ->post(route('client-folders.income-sources.business.update', [$folder, $applicantSource]), ['_method' => 'PUT'] + $normalFieldPayload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('statusType', 'success');

        $this->assertSame([], $otherApplicantBusiness->contributors()->pluck('users.id')->all());
        $this->assertSame([], $coMakerSource->contributors()->pluck('users.id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'income_source.contributor_added', 'client_folder_id' => $folder->id, 'user_id' => $primary->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'income_source.contributor_removed', 'client_folder_id' => $folder->id, 'user_id' => $primary->id]);
        $this->assertSame(3, AuditLog::where('client_folder_id', $folder->id)->where('action', 'income_source.contributor_added')->count());
        $this->assertSame(2, AuditLog::where('client_folder_id', $folder->id)->where('action', 'income_source.contributor_removed')->count());
    }

    public function test_co_maker_add_remove_replace_and_no_change_detection_are_exactly_isolated(): void
    {
        $primary = User::factory()->create();
        $companionB = User::factory()->create();
        $companionC = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $primary->id]);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $primary->id]);
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        $targetSource = $this->business($primary, $folder, $coMakerA, 'Co-Maker A Business');
        $otherBusinessSamePerson = $this->business($primary, $folder, $coMakerA, 'Co-Maker A Other Business');
        $coMakerBSource = $this->business($primary, $folder, $coMakerB, 'Co-Maker B Business');
        $applicantSource = $this->business($primary, $folder, null, 'Applicant Business');
        $otherFolderSource = $this->business($primary, $otherFolder, null, 'Other Folder Business');
        $payload = $this->establishBaseline($primary, $folder, $targetSource, $coMakerA);

        $this->saveCompanions($primary, $folder, $targetSource, $payload, [$companionB->id], $coMakerA)
            ->assertSessionHas('statusType', 'success');
        $this->saveCompanions($primary, $folder, $targetSource, $payload, [])
            ->assertSessionHas('statusType', 'success');
        $this->saveCompanions($primary, $folder, $targetSource, $payload, [$companionB->id], $coMakerA)
            ->assertSessionHas('statusType', 'success');
        $this->saveCompanions($primary, $folder, $targetSource, $payload, [$companionC->id], $coMakerA)
            ->assertSessionHas('statusType', 'success');

        $this->assertSame([$companionC->id], $targetSource->refresh()->contributors()->pluck('users.id')->all());
        foreach ([$otherBusinessSamePerson, $coMakerBSource, $applicantSource, $otherFolderSource] as $unrelatedSource) {
            $this->assertSame([], $unrelatedSource->contributors()->pluck('users.id')->all());
        }

        $revisionBeforeNoChange = $targetSource->refresh()->revision;
        $this->saveCompanions($primary, $folder, $targetSource, $payload, [$companionC->id], $coMakerA)
            ->assertSessionHas('statusType', 'info')
            ->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');
        $this->assertSame($revisionBeforeNoChange, $targetSource->refresh()->revision);

        $normalFieldPayload = $this->exactPayload($targetSource, $payload, [$companionC->id], $coMakerA);
        $normalFieldPayload['report_remarks'] = 'Only the Co-Maker report field changed.';
        $this->actingAs($primary)
            ->post(route('client-folders.income-sources.business.update', [$folder, $targetSource]), ['_method' => 'PUT'] + $normalFieldPayload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('statusType', 'success');

        $this->assertSame(3, AuditLog::where('client_folder_id', $folder->id)->where('action', 'income_source.contributor_added')->count());
        $this->assertSame(2, AuditLog::where('client_folder_id', $folder->id)->where('action', 'income_source.contributor_removed')->count());
    }

    public function test_real_applicant_and_co_maker_forms_bind_picker_values_to_the_submitted_payload(): void
    {
        $primary = User::factory()->create();
        $companion = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $primary->id]);
        $coMaker = $this->coMaker($folder, 'Co-Maker Form Person');
        $applicantSource = $this->business($primary, $folder, null, 'Applicant Form Business');
        $coMakerSource = $this->business($primary, $folder, $coMaker, 'Co-Maker Form Business');
        app(CiParticipantService::class)->syncCompanions($applicantSource, [$companion->id]);
        app(CiParticipantService::class)->syncCompanions($coMakerSource, [$companion->id]);

        $this->actingAs($primary)
            ->get(route('client-folders.income-sources.edit', [$folder, $applicantSource]))
            ->assertOk()
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="contributor_ids[]" value="'.$companion->id.'" form="business-report-form"', false)
            ->assertSee('name="contributor_ids_present" value="1" form="business-report-form"', false);

        $this->actingAs($primary)
            ->get(route('client-folders.income-sources.edit', [$folder, $coMakerSource, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk()
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="contributor_ids[]" value="'.$companion->id.'" form="business-report-form"', false)
            ->assertSee('name="contributor_ids_present" value="1" form="business-report-form"', false);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("form.addEventListener('submit', () => { writeHiddenInputs(visibleIds()); });", $script);
        $this->assertStringContainsString("form.addEventListener('formdata'", $script);
        $this->assertStringContainsString("event.formData.delete('contributor_ids[]')", $script);
        $this->assertStringContainsString("event.formData.append('contributor_ids[]', id)", $script);
        $this->assertStringContainsString("event.formData.set('contributor_ids_present', '1')", $script);
        $this->assertStringContainsString("event.formData.set('_method', methodOverride.value)", $script);
    }

    public function test_companion_only_changes_preserve_unchanged_legacy_validation_gaps_for_applicant_and_co_maker(): void
    {
        $primary = User::factory()->create();
        $companionB = User::factory()->create();
        $companionC = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $primary->id]);
        $coMaker = $this->coMaker($folder, 'Legacy Co-Maker');
        $applicantSource = $this->legacyBusiness($primary, $folder, null, 'Legacy Applicant Business');
        $coMakerSource = $this->legacyBusiness($primary, $folder, $coMaker, 'Legacy Co-Maker Business');
        $unrelatedSource = $this->business($primary, $folder, null, 'Unrelated Complete Business');

        foreach ([[$applicantSource, null], [$coMakerSource, $coMaker]] as [$source, $person]) {
            $this->saveLegacyCompanions($primary, $folder, $source, $person, [$companionB->id])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('statusType', 'success');
            $this->saveLegacyCompanions($primary, $folder, $source, $person, [])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('statusType', 'success');
            $this->saveLegacyCompanions($primary, $folder, $source, $person, [$companionB->id])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('statusType', 'success');
            $this->saveLegacyCompanions($primary, $folder, $source, $person, [$companionC->id])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('statusType', 'success');

            $this->assertSame([$companionC->id], $source->refresh()->contributors()->pluck('users.id')->all());
            $this->assertDatabaseHas('business_reports', [
                'income_source_id' => $source->id,
                'start_date' => null,
                'main_business_address' => null,
                'year_established' => null,
                'registered_owner' => null,
            ]);
        }

        $this->assertSame([], $unrelatedSource->contributors()->pluck('users.id')->all());
        $this->actingAs($primary)
            ->get(route('client-folders.income-sources.edit', [$folder, $applicantSource]))
            ->assertOk()
            ->assertSee('value="'.$companionC->id.'" form="business-report-form"', false);
        $this->actingAs($primary)
            ->get(route('client-folders.income-sources.edit', [$folder, $coMakerSource, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertOk()
            ->assertSee('value="'.$companionC->id.'" form="business-report-form"', false);
    }

    public function test_invalid_business_edits_and_missing_report_creation_still_require_valid_fields(): void
    {
        $primary = User::factory()->create();
        $savedCompanion = User::factory()->create();
        $replacement = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $primary->id]);
        $coMaker = $this->coMaker($folder, 'Validation Co-Maker');
        $applicantSource = $this->legacyBusiness($primary, $folder, null, 'Invalid Applicant Edit');
        $coMakerSource = $this->legacyBusiness($primary, $folder, $coMaker, 'Invalid Co-Maker Edit');

        foreach ([[$applicantSource, null], [$coMakerSource, $coMaker]] as [$source, $person]) {
            app(CiParticipantService::class)->syncCompanions($source, [$savedCompanion->id]);
            $payload = $this->legacyPayload($source, $person, [$replacement->id]);
            $payload['year_established'] = 'not-a-year';

            $this->actingAs($primary)
                ->post(route('client-folders.income-sources.business.update', [$folder, $source]), ['_method' => 'PUT'] + $payload)
                ->assertSessionHasErrors('year_established');
            $this->assertSame([$savedCompanion->id], $source->refresh()->contributors()->pluck('users.id')->all());

            $source->businessReport()->firstOrFail()->forceDelete();
            $this->actingAs($primary)
                ->post(route('client-folders.income-sources.business.update', [$folder, $source]), ['_method' => 'PUT'] + $this->legacyPayload($source, $person, [$replacement->id]))
                ->assertSessionHasErrors(['start_date', 'main_business_address', 'year_established']);
            $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);
        }
    }

    private function saveCompanions(User $actor, ClientFolder $folder, IncomeSource $source, array $baseline, array $companionIds, ?CoMaker $coMaker = null)
    {
        return $this->actingAs($actor)->post(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            ['_method' => 'PUT'] + $this->exactPayload($source, $baseline, $companionIds, $coMaker),
        )->assertRedirect()->assertSessionHasNoErrors();
    }

    private function establishBaseline(User $actor, ClientFolder $folder, IncomeSource $source, ?CoMaker $coMaker): array
    {
        $payload = [
            'intent' => 'stay',
            'co_maker_id' => $coMaker?->id,
            'source_name' => $source->source_name,
            'business_name' => $source->business_name,
            'report_category' => 'Leasing',
            'main_business_address' => 'Exact Business Address',
            'start_date' => '2026-08-28',
            'registered_owner' => 'Exact Registered Owner',
            'year_established' => 2020,
            'properties' => [[
                'property_type' => 'Apartment',
                'is_declared' => true,
                'is_inspected' => true,
                'location' => 'Exact Business Address',
                'units_available' => '4',
                'units_with_tenants' => '3',
            ]],
            'tenants' => [],
        ];

        $this->actingAs($actor)
            ->put(route('client-folders.income-sources.business.update', [$folder, $source]), $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('statusType', 'success');

        $payload['properties'][0]['id'] = $source->refresh()->businessReport->properties()->sole()->id;

        return $payload;
    }

    private function exactPayload(IncomeSource $source, array $baseline, array $companionIds, ?CoMaker $coMaker = null): array
    {
        return $baseline + [
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->refresh()->revision,
            'contributor_ids_present' => '1',
            'contributor_ids' => $companionIds,
        ];
    }

    private function saveLegacyCompanions(User $actor, ClientFolder $folder, IncomeSource $source, ?CoMaker $coMaker, array $companionIds)
    {
        return $this->actingAs($actor)
            ->post(route('client-folders.income-sources.business.update', [$folder, $source]), ['_method' => 'PUT'] + $this->legacyPayload($source, $coMaker, $companionIds))
            ->assertRedirect();
    }

    private function legacyPayload(IncomeSource $source, ?CoMaker $coMaker, array $companionIds): array
    {
        return [
            'intent' => 'complete',
            'co_maker_id' => $coMaker?->id,
            'expected_revision' => $source->refresh()->revision,
            'source_name' => $source->source_name,
            'business_name' => $source->business_name,
            'report_category' => $source->businessReport?->report_category ?? 'Leasing',
            'contributor_ids_present' => '1',
            'contributor_ids' => $companionIds,
            'properties' => [],
            'tenants' => [],
        ];
    }

    private function legacyBusiness(User $actor, ClientFolder $folder, ?CoMaker $coMaker, string $name): IncomeSource
    {
        $source = $this->business($actor, $folder, $coMaker, $name);
        $source->forceFill(['state' => RecordState::Complete])->save();
        $source->businessReport()->firstOrFail()->forceFill([
            'start_date' => null,
            'main_business_address' => null,
            'year_established' => null,
            'registered_owner' => null,
        ])->save();

        return $source->refresh();
    }

    private function business(User $actor, ClientFolder $folder, ?CoMaker $coMaker, string $name): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        return app(CreateIncomeSource::class)->execute($actor, $folder, [
            'income_source_template_id' => $template->id,
            'source_name' => $name,
            'business_name' => $name,
            'co_maker_id' => $coMaker?->id,
        ]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return $folder->coMakers()->create(['full_name' => $name]);
    }
}
