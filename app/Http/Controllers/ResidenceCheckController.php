<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Actions\ClientFolders\UpdateResidenceCheckContributors;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\CloudMediaUploadException;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\SaveResidenceCheckRequest;
use App\Http\Requests\ClientFolders\UpdateResidenceCheckContributorsRequest;
use App\Models\ClientFolder;
use App\Models\ResidenceCheck;
use App\Models\ResidenceCheckPhoto;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\PersonAddressResolver;
use App\Services\ClientFolders\PersonCiDateResolver;
use App\Services\Media\CloudinaryMediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ResidenceCheckController extends Controller
{
    public function __construct(private readonly CiParticipantService $participants) {}

    public function create(ClientFolder $clientFolder, CloudinaryMediaStorage $cloud): View
    {
        Gate::authorize('update', $clientFolder);

        return $this->form($clientFolder, null, $cloud);
    }

    public function edit(ClientFolder $clientFolder, ResidenceCheck $residenceCheck, CloudinaryMediaStorage $cloud): View
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($residenceCheck, $activePerson);
        $residenceCheck->load('photos.uploader:id,full_name', 'investigator:id,full_name', 'updater:id,full_name', 'contributors:id,full_name', 'mapScreenshotUploader:id,full_name');

        return $this->form($clientFolder, $residenceCheck, $cloud);
    }

    public function updateContributors(UpdateResidenceCheckContributorsRequest $request, ClientFolder $clientFolder, ResidenceCheck $residenceCheck, UpdateResidenceCheckContributors $update): RedirectResponse
    {
        $update->execute($request->user(), $clientFolder, $residenceCheck, $request->validated('contributor_ids', []));
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolveFromQuery($clientFolder, $request));

        return redirect()->route('client-folders.residence-checks.edit', [$clientFolder, $residenceCheck] + $personParams)->with('status', 'Contributors updated successfully.');
    }

    /**
     * The AJAX Residence save flow ([data-residence-check-form] in app.js) always sends
     * `Accept: application/json`, which is exactly the same signal Laravel's own ValidationException
     * JSON rendering already keys off (Request::expectsJson()) — so a validation failure here
     * automatically gets the matching JSON {message, errors} shape for free, no extra code needed.
     * Every branch below only changes behavior for that same AJAX case; a plain (non-JS) browser
     * submission never sends that header, so $wantsJson is false and every original
     * redirect-with-flash response is completely untouched.
     */
    public function store(SaveResidenceCheckRequest $request, ClientFolder $clientFolder, SaveResidenceCheck $save, CloudinaryMediaStorage $cloud): RedirectResponse|JsonResponse
    {
        $wasCreate = blank($request->validated('check_id'));
        $checkId = $request->validated('check_id');
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));
        $wantsJson = $request->expectsJson();

        try {
            $check = $save->execute($request->user(), $clientFolder, $request->validated());
        } catch (NoChangesDetectedException $e) {
            if ($wantsJson) {
                // The modal stays open for this outcome (no parent postMessage is ever sent for
                // it), so there's no reload for a session flash to need to survive — the AJAX
                // handler shows this directly in the still-open form via the ordinary toast helper.
                return response()->json(['result' => 'no_change', 'message' => $e->getMessage(), 'status_type' => 'info']);
            }

            return redirect()->route('client-folders.residence-checks.edit', [$clientFolder, $checkId] + $personParams)
                ->with('status', $e->getMessage())->with('statusType', 'info');
        } catch (CloudMediaUploadException) {
            // The whole save was rolled back (SaveResidenceCheck's own catch block already cleaned
            // up any orphaned upload before rethrowing) — nothing was saved.
            $message = 'Residence Check was not saved because one or more photos could not be uploaded to cloud storage. Please check your connection and try again.';
            if ($wantsJson) {
                // 502 (not 422): this is never a validation problem — Cloudinary/the network is
                // what failed — so the AJAX handler can tell it apart from SaveResidenceCheckRequest's
                // own field-level validation errors without inspecting the message text.
                return response()->json(['result' => 'cloud_failure', 'message' => $message, 'status_type' => 'error'], 502);
            }

            // A create goes back to the create form and an edit goes back to its own existing
            // record, never to the listing page. withInput() restores Remarks/etc.; the newly
            // selected file(s) cannot survive the redirect (browsers never let a script repopulate
            // a file input), so the CI only needs to reselect the photo/screenshot itself and retry.
            $retryRoute = $wasCreate
                ? redirect()->route('client-folders.residence-checks.create', [$clientFolder] + $personParams)
                : redirect()->route('client-folders.residence-checks.edit', [$clientFolder, $checkId] + $personParams);

            return $retryRoute->withInput()->with('status', $message)->with('statusType', 'error');
        }

        $baseMessage = $wasCreate ? 'Residence Check saved successfully.' : 'Residence Check updated successfully.';
        $message = trim($baseMessage.' '.$this->cloudUploadSuffix($request, $cloud));

        if ($wantsJson) {
            // The modal closes and the parent reloads the listing page immediately on this
            // response (see the brbi:check-saved postMessage in app.js) — a toast shown here would
            // just be destroyed with the rest of this iframe's DOM before anyone could read it, so
            // the message travels straight through this JSON payload instead: app.js relays it via
            // sessionStorage and the reloaded page renders it itself. This deliberately does NOT
            // rely on session()->flash() surviving until that reload — an unrelated request (e.g.
            // the editing-presence heartbeat's own 30s interval) landing in between can age a flash
            // out before the reload ever gets to read it, which made this toast disappear
            // intermittently when that used to be how the message got there.
            return response()->json([
                'result' => 'success',
                'message' => $message,
                'status_type' => 'success',
                'return_url' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams),
            ]);
        }

        // Redirecting back to the check's own edit page (rather than straight to the Residence &
        // Business Checks listing) keeps the response inside the Add/Edit form's own no-sidebar
        // layout (layouts.check-encoding) — that page's session-status notify hook is what tells
        // the parent window's modal it's safe to close and refresh the listing behind it. A direct
        // redirect to the listing page would render the full app layout (sidebar and all) inside
        // the modal's iframe instead.
        return redirect()->route('client-folders.residence-checks.edit', [$clientFolder, $check] + $personParams)->with('status', $message);
    }

    /**
     * "Photos uploaded to cloud storage." / "Media uploaded to cloud storage." appended to the
     * base save/update message — only when this save actually sent something new to Cloudinary
     * (never when Cloud Storage isn't configured, since local-disk storage isn't "cloud" at all).
     * New Residence Photos take priority over a Map Screenshot when both were uploaded in the same
     * save, mirroring the Saving… loading text's own priority.
     */
    private function cloudUploadSuffix(SaveResidenceCheckRequest $request, CloudinaryMediaStorage $cloud): string
    {
        if (! $cloud->enabled()) {
            return '';
        }
        if (filled($request->validated('photos'))) {
            return 'Photos uploaded to cloud storage.';
        }
        if (filled($request->validated('map_screenshot'))) {
            return 'Media uploaded to cloud storage.';
        }

        return '';
    }

    public function destroy(ClientFolder $clientFolder, ResidenceCheck $residenceCheck, DeleteResidenceCheck $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($residenceCheck, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $delete->execute(request()->user(), $clientFolder, $residenceCheck);

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Residence Check deleted successfully.');
    }

    /**
     * Web delivery for one Residence Picture: Laravel only ever authorizes the request and decides
     * *which* asset applies — for a Cloudinary-backed photo it hands the browser a signed delivery
     * URL via redirect (so the actual image bytes are fetched straight from Cloudinary/CDN, never
     * proxied through this app), and only streams bytes itself for the local-storage fallback path
     * historical photos still use.
     */
    public function photo(ClientFolder $clientFolder, ResidenceCheck $residenceCheck, ResidenceCheckPhoto $photo, CloudinaryMediaStorage $cloud): Response
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

    /** Web delivery for the saved Map Screenshot — same Cloudinary-redirect-or-local-stream split as photo() above. */
    public function mapScreenshot(ClientFolder $clientFolder, ResidenceCheck $residenceCheck, CloudinaryMediaStorage $cloud): Response
    {
        Gate::authorize('view', $clientFolder);
        $wantsThumbnail = request()->boolean('thumbnail');

        if ($residenceCheck->hasCloudMapScreenshot()) {
            $url = $wantsThumbnail
                ? $cloud->thumbnailUrl($residenceCheck->map_screenshot_cloud_public_id, $residenceCheck->map_screenshot_cloud_delivery_type)
                : $cloud->deliveryUrl($residenceCheck->map_screenshot_cloud_public_id, $residenceCheck->map_screenshot_cloud_delivery_type);

            return redirect()->away($url);
        }

        $thumbnail = $wantsThumbnail && filled($residenceCheck->map_screenshot_thumbnail_path);
        $path = $thumbnail ? $residenceCheck->map_screenshot_thumbnail_path : $residenceCheck->map_screenshot_path;
        $disk = Storage::disk(config('cims.media_disk'));
        abort_unless(filled($path) && $disk->exists($path), 404);

        return $disk->response($path, $residenceCheck->map_screenshot_file_name ?? 'map-screenshot.jpg', [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $residenceCheck->map_screenshot_mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function form(ClientFolder $clientFolder, ?ResidenceCheck $residenceCheck, CloudinaryMediaStorage $cloud): View
    {
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $personLabel = $activePerson ? 'Co-Maker Name' : 'Applicant Name';
        $resolvedAddress = PersonAddressResolver::resolve($clientFolder, $activePerson);
        // A resolved exact-person address only supplies the initial prefill for a new check;
        // an existing Applicant or Co-Maker check keeps its own editable saved Location snapshot.
        // An existing one keeps whatever location was captured at the time it was saved — a
        // historical snapshot that must not silently change just because the master address was
        // edited afterward, and must not disappear just because the master address is now blank.
        $defaultLocation = $residenceCheck?->location ?? $resolvedAddress;
        $needsLocationInput = ! $residenceCheck && blank($resolvedAddress);
        $resolvedCiDate = PersonCiDateResolver::resolve($clientFolder, $activePerson);
        $hasScopedCibiReport = $clientFolder->cibiReports()->where('co_maker_id', $activePerson?->id)->exists();
        // Existing Residence keeps its stored date; new Residence uses CI/BI when available.
        // Applicant-only may enter the initial date when CI/BI has not been created yet.
        $defaultCiDate = $residenceCheck?->ci_date ?? $resolvedCiDate;
        $needsApplicantCiDateInput = ! $residenceCheck && ! $activePerson && ! $hasScopedCibiReport && ! $resolvedCiDate;
        $missingCiDate = ! $residenceCheck && ! $resolvedCiDate && ($activePerson || $hasScopedCibiReport);
        // Unlike Location's Co-Maker fallback, CI/BI Report itself is a real, precisely-scoped
        // page for both roles — the same route with the active person's own query params opens
        // that exact person's report.
        $ciDateManagementUrl = route('client-folders.cibi-report.edit', [$clientFolder] + ActivePersonResolver::queryParams($activePerson));

        $existingPhotos = ($residenceCheck?->photos ?? collect())->map(fn (ResidenceCheckPhoto $photo) => [
            'id' => $photo->id,
            'url' => route('client-folders.residence-checks.photo', [$clientFolder, $residenceCheck, $photo, 'thumbnail' => 1]),
            'caption' => $photo->caption,
            'uploaded_by' => $photo->uploader?->full_name,
            'uploaded_at' => $photo->created_at?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A'),
        ])->all();

        $mapScreenshot = $residenceCheck?->hasMapScreenshot() ? [
            'url' => route('client-folders.residence-checks.map-screenshot', [$clientFolder, $residenceCheck]),
            'uploaded_by' => $residenceCheck->mapScreenshotUploader?->full_name,
        ] : null;

        // Plain "Open in Google Maps" link — a Maps search URL built from the authoritative
        // Location text alone, never an API call, never latitude/longitude. Null (and the button
        // disabled) whenever there's no address to search for yet.
        $openInGoogleMapsUrl = filled($defaultLocation) ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($defaultLocation) : null;

        // A not-yet-created Residence Check has no record to derive a primary CI from — the
        // primary is simply whoever is currently encoding it, same convention Business Check's
        // own form already uses.
        $primaryCiId = $residenceCheck ? $residenceCheck->ciPrimaryUserId() : auth()->id();
        $companions = $residenceCheck
            ? $this->participants->orderedParticipants($residenceCheck)->reject(fn (User $user): bool => (int) $user->id === (int) $primaryCiId)->values()
            : collect();

        return view('client-folders.residence-checks.form', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'personName' => $personName,
            'personLabel' => $personLabel,
            'residenceCheck' => $residenceCheck,
            'defaultLocation' => $defaultLocation,
            'needsLocationInput' => $needsLocationInput,
            'defaultCiDate' => $defaultCiDate,
            'needsApplicantCiDateInput' => $needsApplicantCiDateInput,
            'missingCiDate' => $missingCiDate,
            'ciDateManagementUrl' => $ciDateManagementUrl,
            'existingPhotos' => $existingPhotos,
            'mapScreenshot' => $mapScreenshot,
            'openInGoogleMapsUrl' => $openInGoogleMapsUrl,
            'cloudStorageEnabled' => $cloud->enabled(),
            'companions' => $companions,
            // The primary CI is never a valid companion choice — excluded here entirely (not just
            // disabled in the UI) so the modal's candidate list can never even present them.
            'activeCreditInvestigators' => User::query()
                ->where('role', UserRole::CreditInvestigator)
                ->where('status', UserStatus::Active)
                ->where('id', '!=', $primaryCiId)
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
        ]);
    }
}
