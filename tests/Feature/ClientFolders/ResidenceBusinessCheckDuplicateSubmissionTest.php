<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Manage Residence & Business Report — Delete pending state and repeated-delete safety.
 *
 * Add/Edit duplicate protection (request_token replay, one Residence Check per person, the linked
 * Business Check rule, expected_revision) is already covered by ResidenceCheckDuplicateSubmitTest,
 * ManualBusinessCheckDuplicateGuardTest, the *ConcurrencyTest and *DeleteConcurrencyTest classes.
 * This class pins only what those do not: the single-delete dialogs opting into the shared submit
 * guard, the batch delete pending state, the Updating… label, and that a repeated delete is a
 * harmless not-found that never touches another record.
 *
 * The client-side pending state is browser JavaScript, so it is pinned here as a markup/source
 * contract; real rapid-click behavior on a throttled connection still needs a manual browser check.
 */
class ResidenceBusinessCheckDuplicateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_single_delete_dialogs_opt_into_the_shared_guard_with_a_deleting_label(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $residence = $this->residenceCheck($ci, $folder);
        $business = $this->businessCheck($ci, $folder, $this->businessSource($folder, 'Guarded Store'));

        $html = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        foreach (['delete-residence-check-'.$residence->id, 'delete-business-check-'.$business->id] as $dialogId) {
            $start = strpos($html, 'id="'.$dialogId.'"');
            $this->assertNotFalse($start, $dialogId.' not rendered.');
            $this->assertMatchesRegularExpression(
                '/<form method="POST" action="[^"]+" class="w-full sm:w-auto" data-submit-guard data-submit-pending-label="Deleting\.\.\.">/',
                substr($html, $start, 3000),
            );
        }
    }

    public function test_a_confirmation_dialog_without_a_pending_label_renders_exactly_as_before(): void
    {
        $html = Blade::render('<x-ui.confirmation-dialog id="plain" title="Plain" action="/plain" method="DELETE" confirm-label="Delete" destructive>Body</x-ui.confirmation-dialog>');

        $this->assertStringContainsString('<form method="POST" action="/plain" class="w-full sm:w-auto">', $html);
        $this->assertStringNotContainsString('data-submit-guard', $html);
        $this->assertStringNotContainsString('data-submit-pending-label', $html);
    }

    public function test_a_repeated_residence_check_delete_is_a_not_found_that_touches_nothing_else(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $residence = $this->residenceCheck($ci, $folder);
        $business = $this->businessCheck($ci, $folder, $this->businessSource($folder, 'Kept Store'));
        $url = route('client-folders.residence-checks.destroy', [$folder, $residence]);

        $this->actingAs($ci)->delete($url)
            ->assertRedirect(route('client-folders.residence-business.edit', $folder))
            ->assertSessionHas('status', 'Residence Check deleted successfully.');
        $this->assertModelMissing($residence);

        // Double click / retry of the same delete.
        $this->actingAs($ci)->delete($url)->assertNotFound();

        $this->assertModelExists($business);
    }

    public function test_a_repeated_business_check_delete_is_a_not_found_that_touches_nothing_else(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $deleted = $this->businessCheck($ci, $folder, $this->businessSource($folder, 'Deleted Store'));
        $kept = $this->businessCheck($ci, $folder, $this->businessSource($folder, 'Kept Store'));
        $url = route('client-folders.business-checks.destroy', [$folder, $deleted]);

        $this->actingAs($ci)->delete($url)
            ->assertRedirect(route('client-folders.residence-business.edit', $folder))
            ->assertSessionHas('status', 'Business Check permanently deleted.');
        $this->assertModelMissing($deleted);

        $this->actingAs($ci)->delete($url)->assertNotFound();

        $this->assertModelExists($kept);
        $this->assertNotNull(IncomeSource::query()->where('business_name', 'Deleted Store')->first(), 'The Business Report side is never removed by a check delete.');
    }

    public function test_a_repeated_batch_delete_is_refused_whole_and_never_deletes_an_unselected_check(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $residence = $this->residenceCheck($ci, $folder);
        $selected = $this->businessCheck($ci, $folder, $this->businessSource($folder, 'Selected Store'));
        $kept = $this->businessCheck($ci, $folder, $this->businessSource($folder, 'Unselected Store'));
        $payload = ['residence_check_ids' => [$residence->id], 'business_check_ids' => [$selected->id]];

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-delete', $folder), $payload)
            ->assertRedirect(route('client-folders.residence-business.edit', $folder))
            ->assertSessionHas('status', 'Selected reports deleted successfully.');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-delete', $folder), $payload)->assertNotFound();

        $this->assertSame(0, ResidenceCheck::query()->count());
        $this->assertSame([$kept->id], BusinessCheck::query()->pluck('id')->all());
    }

    public function test_deletes_stay_scoped_to_the_folder_and_require_sign_in(): void
    {
        [$ci, $folder] = $this->ciAndFolder();
        $residence = $this->residenceCheck($ci, $folder);
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$otherFolder, $residence]))->assertNotFound();
        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-delete', $otherFolder), ['residence_check_ids' => [$residence->id]])->assertNotFound();

        auth()->guard('web')->logout();
        $this->delete(route('client-folders.residence-checks.destroy', [$folder, $residence]))->assertRedirect(route('login'));

        $this->assertModelExists($residence);
    }

    public function test_pending_state_script_contract(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        // Add shows Saving…, Edit (a rendered check_id) shows Updating…, on both check forms.
        $this->assertSame(2, substr_count($js, "form.querySelector('input[name=\"check_id\"]')?.value ? 'Updating…' : 'Saving…'"));

        // Batch Delete Selected: spinner + label + aria-busy before the one native submit.
        $start = strpos($js, 'if (!window.confirm(`Delete ${count} selected reports? This action cannot be undone.`)) return;');
        $this->assertNotFalse($start);
        $batch = substr($js, $start, 600);
        $this->assertStringContainsString("setButtonPending(deleteSelectedButton, 'Deleting...');", $batch);
        $this->assertStringContainsString('deleteSelectedButton.disabled = true;', $batch);
        $this->assertStringContainsString("deleteSelectedButton.setAttribute('aria-busy', 'true');", $batch);
        $this->assertSame(1, substr_count($batch, 'deleteForm.submit();'));
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function ciAndFolder(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        return [$ci, $folder];
    }

    private function residenceCheck(User $ci, ClientFolder $folder): ResidenceCheck
    {
        $check = new ResidenceCheck;
        $check->forceFill([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'ci_date' => now()->toDateString(),
            'location' => 'Saved location', 'ci_user_id' => $ci->id,
        ])->save();

        return $check;
    }

    private function businessCheck(User $ci, ClientFolder $folder, IncomeSource $source): BusinessCheck
    {
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'business_photos' => [UploadedFile::fake()->image('Business.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        return $folder->businessChecks()->where('income_source_id', $source->id)->firstOrFail();
    }

    private function businessSource(ClientFolder $folder, string $businessName): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => $businessName,
            'business_name' => $businessName,
        ]);
        $source->businessReport()->create([
            'business_name' => $businessName,
            'main_business_address' => 'Poblacion, San Miguel, Bulacan',
            'report_category' => 'retail_grocery_water_refilling',
        ]);

        return $source;
    }
}
