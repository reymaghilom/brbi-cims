@props(['id', 'title', 'confirmLabel' => 'Confirm', 'action', 'method' => 'POST', 'destructive' => false, 'cancelIcon' => null, 'confirmIcon' => null])

<x-ui.modal :id="$id" :title="$title" {{ $attributes }}>
    {{ $slot }}
    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary">@if($cancelIcon)<x-ui.icon :name="$cancelIcon" size="size-4" />@endif Cancel</button>
        <form method="POST" action="{{ $action }}">
            @csrf
            @if(! in_array(strtoupper($method), ['GET', 'POST'], true)) @method($method) @endif
            @isset($formFields){{ $formFields }}@endisset
            <button class="{{ $destructive ? 'ui-button-danger' : 'ui-button-primary' }}">@if($confirmIcon)<x-ui.icon :name="$confirmIcon" size="size-4" />@endif {{ $confirmLabel }}</button>
        </form>
    </x-slot:footer>
</x-ui.modal>
