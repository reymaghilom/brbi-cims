<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Residence & Business Checks listing page (residence-business/edit.blade.php) used to render
 * its own page-specific, never-auto-dismissing `session('status')` banner in addition to
 * layouts.app's own <main>-level <x-ui.toast> (which the generic [data-toast] setTimeout in app.js
 * already auto-dismisses) — every save/update/delete redirect that lands on this page showed the
 * temporary toast, then that same message a second time as a banner that never went away on its
 * own. The banner has been removed; this only covers that this page renders exactly one visible
 * success confirmation (the toast's own <p>) and never the old persistent banner markup. A hidden
 * `data-check-saved-message` attribute (used only by the modal-close postMessage relay, never
 * displayed) also legitimately carries the same text and is deliberately excluded from the count.
 */
class ResidenceBusinessSuccessToastTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_BANNER_MARKUP = 'border-success/30 bg-success-soft p-4 text-sm font-semibold text-success';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_residence_check_delete_shows_only_the_temporary_toast(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->delete(route('client-folders.residence-checks.destroy', [$folder, $check]))
            ->assertRedirect(route('client-folders.residence-business.edit', $folder))
            ->assertSessionHas('status', 'Residence Check deleted successfully.');

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        $this->assertVisibleToastAppearsExactlyOnce('Residence Check deleted successfully.', $content);
        $this->assertStringNotContainsString(self::OLD_BANNER_MARKUP, $content);
    }

    public function test_business_check_delete_shows_only_the_temporary_toast(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ]);
        $check = $folder->businessChecks()->firstOrFail();

        $this->actingAs($ci)->delete(route('client-folders.business-checks.destroy', [$folder, $check]))
            ->assertRedirect(route('client-folders.residence-business.edit', $folder))
            ->assertSessionHas('status', 'Business Check deleted successfully.');

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        $this->assertVisibleToastAppearsExactlyOnce('Business Check deleted successfully.', $content);
        $this->assertStringNotContainsString(self::OLD_BANNER_MARKUP, $content);
    }

    public function test_a_residence_check_update_redirect_shows_only_the_temporary_toast_on_the_listing_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'A genuinely different remark.',
        ])->assertSessionHas('status', 'Residence Check updated successfully.');

        // The parent listing page's own reload after the modal closes is where the CI actually
        // sees the confirmation — same page, same session('status'), verified banner-free here too.
        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))->assertOk()->getContent();

        $this->assertVisibleToastAppearsExactlyOnce('Residence Check updated successfully.', $content);
        $this->assertStringNotContainsString(self::OLD_BANNER_MARKUP, $content);
    }

    /** Counts only the message rendered inside the toast component's own <p> — never the unrelated hidden data-check-saved-message attribute the modal-close relay also legitimately carries. */
    private function assertVisibleToastAppearsExactlyOnce(string $message, string $content): void
    {
        $needle = '<p class="min-w-0 flex-1 text-sm font-semibold">'.$message.'</p>';
        $this->assertSame(1, substr_count($content, $needle), "The toast must render \"$message\" exactly once.");
    }

    /**
     * The actual 404 root cause: the listing page's own delete-confirmation-dialog form action was
     * built via route('client-folders.business-checks.destroy', [$clientFolder, $check]) — no
     * person/co_maker_id query params — so deleting a Co-Maker's own Business Check submitted with
     * none of that context. ActivePersonResolver::resolveFromQuery() then resolved null,
     * assertOwnedBy() saw the check's real (non-null) co_maker_id not match, and aborted 404 before
     * DeleteBusinessCheck ever ran. Fixed by appending $personParams (already computed at the top of
     * residence-business/edit.blade.php) to that one route() call.
     */
    public function test_the_listing_page_renders_the_business_check_delete_action_with_the_co_makers_query_params(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Co-Maker Address', $coMaker->id);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ]);
        $check = $folder->businessChecks()->firstOrFail();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))->assertOk()->getContent();

        $expectedAction = route('client-folders.business-checks.destroy', [$folder, $check, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $this->assertStringContainsString('action="'.htmlspecialchars($expectedAction).'"', $content);
    }

    public function test_deleting_a_co_makers_business_check_preserves_scope_and_does_not_404(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Co Maker Person', 'address' => 'Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Co-Maker Address', $coMaker->id);
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Co-Maker Address',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ]);
        $check = $folder->businessChecks()->firstOrFail();

        $expectedRedirect = route('client-folders.residence-business.edit', [$folder, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]);
        $this->actingAs($ci)->delete(route('client-folders.business-checks.destroy', [$folder, $check, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]))
            ->assertRedirect($expectedRedirect)
            ->assertSessionHas('status', 'Business Check deleted successfully.');

        $this->assertDatabaseMissing('business_checks', ['id' => $check->id]);

        $this->actingAs($ci)->get($expectedRedirect)->assertOk();
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['co_maker_id' => $coMakerId, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
