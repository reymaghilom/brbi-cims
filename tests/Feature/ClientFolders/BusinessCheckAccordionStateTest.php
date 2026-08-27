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
 * Business Check accordion default state: only Basic Information starts open (Add and Edit alike,
 * regardless of existing saved content) — Business Photos/Competitors/Map Screenshot default to
 * collapsed and only auto-open when redisplayed after their own validation error.
 */
class BusinessCheckAccordionStateTest extends TestCase
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
        [$ci, $folder] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="business-basic-info-title">/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="business-photos-title"/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="business-map-screenshot-title"/', $content);
        $this->assertDoesNotMatchRegularExpression('/<details open[^>]*data-photo-upload-field/', $content);
    }

    public function test_edit_form_stays_collapsed_by_default_even_with_existing_photos_and_screenshot(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => 'Storefront', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
            'competitor_photos' => [UploadedFile::fake()->image('rival.jpg', 900, 700)->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('map.png', 800, 600)->size(400),
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->getContent();

        // Existing saved photos/screenshot are present (would previously have auto-opened these
        // sections), but the new default keeps everything but Basic Information collapsed.
        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="business-basic-info-title">/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="business-photos-title"/', $content);
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="business-map-screenshot-title"/', $content);
        $this->assertDoesNotMatchRegularExpression('/<details open[^>]*data-photo-upload-field/', $content);
    }

    public function test_a_photo_groups_validation_error_opens_the_business_photos_section(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.create', $folder))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'Bad file', 'photos' => [UploadedFile::fake()->create('not-a-photo.jpg', 10, 'text/plain')]],
            ],
        ])->getContent();

        $this->assertMatchesRegularExpression('/<details\s+open\s+class="group" aria-labelledby="business-photos-title"/', $content);
    }

    public function test_the_required_photo_validation_error_opens_the_business_photos_section(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.create', $folder))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->getContent();

        $this->assertStringContainsString('At least one business photo is required.', $content);
        $this->assertMatchesRegularExpression('/<details\s+open\s+class="group" aria-labelledby="business-photos-title"/', $content);
        // Same temporary toast UX as Residence Check's own missing-photo error: a single marker for
        // app.js to turn into one auto-dismissing toast (see [data-business-check-photo-error] in
        // business-checks/form.blade.php) — this is the ONLY place the message should appear now;
        // the persistent "Please correct the highlighted fields" banner is suppressed for this
        // specific validation case (see the next two assertions).
        $this->assertSame(
            1,
            substr_count($content, 'data-business-check-photo-error="At least one business photo is required."'),
            'Exactly one toast marker must be rendered — never zero, never a duplicate.'
        );
        $this->assertStringNotContainsString('Please correct the highlighted fields. No changes were saved.', $content);
        // The message legitimately appears twice — the toast marker's own attribute value, and the
        // kept inline field-level message next to the Business Photos upload area — never a third
        // time as a bullet inside a global summary box (which would make it 3, or more with repeats).
        $this->assertSame(2, substr_count($content, 'At least one business photo is required.'));
    }

    public function test_editing_a_check_and_removing_every_photo_shows_the_same_toast_marker(): void
    {
        [$ci, $folder, , $check] = $this->createCheckWithOnePhoto();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.edit', [$folder, $check]))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'check_id' => $check->id, 'income_source_id' => $check->income_source_id,
            'ci_date' => $check->ci_date->toDateString(), 'location' => $check->location,
            'photo_groups' => [['id' => $check->photoGroups()->firstOrFail()->id, 'removed_photo_ids' => [$check->photos()->firstOrFail()->id]]],
        ])->getContent();

        $this->assertStringContainsString('At least one business photo is required.', $content);
        $this->assertSame(1, substr_count($content, 'data-business-check-photo-error='));
        $this->assertStringNotContainsString('Please correct the highlighted fields. No changes were saved.', $content);
    }

    /** A validation error unrelated to the required-photo rule has no toast of its own, so the persistent summary banner must keep showing for it exactly as before. */
    public function test_a_non_photo_validation_error_still_shows_the_persistent_summary_banner(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.create', $folder))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            // A valid photo is attached so the required-photo rule doesn't also fire here — this
            // test is isolated to confirming a non-photo error keeps the persistent banner.
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
            'map_screenshot' => UploadedFile::fake()->create('not-a-screenshot.jpg', 10, 'text/plain'),
        ])->getContent();

        $this->assertStringContainsString('Please correct the highlighted fields. No changes were saved.', $content);
        $this->assertStringContainsString('The file extension does not match its verified media type.', $content);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource, 3: \App\Models\BusinessCheck} */
    private function createCheckWithOnePhoto(): array
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();

        return [$ci, $folder, $source, $folder->businessChecks()->firstOrFail()];
    }

    public function test_a_competitor_photos_validation_error_opens_the_competitors_section(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.create', $folder))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'competitor_photos' => [UploadedFile::fake()->create('not-a-photo.jpg', 10, 'text/plain')],
        ])->getContent();

        $this->assertMatchesRegularExpression('/<details\s+open\s+[^>]*data-photo-upload-field/', $content);
    }

    public function test_a_map_screenshot_validation_error_opens_the_map_screenshot_section(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.create', $folder))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'map_screenshot' => UploadedFile::fake()->create('not-a-screenshot.jpg', 10, 'text/plain'),
        ])->getContent();

        $this->assertMatchesRegularExpression('/<details\s+open\s+class="group" aria-labelledby="business-map-screenshot-title"/', $content);
    }

    public function test_a_location_validation_error_leaves_basic_information_open_as_always(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->from(route('client-folders.business-checks.create', $folder))->followingRedirects()->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => '',
            // A photo is attached so the required-photo rule doesn't also fire here — this test is
            // isolated to confirming a Basic Information-only error never forces Business Photos open.
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ])->getContent();

        $this->assertMatchesRegularExpression('/<details open class="group" aria-labelledby="business-basic-info-title">/', $content);
        // A Basic Information error must not force the other, unrelated sections open.
        $this->assertMatchesRegularExpression('/<details\s+class="group" aria-labelledby="business-photos-title"/', $content);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource} */
    private function setUpBusiness(): array
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        return [$ci, $folder, $source];
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
