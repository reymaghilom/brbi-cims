<?php

namespace App\Exceptions;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BusinessReportDeletedWhileEditingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Business Report was deleted by another user while you were working on it. Please return to the Business Report page.');
    }

    public function render(Request $request): RedirectResponse
    {
        /** @var ClientFolder $folder */
        $folder = $request->route('clientFolder');
        /** @var IncomeSource $source */
        $source = $request->route('incomeSource');
        $activePerson = $source->co_maker_id ? $folder->coMakers()->find($source->co_maker_id) : null;
        $personParams = ActivePersonResolver::queryParams($activePerson);

        return redirect()
            ->route('client-folders.income-sources.edit', [$folder, $source] + $personParams)
            ->with('business_report_deleted', [
                'message' => $this->getMessage(),
                'return_url' => route('client-folders.income-sources.manage', [$folder] + $personParams),
            ]);
    }
}
