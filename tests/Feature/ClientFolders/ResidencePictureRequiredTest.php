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
 * Residence Check must always have at least one Residence Picture — enforced server-side
 * (SaveResidenceCheckRequest), not just in the browser. A brand-new check has no existing photos
 * to fall back on, so creation always needs an upload; an existing check only needs one if the CI
 * is removing every photo it already had. Map Screenshot stays optional throughout.
 */
class ResidencePictureRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_new_residence_check_cannot_be_saved_without_a_residence_picture(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
        ])->assertSessionHasErrors('photos');

        $this->assertSame('At least one residence picture is required.', session('errors')->first('photos'));
        $this->assertDatabaseCount('residence_checks', 0);
    }

    public function test_new_residence_check_saves_with_at_least_one_residence_picture(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('residence_checks', 1);
    }

    public function test_existing_residence_picture_satisfies_the_update_requirement_without_a_new_upload(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified with barangay confirmation.',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Residence Check updated successfully.');

        $this->assertSame(1, $check->photos()->count());
    }

    public function test_removing_every_existing_residence_picture_blocks_the_update(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $photoId = $check->photos()->firstOrFail()->id;

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.', 'removed_photo_ids' => [$photoId],
        ])->assertSessionHasErrors('photos');

        $this->assertSame('At least one residence picture is required.', session('errors')->first('photos'));
        $this->assertSame(1, $check->photos()->count());
    }

    public function test_removing_the_last_photo_while_uploading_a_replacement_is_allowed(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ]);
        $check = $folder->residenceChecks()->firstOrFail();
        $photoId = $check->photos()->firstOrFail()->id;

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id, 'remarks' => 'Residence verified.',
            'removed_photo_ids' => [$photoId], 'photos' => [UploadedFile::fake()->image('Replacement.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $check->photos()->count());
    }

    public function test_map_screenshot_remains_optional_on_a_new_residence_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'remarks' => 'Residence verified.',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors('map_screenshot');

        $check = $folder->residenceChecks()->firstOrFail();
        $this->assertFalse($check->hasMapScreenshot());
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
