@props(['label' => 'More actions'])

<details data-context-menu {{ $attributes->class('group relative inline-block') }}>
    @isset($trigger)
        <summary class="cursor-pointer list-none rounded-control [&::-webkit-details-marker]:hidden" aria-label="{{ $label }}" aria-haspopup="menu" aria-expanded="false">{{ $trigger }}</summary>
    @else
        <summary class="ui-icon-button cursor-pointer list-none [&::-webkit-details-marker]:hidden" aria-label="{{ $label }}" aria-haspopup="menu" aria-expanded="false"><x-ui.icon name="more" /></summary>
    @endisset
    <div class="absolute right-0 z-30 mt-2 min-w-48 rounded-card border border-ui-border bg-surface p-1.5 shadow-float" role="menu">{{ $slot }}</div>
</details>
