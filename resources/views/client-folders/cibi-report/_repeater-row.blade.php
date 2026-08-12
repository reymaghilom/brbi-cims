@php
    $rowId = data_get($row, 'id');
@endphp
<fieldset @class(['rounded-control border border-ui-border p-3 sm:p-4', 'bg-surface' => $paper ?? false, 'rounded-card bg-surface-subtle sm:p-5' => ! ($paper ?? false)]) data-repeater-row @if(data_get($row, '_delete')) hidden @endif>
    <legend class="px-1 text-xs font-semibold text-brand-sidebar">{{ $title }} Row</legend>
    <input type="hidden" name="{{ $section }}[{{ $index }}][id]" value="{{ $rowId }}">
    <input type="hidden" name="{{ $section }}[{{ $index }}][_delete]" value="{{ data_get($row, '_delete', 0) }}" data-delete-field>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach($fields as $field)
            @php
                $name = "{$section}.{$index}.{$field['name']}";
                $htmlName = "{$section}[{$index}][{$field['name']}]";
                $id = "{$section}-{$index}-{$field['name']}";
                $value = data_get($row, $field['name']);
                $type = $field['type'] ?? 'text';
            @endphp
            <div @class(['sm:col-span-2 xl:col-span-3' => ($field['wide'] ?? false)])>
                @if($type === 'checkbox')
                    <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold">
                        <input type="hidden" name="{{ $htmlName }}" value="0">
                        <input type="checkbox" id="{{ $id }}" name="{{ $htmlName }}" value="1" @checked((bool) $value) class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary">{{ $field['label'] }}
                    </label>
                @else
                    <label for="{{ $id }}" class="ui-label">{{ $field['label'] }}</label>
                    @if($type === 'textarea')
                        <textarea id="{{ $id }}" name="{{ $htmlName }}" rows="3" class="ui-control">{{ $value }}</textarea>
                    @elseif($type === 'select')
                        <select id="{{ $id }}" name="{{ $htmlName }}" class="ui-control"><option value="">Select</option>@if(filled($value) && ! array_key_exists((string) $value, $field['options']))<option value="{{ $value }}" selected>{{ str($value)->replace('_', ' ')->title() }}</option>@endif @foreach($field['options'] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach</select>
                    @else
                        <input id="{{ $id }}" name="{{ $htmlName }}" type="{{ $type }}" value="{{ $value }}" @if($type === 'number') step="{{ $field['step'] ?? '1' }}" min="0" @endif class="ui-control">
                    @endif
                    @if(!$template)<x-form.validation-message :for="$name" />@endif
                @endif
            </div>
        @endforeach
    </div>
    <button type="button" class="ui-button-danger mt-3" data-repeater-remove>{{ $rowId ? 'Remove Saved Row' : 'Remove Row' }}</button>
</fieldset>
