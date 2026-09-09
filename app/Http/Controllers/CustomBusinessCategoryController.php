<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\ManageCustomBusinessCategory;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\SaveCustomBusinessCategoryRequest;
use App\Models\ClientFolder;
use App\Models\CustomBusinessCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Add / rename / remove the CI-created checkbox options offered by the "Other Business / Source of
 * Income" form. This controller only ever writes a CustomBusinessCategory definition — never an
 * IncomeSource, a BusinessReport or any stored selection.
 *
 * Answers JSON to the encoding form (which posts these without navigating away, so the CI never
 * loses unsaved checkbox state) and redirects back for a plain non-JS submit.
 */
class CustomBusinessCategoryController extends Controller
{
    public function store(
        SaveCustomBusinessCategoryRequest $request,
        ClientFolder $clientFolder,
        ManageCustomBusinessCategory $manage,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $clientFolder);

        $category = $manage->create($request->user(), $clientFolder, $request->validated('name'));

        return $this->respond($request, $category->name.' added to the business options.', $category);
    }

    public function update(
        SaveCustomBusinessCategoryRequest $request,
        ClientFolder $clientFolder,
        CustomBusinessCategory $customBusinessCategory,
        ManageCustomBusinessCategory $manage,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $clientFolder);

        try {
            $category = $manage->rename($request->user(), $clientFolder, $customBusinessCategory, $request->validated('name'));
        } catch (NoChangesDetectedException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage(), 'no_change' => true]);
            }

            return back()->with('status', $exception->getMessage())->with('statusType', 'info');
        }

        return $this->respond($request, $category->name.' business option updated.', $category);
    }

    public function destroy(
        Request $request,
        ClientFolder $clientFolder,
        CustomBusinessCategory $customBusinessCategory,
        ManageCustomBusinessCategory $manage,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('update', $clientFolder);

        $name = $customBusinessCategory->name;
        $optionKey = $customBusinessCategory->optionKey();
        $deleted = $manage->remove($request->user(), $clientFolder, $customBusinessCategory);

        $message = $deleted
            ? $name.' removed from the business options.'
            : $name.' is used by a saved Business Report, so it was removed from future selection and every existing report was left unchanged.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'deleted' => $deleted, 'optionKey' => $optionKey]);
        }

        return back()->with('status', $message);
    }

    private function respond(Request $request, string $message, CustomBusinessCategory $category): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'category' => [
                    'id' => $category->getKey(),
                    'name' => $category->name,
                    'optionKey' => $category->optionKey(),
                ],
            ]);
        }

        return back()->with('status', $message);
    }
}
