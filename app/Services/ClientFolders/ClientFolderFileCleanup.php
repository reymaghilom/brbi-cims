<?php

namespace App\Services\ClientFolders;

use App\Models\BusinessCheckPhoto;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\MediaReference;
use App\Models\ResidenceCheckPhoto;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stored-file side of an Administrator's permanent delete of a folder that already holds saved
 * records. The database graph itself is removed by the folder's cascading foreign keys.
 *
 * collect() runs INSIDE the delete transaction and only reads: it snapshots every folder-owned
 * stored file (Supporting Proof / media, Residence and Business Check photos and map screenshots,
 * generated report files). retire() runs only AFTER that transaction has committed, so a purge
 * that fails and rolls back never loses a file. Each retirement reuses the storage call the
 * module's own delete already uses:
 *  - media_references: local / CI Team files through PrivateMediaStorage, Cloudinary proofs
 *    through CloudinaryCiActivityProofStorage (the only writer of Cloudinary media references);
 *  - check photos and map screenshots: PrivateMediaStorage + ClientMediaUploader::retireCloudAsset
 *    with each row's own delivery type (as DeleteResidenceCheck / BusinessCheckMediaCleanup);
 *  - generated reports: the same disk resolution the download uses.
 *
 * collect() also serves an Administrator's delete of one Co-Maker: given that exact Co-Maker, it
 * snapshots only the files owned by that client_folder_id + co_maker_id.
 *
 * A file or Cloudinary asset that any record OUTSIDE that scope (another folder, or — for a
 * Co-Maker — the Applicant or another Co-Maker) still references is never
 * retired. Each eligible reference is staged durably in the business deletion transaction. A
 * retirement failure after commit is reported and remains pending; it cannot roll back the DB
 * deletion that already committed.
 */
class ClientFolderFileCleanup
{
    private const CLAIM_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ClientMediaUploader $mediaUploader,
        private readonly CloudinaryCiActivityProofStorage $proofCloud,
        private readonly CiTeamDocumentStorage $documents,
    ) {}

    /**
     * @return array{local: list<array{path: string, provider: string}>, cloud: list<array{public_id: string, resource_type: ?string, delivery_type: ?string}>, proof_cloud: list<array{public_id: string, resource_type: string}>, reports: list<string>}
     */
    public function collect(ClientFolder $folder, ?CoMaker $coMaker = null): array
    {
        $files = ['local' => [], 'cloud' => [], 'proof_cloud' => [], 'reports' => []];

        $this->inScope(MediaReference::withTrashed(), $folder, $coMaker)->get()->each(function (MediaReference $media) use (&$files, $folder, $coMaker): void {
            if ($media->storage_provider === MediaReference::STORAGE_PROVIDER_CLOUDINARY) {
                if (filled($media->cloudinary_public_id) && ! $this->mediaSharedOutside($folder, $coMaker, 'cloudinary_public_id', $media->cloudinary_public_id)) {
                    $files['proof_cloud'][] = ['public_id' => (string) $media->cloudinary_public_id, 'resource_type' => (string) $media->cloudinary_resource_type];
                }

                return;
            }

            foreach (array_filter([$media->temporary_local_path, $media->thumbnail_path]) as $path) {
                if (! $this->mediaSharedOutside($folder, $coMaker, 'temporary_local_path', $path) && ! $this->mediaSharedOutside($folder, $coMaker, 'thumbnail_path', $path)) {
                    $files['local'][] = ['path' => (string) $path, 'provider' => (string) $media->storage_provider];
                }
            }
        });

        foreach ([[ResidenceCheckPhoto::class, 'residence_checks', 'residence_check_id'], [BusinessCheckPhoto::class, 'business_checks', 'business_check_id']] as [$photoModel, $checkTable, $foreignKey]) {
            $checkIds = $this->inScope(DB::table($checkTable), $folder, $coMaker)->pluck('id');

            $photoModel::query()->whereIn($foreignKey, $checkIds)->get()->each(function ($photo) use (&$files, $photoModel, $foreignKey, $checkIds): void {
                $outside = fn (string $column, ?string $value): bool => filled($value)
                    && $photoModel::query()->whereNotIn($foreignKey, $checkIds)->where($column, $value)->exists();

                foreach (array_filter([$photo->path, $photo->thumbnail_path]) as $path) {
                    if (! $outside('path', $path) && ! $outside('thumbnail_path', $path)) {
                        $files['local'][] = ['path' => (string) $path, 'provider' => MediaReference::STORAGE_PROVIDER_LOCAL];
                    }
                }
                if ($photo->isCloud() && ! $outside('cloud_public_id', $photo->cloud_public_id)) {
                    $files['cloud'][] = ['public_id' => (string) $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
                }
            });

            DB::table($checkTable)->whereIn('id', $checkIds)->get()->each(function (object $check) use (&$files, $checkTable, $checkIds): void {
                $outside = fn (string $column, ?string $value): bool => filled($value)
                    && DB::table($checkTable)->whereNotIn('id', $checkIds)->where($column, $value)->exists();

                foreach (array_filter([$check->map_screenshot_path ?? null, $check->map_screenshot_thumbnail_path ?? null]) as $path) {
                    if (! $outside('map_screenshot_path', $path) && ! $outside('map_screenshot_thumbnail_path', $path)) {
                        $files['local'][] = ['path' => (string) $path, 'provider' => MediaReference::STORAGE_PROVIDER_LOCAL];
                    }
                }
                if (filled($check->map_screenshot_cloud_public_id ?? null) && ! $outside('map_screenshot_cloud_public_id', $check->map_screenshot_cloud_public_id)) {
                    $files['cloud'][] = [
                        'public_id' => (string) $check->map_screenshot_cloud_public_id,
                        'resource_type' => $check->map_screenshot_cloud_resource_type ?? null,
                        'delivery_type' => $check->map_screenshot_cloud_delivery_type ?? null,
                    ];
                }
            });
        }

        $this->inScope(GeneratedReport::query(), $folder, $coMaker)->whereNotNull('private_file_reference')->get()
            ->each(function (GeneratedReport $report) use (&$files, $folder, $coMaker): void {
                $sharedOutside = $this->outsideScope(GeneratedReport::query(), $folder, $coMaker)
                    ->where('private_file_reference', $report->private_file_reference)->exists();
                if (! $sharedOutside) {
                    $files['reports'][] = (string) $report->private_file_reference;
                }
            });

        return [
            'local' => array_values(array_unique($files['local'], SORT_REGULAR)),
            'cloud' => array_values(array_unique($files['cloud'], SORT_REGULAR)),
            'proof_cloud' => array_values(array_unique($files['proof_cloud'], SORT_REGULAR)),
            'reports' => array_values(array_unique($files['reports'])),
        ];
    }

    /**
     * Persist cleanup intent on the caller's current transaction, before its business delete commits.
     *
     * @return list<int>
     */
    public function stage(array $files): array
    {
        $tasks = [];
        foreach ($files['local'] ?? [] as $file) {
            $tasks[] = ['kind' => 'local', 'path' => $file['path'], 'storage_provider' => $file['provider']];
        }
        foreach ($files['proof_cloud'] ?? [] as $asset) {
            $tasks[] = ['kind' => 'proof_cloud', 'public_id' => $asset['public_id'], 'resource_type' => $asset['resource_type']];
        }
        foreach ($files['cloud'] ?? [] as $asset) {
            $tasks[] = ['kind' => 'cloud', 'public_id' => $asset['public_id'], 'resource_type' => $asset['resource_type'], 'delivery_type' => $asset['delivery_type']];
        }
        foreach ($files['reports'] ?? [] as $path) {
            $tasks[] = ['kind' => 'report', 'path' => $path];
        }

        $now = now();

        return array_map(fn (array $task): int => (int) DB::table('pending_file_cleanups')->insertGetId($task + [
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]), $tasks);
    }

    /** Call only after the delete transaction has committed. */
    public function retire(array $taskIds): int
    {
        if ($taskIds === []) {
            return 0;
        }

        try {
            return $this->retryTasks(count($taskIds), $taskIds);
        } catch (Throwable $exception) {
            // The owning database delete has already committed. Reporting a cleanup/outbox error
            // must never turn that successful delete into a false user-facing failure.
            report($exception);

            return 0;
        }
    }

    /** Safe for repeated command/worker runs; each invocation attempts a task at most once. */
    public function retryPending(int $limit = 100): int
    {
        return $this->retryTasks(max(0, $limit));
    }

    private function mediaSharedOutside(ClientFolder $folder, ?CoMaker $coMaker, string $column, string $value): bool
    {
        return $this->outsideScope(MediaReference::withTrashed(), $folder, $coMaker)->where($column, $value)->exists();
    }

    /** Rows owned by this folder, or by exactly this Co-Maker in it when one is given. */
    private function inScope(mixed $query, ClientFolder $folder, ?CoMaker $coMaker): mixed
    {
        return $query->where('client_folder_id', $folder->id)
            ->when($coMaker !== null, fn ($scoped) => $scoped->where('co_maker_id', $coMaker->id));
    }

    /** Every row NOT in that scope: other folders, and (for a Co-Maker) the Applicant and other Co-Makers. */
    private function outsideScope(mixed $query, ClientFolder $folder, ?CoMaker $coMaker): mixed
    {
        return $query->where(fn ($outside) => $outside->where('client_folder_id', '!=', $folder->id)
            ->when($coMaker !== null, fn ($other) => $other->orWhereNull('co_maker_id')->orWhere('co_maker_id', '!=', $coMaker->id)));
    }

    /** @param list<int>|null $onlyIds */
    private function retryTasks(int $limit, ?array $onlyIds = null): int
    {
        $attemptedIds = [];

        while (count($attemptedIds) < $limit) {
            $task = $this->claim($attemptedIds, $onlyIds);
            if ($task === null) {
                break;
            }

            $attemptedIds[] = (int) $task->id;

            try {
                $this->retireTask($task);
                DB::table('pending_file_cleanups')->where('id', $task->id)->where('claim_token', $task->claim_token)->delete();
            } catch (Throwable $exception) {
                report($exception);
                DB::table('pending_file_cleanups')->where('id', $task->id)->where('claim_token', $task->claim_token)->update([
                    'claim_token' => null,
                    'claimed_at' => null,
                    'last_error' => 'Cleanup failed ('.class_basename($exception).'). See application logs.',
                    'updated_at' => now(),
                ]);
            }
        }

        return count($attemptedIds);
    }

    /**
     * @param  list<int>  $attemptedIds
     * @param  list<int>|null  $onlyIds
     */
    private function claim(array $attemptedIds, ?array $onlyIds): ?object
    {
        return DB::transaction(function () use ($attemptedIds, $onlyIds): ?object {
            $task = DB::table('pending_file_cleanups')
                ->where(fn ($claimable) => $claimable->whereNull('claimed_at')->orWhere('claimed_at', '<=', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)))
                ->when($attemptedIds !== [], fn ($pending) => $pending->whereNotIn('id', $attemptedIds))
                ->when($onlyIds !== null, fn ($pending) => $pending->whereIn('id', $onlyIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($task === null) {
                return null;
            }

            $claimToken = (string) Str::uuid();
            DB::table('pending_file_cleanups')->where('id', $task->id)->update([
                'attempts' => DB::raw('attempts + 1'),
                'claim_token' => $claimToken,
                'claimed_at' => now(),
                'last_attempted_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
            $task->claim_token = $claimToken;

            return $task;
        });
    }

    private function retireTask(object $task): void
    {
        match ($task->kind) {
            'local' => $this->storage->deleteStoredFiles([$task->path], $task->storage_provider),
            'proof_cloud' => $this->proofCloud->delete($task->public_id, $task->resource_type),
            'cloud' => $this->mediaUploader->retireCloudAsset($task->public_id, $task->resource_type, $task->delivery_type),
            'report' => $this->deleteReport($task->path),
            default => throw new \LogicException('Unknown pending file cleanup kind.'),
        };
    }

    private function deleteReport(string $path): void
    {
        $disk = $this->documents->isLegacyReportPath($path) ? Storage::disk(config('cims.report_disk')) : $this->documents->disk();
        $disk->delete($path);
    }
}
