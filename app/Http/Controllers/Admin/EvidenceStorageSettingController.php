<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\Settings\EvidenceStorageSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EvidenceStorageSettingController extends Controller
{
    public function __construct(private readonly EvidenceStorageSetting $setting) {}

    public function update(Request $request): RedirectResponse
    {
        // Server-side administrator authorization, independent of the admin route group and of the
        // UI: a hand-crafted request from an ordinary user can never reach the write below.
        Gate::authorize('create', SystemSetting::class);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(EvidenceStorageSetting::PROVIDERS)],
        ]);

        $previous = $this->setting->update($request->user(), $validated['provider']);
        $label = EvidenceStorageSetting::label($validated['provider']);

        return redirect()->route('admin.settings.index')->with(
            'status',
            $previous === null
                ? "File Storage is already set to {$label}."
                : 'File Storage changed from '.EvidenceStorageSetting::label($previous)." to {$label}. Existing files stay where they were saved.",
        );
    }
}
