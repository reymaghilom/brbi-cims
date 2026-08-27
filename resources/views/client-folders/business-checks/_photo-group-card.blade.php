{{-- One Photo Group card: caption + its own multi-file photo-upload-field, reused both for
     already-rendered groups (real $index/$group) and, with index '__INDEX__' and no $group, as the
     <template> a new "+ Add Photo Group" row is cloned from (see the data-photo-group-repeater
     script in app.js).

     The first/default group ($isFirst) is deliberately presented as a plain "Business Photos"
     upload area — no "Photo Group 1" heading, no border/card, no Remove Group button — so a CI who
     never needs more than one group never has to think about "groups" at all; it's still a real
     Photo Group underneath (same photo_groups[0][...] fields, same backend sync), just styled to
     look like the simple single-group UI this feature replaced. Every group added via "+ Add Photo
     Group" is never first, so it always gets the numbered heading + card + Remove Group. --}}
@php
    $group = $group ?? ['id' => '', 'caption' => '', 'legacy' => false, 'photos' => []];
    $maxFiles = config('cims.media.max_files_per_upload');
    $maxPhotoMb = (int) (config('cims.media.image_max_kilobytes') / 1024);
    $isLegacy = $group['legacy'] ?? false;
    $isFirst = $isFirst ?? false;
    $groupNumber = is_int($index) ? $index + 1 : '';
@endphp
<div class="{{ $isFirst ? '' : 'rounded-control border border-ui-border p-3 sm:p-4' }}" data-photo-group-row @if($isFirst) data-photo-group-first @endif>
    <input type="hidden" name="photo_groups[{{ $index }}][id]" value="{{ $group['id'] }}">
    <input type="hidden" name="photo_groups[{{ $index }}][_delete]" value="0" data-delete-field>
    @if($isLegacy)
        @foreach($group['photos'] as $photo)
            <input type="hidden" name="photo_groups[{{ $index }}][legacy_photo_ids][]" value="{{ $photo['id'] }}">
        @endforeach
    @endif
    @unless($isFirst)
        <div class="mb-3 flex items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-text-main">Photo Group <span data-photo-group-number>{{ $groupNumber }}</span></h3>
            <button type="button" class="ui-button-danger-compact shrink-0" data-photo-group-remove><x-ui.icon name="trash" size="size-3.5" />Remove Group</button>
        </div>
    @endunless
    @if($isFirst)
        {{-- The default Business Photos area is a plain upload area with no caption field of its
             own — but a check saved from an earlier version of this feature may already have a
             caption stored on this first group, so its value still round-trips unchanged via this
             hidden input rather than being silently wiped out by a save that never displayed it. --}}
        <input type="hidden" name="photo_groups[{{ $index }}][caption]" value="{{ $group['caption'] }}">
    @else
        <div class="mb-3">
            <label class="ui-label">Caption / Remarks <span class="font-normal text-text-muted">(Optional)</span></label>
            <textarea name="photo_groups[{{ $index }}][caption]" rows="2" maxlength="2000" class="ui-control">{{ $group['caption'] }}</textarea>
        </div>
    @endif
    <div data-photo-upload-field data-photo-upload-max="{{ $maxFiles }}" data-photo-upload-removed-name="photo_groups[{{ $index }}][removed_photo_ids]">
        <div class="flex flex-wrap items-center justify-end gap-2 pb-3">
            <button type="button" class="ui-button-primary-compact" data-photo-upload-trigger><x-ui.icon name="upload" size="size-3.5" />Upload Photos</button>
        </div>
        <input type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" name="photo_groups[{{ $index }}][photos][]" data-photo-upload-input aria-label="Group photos">

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" data-photo-upload-grid>
            @foreach($group['photos'] as $photo)
                <div class="group relative aspect-square overflow-hidden rounded-control border border-ui-border" data-photo-upload-existing-tile data-photo-id="{{ $photo['id'] }}">
                    <img src="{{ $photo['url'] }}" alt="Uploaded photo" class="h-full w-full object-cover">
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
                <span class="px-1 text-xs font-semibold leading-tight text-text-main">{{ count($group['photos']) > 0 ? 'Add More Photos' : 'Add Photos' }}</span>
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

        <p class="mt-2 text-xs font-semibold text-text-muted"><span data-photo-upload-count>{{ count($group['photos']) }}</span> of {{ $maxFiles }} photos &middot; JPG, PNG up to {{ $maxPhotoMb }}MB each</p>
    </div>
</div>
