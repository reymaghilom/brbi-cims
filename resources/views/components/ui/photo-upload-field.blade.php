@props(['inputName', 'label', 'existingPhotos' => [], 'removedInputName' => 'removed_photo_ids', 'maxFiles' => 10, 'sectionNumber' => null, 'optional' => false, 'validationFor' => null, 'open' => null])

@php
    $maxPhotoMb = (int) (config('cims.media.image_max_kilobytes') / 1024);
    // Compact collapsed-by-default accordion row — but opens automatically when there's already
    // something saved here, so an edit never hides existing photos behind an extra click. A caller
    // that needs a different default (e.g. Business Check wanting every section collapsed
    // regardless of existing content, opening only on its own validation error) can override this
    // via the explicit `open` prop instead.
    $open = $open ?? (count($existingPhotos) > 0);
@endphp

<details @if($open) open @endif {{ $attributes->class('group') }} data-photo-upload-field data-photo-upload-max="{{ $maxFiles }}" data-photo-upload-removed-name="{{ $removedInputName }}">
    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
        <h2 class="flex items-center gap-2 text-sm font-bold text-text-main">
            @if($sectionNumber !== null)<span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">{{ $sectionNumber }}</span>@endif
            {{ $label }}
            @if($optional)<span class="font-normal text-text-muted">(Optional)</span>@endif
        </h2>
        <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
    </summary>
    <div class="border-t border-ui-border p-4 sm:p-5">
    <div class="flex flex-wrap items-center justify-end gap-2 pb-3">
        <button type="button" class="ui-button-primary-compact" data-photo-upload-trigger><x-ui.icon name="upload" size="size-3.5" />Upload Photos</button>
    </div>
    <input type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" name="{{ $inputName }}[]" data-photo-upload-input aria-label="{{ $label }}">

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" data-photo-upload-grid>
        @foreach($existingPhotos as $photo)
            <div class="group relative aspect-square overflow-hidden rounded-control border border-ui-border" data-photo-upload-existing-tile data-photo-id="{{ $photo['id'] }}">
                <img src="{{ $photo['url'] }}" alt="{{ $photo['caption'] ?? 'Uploaded photo' }}" class="h-full w-full object-cover">
                <button type="button" class="ui-action-icon-button ui-action-icon-button-danger !size-7 absolute right-1.5 top-1.5 bg-surface/90" data-photo-upload-remove-existing aria-label="Remove photo" title="Remove"><x-ui.icon name="close" size="size-3" /></button>
                @if(!empty($photo['uploaded_by']))
                    <p class="absolute inset-x-0 bottom-8 truncate bg-brand-sidebar/70 px-1.5 py-0.5 text-[10px] leading-tight text-white" title="{{ $photo['uploaded_by'] }}@if(!empty($photo['uploaded_at'])) &middot; {{ $photo['uploaded_at'] }}@endif">{{ $photo['uploaded_by'] }}</p>
                @endif
                <div class="absolute inset-x-0 bottom-0 grid grid-cols-2 text-[11px] font-semibold text-white">
                    <a href="{{ $photo['url'] }}" target="_blank" rel="noopener" class="flex items-center justify-center gap-1 bg-brand-sidebar/85 py-1.5 transition hover:bg-brand-sidebar" title="Preview"><x-ui.icon name="eye" size="size-3" class="pointer-events-none" />Preview</a>
                    <button type="button" class="flex items-center justify-center gap-1 bg-danger/90 py-1.5 transition hover:bg-danger" data-photo-upload-remove-existing aria-label="Remove photo" title="Remove"><x-ui.icon name="trash" size="size-3" class="pointer-events-none" />Remove</button>
                </div>
            </div>
        @endforeach

        <button type="button" class="flex aspect-square flex-col items-center justify-center gap-1.5 rounded-control border-2 border-dashed border-brand-primary/40 bg-brand-soft/30 p-2 text-center transition hover:border-brand-primary hover:bg-brand-soft/50" data-photo-upload-dropzone data-photo-upload-trigger data-photo-upload-add-more-tile>
            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="upload" size="size-4" /></span>
            <span class="px-1 text-xs font-semibold leading-tight text-text-main">{{ count($existingPhotos) > 0 ? 'Add More '.$label : 'Add '.$label }}</span>
            <span class="px-1 text-[10.5px] leading-tight text-text-muted">Click or drag photos here</span>
        </button>
    </div>

    <template data-photo-upload-tile-template>
        <div class="group relative aspect-square overflow-hidden rounded-control border border-ui-border" data-photo-upload-new-tile>
            <img alt="New photo" class="h-full w-full object-cover">
            <button type="button" class="ui-action-icon-button ui-action-icon-button-danger !size-7 absolute right-1.5 top-1.5 bg-surface/90" data-photo-upload-remove-new aria-label="Remove photo" title="Remove"><x-ui.icon name="close" size="size-3" /></button>
            <div class="absolute inset-x-0 bottom-0 grid grid-cols-2 text-[11px] font-semibold text-white">
                <a href="#" target="_blank" rel="noopener" class="flex items-center justify-center gap-1 bg-brand-sidebar/85 py-1.5 transition hover:bg-brand-sidebar" data-photo-upload-preview-new title="Preview"><x-ui.icon name="eye" size="size-3" class="pointer-events-none" />Preview</a>
                <button type="button" class="flex items-center justify-center gap-1 bg-danger/90 py-1.5 transition hover:bg-danger" data-photo-upload-remove-new aria-label="Remove photo" title="Remove"><x-ui.icon name="trash" size="size-3" class="pointer-events-none" />Remove</button>
            </div>
        </div>
    </template>

    <p class="mt-2 text-xs font-semibold text-text-muted"><span data-photo-upload-count>{{ count($existingPhotos) }}</span> of {{ $maxFiles }} photos &middot; JPG, PNG up to {{ $maxPhotoMb }}MB each</p>
    @if($validationFor)<x-form.validation-message :for="$validationFor" />@endif

    {{ $slot }}
    </div>
</details>
