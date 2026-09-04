<?php

namespace App\Http\Controllers;

use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaReferenceController extends Controller
{
    public function __construct(private readonly CiTeamDocumentStorage $documents) {}

    public function activityContent(ClientFolder $clientFolder, CiActivity $ciActivity, MediaReference $mediaReference): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $mediaReference);
        abort_unless($ciActivity->client_folder_id === $clientFolder->id, 404);
        abort_unless($mediaReference->client_folder_id === $clientFolder->id, 404);
        abort_unless($mediaReference->co_maker_id === $ciActivity->co_maker_id, 404);
        abort_unless($ciActivity->mediaReferences()->whereKey($mediaReference->id)->exists(), 404);

        return $this->contentResponse($mediaReference);
    }

    private function contentResponse(MediaReference $mediaReference): StreamedResponse|RedirectResponse
    {
        if ($mediaReference->storage_provider === MediaReference::STORAGE_PROVIDER_CLOUDINARY) {
            abort_unless(
                filled($mediaReference->cloudinary_public_id)
                    && filled($mediaReference->cloudinary_resource_type)
                    && filled($mediaReference->cloudinary_secure_url),
                404,
            );

            return redirect()->away($mediaReference->cloudinary_secure_url);
        }

        abort_unless(in_array($mediaReference->storage_provider, [MediaReference::STORAGE_PROVIDER_LOCAL, MediaReference::STORAGE_PROVIDER_CI_TEAM], true), 404);
        $thumbnail = request()->boolean('thumbnail') && filled($mediaReference->thumbnail_path);
        $path = $thumbnail ? $mediaReference->thumbnail_path : $mediaReference->temporary_local_path;
        abort_unless(filled($path), 404);
        $disk = $this->documents->diskForMedia($mediaReference, $path);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $mediaReference->file_name, [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $mediaReference->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
