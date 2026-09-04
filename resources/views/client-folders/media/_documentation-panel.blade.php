{{--
    The main documentation workflow for whichever tab is selected. Only one of these renders per
    request — the Residence/Business tabs in index.blade.php decide which. This content is never
    collapsible; the only hide/show control on the page belongs to the right-side support panel,
    and its "Show Preview" button is parked in this header (the same place the Saved Businesses
    panel keeps its "Show Activity" button).

    Expected props: $category ('residence'|'business'), $categoryLabel,
    $activeDocument, $cibiPrefill, $clientFolder, $activePerson,
    $personParams and $clientName.
--}}
@php($idPrefix = $category.'-documentation')
@php($formHasErrors = old('documentation_form') === $category)
@php($locationValue = $formHasErrors ? old('location') : ($activeDocument->location ?? $cibiPrefill ?? ''))

<form id="{{ $idPrefix }}-form" method="POST" enctype="multipart/form-data" action="{{ $activeDocument ? route('client-folders.media.documentation.update', [$clientFolder, $activeDocument] + $personParams) : route('client-folders.media.documentation.store', [$clientFolder] + $personParams) }}" data-documentation-save-form data-unsaved-form>
    @csrf
    @if($activeDocument) @method('PATCH') @endif
    <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
    <input type="hidden" name="category" value="{{ $category }}">
    <input type="hidden" name="documentation_form" value="{{ $category }}">
</form>

{{-- No section heading here: the selected tab above already names this workflow, so repeating
     "<Category> Documentation" inside the panel would be redundant. The panel takes its
     accessible name from its own tab instead. --}}
<section class="media-card p-4 sm:p-5" id="{{ $idPrefix }}-panel" role="tabpanel" aria-labelledby="{{ $idPrefix }}-tab">
    @if($category === 'business')
        <div class="flex flex-wrap items-start justify-between gap-3 rounded-control border border-brand-primary/15 bg-brand-soft/40 p-3.5 sm:p-4" data-business-context-header>
            <div class="min-w-0">
                <p class="text-[0.68rem] font-bold uppercase tracking-[0.14em] text-brand-primary">{{ $activeDocument ? 'Selected Business' : 'Business Documentation' }}</p>
                <h2 class="mt-1 break-words text-lg font-bold text-text-main">{{ $activeDocument?->businessDisplayName() ?? 'New Business' }}</h2>
                @if($activeDocument && filled($activeDocument->location))
                    <p class="mt-1 flex items-start gap-1.5 text-sm leading-5 text-text-muted"><x-ui.icon name="pin" size="mt-0.5 size-3.5 shrink-0" />{{ $activeDocument->location }}</p>
                @else
                    <p class="mt-1 text-sm text-text-muted">Enter the details and evidence below, then save this independent business record.</p>
                @endif
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if($activeDocument)
                    <span class="rounded-full bg-surface px-2.5 py-1 text-xs font-bold text-brand-primary shadow-xs">BD-{{ str_pad((string) $activeDocument->id, 6, '0', STR_PAD_LEFT) }}</span>
                    <span class="rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">Saved</span>
                @else
                    <span class="rounded-full border border-warning/25 bg-warning/10 px-2.5 py-1 text-xs font-bold text-text-main">Unsaved Draft</span>
                @endif
            </div>
        </div>
    @endif
    @if($category === 'business' && $activeDocument?->isLegacyBusiness())
        <div class="mt-4 rounded-control border border-warning/25 bg-warning/10 px-3 py-2 text-xs leading-5 text-text-main">
            This historical Business Documentation is preserved as Legacy / Unassigned. It has not been attached to a business automatically.
        </div>
    @endif
    @if($category === 'business')
        <div class="media-basic mt-4">
            <h3 class="mb-3 text-sm font-bold text-text-main">Business Details</h3>
            @if($activeDocument?->isLegacyBusiness())
                <span class="media-field-label"><x-ui.icon name="folder" size="size-3.5" />Business Name</span>
                <p class="flex min-h-[50px] items-center rounded-control border border-ui-border bg-surface-muted px-4 text-sm font-semibold text-text-main">Legacy / Unassigned</p>
            @else
                <label for="{{ $idPrefix }}-business-name" class="media-field-label"><x-ui.icon name="building" size="size-3.5" />Business Name <span class="text-danger">*</span></label>
                <div class="flex min-w-0 items-center gap-3">
                    <input id="{{ $idPrefix }}-business-name" form="{{ $idPrefix }}-form" name="business_name" value="{{ $formHasErrors ? old('business_name') : ($activeDocument->business_name ?? '') }}" class="ui-control !h-[50px] min-w-0" maxlength="255" required autocomplete="off">
                    @if($activeDocument)<span class="shrink-0 text-[0.68rem] font-bold text-text-subtle">BD-{{ str_pad((string) $activeDocument->id, 6, '0', STR_PAD_LEFT) }}</span>@endif
                </div>
                @if($formHasErrors)<x-form.validation-message for="business_name" />@endif
            @endif
        </div>
    @endif
    @if($activeDocument)
        <div class="flex flex-wrap items-center justify-end gap-2">
            {{-- Residence is ready with its location and main picture evidence only. Business
                 keeps its existing map-and-picture readiness rule. --}}
            @php($ready = filled($activeDocument->location) && $activeDocument->pictures->isNotEmpty() && ($category !== 'business' || $activeDocument->mapScreenshot))
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[0.68rem] font-bold {{ $ready ? 'bg-success/10 text-success' : 'bg-surface-muted text-text-muted' }}"><x-ui.icon :name="$ready ? 'check-circle' : 'clock'" size="size-3.5" />{{ $ready ? 'Ready to Send' : 'Draft' }}</span>
        </div>
    @endif

    {{-- Client Name (display only) and Location. Deliberately no section heading here —
         the active person is used internally for scoping but is not displayed as its own
         field, and this area otherwise leads straight into the workflow steps below. --}}
    <div class="media-basic mt-4">
        <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,9fr)_minmax(0,11fr)]">
            <div data-documentation-client-display>
                <span class="media-field-label"><x-ui.icon name="user" size="size-3.5" />Client Name</span>
                <p class="flex h-[50px] cursor-default select-text items-center rounded-control border border-ui-border bg-surface-muted px-4 text-sm font-semibold leading-5 text-text-main shadow-xs">{{ $clientName }}</p>
            </div>
            <div>
                <label for="{{ $idPrefix }}-location" class="block cursor-text" data-documentation-location-field>
                    <span class="media-field-label"><x-ui.icon name="pin" size="size-3.5" />{{ $category === 'residence' ? 'Residence Address' : 'Business Location' }}</span>
                    <input id="{{ $idPrefix }}-location" form="{{ $idPrefix }}-form" name="location" value="{{ $locationValue }}" class="ui-control !h-[50px] cursor-text !bg-white !px-4 transition-[border-color,box-shadow] duration-200 focus:!border-brand-primary focus:!ring-3 focus:!ring-brand-primary/15" maxlength="2000" required>
                </label>
                @if($formHasErrors)
                    <x-form.validation-message for="location" />
                @endif
            </div>
        </div>
    </div>

    @if($activeDocument)
        @php($documentation = $activeDocument)

        <div class="media-step">
            <div class="media-step-head">
                <h3 class="media-step-title"><span class="media-step-badge">1</span><x-ui.icon name="pin" size="size-4 text-text-muted" />Google Map Screenshot</h3>
                <span class="media-optional-badge">{{ $category === 'residence' ? 'Optional' : 'Required' }}</span>
            </div>
            <div class="mt-3" data-map-screenshot-field>
                <input form="{{ $idPrefix }}-form" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" name="map_screenshot" id="{{ $idPrefix }}-map-screenshot-input" data-map-screenshot-input aria-label="Map screenshot">
                <input form="{{ $idPrefix }}-form" type="hidden" name="remove_map_screenshot" value="0" data-map-screenshot-remove-flag>
                <div class="w-full max-w-lg">
                    <label for="{{ $idPrefix }}-map-screenshot-input" class="media-dropzone h-[170px] sm:h-[200px]" data-map-screenshot-dropzone @if($documentation->mapScreenshot) hidden @endif>
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="pin" size="size-5" /></span>
                        <span class="min-w-0"><span class="block text-sm font-semibold text-text-main">Upload Google Map Screenshot</span><span class="mt-0.5 block text-xs text-text-muted">JPG, PNG, or WEBP up to {{ (int) (config('cims.media.image_max_kilobytes') / 1024) }}MB</span></span>
                    </label>
                    <div @if(! $documentation->mapScreenshot) hidden @endif data-map-screenshot-preview-wrap>
                        <div class="media-preview-frame h-[170px] sm:h-[200px]">
                            @if($documentation->mapScreenshot)
                                <img src="{{ route('client-folders.media.content', [$clientFolder, $documentation->mapScreenshot]) }}" alt="Map screenshot" class="h-full w-full object-contain" data-map-screenshot-preview-img>
                            @else
                                <img alt="Map screenshot" class="h-full w-full object-contain" data-map-screenshot-preview-img>
                            @endif
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" class="ui-button-secondary-compact !px-2.5" data-map-screenshot-replace><x-ui.icon name="upload" size="size-3.5" />Replace</button>
                            <form method="POST" action="{{ route('client-folders.media.documentation.map-screenshot', [$clientFolder, $documentation] + $personParams) }}">
                                @csrf
                                <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                                <input type="hidden" name="remove_map_screenshot" value="1">
                                <button type="submit" class="ui-button-danger-compact !px-2.5"><x-ui.icon name="trash" size="size-3.5" />Remove</button>
                            </form>
                        </div>
                    </div>
                </div>
                <x-form.validation-message for="map_screenshot" />
                @if($category === 'business')
                    <p class="mt-2 text-xs leading-5 text-text-muted">A replacement is staged for this selected business until you click Save Changes. Remove affects only BD-{{ str_pad((string) $documentation->id, 6, '0', STR_PAD_LEFT) }}.</p>
                @endif
            </div>
        </div>

        <div class="media-step">
            <div class="media-step-head">
                <h3 class="media-step-title"><span class="media-step-badge">2</span><x-ui.icon name="media" size="size-4 text-text-muted" />{{ $categoryLabel }} Pictures <span class="media-count-badge {{ $documentation->pictures->count() ? '' : 'is-empty' }}">{{ $documentation->pictures->count() }}</span></h3>
                {{-- A <label> rather than a [data-photo-upload-trigger] button: the trigger hook is
                     scoped to the upload <form> below, and this header sits outside it. --}}
                <label for="{{ $idPrefix }}-pictures-input" class="ui-button-secondary-compact !px-2.5 cursor-pointer"><x-ui.icon name="plus" size="size-3.5" />Add Pictures</label>
            </div>
            <div class="mt-3" data-photo-upload-field data-photo-upload-max="{{ config('cims.media.max_files_per_upload') }}">
                <input form="{{ $idPrefix }}-form" id="{{ $idPrefix }}-pictures-input" type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" name="pictures[]" data-photo-upload-input aria-label="Pictures">
                @php($featuredBusinessPicture = $category === 'business' && $documentation->pictures->count() > 1 ? $documentation->pictures->first() : null)
                <div class="{{ $featuredBusinessPicture ? 'grid items-start gap-3 lg:grid-cols-[minmax(0,3fr)_minmax(16rem,2fr)]' : '' }}">
                    @if($featuredBusinessPicture)
                        <div class="media-tile min-h-[210px] sm:min-h-[300px] lg:min-h-[340px]" data-photo-upload-existing-tile data-photo-id="{{ $featuredBusinessPicture->id }}">
                            <img src="{{ route('client-folders.media.content', [$clientFolder, $featuredBusinessPicture]) }}" alt="Primary business picture" class="h-full w-full object-cover">
                            <span class="media-tile-scrim" aria-hidden="true"></span>
                            <span class="absolute bottom-2 left-2 rounded-full bg-slate-950/65 px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wide text-white">Primary</span>
                            <form method="POST" action="{{ route('client-folders.media.documentation.destroy-media', [$clientFolder, $documentation, $featuredBusinessPicture] + $personParams) }}" class="media-tile-action">@csrf @method('DELETE')<button type="submit" class="ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove primary picture" title="Remove"><x-ui.icon name="close" size="size-3" /></button></form>
                        </div>
                    @endif
                    <div class="media-grid {{ $featuredBusinessPicture ? 'media-grid-secondary' : '' }}" data-photo-upload-grid>
                        @foreach($documentation->pictures as $picture)
                            @continue($featuredBusinessPicture?->is($picture))
                            <div class="media-tile" data-photo-upload-existing-tile data-photo-id="{{ $picture->id }}">
                                <img src="{{ route('client-folders.media.content', [$clientFolder, $picture]) }}" alt="Picture" class="h-full w-full object-cover">
                                <span class="media-tile-scrim" aria-hidden="true"></span>
                                <form method="POST" action="{{ route('client-folders.media.documentation.destroy-media', [$clientFolder, $documentation, $picture] + $personParams) }}" class="media-tile-action">@csrf @method('DELETE')<button type="submit" class="ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove picture" title="Remove"><x-ui.icon name="close" size="size-3" /></button></form>
                            </div>
                        @endforeach
                        <button type="button" class="media-add-tile" data-photo-upload-dropzone data-photo-upload-trigger data-photo-upload-add-more-tile>
                            <span class="grid size-8 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="plus" size="size-4" /></span><span class="px-1 text-xs font-semibold leading-tight text-text-main">Add Pictures</span>
                        </button>
                    </div>
                </div>
                <template data-photo-upload-tile-template>
                    <div class="media-tile" data-photo-upload-new-tile>
                        <img alt="New picture" class="h-full w-full object-cover"><span class="media-tile-scrim" aria-hidden="true"></span>
                        <button type="button" class="media-tile-action ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove picture" title="Remove" data-photo-upload-remove-new><x-ui.icon name="close" size="size-3" /></button>
                    </div>
                </template>
                @if($category === 'business')
                    <p class="mt-2 text-xs leading-5 text-text-muted">Add Pictures stages new evidence for BD-{{ str_pad((string) $documentation->id, 6, '0', STR_PAD_LEFT) }}. Click Save Changes to persist it; existing pictures remain unless you remove them.</p>
                @endif
            </div>
        </div>

        @if($category !== 'business')
        <div class="media-step">
            <div class="max-w-xl">
                @include('client-folders.media._remarks-field', ['category' => $category, 'idPrefix' => $idPrefix, 'activeDocument' => $activeDocument, 'formHasErrors' => $formHasErrors])
            </div>
        </div>
        @endif

        <div class="media-step">
            <div class="media-step-head">
                <h3 class="media-step-title"><span class="media-step-badge">3</span><x-ui.icon name="video" size="size-4 text-text-muted" />{{ $categoryLabel }} Video <span class="media-optional-badge">Optional</span> <span class="media-count-badge {{ $documentation->videos->count() ? '' : 'is-empty' }}">{{ $documentation->videos->count() }}</span></h3>
                <label for="{{ $idPrefix }}-videos-input" class="ui-button-secondary-compact !px-2.5 cursor-pointer"><x-ui.icon name="plus" size="size-3.5" />Add Videos</label>
            </div>
            <div class="mt-3" data-video-upload-field data-video-upload-max="{{ config('cims.media.max_files_per_upload') }}">
                <input form="{{ $idPrefix }}-form" id="{{ $idPrefix }}-videos-input" type="file" multiple accept="video/mp4" class="hidden" name="videos[]" data-video-upload-input aria-label="Videos">
                <div class="media-grid" data-video-upload-grid>
                    @foreach($documentation->videos as $video)
                        <div class="media-tile media-tile-video" data-video-upload-existing-tile data-video-id="{{ $video->id }}">
                            <video class="saved-video-preview" controls preload="metadata" playsinline data-saved-video-preview aria-label="Saved {{ strtolower($categoryLabel) }} video preview">
                                <source src="{{ route('client-folders.media.content', [$clientFolder, $video]) }}" type="{{ $video->mime_type }}">
                                <a href="{{ route('client-folders.media.content', [$clientFolder, $video]) }}" target="_blank" rel="noopener">View saved video</a>
                            </video>
                            <form method="POST" action="{{ route('client-folders.media.documentation.destroy-media', [$clientFolder, $documentation, $video] + $personParams) }}" class="media-tile-action">@csrf @method('DELETE')<button type="submit" class="ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove video" title="Remove"><x-ui.icon name="close" size="size-3" /></button></form>
                        </div>
                    @endforeach
                    <button type="button" class="media-add-tile" data-video-upload-dropzone data-video-upload-trigger data-video-upload-add-more-tile>
                        <span class="grid size-8 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="plus" size="size-4" /></span><span class="px-1 text-xs font-semibold leading-tight text-text-main">Add Videos</span>
                    </button>
                </div>
                <template data-video-upload-tile-template>
                    <div class="media-tile media-tile-video" data-video-upload-new-tile>
                        <span class="media-play-badge"><x-ui.icon name="video" size="size-5" /></span>
                        <button type="button" class="media-tile-action ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove video" title="Remove" data-video-upload-remove-new><x-ui.icon name="close" size="size-3" /></button>
                    </div>
                </template>
                <p class="mt-2 text-xs leading-5 text-text-muted">MP4 up to {{ (int) ceil(config('cims.media.video_max_kilobytes') / 1024) }} MB (application limit). Current PHP server file limit: {{ config('cims.media.php_upload_max_filesize') }}.</p>
                @if($category === 'business')
                    <p class="mt-1 text-xs leading-5 text-text-muted">Add Videos stages new evidence for this selected business. Click Save Changes to persist it.</p>
                @endif
            </div>
        </div>
        @if($category === 'business')
            <div class="media-step">
                <div class="max-w-xl">
                    @include('client-folders.media._remarks-field', ['category' => $category, 'idPrefix' => $idPrefix, 'activeDocument' => $activeDocument, 'formHasErrors' => $formHasErrors])
                </div>
            </div>
        @endif
    @else
        {{-- New-set controls submit with the main form. The backend creates the real
             documentation record and attaches these staged files during the same first Save
             Locally request, so no empty placeholder record is needed. --}}
        <div class="media-step" data-map-screenshot-field>
            <div class="media-step-head">
                <h3 class="media-step-title"><span class="media-step-badge">1</span><x-ui.icon name="pin" size="size-4 text-text-muted" />Google Map Screenshot</h3>
                <span class="media-optional-badge">{{ $category === 'residence' ? 'Optional' : 'Required' }}</span>
            </div>
            <input form="{{ $idPrefix }}-form" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" name="map_screenshot" id="{{ $idPrefix }}-map-screenshot-input" data-map-screenshot-input aria-label="Map screenshot">
            <input form="{{ $idPrefix }}-form" type="hidden" name="remove_map_screenshot" value="0" data-map-screenshot-remove-flag>
            <div class="mt-3 w-full max-w-lg">
                <label for="{{ $idPrefix }}-map-screenshot-input" class="media-dropzone h-[170px] sm:h-[200px]" data-map-screenshot-dropzone>
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="pin" size="size-5" /></span>
                    <span class="min-w-0"><span class="block text-sm font-semibold text-text-main">Upload Google Map Screenshot</span><span class="mt-0.5 block text-xs text-text-muted">JPG, PNG, or WEBP up to {{ (int) (config('cims.media.image_max_kilobytes') / 1024) }}MB</span></span>
                </label>
                <div hidden data-map-screenshot-preview-wrap>
                    <div class="media-preview-frame h-[170px] sm:h-[200px]"><img alt="Map screenshot preview" class="h-full w-full object-contain" data-map-screenshot-preview-img></div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="ui-button-secondary-compact !px-2.5" data-map-screenshot-replace><x-ui.icon name="upload" size="size-3.5" />Replace</button>
                        <button type="button" class="ui-button-danger-compact !px-2.5" data-map-screenshot-remove><x-ui.icon name="trash" size="size-3.5" />Remove</button>
                    </div>
                </div>
            </div>
            @if($formHasErrors)<x-form.validation-message for="map_screenshot" />@endif
        </div>

        <div class="media-step" data-photo-upload-field data-photo-upload-max="{{ config('cims.media.max_files_per_upload') }}">
            <div class="media-step-head">
                <h3 class="media-step-title"><span class="media-step-badge">2</span><x-ui.icon name="media" size="size-4 text-text-muted" />{{ $categoryLabel }} Pictures <span class="media-count-badge is-empty" data-photo-upload-count>0</span></h3>
                <label for="{{ $idPrefix }}-pictures-input" class="ui-button-secondary-compact !px-2.5 cursor-pointer"><x-ui.icon name="plus" size="size-3.5" />Add Pictures</label>
            </div>
            <input form="{{ $idPrefix }}-form" id="{{ $idPrefix }}-pictures-input" type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" name="pictures[]" data-photo-upload-input aria-label="Pictures">
            <div class="media-grid mt-3" data-photo-upload-grid>
                <button type="button" class="media-add-tile" data-photo-upload-dropzone data-photo-upload-trigger data-photo-upload-add-more-tile>
                    <span class="grid size-8 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="plus" size="size-4" /></span><span class="px-1 text-xs font-semibold leading-tight text-text-main">Add Pictures</span>
                </button>
            </div>
            <template data-photo-upload-tile-template>
                <div class="media-tile" data-photo-upload-new-tile>
                    <img alt="New picture" class="h-full w-full object-cover"><span class="media-tile-scrim" aria-hidden="true"></span>
                    <button type="button" class="media-tile-action ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove picture" title="Remove" data-photo-upload-remove-new><x-ui.icon name="close" size="size-3" /></button>
                </div>
            </template>
            @if($formHasErrors)<x-form.validation-message for="pictures" />@endif
        </div>

        @if($category !== 'business')
            <div class="media-step"><div class="max-w-xl">@include('client-folders.media._remarks-field', ['category' => $category, 'idPrefix' => $idPrefix, 'activeDocument' => null, 'formHasErrors' => $formHasErrors])</div></div>
        @endif

        <div class="media-step" data-video-upload-field data-video-upload-max="{{ config('cims.media.max_files_per_upload') }}">
            <div class="media-step-head">
                <h3 class="media-step-title"><span class="media-step-badge">3</span><x-ui.icon name="video" size="size-4 text-text-muted" />{{ $categoryLabel }} Video <span class="media-optional-badge">Optional</span></h3>
                <label for="{{ $idPrefix }}-videos-input" class="ui-button-secondary-compact !px-2.5 cursor-pointer"><x-ui.icon name="plus" size="size-3.5" />Add Videos</label>
            </div>
            <input form="{{ $idPrefix }}-form" id="{{ $idPrefix }}-videos-input" type="file" multiple accept="video/mp4" class="hidden" name="videos[]" data-video-upload-input aria-label="Videos">
            <div class="media-grid mt-3" data-video-upload-grid>
                <button type="button" class="media-add-tile" data-video-upload-dropzone data-video-upload-trigger data-video-upload-add-more-tile>
                    <span class="grid size-8 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="plus" size="size-4" /></span><span class="px-1 text-xs font-semibold leading-tight text-text-main">Add Videos</span>
                </button>
            </div>
            <template data-video-upload-tile-template>
                <div class="media-tile media-tile-video" data-video-upload-new-tile>
                    <span class="media-play-badge"><x-ui.icon name="video" size="size-5" /></span>
                    <button type="button" class="media-tile-action ui-action-icon-button ui-action-icon-button-danger !size-7 bg-surface/95 shadow-sm" aria-label="Remove video" title="Remove" data-video-upload-remove-new><x-ui.icon name="close" size="size-3" /></button>
                </div>
            </template>
            <p class="mt-2 text-xs leading-5 text-text-muted">MP4 up to {{ (int) ceil(config('cims.media.video_max_kilobytes') / 1024) }} MB (application limit). Current PHP server file limit: {{ config('cims.media.php_upload_max_filesize') }}.</p>
            @if($formHasErrors)<x-form.validation-message for="videos" />@endif
        </div>
        @if($category === 'business')
            <div class="media-step"><div class="max-w-xl">@include('client-folders.media._remarks-field', ['category' => $category, 'idPrefix' => $idPrefix, 'activeDocument' => null, 'formHasErrors' => $formHasErrors])</div></div>

            @php($returnBusinessId = request()->integer('return_business_documentation'))
            @php($cancelBusiness = $categoryDocuments->firstWhere('id', $returnBusinessId) ?? $categoryDocuments->first())
            @php($cancelUrl = route('client-folders.media.index', [$clientFolder] + $personParams + ['tab' => 'business'] + ($cancelBusiness ? ['business_documentation' => $cancelBusiness->id] : [])))
            <div class="media-step flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end" data-business-draft-actions>
                <a href="{{ $cancelUrl }}" class="ui-button-secondary w-full sm:w-auto" data-business-draft-cancel><x-ui.icon name="close" size="size-4" />Cancel</a>
                <button type="submit" form="{{ $idPrefix }}-form" class="ui-button-primary w-full sm:w-auto" data-documentation-save-submit><x-ui.icon name="download" size="size-4" /><span data-documentation-save-label>Save Business</span></button>
            </div>
        @endif
    @endif
</section>
