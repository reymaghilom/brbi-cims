@props(['title', 'number', 'href' => null, 'status' => 'on_progress', 'progress' => 0])

<article {{ $attributes->class('group relative overflow-hidden rounded-panel border border-folder/70 bg-folder-soft p-5 shadow-card transition hover:-translate-y-0.5 hover:shadow-float') }}>
    <div class="absolute inset-x-0 top-0 h-2 bg-folder" aria-hidden="true"></div>
    <div class="mt-2 flex items-start justify-between gap-4">
        <span class="grid size-12 place-items-center rounded-card bg-folder text-brand-sidebar"><x-ui.icon name="folder" size="size-7" /></span>
        <x-ui.status-badge :status="$status" />
    </div>
    <p class="mt-5 text-xs font-bold uppercase tracking-wide text-text-muted">{{ $number }}</p>
    <h3 class="mt-1 truncate text-lg font-bold">@if($href)<a href="{{ $href }}" class="rounded hover:text-brand-primary hover:underline">{{ $title }}</a>@else{{ $title }}@endif</h3>
    <x-ui.progress-bar :value="$progress" class="mt-5" label="Folder completion" />
    @isset($footer)<footer class="relative z-10 mt-4 border-t border-folder/60 pt-4">{{ $footer }}</footer>@endisset
</article>
