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
    public function launch(ClientFolder $clientFolder): RedirectResponse
    {
        Gate::authorize('view', $clientFolder);
        $source = $this->dedicatedSource($clientFolder);

        if ($source) {
            return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $source]);
        }

        return redirect()->route('client-folders.income-sources.create', $clientFolder);
    }

    public function index(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $sources = $clientFolder->incomeSources()->with('template:id,name,business_category,is_fallback')->withCount(['mediaReferences', 'generatedReports'])->orderBy('sort_order')->orderBy('id')->get();

        return view('client-folders.income-sources.index', compact('clientFolder', 'sources'));
    }

    public function create(ClientFolder $clientFolder, CreateIncomeSource $create): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);
        $source = $this->dedicatedSource($clientFolder);
        if ($source === null) {
            $template = IncomeSourceTemplate::query()
                ->where('is_active', true)
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->firstOrFail();
            $source = $create->execute(request()->user(), $clientFolder, [
                'income_source_template_id' => $template->id,
                'source_name' => '',
                'business_name' => null,
            ]);
        }

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $source]);
    }

    public function selectTemplate(ClientFolder $clientFolder): View
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);
        $templates = IncomeSourceTemplate::query()->where('is_active', true)->orderBy('is_fallback')->orderBy('sort_order')->orderBy('name')->get();

        return view('client-folders.income-sources.create', compact('clientFolder', 'templates'));
    }

    public function store(StoreIncomeSourceRequest $request, ClientFolder $clientFolder, CreateIncomeSource $create): RedirectResponse
    {
        $source = $create->execute($request->user(), $clientFolder, $request->validated());

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $source])->with('status', 'Income source created. Complete its selected report form below.');
    }

    public function show(ClientFolder $clientFolder, IncomeSource $incomeSource): RedirectResponse
    {
        Gate::authorize('view', $incomeSource);

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $incomeSource]);
    }

    public function edit(ClientFolder $clientFolder, IncomeSource $incomeSource): View
    {
        Gate::authorize('update', $incomeSource);
        $incomeSource->load('template');
        if ($incomeSource->template->is_fallback) {
            $incomeSource->load('generalReport.declaredItems');

            return view('client-folders.income-sources.general-edit', compact('clientFolder', 'incomeSource'));
        }

        $incomeSource->load(['businessReport.properties.tenants', 'businessReport.branches', 'businessReport.products', 'businessReport.suppliers', 'businessReport.observations', 'businessReport.competitors']);
        $businesses = $this->dedicatedSources($clientFolder);

        return view('client-folders.income-sources.business-edit', compact('clientFolder', 'incomeSource', 'businesses'));
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

        return redirect()->route('client-folders.income-sources.manage', $clientFolder)->with('status', 'Income source removed from the active folder.');
    }

    private function afterSave(string $intent, ClientFolder $folder, IncomeSource $source): RedirectResponse
    {
        $route = $intent === 'return' ? route('client-folders.income-sources.manage', $folder) : route('client-folders.income-sources.edit', [$folder, $source]);

        return redirect($route)->with('status', 'Income source report saved successfully.');
    }

    private function dedicatedSource(ClientFolder $folder): ?IncomeSource
    {
        return $this->dedicatedSources($folder)->first();
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
}
