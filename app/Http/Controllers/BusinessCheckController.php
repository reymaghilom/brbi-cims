<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Actions\ClientFolders\UpdateBusinessCheckContributors;
use App\Enums\RecordState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\SaveBusinessCheckRequest;
use App\Http\Requests\ClientFolders\UpdateBusinessCheckContributorsRequest;
use App\Models\BusinessCheck;
use App\Models\BusinessCheckPhoto;
use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\Media\CloudinaryMediaStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BusinessCheckController extends Controller
{
    public function __construct(private readonly CiParticipantService $participants) {}

    public function create(ClientFolder $clientFolder): View
    {
        Gate::authorize('update', $clientFolder);

        return $this->form($clientFolder, null);
    }

    public function edit(ClientFolder $clientFolder, BusinessCheck $businessCheck): View
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($businessCheck, $activePerson);
        $businessCheck->load(['photos.uploader:id,full_name', 'photoGroups.photos.uploader:id,full_name', 'incomeSource', 'investigator:id,full_name', 'updater:id,full_name', 'contributors:id,full_name', 'mapScreenshotUploader:id,full_name']);

        return $this->form($clientFolder, $businessCheck);
    }

    public function updateContributors(UpdateBusinessCheckContributorsRequest $request, ClientFolder $clientFolder, BusinessCheck $businessCheck, UpdateBusinessCheckContributors $update): RedirectResponse
    {
        $update->execute($request->user(), $clientFolder, $businessCheck, $request->validated('contributor_ids', []));
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolveFromQuery($clientFolder, $request));

        return redirect()->route('client-folders.business-checks.edit', [$clientFolder, $businessCheck] + $personParams)->with('status', 'Contributors updated successfully.');
    }

    public function store(SaveBusinessCheckRequest $request, ClientFolder $clientFolder, SaveBusinessCheck $save): RedirectResponse
    {
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));

        try {
            $check = $save->execute($request->user(), $clientFolder, $request->validated());
        } catch (NoChangesDetectedException $e) {
            return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', $e->getMessage())->with('statusType', 'info');
        }

        // wasRecentlyCreated is Eloquent's own "this exact save() call inserted a new row" flag,
        // set inside SaveBusinessCheck::execute() and left untouched by its closing ->refresh() —
        // reading it here distinguishes Add from Update without changing that action's save logic.
        $message = $check->wasRecentlyCreated ? 'Business Check saved successfully.' : 'Business Check updated successfully.';

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', $message)->with('statusType', 'success');
    }

    public function destroy(ClientFolder $clientFolder, BusinessCheck $businessCheck, DeleteBusinessCheck $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($businessCheck, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $delete->execute(request()->user(), $clientFolder, $businessCheck);

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Business Check deleted successfully.');
    }

    /** Web delivery for one Business Picture — same Cloudinary-redirect-or-local-stream split as ResidenceCheckController::photo(). */
    public function photo(ClientFolder $clientFolder, BusinessCheck $businessCheck, BusinessCheckPhoto $photo, CloudinaryMediaStorage $cloud): Response
    {
        Gate::authorize('view', $clientFolder);
        $wantsThumbnail = request()->boolean('thumbnail');

        if ($photo->isCloud()) {
            $url = $wantsThumbnail
                ? $cloud->thumbnailUrl($photo->cloud_public_id, $photo->cloud_delivery_type)
                : $cloud->deliveryUrl($photo->cloud_public_id, $photo->cloud_delivery_type);

            return redirect()->away($url);
        }

        $thumbnail = $wantsThumbnail && filled($photo->thumbnail_path);
        $path = $thumbnail ? $photo->thumbnail_path : $photo->path;
        $disk = Storage::disk(config('cims.media_disk'));
        abort_unless(filled($path) && $disk->exists($path), 404);

        return $disk->response($path, $photo->file_name, [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $photo->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Web delivery for the saved Map Screenshot — same Cloudinary-redirect-or-local-stream split as ResidenceCheckController::mapScreenshot(). */
    public function mapScreenshot(ClientFolder $clientFolder, BusinessCheck $businessCheck, CloudinaryMediaStorage $cloud): Response
    {
        Gate::authorize('view', $clientFolder);
        $wantsThumbnail = request()->boolean('thumbnail');

        if ($businessCheck->hasCloudMapScreenshot()) {
            $url = $wantsThumbnail
                ? $cloud->thumbnailUrl($businessCheck->map_screenshot_cloud_public_id, $businessCheck->map_screenshot_cloud_delivery_type)
                : $cloud->deliveryUrl($businessCheck->map_screenshot_cloud_public_id, $businessCheck->map_screenshot_cloud_delivery_type);

            return redirect()->away($url);
        }

        $thumbnail = $wantsThumbnail && filled($businessCheck->map_screenshot_thumbnail_path);
        $path = $thumbnail ? $businessCheck->map_screenshot_thumbnail_path : $businessCheck->map_screenshot_path;
        $disk = Storage::disk(config('cims.media_disk'));
        abort_unless(filled($path) && $disk->exists($path), 404);

        return $disk->response($path, $businessCheck->map_screenshot_file_name ?? 'map-screenshot.jpg', [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $businessCheck->map_screenshot_mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function form(ClientFolder $clientFolder, ?BusinessCheck $businessCheck): View
    {
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;

        $businesses = $clientFolder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['businessReport:id,income_source_id,main_business_address,start_date', 'template'])
            ->whereHas('template', fn ($query) => $query->where('is_fallback', false)->where('form_handler', 'dedicated-business'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($source) => [
                'id' => $source->id, 'name' => $source->displayName(),
                'location' => $source->businessReport?->main_business_address,
                'ci_date' => $source->businessReport?->start_date?->format('Y-m-d'),
                // Drives the "Business Report available" / "Business Report not yet created"
                // helper text — the same completion state already tracked for every income
                // source, not a new concept invented for this selector.
                'report_complete' => $source->state === RecordState::Complete,
            ]);

        // The one authoritative business address is BusinessReport::main_business_address, and the
        // one authoritative CI Date is BusinessReport::start_date ("Start Date of CI" on the
        // Business Report form) — see SaveBusinessIncomeSource::REPORT_FIELDS and the quick-create
        // flow in IncomeSourceController::quickCreate(). Both are re-derived here on every render
        // rather than trusting the possibly-stale copy saved on the BusinessCheck row itself, so an
        // edit from Business Report is reflected the next time this Business Check is opened
        // instead of silently showing what was true whenever it was last saved. Each falls back to
        // its own saved copy only for a check whose linked business (or shared value) no longer
        // resolves — e.g. a legacy record from before this business had a Business Report at all.
        $currentBusiness = $businessCheck ? $businesses->firstWhere('id', $businessCheck->income_source_id) : null;
        $currentLocation = $currentBusiness['location'] ?? $businessCheck?->location;
        $currentCiDate = $currentBusiness['ci_date'] ?? $businessCheck?->ci_date?->format('Y-m-d');

        $mapQuery = $currentLocation;
        $photos = $businessCheck?->photos ?? collect();

        $mapPhotos = fn (string $category) => $photos->filter(fn (BusinessCheckPhoto $photo) => $photo->category?->value === $category)->map(fn (BusinessCheckPhoto $photo) => [
            'id' => $photo->id,
            'url' => route('client-folders.business-checks.photo', [$clientFolder, $businessCheck, $photo, 'thumbnail' => 1]),
            'caption' => $photo->caption,
            'uploaded_by' => $photo->uploader?->full_name,
            'uploaded_at' => $photo->created_at?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A'),
        ])->values()->all();

        // One card per saved BusinessCheckPhotoGroup, in display order, PLUS a synthetic "Photo
        // Group 1" prepended when this check still has business photos saved before this feature
        // existed (business_check_photo_group_id null) — same photo shape as $mapPhotos above,
        // just without the flat `caption` column (a group's caption lives on the group itself).
        // The legacy group carries no real id/Remove button; SaveBusinessCheck::syncPhotoGroups()
        // only ever creates a real row for it the next time this check is actually saved.
        $mapGroupPhoto = fn (BusinessCheckPhoto $photo) => [
            'id' => $photo->id,
            'url' => route('client-folders.business-checks.photo', [$clientFolder, $businessCheck, $photo, 'thumbnail' => 1]),
            'uploaded_by' => $photo->uploader?->full_name,
            'uploaded_at' => $photo->created_at?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A'),
        ];
        $photoGroups = collect();
        if ($businessCheck) {
            $legacyPhotos = $photos->filter(fn (BusinessCheckPhoto $photo) => $photo->category?->value === 'business' && $photo->business_check_photo_group_id === null)->values();
            if ($legacyPhotos->isNotEmpty()) {
                $photoGroups->push(['id' => '', 'caption' => '', 'legacy' => true, 'photos' => $legacyPhotos->map($mapGroupPhoto)->all()]);
            }
            foreach ($businessCheck->photoGroups as $group) {
                $photoGroups->push(['id' => $group->id, 'caption' => $group->caption, 'legacy' => false, 'photos' => $group->photos->map($mapGroupPhoto)->all()]);
            }
        }
        if ($photoGroups->isEmpty()) {
            $photoGroups->push(['id' => '', 'caption' => '', 'legacy' => false, 'photos' => []]);
        }

        $mapScreenshot = $businessCheck?->hasMapScreenshot() ? [
            'url' => route('client-folders.business-checks.map-screenshot', [$clientFolder, $businessCheck]),
            'uploaded_by' => $businessCheck->mapScreenshotUploader?->full_name,
        ] : null;

        // A not-yet-created Business Check has no record to derive a primary CI from — the
        // primary is simply whoever is currently encoding it, exactly as the existing (unrelated)
        // CI Name display already assumed for a brand-new check.
        $primaryCiId = $businessCheck ? $businessCheck->ciPrimaryUserId() : auth()->id();
        $companions = $businessCheck
            ? $this->participants->orderedParticipants($businessCheck)->reject(fn (User $user): bool => (int) $user->id === (int) $primaryCiId)->values()
            : collect();

        // "+ Add Business" quick-create is Applicant-only for now (see
        // QuickCreateIncomeSourceRequest) — the template list is only actually needed then, but
        // it's cheap enough to always resolve rather than branching the query itself.
        $businessTemplates = IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('client-folders.business-checks.form', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'personName' => $personName,
            'businessCheck' => $businessCheck,
            'businesses' => $businesses,
            'businessTemplates' => $businessTemplates,
            'existingCompetitorPhotos' => $businessCheck ? $mapPhotos('competitor') : [],
            'photoGroups' => $photoGroups,
            'currentLocation' => $currentLocation,
            'currentCiDate' => $currentCiDate,
            // "Open in Google Maps" helper inside the Map Screenshot section, derived from Location
            // alone now that the separate Google Maps Link input/section is gone.
            'mapOpenLink' => $mapQuery ? 'https://www.google.com/maps?q='.urlencode($mapQuery) : null,
            'mapScreenshot' => $mapScreenshot,
            'companions' => $companions,
            // The primary CI is never a valid companion choice — excluded here entirely (not
            // just disabled in the UI) so the modal's candidate list can never even present them.
            'activeCreditInvestigators' => User::query()
                ->where('role', UserRole::CreditInvestigator)
                ->where('status', UserStatus::Active)
                ->where('id', '!=', $primaryCiId)
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
        ]);
    }
}
