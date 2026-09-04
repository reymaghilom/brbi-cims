<?php

namespace App\Actions\Media;

use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class AddCiActivityProofPhotos
{
    public function __construct(private readonly UploadCiActivityProof $upload) {}

    /**
     * @param  array<int, UploadedFile>  $photos
     * @return Collection<int, MediaReference>
     */
    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, array $photos): Collection
    {
        return collect($photos)
            ->filter(fn (mixed $photo): bool => $photo instanceof UploadedFile)
            ->map(fn (UploadedFile $photo): MediaReference => $this->upload->execute($actor, $folder, $activity, $photo))
            ->values();
    }
}
