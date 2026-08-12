@props(['title', 'description' => null])

<section {{ $attributes->class('ui-panel p-5 sm:p-7') }}>
    <header class="mb-6"><h2 class="ui-section-title">{{ $title }}</h2>@if($description)<p class="mt-1.5 text-sm leading-6 text-text-muted">{{ $description }}</p>@endif</header>
    <div class="grid gap-5 sm:grid-cols-2">{{ $slot }}</div>
</section>
