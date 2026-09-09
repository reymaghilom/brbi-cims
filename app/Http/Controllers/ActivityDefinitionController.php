<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\ManageActivityDefinition;
use App\Http\Requests\ClientFolders\UpdateActivityDefinitionRequest;
use App\Models\ActivityDefinition;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiActivityHistoryFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Activity Type Management, opened from the CI Activities page. This controller only ever edits
 * the reusable ActivityDefinition — never a CiActivity instance, whose person scope, status,
 * schedule and history are left exactly as they are by every action here.
 */
class ActivityDefinitionController extends Controller
{
    public function update(
        UpdateActivityDefinitionRequest $request,
        ClientFolder $clientFolder,
        ActivityDefinition $activityDefinition,
        ManageActivityDefinition $manage,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $clientFolder);

        $watermark = CiActivityHistoryFeed::watermark();
        $definition = $manage->rename($request->user(), $clientFolder, $activityDefinition, $request->validated('name'));

        return $this->respond($request, $clientFolder, $definition->name.' activity type updated.', $watermark);
    }

    public function activation(
        Request $request,
        ClientFolder $clientFolder,
        ActivityDefinition $activityDefinition,
        ManageActivityDefinition $manage,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $clientFolder);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $validated['is_active'];

        $watermark = CiActivityHistoryFeed::watermark();
        $definition = $manage->setActivation($request->user(), $clientFolder, $activityDefinition, $active);

        return $this->respond(
            $request,
            $clientFolder,
            $definition->name.($active ? ' activity type activated.' : ' activity type deactivated.'),
            $watermark,
        );
    }

    public function destroy(
        Request $request,
        ClientFolder $clientFolder,
        ActivityDefinition $activityDefinition,
        ManageActivityDefinition $manage,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $clientFolder);
        $name = $activityDefinition->name;

        $watermark = CiActivityHistoryFeed::watermark();
        $manage->deletePermanently($request->user(), $clientFolder, $activityDefinition);

        return $this->respond($request, $clientFolder, $name.' activity type permanently deleted.', $watermark);
    }

    private function respond(Request $request, ClientFolder $clientFolder, string $message, int $watermark): JsonResponse|RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->input('co_maker_id'));
        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson),
        );

        if ($request->expectsJson()) {
            // Same authoritative payload shape every CI Activity mutation already returns: the
            // history entries this exact mutation produced, rendered by the shared partial.
            return response()->json([
                'message' => $message,
                'redirect' => $destination,
                'history' => CiActivityHistoryFeed::renderHtml(
                    CiActivityHistoryFeed::since($clientFolder, $watermark, $activePerson?->id),
                ),
            ]);
        }

        return redirect($destination)->with('status', $message);
    }
}
