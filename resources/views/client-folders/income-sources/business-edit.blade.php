@extends('layouts.business-encoding')

@section('title', 'Business Report · '.$clientFolder->display_name)

@php
    $report = $incomeSource->businessReport;
    $tags = $incomeSource->template->compatibility_tags ?? [];
    $propertyOptions = $report->properties->mapWithKeys(fn ($property) => [$property->id => $property->property_type . ($property->location ? ' - ' . str($property->location)->limit(50) : '')])->all();
    $tenants = $report->properties->pluck('tenants')->flatten();
    $officialTitle = match ($incomeSource->template_type) {
        'leasing_non_agricultural' => 'LEASING OPERATIONS: NON-AGRICULTURAL REAL ESTATE',
        'retail_grocery_water_refilling' => 'RETAIL: GROCERY STORE / SUPERMARKET / SARI-SARI STORE / WATER REFILLING',
        default => str($incomeSource->template->name)->upper(),
    };
@endphp

@section('content')
    <section class="business-selector" aria-labelledby="business-selector-title" data-business-selector>
        <div class="business-selector-heading">
            <div>
                <h1 id="business-selector-title">Business Reports</h1>
                <p>{{ $clientFolder->display_name }} <span aria-hidden="true">&middot;</span> Select a business to encode its independent report.</p>
            </div>
            <form method="POST" action="{{ route('client-folders.income-sources.businesses.store', [$clientFolder, $incomeSource]) }}">
                @csrf
                <button type="submit" class="business-add-button" aria-label="Add another business report">
                    <span aria-hidden="true">+</span> Add Business
                </button>
            </form>
        </div>

        <div class="business-selector-tabs" role="tablist" aria-label="Businesses for {{ $clientFolder->display_name }}">
            @foreach($businesses as $business)
                @php
                    $businessLabel = filled($business->businessReport?->business_name)
                        ? $business->businessReport->business_name
                        : (filled($business->source_name) ? $business->source_name : 'Business '.$loop->iteration);
                @endphp
                <a
                    href="{{ route('client-folders.income-sources.edit', [$clientFolder, $business]) }}"
                    class="business-selector-tab {{ $business->is($incomeSource) ? 'is-active' : '' }}"
                    role="tab"
                    aria-selected="{{ $business->is($incomeSource) ? 'true' : 'false' }}"
                    @if($business->is($incomeSource)) aria-current="page" @endif
                >
                    <span>{{ $businessLabel }}</span>
                    <small>{{ $business->template->name }}</small>
                </a>
            @endforeach
        </div>
    </section>

    <form id="business-report-form" method="POST" action="{{ route('client-folders.income-sources.business.update', [$clientFolder, $incomeSource]) }}" class="business-encoding-page" data-business-report-form data-unsaved-form>
        @csrf
        @method('PUT')

        @if($errors->any())
            <div class="mb-3 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert" tabindex="-1">
                <strong>Please correct the highlighted Business Report fields.</strong> No changes were saved.
            </div>
        @endif

        <div class="business-report-paper">
            <header class="business-report-official-header">
                <div>
                    <div class="business-report-title-line"><h1>CREDIT INVESTIGATION REPORT</h1><strong>INDIVIDUAL ACCOUNT</strong></div>
                    <p class="business-report-scope">(SOURCE OF INCOME VALIDATION)</p>
                    <p class="business-report-confidential">RESTRICTED &amp; CONFIDENTIAL</p>
                </div>
                <img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc.">
            </header>

            <section class="business-report-metadata" aria-label="Business Report details">
                <div><span class="business-report-label">CI-IN CHARGE</span><p class="business-report-readonly">{{ $clientFolder->assignedInvestigator->full_name }}</p></div>
                <x-form.input name="branch_name" label="Branch" :value="$incomeSource->branch_name" />
                <div><span class="business-report-label">Name of Applicant</span><p class="business-report-readonly">{{ $clientFolder->display_name }}</p></div>
                <x-form.input name="account_officer_name" label="Account Officer" :value="$incomeSource->account_officer_name" />
                <x-form.input name="amount_applied" label="Amount Applied" type="number" step="0.01" min="0" :value="$incomeSource->amount_applied" />
                <x-form.input name="source_name" label="Income Source Name" :value="$incomeSource->source_name" required />
                <x-form.input name="report_category" label="Report Category" :value="$report->report_category" required />
                <div><span class="business-report-label">Official Template</span><p class="business-report-readonly">{{ $incomeSource->template->name }} · Version {{ $incomeSource->template_version }}</p></div>
            </section>

            <h2 class="business-report-template-title">{{ $officialTitle }}</h2>

            <section class="business-report-profile" aria-label="Business profile">
                <x-form.input name="business_name" label="Business Name" :value="$report->business_name" required />
                <x-form.input name="year_established" label="Year Established" type="number" min="1800" :value="$report->year_established" />
                <x-form.input name="main_business_address" label="Main Business Address" :value="$report->main_business_address" class="business-wide-field" />
                <x-form.input name="length_of_stay_months" label="Length of Stay (Months)" type="number" min="0" :value="$report->length_of_stay_months" />
                <x-form.select name="ownership_type" label="Business Address Status" :options="['Residence Only' => 'Residence Only', 'Owned' => 'Owned', 'Mortgaged' => 'Mortgaged', 'Rented' => 'Rented']" :selected="$report->ownership_type" placeholder="Select status" />
                <x-form.input name="monthly_rent" label="PHP Monthly Rent" type="number" step="0.01" min="0" :value="$report->monthly_rent" />
                <x-form.input name="previous_business_address" label="Previous Business Address" :value="$report->previous_business_address" class="business-wide-field" />
                <x-form.input name="reason_for_transfer" label="Reason for Transfer" :value="$report->reason_for_transfer" />
                <x-form.input name="informant" label="Informant" :value="$report->informant" />
                <x-form.input name="registered_owner" label="Registered Owner" :value="$report->registered_owner" required />
                <x-form.input name="relationship_to_borrower" label="If Registered Owner Is Not Borrower, Relationship" :value="$report->relationship_to_borrower" class="business-wide-field" />
                <x-form.input name="business_type" label="Business Type" :value="$report->business_type" />
                <x-form.input name="scale" label="Scale of Business" :value="$report->scale" />
            </section>

            @if(in_array('properties', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'properties', 'title' => 'Summary of Properties Inspected', 'description' => 'Non-agricultural real-estate properties declared and inspected.', 'records' => $report->properties, 'fields' => [
                    ['name' => 'property_type', 'label' => 'Type of Real Estate'], ['name' => 'is_declared', 'label' => 'Declared?', 'type' => 'checkbox'], ['name' => 'is_inspected', 'label' => 'Inspected?', 'type' => 'checkbox'], ['name' => 'units_available', 'label' => 'Total Units Available', 'type' => 'number'], ['name' => 'units_with_tenants', 'label' => 'Units With Tenants', 'type' => 'number'], ['name' => 'location', 'label' => 'Location'], ['name' => 'area_square_meters', 'label' => 'Total SQM', 'type' => 'number', 'step' => '0.01'], ['name' => 'has_contract', 'label' => 'With Contract?', 'type' => 'checkbox'], ['name' => 'reason_not_inspected', 'label' => 'Reason Not Inspected'], ['name' => 'remarks', 'label' => 'Relevant Information'],
                ]])
            @endif
            @if(in_array('tenants', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'tenants', 'title' => 'Tenants', 'description' => $propertyOptions ? 'Link each tenant to a saved property in this report.' : 'Save at least one property before adding tenant information.', 'records' => $tenants, 'fields' => [
                    ['name' => 'business_property_id', 'label' => 'Saved Property', 'type' => 'select', 'options' => $propertyOptions], ['name' => 'tenant_name', 'label' => 'Tenant Name'], ['name' => 'monthly_rent', 'label' => 'Monthly Rent', 'type' => 'number', 'step' => '0.01'], ['name' => 'years_renting', 'label' => 'Years Renting', 'type' => 'number', 'step' => '0.01'], ['name' => 'has_contract', 'label' => 'With Contract?', 'type' => 'checkbox'], ['name' => 'contact_details', 'label' => 'Contact Details'], ['name' => 'remarks', 'label' => 'Relevant Information'],
                ]])
            @endif
            @if(in_array('branches', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'branches', 'title' => 'Summary of Branches Inspected', 'records' => $report->branches, 'fields' => [
                    ['name' => 'location', 'label' => 'Location'], ['name' => 'is_declared', 'label' => 'Declared?', 'type' => 'checkbox'], ['name' => 'is_inspected', 'label' => 'Inspected?', 'type' => 'checkbox'], ['name' => 'frontage_meters', 'label' => 'Front (M)', 'type' => 'number', 'step' => '0.01'], ['name' => 'total_area_square_meters', 'label' => 'Total SQM', 'type' => 'number', 'step' => '0.01'], ['name' => 'is_air_conditioned', 'label' => 'Aircon?', 'type' => 'checkbox'], ['name' => 'operating_days_hours', 'label' => 'Operating Days & Hours'], ['name' => 'shifts_count', 'label' => 'No. of Shifts', 'type' => 'number'], ['name' => 'employees_per_shift', 'label' => 'Employees per Shift', 'type' => 'number'], ['name' => 'average_sales_per_shift', 'label' => 'Average PHP Sales per Shift', 'type' => 'number', 'step' => '0.01'], ['name' => 'inventory_level', 'label' => 'Inventory Level'], ['name' => 'monthly_rent', 'label' => 'Rent per Month', 'type' => 'number', 'step' => '0.01'], ['name' => 'years_in_area', 'label' => 'Years in Area', 'type' => 'number', 'step' => '0.01'], ['name' => 'nearby_brands', 'label' => 'Big Brands Near the Area'], ['name' => 'reason_not_inspected', 'label' => 'Reason Not Inspected'],
                ]])
            @endif
            @if(in_array('products', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'products', 'title' => 'Products - Top Sellable Products', 'records' => $report->products, 'fields' => [
                    ['name' => 'product_name', 'label' => 'Product Name'], ['name' => 'unit_size', 'label' => 'Unit / Size'], ['name' => 'selling_price', 'label' => 'Selling Price per Item', 'type' => 'number', 'step' => '0.01'], ['name' => 'stock_level', 'label' => 'Stock Level'], ['name' => 'is_top_seller', 'label' => 'Top Seller?', 'type' => 'checkbox'],
                ]])
            @endif
            @if(in_array('observations', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'observations', 'title' => 'Business Observations', 'records' => $report->observations, 'fields' => [
                    ['name' => 'observation_code', 'label' => 'Code'], ['name' => 'question_snapshot', 'label' => 'Question / Observation', 'type' => 'textarea'], ['name' => 'answer', 'label' => 'Validated Answer', 'type' => 'textarea'], ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
                ]])
            @endif
            @if(in_array('competitors', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'competitors', 'title' => 'Nearby Competitors', 'records' => $report->competitors, 'fields' => [
                    ['name' => 'name', 'label' => 'Competitor Name'], ['name' => 'location', 'label' => 'Location'], ['name' => 'notes', 'label' => 'Relevant Observations', 'type' => 'textarea'],
                ]])
            @endif
            @if(in_array('suppliers', $tags, true))
                @include('client-folders.income-sources._business-repeater', ['section' => 'suppliers', 'title' => 'Suppliers - Validation of Top Sellable Product Suppliers', 'records' => $report->suppliers, 'fields' => [
                    ['name' => 'supplier_name', 'label' => 'Supplier Name'], ['name' => 'office_location', 'label' => 'Office Location'], ['name' => 'is_confirmed', 'label' => 'Confirmed?', 'type' => 'checkbox'], ['name' => 'contact_information', 'label' => 'Contact Information'], ['name' => 'years_transacting', 'label' => 'Years Transacting', 'type' => 'number', 'step' => '0.01'], ['name' => 'payment_performance', 'label' => 'Payment Performance'], ['name' => 'remarks', 'label' => 'Important Remarks', 'type' => 'textarea'],
                ]])
            @endif

            <section class="business-report-section business-report-remarks">
                <h2>OTHER REMARKS</h2>
                <x-form.textarea name="report_remarks" label="Other Remarks" :value="$report->report_remarks" rows="5" />
            </section>

            <section class="business-report-contribution" aria-label="Income source contribution">
                <h2>INCOME SOURCE CONTRIBUTION</h2>
                <div class="business-report-contribution-grid">
                    <x-form.input name="contribution_rank" label="Contribution Rank" type="number" min="1" :value="$incomeSource->contribution_rank" />
                    <x-form.input name="estimated_monthly_contribution" label="Estimated Monthly Contribution" type="number" step="0.01" min="0" :value="$incomeSource->estimated_monthly_contribution" />
                    <label class="business-report-primary-source"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1" @checked(old('is_primary', $incomeSource->is_primary))> Primary income source</label>
                </div>
            </section>
        </div>

        <x-ui.sticky-form-toolbar class="business-report-toolbar !bottom-3 !rounded-control !p-2.5">
            <span>Revision {{ $incomeSource->revision }} · Official outputs use the latest saved data.</span>
            <x-slot:actions>
                <button type="submit" name="intent" value="return" class="ui-button-secondary">Save and Return</button>
                <button type="submit" name="intent" value="stay" class="ui-button-secondary">Save Draft</button>
                <button type="submit" name="intent" value="complete" class="ui-button-primary">Save and Mark Complete</button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>

    <x-ui.modal id="business-remove-entry-dialog" title="Remove this entry?" description="This row already contains information. Are you sure you want to remove it?" size="max-w-md" data-repeater-remove-dialog>
        <p class="text-sm text-text-muted">The entry will be removed when the Business Report is saved.</p>
        <x-slot:footer><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="button" class="ui-button-danger" data-repeater-remove-confirm>Remove</button></x-slot:footer>
    </x-ui.modal>
@endsection
