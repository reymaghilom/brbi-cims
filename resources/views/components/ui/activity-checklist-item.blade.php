@props(['title', 'status' => 'not_started', 'description' => null, 'href' => null])

<article {{ $attributes->class('ui-card flex items-start gap-4 p-4') }}>
    <span @class(['mt-0.5 grid size-8 shrink-0 place-items-center rounded-full', 'bg-success-soft text-success' => in_array($status, ['completed', 'complete'], true), 'bg-progress-soft text-progress' => ! in_array($status, ['completed', 'complete'], true)])><x-ui.icon :name="in_array($status, ['completed', 'complete'], true) ? 'check' : 'activity'" size="size-4" /></span>
    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-bold">@if($href)<a href="{{ $href }}" class="hover:text-brand-primary hover:underline">{{ $title }}</a>@else{{ $title }}@endif</h3><x-ui.status-badge :status="$status" /></div>@if($description)<p class="mt-1 text-sm text-text-muted">{{ $description }}</p>@endif</div>
</article>
