@extends('layouts.business-encoding')

@section('title', 'Business Report · '.$clientFolder->display_name)

@php
    $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
    $report = $incomeSource?->businessReport;
    $hasActiveReport = $report !== null;
    $cibiReport = $clientFolder->cibiReport()->where('co_maker_id', ($activePerson ?? null)?->id)->first();
    $headerBranch = $incomeSource?->branch_name ?: $cibiReport?->branch_name;
    $headerAccountOfficer = $incomeSource?->account_officer_name ?: $cibiReport?->account_officer_name;
    $headerAmountApplied = $cibiReport?->amount_applied;
    $formatAmountApplied = static function (mixed $value): string {
        $original = trim((string) $value);
        $normalized = str_replace(',', '', $original);
        if ($original === '' || !preg_match('/^([+-]?)(\d+)(\.\d+)?$/', $normalized, $parts)) {
            return $original;
        }

        $integer = ltrim($parts[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $groupedInteger = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer);

        return $parts[1].$groupedInteger.($parts[3] ?? '');
    };
    $headerAmountAppliedDisplay = $formatAmountApplied($headerAmountApplied);
    $headerFormId = $incomeSource ? 'business-report-form' : 'business-template-form';
    // Derived from the active person (co_maker_id ownership), not the linked CI/BI report's
    // stored party_type column — see the matching note in OfficialReportDataBuilder::cibi().
    $partyType = ($activePerson ?? null) ? 'co_maker' : 'borrower';
    $nameLabel = ($activePerson ?? null) ? 'NAME OF CO-MAKER:' : 'NAME OF APPLICANT:';
    $tags = $incomeSource?->template?->compatibility_tags ?? [];
    $propertyOptions = $report?->properties?->mapWithKeys(fn ($property) => [$property->id => $property->property_type . ($property->location ? ' - ' . str($property->location)->limit(50) : '')])->all() ?? [];
    $tenants = $report?->properties?->pluck('tenants')->flatten() ?? collect();
    $officialTitle = match ($incomeSource?->template_type) {
        'leasing_non_agricultural' => 'LEASING OPERATIONS: NON-AGRICULTURAL REAL ESTATE',
        'retail_grocery_water_refilling' => 'RETAIL: GROCERY STORE / SUPERMARKET / SARI-SARI STORE / WATER REFILLING',
        default => $incomeSource ? str($incomeSource->template->name)->upper() : null,
    };
@endphp

@section('content')
    <form id="business-template-form" method="POST" action="{{ route('client-folders.income-sources.store', $clientFolder) }}" hidden>@csrf<input type="hidden" name="intent" value="complete"><input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}"></form>

    @if($incomeSource)
        <form id="business-report-form" method="POST" action="{{ route('client-folders.income-sources.business.update', [$clientFolder, $incomeSource]) }}" class="business-encoding-page" data-business-report-form data-unsaved-form>
            @csrf
            @method('PUT')
            <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
            <input type="hidden" name="expected_revision" value="{{ $incomeSource->revision }}">

    @else
        <div class="business-encoding-page" data-business-report-form>
    @endif

        @if($errors->has(\App\Http\Requests\ClientFolders\StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY))
            {{-- A duplicate category combination has one cause and one explanation, so it replaces
                 the generic "correct the highlighted fields" banner entirely and is never repeated
                 beside the checkboxes. The user's entries and ticks are preserved by old(). --}}
            <div class="mb-3 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert" tabindex="-1" data-business-duplicate-categories-error>
                {{ $errors->first(\App\Http\Requests\ClientFolders\StoreIncomeSourceRequest::DUPLICATE_CATEGORIES_KEY) }}
            </div>
        @elseif($errors->any())
            <div class="mb-3 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert" tabindex="-1">
                <strong>Please correct the highlighted Business Report fields.</strong> No changes were saved.
            </div>
        @endif

        <div class="business-report-paper">
            <section class="business-report-header" aria-labelledby="business-report-official-title">
                <header class="business-report-official-header">
                    <div>
                        <div class="business-report-title-line"><h1 id="business-report-official-title">CREDIT INVESTIGATION REPORT</h1><strong>INDIVIDUAL ACCOUNT</strong></div>
                        <p class="business-report-scope">(SOURCE OF INCOME VALIDATION)</p>
                        <p class="business-report-confidential">RESTRICTED &amp; CONFIDENTIAL <span>(v.as of -2020.08.03)</span></p>
                    </div>
                    <img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc.">
                </header>

                <div class="business-report-header-grid" aria-label="Business Report details">
                    <div class="business-report-header-label">CI-IN CHARGE:</div>
                    {{-- New (unsaved) business: CI In-Charge is whoever is currently authenticated
                         and creating it, never the folder's own (unrelated, static) assigned
                         investigator. Existing business: its actual recorded creator (or '—' for
                         a legacy row saved before creator tracking existed) — editing it later
                         must never silently reassign who originally created it to whichever CI
                         happens to be editing it now. --}}
                    <div class="business-report-header-value business-report-header-readonly">
                        <div class="flex w-full flex-nowrap items-center justify-between gap-x-3" data-companion-ci-picker data-companion-dialog-id="business-companion-ci-dialog">
                            {{-- business-report-ci-names carries the responsive, slightly smaller type
                                 that keeps several CI names on one line; text-xs is dropped so it is
                                 not competing with that. Which names are shown, and in what form, is
                                 unchanged — see the compact-display note on the companion list. --}}
                            <div class="business-report-ci-names flex min-w-0 flex-1 flex-nowrap items-center gap-x-1 uppercase" data-companion-ci-container data-header-form-id="{{ $headerFormId }}">
                                <span class="font-semibold" data-ci-primary-name>{{ $incomeSource ? ($incomeSource->creator?->full_name ?? '—') : auth()->user()->full_name }}</span>
                                <span class="flex flex-nowrap items-center gap-x-1.5" data-companion-participant-list>
                                    @foreach($companions as $companion)
                                        {{-- Display only: the first two CIs on the record keep their full
                                             names and every CI after them is shown by first given name
                                             alone (CiParticipantService::compactDisplayName). The stored
                                             user, the hidden contributor_ids and every official output
                                             still carry the full name — see data-full-name below, which
                                             is what the remove control and the outputs read. --}}
                                        <span class="flex items-center gap-1" data-companion-participant data-user-id="{{ $companion->id }}">
                                            <span aria-hidden="true">/</span>
                                            <span data-full-name="{{ $companion->full_name }}">{{ \App\Services\ClientFolders\CiParticipantService::compactDisplayName($companion->full_name, $loop->index + 1) }}</span>
                                            <button type="button" class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border border-danger/40 bg-danger-soft text-[0.7rem] font-bold normal-case leading-none text-danger transition hover:border-danger hover:bg-danger hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40" data-companion-remove aria-label="Remove {{ $companion->full_name }}">&times;</button>
                                        </span>
                                    @endforeach
                                </span>
                                <div data-companion-hidden-inputs hidden>
                                    @foreach($companions as $companion)
                                        <input type="hidden" name="contributor_ids[]" value="{{ $companion->id }}" form="{{ $headerFormId }}">
                                    @endforeach
                                </div>
                                <input type="hidden" name="contributor_ids_present" value="1" form="{{ $headerFormId }}">
                            </div>
                            <button type="button" class="ui-button-secondary-compact shrink-0" data-modal-open="business-companion-ci-dialog" data-companion-dialog-trigger><span aria-hidden="true">+</span> Add CI</button>
                        </div>
                    </div>
                    <label class="business-report-header-label" for="branch_name">BRANCH:</label>
                    <div class="business-report-header-value business-report-header-branch"><input id="branch_name" name="branch_name" form="{{ $headerFormId }}" value="{{ old('branch_name', $headerBranch) }}" class="business-report-header-control" readonly aria-readonly="true" @error('branch_name') aria-invalid="true" aria-describedby="branch_name-error" @enderror><x-form.validation-message for="branch_name" /></div>

                    <label class="business-report-header-label" for="start_date">START DATE OF CI:</label>
                    <div class="business-report-header-value"><input id="start_date" name="start_date" form="{{ $headerFormId }}" type="date" required value="{{ old('start_date', $report?->start_date?->format('Y-m-d')) }}" class="business-report-header-control" @error('start_date') aria-invalid="true" aria-describedby="start_date-error" @enderror><x-form.validation-message for="start_date" /></div>
                    <div class="business-report-header-label">{{ $nameLabel }}</div>
                    <div class="business-report-header-value business-report-header-readonly">{{ ($activePerson ?? null)?->full_name ?? $clientFolder->display_name }}</div>

                    <label class="business-report-header-label" for="submitted_date">DATE SUBMITTED TO CA:</label>
                    <div class="business-report-header-value"><input id="submitted_date" name="submitted_date" form="{{ $headerFormId }}" type="date" value="{{ old('submitted_date', $report?->submitted_date?->format('Y-m-d')) }}" class="business-report-header-control" @error('submitted_date') aria-invalid="true" aria-describedby="submitted_date-error" @enderror><x-form.validation-message for="submitted_date" /></div>
                    <label class="business-report-header-label" for="account_officer_name">ACCOUNT OFFICER:</label>
                    <div class="business-report-header-value"><input id="account_officer_name" name="account_officer_name" form="{{ $headerFormId }}" value="{{ old('account_officer_name', $headerAccountOfficer) }}" class="business-report-header-control" readonly aria-readonly="true" @error('account_officer_name') aria-invalid="true" aria-describedby="account_officer_name-error" @enderror><x-form.validation-message for="account_officer_name" /></div>

                    <div class="business-report-header-party" aria-label="Saved applicant role">
                        <span><span class="business-report-party-check" aria-hidden="true">( {{ $partyType === 'borrower' ? '✓' : ' ' }} )</span> BORROWER</span>
                        <span><span class="business-report-party-check" aria-hidden="true">( {{ $partyType === 'co_maker' ? '✓' : ' ' }} )</span> CO-MAKER</span>
                    </div>
                    <label class="business-report-header-label" for="amount_applied">AMOUNT APPLIED:</label>
                    <div class="business-report-header-value"><input id="amount_applied" type="text" value="{{ $headerAmountAppliedDisplay }}" class="business-report-header-control" readonly aria-readonly="true"></div>
                </div>
            </section>

            @if($incomeSource)
                <div data-editing-presence data-editing-type="income_source" data-editing-id="{{ $incomeSource->id }}" data-editing-label="Business Report">
                    <div data-editing-presence-banner hidden role="status" class="mt-3 flex items-start gap-2 rounded-control border border-progress/30 bg-progress-soft p-3 text-sm text-progress">
                        <x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" />
                        <span data-editing-presence-text></span>
                    </div>
                </div>
            @endif

            @unless($incomeSource)
            @php
                $businessTemplatePreselected = filled($preselectedTemplateId) || filled(old('income_source_template_id'));
            @endphp
            <section class="business-template-chooser" aria-labelledby="business-template-title" data-business-template-title @if($businessTemplatePreselected) hidden @endif>
                @unless($businessTemplatePreselected)
                <div>
                    <h2 id="business-template-title">Please choose Business Template</h2>
                </div>
                @endunless
                <div class="business-template-actions">
                    <label for="income_source_template_id" class="sr-only">Business Template</label>
                    <select id="income_source_template_id" name="income_source_template_id" form="business-template-form" class="business-template-select" data-business-template-select required>
                        <option value="">Please choose Business Template</option>
                        @foreach($businessTemplates as $template)
                            <option value="{{ $template->id }}" @selected((string) old('income_source_template_id', $preselectedTemplateId) === (string) $template->id)>{{ $template->name }}</option>
                        @endforeach
                    </select>
                    @unless($businessTemplatePreselected)
                    <button type="submit" form="business-template-form" class="business-add-button" @disabled($businessTemplates->isEmpty())><span aria-hidden="true">+</span> Add Business</button>
                    @endunless
                </div>
                <x-form.validation-message for="income_source_template_id" />
            </section>
            @endunless

            <div data-business-template-preview-target hidden></div>
            <div data-current-business-form>
            @if(!$incomeSource)
                <section class="business-report-empty" aria-label="No Business Report selected">
                    <h2>No Business Report yet</h2>
                    <p>Choose a Business Template above to display its encoding fields immediately.</p>
                </section>
            @else
            <input type="hidden" name="source_name" value="{{ old('source_name', $incomeSource->source_name) }}">
            <input type="hidden" name="report_category" value="{{ old('report_category', $report?->report_category ?? $incomeSource->template->business_category) }}">

            @unless($hasActiveReport)
                <section class="business-report-empty" aria-label="No active Business Report">
                    <h2>No active Business Report</h2>
                    <p>Complete and save this form to recreate the Business Report for this same saved business.</p>
                </section>
            @endunless

            @include('client-folders.income-sources._business-form-body', [
                'incomeSource' => $incomeSource,
                'report' => $report,
                'template' => $incomeSource->template,
                'tags' => $tags,
                'propertyOptions' => $propertyOptions,
                'tenants' => $tenants,
                'officialTitle' => $officialTitle,
            ])

            @endif
            </div>
        </div>

        @if($incomeSource)
        </form>
        @else
        </div>
        @endif

    <x-ui.sticky-form-toolbar
        class="!bottom-3 !rounded-control !p-2.5"
        data-business-save-toolbar
        :hidden="$incomeSource === null"
    >
        <span class="sr-only">Business Report actions</span>
        {{-- Same action-bar convention as the Edit Business Check form: a secondary Cancel that
             closes the dialog this form is opened in, and a primary submit carrying the save icon.
             The label follows the one existing create/edit signal this page already uses —
             $hasActiveReport — so a brand-new report reads "Save" and an existing one "Update".
             The submit's form/name/value are untouched, so intent handling is exactly as before. --}}
        <x-slot:actions>
            <button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>
            <button type="submit" form="{{ $headerFormId }}" name="intent" value="complete" class="ui-button-primary" data-business-save><x-ui.icon name="check" size="size-4" />{{ $hasActiveReport ? 'Update Business Report' : 'Save Business Report' }}</button>
        </x-slot:actions>
    </x-ui.sticky-form-toolbar>

    @foreach($businessTemplates as $previewTemplate)
        @php
            $previewTitle = match ($previewTemplate->template_type) {
                'leasing_non_agricultural' => 'LEASING OPERATIONS: NON-AGRICULTURAL REAL ESTATE',
                'retail_grocery_water_refilling' => 'RETAIL: GROCERY STORE / SUPERMARKET / SARI-SARI STORE / WATER REFILLING',
                default => str($previewTemplate->name)->upper(),
            };
        @endphp
        <template data-business-template-preview="{{ $previewTemplate->id }}">
            @include('client-folders.income-sources._business-form-body', [
                'incomeSource' => null,
                'report' => null,
                'template' => $previewTemplate,
                'tags' => $previewTemplate->compatibility_tags ?? [],
                'propertyOptions' => [],
                'tenants' => collect(),
                'officialTitle' => $previewTitle,
            ])
        </template>
    @endforeach

    <x-ui.modal id="business-remove-entry-dialog" title="Remove this entry?" description="This row already contains information. Are you sure you want to remove it?" size="max-w-md" data-repeater-remove-dialog>
        <p class="text-sm text-text-muted">The entry will be removed when the Business Report is saved.</p>
        <x-slot:footer><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="button" class="ui-button-danger" data-repeater-remove-confirm>Remove</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="business-template-switch-dialog" title="Switch Business Template?" size="max-w-md" data-business-template-switch-dialog>
        <p class="text-sm leading-6 text-text-muted">You have unsaved data in the current Business Report. Switching templates may cause this data to be lost. Do you want to continue?</p>
        <x-slot:footer><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="button" class="ui-button-primary" data-business-template-switch-confirm>Continue</button></x-slot:footer>
    </x-ui.modal>

    <x-ui.modal id="business-companion-ci-dialog" title="Add Companion CI" description="Select one or more active Credit Investigators to add as companions on this Business Report." size="max-w-md" data-companion-ci-dialog>
        <label for="business-companion-ci-search" class="sr-only">Search Credit Investigator</label>
        <input type="search" id="business-companion-ci-search" class="ui-control mb-3" placeholder="Search by name..." autocomplete="off" data-companion-search>
        <div class="flex max-h-64 flex-col gap-2 overflow-y-auto pr-1" data-companion-option-list>
            @forelse($activeCreditInvestigators as $candidate)
                <label class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-control border border-ui-border bg-surface px-3.5 py-2 text-sm font-medium hover:border-brand-primary hover:bg-brand-soft" data-companion-option data-user-id="{{ $candidate->id }}" data-full-name="{{ $candidate->full_name }}" data-search-name="{{ mb_strtolower($candidate->full_name) }}">
                    <input type="checkbox" class="size-4 border-ui-border-strong text-brand-primary focus:ring-brand-primary" value="{{ $candidate->id }}" data-companion-checkbox>
                    <span>{{ $candidate->full_name }}</span>
                </label>
            @empty
                <p class="text-sm text-text-muted">No other active Credit Investigators available.</p>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-text-muted" data-companion-search-empty hidden>No matching Credit Investigator found.</p>
        <x-slot:footer>
            <button type="button" class="ui-button-secondary" data-modal-close>Cancel</button>
            <button type="button" class="ui-button-primary" data-companion-confirm>Add Selected</button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
