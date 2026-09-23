<?php

namespace Tests\Feature\ClientFolders;

use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CibiReportEditingPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const ADVICE = 'You may continue reviewing the form, but if they save changes first, you will need to refresh before saving your changes.';

    public function test_applicant_and_co_maker_forms_use_the_business_report_presence_guidance_in_exact_report_scope(): void
    {
        [$ci, $folder, $applicant, $coMaker, $coMakerReport] = $this->context();

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', $folder))
            ->assertOk()
            ->assertSee('data-editing-type="cibi_report"', false)
            ->assertSee('data-editing-id="'.$applicant->id.'"', false)
            ->assertSee('data-editing-advice="'.self::ADVICE.'"', false)
            ->assertDontSee('data-editing-id="'.$coMakerReport->id.'"', false);

        $this->actingAs($ci)->get(route('client-folders.cibi-report.edit', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
        ]))
            ->assertOk()
            ->assertSee('data-editing-id="'.$coMakerReport->id.'"', false)
            ->assertSee('data-editing-advice="'.self::ADVICE.'"', false)
            ->assertDontSee('data-editing-id="'.$applicant->id.'"', false);
    }

    public function test_valid_presence_is_shared_only_for_the_same_applicant_or_exact_co_maker_report(): void
    {
        [$first, , $applicant, , $coMakerReport] = $this->context();
        $second = User::factory()->create(['full_name' => 'Second CI']);

        $this->heartbeat($first, $applicant)->assertOk()->assertJson(['other_editors' => []]);
        $this->heartbeat($second, $coMakerReport)->assertOk()->assertJson(['other_editors' => []]);

        $this->assertSame(
            [$first->full_name],
            collect($this->heartbeat($second, $applicant)->json('other_editors'))->pluck('name')->all(),
        );
        $this->assertSame(
            [$second->full_name],
            collect($this->heartbeat($first, $coMakerReport)->json('other_editors'))->pluck('name')->all(),
        );
    }

    public function test_closing_the_cibi_modal_releases_presence_and_discards_the_stale_iframe_document(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $closeLifecycle = substr(
            $javascript,
            strpos($javascript, "if (dialog.matches('[data-cibi-report-dialog]')) {"),
            1400,
        );

        $this->assertStringContainsString("frameDocument?.dispatchEvent(new Event('editing-presence-release'));", $closeLifecycle);
        $this->assertStringContainsString("form.dispatchEvent(new Event('unsaved-form-reset'))", $closeLifecycle);
        $this->assertStringContainsString("frame.src = 'about:blank';", $closeLifecycle);

        $presenceLifecycle = substr($javascript, strpos($javascript, "document.querySelectorAll('[data-editing-presence]')"));
        $this->assertStringContainsString("document.addEventListener('editing-presence-release', release);", $presenceLifecycle);
        $this->assertStringContainsString("window.addEventListener('pagehide', release);", $presenceLifecycle);

        $modal = file_get_contents(resource_path('views/components/ui/cibi-report-modal.blade.php'));
        $this->assertMatchesRegularExpression('/<iframe(?![^>]*\ssrc=)[^>]*data-cibi-report-frame[^>]*><\/iframe>/', $modal);
    }

    /** @return array{0: User, 1: ClientFolder, 2: CibiReport, 3: CoMaker, 4: CibiReport} */
    private function context(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $applicant = CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'co_maker_id' => null,
        ]);
        $coMakerReport = CibiReport::factory()->create([
            'client_folder_id' => $folder->id,
            'ci_in_charge_id' => $ci->id,
            'co_maker_id' => $coMaker->id,
        ]);

        return [$ci, $folder, $applicant, $coMaker, $coMakerReport];
    }

    private function heartbeat(User $user, CibiReport $report)
    {
        return $this->actingAs($user)->postJson(route('editing-presence.heartbeat'), [
            'type' => 'cibi_report',
            'id' => $report->id,
        ]);
    }
}
