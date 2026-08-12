<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminSectionController extends Controller
{
    public function settings(): View
    {
        Gate::authorize('viewAny', SystemSetting::class);

        return view('admin.section-placeholder', ['title' => 'Settings']);
    }

    public function auditLogs(): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        return view('admin.section-placeholder', ['title' => 'Audit Trail']);
    }

    public function uiFoundation(): View
    {
        Gate::authorize('viewAny', SystemSetting::class);

        return view('ui.foundation-preview');
    }
}
