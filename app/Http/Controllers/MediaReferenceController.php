<?php

namespace App\Http\Controllers;

use App\Actions\Media\RemoveMedia;
use App\Actions\Media\UpdateMediaMetadata;
use App\Actions\Media\UploadMedia;
use App\Enums\MediaCategory;
use App\Enums\MediaType;
use App\Http\Requests\ClientFolders\StoreMediaRequest;
use App\Http\Requests\ClientFolders\UpdateMediaRequest;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaReferenceController extends Controller
{
    public function globalIndex(): View
    {
        Gate::authorize('viewAny', MediaReference::class);
        $query = MediaReference::query()
            ->whereHas('clientFolder', fn ($folders) => $folders->accessibleTo(request()->user()))
            ->with(['clientFolder:id,folder_number,display_name,assigned_ci_id', 'uploader:id,full_name', 'activities:id,name', 'incomeSource:id,source_name,business_name'])
            ->latest();
        $this->applyFilters($query);

        return view('media.index', [
            'mediaItems' => $query->paginate(24)->withQueryString(),
            'categories' => MediaCategory::cases(),
        ]);
    }

    public function index(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $query = $clientFolder->mediaReferences()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['uploader:id,full_name', 'activities:id,name', 'incomeSource:id,source_name,business_name'])
            ->latest();
        $this->applyFilters($query);

        return view('client-folders.media.index', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'mediaItems' => $query->paginate(24)->withQueryString(),
            'categories' => MediaCategory::cases(),
            'activities' => $clientFolder->activities()->where('co_maker_id', $activePerson?->id)->orderBy('name')->get(['id', 'name']),
            'incomeSources' => $clientFolder->incomeSources()->where('co_maker_id', $activePerson?->id)->orderBy('sort_order')->get(['id', 'source_name', 'business_name']),
            'counts' => [
                'all' => $clientFolder->mediaReferences()->where('co_maker_id', $activePerson?->id)->count(),
                'photo' => $clientFolder->mediaReferences()->where('co_maker_id', $activePerson?->id)->where('media_type', MediaType::Photo->value)->count(),
                'video' => $clientFolder->mediaReferences()->where('co_maker_id', $activePerson?->id)->where('media_type', MediaType::Video->value)->count(),
            ],
        ]);
    }

    public function store(StoreMediaRequest $request, ClientFolder $clientFolder, UploadMedia $upload): RedirectResponse
    {
        $records = $upload->execute($request->user(), $clientFolder, $request->validated());
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));

        return redirect()->route('client-folders.media.index', [$clientFolder] + $personParams)->with('status', count($records) === 1 ? 'Media uploaded successfully.' : count($records).' media items uploaded successfully.');
    }

    public function update(UpdateMediaRequest $request, ClientFolder $clientFolder, MediaReference $mediaReference, UpdateMediaMetadata $update): RedirectResponse
    {
        $update->execute($request->user(), $clientFolder, $mediaReference, $request->validated());
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));

        return redirect()->route('client-folders.media.index', [$clientFolder] + $personParams)->with('status', 'Media details updated successfully.');
    }

    public function destroy(ClientFolder $clientFolder, MediaReference $mediaReference, RemoveMedia $remove): RedirectResponse
    {
        Gate::authorize('delete', $mediaReference);
        $personParams = ActivePersonResolver::queryParams($mediaReference->co_maker_id ? $clientFolder->coMakers()->find($mediaReference->co_maker_id) : null);
        $remove->execute(request()->user(), $clientFolder, $mediaReference);

        return redirect()->route('client-folders.media.index', [$clientFolder] + $personParams)->with('status', 'Media removed from the active gallery.');
    }

    public function content(ClientFolder $clientFolder, MediaReference $mediaReference): StreamedResponse
    {
        // Reads by exact media id — folder-level authorization already fully identifies and
        // permits the request, so no active-person check is layered on here (thumbnails/inline
        // previews are also rendered from the person-agnostic global gallery, which has no
        // "active person" query-string context to check against).
        Gate::authorize('view', $mediaReference);
        $thumbnail = request()->boolean('thumbnail') && filled($mediaReference->thumbnail_path);
        $path = $thumbnail ? $mediaReference->thumbnail_path : $mediaReference->temporary_local_path;
        abort_unless(filled($path), 404);
        $disk = Storage::disk(config('cims.media_disk'));
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $mediaReference->file_name, [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $mediaReference->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(ClientFolder $clientFolder, MediaReference $mediaReference): StreamedResponse
    {
        Gate::authorize('export', $mediaReference);
        $path = $mediaReference->temporary_local_path;
        abort_unless(filled($path), 404);
        $disk = Storage::disk(config('cims.media_disk'));
        abort_unless($disk->exists($path), 404);
        $extension = pathinfo($mediaReference->file_name, PATHINFO_EXTENSION);
        $downloadName = Str::slug($mediaReference->label ?: 'media-evidence').'.'.$extension;

        return $disk->download($path, $downloadName, [
            'Content-Type' => $mediaReference->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function applyFilters($query): void
    {
        $type = request()->string('type')->toString();
        $category = request()->string('category')->toString();
        if (in_array($type, array_column(MediaType::cases(), 'value'), true)) {
            $query->where('media_type', $type);
        }
        if (in_array($category, array_column(MediaCategory::cases(), 'value'), true)) {
            $query->where('category', $category);
        }
    }
}
