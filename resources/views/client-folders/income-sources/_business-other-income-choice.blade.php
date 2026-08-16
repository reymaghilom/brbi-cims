@php
    $fieldKey = $choice['key'];
    $selectedSources = $selectedSources ?? [];
@endphp

<div class="business-other-income-choice">
    <label class="sr-only" for="other_income_{{ $fieldKey }}">Select {{ $choice['label'] }}</label>
    <input
        id="other_income_{{ $fieldKey }}"
        class="business-report-checkbox"
        name="template_data[fields][income_sources][]"
        type="checkbox"
        value="{{ $fieldKey }}"
        data-income-source-choice
        data-income-source-label="{{ $choice['label'] }}"
        @checked(in_array($fieldKey, $selectedSources, true))
    >
    <span>{{ $choice['label'] }}</span>
</div>
