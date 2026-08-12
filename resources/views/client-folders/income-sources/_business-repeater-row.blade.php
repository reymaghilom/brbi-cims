@php
    $rowId = data_get($row, 'id');
@endphp
<tr data-repeater-row @if(data_get($row, '_delete')) hidden @endif>
    @foreach($fields as $field)
        @php
            $name = "{$section}.{$index}.{$field['name']}";
            $htmlName = "{$section}[{$index}][{$field['name']}]";
            $id = "{$section}-{$index}-{$field['name']}";
            $value = data_get($row, $field['name']);
            $type = $field['type'] ?? 'text';
        @endphp
        <td>
            @if($loop->first)
                <input type="hidden" name="{{ $section }}[{{ $index }}][id]" value="{{ $rowId }}">
                <input type="hidden" name="{{ $section }}[{{ $index }}][_delete]" value="{{ data_get($row, '_delete', 0) }}" data-delete-field>
            @endif
            <label for="{{ $id }}" class="sr-only">{{ $field['label'] }}</label>
            @if($type === 'checkbox')
                <input type="hidden" name="{{ $htmlName }}" value="0">
                <input id="{{ $id }}" type="checkbox" name="{{ $htmlName }}" value="1" @checked((bool) $value) class="business-report-checkbox">
            @elseif($type === 'select')
                <select id="{{ $id }}" name="{{ $htmlName }}" class="ui-control">
                    <option value="">Select</option>
                    @if(filled($value) && ! array_key_exists((string) $value, $field['options']))<option value="{{ $value }}" selected>{{ str($value)->replace('_', ' ')->title() }}</option>@endif
                    @foreach($field['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
                </select>
            @elseif($type === 'textarea')
                <textarea id="{{ $id }}" name="{{ $htmlName }}" rows="2" class="ui-control">{{ $value }}</textarea>
            @else
                <input id="{{ $id }}" name="{{ $htmlName }}" type="{{ $type }}" value="{{ $value }}" @if($type === 'number') step="{{ $field['step'] ?? '1' }}" min="0" @endif class="ui-control">
            @endif
            @if(!$template)<x-form.validation-message :for="$name" />@endif
        </td>
    @endforeach
    <td class="business-report-action-cell"><button type="button" class="business-remove-entry" data-repeater-remove title="Remove entry" aria-label="Remove entry"><x-ui.icon name="trash" size="size-4" /></button></td>
</tr>
