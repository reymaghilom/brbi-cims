@props(['title', 'description' => null, 'icon' => 'report', 'href' => null, 'state' => null, 'updatedAt' => null, 'asButton' => false, 'modalId' => null, 'modalUrl' => null])

@php
    $stateValue = $state instanceof BackedEnum ? $state->value : (string) $state;
    [$stateLabel, $stateTone] = match($stateValue) {
        'complete', 'completed' => ['Completed', 'bg-success-soft text-success'],
        'in_progress', 'draft' => ['In Progress', 'bg-progress-soft text-progress'],
        'available' => ['Available', 'bg-success-soft text-success'],
        'not_configured' => ['Not Configured', 'bg-progress-soft text-progress'],
        default => ['Not Started', 'bg-surface-muted text-text-muted'],
    };
@endphp

@if($href)
    <a href="{{ $href }}" @if($modalId) data-modal-open="{{ $modalId }}" data-cibi-report-url="{{ $modalUrl ?? $href }}" @endif {{ $attributes->class('group flex min-h-[4.75rem] items-center gap-3 rounded-card bg-surface-muted/65 p-3 transition duration-150 hover:bg-brand-soft/55 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 sm:p-3.5') }}>
@elseif($asButton)
    <button type="button" {{ $attributes->class('group flex min-h-[4.75rem] w-full items-center gap-3 rounded-card bg-surface-muted/65 p-3 text-left transition duration-150 hover:bg-brand-soft/55 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 sm:p-3.5') }}>
@else
    <div {{ $attributes->class('flex min-h-[4.75rem] items-center gap-3 rounded-card bg-surface-muted/65 p-3 sm:p-3.5') }}>
@endif
        <span class="grid size-10 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon :name="$icon" size="size-5" /></span>
        <div class="min-w-0 flex-1">
            <h3 class="text-sm font-semibold leading-5 text-brand-sidebar group-hover:text-brand-primary">{{ $title }}</h3>
            @if($description)<p class="mt-0.5 line-clamp-1 text-xs leading-5 text-text-muted">{{ $description }}</p>@endif
        </div>
        @if($state)<span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-[0.68rem] font-semibold {{ $stateTone }}" data-module-status>{{ $stateLabel }}</span>@endif
        @if($href || $asButton)<span class="shrink-0 text-text-muted transition group-hover:translate-x-0.5 group-hover:text-brand-primary"><x-ui.icon name="chevron-right" size="size-4" /></span>@endif
@if($href)
    </a>
@elseif($asButton)
    </button>
@else
    </div>
@endif
