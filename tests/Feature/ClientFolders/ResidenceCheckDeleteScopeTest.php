<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\Progress\ClientProgressService;
use App\Services\Storage\CiTeamDocumentStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression: the Residence Check delete dialog posted to the destroy route without the active
 * person's query parameters, so a Co-Maker's own Residence Check resolved to the Applicant and
 * ActivePersonResolver::assertOwnedBy() answered 404. These tests submit the exact URL the page
 * renders, for the Applicant and for a Co-Maker, and keep every cross-scope attempt a 404.
 */
class ResidenceCheckDeleteScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $ci;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        $this->ci = User::factory()->create();
    }

    public function test_the_rendered_delete_dialog_deletes_the_applicants_residence_check(): void
    {
        $folder = $this->folder();
        $check = $this->check($folder, null);
        $action = $this->renderedDeleteAction($folder, $check, []);

        $this->assertSame(route('client-folders.residence-checks.destroy', [$folder, $check]), $action);
        $this->actingAs($this->ci)->delete($action)
            ->assertRedirect(route('client-folders.residence-business.edit', $folder))
            ->assertSessionHas('status', 'Residence Check deleted successfully.');
        $this->assertModelMissing($check);
    }

    public function test_the_rendered_delete_dialog_deletes_a_co_makers_own_residence_check_without_a_404(): void
    {
        $folder = $this->folder();
        $coMaker = $this->coMaker($folder, 'Co-Maker A');
        $check = $this->check($folder, $coMaker->id);
        $person = ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];
        $action = $this->renderedDeleteAction($folder, $check, $person);

        $this->assertSame(route('client-folders.residence-checks.destroy', [$folder, $check] + $person), $action);
        $this->actingAs($this->ci)->delete($action)
            ->assertRedirect(route('client-folders.residence-business.edit', [$folder] + $person));
        $this->assertModelMissing($check);
        $this->assertTrue(AuditLog::query()->where('client_folder_id', $folder->id)->where('action', 'residence_check.deleted')->exists());
        $this->assertSame($coMaker->id, AuditLog::query()->where('action', 'residence_check.deleted')->sole()->metadata['co_maker_id']);
    }

    public function test_cross_person_and_cross_folder_deletes_stay_not_found(): void
    {
        $folder = $this->folder();
        $coMakerA = $this->coMaker($folder, 'Co-Maker A');
        $coMakerB = $this->coMaker($folder, 'Co-Maker B');
        $applicantCheck = $this->check($folder, null);
        $checkA = $this->check($folder, $coMakerA->id);
        $checkB = $this->check($folder, $coMakerB->id);
        $otherFolder = $this->folder();
        $asA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $this->actingAs($this->ci);

        // Applicant scope cannot delete a Co-Maker's check, and a Co-Maker cannot delete the Applicant's.
        $this->delete(route('client-folders.residence-checks.destroy', [$folder, $checkA]))->assertNotFound();
        $this->delete(route('client-folders.residence-checks.destroy', [$folder, $applicantCheck] + $asA))->assertNotFound();
        // Co-Maker A cannot delete Co-Maker B's check.
        $this->delete(route('client-folders.residence-checks.destroy', [$folder, $checkB] + $asA))->assertNotFound();
        // A check is unreachable through another folder.
        $this->delete(route('client-folders.residence-checks.destroy', [$otherFolder, $applicantCheck]))->assertNotFound();

        foreach ([$applicantCheck, $checkA, $checkB] as $check) {
            $this->assertModelExists($check);
        }
        $this->assertFalse(AuditLog::query()->where('action', 'residence_check.deleted')->exists());
    }

    public function test_deleting_refreshes_folder_progress_and_removes_its_photo(): void
    {
        $folder = $this->folder();
        CibiReport::factory()->create(['client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_in_charge_id' => $this->ci->id, 'state' => RecordState::Complete]);
        $this->actingAs($this->ci)->post(route('client-folders.residence-checks.store', $folder), [
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photos' => [UploadedFile::fake()->image('Residence.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->with('photos')->sole();
        $photoPath = $check->photos->sole()->path;
        $disk = app(CiTeamDocumentStorage::class)->disk();
        $this->assertTrue($disk->exists($photoPath));
        $this->assertEquals(28.57, (float) $folder->fresh()->progress_percent); // CIBI + Residence Check of 7

        $this->delete($this->renderedDeleteAction($folder, $check, []))->assertRedirect();

        $this->assertModelMissing($check);
        $this->assertFalse($disk->exists($photoPath), 'The local photo file must be cleaned up with the check.');
        $this->assertEquals(14.29, (float) $folder->fresh()->progress_percent); // CIBI only
        $this->assertEquals(14.29, app(ClientProgressService::class)->calculate($folder->fresh())->percentage);
    }

    /** Reads the delete form action straight from the rendered Residence & Business page. */
    private function renderedDeleteAction(ClientFolder $folder, ResidenceCheck $check, array $person): string
    {
        $html = $this->actingAs($this->ci)->get(route('client-folders.residence-business.edit', [$folder] + $person))->assertOk()->getContent();
        $start = strpos($html, 'id="delete-residence-check-'.$check->id.'"');
        $this->assertNotFalse($start, 'Delete dialog not rendered.');
        preg_match('/<form method="POST" action="([^"]+)"/', substr($html, $start), $form);
        $this->assertStringContainsString('name="_method" value="DELETE"', substr($html, $start, 2000));

        return html_entity_decode($form[1] ?? '');
    }

    private function folder(): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'created_by' => $this->ci->id]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        return CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => $name]);
    }

    private function check(ClientFolder $folder, ?int $coMakerId): ResidenceCheck
    {
        $check = new ResidenceCheck;
        $check->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerId, 'ci_date' => now()->toDateString(),
            'location' => 'Saved location', 'ci_user_id' => $this->ci->id,
        ])->save();

        return $check;
    }
}
