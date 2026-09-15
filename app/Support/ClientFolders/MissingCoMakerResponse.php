<?php

namespace App\Support\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Answers "the Co-Maker this request acts on no longer exists" — in practice, another user deleted
 * that Co-Maker while this page was still open — for a Client Folder that DOES still exist (a
 * missing folder is MissingClientFolderResponse's case and is checked first).
 *
 * The target Co-Maker is read exactly as the request names it: the {coMaker} route parameter, or
 * the co_maker_id the page/form carries. It applies only when that exact Co-Maker is not found
 * under this folder, no longer exists anywhere, AND the request already failed (not found,
 * validation — e.g. the co_maker_id exists rule — or a database error). An id that still belongs
 * to another folder receives an ordinary non-disclosing 404. A
 * stale form therefore never falls back to the Applicant or another Co-Maker: it fails, and this
 * only replaces the wording for a genuinely deleted person. Nothing is written.
 *
 *  - Page request (GET/HEAD), or deleting that Co-Maker again: back to the Client Folder overview
 *    with "already been deleted by another user".
 *  - Save: back to the Client Folder overview with "Your changes were not saved…".
 *  - JSON/fetch: 404 with {message, co_maker_missing: true}.
 */
class MissingCoMakerResponse
{
    public const VIEW_MESSAGE = 'This Co-Maker has already been deleted by another user.';

    public const SAVE_MESSAGE = 'Your changes were not saved because this Co-Maker has already been deleted.';

    public static function for(Throwable $exception, Request $request): ?Response
    {
        if ($request->user() === null
            || ! ($exception instanceof NotFoundHttpException || $exception instanceof ValidationException || $exception instanceof QueryException)) {
            return null;
        }

        $folder = $request->route()?->parameter('clientFolder');
        if (! $folder instanceof ClientFolder || ! ClientFolder::query()->whereKey($folder->getKey())->exists()) {
            return null;
        }

        $routeCoMaker = $request->route()?->parameter('coMaker');
        $coMakerId = $routeCoMaker instanceof CoMaker ? $routeCoMaker->getKey() : ($routeCoMaker ?? $request->input('co_maker_id'));
        if (blank($coMakerId) || ! is_numeric($coMakerId)
            || CoMaker::query()->whereKey((int) $coMakerId)->where('client_folder_id', $folder->getKey())->exists()) {
            return null;
        }

        // A real Co-Maker owned by another folder is a forged/mismatched nested identifier, not a
        // stale deleted-person request. A NotFound exception can follow Laravel's native renderer;
        // validation/query failures need conversion here or they would leak as 422/500 instead.
        if (CoMaker::query()->whereKey((int) $coMakerId)->exists()) {
            if ($exception instanceof NotFoundHttpException) {
                return null;
            }

            return $request->expectsJson()
                ? response()->json(['message' => Response::$statusTexts[404]], 404)
                : response(Response::$statusTexts[404], 404);
        }

        $isRead = $request->isMethod('GET') || $request->isMethod('HEAD') || $request->route()?->getName() === 'client-folders.co-maker.destroy';
        $message = $isRead ? self::VIEW_MESSAGE : self::SAVE_MESSAGE;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'co_maker_missing' => true,
                'result' => 'not_available',
                'status_type' => 'error',
            ], 404);
        }

        $overview = route('client-folders.show', $folder);

        return redirect()->to($overview)
            ->with('status', $message)
            ->with('statusType', 'error')
            ->with('co_maker_missing', true)
            ->with('stale_return_url', $overview);
    }
}
