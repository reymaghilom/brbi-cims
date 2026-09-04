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
use Illuminate\Http\JsonResponse;
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

    public function store(SaveBusinessCheckRequest $request, ClientFolder $clientFolder, SaveBusinessCheck $save): RedirectResponse|JsonResponse
    {
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));
        $wantsJson = $request->expectsJson();

        try {
            $check = $save->execute($request->user(), $clientFolder, $request->validated());
        } catch (NoChangesDetectedException $e) {
            if ($wantsJson) {
                return response()->json(['result' => 'no_change', 'message' => $e->getMessage(), 'status_type' => 'info']);
            }

            return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', $e->getMessage())->with('statusType', 'info');
        }

        // wasRecentlyCreated is Eloquent's own "this exact save() call inserted a new row" flag,
        // set inside SaveBusinessCheck::execute() and left untouched by its closing ->refresh() —
        // reading it here distinguishes Add from Update without changing that action's save logic.
        $message = $check->wasRecentlyCreated ? 'Business Check saved successfully.' : 'Business Check updated successfully.';

        if ($wantsJson) {
            return response()->json([
                'result' => 'success',
                'message' => $message,
                'status_type' => 'success',
                'return_url' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams),
            ]);
        }

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', $message)->with('statusType', 'success');
    }

    public function destroy(ClientFolder $clientFolder, BusinessCheck $businessCheck, DeleteBusinessCheck $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($businessCheck, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $delete->execute(request()->user(), $clientFolder, $businessCheck);

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Business Check permanently deleted.');
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
        $existingChecksByIncomeSource = $clientFolder->businessChecks()
            ->where('co_maker_id', $activePerson?->id)
            ->get(['id', 'income_source_id'])
            ->keyBy('income_source_id');

        // The "Select Business" dropdown's source of truth is EXPLICITLY SAVED Business Reports
        // only (revision > 1 AND a BusinessReport row exists — the same authoritative "actually
        // saved" convention as IncomeSourceController::dedicatedSources()'s requireReport). A
        // revision-1 draft/Check-first/quick-add shell is not a saved Report and must never appear
        // here, and neither may a business whose Report row was hard-deleted. A candidate must also
        // have no Business Check of its own yet (no duplicate candidates — server-side rejection in
        // SaveBusinessCheck stays in place regardless of this listing rule). Orphaned IncomeSources
        // (no meaningful Report and no Check — see DeleteIncomeSourceIfOrphaned) are force-deleted at
        // delete time and so never reach this query at all. The Business Check currently being
        // edited is the one exception to both rules: its own business must still render (and remain
        // selected) no matter its Report/Check state, or the edit form itself would have nothing to
        // select — CREATE mode (no $businessCheck) never grants this exception.
        //
        // "+ Add Business" quick-add deliberately does NOT go through this list at all: it creates
        // its revision-1 IncomeSource via a separate AJAX endpoint and the client-side script
        // (app.js) appends/selects that business's <option> directly in the already-open form — see
        // ApplicantBusinessQuickAddTest. That in-session continuation never touches this query, so a
        // quick-added business can still be used to finish creating the CURRENT Business Check, but
        // reopening this form fresh afterward will not list it until its Report is explicitly saved.
        $businesses = $clientFolder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['businessReport:id,income_source_id,main_business_address,start_date', 'businessDocumentation:id,income_source_id,location', 'template'])
            ->whereHas('template', fn ($query) => $query->where('is_fallback', false)->where('form_handler', 'dedicated-business'))
            ->where(fn ($query) => $query
                ->where(fn ($saved) => $saved->whereHas('businessReport')->where('revision', '>', 1))
                ->orWhereHas('businessDocumentation')
                ->when($businessCheck, fn ($query) => $query->orWhere('id', $businessCheck->income_source_id)))
            ->where(fn ($query) => $query
                ->whereDoesntHave('businessCheck')
                ->when($businessCheck, fn ($query) => $query->orWhere('id', $businessCheck->income_source_id)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($source) => [
                'id' => $source->id, 'name' => $source->displayName(),
                'location' => $source->businessReport?->main_business_address ?: $source->businessDocumentation?->location,
                'ci_date' => $source->businessReport?->start_date?->format('Y-m-d'),
                'existing_check_id' => $existingChecksByIncomeSource->get($source->id)?->id,
                // Drives the "Business Report available" / "Business Report not yet created"
                // helper text — the same completion state already tracked for every income
                // source, not a new concept invented for this selector.
                'report_complete' => $source->state === RecordState::Complete,
            ]);

        // Report → Check is PREFILL ONLY, and only for a Business Check that doesn't exist yet —
        // an existing, already-saved Business Check always shows its own persisted values here,
        // never a newer value from the Business Report (see
        // BusinessReportBusinessCheckIndependenceTest). For a brand-new check, the initially
        // selected business (old('income_source_id') on a validation-failed reload) still prefills
        // from that business's current Business Report, exactly like each <option>'s
        // data-location/data-ci-date attributes drive the same prefill client-side on selection.
        $selectedNewBusiness = $businessCheck ? null : $businesses->firstWhere('id', (int) old('income_source_id'));
        $currentLocation = $businessCheck ? $businessCheck->location : ($selectedNewBusiness['location'] ?? null);
        $currentCiDate = $businessCheck ? $businessCheck->ci_date?->format('Y-m-d') : ($selectedNewBusiness['ci_date'] ?? null);

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

        // Shared "+ Add Business" quick-create template list for the active Applicant or exact
        // Co-Maker; ownership itself remains request-validated by co_maker_id.
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
