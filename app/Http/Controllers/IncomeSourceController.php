<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\CreateIncomeSource;
use App\Actions\ClientFolders\DeleteBusinessReport;
use App\Actions\ClientFolders\DeleteIncomeSource;
use App\Actions\ClientFolders\SaveBusinessIncomeSource;
use App\Actions\ClientFolders\SaveGeneralIncomeSource;
use App\Actions\ClientFolders\UpdateIncomeSourceContributors;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\StoreIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateBusinessIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateGeneralIncomeSourceRequest;
use App\Http\Requests\ClientFolders\UpdateIncomeSourceContributorsRequest;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\ClientFolderOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class IncomeSourceController extends Controller
{
    public function __construct(
        private readonly CiParticipantService $participants,
        private readonly ClientFolderOverview $overview,
    ) {}

    public function launch(ClientFolder $clientFolder): View|RedirectResponse
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());

        // "Next" in Select Business Template lands here with the chosen template. A standard
        // template this exact person already uses must never reach the encoding form at all, so it
        // is turned away here rather than after the CI has filled the form in. Other Business /
        // Source of Income is deliberately allowed through: its identity is the set of categories
        // ticked inside the form, so it can only be judged on Save (StoreIncomeSourceRequest).
        $template = IncomeSourceTemplate::query()->find((int) request()->query('income_source_template_id'));
        if ($template && $template->template_type !== self::OTHER_BUSINESS_TEMPLATE_TYPE
            && $this->personAlreadyUsesTemplate($clientFolder, $activePerson, $template)) {
            return redirect()
                ->route('client-folders.income-sources.manage', [$clientFolder] + ActivePersonResolver::queryParams($activePerson))
                ->with('status', self::DUPLICATE_TEMPLATE_MESSAGE)
                ->with('statusType', 'error');
        }

        return $this->businessPage($clientFolder, null, $activePerson);
    }

    /**
     * True when this exact person already holds a LEGITIMATE business report on this exact template.
     *
     * "Legitimate" is the important half. A surviving IncomeSource whose Business Report was
     * intentionally deleted (business_report_deleted_at), or one that never had a report row at
     * all, is not an existing report — treating it as one is what made a template like Remittance
     * report itself as a duplicate when the person had no Remittance report at all. Neither is the
     * unencoded shell CreateIncomeSource auto-creates for a Business Check-first business: see the
     * revision condition below.
     */
    private function personAlreadyUsesTemplate(ClientFolder $clientFolder, ?CoMaker $activePerson, IncomeSourceTemplate $template): bool
    {
        return $clientFolder->incomeSources()
            ->where('income_source_template_id', $template->id)
            ->where('co_maker_id', $activePerson?->id)
            ->whereNull('business_report_deleted_at')
            ->has('businessReport')
            // An encoded report, not a shell. A Business Check-first business already owns a
            // business_reports row, but its revision stays 1 until SaveBusinessIncomeSource has
            // actually run for it — the same convention checkFirstCandidates(),
            // isBlankLegacyPlaceholder() and the picker's own used-template list (built from
            // dedicatedSources(requireReport: true)) already use. Without this the Check-first
            // business blocked its own Business Report, and Next disagreed with the picker.
            ->where('revision', '>', 1)
            ->exists();
    }

    public function index(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());

        return view('client-folders.income-sources.manage', $this->manageViewData($clientFolder, $activePerson));
    }

    private function manageViewData(ClientFolder $clientFolder, ?CoMaker $activePerson): array
    {
        $sortableColumns = ['ci_date', 'business_name', 'year_established'];
        $sort = in_array(request()->query('sort'), $sortableColumns, true) ? request()->query('sort') : null;
        $sortDirection = $sort && request()->query('direction') === 'desc' ? 'desc' : 'asc';

        $businesses = $this->dedicatedSources($clientFolder, $activePerson, $sort, $sortDirection, requireReport: true);
        $legacyPlaceholders = $businesses->filter(fn (IncomeSource $business): bool => $this->isBlankLegacyPlaceholder($business));
        if ($legacyPlaceholders->count() > 1) {
            $legacyIds = $legacyPlaceholders->modelKeys();
            $businesses = $businesses->reject(fn (IncomeSource $business): bool => in_array($business->getKey(), $legacyIds, true))->values();
        }
        $checkFirstCandidates = $this->checkFirstCandidates($clientFolder, $activePerson);
        $displayTimezone = config('cims.display_timezone');
        $businessTemplates = $this->activeBusinessTemplates();
        // Derived from the businesses already loaded above rather than a second query — this list
        // only lets Select Business Template refuse a duplicate early; launch() re-checks it
        // against the database, so nothing depends on this being exhaustive.
        $usedTemplateIds = $businesses
            ->reject(fn (IncomeSource $business): bool => $business->template?->template_type === self::OTHER_BUSINESS_TEMPLATE_TYPE)
            // Same "legitimate" test the server applies, so Next and launch() can never disagree:
            // a business whose report was intentionally deleted leaves its template free again.
            ->reject(fn (IncomeSource $business): bool => $business->business_report_deleted_at !== null || $business->businessReport === null)
            ->pluck('income_source_template_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $recentActivity = $this->overview->businessActivity($clientFolder, $activePerson);
        $businessChecksCount = $clientFolder->businessChecks()->where('co_maker_id', $activePerson?->id)->count();
        $personParams = ActivePersonResolver::queryParams($activePerson);

        return compact('clientFolder', 'businesses', 'checkFirstCandidates', 'displayTimezone', 'businessTemplates', 'usedTemplateIds', 'activePerson', 'sort', 'sortDirection', 'recentActivity', 'businessChecksCount', 'personParams');
    }

    private const OTHER_BUSINESS_TEMPLATE_TYPE = 'other_business_source_of_income';

    /**
     * The single wording for a STANDARD duplicate Business Template, wherever the CI meets it: the
     * Select Business Template modal states it inline under the selector, launch() flashes it when
     * the picker is bypassed, and StoreIncomeSourceRequest raises it on save. One string so the
     * fast client-side refusal and the authoritative server one can never read differently.
     *
     * Other Business / Source of Income is never judged by this rule — its identity is the exact
     * normalized set of categories ticked inside the form, and it has its own message.
     */
    public const DUPLICATE_TEMPLATE_MESSAGE = 'This business template already exists for this client. Please select another template.';

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

        $this->flashManageRefresh($clientFolder, ActivePersonResolver::resolve($clientFolder, $data['co_maker_id'] ?? null));

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

        return redirect()->route('client-folders.income-sources.manage', [$clientFolder] + $personParams)->with('status', 'Business and linked Business Report and Business Check permanently deleted.');
    }

    /**
     * Permanent, independent counterpart to destroy() above — used only by the dedicated-business
     * "Business / Income Sources" list, where a row always has a real BusinessReport. This never
     * touches the linked Business Check or the IncomeSource itself unless the IncomeSource is left
     * truly orphaned (see DeleteBusinessReport / DeleteIncomeSourceIfOrphaned), whereas destroy()
     * above removes the whole business — IncomeSource, Report and Check together.
     */
    public function destroyBusinessReport(ClientFolder $clientFolder, IncomeSource $incomeSource, DeleteBusinessReport $delete): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $incomeSource);
        $activePerson = $incomeSource->co_maker_id ? $clientFolder->coMakers()->find($incomeSource->co_maker_id) : null;
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $delete->execute(request()->user(), $clientFolder, $incomeSource);

        if (request()->wantsJson()) {
            return response()->json(['deleted' => 1] + $this->refreshPayload($clientFolder, $activePerson));
        }

        return redirect()->route('client-folders.income-sources.manage', [$clientFolder] + $personParams)->with('status', 'Business Report permanently deleted.');
    }

    /**
     * Bulk counterpart to destroyBusinessReport() above, used by the Saved Businesses "Delete
     * Selected" toolbar action. Every selected id is re-scoped to the current folder + exact
     * active person (never trusted as-is from the request) and must already be a genuinely saved
     * Business Report (revision > 1) — an id outside that scope is simply skipped, never deleted,
     * and never allowed to leak another person's or folder's data. Each report is deleted one at a
     * time through the same canonical DeleteBusinessReport action used by the single-delete route
     * (so Business Check survival, orphan IncomeSource cleanup, and the individual AuditLog entry
     * are all identical to a one-by-one delete) inside one outer transaction, so a mid-batch failure
     * never leaves a partial delete behind.
     *
     * Ownership validation is strictly all-or-nothing: every submitted id must resolve to a
     * genuinely saved Business Report inside this exact folder + exact active person, or NOTHING is
     * deleted — never a partial delete that silently drops a foreign/invalid id and proceeds with
     * whatever remains. Deletion only ever begins once the complete submitted set is confirmed
     * authorized.
     */
    public function destroySelectedBusinessReports(ClientFolder $clientFolder, DeleteBusinessReport $delete): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $clientFolder);
        $validated = request()->validate([
            'co_maker_id' => ['nullable', 'integer'],
            'income_source_ids' => ['required', 'array', 'min:1'],
            'income_source_ids.*' => ['integer'],
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $submittedIds = array_values(array_unique(array_map('intval', $validated['income_source_ids'])));

        $sources = IncomeSource::query()
            ->where('client_folder_id', $clientFolder->id)
            ->where('co_maker_id', $activePerson?->id)
            ->whereIn('id', $submittedIds)
            ->whereHas('businessReport')
            ->where('revision', '>', 1)
            ->get();

        if ($sources->count() !== count($submittedIds)) {
            throw ValidationException::withMessages([
                'income_source_ids' => 'One or more selected Business Reports could not be verified for this person and folder. No reports were deleted.',
            ]);
        }

        foreach ($sources as $source) {
            Gate::authorize('delete', $source);
        }

        $deletedCount = 0;
        DB::transaction(function () use ($sources, $delete, $clientFolder, &$deletedCount): void {
            foreach ($sources as $source) {
                $delete->execute(request()->user(), $clientFolder, $source);
                $deletedCount++;
            }
        });

        $message = $deletedCount === 1 ? '1 Business Report permanently deleted.' : "{$deletedCount} Business Reports permanently deleted.";

        if (request()->wantsJson()) {
            return response()->json(['deleted' => $deletedCount] + $this->refreshPayload($clientFolder, $activePerson));
        }

        return redirect()->route('client-folders.income-sources.manage', [$clientFolder] + $personParams)->with('status', $message);
    }

    private function refreshPayload(ClientFolder $clientFolder, ?CoMaker $activePerson): array
    {
        $data = $this->manageViewData($clientFolder, $activePerson);

        return [
            // Report Pending candidates now render as ordinary rows inside the unified "panel"
            // table itself (see partials/saved-businesses-panel-body.blade.php) — there is no
            // longer a separate candidates fragment to refresh independently.
            'panel' => view('client-folders.income-sources.partials.saved-businesses-panel-body', $data)->render(),
            'activity' => view('client-folders.income-sources.partials.recent-activity-body', $data)->render(),
            'modal' => view('components.ui.recent-activity-modal', ['id' => 'business-recent-activity-dialog', 'activities' => $data['recentActivity']])->render(),
            // The template picker lives outside the refreshed panel (it is a page-level <dialog>),
            // so without this its data-used-template-ids stayed frozen at whatever was true when
            // the page was first rendered — and after a save or a Business Report delete it started
            // refusing templates the server would have allowed. The refresh now carries the same
            // server-computed list the page was rendered with, so the picker's state has one source
            // of truth and no separate lifecycle.
            'usedTemplateIds' => $data['usedTemplateIds'],
        ];
    }

    private function afterSave(string $intent, ClientFolder $folder, IncomeSource $source, string $message, ?CoMaker $activePerson, string $statusType = 'success'): RedirectResponse
    {
        // A no-change save ('info') wrote nothing, so there is nothing for the parent page to
        // AUTO-UPDATE and no saved-state to hand it. Skipping the flash here is what keeps the
        // encoding dialog open on a no-op: business-encoding.blade.php only emits its
        // 'brbi:business-saved' notify element for a save that actually persisted.
        if ($statusType !== 'info') {
            $this->flashManageRefresh($folder, $activePerson);
        }
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $route = $intent === 'return'
            ? route('client-folders.income-sources.manage', [$folder] + $personParams)
            : route('client-folders.income-sources.edit', [$folder, $source] + $personParams);

        return redirect($route)->with('status', $message)->with('statusType', $statusType);
    }

    /**
     * The authoritative Business page state (same fragments refreshPayload() renders for the
     * dedicated AJAX delete endpoints below) flashed onto the session so the very next request —
     * the "stay" redirect landing back on the Business Report edit page, loaded inside the existing
     * modal/iframe — can embed it directly in that same response (see business-encoding.blade.php's
     * data-business-saved-notify element). This is what lets the iframe's postMessage to the parent
     * carry the updated state itself: the parent never needs to issue its own follow-up GET request
     * to learn what changed.
     */
    private function flashManageRefresh(ClientFolder $folder, ?CoMaker $activePerson): void
    {
        session()->flash('business_manage_refresh', $this->refreshPayload($folder, $activePerson));
    }

    /**
     * $requireReport is true only for the Saved Businesses / Income Sources listing
     * (IncomeSourceController::index(), i.e. manage.blade.php). A genuinely saved Business Report
     * requires both: a business_reports row still existing (it survives a hard-delete of the Check,
     * see DeleteBusinessReport, but not of the Report itself) AND revision > 1, meaning
     * SaveBusinessIncomeSource has actually run at least once for this business — CreateIncomeSource
     * alone leaves revision at 1 and must never
     * count as a saved Report (see IncomeSourceController::checkFirstCandidates() below for how that
     * shell is instead surfaced as a create-report candidate). The Business Report edit page's own
     * business-switcher sidebar (businessPage() below) intentionally keeps showing every dedicated
     * business regardless, since navigating straight to a Report-less one is exactly how it gets
     * recreated — from the normal template workflow alone, never from Business Check data.
     */
    private function dedicatedSources(ClientFolder $folder, ?CoMaker $activePerson, ?string $sort = null, string $sortDirection = 'asc', bool $requireReport = false)
    {
        $businesses = $folder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['template', 'businessReport'])
            ->whereHas('template', fn ($query) => $query
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business'))
            ->when($requireReport, fn ($query) => $query->whereHas('businessReport')->where('revision', '>', 1))
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

    /**
     * Historical businesses left behind by the retired Check-first flow — a real IncomeSource +
     * business_reports shell that was never explicitly saved through the Business Report form
     * (revision === 1) and already has its own Business Check. Business Check can no longer create
     * one of these, but the ones already in the data must stay readable and completable. These are deliberately excluded from $businesses (see
     * dedicatedSources()'s $requireReport) so they never masquerade as a saved Business Report, but
     * they must still be reachable without forcing the user back through the generic "Choose
     * Business Template" picker — the whole point of Check-first is that the template was already
     * chosen once. Requires an existing Business Check specifically (not just an unfinalized
     * Report) so a candidate disappears the moment its only Check is deleted, exactly like the
     * "no stale candidate" rule requires.
     */
    private function checkFirstCandidates(ClientFolder $folder, ?CoMaker $activePerson)
    {
        return $folder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->where('revision', 1)
            ->with(['template', 'businessReport', 'businessCheck.investigator:id,full_name'])
            ->whereHas('template', fn ($query) => $query
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business'))
            ->whereHas('businessCheck')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
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
            ->whereIn('role', UserRole::creditInvestigatorRoles())
            ->where('status', UserStatus::Active)
            ->where('id', '!=', $primaryCiId)
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        return view('client-folders.income-sources.business-edit', compact('clientFolder', 'incomeSource', 'businesses', 'businessTemplates', 'suppressLegacyBusinessUi', 'preselectedTemplateId', 'activePerson', 'activeCreditInvestigators', 'companions'));
    }

    private function activeBusinessTemplates()
    {
        return IncomeSourceTemplate::query()
            ->activeBusiness()
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
