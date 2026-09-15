<?php

namespace App\Support\ClientFolders;

use App\Models\ClientFolder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One place that answers "the Client Folder this request belongs to no longer exists" — in
 * practice, another user permanently deleted it while this page was still open.
 *
 * Applies ONLY when the missing model is the Client Folder itself (route-model binding of
 * {clientFolder}), or when a request whose {clientFolder} did resolve fails with a not-found or
 * database error AND that folder is now gone (a delete that landed mid-request, e.g. a foreign-key
 * failure or a locked child lookup). Every other 404 or error keeps Laravel's normal handling, and
 * guests are untouched (authentication runs before route-model binding).
 *
 * Nothing is ever written: the request never reaches its action, or its transaction has already
 * rolled back.
 *  - Page request (GET/HEAD): redirect to Client Folders with a friendly notice.
 *  - Save (any other method): redirect to Client Folders saying the changes were not saved.
 *  - JSON/fetch: 404 with {message, folder_missing: true}, never the exception text.
 */
class MissingClientFolderResponse
{
    public const VIEW_MESSAGE = 'This Client Folder is no longer available. It may have been permanently deleted by another user.';

    public const JSON_VIEW_MESSAGE = 'This Client Folder is no longer available.';

    public const SAVE_MESSAGE = 'Unable to save changes. This Client Folder has already been permanently deleted by another user.';

    public static function for(Throwable $exception, Request $request): ?Response
    {
        if ($request->user() === null || ! self::folderIsMissing($exception, $request)) {
            return null;
        }

        // Deleting a folder that is already gone is not a lost save: it gets the "no longer available" wording.
        $isRead = $request->isMethod('GET') || $request->isMethod('HEAD') || $request->route()?->getName() === 'client-folders.destroy';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $isRead ? self::JSON_VIEW_MESSAGE : self::SAVE_MESSAGE,
                'folder_missing' => true,
                'result' => 'not_available',
                'status_type' => 'error',
            ], 404);
        }

        // client_folder_missing lets a page loaded inside a report dialog's iframe hand the notice to
        // its parent page instead of rendering the folder list inside the dialog (see app.js).
        return redirect()->route('client-folders.index')
            ->with('status', $isRead ? self::VIEW_MESSAGE : self::SAVE_MESSAGE)
            ->with('statusType', 'error')
            ->with('client_folder_missing', true);
    }

    private static function folderIsMissing(Throwable $exception, Request $request): bool
    {
        $missing = $exception instanceof NotFoundHttpException ? $exception->getPrevious() : null;
        if ($missing instanceof ModelNotFoundException && $missing->getModel() === ClientFolder::class) {
            return true;
        }

        if (! $missing instanceof ModelNotFoundException && ! $exception instanceof QueryException) {
            return false;
        }

        $folder = $request->route()?->parameter('clientFolder');

        return $folder instanceof ClientFolder && ! ClientFolder::query()->whereKey($folder->getKey())->exists();
    }
}
