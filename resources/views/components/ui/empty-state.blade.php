@props(['title', 'description' => null, 'icon' => 'folder'])

<section {{ $attributes->class('ui-card px-6 py-12 text-center') }}>
    <span class="mx-auto grid size-14 place-items-center rounded-panel bg-brand-soft text-brand-primary"><x-ui.icon :name="$icon" size="size-7" /></span>
    <h2 class="mt-4 text-lg font-bold">{{ $title }}</h2>
    @if($description)<p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-text-muted">{{ $description }}</p>@endif
    @isset($action)<div class="mt-6 flex justify-center">{{ $action }}</div>@endisset
</section>
