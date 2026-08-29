<?php

namespace App\Actions\Media;

use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ReplaceCiActivityProof
{
    public function __construct(
        private readonly UploadCiActivityProof $upload,
        private readonly RemoveCiActivityProof $remove,
    ) {}

    public function execute(
        User $actor,
        ClientFolder $folder,
        CiActivity $activity,
        MediaReference $existingMedia,
        UploadedFile $replacement,
    ): MediaReference {
        $newMedia = $this->upload->execute($actor, $folder, $activity, $replacement);

        try {
            $this->remove->execute($actor, $folder, $activity, $existingMedia);
        } catch (\Throwable $exception) {
            try {
                $this->remove->execute($actor, $folder, $activity, $newMedia);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return $newMedia;
    }
}
