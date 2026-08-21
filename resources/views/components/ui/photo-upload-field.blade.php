@props(['inputName', 'label', 'existingPhotos' => [], 'removedInputName' => 'removed_photo_ids', 'maxFiles' => 10])

<div class="ui-panel p-4 sm:p-5" data-photo-upload-field data-photo-upload-max="{{ $maxFiles }}" data-photo-upload-removed-name="{{ $removedInputName }}">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="ui-label !mb-0">{{ $label }}</span>
        <button type="button" class="ui-button-secondary-compact" data-photo-upload-trigger>
            <x-ui.icon name="attachment" size="size-3.5" />Upload Photos
        </button>
        <input type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" name="{{ $inputName }}[]" data-photo-upload-input aria-label="{{ $label }}">
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 empty:hidden" data-photo-upload-grid>
        @foreach($existingPhotos as $photo)
            <div class="group relative overflow-hidden rounded-control border border-ui-border" data-photo-upload-existing-tile data-photo-id="{{ $photo['id'] }}">
                <img src="{{ $photo['url'] }}" alt="{{ $photo['caption'] ?? 'Uploaded photo' }}" class="h-24 w-full object-cover sm:h-28">
                <button type="button" class="ui-action-icon-button ui-action-icon-button-danger absolute right-1.5 top-1.5 bg-surface/90" data-photo-upload-remove-existing aria-label="Remove photo">
                    <x-ui.icon name="close" size="size-3.5" />
                </button>
            </div>
        @endforeach
    </div>

    <template data-photo-upload-tile-template>
        <div class="group relative overflow-hidden rounded-control border border-ui-border" data-photo-upload-new-tile>
            <img alt="New photo" class="h-24 w-full object-cover sm:h-28">
            <button type="button" class="ui-action-icon-button ui-action-icon-button-danger absolute right-1.5 top-1.5 bg-surface/90" data-photo-upload-remove-new aria-label="Remove photo">
                <x-ui.icon name="close" size="size-3.5" />
            </button>
        </div>
    </template>

    <p class="mt-2 text-xs text-text-muted">You can upload up to {{ $maxFiles }} photos. Max file size per photo is 10 MB.</p>
</div>
