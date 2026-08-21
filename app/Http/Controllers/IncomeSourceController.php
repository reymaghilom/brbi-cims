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
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IncomeSourceController extends Controller
{
    public function launch(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());

        return $this->businessPage($clientFolder, null, $activePerson);
    }

    public function index(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $sortableColumns = ['ci_date', 'business_name', 'year_established'];
        $sort = in_array(request()->query('sort'), $sortableColumns, true) ? request()->query('sort') : null;
        $sortDirection = $sort && request()->query('direction') === 'desc' ? 'desc' : 'asc';

        $businesses = $this->dedicatedSources($clientFolder, $activePerson, $sort, $sortDirection);
        $legacyPlaceholders = $businesses->filter(fn (IncomeSource $business): bool => $this->isBlankLegacyPlaceholder($business));
        if ($legacyPlaceholders->count() > 1) {
            $legacyIds = $legacyPlaceholders->modelKeys();
            $businesses = $businesses->reject(fn (IncomeSource $business): bool => in_array($business->getKey(), $legacyIds, true))->values();
        }
        $displayTimezone = config('cims.display_timezone');
        $businessTemplates = $this->activeBusinessTemplates();

        return view('client-folders.income-sources.manage', compact('clientFolder', 'businesses', 'displayTimezone', 'businessTemplates', 'activePerson', 'sort', 'sortDirection'));
    }

    public function create(ClientFolder $clientFolder): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolveFromQuery($clientFolder, request()));

        return redirect()->route('client-folders.income-sources.index', [$clientFolder] + $personParams);
    }

    public function selectTemplate(ClientFolder $clientFolder): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolveFromQuery($clientFolder, request()));

        return redirect()->route('client-folders.income-sources.index', [$clientFolder] + $personParams);
    }

    public function store(StoreIncomeSourceRequest $request, ClientFolder $clientFolder, CreateIncomeSource $create, SaveBusinessIncomeSource $save): RedirectResponse
    {
        $data = $request->validated();
        $source = $create->execute($request->user(), $clientFolder, $data);
        $save->execute($request->user(), $clientFolder, $source, $data);
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $data['co_maker_id'] ?? null));

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $source] + $personParams)->with('status', 'Business Report saved successfully.');
    }

    public function show(ClientFolder $clientFolder, IncomeSource $incomeSource): RedirectResponse
    {
        Gate::authorize('view', $incomeSource);
        $personParams = ActivePersonResolver::queryParams($incomeSource->co_maker_id ? $clientFolder->coMakers()->find($incomeSource->co_maker_id) : null);

        return redirect()->route('client-folders.income-sources.edit', [$clientFolder, $incomeSource] + $personParams);
    }

    public function edit(ClientFolder $clientFolder, IncomeSource $incomeSource): View|RedirectResponse
    {
        Gate::authorize('update', $incomeSource);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($incomeSource, $activePerson);
        $incomeSource->load('template');
        if ($incomeSource->template->is_fallback) {
            return redirect()->route('client-folders.income-sources.index', [$clientFolder] + ActivePersonResolver::queryParams($activePerson));
        }

        return $this->businessPage($clientFolder, $incomeSource, $activePerson);
    }

    public function addBusiness(ClientFolder $clientFolder, IncomeSource $incomeSource, CreateIncomeSource $create): RedirectResponse
    {
        Gate::authorize('create', [IncomeSource::class, $clientFolder]);
        Gate::authorize('view', $incomeSource);
        $incomeSource->load('template');
        abort_if($incomeSource->template->is_fallback || $incomeSource->template->form_handler !== 'dedicated-business', 404);

        // The new business always belongs to the exact same person as the one it's being added
        // alongside — inherited straight from the already-authorized source record, never from
        // client-supplied input, so there's no separate ownership input to validate here.
        $business = $create->execute(request()->user(), $clientFolder, [
            'income_source_template_id' => $incomeSource->income_source_template_id,
            'source_name' => '',
            'business_name' => null,
            'co_maker_id' => $incomeSource->co_maker_id,
        ]);
        $personParams = ActivePersonResolver::queryParams($incomeSource->co_maker_id ? $clientFolder->coMakers()->find($incomeSource->co_maker_id) : null);

        return redirect()
            ->route('client-folders.income-sources.edit', [$clientFolder, $business] + $personParams)
            ->with('status', 'A new blank Business Report is ready for encoding.');
    }

    public function updateGeneral(UpdateGeneralIncomeSourceRequest $request, ClientFolder $clientFolder, IncomeSource $incomeSource, SaveGeneralIncomeSource $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $incomeSource, $request->validated());
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));

        return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource, 'Income source report saved successfully.', $activePerson);
    }

    public function updateBusiness(UpdateBusinessIncomeSourceRequest $request, ClientFolder $clientFolder, IncomeSource $incomeSource, SaveBusinessIncomeSource $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $incomeSource, $request->validated());
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));

        return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource, 'Business Report updated successfully.', $activePerson);
    }

    public function destroy(ClientFolder $clientFolder, IncomeSource $incomeSource, DeleteIncomeSource $delete): RedirectResponse
    {
        Gate::authorize('delete', $incomeSource);
        $personParams = ActivePersonResolver::queryParams($incomeSource->co_maker_id ? $clientFolder->coMakers()->find($incomeSource->co_maker_id) : null);
        $delete->execute(request()->user(), $clientFolder, $incomeSource);

        return redirect()->route('client-folders.income-sources.manage', [$clientFolder] + $personParams)->with('status', 'Business deleted successfully.');
    }

    private function afterSave(string $intent, ClientFolder $folder, IncomeSource $source, string $message, ?CoMaker $activePerson): RedirectResponse
    {
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $route = $intent === 'return'
            ? route('client-folders.income-sources.manage', [$folder] + $personParams)
            : route('client-folders.income-sources.edit', [$folder, $source] + $personParams);

        return redirect($route)->with('status', $message);
    }

    private function dedicatedSources(ClientFolder $folder, ?CoMaker $activePerson, ?string $sort = null, string $sortDirection = 'asc')
    {
        $businesses = $folder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['template', 'businessReport'])
            ->whereHas('template', fn ($query) => $query
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business'))
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($sort === null) {
            return $businesses;
        }

        // Sorted in PHP rather than SQL: Business Name has template-specific display overrides
        // (see IncomeSource::displayName()) that don't live in a single sortable column, so
        // every sortable field is ordered against the exact same value the table displays.
        $descending = $sortDirection === 'desc';
        $key = match ($sort) {
            'ci_date' => fn (IncomeSource $business) => $business->businessReport?->start_date,
            'year_established' => fn (IncomeSource $business) => $business->businessReport?->year_established,
            'business_name' => fn (IncomeSource $business) => mb_strtolower($business->displayName()),
        };

        return $businesses->sortBy($key, SORT_REGULAR, $descending)->values();
    }

    private function businessPage(ClientFolder $clientFolder, ?IncomeSource $incomeSource, ?CoMaker $activePerson): View
    {
        $clientFolder->loadMissing([
            'assignedInvestigator:id,full_name',
            'cibiReport' => fn ($query) => $query->where('co_maker_id', $activePerson?->id)->select('id', 'client_folder_id', 'co_maker_id', 'party_type', 'branch_name', 'account_officer_name', 'amount_applied'),
        ]);
        if ($incomeSource) {
            $incomeSource->load([
                'template', 'businessReport.properties.tenants', 'businessReport.branches', 'businessReport.products',
                'businessReport.suppliers', 'businessReport.observations', 'businessReport.competitors',
            ]);
        }
        $businesses = $this->dedicatedSources($clientFolder, $activePerson);
        $legacyPlaceholders = $businesses->filter(fn (IncomeSource $business): bool => $this->isBlankLegacyPlaceholder($business));
        $suppressLegacyBusinessUi = $legacyPlaceholders->count() > 1;
        if ($suppressLegacyBusinessUi) {
            $legacyIds = $legacyPlaceholders->modelKeys();
            $businesses = $businesses->reject(fn (IncomeSource $business): bool => in_array($business->getKey(), $legacyIds, true))->values();
            if ($incomeSource && in_array($incomeSource->getKey(), $legacyIds, true)) {
                $incomeSource = null;
            }
        }
        $businessTemplates = $this->activeBusinessTemplates();
        $preselectedTemplateId = $incomeSource ? null : request()->query('income_source_template_id');

        return view('client-folders.income-sources.business-edit', compact('clientFolder', 'incomeSource', 'businesses', 'businessTemplates', 'suppressLegacyBusinessUi', 'preselectedTemplateId', 'activePerson'));
    }

    private function activeBusinessTemplates()
    {
        return IncomeSourceTemplate::query()
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'business_category', 'template_type', 'version', 'compatibility_tags']);
    }

    private function isBlankLegacyPlaceholder(IncomeSource $source): bool
    {
        return $source->revision === 1
            && blank($source->source_name)
            && blank($source->business_name)
            && blank($source->businessReport?->business_name);
    }
}
