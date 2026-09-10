<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Enums\UserStatus;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiCiSelectorEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_all_supported_applicant_and_co_maker_create_and_edit_selectors_use_the_record_creator_and_both_ci_roles(): void
    {
        $a = User::factory()->create(['full_name' => 'USER A FOLDER CREATOR']);
        $b = User::factory()->seniorCreditInvestigator()->create(['full_name' => 'USER B RECORD CREATOR']);
        $c = User::factory()->create(['full_name' => 'USER C ACTIVE CI']);
        $d = User::factory()->seniorCreditInvestigator()->create(['full_name' => 'USER D ACTIVE SENIOR CI']);
        $e = User::factory()->create(['full_name' => 'USER E INACTIVE CI', 'status' => UserStatus::Disabled]);
        $f = User::factory()->administrator()->create(['full_name' => 'USER F ADMIN']);
        $folder = ClientFolder::factory()->create(['created_by' => $a->id, 'assigned_ci_id' => $a->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'CO MAKER EXACT PERSON']);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        foreach ([null, $coMaker->id] as $coMakerId) {
            $personParams = ActivePersonResolver::queryParams($coMakerId ? $coMaker : null);

            // Create: the authenticated creator B is excluded; folder creator A remains eligible.
            $createResponses = [
                $this->actingAs($b)->get(route('client-folders.income-sources.index', [$folder, 'income_source_template_id' => $template->id] + $personParams))->assertOk(),
                $this->actingAs($b)->get(route('client-folders.residence-checks.create', [$folder] + $personParams))->assertOk(),
                $this->actingAs($b)->get(route('client-folders.business-checks.create', [$folder] + $personParams))->assertOk(),
            ];
            foreach ($createResponses as $response) {
                $this->assertCandidateIds($response, [$a->id, $c->id, $d->id], [$b->id, $e->id, $f->id]);
            }

            $source = app(CreateIncomeSource::class)->execute($b, $folder, [
                'co_maker_id' => $coMakerId,
                'income_source_template_id' => $template->id,
                'source_name' => $coMakerId ? 'CO-MAKER REPORT' : 'APPLICANT REPORT',
                'business_name' => 'SAVED BUSINESS',
            ]);
            $residence = ResidenceCheck::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
                'ci_user_id' => $b->id, 'ci_date' => now()->toDateString(), 'location' => 'Saved residence',
            ]);
            $business = BusinessCheck::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId,
                'ci_user_id' => $b->id, 'ci_date' => now()->toDateString(), 'location' => 'Saved business',
                'business_name' => 'Manual saved business',
            ]);

            // Historical inactive contributors remain loaded, but cannot appear as new choices.
            foreach ([$source, $residence, $business] as $record) {
                app(CiParticipantService::class)->syncCompanions($record, [$e->id]);
            }

            // Edit by C: persisted creator B stays excluded; current editor C and folder creator A
            // remain eligible. Applicant and this exact Co-Maker use their own scoped records.
            $editResponses = [
                $this->actingAs($c)->get(route('client-folders.income-sources.edit', [$folder, $source] + $personParams))->assertOk(),
                $this->actingAs($c)->get(route('client-folders.residence-checks.edit', [$folder, $residence] + $personParams))->assertOk(),
                $this->actingAs($c)->get(route('client-folders.business-checks.edit', [$folder, $business] + $personParams))->assertOk(),
            ];
            foreach ($editResponses as $response) {
                $this->assertCandidateIds($response, [$a->id, $c->id, $d->id], [$b->id, $e->id, $f->id]);
                $this->assertSame([$e->id], $response->original->getData()['companions']->pluck('id')->all());
            }

            $this->actingAs($c)->put(route('client-folders.income-sources.contributors.update', [$folder, $source] + $personParams), ['contributor_ids' => [$e->id]])
                ->assertSessionHasNoErrors();
            $this->actingAs($c)->put(route('client-folders.residence-checks.contributors.update', [$folder, $residence] + $personParams), ['contributor_ids' => [$e->id]])
                ->assertSessionHasNoErrors();
            $this->actingAs($c)->put(route('client-folders.business-checks.contributors.update', [$folder, $business] + $personParams), ['contributor_ids' => [$e->id]])
                ->assertSessionHasNoErrors();
            foreach ([$source, $residence, $business] as $record) {
                $this->assertSame([$e->id], $record->fresh()->contributors()->pluck('users.id')->all());
            }
        }
    }

    private function assertCandidateIds($response, array $included, array $excluded): void
    {
        $ids = $response->original->getData()['activeCreditInvestigators']->pluck('id')->all();
        foreach ($included as $id) {
            $this->assertContains($id, $ids);
        }
        foreach ($excluded as $id) {
            $this->assertNotContains($id, $ids);
        }
    }
}
