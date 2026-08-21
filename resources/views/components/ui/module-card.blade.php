@props(['title', 'description' => null, 'icon' => 'report', 'href' => null, 'state' => null, 'badge' => null, 'updatedAt' => null, 'asButton' => false, 'modalId' => null, 'modalUrl' => null, 'openLabel' => 'Open', 'openIcon' => 'open', 'primary' => false])

@php
    $stateValue = $state instanceof BackedEnum ? $state->value : (string) $state;
    [$stateLabel, $stateTone] = match($stateValue) {
        'complete', 'completed' => ['Completed', 'bg-success-soft text-success'],
        'in_progress', 'draft' => ['In Progress', 'bg-progress-soft text-progress'],
        'available' => ['Available', 'bg-success-soft text-success'],
        'not_configured' => ['Not Configured', 'bg-progress-soft text-progress'],
        default => ['Not Started', 'bg-surface-muted text-text-muted'],
    };
    $badgeLabel = $badge ?? $stateLabel;
    $openButtonClass = $primary ? 'ui-button-primary-compact' : 'ui-button-secondary-compact';
@endphp

<article {{ $attributes->class('flex h-full flex-col gap-3 rounded-card border border-ui-border bg-surface p-3.5 shadow-card transition duration-150 hover:shadow-float sm:p-4') }}>
    <div class="flex items-start justify-between gap-3">
        <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon :name="$icon" size="size-5" /></span>
        @if($state)<span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-[0.68rem] font-semibold {{ $stateTone }}" data-module-status>{{ $badgeLabel }}</span>@endif
    </div>

    <div class="min-w-0 flex-1">
        <h3 class="text-sm font-semibold leading-5 text-brand-sidebar">{{ $title }}</h3>
        @if($description)<p class="mt-1 line-clamp-2 text-xs leading-5 text-text-muted">{{ $description }}</p>@endif
        @if($updatedAt)<p class="mt-2 flex items-center gap-1.5 text-[0.68rem] leading-4 text-text-subtle"><x-ui.icon name="clock" size="size-4" />Last updated {{ $updatedAt }}</p>@endif
    </div>

    <div class="mt-auto flex flex-wrap items-center gap-1 border-t border-ui-border pt-3">
        @if($href)
            <a href="{{ $href }}" @if($modalId) data-modal-open="{{ $modalId }}" @if($modalId === 'business-report-dialog') data-business-report-url="{{ $modalUrl ?? $href }}" @else data-cibi-report-url="{{ $modalUrl ?? $href }}" @endif @endif class="{{ $openButtonClass }}"><x-ui.icon :name="$openIcon" size="size-3.5" />{{ $openLabel }}</a>
        @elseif($asButton)
            <button type="button" class="{{ $openButtonClass }}"><x-ui.icon :name="$openIcon" size="size-3.5" />{{ $openLabel }}</button>
        @endif
        {{ $footer ?? '' }}
    </div>
</article>
