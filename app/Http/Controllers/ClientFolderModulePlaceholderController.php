<?php

namespace App\Http\Controllers;

use App\Models\ClientFolder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientFolderModulePlaceholderController extends Controller
{
    private const MODULES = [
        'client-information' => ['Client Information', 10],
        'activities' => ['CI Activities', 11],
        'cibi-report' => ['CI / BI Report', 12],
        'generated-reports' => ['Generated Reports', 15],
        'media' => ['Photos & Videos', 16],
        'google-drive' => ['Google Drive', 17],
        'telegram-history' => ['Telegram History', 18],
        'attachments' => ['Attachments / Documents', null],
    ];

    public function __invoke(ClientFolder $clientFolder, string $module): View
    {
        Gate::authorize('view', $clientFolder);
        abort_unless(isset(self::MODULES[$module]), 404);

        [$title, $phase] = self::MODULES[$module];

        return view('client-folders.module-placeholder', compact('clientFolder', 'title', 'phase'));
    }
}
