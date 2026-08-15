@php
    $category = data_get($row, 'supplier_category');
    if (!filled($category) && is_numeric($index)) {
        $category = $supplierCategories[(int) $index] ?? null;
    }
    $fixedPosition = filled($category) ? array_search($category, $supplierCategories, true) : false;
    $employeeField = $fixedPosition !== false ? $employeeFields[$fixedPosition] : null;
    $prefix = "template_data.tables.$tableKey.$index";
    $htmlPrefix = "template_data[tables][$tableKey][$index]";
@endphp
<tr data-repeater-row>
    <td class="business-truck-employee-label">@if($employeeField)<span class="ui-label !mb-0">{{ $employeeField['label'] }}</span>@endif</td>
    <td class="business-truck-employee-value">
        @if($employeeField)
            <label for="template-field-{{ $employeeField['key'] }}" class="sr-only">{{ $employeeField['label'] }}</label>
            <input id="template-field-{{ $employeeField['key'] }}" class="ui-control" name="template_data[fields][{{ $employeeField['key'] }}]" type="text" value="{{ old('template_data.fields.'.$employeeField['key'], data_get($report?->template_data, 'fields.'.$employeeField['key'])) }}">
            <x-form.validation-message :for="'template_data.fields.'.$employeeField['key']" />
        @endif
    </td>
    <td class="business-truck-supplier-label">
        @if($fixedPosition !== false)
            <span class="ui-label !mb-0">{{ $category }}:</span>
            <input type="hidden" name="{{ $htmlPrefix }}[supplier_category]" value="{{ $category }}">
        @else
            <label for="truck-supplier-{{ $index }}-category" class="sr-only">Supplier type</label>
            <input id="truck-supplier-{{ $index }}-category" class="ui-control" name="{{ $htmlPrefix }}[supplier_category]" type="text" value="{{ $category }}" placeholder="Supplier type">
        @endif
    </td>
    <td>
        <label for="truck-supplier-{{ $index }}-name" class="sr-only">Business name or contact person</label>
        <input id="truck-supplier-{{ $index }}-name" class="ui-control" name="{{ $htmlPrefix }}[supplier_name]" type="text" value="{{ data_get($row, 'supplier_name') }}">
        @if(filled(data_get($row, 'contact_information')))<input type="hidden" name="{{ $htmlPrefix }}[contact_information]" value="{{ data_get($row, 'contact_information') }}">@endif
    </td>
    <td><label for="truck-supplier-{{ $index }}-address" class="sr-only">Address</label><input id="truck-supplier-{{ $index }}-address" class="ui-control" name="{{ $htmlPrefix }}[office_location]" type="text" value="{{ data_get($row, 'office_location') }}"></td>
    <td><label for="truck-supplier-{{ $index }}-years" class="sr-only">Years transacting</label><input id="truck-supplier-{{ $index }}-years" class="ui-control" name="{{ $htmlPrefix }}[years_transacting]" type="text" value="{{ data_get($row, 'years_transacting') }}"></td>
    <td><label for="truck-supplier-{{ $index }}-payment" class="sr-only">Monthly average expense and payment track record</label><input id="truck-supplier-{{ $index }}-payment" class="ui-control" name="{{ $htmlPrefix }}[payment_performance]" type="text" value="{{ data_get($row, 'payment_performance') }}"></td>
    @unless($template->template_type === 'trucking_services')<td class="business-report-action-cell">@if($fixedPosition === false)<button type="button" class="business-remove-entry" data-repeater-remove title="Remove entry" aria-label="Remove entry"><x-ui.icon name="trash" size="size-4" /></button>@endif</td>@endunless
</tr>
