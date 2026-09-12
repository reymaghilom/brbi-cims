<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ResetOperationalData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetOperationalDataRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResetOperationalDataController extends Controller
{
    public const SUCCESS_MESSAGE = 'Operational data has been reset successfully. User accounts and system master data were preserved.';

    public const FAILURE_MESSAGE = 'Unable to reset operational data. No changes were completed. Please try again or contact the system administrator.';

    /**
     * POST only, CSRF-protected, Administrator-gated by both the route group's role middleware and
     * the form request's policy check, and it redirects on the way out so a refresh can never
     * repeat the reset.
     */
    public function store(ResetOperationalDataRequest $request, ResetOperationalData $reset): RedirectResponse
    {
        try {
            $reset->execute($request->user());
        } catch (Throwable $exception) {
            // The action runs inside one transaction, so nothing was committed. The message can
            // honestly promise that.
            Log::error('Operational data reset failed.', [
                'user_id' => $request->user()->id,
                'exception' => $exception,
            ]);

            return redirect()
                ->route('admin.settings.index')
                ->with('error', self::FAILURE_MESSAGE);
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('status', self::SUCCESS_MESSAGE);
    }
}
