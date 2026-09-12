@php
    $mobile ??= false;
    $controlId = 'work-today-'.($mobile ? 'mobile' : 'desktop').'-'.$item['id'].'-'.($item['target_id'] ?? 'activity');
    $controlClass = $mobile
        ? 'min-h-10 min-w-28 px-4 py-2'
        : 'min-w-20 px-2';
@endphp

@if($item['direct_completion'])
    <button
        id="{{ $controlId }}"
        type="button"
        class="ui-button-primary-compact {{ $controlClass }} cursor-pointer"
        data-work-today-action="continue"
        data-modal-open="{{ $item['completion_modal_id'] }}"
        data-dashboard-completion-name="{{ $item['activity'] }}"
        data-dashboard-completion-target="{{ $item['completion_target'] }}"
        data-dashboard-completion-target-type="{{ $item['completion_target_type'] }}"
        data-dashboard-completion-update-url="{{ $item['completion_url'] }}"
        data-dashboard-completion-method="{{ $item['completion_method'] }}"
        data-dashboard-completion-co-maker-id="{{ $item['completion_co_maker_id'] }}"
        data-dashboard-completion-expected-updated-at="{{ $item['completion_expected_updated_at'] }}"
        data-dashboard-completion-schedule="{{ $item['completion_schedule'] }}"
        data-dashboard-completion-remarks="{{ $item['completion_remarks'] }}"
        data-dashboard-activity-url="{{ $item['modal_url'] }}"
        data-dashboard-activity-title="{{ $item['activity'] }}"
        data-dashboard-activity-context="{{ ($item['person'] ? 'Co-Maker: '.$item['person'] : 'Applicant: '.$item['client']).' · '.$item['status'] }}"
        aria-haspopup="dialog"
    >
        <x-ui.icon name="chevron-right" size="size-4" data-work-today-action-icon />
        Continue
    </button>
@else
    <a
        id="{{ $controlId }}"
        href="{{ $item['url'] }}"
        class="ui-button-secondary-compact {{ $controlClass }} cursor-pointer"
        data-work-today-action="open"
    >
        <x-ui.icon name="open" size="size-4" data-work-today-action-icon />
        Open
    </a>
@endif
