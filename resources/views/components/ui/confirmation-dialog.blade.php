@props(['id', 'title', 'confirmLabel' => 'Confirm', 'action', 'method' => 'POST', 'destructive' => false, 'cancelIcon' => null, 'confirmIcon' => null])

<x-ui.modal :id="$id" :title="$title" {{ $attributes }}>
    {{ $slot }}
    <x-slot:footer>
        {{-- The modal footer stacks on mobile (flex-col-reverse) and both actions stretch to the
             full width there. Cancel is a direct flex child so it already did; the confirm button
             sits inside this form, which stretched while the button itself stayed content-width —
             that is why Delete looked narrower than Cancel on a phone. Making the form and its
             button full-width below `sm` gives the two buttons identical width; from `sm` up both
             revert to auto width, so the desktop footer is unchanged. --}}
        <button type="button" data-modal-close class="ui-button-secondary w-full sm:w-auto">@if($cancelIcon)<x-ui.icon :name="$cancelIcon" size="size-4" />@endif Cancel</button>
        <form method="POST" action="{{ $action }}" class="w-full sm:w-auto">
            @csrf
            @if(! in_array(strtoupper($method), ['GET', 'POST'], true)) @method($method) @endif
            @isset($formFields){{ $formFields }}@endisset
            <button class="{{ $destructive ? 'ui-button-danger' : 'ui-button-primary' }} w-full sm:w-auto">@if($confirmIcon)<x-ui.icon :name="$confirmIcon" size="size-4" />@endif {{ $confirmLabel }}</button>
        </form>
    </x-slot:footer>
</x-ui.modal>
