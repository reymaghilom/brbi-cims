<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Actions\ClientFolders\SaveGeneralIncomeSource;
use App\Actions\ClientFolders\UpdateIncomeSourceContributors;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\QuickCreateIncomeSourceRequest;
use App\Http\Requests\ClientFolders\StoreIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateBusinessIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateGeneralIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateIncomeSourceContributorsRequest;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class IncomeSourceController extends Controller
{
    public function __construct(private readonly CiParticipantService $participants) {}

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

    /**
     * "+ Add Business" quick-create used from the Business Check form (Applicant only for now)
     * when the business hasn't been created through the normal Business Report flow yet. Creates
     * the exact same shared IncomeSource/BusinessReport shell as the full flow (CreateIncomeSource
     * is reused as-is, unmodified) — never a Business-Check-only text field, never a duplicate
     * identity, never a fake "completed" report. It stays in RecordState::Draft exactly like any
     * other freshly created business until someone completes it through Business / Income Sources.
     *
     * The modal's own Location field is set as an extra step right here (not inside
     * CreateIncomeSource) — it becomes the new BusinessReport's main_business_address, the one
     * authoritative business address Business Check and Business Report both read from, so a
     * business quick-added from Business Check already shows the same address if/when a Business
     * Report is opened for it later.
     */
    public function quickCreate(QuickCreateIncomeSourceRequest $request, ClientFolder $clientFolder, CreateIncomeSource $create): JsonResponse
    {
        $data = $request->validated();
        $source = $create->execute($request->user(), $clientFolder, [
            'income_source_template_id' => $data['income_source_template_id'],
            'source_name' => $data['business_name'],
            'business_name' => $data['business_name'],
            'co_maker_id' => $data['co_maker_id'] ?? null,
        ]);

        if (filled($data['location'] ?? null)) {
            $source->businessReport?->update(['main_business_address' => $data['location']]);
        }

        return response()->json(['id' => $source->id, 'name' => $source->displayName(), 'location' => $source->businessReport?->main_business_address]);
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
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));

        try {
            $save->execute($request->user(), $clientFolder, $incomeSource, $request->validated());
        } catch (NoChangesDetectedException $e) {
            return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource, $e->getMessage(), $activePerson, 'info');
        }

        return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource, 'Income source report saved successfully.', $activePerson);
    }

    public function updateBusiness(UpdateBusinessIncomeSourceRequest $request, ClientFolder $clientFolder, IncomeSource $incomeSource, SaveBusinessIncomeSource $save): RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));

        try {
            $save->execute($request->user(), $clientFolder, $incomeSource, $request->validated());
        } catch (NoChangesDetectedException $e) {
            return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource, $e->getMessage(), $activePerson, 'info');
        }

        return $this->afterSave($request->string('intent')->toString(), $clientFolder, $incomeSource, 'Business Report updated successfully.', $activePerson);
    }

    public function updateContributors(UpdateIncomeSourceContributorsRequest $request, ClientFolder $clientFolder, IncomeSource $incomeSource, UpdateIncomeSourceContributors $update): RedirectResponse
    {
        $update->execute($request->user(), $clientFolder, $incomeSource, $request->validated('contributor_ids', []));
        $activePerson = ActivePersonResolver::resolve($clientFolder, $incomeSource->co_maker_id);

        return $this->afterSave('stay', $clientFolder, $incomeSource, 'Contributors updated successfully.', $activePerson);
    }

    public function destroy(ClientFolder $clientFolder, IncomeSource $incomeSource, DeleteIncomeSource $delete): RedirectResponse
    {
        Gate::authorize('delete', $incomeSource);
        $personParams = ActivePersonResolver::queryParams($incomeSource->co_maker_id ? $clientFolder->coMakers()->find($incomeSource->co_maker_id) : null);
        $delete->execute(request()->user(), $clientFolder, $incomeSource);

        return redirect()->route('client-folders.income-sources.manage', [$clientFolder] + $personParams)->with('status', 'Business deleted successfully.');
    }

    private function afterSave(string $intent, ClientFolder $folder, IncomeSource $source, string $message, ?CoMaker $activePerson, string $statusType = 'success'): RedirectResponse
    {
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $route = $intent === 'return'
            ? route('client-folders.income-sources.manage', [$folder] + $personParams)
            : route('client-folders.income-sources.edit', [$folder, $source] + $personParams);

        return redirect($route)->with('status', $message)->with('statusType', $statusType);
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
                'creator:id,full_name', 'lastEditor:id,full_name', 'contributors:id,full_name',
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

        // A not-yet-created business has no IncomeSource to derive a primary CI from — the
        // primary is simply whoever is currently encoding it, exactly as the existing (unrelated)
        // CI In-Charge display already assumes for a brand-new business.
        $primaryCiId = $incomeSource ? $incomeSource->ciPrimaryUserId() : auth()->id();
        $companions = $incomeSource
            ? $this->participants->orderedParticipants($incomeSource)->reject(fn (User $user): bool => (int) $user->id === (int) $primaryCiId)->values()
            : collect();
        // The primary CI is never a valid companion choice — excluded here entirely (not just
        // disabled in the UI) so the modal's candidate list can never even present them.
        $activeCreditInvestigators = User::query()
            ->where('role', UserRole::CreditInvestigator)
            ->where('status', UserStatus::Active)
            ->where('id', '!=', $primaryCiId)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        return view('client-folders.income-sources.business-edit', compact('clientFolder', 'incomeSource', 'businesses', 'businessTemplates', 'suppressLegacyBusinessUi', 'preselectedTemplateId', 'activePerson', 'activeCreditInvestigators', 'companions'));
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
