@php
    $fieldKey = $choice['key'];
    $selectedSources = $selectedSources ?? [];
    // Only a CI-created category carries an id, and only those get management actions — a default
    // option has none, so it can never be renamed or removed from here.
    $customId = $choice['custom_id'] ?? null;
    $retired = (bool) ($choice['retired'] ?? false);
    $hasActions = $customId && ! $retired && ($canManageCustomBusinesses ?? false);
@endphp

{{-- Identical to a default row apart from the optional third grid column: same checkbox column,
     same label cell, same divider and height. has-row-actions is what opens that third track — the
     actions must never become an extra implicit grid row, which is what would make this row taller
     than the default rows beside it. --}}
<div class="business-other-income-choice{{ $hasActions ? ' has-row-actions' : '' }}" @if($customId) data-custom-business-category="{{ $customId }}" data-custom-business-name="{{ $choice['label'] }}" @endif>
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
    <span><span data-custom-business-label>{{ $choice['label'] }}</span>@if($retired){{-- This report selected the category before it was removed from future selection: it stays visible and stays checked so an unrelated save cannot silently drop it. --}}<span class="business-other-income-choice-retired">Removed</span>@endif</span>
    @if($hasActions)
        <span class="business-other-income-choice-actions">
            <button type="button" class="business-other-income-choice-action"
                    title="Edit {{ $choice['label'] }}" aria-label="Edit {{ $choice['label'] }}"
                    data-custom-business-edit><x-ui.icon name="edit" size="" /></button>
            <button type="button" class="business-other-income-choice-action business-other-income-choice-action-danger"
                    title="Remove {{ $choice['label'] }}" aria-label="Remove {{ $choice['label'] }}"
                    data-custom-business-remove><x-ui.icon name="trash" size="" /></button>
        </span>
    @endif
</div>
