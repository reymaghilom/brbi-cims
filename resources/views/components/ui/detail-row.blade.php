@props(['url', 'title', 'label' => null, 'badge' => null, 'badgeClass' => 'bg-surface-muted text-text-muted', 'icon' => null])
{{-- One actionable row in a Dashboard KPI detail modal: the whole row is a link, with the same
     hover / focus / chevron treatment as the Needs Attention rows. Supporting lines go in the slot. --}}
<li {{ $attributes }}>
    <a href="{{ $url }}" class="group flex cursor-pointer items-start gap-3 px-3.5 py-3 transition duration-150 hover:bg-brand-soft/40 focus-visible:bg-brand-soft/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary/40" @if($label) aria-label="{{ $label }}" @endif>
        @if($icon)<x-ui.icon :name="$icon" size="size-4" class="mt-0.5 shrink-0 text-text-muted" />@endif
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                <p class="min-w-0 break-words text-sm font-semibold leading-5 text-text-main group-hover:text-brand-primary">{{ $title }}</p>
                @if($badge)<span class="w-fit shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $badgeClass }}" data-detail-badge>{{ $badge }}</span>@endif
            </div>
            {{ $slot }}
        </div>
        <x-ui.icon name="chevron-right" size="size-4" class="mt-0.5 shrink-0 text-text-subtle transition group-hover:translate-x-0.5 group-hover:text-brand-primary" />
    </a>
</li>
