<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditingPresenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class EditingPresenceController extends Controller
{
    private const TTL_SECONDS = 90;

    public function heartbeat(EditingPresenceRequest $request): JsonResponse
    {
        $this->resolveAuthorized($request);
        $key = $this->cacheKey($request->string('type')->toString(), (int) $request->integer('id'));
        $user = $request->user();

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

    public function release(EditingPresenceRequest $request): JsonResponse
    {
        $key = $this->cacheKey($request->string('type')->toString(), (int) $request->integer('id'));
        $user = $request->user();

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
        Gate::authorize('update', $model);

        return $model;
    }

    private function cacheKey(string $type, int $id): string
    {
        return "editing:{$type}:{$id}";
    }
}
