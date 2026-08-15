<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Actions\ClientFolders\SaveGeneralIncomeSource;
use App\Http\Requests\ClientFolders\StoreIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateBusinessIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateGeneralIncomeSourceRequest;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IncomeSourceController extends Controller
{
    public function launch(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);

        return $this->businessPage($clientFolder, null);
    }

    public function index(ClientFolder $clientFolder): RedirectResponse
    {
        Gate::authorize('view', $clientFolder);

        return redirect()->route('client-folders.income-sources.index', $clientFolder);
    }

    public function create(ClientFolder $clientFolder): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);

        return redirect()->route('client-folders.income-sources.index', $clientFolder);
    }

    public function selectTemplate(ClientFolder $clientFolder): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);

        return redirect()->route('client-folders.income-sources.index', $clientFolder);
    }

    public function store(StoreIncomeSourceRequest $request, ClientFolder $clientFolder, CreateIncomeSource $create, SaveBusinessIncomeSource $save): RedirectResponse
    {
        $data = $request->validated();
        $source = $create->execute($request->user(), $clientFolder, $data);
        $save->execute($request->user(), $clientFolder, $source, $data);

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $source])->with('status', 'A new blank Business Report is ready for encoding.');
    }

    public function show(ClientFolder $clientFolder, IncomeSource $incomeSource): RedirectResponse
    {
        Gate::authorize('view', $incomeSource);

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $incomeSource]);
    }

    public function edit(ClientFolder $clientFolder, IncomeSource $incomeSource): View|RedirectResponse
    {
        Gate::authorize('update', $incomeSource);
        $incomeSource->load('template');
        if ($incomeSource->template->is_fallback) {
            return redirect()->route('client-folders.income-sources.index', $clientFolder);
        }

        return $this->businessPage($clientFolder, $incomeSource);
    }

    public function addBusiness(ClientFolder $clientFolder, IncomeSource $incomeSource, CreateIncomeSource $create): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);
        Gate::authorize('view', $incomeSource);
        $incomeSource->load('template');
        abort_if($incomeSource->template->is_fallback || $incomeSource->template->form_handler !== 'dedicated-business', 404);

        $business = $create->execute(request()->user(), $clientFolder, [
            'income_source_template_id' => $incomeSource->income_source_template_id,
            'source_name' => '',
            'business_name' => null,
        ]);

        return redirect()
            ->route('client-folders.income-sources.edit', [$clientFolder, $business])
            ->with('status', 'A new blank Business Report is ready for encoding.');
    }

    public function updateGeneral(UpdateGeneralIncomeSourceRequest $request, ClientFolder $clientFolder, IncomeSource $incomeSource, SaveGeneralIncomeSource $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $incomeSource, $request->validated());

        return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource);
    }

    public function updateBusiness(UpdateBusinessIncomeSourceRequest $request, ClientFolder $clientFolder, IncomeSource $incomeSource, SaveBusinessIncomeSource $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $incomeSource, $request->validated());

        return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource);
    }

    public function destroy(ClientFolder $clientFolder, IncomeSource $incomeSource, DeleteIncomeSource $delete): RedirectResponse
    {
        Gate::authorize('delete', $incomeSource);
        $delete->execute(request()->user(), $clientFolder, $incomeSource);

        return redirect()->route('client-folders.income-sources.index', $clientFolder)->with('status', 'Income source removed from the active folder.');
    }

    private function afterSave(string $intent, ClientFolder $folder, IncomeSource $source): RedirectResponse
    {
        $route = $intent === 'return' ? route('client-folders.income-sources.index', $folder) : route('client-folders.income-sources.edit', [$folder, $source]);

        return redirect($route)->with('status', 'Income source report saved successfully.');
    }

    private function dedicatedSources(ClientFolder $folder)
    {
        return $folder->incomeSources()
            ->with(['template', 'businessReport'])
            ->whereHas('template', fn ($query) => $query
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business'))
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function businessPage(ClientFolder $clientFolder, ?IncomeSource $incomeSource): View
    {
        $clientFolder->loadMissing([
            'assignedInvestigator:id,full_name',
            'cibiReport:id,client_folder_id,party_type,branch_name,account_officer_name,amount_applied',
        ]);
        if ($incomeSource) {
            $incomeSource->load([
                'template', 'businessReport.properties.tenants', 'businessReport.branches', 'businessReport.products',
                'businessReport.suppliers', 'businessReport.observations', 'businessReport.competitors',
            ]);
        }
        $businesses = $this->dedicatedSources($clientFolder);
        $legacyPlaceholders = $businesses->filter(fn (IncomeSource $business): bool => $this->isBlankLegacyPlaceholder($business));
        $suppressLegacyBusinessUi = $legacyPlaceholders->count() > 1;
        if ($suppressLegacyBusinessUi) {
            $legacyIds = $legacyPlaceholders->modelKeys();
            $businesses = $businesses->reject(fn (IncomeSource $business): bool => in_array($business->getKey(), $legacyIds, true))->values();
            if ($incomeSource && in_array($incomeSource->getKey(), $legacyIds, true)) {
                $incomeSource = null;
            }
        }
        $businessTemplates = IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'business_category', 'template_type', 'version', 'compatibility_tags']);

        return view('client-folders.income-sources.business-edit', compact('clientFolder', 'incomeSource', 'businesses', 'businessTemplates', 'suppressLegacyBusinessUi'));
    }

    private function isBlankLegacyPlaceholder(IncomeSource $source): bool
    {
        return $source->revision === 1
            && blank($source->source_name)
            && blank($source->business_name)
            && blank($source->businessReport?->business_name);
    }
}
