<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessCheckLocalRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_failed_update_preserves_old_photo_and_map_files_and_references(): void
    {
        [$actor, $folder, $source, $check, $photo, $photoPath, $mapPath] = $this->savedCheck();
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class)
            ->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('late failure'));

        try {
            app(SaveBusinessCheck::class)->execute($actor, $folder, [
                'check_id' => $check->id,
                'expected_revision' => $check->revision,
                'income_source_id' => $source->id,
                'ci_date' => $check->ci_date->toDateString(),
                'location' => $check->location,
                'removed_photo_ids' => [$photo->id],
                'business_photos' => [UploadedFile::fake()->image('replacement.jpg')],
                'remove_map_screenshot' => true,
            ]);
            $this->fail('The simulated failure should roll back the update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('late failure', $exception->getMessage());
        }

        $this->assertSame(1, $check->fresh()->revision);
        $this->assertSame($photoPath, $photo->fresh()->path);
        $this->assertSame($mapPath, $check->fresh()->map_screenshot_path);
        Storage::disk('local')->assertExists([$photoPath, $mapPath]);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertSame(1, $check->photos()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'business_check.updated')->count());
        $this->assertDatabaseCount('pending_file_cleanups', 0);
    }

    public function test_failed_check_delete_preserves_old_local_media(): void
    {
        [$actor, $folder, , $check, , $photoPath, $mapPath] = $this->savedCheck();
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class)
            ->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('late failure'));

        try {
            app(DeleteBusinessCheck::class)->execute($actor, $folder, $check);
            $this->fail('The simulated failure should roll back the delete.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('late failure', $exception->getMessage());
        }

        $this->assertModelExists($check);
        $this->assertSame(1, $check->photos()->count());
        Storage::disk('local')->assertExists([$photoPath, $mapPath]);
        $this->assertSame(0, AuditLog::query()->where('action', 'business_check.deleted')->count());
        $this->assertDatabaseCount('pending_file_cleanups', 0);
    }

    public function test_failed_linked_source_delete_preserves_check_and_local_media(): void
    {
        [$actor, $folder, $source, $check, , $photoPath, $mapPath] = $this->savedCheck();
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class)
            ->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('late failure'));

        try {
            app(DeleteIncomeSource::class)->execute($actor, $folder, $source);
            $this->fail('The simulated failure should roll back the source delete.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('late failure', $exception->getMessage());
        }

        $this->assertModelExists($source);
        $this->assertModelExists($check);
        $this->assertSame(1, $check->photos()->count());
        Storage::disk('local')->assertExists([$photoPath, $mapPath]);
        $this->assertSame(0, AuditLog::query()->where('action', 'income_source.deleted')->count());
        $this->assertDatabaseCount('pending_file_cleanups', 0);
    }

    public function test_outer_transaction_rollback_after_a_linked_source_delete_keeps_its_files(): void
    {
        [$actor, $folder, $source, $check, , $photoPath, $mapPath] = $this->savedCheck();

        try {
            DB::transaction(function () use ($actor, $folder, $source): void {
                app(DeleteIncomeSource::class)->execute($actor, $folder, $source);
                throw new \RuntimeException('outer rollback');
            });
            $this->fail('The outer transaction should roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outer rollback', $exception->getMessage());
        }

        $this->assertModelExists($source);
        $this->assertModelExists($check);
        Storage::disk('local')->assertExists([$photoPath, $mapPath]);
        $this->assertDatabaseCount('pending_file_cleanups', 0);
    }

    private function savedCheck(): array
    {
        $actor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Saved Store',
            'business_name' => 'Saved Store',
        ]);
        $photoPath = 'client-media/business-old.jpg';
        $mapPath = 'client-media/business-map-old.jpg';
        Storage::disk('local')->put($photoPath, 'old photo');
        Storage::disk('local')->put($mapPath, 'old map');
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id,
            'business_name' => 'Saved Store',
            'ci_user_id' => $actor->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Saved address',
            'revision' => 1,
            'map_screenshot_file_name' => 'business-map-old.jpg',
            'map_screenshot_path' => $mapPath,
        ]);
        $photo = $check->photos()->create([
            'category' => 'business',
            'file_name' => 'business-old.jpg',
            'path' => $photoPath,
            'uploaded_by' => $actor->id,
        ]);

        return [$actor, $folder, $source, $check, $photo, $photoPath, $mapPath];
    }
}
