<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ResetOperationalData;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\Settings\EvidenceStorageSetting;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AdminSectionController extends Controller
{
    public function settings(EvidenceStorageSetting $evidenceStorage, ResetOperationalData $reset): View
    {
        Gate::authorize('viewAny', SystemSetting::class);

        return view('admin.settings.index', [
            'title' => 'Settings',
            // Read-only. Opening Settings or the confirmation modal never mutates anything.
            'operationalSummary' => $reset->summary(),
            'evidenceProvider' => $evidenceStorage->provider(),
            'evidenceSetting' => SystemSetting::query()->with('updater:id,full_name')->where('key', EvidenceStorageSetting::KEY)->first(),
        ]);
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
