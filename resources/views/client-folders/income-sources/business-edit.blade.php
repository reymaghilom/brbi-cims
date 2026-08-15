@extends('layouts.business-encoding')

@section('title', 'Business Report · '.$clientFolder->display_name)

@php
    $report = $incomeSource?->businessReport;
    $cibiReport = $clientFolder->cibiReport;
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
    $partyType = $cibiReport?->party_type?->value ?? 'borrower';
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
    <form id="business-template-form" method="POST" action="{{ route('client-folders.income-sources.store', $clientFolder) }}" hidden>@csrf<input type="hidden" name="intent" value="complete"></form>

    @if($incomeSource)
        <form id="business-report-form" method="POST" action="{{ route('client-folders.income-sources.business.update', [$clientFolder, $incomeSource]) }}" class="business-encoding-page" data-business-report-form data-unsaved-form>
            @csrf
            @method('PUT')
    @else
        <div class="business-encoding-page" data-business-report-form>
    @endif

        @if($errors->any())
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
                    <div class="business-report-header-value business-report-header-readonly">{{ $clientFolder->assignedInvestigator->full_name }}</div>
                    <label class="business-report-header-label" for="branch_name">BRANCH:</label>
                    <div class="business-report-header-value"><input id="branch_name" name="branch_name" form="{{ $headerFormId }}" value="{{ old('branch_name', $headerBranch) }}" class="business-report-header-control" readonly aria-readonly="true" @error('branch_name') aria-invalid="true" aria-describedby="branch_name-error" @enderror><x-form.validation-message for="branch_name" /></div>

                    <label class="business-report-header-label" for="start_date">START DATE OF CI:</label>
                    <div class="business-report-header-value"><input id="start_date" name="start_date" form="{{ $headerFormId }}" type="date" value="{{ old('start_date', $report?->start_date?->format('Y-m-d')) }}" class="business-report-header-control" @error('start_date') aria-invalid="true" aria-describedby="start_date-error" @enderror><x-form.validation-message for="start_date" /></div>
                    <div class="business-report-header-label">NAME OF APPLICANT:</div>
                    <div class="business-report-header-value business-report-header-readonly">{{ $clientFolder->display_name }}</div>

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

            <section class="business-template-chooser" aria-labelledby="business-template-title" data-business-template-title>
                <div>
                    <h2 id="business-template-title">Please choose Business Template</h2>
                </div>
                <div class="business-template-actions">
                    <label for="income_source_template_id" class="sr-only">Business Template</label>
                    <select id="income_source_template_id" name="income_source_template_id" form="business-template-form" class="business-template-select" data-business-template-select required>
                        <option value="">Please choose Business Template</option>
                        @foreach($businessTemplates as $template)
                            <option value="{{ $template->id }}" @selected((string) old('income_source_template_id') === (string) $template->id)>{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" form="business-template-form" class="business-add-button" @disabled($businessTemplates->isEmpty())><span aria-hidden="true">+</span> Add Business</button>
                </div>
                <x-form.validation-message for="income_source_template_id" />
            </section>

            <div data-business-template-preview-target hidden></div>
            <div data-current-business-form>
            @if(!$incomeSource)
                <section class="business-report-empty" aria-label="No Business Report selected">
                    <h2>No Business Report yet</h2>
                    <p>Choose a Business Template above to display its encoding fields immediately.</p>
                </section>
            @else
            <input type="hidden" name="source_name" value="{{ old('source_name', $incomeSource->source_name) }}">
            <input type="hidden" name="report_category" value="{{ old('report_category', $report->report_category) }}">

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
        <x-slot:actions><button type="submit" form="{{ $headerFormId }}" name="intent" value="complete" class="ui-button-primary" data-business-save>Save</button></x-slot:actions>
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
@endsection
