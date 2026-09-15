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
 * retired. A retirement that fails after commit is reported and skipped — a stray file is safer
 * than rolling back a delete that already happened, and no database reference to it remains.
 */
class ClientFolderFileCleanup
{
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

    /** Call only after the delete transaction has committed. */
    public function retire(array $files): void
    {
        foreach ($files['local'] ?? [] as $file) {
            $this->attempt(fn () => $this->storage->deleteStoredFiles([$file['path']], $file['provider']));
        }
        foreach ($files['proof_cloud'] ?? [] as $asset) {
            $this->attempt(fn () => $this->proofCloud->delete($asset['public_id'], $asset['resource_type']));
        }
        foreach ($files['cloud'] ?? [] as $asset) {
            $this->attempt(fn () => $this->mediaUploader->retireCloudAsset($asset['public_id'], $asset['resource_type'], $asset['delivery_type']));
        }
        foreach ($files['reports'] ?? [] as $path) {
            $this->attempt(function () use ($path): void {
                $disk = $this->documents->isLegacyReportPath($path) ? Storage::disk(config('cims.report_disk')) : $this->documents->disk();
                $disk->delete($path);
            });
        }
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

    private function attempt(callable $retire): void
    {
        try {
            $retire();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
