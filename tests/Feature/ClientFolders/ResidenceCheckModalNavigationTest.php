<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Root-cause regression coverage: after a Residence Check is saved, the Add/Edit form runs inside
 * an <iframe> inside the shared "Residence & Business Checks" <dialog> (data-check-report-dialog).
 * ResidenceCheckController::store() used to redirect straight to the full Residence & Business
 * Checks listing page (layouts.app, sidebar and all) — since that redirect is followed *inside the
 * iframe*, the full app layout ended up rendered nested inside the modal. The fix redirects back to
 * the check's own edit page instead, which stays on the sidebar-free layouts.check-encoding layout
 * used by the Add/Edit form itself; that page's session-status notify hook then tells the parent
 * window (via postMessage) that it's safe to close the dialog and refresh the listing behind it.
 */
class ResidenceCheckModalNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_creating_a_residence_check_succeeds(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload())
            ->assertRedirect();

        $this->assertDatabaseCount('residence_checks', 1);
    }

    public function test_successful_create_redirects_to_the_check_encoding_layout_not_the_listing_page(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload());
        $check = $folder->residenceChecks()->firstOrFail();

        $response->assertRedirect(route('client-folders.residence-checks.edit', [$folder, $check]));
    }

    public function test_successful_create_response_does_not_render_a_second_full_app_layout(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload());
        $check = $folder->residenceChecks()->firstOrFail();

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk();

        // id="primary-sidebar" only ever appears in layouts.app (the full application shell). The
        // Add/Edit form's own layout carries data-check-encoding-layout instead, with no sidebar.
        $response->assertDontSee('id="primary-sidebar"', false);
        $response->assertSee('data-check-encoding-layout', false);
    }

    public function test_successful_create_response_includes_the_auto_close_notify_hook(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload());
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))
            ->assertOk()->assertSee('data-check-saved-notify', false);
    }

    public function test_create_success_message_is_shown(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload())
            ->assertSessionHas('status', 'Residence Check saved successfully.');
    }

    public function test_parent_residence_business_list_reflects_the_new_check_after_create(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload([
            'remarks' => 'Newly added residence check',
        ]));

        $this->actingAs($ci)->get(route('client-folders.residence-business.edit', $folder))
            ->assertOk()->assertSee('1 Residence Report');
    }

    public function test_editing_with_a_real_change_succeeds_and_shows_the_updated_message(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload(['remarks' => 'Original remarks']));
        $check = $folder->residenceChecks()->firstOrFail();

        $response = $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified with barangay confirmation.',
        ]);

        $response->assertRedirect(route('client-folders.residence-checks.edit', [$folder, $check]));
        $response->assertSessionHas('status', 'Residence Check updated successfully.');
        $this->assertSame('Residence verified with barangay confirmation.', $check->fresh()->remarks);
    }

    public function test_edit_success_response_also_includes_the_auto_close_notify_hook(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload(['remarks' => 'Original remarks']));
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'A genuinely different remark.',
        ]);

        $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))
            ->assertOk()->assertSee('data-check-saved-notify', false);
    }

    public function test_no_change_update_does_not_include_the_auto_close_notify_hook(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload(['remarks' => 'Residence verified.']));
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.',
        ])->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.')->assertSessionHas('statusType', 'info');

        // No auto-close hook — the no-change response must leave the form open rather than
        // triggering the parent modal to close and refresh.
        $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))
            ->assertOk()->assertDontSee('data-check-saved-notify', false);
    }

    public function test_no_full_layout_regression_after_editing_an_existing_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), $this->newCheckPayload(['remarks' => 'Original remarks']));
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Updated remarks after edit.',
        ]);

        $response = $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk();
        $response->assertDontSee('id="primary-sidebar"', false);
        $response->assertSee('data-check-encoding-layout', false);
    }

    /** Minimal valid payload for creating a brand-new Residence Check — always includes the required Residence Picture. */
    private function newCheckPayload(array $overrides = []): array
    {
        return array_merge([
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ], $overrides);
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
