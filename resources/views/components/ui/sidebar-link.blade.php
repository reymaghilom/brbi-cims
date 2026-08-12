@props(['href', 'icon', 'active' => false])

<a href="{{ $href }}" @class(['ui-sidebar-link', 'ui-sidebar-link-active' => $active]) @if($active) aria-current="page" @endif {{ $attributes }}>
    <x-ui.icon :name="$icon" />
    <span>{{ $slot }}</span>
</a>
