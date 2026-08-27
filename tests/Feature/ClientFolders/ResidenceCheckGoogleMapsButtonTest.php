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
 * Verified Map Location and Route Verification were removed from Residence Check entirely — Map
 * Screenshot is the only Google Map evidence left, and it stays optional. In their place, a plain
 * "Open Google Maps" link (a Maps search URL built from the authoritative Location text, never
 * an API call) sits only next to the Map Screenshot section — never duplicated next to the
 * read-only Location field itself.
 */
class ResidenceCheckGoogleMapsButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_verified_map_location_and_route_ui_are_completely_removed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        // Verified Map Location: tabs, embedded map, Places search, current location, marker, clear.
        $this->assertStringNotContainsString('Verified Map Location', $content);
        $this->assertStringNotContainsString('data-map-tabs', $content);
        $this->assertStringNotContainsString('data-map-tab-trigger', $content);
        $this->assertStringNotContainsString('data-residence-map', $content);
        $this->assertStringNotContainsString('data-residence-map-search-input', $content);
        $this->assertStringNotContainsString('data-residence-map-current-location', $content);
        $this->assertStringNotContainsString('data-residence-map-canvas', $content);
        $this->assertStringNotContainsString('data-residence-map-clear', $content);
        $this->assertStringNotContainsString('Use Current Location', $content);
        $this->assertStringNotContainsString('Clear Map Location', $content);
        $this->assertStringNotContainsString('map_evidence_type', $content);

        // Route Verification (already removed in an earlier pass) must still be absent.
        $this->assertStringNotContainsString('Get Route', $content);
        $this->assertStringNotContainsString('Route Details', $content);
        $this->assertStringNotContainsString('Open Route in Google Maps', $content);
        $this->assertStringNotContainsString('TWO_WHEELER', $content);
        $this->assertStringNotContainsString('Route.computeRoutes', $content);

        // No Google Maps JavaScript API bootstrap loader is emitted for this page any more.
        $this->assertStringNotContainsString('importLibrary', $content);
        $this->assertStringNotContainsString('maps.googleapis.com', $content);
    }

    public function test_open_google_maps_button_uses_the_authoritative_address_and_opens_a_new_tab_without_any_google_api(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        $expectedUrl = htmlspecialchars('https://www.google.com/maps/search/?api=1&query='.urlencode('Applicant Address'));
        $this->assertStringContainsString('Open Google Maps', $content);
        $this->assertStringContainsString('href="'.$expectedUrl.'"', $content);
        $this->assertStringContainsString('target="_blank"', $content);
        $this->assertStringContainsString('rel="noopener noreferrer"', $content);

        // A plain Maps search URL — no API key, no Places/Routes/Static Maps API, no coordinates.
        $this->assertStringNotContainsString('key=', $content);

        // Only one actual link to the Maps search URL — near the Map Screenshot section, never
        // duplicated next to the read-only Location field, which carries no button of its own.
        $this->assertSame(1, substr_count($content, 'href="'.$expectedUrl.'"'));
        $this->assertStringNotContainsString('Open in Google Maps', $content);
    }

    public function test_open_google_maps_button_is_disabled_when_there_is_no_address_yet(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Open Google Maps', $content);
        $this->assertStringNotContainsString('https://www.google.com/maps/search', $content);
        $this->assertMatchesRegularExpression('/aria-disabled="true"[^>]*title="No address available yet"/', $content);
    }

    public function test_helper_text_matches_the_required_copy_exactly(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)
            ->get(route('client-folders.residence-checks.create', $folder))
            ->assertOk()->getContent();

        $this->assertStringContainsString('You can take a screenshot from Google Maps using your phone/PC and upload it here. Make sure the location pin and nearby landmarks are visible.', $content);
    }

    public function test_residence_check_saves_successfully_without_a_map_screenshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.', 'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertFalse($check->hasMapScreenshot());

        // Removing an (absent) screenshot on update must not block the save either.
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence re-verified.', 'remove_map_screenshot' => '1',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
