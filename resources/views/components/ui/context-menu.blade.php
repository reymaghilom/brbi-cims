@props(['label' => 'More actions'])

<details data-context-menu {{ $attributes->class('group relative inline-block') }}>
    @isset($trigger)
        <summary class="cursor-pointer list-none rounded-control marker:content-none [&::-webkit-details-marker]:hidden" title="{{ $label }}" aria-label="{{ $label }}" aria-haspopup="menu" aria-expanded="false">{{ $trigger }}</summary>
    @else
        <summary class="ui-icon-button cursor-pointer list-none marker:content-none [&::-webkit-details-marker]:hidden" title="{{ $label }}" aria-label="{{ $label }}" aria-haspopup="menu" aria-expanded="false"><x-ui.icon name="more" /></summary>
    @endisset
    <div class="absolute right-0 z-30 mt-2 min-w-48 -translate-y-1 scale-[0.98] rounded-card border border-ui-border bg-surface p-1.5 opacity-0 shadow-float transition-[opacity,transform] duration-150 ease-in data-[state=open]:translate-y-0 data-[state=open]:scale-100 data-[state=open]:opacity-100 data-[state=open]:ease-out motion-reduce:transform-none motion-reduce:transition-none" role="menu" data-context-menu-panel>{{ $slot }}</div>
</details>
