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
 * Residence Check accordion default state: only Basic Information starts open (Add and Edit
 * alike) — Residence Photos/Map Screenshot default to collapsed and only auto-open when
 * redisplayed after their own validation error. Same pattern as Business Check's own accordion
 * (see BusinessCheckAccordionStateTest).
 */
class ResidenceCheckAccordionStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_add_form_only_opens_basic_information_by_default(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)->get(route('client-folders.residence-checks.create', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="residence-basic-info-title">/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="residence-photos-title"/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="residence-map-title"/', $content);
    }

    public function test_edit_form_stays_collapsed_by_default_even_with_existing_photos_and_screenshot(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('Map.png', 800, 600)->size(400),
        ])->assertSessionHasNoErrors();
        $check = $folder->residenceChecks()->firstOrFail();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $check]))->assertOk()->getContent();

        // Existing saved photos/screenshot are present (would previously have auto-opened these
        // sections), but the new default keeps everything but Basic Information collapsed.
        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="residence-basic-info-title">/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="residence-photos-title"/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="residence-map-title"/', $content);
    }

    public function test_missing_required_photo_opens_the_residence_photos_section(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)->from(route('client-folders.residence-checks.create', $folder))->followingRedirects()->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'No photo attached.',
        ])->getContent();

        $this->assertStringContainsString('At least one residence picture is required.', $content);
        $this->assertMatchesRegularExpression('/<details\s+open\s+class="group" aria-labelledby="residence-photos-title"/', $content);
        // A photo-requirement error must not force the unrelated Map Screenshot section open too.
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="residence-map-title"/', $content);
    }

    public function test_a_map_screenshot_validation_error_opens_the_map_screenshot_section(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $content = $this->actingAs($ci)->from(route('client-folders.residence-checks.create', $folder))->followingRedirects()->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->create('not-a-screenshot.jpg', 10, 'text/plain'),
        ])->getContent();

        $this->assertMatchesRegularExpression('/<details\s+open\s+class="group" aria-labelledby="residence-map-title"/', $content);
        // Residence Photos itself is satisfied here (a valid photo was attached) and must stay
        // collapsed even though this same submission failed validation elsewhere.
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="residence-photos-title"/', $content);
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
