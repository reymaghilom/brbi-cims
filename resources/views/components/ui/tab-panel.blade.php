@props(['id', 'active' => false])

<section id="panel-{{ $id }}" role="tabpanel" aria-labelledby="tab-{{ $id }}" @if(! $active) hidden @endif {{ $attributes }}>{{ $slot }}</section>
