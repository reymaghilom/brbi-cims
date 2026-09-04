<?php

namespace App\Http\Controllers;

use App\Actions\Media\RemoveMedia;
use App\Actions\Media\UpdateMediaMetadata;
use App\Actions\Media\UploadMedia;
use App\Enums\MediaCategory;
use App\Enums\MediaType;
use App\Http\Requests\ClientFolders\StoreMediaRequest;
use App\Http\Requests\ClientFolders\UpdateMediaRequest;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\ClientFolderOverview;
use App\Services\ClientFolders\PersonAddressResolver;
use App\Services\Media\DocumentationCaptionBuilder;
use App\Services\Media\DocumentationTelegramSender;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaReferenceController extends Controller
{
    public function __construct(
        private readonly CiTeamDocumentStorage $documents,
        private readonly ClientFolderOverview $overview,
    ) {}

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

    public function index(ClientFolder $clientFolder, DocumentationCaptionBuilder $captionBuilder, DocumentationTelegramSender $telegramSender): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());

        $documentations = $clientFolder->residenceBusinessDocumentations()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['mapScreenshot', 'latestTelegramDelivery'])
            ->withCount(['pictures', 'videos'])
            ->latest('updated_at')
            ->get();

        // Residence and Business are independent, simultaneously visible panels now (no tab
        // switches which one is "active"), so each resolves its own active set from its own
        // query string key rather than sharing a single "documentation" id.
        $residenceDocumentations = $documentations->filter->isResidence()->values();
        $businessDocumentations = $documentations->reject->isResidence()->values();
        $legacyBusinessDocumentations = $businessDocumentations->filter->isLegacyBusiness()->values();
        $requestedResidenceId = request()->integer('residence_documentation') ?: null;
        $requestedBusinessId = request()->integer('business_documentation') ?: null;
        $activeResidenceDocumentation = $requestedResidenceId
            ? $residenceDocumentations->firstWhere('id', $requestedResidenceId)
            : $residenceDocumentations->first();
        $requestedBusinessDocumentation = $requestedBusinessId
            ? $businessDocumentations->firstWhere('id', $requestedBusinessId)
            : null;
        $newBusinessDraft = request()->boolean('business_draft');
        $activeBusinessDocumentation = $newBusinessDraft
            ? null
            : ($requestedBusinessDocumentation ?: $businessDocumentations->first());
        $activeResidenceDocumentation?->load(['pictures', 'videos', 'latestTelegramDelivery']);
        $activeBusinessDocumentation?->load(['pictures', 'videos', 'latestTelegramDelivery']);

        // Legacy Media: existing CI Activity Supporting Proof and any pre-redesign general
        // uploads — identified structurally by having no documentation set at all, never
        // deleted/migrated by this redesign.
        $legacyQuery = $clientFolder->mediaReferences()
            ->where('co_maker_id', $activePerson?->id)
            ->whereNull('residence_business_documentation_id')
            ->with(['uploader:id,full_name', 'activities:id,name', 'incomeSource:id,source_name,business_name'])
            ->latest();
        $this->applyFilters($legacyQuery);

        return view('client-folders.media.index', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'residenceDocumentations' => $residenceDocumentations,
            'businessDocumentations' => $businessDocumentations,
            'legacyBusinessDocumentations' => $legacyBusinessDocumentations,
            'activeResidenceDocumentation' => $activeResidenceDocumentation,
            'activeBusinessDocumentation' => $activeBusinessDocumentation,
            'residenceCaption' => $activeResidenceDocumentation ? $captionBuilder->build($activeResidenceDocumentation) : null,
            'businessCaption' => $activeBusinessDocumentation ? $captionBuilder->build($activeBusinessDocumentation) : null,
            'telegramMessageBodyMaxLength' => $captionBuilder->maxMessageBodyLength(request()->user()),
            'telegramConfigured' => $telegramSender->configured(),
            'residenceDocumentationActivity' => $this->overview->documentationActivity($clientFolder, $activePerson, ResidenceBusinessDocumentation::CATEGORY_RESIDENCE),
            'businessDocumentationActivity' => $this->overview->documentationActivity($clientFolder, $activePerson, ResidenceBusinessDocumentation::CATEGORY_BUSINESS, $activeBusinessDocumentation?->id),
            // Prefill-before-save, independent-after-save: only offered while starting a brand-new
            // Residence set — an existing one already has its own authoritative saved location.
            'residenceCibiPrefill' => $activeResidenceDocumentation ? null : PersonAddressResolver::savedCibiPresentAddress($clientFolder, $activePerson),
            'businessLocationPrefill' => null,
            'mediaItems' => $legacyQuery->paginate(24)->withQueryString(),
            'categories' => MediaCategory::cases(),
            'activities' => $clientFolder->activities()->where('co_maker_id', $activePerson?->id)->orderBy('name')->get(['id', 'name']),
            'incomeSources' => $clientFolder->incomeSources()->where('co_maker_id', $activePerson?->id)->orderBy('sort_order')->get(['id', 'source_name', 'business_name']),
            'counts' => [
                'all' => $clientFolder->mediaReferences()->where('co_maker_id', $activePerson?->id)->whereNull('residence_business_documentation_id')->count(),
                'photo' => $clientFolder->mediaReferences()->where('co_maker_id', $activePerson?->id)->whereNull('residence_business_documentation_id')->where('media_type', MediaType::Photo->value)->count(),
                'video' => $clientFolder->mediaReferences()->where('co_maker_id', $activePerson?->id)->whereNull('residence_business_documentation_id')->where('media_type', MediaType::Video->value)->count(),
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

    public function content(ClientFolder $clientFolder, MediaReference $mediaReference): StreamedResponse|RedirectResponse
    {
        // Reads by exact media id — folder-level authorization already fully identifies and
        // permits the request, so no active-person check is layered on here (thumbnails/inline
        // previews are also rendered from the person-agnostic global gallery, which has no
        // "active person" query-string context to check against).
        Gate::authorize('view', $mediaReference);
        abort_unless($mediaReference->client_folder_id === $clientFolder->id, 404);

        return $this->contentResponse($mediaReference);
    }

    public function activityContent(ClientFolder $clientFolder, CiActivity $ciActivity, MediaReference $mediaReference): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $mediaReference);
        abort_unless($ciActivity->client_folder_id === $clientFolder->id, 404);
        abort_unless($mediaReference->client_folder_id === $clientFolder->id, 404);
        abort_unless($mediaReference->co_maker_id === $ciActivity->co_maker_id, 404);
        abort_unless($ciActivity->mediaReferences()->whereKey($mediaReference->id)->exists(), 404);

        return $this->contentResponse($mediaReference);
    }

    public function download(ClientFolder $clientFolder, MediaReference $mediaReference): StreamedResponse|RedirectResponse
    {
        Gate::authorize('export', $mediaReference);
        abort_unless($mediaReference->client_folder_id === $clientFolder->id, 404);
        if ($mediaReference->storage_provider === MediaReference::STORAGE_PROVIDER_CLOUDINARY) {
            abort_unless(filled($mediaReference->cloudinary_secure_url), 404);

            return redirect()->away($mediaReference->cloudinary_secure_url);
        }

        $path = $mediaReference->temporary_local_path;
        abort_unless(filled($path), 404);
        abort_unless(in_array($mediaReference->storage_provider, [MediaReference::STORAGE_PROVIDER_LOCAL, MediaReference::STORAGE_PROVIDER_CI_TEAM], true), 404);
        $disk = $this->documents->diskForMedia($mediaReference, $path);
        abort_unless($disk->exists($path), 404);
        $extension = pathinfo($mediaReference->file_name, PATHINFO_EXTENSION);
        $downloadName = Str::slug($mediaReference->label ?: 'media-evidence').'.'.$extension;

        return $disk->download($path, $downloadName, [
            'Content-Type' => $mediaReference->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
