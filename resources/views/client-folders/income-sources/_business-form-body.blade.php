@php
    $schema = $template->businessReportSchema();
    $showBusinessProfile = empty($schema) || ($schema['profile'] ?? false);
    $hiddenProfileFields = $schema['hidden_profile_fields'] ?? [];
    $addressStatuses = ['Residence Only' => 'Residence Only', 'Owned' => 'Owned', 'Mortgaged' => 'Mortgaged From', 'Rented' => 'Rented From'];
    $savedAddressStatus = str(old('ownership_type', $report?->ownership_type))->replace(['_', '-'], ' ')->squish();
    $selectedAddressStatus = collect(array_keys($addressStatuses))->first(
        fn (string $status) => (string) str($status)->lower() === (string) $savedAddressStatus->lower()
    );
@endphp
<h2 class="business-report-template-title">{{ $officialTitle }}</h2>

@if($showBusinessProfile)
<section class="business-report-profile" aria-label="Business profile">
    <div class="business-excel-paired-row">
        <label class="ui-label" for="business_name">Business Name:</label>
        <div class="business-excel-row-control">
            <input id="business_name" class="ui-control" name="business_name" type="text" value="{{ old('business_name', $report?->business_name) }}" required>
            <x-form.validation-message for="business_name" />
        </div>
        <label class="ui-label" for="year_established">{{ in_array($template->template_type, ['taxi_operator', 'puj_van_jeepney_operator'], true) ? 'Years Operating:' : 'Year Established:' }}</label>
        <div class="business-excel-row-control">
            <input id="year_established" class="ui-control" name="year_established" type="number" min="1800" value="{{ old('year_established', $report?->year_established) }}">
            <x-form.validation-message for="year_established" />
        </div>
    </div>
    <div class="business-excel-paired-row business-main-address-row">
        <label class="ui-label" for="main_business_address">{{ in_array($template->template_type, ['trucking_services', 'distributorship_wholesaler_b2b'], true) ? 'Garage/ Office Address:' : 'Main Business Address:' }}</label>
        <div class="business-excel-row-control">
            <input id="main_business_address" class="ui-control" name="main_business_address" type="text" value="{{ old('main_business_address', $report?->main_business_address) }}">
            <x-form.validation-message for="main_business_address" />
        </div>
        <label class="ui-label" for="length_of_stay_months">Length of Stay:</label>
        <div class="business-excel-row-control business-main-address-stay">
            <input id="length_of_stay_months" class="ui-control" name="length_of_stay_months" type="text" value="{{ old('length_of_stay_months', $report?->length_of_stay_months) }}">
            <x-form.validation-message for="length_of_stay_months" />
        </div>
    </div>
    <div class="business-address-status">
        <fieldset>
            <legend class="sr-only">Business Address Status</legend>
            <div class="business-address-status-row">
                <span class="business-address-status-title" aria-hidden="true">Business Address Status:</span>
                @foreach($addressStatuses as $addressStatus => $addressStatusLabel)
                    <label class="business-report-choice-option" for="ownership_type_{{ str($addressStatus)->slug('_') }}">
                        <input
                            id="ownership_type_{{ str($addressStatus)->slug('_') }}"
                            class="business-report-checkbox"
                            name="ownership_type"
                            type="radio"
                            value="{{ $addressStatus }}"
                            data-business-address-status
                            @checked($selectedAddressStatus === $addressStatus)
                        >
                        <span>{{ strtoupper($addressStatusLabel) }}</span>
                    </label>
                @endforeach
                <label class="sr-only" for="rented_from">Mortgagee or lessor</label>
                <input id="rented_from" class="ui-control business-address-inline-input business-address-rented-from" name="rented_from" type="text" value="{{ old('rented_from', $report?->rented_from) }}" placeholder="Specify Mortgagee/Lessor" data-business-address-from @disabled(!in_array($selectedAddressStatus, ['Mortgaged', 'Rented'], true))>
                <span class="business-address-status-divider" aria-hidden="true">|</span>
                <label class="ui-label business-address-inline-label" for="monthly_rent">PHP MONTHLY RENT:</label>
                <input id="monthly_rent" class="ui-control business-address-inline-input business-address-monthly-rent" name="monthly_rent" type="text" value="{{ old('monthly_rent', $report?->monthly_rent) }}" data-business-monthly-rent @disabled($selectedAddressStatus !== 'Rented')>
            </div>
            <x-form.validation-message for="ownership_type" />
            <x-form.validation-message for="rented_from" />
            <x-form.validation-message for="monthly_rent" />
        </fieldset>
    </div>
    @unless(in_array('previous_business_address', $hiddenProfileFields, true))
    <div class="business-excel-paired-row business-previous-address-row">
        <label class="ui-label" for="previous_business_address">Previous Business Address</label>
        <div class="business-excel-row-control">
            <input id="previous_business_address" class="ui-control" name="previous_business_address" type="text" value="{{ old('previous_business_address', $report?->previous_business_address) }}">
            <x-form.validation-message for="previous_business_address" />
        </div>
        <label class="ui-label" for="previous_business_address_length_of_stay">Length of Stay:</label>
        <div class="business-excel-row-control business-previous-address-stay">
            <input id="previous_business_address_length_of_stay" class="ui-control" name="previous_business_address_length_of_stay" type="text" value="{{ old('previous_business_address_length_of_stay', $report?->previous_business_address_length_of_stay) }}">
            <x-form.validation-message for="previous_business_address_length_of_stay" />
        </div>
    </div>
    @endunless
    @unless(in_array('reason_for_transfer', $hiddenProfileFields, true))
    <div class="business-excel-paired-row">
        <label class="ui-label" for="reason_for_transfer">Reason for Transfer:</label>
        <div class="business-excel-row-control">
            <input id="reason_for_transfer" class="ui-control" name="reason_for_transfer" type="text" value="{{ old('reason_for_transfer', $report?->reason_for_transfer) }}">
            <x-form.validation-message for="reason_for_transfer" />
        </div>
        <label class="ui-label" for="informant">Informant:</label>
        <div class="business-excel-row-control">
            <input id="informant" class="ui-control" name="informant" type="text" value="{{ old('informant', $report?->informant) }}">
            <x-form.validation-message for="informant" />
        </div>
    </div>
    @endunless
    @unless(in_array('registered_owner', $hiddenProfileFields, true))
    <div class="business-excel-paired-row business-owner-relationship-row">
        <label class="ui-label" for="registered_owner">Registered Owner:</label>
        <div class="business-excel-row-control">
            <input id="registered_owner" class="ui-control" name="registered_owner" type="text" value="{{ old('registered_owner', $report?->registered_owner) }}" required>
            <x-form.validation-message for="registered_owner" />
        </div>
        @if($template->template_type === 'leasing_non_agricultural')
        <span class="ui-label">Leasing Business Registration Status:</span>
        <div class="business-excel-row-control business-report-choice-group" role="radiogroup" aria-label="Leasing Business Registration Status">
            @foreach(['Registered' => 'Leasing Business Registered', 'Not Registered' => 'Leasing Business Not Registered'] as $value => $label)
                <label class="business-report-choice-option"><input class="business-report-checkbox" name="business_type" type="radio" value="{{ $value }}" @checked(old('business_type', $report?->business_type) === $value)> <span>{{ $label }}</span></label>
            @endforeach
            <x-form.validation-message for="business_type" />
        </div>
        <input type="hidden" name="relationship_to_borrower" value="{{ old('relationship_to_borrower', $report?->relationship_to_borrower) }}">
        @else
        <label class="ui-label" for="relationship_to_borrower">If Registered Owner Is Not Borrower, Relationship:</label>
        <div class="business-excel-row-control">
            <input id="relationship_to_borrower" class="ui-control" name="relationship_to_borrower" type="text" value="{{ old('relationship_to_borrower', $report?->relationship_to_borrower) }}">
            <x-form.validation-message for="relationship_to_borrower" />
        </div>
        @endif
    </div>
    @endunless
    @if($template->template_type === 'retail_grocery_water_refilling')
    <div class="business-excel-full-value-row">
        <span class="ui-label">Scale of Business:</span>
        <div class="business-excel-row-control business-report-choice-group" role="radiogroup" aria-label="Scale of Business">
            @foreach(['Sari-Sari Store', 'Grocery Store', 'Convenience Store', 'Supermarket', 'Water Refilling'] as $scaleOption)
                <label class="business-report-choice-option"><input class="business-report-checkbox" name="scale" type="radio" value="{{ $scaleOption }}" @checked(old('scale', $report?->scale) === $scaleOption)> <span>{{ strtoupper($scaleOption) }}</span></label>
            @endforeach
            <x-form.validation-message for="scale" />
        </div>
    </div>
    <input type="hidden" name="business_type" value="{{ old('business_type', $report?->business_type) }}">
    @else
    @unless($template->template_type === 'leasing_non_agricultural')
    <input type="hidden" name="business_type" value="{{ old('business_type', $report?->business_type) }}">
    @endunless
    <input type="hidden" name="scale" value="{{ old('scale', $report?->scale) }}">
    @endif
</section>
@else
    <input type="hidden" name="business_name" value="{{ old('business_name', $report?->business_name ?: $template->name) }}">
@endif

@if(!empty($schema))
    @include('client-folders.income-sources._business-template-schema')
@else
@if($template->template_type === 'leasing_non_agricultural')
    @include('client-folders.income-sources._business-non-agricultural-properties', ['records' => $report?->properties ?? collect()])
@else
    @if(in_array('properties', $tags, true))
        @include('client-folders.income-sources._business-repeater', ['section' => 'properties', 'title' => 'Summary of Properties Inspected', 'description' => 'Non-agricultural real-estate properties declared and inspected.', 'records' => $report?->properties ?? collect(), 'fields' => [
            ['name' => 'property_type', 'label' => 'Type of Real Estate'], ['name' => 'is_declared', 'label' => 'Declared?', 'type' => 'checkbox'], ['name' => 'is_inspected', 'label' => 'Inspected?', 'type' => 'checkbox'], ['name' => 'units_available', 'label' => 'Total Units Available', 'type' => 'number'], ['name' => 'units_with_tenants', 'label' => 'Units With Tenants', 'type' => 'number'], ['name' => 'location', 'label' => 'Location'], ['name' => 'area_square_meters', 'label' => 'Total SQM', 'type' => 'number', 'step' => '0.01'], ['name' => 'has_contract', 'label' => 'With Contract?', 'type' => 'checkbox'], ['name' => 'reason_not_inspected', 'label' => 'Reason Not Inspected'], ['name' => 'remarks', 'label' => 'Relevant Information'],
        ]])
    @endif
    @if(in_array('tenants', $tags, true))
        @include('client-folders.income-sources._business-repeater', ['section' => 'tenants', 'title' => 'Tenants', 'description' => $propertyOptions ? 'Link each tenant to a saved property in this report.' : 'Save at least one property before adding tenant information.', 'records' => $tenants, 'fields' => [
            ['name' => 'business_property_id', 'label' => 'Saved Property', 'type' => 'select', 'options' => $propertyOptions], ['name' => 'tenant_name', 'label' => 'Tenant Name'], ['name' => 'monthly_rent', 'label' => 'Monthly Rent', 'type' => 'number', 'step' => '0.01'], ['name' => 'years_renting', 'label' => 'Years Renting', 'type' => 'number', 'step' => '0.01'], ['name' => 'has_contract', 'label' => 'With Contract?', 'type' => 'checkbox'], ['name' => 'contact_details', 'label' => 'Contact Details'], ['name' => 'remarks', 'label' => 'Relevant Information'],
        ]])
    @endif
@endif
@if($template->template_type === 'retail_grocery_water_refilling')
    @include('client-folders.income-sources._business-retail-summary')
@endif
@if(in_array('branches', $tags, true))
    @php
        $branchFields = $template->template_type === 'retail_grocery_water_refilling' ? [
            ['name' => 'location', 'label' => 'LOCATION'], ['name' => 'frontage_meters', 'label' => 'FRONT (SQM)', 'type' => 'number', 'step' => '0.01'], ['name' => 'total_area_square_meters', 'label' => 'TOTAL SQM', 'type' => 'number', 'step' => '0.01'], ['name' => 'is_air_conditioned', 'label' => 'AIRCON (Y/N)', 'format' => 'yes_no'], ['name' => 'operating_days_hours', 'label' => 'OPERATING DAYS & HOURS'], ['name' => 'shifts_count', 'label' => '# OF SHIFTS', 'type' => 'number'], ['name' => 'employees_per_shift', 'label' => '# OF EMPLOYEES PER SHIFT', 'type' => 'number'], ['name' => 'average_sales_per_shift', 'label' => 'AVE. PHP SALES PER SHIFT', 'type' => 'number', 'step' => '0.01'], ['name' => 'inventory_level', 'label' => 'INVENTORY LEVEL (HIGH, MID, LOW)'], ['name' => 'monthly_rent', 'label' => 'RENT PER MONTH', 'type' => 'number', 'step' => '0.01'], ['name' => 'years_in_area', 'label' => 'YEARS IN THE AREA', 'type' => 'number', 'step' => '0.01'], ['name' => 'nearby_brands', 'label' => 'BIG BRANDS NEAR THE AREA?'],
        ] : [
            ['name' => 'location', 'label' => 'Location'], ['name' => 'is_declared', 'label' => 'Declared?', 'type' => 'checkbox'], ['name' => 'is_inspected', 'label' => 'Inspected?', 'type' => 'checkbox'], ['name' => 'frontage_meters', 'label' => 'Front (M)', 'type' => 'number', 'step' => '0.01'], ['name' => 'total_area_square_meters', 'label' => 'Total SQM', 'type' => 'number', 'step' => '0.01'], ['name' => 'is_air_conditioned', 'label' => 'Aircon?', 'type' => 'checkbox'], ['name' => 'operating_days_hours', 'label' => 'Operating Days & Hours'], ['name' => 'shifts_count', 'label' => 'No. of Shifts', 'type' => 'number'], ['name' => 'employees_per_shift', 'label' => 'Employees per Shift', 'type' => 'number'], ['name' => 'average_sales_per_shift', 'label' => 'Average PHP Sales per Shift', 'type' => 'number', 'step' => '0.01'], ['name' => 'inventory_level', 'label' => 'Inventory Level'], ['name' => 'monthly_rent', 'label' => 'Rent per Month', 'type' => 'number', 'step' => '0.01'], ['name' => 'years_in_area', 'label' => 'Years in Area', 'type' => 'number', 'step' => '0.01'], ['name' => 'nearby_brands', 'label' => 'Big Brands Near the Area'], ['name' => 'reason_not_inspected', 'label' => 'Reason Not Inspected'],
        ];
    @endphp
    @include('client-folders.income-sources._business-repeater', ['section' => 'branches', 'title' => 'Summary of Branches Inspected', 'records' => $report?->branches ?? collect(), 'fields' => $branchFields, 'defaultRows' => $template->template_type === 'retail_grocery_water_refilling' ? 3 : 1])
@endif
@if(in_array('products', $tags, true))
    @if($template->template_type === 'retail_grocery_water_refilling')
        @include('client-folders.income-sources._business-retail-products-observations')
    @else
    @include('client-folders.income-sources._business-repeater', ['section' => 'products', 'title' => 'Products - Top Sellable Products', 'records' => $report?->products ?? collect(), 'fields' => [
        ['name' => 'product_name', 'label' => 'Product Name'], ['name' => 'unit_size', 'label' => 'Unit / Size'], ['name' => 'selling_price', 'label' => 'Selling Price per Item', 'type' => 'number', 'step' => '0.01'], ['name' => 'stock_level', 'label' => 'Stock Level'], ['name' => 'is_top_seller', 'label' => 'Top Seller?', 'type' => 'checkbox'],
    ]])
    @endif
@endif
@if(in_array('observations', $tags, true) && $template->template_type !== 'retail_grocery_water_refilling')
    @include('client-folders.income-sources._business-repeater', ['section' => 'observations', 'title' => 'Business Observations', 'records' => $report?->observations ?? collect(), 'fields' => [
        ['name' => 'observation_code', 'label' => 'Code'], ['name' => 'question_snapshot', 'label' => 'Question / Observation', 'type' => 'textarea'], ['name' => 'answer', 'label' => 'Validated Answer', 'type' => 'textarea'], ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
    ]])
@endif
@if(in_array('competitors', $tags, true) && $template->template_type !== 'retail_grocery_water_refilling')
    @include('client-folders.income-sources._business-repeater', ['section' => 'competitors', 'title' => 'Nearby Competitors', 'records' => $report?->competitors ?? collect(), 'fields' => [
        ['name' => 'name', 'label' => 'Competitor Name'], ['name' => 'location', 'label' => 'Location'], ['name' => 'notes', 'label' => 'Relevant Observations', 'type' => 'textarea'],
    ]])
@endif
@if(in_array('suppliers', $tags, true))
    @php
        $supplierFields = $template->template_type === 'retail_grocery_water_refilling' ? [
            ['name' => 'supplier_name', 'label' => 'SUPPLIER NAME'], ['name' => 'office_location', 'label' => 'OFFICE LOCATION'], ['name' => 'is_confirmed', 'label' => 'CONFIMRED', 'guide' => '(Y/N)', 'format' => 'yes_no'], ['name' => 'payment_performance', 'label' => 'IMPORTANT REMARKS (CONTACT INFORMATION, YEARS TRANSACTING, BAD / GOOD PAYMENT PERFORMANCE, ETC.)'],
        ] : [
            ['name' => 'supplier_name', 'label' => 'Supplier Name'], ['name' => 'office_location', 'label' => 'Office Location'], ['name' => 'is_confirmed', 'label' => 'Confirmed?', 'type' => 'checkbox'], ['name' => 'contact_information', 'label' => 'Contact Information'], ['name' => 'years_transacting', 'label' => 'Years Transacting', 'type' => 'number', 'step' => '0.01'], ['name' => 'payment_performance', 'label' => 'Payment Performance'], ['name' => 'remarks', 'label' => 'Important Remarks', 'type' => 'textarea'],
        ];
    @endphp
    @include('client-folders.income-sources._business-repeater', ['section' => 'suppliers', 'title' => $template->template_type === 'retail_grocery_water_refilling' ? 'Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):' : 'Suppliers - Validation of Top Sellable Product Suppliers', 'records' => $report?->suppliers ?? collect(), 'fields' => $supplierFields, 'defaultRows' => $template->template_type === 'retail_grocery_water_refilling' ? 3 : 1, 'tableClass' => $template->template_type === 'retail_grocery_water_refilling' ? 'business-pharmacy-suppliers-table' : null])
@endif
@endif

<section class="business-report-section business-report-remarks">
    <h2>{{ $template->template_type === 'other_business_source_of_income' ? 'BUSINESS / INCOME SOURCE DETAILS' : 'OTHER REMARKS' }}</h2>
    <div>
        <label for="report_remarks" class="sr-only">{{ $template->template_type === 'other_business_source_of_income' ? 'Business or income source details' : 'Other Remarks' }}</label>
        <textarea id="report_remarks" name="report_remarks" rows="{{ $template->template_type === 'other_business_source_of_income' ? 8 : 5 }}" class="ui-control">{{ old('report_remarks', $report?->report_remarks) }}</textarea>
        <x-form.validation-message for="report_remarks" />
    </div>
</section>

<input type="hidden" name="contribution_rank" value="{{ old('contribution_rank', $incomeSource?->contribution_rank) }}">
<input type="hidden" name="estimated_monthly_contribution" value="{{ old('estimated_monthly_contribution', $incomeSource?->estimated_monthly_contribution) }}">
<input type="hidden" name="is_primary" value="{{ old('is_primary', $incomeSource?->is_primary ? '1' : '0') }}">
