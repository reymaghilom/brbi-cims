<div {{ $attributes->class('sticky bottom-4 z-20 mt-7 flex flex-col-reverse gap-3 rounded-card bg-surface/95 p-3 shadow-float backdrop-blur sm:flex-row sm:items-center sm:justify-between') }}>
    <div class="text-sm text-text-muted">{{ $slot }}</div>
    @isset($actions)<div class="flex flex-col-reverse gap-2 sm:flex-row">{{ $actions }}</div>@endisset
</div>
