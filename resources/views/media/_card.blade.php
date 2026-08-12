@php
    $folder = $media->clientFolder ?? $clientFolder;
    $title = $media->label ?: ($media->media_type === App\Enums\MediaType::Photo ? 'Photo evidence' : 'Video evidence');
    $previewId = 'media-preview-'.$media->id;
@endphp

<article class="ui-card min-w-0 overflow-hidden" data-media-card data-media-type="{{ $media->media_type->value }}" data-media-category="{{ $media->category->value }}">
    <button type="button" class="group relative block aspect-[4/3] w-full overflow-hidden bg-surface-muted text-left focus-visible:outline-none" data-modal-open="{{ $previewId }}" aria-label="Preview {{ $title }}">
        @if($media->media_type === App\Enums\MediaType::Photo)
            <img src="{{ route('client-folders.media.content', [$folder, $media, 'thumbnail' => 1]) }}" alt="{{ $title }}" loading="lazy" decoding="async" class="size-full object-cover transition duration-200 group-hover:scale-[1.02]">
        @else
            <span class="grid size-full place-items-center bg-brand-soft text-brand-primary"><span class="grid size-14 place-items-center rounded-full border border-brand-primary/20 bg-surface"><x-ui.icon name="video" size="size-7" /></span><span class="sr-only">Open video preview</span></span>
        @endif
        <span class="absolute left-2.5 top-2.5 rounded-full bg-brand-sidebar/90 px-2.5 py-1 text-[0.68rem] font-bold text-white">{{ $media->media_type === App\Enums\MediaType::Photo ? 'Photo' : 'Video' }}</span>
    </button>
    <div class="p-3.5">
        <div class="flex items-start justify-between gap-3"><h2 class="line-clamp-2 min-w-0 font-bold leading-5">{{ $title }}</h2><span class="shrink-0 rounded-full bg-brand-soft px-2 py-1 text-[0.68rem] font-semibold text-brand-primary">{{ str($media->category->value)->replace('_', ' ')->title() }}</span></div>
        <p class="mt-2 text-xs text-text-muted">{{ $media->captured_at ? 'Taken '.$media->captured_at->timezone(config('cims.display_timezone'))->format('M j, Y') : 'Uploaded '.$media->created_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</p>
        @if($media->activities->isNotEmpty())<p class="mt-1 truncate text-xs text-text-muted">Activity: {{ $media->activities->pluck('name')->join(', ') }}</p>@endif
        @isset($showFolder)<p class="mt-1 truncate text-xs font-semibold text-brand-primary">{{ $folder->display_name }}</p>@endisset
        <div class="mt-3 flex flex-wrap gap-2 border-t border-ui-border pt-3">
            <button type="button" class="ui-button-secondary min-h-9 px-3 py-1.5 text-xs" data-modal-open="{{ $previewId }}">View</button>
            @isset($editable)
                <button type="button" class="ui-button-secondary min-h-9 px-3 py-1.5 text-xs" data-modal-open="media-edit-{{ $media->id }}">Edit</button>
                <button type="button" class="inline-flex min-h-9 items-center rounded-control px-3 py-1.5 text-xs font-semibold text-danger transition hover:bg-danger-soft" data-modal-open="media-remove-{{ $media->id }}">Remove</button>
            @endisset
        </div>
    </div>
</article>

<x-ui.modal :id="$previewId" :title="$title" size="max-w-4xl" data-media-preview data-media-id="{{ $media->id }}">
    <div class="overflow-hidden rounded-card bg-brand-sidebar/5">
        @if($media->media_type === App\Enums\MediaType::Photo)
            <img src="{{ route('client-folders.media.content', [$folder, $media]) }}" alt="{{ $title }}" loading="lazy" class="mx-auto max-h-[60vh] w-auto max-w-full object-contain">
        @else
            <video controls preload="none" class="mx-auto max-h-[60vh] w-full bg-black" aria-label="{{ $title }}"><source src="{{ route('client-folders.media.content', [$folder, $media]) }}" type="video/mp4">Your browser does not support MP4 video playback.</video>
        @endif
    </div>
    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
        <div><dt class="font-semibold text-text-muted">Category</dt><dd class="mt-0.5 font-medium">{{ str($media->category->value)->replace('_', ' ')->title() }}</dd></div>
        <div><dt class="font-semibold text-text-muted">Uploaded by</dt><dd class="mt-0.5 font-medium">{{ $media->uploader?->full_name ?? 'Authorized user' }}</dd></div>
        <div><dt class="font-semibold text-text-muted">Date uploaded</dt><dd class="mt-0.5 font-medium">{{ $media->created_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') }}</dd></div>
        <div><dt class="font-semibold text-text-muted">Date taken</dt><dd class="mt-0.5 font-medium">{{ $media->captured_at?->timezone(config('cims.display_timezone'))->format('M j, Y') ?? 'Not recorded' }}</dd></div>
        @if($media->incomeSource)<div><dt class="font-semibold text-text-muted">Income source</dt><dd class="mt-0.5 font-medium">{{ $media->incomeSource->business_name ?: $media->incomeSource->source_name }}</dd></div>@endif
        <div><dt class="font-semibold text-text-muted">Related CI activity</dt><dd class="mt-0.5 font-medium">{{ $media->activities->pluck('name')->join(', ') ?: 'Not linked' }}</dd></div>
        @if($media->remarks)<div class="sm:col-span-2"><dt class="font-semibold text-text-muted">Caption / Notes</dt><dd class="mt-1 whitespace-pre-line leading-6">{{ $media->remarks }}</dd></div>@endif
    </dl>
    <x-slot:footer>
        @if($mediaItems->count() > 1)
            <div class="mr-auto flex gap-2" aria-label="Media preview navigation">
                <button type="button" class="ui-button-secondary" data-media-previous>Previous</button>
                <button type="button" class="ui-button-secondary" data-media-next>Next</button>
            </div>
        @endif
        <a href="{{ route('client-folders.media.download', [$folder, $media]) }}" class="ui-button-secondary">Download</a>
        <button type="button" data-modal-close class="ui-button-primary">Close</button>
    </x-slot:footer>
</x-ui.modal>
