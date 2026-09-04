<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Single-file, replace-in-place map screenshot for a Residence/Business Documentation set — stored as an ordinary local MediaReference (same as any other documentation picture) so the existing content/download/preview routes work unchanged; only the FK on the documentation itself singles it out. */
class SaveDocumentationMapScreenshot
{
    public function __construct(private readonly PrivateMediaStorage $storage) {}

    /** @param  array{map_screenshot: ?UploadedFile, remove_map_screenshot: bool}  $data */
    public function execute(User $actor, ResidenceBusinessDocumentation $documentation, array $data): ResidenceBusinessDocumentation
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($actor, $documentation, $data, &$storedPaths): ResidenceBusinessDocumentation {
                $previous = $documentation->mapScreenshot;
                $operation = null;

                if ($data['remove_map_screenshot'] && $previous) {
                    $documentation->map_screenshot_media_id = null;
                    $documentation->save();
                    $previous->delete();
                    $operation = 'removed';
                } elseif ($data['map_screenshot'] !== null) {
                    $folder = $documentation->clientFolder;
                    $stored = $this->storage->storeDocumentation($documentation, $data['map_screenshot'], 'map');
                    $storedPaths[] = $stored['temporary_local_path'];
                    $storedPaths[] = $stored['thumbnail_path'];

                    $media = MediaReference::create(
                        collect($stored)->except('suggested_label')->all() + [
                            'client_folder_id' => $folder->id,
                            'co_maker_id' => $documentation->co_maker_id,
                            'income_source_id' => null,
                            'residence_business_documentation_id' => $documentation->id,
                            'category' => $documentation->category,
                            'label' => 'Map Screenshot',
                            'uploaded_by' => $actor->id,
                            'temporary_expires_at' => null,
                        ],
                    );

                    $documentation->map_screenshot_media_id = $media->id;
                    $documentation->save();

                    if ($previous) {
                        $previous->delete();
                    }
                    $operation = $previous ? 'replaced' : 'uploaded';
                }

                if ($operation !== null) {
                    $category = ucfirst($documentation->category);
                    AuditLog::create([
                        'user_id' => $actor->id,
                        'client_folder_id' => $documentation->client_folder_id,
                        'action' => "residence_business_documentation.map_screenshot_{$operation}",
                        'module' => 'media',
                        'description' => ucfirst($operation)." {$category} Google Map Screenshot.",
                        'metadata' => [
                            'residence_business_documentation_id' => $documentation->id,
                            'co_maker_id' => $documentation->co_maker_id,
                            'business_name' => $documentation->business_name,
                            'category' => $documentation->category,
                        ],
                        'ip_address' => request()?->ip(),
                        'user_agent' => request()?->userAgent(),
                    ]);
                }

                return $documentation->refresh();
            });
        } catch (\Throwable $exception) {
            try {
                $this->storage->deleteStoredFiles($storedPaths, MediaReference::STORAGE_PROVIDER_CI_TEAM);
            } catch (\Throwable) {
                // Rollback cleanup is best-effort and must not replace the original storage error.
            }
            throw $exception;
        }
    }
}
