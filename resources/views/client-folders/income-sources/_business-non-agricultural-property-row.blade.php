@php
    $rowId = data_get($row, 'id');
    $savedPropertyType = (string) data_get($row, 'property_type');
    $normalizedPropertyType = (string) str($savedPropertyType)->lower();
    $propertyTypeOptions = [
        'warehouse' => ['label' => 'WAREHOUSE', 'value' => str_contains($normalizedPropertyType, 'warehouse') ? $savedPropertyType : 'Warehouse'],
        'commercial' => ['label' => "COMM'L", 'value' => str_contains($normalizedPropertyType, 'comm') ? $savedPropertyType : 'Commercial'],
        'residential' => ['label' => "RES'L", 'value' => str_contains($normalizedPropertyType, 'res') ? $savedPropertyType : 'Residential'],
    ];
    $tenantInformation = data_get($row, 'remarks');
    if (blank($tenantInformation) && $propertyRecord) {
        $tenantInformation = $propertyRecord->tenants->map(fn ($tenant) => collect([
            $tenant->tenant_name,
            filled($tenant->monthly_rent) ? 'PHP '.$tenant->monthly_rent : null,
            filled($tenant->years_renting) ? $tenant->years_renting.' years' : null,
        ])->filter()->implode(' / '))->filter()->implode('; ');
    }
@endphp
<tr data-repeater-row @if(data_get($row, '_delete')) hidden @endif>
    <td>
        <input type="hidden" name="properties[{{ $index }}][id]" value="{{ $rowId }}">
        <input type="hidden" name="properties[{{ $index }}][_delete]" value="{{ data_get($row, '_delete', 0) }}" data-delete-field>
        <input type="hidden" name="properties[{{ $index }}][is_declared]" value="1">
        <fieldset class="business-property-type-options"><legend class="sr-only">Type of real estate</legend>@foreach($propertyTypeOptions as $optionKey => $option)<label class="business-report-choice-option"><input id="properties-{{ $index }}-property-type-{{ $optionKey }}" name="properties[{{ $index }}][property_type]" type="radio" value="{{ $option['value'] }}" @checked(str_contains($normalizedPropertyType, $optionKey === 'commercial' ? 'comm' : ($optionKey === 'residential' ? 'res' : 'warehouse'))) class="business-report-checkbox"><span>{{ $option['label'] }}</span></label>@endforeach</fieldset>
    </td>
    <td>
        <input type="hidden" name="properties[{{ $index }}][is_inspected]" value="{{ filled($rowId) ? ((bool) data_get($row, 'is_inspected') ? '1' : '0') : '' }}" data-property-boolean-value>
        <label for="properties-{{ $index }}-is-inspected" class="sr-only">Inspected, Y or N</label><input id="properties-{{ $index }}-is-inspected" type="text" maxlength="1" pattern="[YyNn]" value="{{ filled($rowId) ? ((bool) data_get($row, 'is_inspected') ? 'Y' : 'N') : '' }}" class="ui-control business-property-yn-input" data-property-boolean-display>
        <input name="properties[{{ $index }}][reason_not_inspected]" type="hidden" value="{{ data_get($row, 'reason_not_inspected') }}" data-property-reason-value>
    </td>
    <td><label for="properties-{{ $index }}-units-available" class="sr-only">Total units available</label><input id="properties-{{ $index }}-units-available" name="properties[{{ $index }}][units_available]" type="text" value="{{ data_get($row, 'units_available') }}" class="ui-control"></td>
    <td><label for="properties-{{ $index }}-units-with-tenants" class="sr-only">Units with tenants</label><input id="properties-{{ $index }}-units-with-tenants" name="properties[{{ $index }}][units_with_tenants]" type="text" value="{{ data_get($row, 'units_with_tenants') }}" class="ui-control"></td>
    <td class="business-property-location-cell"><label for="properties-{{ $index }}-location" class="sr-only">Location and total square meters of building</label><input id="properties-{{ $index }}-location" name="properties[{{ $index }}][location]" type="text" value="{{ data_get($row, 'location') }}" class="ui-control"><input type="hidden" name="properties[{{ $index }}][area_square_meters]" value="{{ data_get($row, 'area_square_meters') }}"></td>
    <td class="business-property-tenants-cell">
        <label for="properties-{{ $index }}-tenant-information" class="sr-only">Tenant information</label><input id="properties-{{ $index }}-tenant-information" name="properties[{{ $index }}][remarks]" type="text" value="{{ $tenantInformation }}" class="ui-control">
    </td>
    <td>
        <input type="hidden" name="properties[{{ $index }}][has_contract]" value="{{ filled($rowId) && data_get($row, 'has_contract') !== null ? ((bool) data_get($row, 'has_contract') ? '1' : '0') : '' }}" data-property-boolean-value>
        <label for="properties-{{ $index }}-has-contract" class="sr-only">With contract, Y or N</label><input id="properties-{{ $index }}-has-contract" type="text" maxlength="1" pattern="[YyNn]" value="{{ filled($rowId) && data_get($row, 'has_contract') !== null ? ((bool) data_get($row, 'has_contract') ? 'Y' : 'N') : '' }}" class="ui-control business-property-yn-input" data-property-boolean-display>
    </td>
    <td class="business-report-action-cell"><button type="button" class="business-remove-entry" data-repeater-remove title="Remove entry" aria-label="Remove entry"><x-ui.icon name="trash" size="size-4" /></button></td>
</tr>
