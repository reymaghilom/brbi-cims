<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResidenceCheckLocalRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_failed_update_keeps_an_existing_local_photo_and_its_database_reference(): void
    {
        [$actor, $folder, $check, $path] = $this->savedCheck();
        $photo = $check->photos()->firstOrFail();
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class)
            ->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Failure after media removal'));

        try {
            app(SaveResidenceCheck::class)->execute($actor, $folder, [
                'check_id' => $check->id,
                'expected_revision' => $check->revision,
                'ci_date' => $check->ci_date->toDateString(),
                'location' => $check->location,
                'removed_photo_ids' => [$photo->id],
                'photos' => [UploadedFile::fake()->image('Replacement.jpg')],
            ]);
            $this->fail('The simulated failure should roll back the update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failure after media removal', $exception->getMessage());
        }

        $this->assertSame(1, $check->fresh()->revision);
        $this->assertSame($path, $photo->fresh()->path);
        Storage::disk('local')->assertExists($path);
        $this->assertSame(0, AuditLog::query()->where('action', 'residence_check.updated')->count());
        $this->assertDatabaseCount('pending_file_cleanups', 0);
    }

    public function test_failed_delete_keeps_the_check_and_its_existing_local_photo(): void
    {
        [$actor, $folder, $check, $path] = $this->savedCheck();
        $this->mock(ResidenceBusinessCheckCompletionEvaluator::class)
            ->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('Failure after check deletion'));

        try {
            app(DeleteResidenceCheck::class)->execute($actor, $folder, $check);
            $this->fail('The simulated failure should roll back the deletion.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failure after check deletion', $exception->getMessage());
        }

        $this->assertModelExists($check);
        $this->assertSame(1, $check->photos()->count());
        Storage::disk('local')->assertExists($path);
        $this->assertSame(0, AuditLog::query()->where('action', 'residence_check.deleted')->count());
        $this->assertDatabaseCount('pending_file_cleanups', 0);
    }

    private function savedCheck(): array
    {
        $actor = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $actor->id]);
        $check = $folder->residenceChecks()->create([
            'ci_user_id' => $actor->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Saved residence location',
            'revision' => 1,
        ]);
        $path = 'client-media/residence-original.jpg';
        Storage::disk('local')->put($path, 'original photo bytes');
        $check->photos()->create([
            'file_name' => 'residence-original.jpg',
            'path' => $path,
            'uploaded_by' => $actor->id,
        ]);

        return [$actor, $folder, $check, $path];
    }
}
