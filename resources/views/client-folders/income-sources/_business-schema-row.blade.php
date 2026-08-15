<tr data-repeater-row>
    @foreach($visibleColumns ?? $columns as $column)
        @php
            $validationName = "template_data.tables.{$tableKey}.{$index}.{$column['key']}";
            $htmlName = "template_data[tables][{$tableKey}][{$index}][{$column['key']}]";
            $id = "template-{$tableKey}-{$index}-{$column['key']}";
            $value = data_get($row, $column['key']);
            $type = $column['type'] ?? 'text';
        @endphp
        <td>
            <label for="{{ $id }}" class="sr-only">{{ $column['label'] }}</label>
            @include('client-folders.income-sources._business-table-control', ['options' => $column['options'] ?? [], 'step' => $column['step'] ?? 'any'])
            <x-form.validation-message :for="$validationName" />
        </td>
    @endforeach
    <td class="business-report-action-cell">
        @foreach(collect($columns)->filter(fn (array $column) => (bool) ($column['hidden'] ?? false)) as $hiddenColumn)
            <input type="hidden" name="template_data[tables][{{ $tableKey }}][{{ $index }}][{{ $hiddenColumn['key'] }}]" value="{{ data_get($row, $hiddenColumn['key']) }}">
        @endforeach
        <button type="button" class="business-remove-entry" data-repeater-remove title="Remove entry" aria-label="Remove entry"><x-ui.icon name="trash" size="size-4" /></button>
    </td>
</tr>
