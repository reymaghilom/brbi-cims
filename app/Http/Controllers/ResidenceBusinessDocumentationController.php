<?php

namespace App\Http\Controllers;

use App\Actions\Media\RemoveMedia;
use App\Actions\Media\SaveDocumentationMapScreenshot;
use App\Actions\Media\SaveResidenceBusinessDocumentation;
use App\Actions\Media\UploadMedia;
use App\Http\Requests\ClientFolders\SaveResidenceBusinessDocumentationRequest;
use App\Http\Requests\ClientFolders\SendDocumentationToTelegramRequest;
use App\Http\Requests\ClientFolders\UploadDocumentationMapScreenshotRequest;
use App\Http\Requests\ClientFolders\UploadDocumentationMediaRequest;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\ClientFolderOverview;
use App\Services\Media\DocumentationCaptionBuilder;
use App\Services\Media\DocumentationStorageException;
use App\Services\Media\DocumentationTelegramSender;
use App\Services\Media\DocumentationTelegramSendException;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class ResidenceBusinessDocumentationController extends Controller
{
    public function store(SaveResidenceBusinessDocumentationRequest $request, ClientFolder $clientFolder, SaveResidenceBusinessDocumentation $save): JsonResponse|RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        try {
            $documentation = $this->saveWithStagedMedia($request, $clientFolder, $save);
        } catch (DocumentationStorageException $exception) {
            return $this->storageFailureResponse($request, $exception->getMessage());
        }

        return $this->saveResponse($request, $clientFolder, $documentation, $activePerson, 'Documentation saved as draft.');
    }

    public function update(SaveResidenceBusinessDocumentationRequest $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, SaveResidenceBusinessDocumentation $save): JsonResponse|RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $this->assertDocumentationScope($clientFolder, $documentation, $activePerson);
        try {
            $documentation = $this->saveWithStagedMedia($request, $clientFolder, $save, $documentation);
        } catch (DocumentationStorageException $exception) {
            return $this->storageFailureResponse($request, $exception->getMessage());
        }

        return $this->saveResponse($request, $clientFolder, $documentation, $activePerson, 'Documentation updated.');
    }

    /**
     * Attaches whatever media the encoder staged alongside the save. This is what lets the very
     * first Save Locally create the set AND its map screenshot / pictures / video in one step,
     * so the upload controls can be used immediately instead of being gated behind creating an
     * empty set first. Each piece is optional; nothing here runs when nothing was staged.
     */
    private function saveWithStagedMedia(SaveResidenceBusinessDocumentationRequest $request, ClientFolder $clientFolder, SaveResidenceBusinessDocumentation $save, ?ResidenceBusinessDocumentation $documentation = null): ResidenceBusinessDocumentation
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($request, $clientFolder, $save, $documentation, &$storedPaths): ResidenceBusinessDocumentation {
                $documentation = $save->execute($request->user(), $clientFolder, $request->validated(), $documentation);
                $this->attachStagedMedia($request, $clientFolder, $documentation, $storedPaths);

                return $documentation->refresh();
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                try {
                    app(PrivateMediaStorage::class)->deleteStoredFiles(
                        array_values(array_unique($storedPaths)),
                        MediaReference::STORAGE_PROVIDER_CI_TEAM,
                    );
                } catch (Throwable) {
                    // Preserve the original save failure. These are only files created by this request.
                }
            }

            throw $exception;
        }
    }

    private function attachStagedMedia(SaveResidenceBusinessDocumentationRequest $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, array &$storedPaths): void
    {
        $mapScreenshot = $request->file('map_screenshot');
        $removeMapScreenshot = $request->boolean('remove_map_screenshot');
        if ($mapScreenshot !== null || $removeMapScreenshot) {
            app(SaveDocumentationMapScreenshot::class)->execute($request->user(), $documentation, [
                'map_screenshot' => $mapScreenshot,
                'remove_map_screenshot' => $removeMapScreenshot,
            ]);

            if ($mapScreenshot !== null) {
                $map = $documentation->mapScreenshot()->first();
                if ($map !== null) {
                    $storedPaths = array_merge($storedPaths, [$map->temporary_local_path, $map->thumbnail_path]);
                }
            }
        }

        foreach (['pictures', 'videos'] as $group) {
            $files = array_filter((array) $request->file($group, []));
            if ($files === []) {
                continue;
            }

            $media = app(UploadMedia::class)->execute($request->user(), $clientFolder, [
                'files' => $files,
                'co_maker_id' => $documentation->co_maker_id,
                'category' => $documentation->category,
                'residence_business_documentation_id' => $documentation->id,
                'documentation' => $documentation,
                'documentation_kind' => $group === 'videos' ? 'video' : 'picture',
            ]);

            foreach ($media as $item) {
                $storedPaths = array_merge($storedPaths, [$item->temporary_local_path, $item->thumbnail_path]);
            }
        }
    }

    public function uploadMapScreenshot(UploadDocumentationMapScreenshotRequest $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, SaveDocumentationMapScreenshot $save): JsonResponse|RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $this->assertDocumentationScope($clientFolder, $documentation, $activePerson);
        $save->execute($request->user(), $documentation, [
            'map_screenshot' => $request->file('map_screenshot'),
            'remove_map_screenshot' => $request->boolean('remove_map_screenshot'),
        ]);

        return $this->mutationResponse($request, $clientFolder, $documentation, $activePerson, 'Map screenshot updated.');
    }

    public function uploadMedia(UploadDocumentationMediaRequest $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, UploadMedia $upload): JsonResponse|RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $this->assertDocumentationScope($clientFolder, $documentation, $activePerson);
        $upload->execute($request->user(), $clientFolder, [
            'files' => $request->file('files'),
            'co_maker_id' => $documentation->co_maker_id,
            'category' => $documentation->category,
            'residence_business_documentation_id' => $documentation->id,
            'documentation' => $documentation,
            'documentation_kind' => $request->validated('kind'),
        ]);

        $label = $request->validated('kind') === 'video' ? 'Video(s) uploaded.' : 'Picture(s) uploaded.';

        return $this->mutationResponse($request, $clientFolder, $documentation, $activePerson, $label);
    }

    public function destroyMedia(Request $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, MediaReference $mediaReference, RemoveMedia $remove): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $mediaReference);
        abort_unless($mediaReference->residence_business_documentation_id === $documentation->id, 404);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->input('co_maker_id'));
        $this->assertDocumentationScope($clientFolder, $documentation, $activePerson);
        $remove->execute($request->user(), $clientFolder, $mediaReference);

        return $this->mutationResponse($request, $clientFolder, $documentation, $activePerson, 'Media removed.');
    }

    public function preview(ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, DocumentationCaptionBuilder $captionBuilder): View
    {
        Gate::authorize('view', $documentation);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $this->assertDocumentationScope($clientFolder, $documentation, $activePerson);
        $documentation->load(['mapScreenshot', 'pictures', 'videos', 'coMaker']);

        return view('client-folders.media.documentation-preview', [
            'clientFolder' => $clientFolder,
            'documentation' => $documentation,
            'caption' => $captionBuilder->buildForSender($documentation, request()->user()),
        ]);
    }

    public function sendToTelegram(SendDocumentationToTelegramRequest $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, DocumentationTelegramSender $sender): JsonResponse|RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $this->assertDocumentationScope($clientFolder, $documentation, $activePerson);

        try {
            $messageBody = $request->validated('telegram_message_body');
            $sender->send($documentation, $request->user(), is_string($messageBody) ? $messageBody : null);
        } catch (DocumentationTelegramSendException $exception) {
            return $this->backToDocumentation($clientFolder, $documentation, $activePerson, $exception->getMessage(), 'error')
                ->withInput([
                    'telegram_documentation_id' => $documentation->id,
                    'telegram_message_body' => $request->input('telegram_message_body'),
                ]);
        }

        return $this->mutationResponse($request, $clientFolder, $documentation, $activePerson, 'Documentation sent successfully to Telegram.');
    }

    public function previewAllBusinesses(ClientFolder $clientFolder, DocumentationCaptionBuilder $captionBuilder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $documentations = $this->businessDocumentationsFor($clientFolder, $activePerson);
        abort_if($documentations->isEmpty(), 404);

        return view('client-folders.media.business-documentation-batch-preview', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'documentations' => $documentations,
            'captions' => $documentations->mapWithKeys(function (ResidenceBusinessDocumentation $documentation) use ($captionBuilder): array {
                $messageBody = $documentation->latestTelegramDelivery?->message_body;

                return [$documentation->id => $captionBuilder->buildForSender(
                    $documentation,
                    request()->user(),
                    filled($messageBody) ? $messageBody : null,
                )];
            }),
        ]);
    }

    public function sendAllBusinesses(Request $request, ClientFolder $clientFolder, DocumentationTelegramSender $sender): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $documentations = $this->businessDocumentationsFor($clientFolder, $activePerson);
        abort_if($documentations->isEmpty(), 404);
        $documentations->each(fn (ResidenceBusinessDocumentation $documentation) => Gate::authorize('update', $documentation));

        try {
            $sender->sendAllBusinesses($documentations, $request->user());
        } catch (DocumentationTelegramSendException $exception) {
            return $this->backToDocumentation($clientFolder, $documentations->first(), $activePerson, $exception->getMessage(), 'error');
        }

        return $this->mutationResponse(
            $request,
            $clientFolder,
            $documentations->first(),
            $activePerson,
            'All '.$documentations->count().' saved businesses were sent successfully to Telegram.',
        );
    }

    private function backToDocumentation(ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, $activePerson, string $message, string $statusType = 'success'): RedirectResponse
    {
        $personParams = ActivePersonResolver::queryParams($activePerson);
        // Residence and Business each keep their own query string key, so a save/upload/remove in
        // one never disturbs which set the other holds. `tab` returns the encoder to the same
        // documentation tab they were working in.
        $tab = $documentation->isResidence() ? 'residence' : 'business';

        $documentationParams = [$tab.'_documentation' => $documentation->id, 'tab' => $tab];

        return redirect()->route('client-folders.media.index', [$clientFolder] + $personParams + $documentationParams)
            ->with('status', $message)
            ->with('statusType', $statusType);
    }

    private function saveResponse(SaveResidenceBusinessDocumentationRequest $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, $activePerson, string $message): JsonResponse|RedirectResponse
    {
        return $this->mutationResponse($request, $clientFolder, $documentation, $activePerson, $message);
    }

    private function mutationResponse(Request $request, ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, $activePerson, string $message): JsonResponse|RedirectResponse
    {
        $redirect = $this->backToDocumentation($clientFolder, $documentation, $activePerson, $message);
        if (! $request->expectsJson()) {
            return $redirect;
        }

        $category = $documentation->category;
        $activities = app(ClientFolderOverview::class)->documentationActivity(
            $clientFolder,
            $activePerson,
            $category,
            $documentation->isResidence() ? null : $documentation->id,
        );

        return response()->json([
            'result' => 'success',
            'message' => $message,
            'return_url' => $redirect->getTargetUrl(),
            'recent_activity_category' => $category,
            'recent_activity_html' => view('client-folders.media._recent-activity', compact('activities', 'category'))->render(),
            'recent_activity_modal_html' => view('client-folders.media._recent-activity-modal-body', compact('activities'))->render(),
        ]);
    }

    private function storageFailureResponse(SaveResidenceBusinessDocumentationRequest $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['result' => 'storage_failure', 'message' => $message], 500);
        }

        return redirect()->back()
            ->withInput()
            ->with('status', $message)
            ->with('statusType', 'error');
    }

    private function assertDocumentationScope(ClientFolder $clientFolder, ResidenceBusinessDocumentation $documentation, $activePerson): void
    {
        ActivePersonResolver::assertOwnedBy($documentation, $activePerson);
    }

    private function businessDocumentationsFor(ClientFolder $clientFolder, $activePerson)
    {
        return $clientFolder->residenceBusinessDocumentations()
            ->where('category', ResidenceBusinessDocumentation::CATEGORY_BUSINESS)
            ->where('co_maker_id', $activePerson?->id)
            ->with(['mapScreenshot', 'pictures', 'videos', 'latestTelegramDelivery', 'coMaker', 'clientFolder'])
            ->orderBy('id')
            ->get();
    }
}
