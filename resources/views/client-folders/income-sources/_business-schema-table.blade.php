@php
    $tableKey = $table['key'];
    $columns = $table['columns'];
    $visibleColumns = collect($columns)->reject(fn (array $column) => (bool) ($column['hidden'] ?? false))->values()->all();
    $isTruckingUnits = $template->template_type === 'trucking_services' && $tableKey === 'units';
    $isDistributorUnits = $template->template_type === 'distributorship_wholesaler_b2b' && $tableKey === 'units';
    $isPharmacySuppliers = in_array($template->template_type, ['pharmacy_drugstore', 'general_merchandise_hardware_parts', 'buy_sell_dry_goods'], true) && $tableKey === 'suppliers';
    $isContractorSuppliers = $template->template_type === 'contractor_subcontractor' && $tableKey === 'suppliers';
    $usesGroupedVehicleHeading = $isTruckingUnits || $isDistributorUnits;
    $rows = old("template_data.tables.$tableKey", data_get($report?->template_data, "tables.$tableKey", []));
    $propertyTableUsesThreeRows = ($template->template_type === 'leasing_agricultural' && $tableKey === 'properties')
        || ($template->template_type === 'leasing_poultry_farm' && $tableKey === 'farms');
    $usesThreeDefaultRows = $table['title'] === 'Summary of Units Inspected' || $propertyTableUsesThreeRows;
    $defaultRowCount = max(1, (int) ($table['default_rows'] ?? ($usesThreeDefaultRows ? 3 : 1)));
    $defaultRowValues = (array) ($table['default_row_values'] ?? []);
    $defaultRowKey = $table['default_row_key'] ?? null;
    if (count((array) $rows) && count($defaultRowValues) && filled($defaultRowKey)) {
        $savedRows = collect((array) $rows)->values();
        $defaultKeys = collect($defaultRowValues)->pluck($defaultRowKey)->filter()->all();
        $rows = collect($defaultRowValues)
            ->map(fn (array $defaultRow): array => $savedRows->firstWhere($defaultRowKey, data_get($defaultRow, $defaultRowKey)) ?? $defaultRow)
            ->concat($savedRows->reject(fn (array $row): bool => in_array(data_get($row, $defaultRowKey), $defaultKeys, true)))
            ->values()
            ->all();
    } else {
        $rows = count((array) $rows) ? $rows : (count($defaultRowValues) ? $defaultRowValues : array_fill(0, $defaultRowCount, []));
    }
@endphp
<section class="business-report-section scroll-mt-4" data-repeater="template-{{ $tableKey }}" @if($propertyTableUsesThreeRows) data-empty-row-remove-without-confirmation @endif>
    <header @class(['business-report-subheading', 'business-units-summary-heading' => $table['title'] === 'Summary of Units Inspected'])>
        <div><h2>{{ $table['title'] }}</h2></div>
        <button type="button" class="business-add-entry" data-repeater-add>+ Add Row</button>
    </header>
    <div class="business-report-table-wrap">
        <table @class([
            'business-report-table',
            'business-operator-units-table' => in_array($template->template_type, ['taxi_operator', 'puj_van_jeepney_operator'], true) && $tableKey === 'units',
            'business-taxi-units-table' => $template->template_type === 'taxi_operator' && $tableKey === 'units',
            'business-puj-units-table' => $template->template_type === 'puj_van_jeepney_operator' && $tableKey === 'units',
            'business-trucking-units-table' => $isTruckingUnits,
            'business-distributor-units-table' => $isDistributorUnits,
            'business-pharmacy-suppliers-table' => $isPharmacySuppliers || $isContractorSuppliers,
        ])>
            @if($usesGroupedVehicleHeading)
            <colgroup>
                <col class="business-trucking-col-brand"><col class="business-trucking-col-short"><col class="business-trucking-col-short">
                <col class="business-trucking-col-short"><col class="business-trucking-col-goods"><col class="business-trucking-col-areas">
                <col class="business-trucking-col-check"><col class="business-trucking-col-check"><col class="business-trucking-col-action">
            </colgroup>
            <thead class="business-grouped-vehicle-units-heading">
                <tr>
                    @foreach(array_slice($visibleColumns, 0, 3) as $column)<th scope="col" rowspan="2"><span>{{ $column['label'] }}</span></th>@endforeach
                    <th scope="colgroup" colspan="3"><span>{{ $isDistributorUnits ? 'INTERVIEW WITH DRIVER/AGENT' : 'INTERVIEW WITH DRIVER' }}</span></th>
                    <th scope="colgroup" colspan="2"><span>ACTUAL OR/CR CHECKING</span></th>
                    <th scope="col" rowspan="2" class="business-report-action-heading">Action</th>
                </tr>
                <tr>
                    @foreach(array_slice($visibleColumns, 3) as $column)<th scope="col"><span>{{ $column['label'] }}</span></th>@endforeach
                </tr>
            </thead>
            @else
            <thead><tr>@foreach($visibleColumns as $column)<th scope="col"><span>{{ $column['label'] }}</span>@if(filled($column['guide'] ?? null))<small class="business-report-column-guide">{{ $column['guide'] }}</small>@endif</th>@endforeach<th scope="col" class="business-report-action-heading">Action</th></tr></thead>
            @endif
            <tbody data-repeater-rows>
                @foreach($rows as $index => $row)
                    @include('client-folders.income-sources._business-schema-row')
                @endforeach
            </tbody>
        </table>
    </div>
    <template data-repeater-template>
        @include('client-folders.income-sources._business-schema-row', ['row' => [], 'index' => '__INDEX__'])
    </template>
    <x-form.validation-message :for="'template_data.tables.'.$tableKey" />
</section>
