<?php

namespace App\Services\Maintenance;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PhotosVideosRemoval
{
    public const AUDIT_ACTIONS = [
        'residence_business_documentation.created',
        'residence_business_documentation.updated',
        'residence_business_documentation.map_screenshot_uploaded',
        'residence_business_documentation.map_screenshot_replaced',
        'residence_business_documentation.map_screenshot_removed',
        'residence_business_documentation.media_uploaded',
        'residence_business_documentation.media_removed',
        'residence_business_documentation.telegram_sent',
    ];

    public function __construct(
        private readonly CiTeamDocumentStorage $documents,
        private readonly CloudinaryMediaStorage $cloudinary,
    ) {}

    /** @return array{counts: array<string, int>, files: list<string>, directories: list<string>} */
    public function inventory(): array
    {
        if (! Schema::hasTable('residence_business_documentations')) {
            return ['counts' => $this->emptyCounts(), 'files' => [], 'directories' => []];
        }

        $media = $this->documentationMedia();
        $directories = $this->directoryCandidates($media);

        return [
            'counts' => [
                'residence_documentations' => DB::table('residence_business_documentations')->where('category', 'residence')->count(),
                'business_documentations' => DB::table('residence_business_documentations')->where('category', 'business')->count(),
                'documentation_media' => $media->count(),
                'documentation_maps' => DB::table('residence_business_documentations')->whereNotNull('map_screenshot_media_id')->count(),
                'documentation_telegram_deliveries' => Schema::hasTable('documentation_telegram_deliveries') ? DB::table('documentation_telegram_deliveries')->count() : 0,
                'documentation_audit_rows' => Schema::hasTable('audit_logs') ? DB::table('audit_logs')->whereIn('action', self::AUDIT_ACTIONS)->count() : 0,
            ],
            'files' => $media
                ->flatMap(fn (object $row): array => array_values(array_filter([
                    $row->temporary_local_path ? $row->storage_provider.':'.$row->temporary_local_path : null,
                    $row->thumbnail_path ? $row->storage_provider.':'.$row->thumbnail_path : null,
                    $row->cloudinary_public_id ? 'cloudinary:'.$row->cloudinary_public_id : null,
                ])))
                ->unique()->sort()->values()->all(),
            'directories' => $directories->sort()->values()->all(),
        ];
    }

    /** @return array{counts: array<string, int>, files: list<string>, directories: list<string>} */
    public function execute(): array
    {
        $inventory = $this->inventory();
        if (! Schema::hasTable('residence_business_documentations')) {
            return $inventory;
        }

        $media = $this->documentationMedia();
        $this->assertNoProtectedLinks($media->pluck('id'));

        foreach ($media as $row) {
            if ($row->storage_provider === MediaReference::STORAGE_PROVIDER_CLOUDINARY) {
                $this->cloudinary->destroy($row->cloudinary_public_id, $row->cloudinary_resource_type);

                continue;
            }

            $model = (new MediaReference)->forceFill((array) $row);
            foreach (array_filter([$row->temporary_local_path, $row->thumbnail_path]) as $path) {
                $path = $this->documents->relative((string) $path);
                $this->documents->diskForMedia($model, $path)->delete($path);
            }
        }

        $directories = $this->directoryCandidates($media);
        foreach ($directories as $directory) {
            foreach ($this->ciTeamDisks() as $disk) {
                if ($disk->directoryExists($directory)) {
                    $disk->deleteDirectory($directory);
                }
            }
        }

        DB::transaction(function () use ($media): void {
            $ids = $media->pluck('id');
            if (Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')->whereIn('action', self::AUDIT_ACTIONS)->delete();
            }
            if ($ids->isNotEmpty()) {
                DB::table('media_references')->whereIn('id', $ids)->delete();
            }
            if (Schema::hasTable('documentation_telegram_deliveries')) {
                DB::table('documentation_telegram_deliveries')->delete();
            }
            DB::table('residence_business_documentations')->delete();
        });

        return $inventory;
    }

    private function documentationMedia(): Collection
    {
        if (! Schema::hasTable('media_references') || ! Schema::hasColumn('media_references', 'residence_business_documentation_id')) {
            return collect();
        }

        return DB::table('media_references')
            ->whereNotNull('residence_business_documentation_id')
            ->get([
                'id', 'storage_provider', 'temporary_local_path', 'thumbnail_path',
                'cloudinary_public_id', 'cloudinary_resource_type',
            ]);
    }

    private function directoryCandidates(Collection $media): Collection
    {
        $directories = collect();
        foreach ($media as $row) {
            foreach (array_filter([$row->temporary_local_path, $row->thumbnail_path]) as $path) {
                $path = $this->documents->relative((string) $path);
                if (preg_match('#^(.+/(?:Residence Pictures|Business Pictures))(?:/|$)#i', $path, $matches)) {
                    $directories->push($this->documents->relative($matches[1]));
                }
            }
        }

        $documentations = DB::table('residence_business_documentations')->get(['client_folder_id', 'co_maker_id', 'category']);
        $folders = ClientFolder::withTrashed()->whereIn('id', $documentations->pluck('client_folder_id')->unique())->get()->keyBy('id');
        $coMakers = CoMaker::query()->whereIn('id', $documentations->pluck('co_maker_id')->filter()->unique())->get()->keyBy('id');
        foreach ($documentations as $documentation) {
            $folder = $folders->get($documentation->client_folder_id);
            if (! $folder) {
                continue;
            }
            $coMaker = $documentation->co_maker_id ? $coMakers->get($documentation->co_maker_id) : null;
            $leaf = $documentation->category === 'business' ? 'Business Pictures' : 'Residence Pictures';
            $directories->push($this->documents->relative($this->documents->personDirectory($folder, $coMaker).'/'.$leaf));
        }

        return $directories->unique()->values();
    }

    private function assertNoProtectedLinks(Collection $mediaIds): void
    {
        if ($mediaIds->isEmpty()) {
            return;
        }
        foreach (['activity_media', 'photo_report_media', 'telegram_message_media'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('media_reference_id', $mediaIds)->exists()) {
                throw new RuntimeException("Removal stopped: documentation media is linked from protected table {$table}.");
            }
        }
    }

    /** @return list<FilesystemAdapter> */
    private function ciTeamDisks(): array
    {
        return [
            $this->documents->disk(),
            Storage::build(['driver' => 'local', 'root' => $this->documents->root().DIRECTORY_SEPARATOR.'Clients', 'throw' => true]),
        ];
    }

    private function emptyCounts(): array
    {
        return [
            'residence_documentations' => 0,
            'business_documentations' => 0,
            'documentation_media' => 0,
            'documentation_maps' => 0,
            'documentation_telegram_deliveries' => 0,
            'documentation_audit_rows' => 0,
        ];
    }
}
