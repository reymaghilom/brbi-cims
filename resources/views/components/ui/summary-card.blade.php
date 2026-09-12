{{-- `url` is what turns a summary card into a real link to the tab/filter it already counts;
     without it the card stays the plain non-interactive <section> it has always been, so every
     existing use of this component is unchanged. `active` marks the card whose filter is the one
     currently applied. --}}
@props(['label', 'value', 'hint' => null, 'icon' => 'report', 'tone' => 'green', 'compact' => false, 'url' => null, 'active' => false])

@php
    $toneClasses = match ($tone) {
        'green' => 'bg-success-soft text-success',
        'amber' => 'bg-progress-soft text-progress',
        'folder' => 'bg-brand-soft text-brand-primary',
        'violet' => 'bg-violet-50 text-violet-700',
        'red' => 'bg-danger-soft text-danger',
        default => 'bg-brand-soft text-brand-primary',
    };

    $cardTag = $url ? 'a' : 'section';
    // A link says what it does; the plain card keeps the label it has always had.
    $cardLabel = $url
        ? $label.': '.$value.($active ? ', currently showing' : ', show these activities')
        : $label;
@endphp

<{{ $cardTag }}
    @if($url) href="{{ $url }}" @if($active) aria-current="page" @endif @endif
    {{ $attributes->class([
        'rounded-card bg-surface-muted px-3 py-2.5' => $compact,
        'ui-card p-5' => ! $compact,
        'ui-kpi-card' => (bool) $url,
    ]) }}
    aria-label="{{ $cardLabel }}">
    <div @class(['flex', 'items-center gap-2.5' => $compact, 'items-start justify-between gap-4' => ! $compact])>
        @if($compact)
            <span @class(['grid size-8 shrink-0 place-items-center rounded-control', $toneClasses])><x-ui.icon :name="$icon" size="size-4" /></span>
            <p class="min-w-0 flex-1 truncate text-xs font-semibold text-text-muted">{{ $label }}</p>
            <p class="text-xl font-bold tabular-nums tracking-tight text-text-main" data-summary-value>{{ $value }}</p>
        @else
            <div><p class="text-sm font-semibold text-text-muted">{{ $label }}</p><p class="mt-2 text-3xl font-bold tracking-tight">{{ $value }}</p></div>
            <span @class(['grid size-11 place-items-center rounded-card', $toneClasses])><x-ui.icon :name="$icon" /></span>
        @endif
    </div>
    @if ($hint)<p @class(['sr-only' => $compact, 'mt-3 text-sm text-text-muted' => ! $compact])>{{ $hint }}</p>@endif
</{{ $cardTag }}>
