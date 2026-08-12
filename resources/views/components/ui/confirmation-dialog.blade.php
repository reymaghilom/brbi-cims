@props(['id', 'title', 'confirmLabel' => 'Confirm', 'action', 'method' => 'POST', 'destructive' => false])

<x-ui.modal :id="$id" :title="$title" {{ $attributes }}>
    {{ $slot }}
    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
        <form method="POST" action="{{ $action }}">
            @csrf
            @if(! in_array(strtoupper($method), ['GET', 'POST'], true)) @method($method) @endif
            @isset($formFields){{ $formFields }}@endisset
            <button class="{{ $destructive ? 'ui-button-danger' : 'ui-button-primary' }}">{{ $confirmLabel }}</button>
        </form>
    </x-slot:footer>
</x-ui.modal>
