@props(['items' => []])

<section {{ $attributes->class('rounded-card border border-progress/25 bg-progress-soft p-5') }} aria-labelledby="missing-items-title">
    <div class="flex items-start gap-3 text-progress"><x-ui.icon name="warning" /><div><h2 id="missing-items-title" class="font-bold">Missing / Pending Items</h2><p class="mt-1 text-sm">Complete these applicable requirements before the folder can be marked Completed.</p></div></div>
    <ul class="mt-4 space-y-2 pl-9 text-sm text-text-main">@forelse($items as $item)<li class="list-disc">{{ $item }}</li>@empty<li>No missing applicable items.</li>@endforelse</ul>
</section>
