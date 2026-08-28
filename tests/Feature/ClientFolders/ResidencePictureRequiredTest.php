<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\ResidenceCheck;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Residence Check requires between one and ten pictures in its final post-save state. */
class ResidencePictureRequiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_create_persists_every_selected_picture_at_one_three_and_ten(): void
    {
        $ci = User::factory()->create();

        foreach ([1, 3, 10] as $count) {
            $folder = $this->residenceCheckFolder($ci);
            $prefix = "Create{$count}";

            $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
                'remarks' => 'Residence verified.',
                'photos' => $this->photos($count, $prefix),
            ])->assertSessionHasNoErrors();

            $check = $folder->residenceChecks()->firstOrFail();
            $this->assertSame($count, $check->photos()->count());
            $this->assertSame(
                range(1, $count),
                $check->photos()->orderBy('sort_order')->pluck('sort_order')->all(),
            );
        }
    }

    public function test_create_with_eleven_pictures_is_rejected_without_persisting_a_check(): void
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => $this->photos(11, 'TooMany'),
        ])->assertSessionHasErrors(['photos' => 'A maximum of 10 residence pictures is allowed.']);

        $this->assertDatabaseCount('residence_checks', 0);
        $this->assertDatabaseCount('residence_check_photos', 0);
    }

    public function test_update_from_seven_with_three_additions_persists_all_ten(): void
    {
        [$ci, $folder, $check] = $this->existingCheck(7);
        $existingIds = $check->photos()->pluck('id');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'photos' => $this->photos(3, 'Added'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $check->photos()->count());
        $this->assertCount(3, $check->photos()->whereNotIn('id', $existingIds)->get());
    }

    public function test_update_from_eight_with_three_additions_is_rejected_and_preserves_existing_photos(): void
    {
        [$ci, $folder, $check] = $this->existingCheck(8);
        $existingIds = $check->photos()->orderBy('id')->pluck('id')->all();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'photos' => $this->photos(3, 'TooMany'),
        ])->assertSessionHasErrors(['photos' => 'A maximum of 10 residence pictures is allowed.']);

        $this->assertSame($existingIds, $check->photos()->orderBy('id')->pluck('id')->all());
    }

    public function test_update_counts_removals_before_allowing_three_additions_to_eight_existing(): void
    {
        [$ci, $folder, $check] = $this->existingCheck(8);
        $removedId = $check->photos()->oldest('id')->value('id');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'removed_photo_ids' => [$removedId],
            'photos' => $this->photos(3, 'Replacement'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $check->photos()->count());
        $this->assertDatabaseMissing('residence_check_photos', ['id' => $removedId]);
    }

    public function test_update_can_remove_two_and_add_two_at_the_ten_photo_limit(): void
    {
        [$ci, $folder, $check] = $this->existingCheck(10);
        $removedIds = $check->photos()->oldest('id')->limit(2)->pluck('id')->all();
        $keptIds = $check->photos()->whereNotIn('id', $removedIds)->pluck('id');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'removed_photo_ids' => $removedIds,
            'photos' => $this->photos(2, 'Replacement'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $check->photos()->count());
        $this->assertCount(2, $check->photos()->whereNotIn('id', $keptIds)->get());
    }

    public function test_update_can_remove_three_and_add_one_for_a_final_total_of_eight(): void
    {
        [$ci, $folder, $check] = $this->existingCheck(10);
        $removedIds = $check->photos()->oldest('id')->limit(3)->pluck('id')->all();

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'removed_photo_ids' => $removedIds,
            'photos' => $this->photos(1, 'Replacement'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(8, $check->photos()->count());
    }

    public function test_update_cannot_remove_the_only_picture_without_a_replacement(): void
    {
        [$ci, $folder, $check] = $this->existingCheck(1);
        $photoId = $check->photos()->value('id');

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'check_id' => $check->id,
            'removed_photo_ids' => [$photoId],
        ])->assertSessionHasErrors(['photos' => 'At least one residence picture is required.']);

        $this->assertDatabaseHas('residence_check_photos', ['id' => $photoId]);
    }

    /** @return array{User, ClientFolder, ResidenceCheck} */
    private function existingCheck(int $photoCount): array
    {
        $ci = User::factory()->create();
        $folder = $this->residenceCheckFolder($ci);
        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'photos' => $this->photos($photoCount, 'Existing'),
        ])->assertSessionHasNoErrors();

        return [$ci, $folder, $folder->residenceChecks()->firstOrFail()];
    }

    private function residenceCheckFolder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    /** @return array<int, UploadedFile> */
    private function photos(int $count, string $prefix): array
    {
        return collect(range(1, $count))
            ->map(fn (int $index) => UploadedFile::fake()->image("{$prefix}-{$index}.jpg", 900, 700)->size(500))
            ->all();
    }
}
