<?php

use App\Http\Middleware\EnsureCurrentAuthenticationSession;
use App\Http\Middleware\EnsurePasswordHasBeenChanged;
use App\Http\Middleware\EnsureUserHasRole;
use App\Models\ActivityDefinition;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\CustomBusinessCategory;
use App\Models\IncomeSource;
use App\Support\ClientFolders\MissingClientFolderResponse;
use App\Support\ClientFolders\MissingCoMakerResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.session.current' => EnsureCurrentAuthenticationSession::class,
            'password.changed' => EnsurePasswordHasBeenChanged::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * Registered first so it wins over the model-specific handlers below: when the Client
         * Folder a request belongs to has been permanently deleted, the user is told that — not
         * that one of its child records is missing, and never the exception text. Every other
         * case returns null and falls through unchanged. See MissingClientFolderResponse.
         */
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => MissingClientFolderResponse::for($e, $request));
        $exceptions->render(fn (QueryException $e, Request $request) => MissingClientFolderResponse::for($e, $request));

        /**
         * Next: the folder still exists but the exact Co-Maker the request acts on was removed by
         * another user. Only replaces the wording of a request that already failed — a stale form
         * never falls back to the Applicant or another Co-Maker. See MissingCoMakerResponse.
         */
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => MissingCoMakerResponse::for($e, $request));
        $exceptions->render(fn (ValidationException $e, Request $request) => MissingCoMakerResponse::for($e, $request));
        $exceptions->render(fn (QueryException $e, Request $request) => MissingCoMakerResponse::for($e, $request));

        /**
         * A CI Activity that no longer exists is, in practice, always the same story: another user
         * deleted it while this page was still open. Route-model binding (and the authoritative
         * locked lookups in UpdateCiActivity/DeleteCiActivity) raise ModelNotFoundException for it,
         * whose message embeds the model class and id — "No query results for model
         * [App\Models\CiActivity] 155" — which reached the CI directly, because the quick-complete
         * fetch handlers surface `payload.message`.
         *
         * This translates ONLY that case. Every other missing model returns null here and keeps
         * Laravel's own 404 handling untouched. Nothing about the request changes: the row stays
         * deleted, nothing is recreated, no audit or progress side effect occurs — the status stays
         * 404, only the wording the user reads is replaced.
         *
         * Deliberately distinct from the 409 revision conflict, which means the record still exists
         * and should be reviewed and re-saved.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            // Laravel converts a binding/findOrFail ModelNotFoundException into a
            // NotFoundHttpException before render callbacks run, so the original is read from the
            // previous exception — that is also what carries which model was missing.
            $missing = $e->getPrevious();
            if (! $missing instanceof ModelNotFoundException || $missing->getModel() !== CiActivity::class) {
                return null;
            }

            // A normal page request gets its own, deliberately GENERIC wording. The JSON branch
            // below keeps saying "deleted by another user" because it is only ever reached by the
            // quick-complete and edit handlers acting on a row the CI was already looking at; a
            // plain page request is equally the answer for a forged id and for an activity under
            // another folder or person, so that page must not assert a deletion, must not say
            // whether the record ever existed, and must stay a 404 rather than redirect a forged
            // id onto a page that looks valid. Nothing is recreated and no request becomes a
            // success — only the wording changes.
            if (! $request->expectsJson()) {
                return response()->view('client-folders.activities.ci-activity-unavailable', [
                    'message' => 'This CI Activity is no longer available. It may have been deleted or changed by another user. Please return to the CI Activities page.',
                ], 404);
            }

            return response()->json([
                'result' => 'deleted',
                'message' => 'This CI Activity was deleted by another user while you were working on it. Please return to the CI Activities page.',
                'status_type' => 'error',
            ], 404);
        });

        /**
         * Same shape, for a Bank / Coop target that cannot be resolved. Route-model binding — and
         * the authoritative locked lookups inside SaveCiActivityBankTarget — raise
         * ModelNotFoundException, whose message embeds the model class and id ("No query results
         * for model [App\Models\CiActivityBankTarget] 72"); with debug rendering on, that text
         * reached the CI directly.
         *
         * The wording here is deliberately GENERIC. A missing target is not always a deletion: it
         * is the same outcome for a stale page, a target under another parent activity, an
         * Applicant/Co-Maker mismatch, a cross-folder substitution and a forged id. Claiming
         * "deleted by another user" would mislead in those cases and blunt the 404 the scoped
         * protections depend on, so every one of them keeps the identical answer and the identical
         * 404 status. Nothing is recreated, nothing is redirected into looking valid, and no audit,
         * reminder, parent synchronization, progress recalculation or revision change occurs — only
         * the wording changes.
         *
         * Distinct from the 409 conflict, which means the target still exists and is merely stale.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();
            if (! $missing instanceof ModelNotFoundException || $missing->getModel() !== CiActivityBankTarget::class) {
                return null;
            }

            $message = 'This Bank / Coop target is no longer available. It may have been deleted or changed by another user. Please return to the Bank / Coop Check page.';

            if ($request->expectsJson()) {
                return response()->json([
                    'result' => 'not_available',
                    'message' => $message,
                    'status_type' => 'error',
                ], 404);
            }

            // Kept as a 404 page rather than a redirect to the tracker: redirecting would resolve a
            // forged or cross-scope id into a page that looks valid. The CI reads the friendly
            // message instead of the exception text, and the not-found semantics are unchanged.
            return response()->view('client-folders.activities.bank-target-unavailable', ['message' => $message], 404);
        });

        /**
         * Same shape again, for an Asset Check target that cannot be resolved. The embedded Asset
         * tracker modal submits with Accept: application/json and renders payload.message straight
         * into its alert, so Laravel's default body — "No query results for model
         * [App\Models\CiActivityAssetTarget] 72" — reached the CI verbatim.
         *
         * The wording is deliberately GENERIC for the same reason as the Bank / Coop case: a
         * missing target is equally the answer for a deleted row, a stale page, a target under
         * another parent activity, an Applicant/Co-Maker mismatch, a cross-folder substitution and
         * a forged id. All of them keep the identical answer and the identical 404 status, so the
         * message can never confirm that a probe found something real. Nothing is recreated and no
         * request becomes a success — only the wording changes.
         */
        /**
         * An Activity Type that cannot be resolved. Route-model binding raises
         * ModelNotFoundException, whose message embeds the model class and id — "No query results
         * for model [App\Models\ActivityDefinition] 837" — and the Activity Type manager renders
         * `payload.message` straight into its error slot, so that text reached the CI verbatim.
         *
         * The usual cause is an ordinary stale page: a custom type that no CI Activity referenced
         * was permanently deleted (from the manager, or by removing it in the Add Activity type
         * list) while another tab still listed it. Nothing is wrong with the deletion, so the fix
         * is to fail gracefully and tell the CI to refresh — never to resurrect the type.
         *
         * Wording is deliberately GENERIC: the same outcome also covers a forged id and a built-in
         * type, which lockCustomDefinition() 404s on purpose. It must not confirm whether the
         * record existed, and the status stays 404.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();
            if (! $missing instanceof ModelNotFoundException || $missing->getModel() !== ActivityDefinition::class) {
                return null;
            }

            $message = 'This Activity Type is no longer available. It may have been deleted or changed by another user. Please refresh the page and try again.';

            if ($request->expectsJson()) {
                return response()->json([
                    'result' => 'not_available',
                    'message' => $message,
                    'status_type' => 'error',
                ], 404);
            }

            return response()->view('client-folders.activities.activity-type-unavailable', ['message' => $message], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();
            if (! $missing instanceof ModelNotFoundException || $missing->getModel() !== CustomBusinessCategory::class) {
                return null;
            }

            $message = 'This Custom Business Category is no longer available. It may have been deleted or changed by another user. Please refresh the page and try again.';

            if ($request->expectsJson()) {
                return response()->json([
                    'result' => 'not_available',
                    'message' => $message,
                    'status_type' => 'error',
                ], 404);
            }

            return response()->view('client-folders.income-sources.custom-business-category-unavailable', ['message' => $message], 404);
        });

        /**
         * A Business Report / business that another CI already deleted, reached from a stale
         * Business Report edit form or delete confirmation. Route-model binding (and the locked
         * lookups in DeleteBusinessReport / DeleteIncomeSource) raise ModelNotFoundException, whose
         * text embeds the model class and id, and the delete dialog's fetch handler toasts
         * `payload.message` verbatim.
         *
         * Scoped to exactly the Business Report update, the two delete routes and the four
         * Business Report edit/preview/export links listed below, and to the
         * IncomeSource / BusinessReport models only — every other missing model and route keeps
         * Laravel's own 404 handling. Still a 404; nothing is recreated or redirected into looking
         * valid; only the wording changes.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();
            $routeName = $request->route()?->getName();
            // Read-only Business Report links the Reports workspace (and other already-loaded pages)
            // hand out, which naturally go stale once another CI deletes the business.
            $staleReportLinks = ['client-folders.income-sources.edit', 'client-folders.generated-reports.preview', 'client-folders.income-sources.export-pdf', 'client-folders.income-sources.export-excel'];
            if (! $missing instanceof ModelNotFoundException
                || ! in_array($missing->getModel(), [IncomeSource::class, BusinessReport::class], true)
                || ! in_array($routeName, ['client-folders.income-sources.business.update', 'client-folders.income-sources.business-report.destroy', 'client-folders.income-sources.destroy', ...$staleReportLinks], true)) {
                return null;
            }

            [$title, $message] = match (true) {
                in_array($routeName, $staleReportLinks, true) => ['Report unavailable', 'This report is no longer available. It may have been deleted or changed by another CI. Please refresh the Reports page and try again.'],
                $routeName === 'client-folders.income-sources.business.update' => ['Business Report unavailable', 'This Business Report is no longer available. It may have been deleted by another CI while you were editing it. Please return to the Business Reports page and review the latest information.'],
                $missing->getModel() === BusinessReport::class => ['Business Report unavailable', 'This Business Report is no longer available. It may have already been deleted by another CI. Please refresh the Business Reports page.'],
                default => ['Business unavailable', 'This business is no longer available. It may have already been deleted by another CI. Please refresh the page.'],
            };

            if ($request->expectsJson()) {
                return response()->json([
                    'result' => 'not_available',
                    'message' => $message,
                    'status_type' => 'error',
                ], 404);
            }

            return response()->view('client-folders.income-sources.business-report-unavailable', ['title' => $title, 'message' => $message], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $missing = $e->getPrevious();
            if (! $missing instanceof ModelNotFoundException || $missing->getModel() !== CiActivityAssetTarget::class) {
                return null;
            }

            $message = 'This Asset Check target is no longer available. It may have been deleted or changed by another user. Please return to the Asset Check page.';

            if ($request->expectsJson()) {
                return response()->json([
                    'result' => 'not_available',
                    'message' => $message,
                    'status_type' => 'error',
                ], 404);
            }

            // Kept as a 404 page rather than a redirect to the tracker: redirecting would resolve a
            // forged or cross-scope id into a page that looks valid. The CI reads the friendly
            // message instead of the exception text, and the not-found semantics are unchanged.
            return response()->view('client-folders.activities.asset-target-unavailable', ['message' => $message], 404);
        });
    })->create();
