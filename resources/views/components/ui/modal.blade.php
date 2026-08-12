@props(['id', 'title', 'description' => null, 'size' => 'max-w-lg'])

<dialog id="{{ $id }}" {{ $attributes->class("ui-modal-dialog fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] $size rounded-panel border border-ui-border bg-surface p-0 text-text-main shadow-float backdrop:bg-brand-sidebar/60 backdrop:backdrop-blur-[1px]") }} aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-description" @endif>
    <div class="flex items-start justify-between gap-3 border-b border-ui-border px-4 py-3.5 sm:px-5">
        <div><h2 id="{{ $id }}-title" class="text-lg font-bold">{{ $title }}</h2>@if($description)<p id="{{ $id }}-description" class="mt-1 text-sm text-text-muted">{{ $description }}</p>@endif</div>
        <button type="button" data-modal-close class="ui-icon-button -mr-2 -mt-2" aria-label="Close dialog"><x-ui.icon name="close" /></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-4 sm:p-5">{{ $slot }}</div>
    @isset($footer)<footer class="flex flex-col-reverse gap-2 border-t border-ui-border bg-surface-muted px-4 py-3.5 sm:flex-row sm:justify-end sm:px-5">{{ $footer }}</footer>@endisset
</dialog>
