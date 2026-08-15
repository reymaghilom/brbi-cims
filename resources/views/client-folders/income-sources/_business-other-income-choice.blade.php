@php
    $fieldKey = $choice['key'].'_rank';
    $rank = old('template_data.fields.'.$fieldKey, data_get($report?->template_data, 'fields.'.$fieldKey));
@endphp

<div class="business-other-income-choice">
    <label class="sr-only" for="other_income_{{ $fieldKey }}">{{ $choice['label'] }} contribution rank</label>
    <input
        id="other_income_{{ $fieldKey }}"
        class="ui-control business-other-income-rank"
        name="template_data[fields][{{ $fieldKey }}]"
        type="number"
        min="1"
        value="{{ $rank }}"
        inputmode="numeric"
        data-income-source-rank
        data-income-source-label="{{ $choice['label'] }}"
    >
    <span>{{ $choice['label'] }}</span>
</div>
<x-form.validation-message :for="'template_data.fields.'.$fieldKey" />
