@php
    $propertyRows = old('properties');
    if ($propertyRows === null) {
        $propertyRows = $records->map(fn ($property) => [
            'id' => $property->id,
            'property_type' => $property->property_type,
            'is_declared' => $property->is_declared,
            'is_inspected' => $property->is_inspected,
            'reason_not_inspected' => $property->reason_not_inspected,
            'units_available' => $property->units_available,
            'units_with_tenants' => $property->units_with_tenants,
            'location' => $property->location,
            'area_square_meters' => $property->area_square_meters,
            'has_contract' => $property->has_contract,
            'remarks' => $property->remarks,
        ])->all();
    }
    $propertyRows = count((array) $propertyRows) ? array_values($propertyRows) : array_fill(0, 3, []);

    $hasSavedProperties = $records->isNotEmpty();
    $declaredCount = $hasSavedProperties ? $report?->properties_declared : null;
    $inspectedCount = $hasSavedProperties ? $report?->properties_inspected : null;
    $notInspectedCount = $hasSavedProperties ? max(0, (int) $declaredCount - (int) $inspectedCount) : null;
    $notInspectedReason = $records->where('is_inspected', false)->pluck('reason_not_inspected')->filter()->implode('; ');
@endphp

<section class="business-report-section business-non-agricultural-properties scroll-mt-4" data-repeater="properties" data-empty-row-remove-without-confirmation>
    <div class="business-property-summary" aria-label="Property inspection totals">
        <label><span class="ui-label">TOTAL PROPERTIES DECLARED:</span><input class="ui-control" name="properties_declared" type="number" min="0" value="{{ old('properties_declared', $declaredCount) }}"></label>
        <label><span class="ui-label">TOTAL PROPERTIES INSPECTED:</span><input class="ui-control" name="properties_inspected" type="number" min="0" value="{{ old('properties_inspected', $inspectedCount) }}"></label>
        <label><span class="ui-label">TOTAL PROP NOT INSPECTED:</span><input class="ui-control" name="properties_not_inspected" type="number" min="0" value="{{ old('properties_not_inspected', $notInspectedCount) }}"></label>
        <label class="business-property-summary-reason"><span class="ui-label">REASON NOT INSPECTED:</span><input class="ui-control" name="properties_reason_not_inspected" type="text" value="{{ old('properties_reason_not_inspected', $notInspectedReason) }}" data-property-summary-reason></label>
    </div>

    <header class="business-report-subheading business-non-agricultural-heading">
        <div><h2>Summary of Properties Inspected</h2></div>
        <button type="button" class="business-add-entry" data-repeater-add>+ Add Row</button>
    </header>

    <div class="business-report-table-wrap">
        <table class="business-report-table business-property-inspection-table">
            <thead><tr>
                <th scope="col"><span>TYPE OF REAL ESTATE</span><small class="business-report-column-guide">(PER PROPERTY DECLARED)</small></th>
                <th scope="col">INSPECTED?<br>(Y/N)</th>
                <th scope="col">TOTAL UNITS AVAILABLE</th>
                <th scope="col">UNITS W/ TENANTS</th>
                <th scope="col">LOCATION &amp; TOTAL SQM OF BUILDING</th>
                <th scope="col"><span>TENANT INFORMATION</span><small class="business-report-column-guide">(ENUMERATE NAME &amp; MONTHLY RENT &amp; YEARS RENTING)</small></th>
                <th scope="col">W/ CONTRACT?<br>(Y/N)</th>
                <th scope="col" class="business-report-action-heading">Action</th>
            </tr></thead>
            <tbody data-repeater-rows>
                @foreach($propertyRows as $index => $row)
                    @include('client-folders.income-sources._business-non-agricultural-property-row', [
                        'propertyRecord' => $records->firstWhere('id', data_get($row, 'id')),
                    ])
                @endforeach
            </tbody>
        </table>
    </div>

    <template data-repeater-template>
        @include('client-folders.income-sources._business-non-agricultural-property-row', [
            'row' => [],
            'index' => '__INDEX__',
            'propertyRecord' => null,
        ])
    </template>
    <span class="sr-only">Tenants are encoded within their saved property row.</span>
    @error('properties')<p class="ui-error business-section-error" role="alert">{{ $message }}</p>@enderror
    @error('tenants')<p class="ui-error business-section-error" role="alert">{{ $message }}</p>@enderror
</section>
