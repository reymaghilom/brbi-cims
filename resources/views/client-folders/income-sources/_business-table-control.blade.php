@if($type === 'static')
    <span class="business-report-static-cell">{{ $value }}</span>
    <input type="hidden" name="{{ $htmlName }}" value="{{ $value }}">
@elseif($type === 'checkbox')
    <input type="hidden" name="{{ $htmlName }}" value="0">
    <input id="{{ $id }}" type="checkbox" name="{{ $htmlName }}" value="1" @checked((bool) $value) class="business-report-checkbox">
@elseif($type === 'radio')
    <div class="business-report-choice-group" role="radiogroup">
        @foreach($options ?? [] as $optionValue => $optionLabel)
            <label class="business-report-choice-option"><input id="{{ $id }}-{{ $loop->index }}" type="radio" name="{{ $htmlName }}" value="{{ $optionValue }}" @checked((string) $value === (string) $optionValue) class="business-report-checkbox"> <span>{{ $optionLabel }}</span></label>
        @endforeach
    </div>
@elseif($type === 'select')
    <select id="{{ $id }}" name="{{ $htmlName }}" class="ui-control">
        <option value="">Select</option>
        @if(filled($value) && ! array_key_exists((string) $value, $options ?? []))<option value="{{ $value }}" selected>{{ str($value)->replace('_', ' ')->title() }}</option>@endif
        @foreach($options ?? [] as $optionValue => $optionLabel)<option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>@endforeach
    </select>
@elseif($type === 'textarea')
    <textarea id="{{ $id }}" name="{{ $htmlName }}" rows="2" class="ui-control">{{ $value }}</textarea>
@else
    <input id="{{ $id }}" name="{{ $htmlName }}" type="{{ $type }}" value="{{ $value }}" @if($type === 'number') step="{{ $step ?? 'any' }}" min="0" @endif class="ui-control">
@endif
