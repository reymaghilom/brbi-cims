<?php

namespace App\Actions\Media;

use App\Enums\MediaCategory;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use App\Services\Media\EvidenceStorageRecorder;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Settings\EvidenceStorageSetting;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Stores one CI Activity Supporting Proof attachment through whichever provider the administrator
 * has selected system-wide (EvidenceStorageSetting) — the same one decision Residence Check and
 * Business Check pictures use. Each MediaReference records the provider it was actually written
 * with, so reading, replacing and removing an existing attachment never consult the setting.
 */
class UploadCiActivityProof
{
    public function __construct(
        private readonly CloudinaryCiActivityProofStorage $storage,
        private readonly PrivateMediaStorage $localStorage,
        private readonly CiTeamDocumentStorage $documents,
        private readonly EvidenceStorageSetting $evidenceStorage,
        private readonly EvidenceStorageRecorder $recorder,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, UploadedFile $file): MediaReference
    {
        throw_unless(
            $activity->client_folder_id === $folder->id,
            \InvalidArgumentException::class,
            'The proof activity does not belong to the selected Client Folder.',
        );

        $stored = $this->evidenceStorage->usesCloud()
            ? $this->storage->store($folder, $activity, $file)
            : $this->localStorage->storeInDirectory($file, $this->documents->ciActivityProofDirectory($folder, $this->proofCoMaker($folder, $activity)));
        $this->recorder->record($stored['storage_provider'] ?? null);

        try {
            return DB::transaction(function () use ($actor, $folder, $activity, $stored): MediaReference {
                $media = MediaReference::create(Arr::except($stored, ['suggested_label']) + [
                    'client_folder_id' => $folder->id,
                    'co_maker_id' => $activity->co_maker_id,
                    'income_source_id' => null,
                    'category' => MediaCategory::Other->value,
                    'label' => $activity->name.' proof',
                    'remarks' => null,
                    'captured_at' => null,
                    'uploaded_by' => $actor->id,
                    'temporary_expires_at' => null,
                ]);
                $activity->mediaReferences()->attach($media->id, ['label' => $media->label]);
                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                    'action' => 'media.uploaded',
                    'module' => 'media',
                    'description' => 'A protected media evidence item was uploaded.',
                    'metadata' => [
                        'media_reference_id' => $media->id,
                        'co_maker_id' => $media->co_maker_id,
                        'media_type' => $media->media_type->value,
                        'category' => $media->category->value,
                        'byte_size' => $media->byte_size,
                    ],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);

                return $media;
            });
        } catch (\Throwable $exception) {
            try {
                // Clean up through the provider this upload actually used, never the current setting.
                if (($stored['storage_provider'] ?? null) === MediaReference::STORAGE_PROVIDER_CLOUDINARY) {
                    $this->storage->delete(
                        (string) ($stored['cloudinary_public_id'] ?? ''),
                        (string) ($stored['cloudinary_resource_type'] ?? ''),
                    );
                } else {
                    $this->localStorage->deleteStoredFiles([$stored['temporary_local_path'] ?? null, $stored['thumbnail_path'] ?? null]);
                }
            } catch (\Throwable) {
                // Preserve the database failure; cleanup can be retried operationally using the stored reference.
            }

            throw $exception;
        }
    }

    /** Resolves (and re-validates) the exact Applicant/Co-Maker this activity's proof belongs to, mirroring CloudinaryCiActivityProofStorage::folderFor(). */
    private function proofCoMaker(ClientFolder $folder, CiActivity $activity): ?CoMaker
    {
        if ($activity->co_maker_id === null) {
            return null;
        }

        $coMaker = $folder->coMakers()->find($activity->co_maker_id);
        throw_if($coMaker === null, \InvalidArgumentException::class, 'The proof activity Co-Maker does not belong to the selected Client Folder.');

        return $coMaker;
    }
}
