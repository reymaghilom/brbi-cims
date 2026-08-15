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
            if (($field['format'] ?? null) === 'yes_no' && $value !== null && $value !== '') {
                $value = filter_var($value, FILTER_VALIDATE_BOOL) ? 'Y' : 'N';
            }
        @endphp
        <td>
            @if($loop->first)
                <input type="hidden" name="{{ $section }}[{{ $index }}][id]" value="{{ $rowId }}">
                <input type="hidden" name="{{ $section }}[{{ $index }}][_delete]" value="{{ data_get($row, '_delete', 0) }}" data-delete-field>
            @endif
            <label for="{{ $id }}" class="sr-only">{{ $field['label'] }}</label>
            @include('client-folders.income-sources._business-table-control', ['options' => $field['options'] ?? [], 'step' => $field['step'] ?? '1'])
            @if(!$template)<x-form.validation-message :for="$name" />@endif
        </td>
    @endforeach
    <td class="business-report-action-cell"><button type="button" class="business-remove-entry" data-repeater-remove title="Remove entry" aria-label="Remove entry"><x-ui.icon name="trash" size="size-4" /></button></td>
</tr>
