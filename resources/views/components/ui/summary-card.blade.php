@props(['label', 'value', 'hint' => null, 'icon' => 'report', 'tone' => 'green', 'compact' => false])

<section {{ $attributes->class(['rounded-card bg-surface-muted px-3 py-2.5' => $compact, 'ui-card p-5' => ! $compact]) }} aria-label="{{ $label }}">
    <div @class(['flex', 'items-center gap-2.5' => $compact, 'items-start justify-between gap-4' => ! $compact])>
        @if($compact)
            <span @class(['grid size-8 shrink-0 place-items-center rounded-control', 'bg-brand-soft text-brand-primary' => $tone === 'green', 'bg-progress-soft text-progress' => $tone === 'amber', 'bg-folder-soft text-progress' => $tone === 'folder'])><x-ui.icon :name="$icon" size="size-4" /></span>
            <p class="min-w-0 flex-1 truncate text-xs font-semibold text-text-muted">{{ $label }}</p>
            <p class="text-xl font-bold tabular-nums tracking-tight text-text-main" data-summary-value>{{ $value }}</p>
        @else
            <div><p class="text-sm font-semibold text-text-muted">{{ $label }}</p><p class="mt-2 text-3xl font-bold tracking-tight">{{ $value }}</p></div>
            <span @class(['grid size-11 place-items-center rounded-card', 'bg-brand-soft text-brand-primary' => $tone === 'green', 'bg-progress-soft text-progress' => $tone === 'amber', 'bg-folder-soft text-progress' => $tone === 'folder'])><x-ui.icon :name="$icon" /></span>
        @endif
    </div>
    @if ($hint)<p @class(['sr-only' => $compact, 'mt-3 text-sm text-text-muted' => ! $compact])>{{ $hint }}</p>@endif
</section>
