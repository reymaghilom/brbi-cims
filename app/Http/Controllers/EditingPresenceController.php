<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditingPresenceRequest;
use App\Models\CoMaker;
use App\Services\ClientFolders\ClientFolderEditingPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class EditingPresenceController extends Controller
{
    private const TTL_SECONDS = 90;

    public function heartbeat(EditingPresenceRequest $request, ClientFolderEditingPresence $folderPresence): JsonResponse
    {
        $model = $this->resolveAuthorized($request);
        $key = $this->cacheKey($request->string('type')->toString(), (int) $request->integer('id'));
        $user = $request->user();

        // Folder-scoped copy of this heartbeat plus its dirty/saving state, used only by the
        // permanent Client Folder delete guard. The per-record banner below is unchanged.
        $folderPresence->record(
            (int) $model->getAttribute('client_folder_id'),
            $request->string('type')->toString(),
            (int) $model->getKey(),
            $user,
            $request->string('state', ClientFolderEditingPresence::STATE_VIEWING)->toString(),
            // Person scope of the work: the Co-Maker itself when editing a Co-Maker record.
            $model instanceof CoMaker ? (int) $model->getKey() : ($model->getAttribute('co_maker_id') !== null ? (int) $model->getAttribute('co_maker_id') : null),
        );

        $editors = $this->activeEditors($key);
        $editors[$user->id] = [
            'user_id' => $user->id,
            'name' => $user->full_name,
            'since' => $editors[$user->id]['since'] ?? now()->toJSON(),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->toJSON(),
        ];

        Cache::put($key, $editors, self::TTL_SECONDS);

        $others = array_values(array_filter($editors, fn (array $editor) => $editor['user_id'] !== $user->id));

        return response()->json([
            'other_editors' => array_map(fn (array $editor) => ['name' => $editor['name'], 'since' => $editor['since']], $others),
        ]);
    }

    public function release(EditingPresenceRequest $request, ClientFolderEditingPresence $folderPresence): JsonResponse
    {
        $key = $this->cacheKey($request->string('type')->toString(), (int) $request->integer('id'));
        $user = $request->user();

        // Only ever removes the requesting user's own entry; a vanished record simply expires.
        $modelClass = config('cims.editing_presence_types')[$request->string('type')->toString()];
        $clientFolderId = $modelClass::query()->whereKey($request->integer('id'))->value('client_folder_id');
        if ($clientFolderId !== null) {
            $folderPresence->forget((int) $clientFolderId, $request->string('type')->toString(), (int) $request->integer('id'), $user->id);
        }

        $editors = $this->activeEditors($key);
        unset($editors[$user->id]);

        if ($editors === []) {
            Cache::forget($key);
        } else {
            Cache::put($key, $editors, self::TTL_SECONDS);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int, array{user_id: int, name: string, since: string, expires_at: string}>
     */
    private function activeEditors(string $key): array
    {
        $editors = Cache::get($key, []);
        $now = now();

        return array_filter($editors, fn (array $editor) => $now->lt($editor['expires_at']));
    }

    private function resolveAuthorized(EditingPresenceRequest $request): object
    {
        $modelClass = config('cims.editing_presence_types')[$request->string('type')->toString()];
        $model = $modelClass::query()->findOrFail($request->integer('id'));
        // A Co-Maker has no policy of its own: editing it is an update of its Client Folder.
        Gate::authorize('update', $model instanceof CoMaker ? $model->clientFolder : $model);

        return $model;
    }

    private function cacheKey(string $type, int $id): string
    {
        return "editing:{$type}:{$id}";
    }
}
