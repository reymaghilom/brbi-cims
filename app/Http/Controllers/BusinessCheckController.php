<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Actions\ClientFolders\UpdateBusinessCheckContributors;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\SaveBusinessCheckRequest;
use App\Http\Requests\ClientFolders\UpdateBusinessCheckContributorsRequest;
use App\Models\BusinessCheck;
use App\Models\BusinessCheckPhoto;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\Media\CloudinaryMediaStorage;
use App\Services\Media\EvidenceStorageRecorder;
use App\Services\Storage\CiTeamDocumentStorage;
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
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, $request);
        ActivePersonResolver::assertOwnedBy($businessCheck, $activePerson);
        $update->execute($request->user(), $clientFolder, $businessCheck, $request->validated('contributor_ids', []));
        $personParams = ActivePersonResolver::queryParams($activePerson);

        return redirect()->route('client-folders.business-checks.edit', [$clientFolder, $businessCheck] + $personParams)->with('status', 'Contributors updated successfully.');
    }

    public function store(SaveBusinessCheckRequest $request, ClientFolder $clientFolder, SaveBusinessCheck $save, EvidenceStorageRecorder $storage): RedirectResponse|JsonResponse
    {
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));
        $wantsJson = $request->expectsJson();
        // The recorder only ever describes THIS save, never anything an earlier one stored.
        $storage->reset();

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
        $baseMessage = $check->wasRecentlyCreated ? 'Business Check saved successfully.' : 'Business Check updated successfully.';
        // Named from where the files in THIS request actually landed — a text-only save keeps the
        // plain message; see ResidenceCheckController::store() for the same rule.
        $storageLabel = $storage->label();
        $message = trim($baseMessage.($storageLabel === null ? '' : ' Files saved to '.$storageLabel.'.'));

        if ($wantsJson) {
            return response()->json([
                'result' => 'success',
                'message' => $message,
                'status_type' => 'success',
                'storage_provider' => $storage->provider(),
                'storage_label' => $storageLabel,
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
        ActivePersonResolver::assertOwnedBy($businessCheck, ActivePersonResolver::resolveFromQuery($clientFolder, request()));
        $wantsThumbnail = request()->boolean('thumbnail');

        if ($photo->isCloud()) {
            $url = $wantsThumbnail
                ? $cloud->thumbnailUrl($photo->cloud_public_id, $photo->cloud_delivery_type)
                : $cloud->deliveryUrl($photo->cloud_public_id, $photo->cloud_delivery_type);

            return redirect()->away($url);
        }

        $thumbnail = $wantsThumbnail && filled($photo->thumbnail_path);
        $path = $thumbnail ? $photo->thumbnail_path : $photo->path;
        abort_unless(filled($path), 404);
        // The record's own stored path decides where the file lives — never the current Evidence
        // Storage setting — so pictures saved before an administrator switched modes keep opening.
        $disk = app(CiTeamDocumentStorage::class)->evidenceDisk((string) $path);
        abort_unless($disk->exists($path), 404);

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
        ActivePersonResolver::assertOwnedBy($businessCheck, ActivePersonResolver::resolveFromQuery($clientFolder, request()));
        $wantsThumbnail = request()->boolean('thumbnail');

        if ($businessCheck->hasCloudMapScreenshot()) {
            $url = $wantsThumbnail
                ? $cloud->thumbnailUrl($businessCheck->map_screenshot_cloud_public_id, $businessCheck->map_screenshot_cloud_delivery_type)
                : $cloud->deliveryUrl($businessCheck->map_screenshot_cloud_public_id, $businessCheck->map_screenshot_cloud_delivery_type);

            return redirect()->away($url);
        }

        $thumbnail = $wantsThumbnail && filled($businessCheck->map_screenshot_thumbnail_path);
        $path = $thumbnail ? $businessCheck->map_screenshot_thumbnail_path : $businessCheck->map_screenshot_path;
        abort_unless(filled($path), 404);
        // The record's own stored path decides where the file lives — never the current Evidence
        // Storage setting — so pictures saved before an administrator switched modes keep opening.
        $disk = app(CiTeamDocumentStorage::class)->evidenceDisk((string) $path);
        abort_unless($disk->exists($path), 404);

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
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $requestedCreateIncomeSourceId = ! $businessCheck && request()->has('income_source_id')
            ? request()->integer('income_source_id')
            : null;
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
        // Business Check NEVER creates a business. When this list is empty (or the CI leaves it
        // unselected) the business is recorded manually on the Business Check itself and
        // income_source_id simply stays null — no IncomeSource, no BusinessReport, no shell.
        $businesses = $clientFolder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['businessReport:id,income_source_id,main_business_address,start_date', 'template'])
            ->whereHas('template', fn ($query) => $query->where('is_fallback', false)->where('form_handler', 'dedicated-business'))
            ->where(fn ($query) => $query
                ->where(fn ($saved) => $saved->whereHas('businessReport')->where('revision', '>', 1))
                ->when($businessCheck, fn ($query) => $query->orWhere('id', $businessCheck->income_source_id))
                ->when($requestedCreateIncomeSourceId, fn ($query, int $incomeSourceId) => $query->orWhere('id', $incomeSourceId)))
            ->where(fn ($query) => $query
                ->whereDoesntHave('businessCheck')
                ->when($businessCheck, fn ($query) => $query->orWhere('id', $businessCheck->income_source_id)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($source) => [
                // resolvedBusinessName() also covers the six templates that have no Business Name
                // input, including historical rows saved before that default existed — so this
                // dropdown and its prefill never show a blank business.
                'id' => $source->id, 'name' => $source->resolvedBusinessName(),
                'location' => $source->businessReport?->main_business_address,
                'ci_date' => $source->businessReport?->start_date?->format('Y-m-d'),
                'existing_check_id' => $existingChecksByIncomeSource->get($source->id)?->id,
            ]);

        $requestedIncomeSourceId = $businessCheck?->income_source_id
            ?? old('income_source_id', $requestedCreateIncomeSourceId);
        if (! $businessCheck && request()->has('income_source_id')) {
            abort_unless($businesses->contains('id', (int) $requestedIncomeSourceId), 404);
        }

        // Report → Check is PREFILL ONLY, and only for a Business Check that doesn't exist yet —
        // an existing, already-saved Business Check always shows its own persisted values here,
        // never a newer value from the Business Report (see
        // BusinessReportBusinessCheckIndependenceTest). For a brand-new check, the initially
        // selected business (old('income_source_id') on a validation-failed reload) still prefills
        // from that business's current Business Report, exactly like each <option>'s
        // data-location/data-ci-date attributes drive the same prefill client-side on selection.
        $selectedNewBusiness = $businessCheck ? null : $businesses->firstWhere('id', (int) $requestedIncomeSourceId);
        $currentLocation = $businessCheck ? $businessCheck->location : ($selectedNewBusiness['location'] ?? null);
        $currentCiDate = $businessCheck ? $businessCheck->ci_date?->format('Y-m-d') : ($selectedNewBusiness['ci_date'] ?? null);
        // Business Name is an editable input only for a manual Business Check; for one that
        // references a business it renders read-only and shows that business's own name.
        $currentBusinessName = $businessCheck ? $businessCheck->business_name : ($selectedNewBusiness['name'] ?? null);

        // Read-only is decided PER FIELD, on whether an authoritative value actually exists —
        // referencing a business is not on its own enough. Business Name and CI Date always have
        // one for a saved Business Report (CI Date is mandatory in that workflow, and the six
        // no-input templates now carry a derived name), but Main Business Address genuinely can be
        // blank; when it is, the CI types it here and it is stored on the Business Check ALONE —
        // SaveBusinessCheck never writes any of it back into the Business Report.
        $linkedSource = $businessCheck?->income_source_id ?? ($selectedNewBusiness['id'] ?? null);
        $linkedLocation = $businessCheck
            ? ($businessCheck->income_source_id === null ? null : $businesses->firstWhere('id', $businessCheck->income_source_id)['location'] ?? null)
            : ($selectedNewBusiness['location'] ?? null);
        $businessNameReadOnly = $linkedSource !== null && filled($currentBusinessName);
        $locationReadOnly = $linkedSource !== null && filled($linkedLocation);
        $ciDateReadOnly = $linkedSource !== null && filled($currentCiDate);

        $mapQuery = $currentLocation;
        $photos = $businessCheck?->photos ?? collect();

        $mapPhotos = fn (string $category) => $photos->filter(fn (BusinessCheckPhoto $photo) => $photo->category?->value === $category)->map(fn (BusinessCheckPhoto $photo) => [
            'id' => $photo->id,
            'url' => route('client-folders.business-checks.photo', [$clientFolder, $businessCheck, $photo] + $personParams + ['thumbnail' => 1]),
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
            'url' => route('client-folders.business-checks.photo', [$clientFolder, $businessCheck, $photo] + $personParams + ['thumbnail' => 1]),
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
            'url' => route('client-folders.business-checks.map-screenshot', [$clientFolder, $businessCheck] + $personParams),
            'uploaded_by' => $businessCheck->mapScreenshotUploader?->full_name,
        ] : null;

        // A not-yet-created Business Check has no record to derive a primary CI from — the
        // primary is simply whoever is currently encoding it, exactly as the existing (unrelated)
        // CI Name display already assumed for a brand-new check.
        $primaryCiId = $businessCheck ? $businessCheck->ciPrimaryUserId() : auth()->id();
        $companions = $businessCheck
            ? $this->participants->orderedParticipants($businessCheck)->reject(fn (User $user): bool => (int) $user->id === (int) $primaryCiId)->values()
            : collect();

        return view('client-folders.business-checks.form', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'personName' => $personName,
            'businessCheck' => $businessCheck,
            'businesses' => $businesses,
            'selectedIncomeSourceId' => $requestedIncomeSourceId,
            'existingCompetitorPhotos' => $businessCheck ? $mapPhotos('competitor') : [],
            'photoGroups' => $photoGroups,
            'currentLocation' => $currentLocation,
            'currentCiDate' => $currentCiDate,
            'currentBusinessName' => $currentBusinessName,
            'businessNameReadOnly' => $businessNameReadOnly,
            'locationReadOnly' => $locationReadOnly,
            'ciDateReadOnly' => $ciDateReadOnly,
            // "Open in Google Maps" helper inside the Map Screenshot section, derived from Location
            // alone now that the separate Google Maps Link input/section is gone.
            'mapOpenLink' => $mapQuery ? 'https://www.google.com/maps?q='.urlencode($mapQuery) : null,
            'mapScreenshot' => $mapScreenshot,
            'companions' => $companions,
            // The primary CI is never a valid companion choice — excluded here entirely (not
            // just disabled in the UI) so the modal's candidate list can never even present them.
            'activeCreditInvestigators' => User::query()
                ->whereIn('role', UserRole::creditInvestigatorRoles())
                ->where('status', UserStatus::Active)
                ->where('id', '!=', $primaryCiId)
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
        ]);
    }
}
