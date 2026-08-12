<?php

namespace App\Http\Controllers;

use App\Models\ClientFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientFolderSuggestionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ClientFolder::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
        ]);
        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = ClientFolder::query()
            ->accessibleTo($request->user())
            ->where('display_name', 'like', "%{$query}%")
            ->select('display_name')
            ->distinct()
            ->orderBy('display_name')
            ->limit(8)
            ->pluck('display_name')
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }
}
