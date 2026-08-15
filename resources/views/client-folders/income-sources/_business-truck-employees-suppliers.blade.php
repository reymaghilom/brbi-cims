@php
    $tableKey = $table['key'];
    $isTruckingServices = $template->template_type === 'trucking_services';
    $supplierCategories = ['MAIN FUEL SUPPLIER', 'REPAIR & MAINTENANCE', 'MAIN SUPPLIER/LENDER'];
    $employeeFields = [
        ['key' => 'operators_count', 'label' => '# OF OPERATORS:'],
        ['key' => 'drivers_count', 'label' => '# OF DRIVERS:'],
        ['key' => 'helpers_count', 'label' => '# OF HELPERS:'],
    ];
    $rows = old("template_data.tables.$tableKey", data_get($report?->template_data, "tables.$tableKey", []));
    $savedRows = collect(array_values((array) $rows))->map(function (array $row, int $index) use ($supplierCategories): array {
        $row['supplier_category'] = data_get($row, 'supplier_category') ?: ($supplierCategories[$index] ?? null);

        return $row;
    });
    $rows = collect($supplierCategories)
        ->map(fn (string $category): array => $savedRows->firstWhere('supplier_category', $category) ?? ['supplier_category' => $category])
        ->concat($savedRows->reject(fn (array $row): bool => in_array(data_get($row, 'supplier_category'), $supplierCategories, true)))
        ->values()
        ->all();
@endphp
<section class="business-report-section business-truck-employee-supplier-section scroll-mt-4" data-repeater="template-{{ $tableKey }}" aria-label="Employees and suppliers">
    <div class="business-report-table-wrap">
        <table class="business-report-table business-truck-employee-supplier-table">
            <thead>
                <tr>
                    <th scope="col" colspan="2"><span class="ui-label !mb-0">{{ $isTruckingServices ? 'EMPLOYEES (HIRED BY BORROWER):' : 'EMPLOYEES:' }}</span></th>
                    <th scope="col"><span class="ui-label !mb-0">SUPPLIERS:</span></th>
                    <th scope="col"><span class="ui-label !mb-0">BUSINESS NAME/CONTACT PERSON</span></th>
                    <th scope="col"><span class="ui-label !mb-0">ADDRESS</span></th>
                    <th scope="col"><span class="ui-label !mb-0">YEARS TRANSACTING</span></th>
                    <th scope="col"><span class="ui-label !mb-0">MONTHLY AVE EXPENSE &amp; PAYMENT TRACK RECORD</span></th>
                    @unless($isTruckingServices)<th scope="col" class="business-report-action-heading">Action</th>@endunless
                </tr>
            </thead>
            <tbody data-repeater-rows>
                @foreach($rows as $index => $row)
                    @include('client-folders.income-sources._business-truck-supplier-row')
                @endforeach
            </tbody>
        </table>
    </div>
    <template data-repeater-template>
        @include('client-folders.income-sources._business-truck-supplier-row', ['row' => [], 'index' => '__INDEX__'])
    </template>
    <x-form.validation-message :for="'template_data.tables.'.$tableKey" />
</section>
