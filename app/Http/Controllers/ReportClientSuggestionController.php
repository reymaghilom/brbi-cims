<?php

namespace App\Http\Controllers;

use App\Models\ClientFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Client-name suggestions for the Reports workspace search box.
 *
 * Deliberately mirrors ClientFolderSuggestionController — same authorization gate, same
 * ClientFolder::accessibleTo() scope (which excludes recycled folders through the model's own
 * soft-delete scope), same two-character minimum and same bounded limit — with one difference it
 * cannot share: it returns each folder's own id alongside its name instead of a distinct list of
 * names. Reports needs that because two accessible folders may legitimately carry the same display
 * name, and selecting one must filter to that exact Client Folder rather than to everyone who
 * happens to share the name. The Client Folders endpoint keeps its existing name-only contract.
 */
class ReportClientSuggestionController extends Controller
{
    private const MIN_QUERY_LENGTH = 2;

    private const LIMIT = 8;

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ClientFolder::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
        ]);
        $query = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return response()->json(['suggestions' => []]);
        }

        // One bounded, indexed lookup against the folders themselves — never a scan over report
        // work items just to collect the names attached to them.
        $suggestions = ClientFolder::query()
            ->accessibleTo($request->user())
            ->where('display_name', 'like', '%'.$query.'%')
            ->orderBy('display_name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'display_name'])
            ->map(fn (ClientFolder $folder): array => ['id' => $folder->id, 'name' => $folder->display_name])
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }
}
