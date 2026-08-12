@props(['items' => []])

<nav aria-label="Breadcrumb" {{ $attributes->class('mb-4 overflow-x-auto') }}>
    <ol class="flex min-w-max items-center gap-1 text-sm text-text-muted">
        @foreach ($items as $item)
            <li class="flex items-center gap-1">
                @if (! $loop->first)<x-ui.icon name="chevron-right" size="size-4" />@endif
                @if (! empty($item['url']) && ! $loop->last)
                    <a href="{{ $item['url'] }}" class="rounded px-1 py-1 hover:text-brand-primary hover:underline">{{ $item['label'] }}</a>
                @else
                    <span @if($loop->last) aria-current="page" class="px-1 py-1 font-semibold text-text-main" @else class="px-1 py-1" @endif>{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
