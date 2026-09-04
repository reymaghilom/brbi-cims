@extends('layouts.check-encoding')

@section('title', ($residenceCheck ? 'Edit' : 'Add').' Residence Check · '.$clientFolder->display_name)

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $maxPhotos = config('cims.media.max_files_per_upload');
        $maxPhotoMb = (int) (config('cims.media.image_max_kilobytes') / 1024);
        $remarksMaxLength = 10000;
    @endphp
    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folder', 'url' => route('client-folders.index')],
        ['label' => $personName, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'Residence Check', 'url' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams)],
        ['label' => ($residenceCheck ? 'Edit' : 'Add').' Residence Check'],
    ]" />

    <header class="mb-5 flex items-start gap-3">
        <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="home" size="size-5" /></span>
        <div>
            <h1 class="ui-page-title">Residence Check</h1>
            <p class="mt-1 text-sm text-text-muted">Encode residence verification details and supporting photos.</p>
        </div>
    </header>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert" tabindex="-1"><p class="font-bold">Please correct the highlighted fields. No changes were saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @if($residenceCheck)
        <div data-editing-presence data-editing-type="residence_check" data-editing-id="{{ $residenceCheck->id }}" data-editing-label="Residence Check">
            <div data-editing-presence-banner hidden role="status" class="mb-3 flex items-start gap-2 rounded-control border border-progress/30 bg-progress-soft p-3 text-sm text-progress">
            <x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" />
            <span data-editing-presence-text></span>
        </div>
        </div>
    @endif

    <form id="residence-check-form" method="POST" action="{{ route('client-folders.residence-checks.store', $clientFolder) }}" enctype="multipart/form-data" class="flex flex-col gap-4 pb-20" data-unsaved-form data-residence-check-form data-cloud-storage-enabled="{{ $cloudStorageEnabled ? '1' : '0' }}">
        @csrf
        <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
        <input type="hidden" name="check_id" value="{{ $residenceCheck?->id }}">
        <input type="hidden" name="expected_updated_at" value="{{ $residenceCheck?->updated_at?->toISOString() }}">
        {{-- One fresh value per page load — identifies this one loaded copy of the form so
             SaveResidenceCheck can tell a duplicate submit of it (double-click, a retried request)
             apart from a genuinely separate Add Residence Check action, which always reloads this
             form and gets its own new token. Not sent at all on Edit — the save/create dedup guard
             only ever applies to a create (check_id blank). --}}
        @unless($residenceCheck)
            <input type="hidden" name="request_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        @endunless

        <div class="ui-panel divide-y divide-ui-border overflow-hidden">
            <details open class="group" aria-labelledby="residence-basic-info-title">
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <h2 id="residence-basic-info-title" class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">1</span>Basic Information</h2>
                    <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
                </summary>
                <div class="border-t border-ui-border p-4 sm:p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <span class="ui-label">{{ $personLabel }}</span>
                            <div class="relative"><x-ui.icon name="user" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" /><p class="ui-control bg-surface-subtle pl-9">{{ $personName }}</p></div>
                        </div>
                        <div>
                            <label for="residence-ci-date" class="ui-label">CI Date <span class="text-danger" aria-hidden="true">*</span></label>
                            <div class="relative"><x-ui.icon name="calendar" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" /><input id="residence-ci-date" name="ci_date" type="date" class="ui-control pl-9" required max="{{ now()->toDateString() }}" value="{{ old('ci_date', $defaultCiDate?->toDateString()) }}"></div>
                            @if($needsCiDateInput)
                                <p class="mt-1.5 text-xs text-text-muted">No CI/BI Report exists yet for this person. Enter the CI Date directly.</p>
                            @endif
                            <x-form.validation-message for="ci_date" />
                        </div>
                        <div>
                            <label for="residence-location" class="ui-label">Location <span class="text-danger" aria-hidden="true">*</span></label>
                            <div class="relative"><x-ui.icon name="pin" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" /><input id="residence-location" name="location" type="text" class="ui-control pl-9" required maxlength="2000" value="{{ old('location', $defaultLocation) }}"></div>
                            @if($needsLocationInput)
                                <p class="mt-1.5 text-xs text-text-muted">No saved address is available for this person yet. Enter the Residence Location.</p>
                            @endif
                            <x-form.validation-message for="location" />
                        </div>
                        <div class="sm:col-span-2">
                            <span class="ui-label">CI In-Charge</span>
                            <div class="flex min-h-11 w-full flex-wrap items-center justify-between gap-x-3 gap-y-1.5 rounded-control border border-ui-border-strong bg-surface-subtle px-3.5 py-2" data-companion-ci-picker data-companion-dialog-id="residence-check-companion-ci-dialog">
                                <div class="flex flex-wrap items-center gap-x-1 gap-y-1 text-xs" data-companion-ci-container data-header-form-id="residence-check-form">
                                    <span class="font-semibold" data-ci-primary-name>{{ $residenceCheck ? ($residenceCheck->investigator?->full_name ?? '—') : auth()->user()->full_name }}</span>
                                    <span class="flex flex-wrap items-center gap-x-1.5" data-companion-participant-list>
                                        @foreach($companions as $companion)
                                            <span class="flex items-center gap-1" data-companion-participant data-user-id="{{ $companion->id }}">
                                                <span aria-hidden="true">/</span>
                                                <span data-full-name>{{ $companion->full_name }}</span>
                                                <button type="button" class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border border-danger/40 bg-danger-soft text-[0.7rem] font-bold normal-case leading-none text-danger transition hover:border-danger hover:bg-danger hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40" data-companion-remove aria-label="Remove {{ $companion->full_name }}">&times;</button>
                                            </span>
                                        @endforeach
                                    </span>
                                    <div data-companion-hidden-inputs hidden>
                                        @foreach($companions as $companion)
                                            <input type="hidden" name="contributor_ids[]" value="{{ $companion->id }}" form="residence-check-form">
                                        @endforeach
                                    </div>
                                    <input type="hidden" name="contributor_ids_present" value="1" form="residence-check-form">
                                </div>
                                <button type="button" class="ui-button-secondary shrink-0" data-modal-open="residence-check-companion-ci-dialog" data-companion-dialog-trigger><span aria-hidden="true">+</span> Add Companion CI</button>
                            </div>
                        </div>
                        <div class="sm:col-span-2" data-char-counter-field>
                            <label for="remarks" class="ui-label">Remarks <span class="font-normal text-text-muted">(optional)</span></label>
                            <textarea id="remarks" name="remarks" rows="3" maxlength="{{ $remarksMaxLength }}" placeholder="Enter remarks (optional)..." class="ui-control" data-char-counter-input @error('remarks') aria-invalid="true" aria-describedby="remarks-error" @enderror>{{ old('remarks', $residenceCheck?->remarks) }}</textarea>
                            <p class="mt-1 text-right text-xs text-text-muted"><span data-char-counter-value>{{ strlen(old('remarks', $residenceCheck?->remarks ?? '')) }}</span> / {{ $remarksMaxLength }}</p>
                            <x-form.validation-message for="remarks" />
                        </div>
                    </div>
                </div>
            </details>

            <details @if($errors->has('photos') || $errors->has('photos.*')) open @endif class="group" aria-labelledby="residence-photos-title" data-photo-upload-field data-photo-upload-max="{{ $maxPhotos }}" data-photo-upload-removed-name="removed_photo_ids">
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <h2 id="residence-photos-title" class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">2</span>Residence Photos</h2>
                    <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
                </summary>
                <div class="border-t border-ui-border p-4 sm:p-5">
                    <div class="flex flex-wrap items-center justify-end gap-2 pb-3">
                        <button type="button" class="ui-button-primary-compact" data-photo-upload-trigger><x-ui.icon name="upload" size="size-3.5" />Upload Photos</button>
                    </div>
                    <input id="residence-photos-input" type="file" multiple accept="image/jpeg,image/png,image/webp" class="hidden" name="photos[]" data-photo-upload-input aria-label="Residence Photos">

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5" data-photo-upload-grid>
                        @foreach($existingPhotos as $photo)
                            <div class="group relative aspect-square overflow-hidden rounded-control border border-ui-border" data-photo-upload-existing-tile data-photo-id="{{ $photo['id'] }}">
                                <img src="{{ $photo['url'] }}" alt="{{ $photo['caption'] ?? 'Uploaded photo' }}" class="h-full w-full object-cover">
                                <button type="button" class="ui-action-icon-button ui-action-icon-button-danger !size-7 absolute right-1.5 top-1.5 bg-surface/90" data-photo-upload-remove-existing aria-label="Remove photo" title="Remove"><x-ui.icon name="close" size="size-3" /></button>
                                <div class="absolute inset-x-0 bottom-0 grid grid-cols-2 text-[11px] font-semibold text-white">
                                    <a href="{{ $photo['url'] }}" target="_blank" rel="noopener" class="flex items-center justify-center gap-1 bg-brand-sidebar/85 py-1.5 transition hover:bg-brand-sidebar" title="Preview"><x-ui.icon name="eye" size="size-3" class="pointer-events-none" />Preview</a>
                                    <button type="button" class="flex items-center justify-center gap-1 bg-danger/90 py-1.5 transition hover:bg-danger" data-photo-upload-remove-existing aria-label="Remove photo" title="Remove"><x-ui.icon name="trash" size="size-3" class="pointer-events-none" />Remove</button>
                                </div>
                            </div>
                        @endforeach

                        <button type="button" class="flex aspect-square flex-col items-center justify-center gap-1.5 rounded-control border-2 border-dashed border-brand-primary/40 bg-brand-soft/30 p-2 text-center transition hover:border-brand-primary hover:bg-brand-soft/50" data-photo-upload-dropzone data-photo-upload-trigger data-photo-upload-add-more-tile>
                            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="upload" size="size-4" /></span>
                            <span class="px-1 text-xs font-semibold leading-tight text-text-main">{{ count($existingPhotos) > 0 ? 'Add More Photos' : 'Add Residence Pictures' }}</span>
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

                    <p class="mt-2 text-xs font-semibold text-text-muted"><span data-photo-upload-count>{{ count($existingPhotos) }}</span> photos &middot; Maximum {{ $maxPhotos }} residence photos. &middot; JPG, PNG up to {{ $maxPhotoMb }}MB each</p>
                    <x-form.validation-message for="photos" />
                </div>
            </details>

            <details @if($errors->has('map_screenshot')) open @endif class="group" aria-labelledby="residence-map-title" data-map-screenshot-field>
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <h2 id="residence-map-title" class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">3</span>Map Screenshot <span class="font-normal text-text-muted">(Optional)</span></h2>
                    <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
                </summary>
                <div class="border-t border-ui-border p-4 sm:p-5">
                    <div class="mb-4 flex flex-col gap-3 rounded-control border border-brand-primary/25 bg-brand-soft/40 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-text-main">No map screenshot yet?</p>
                            <p class="mt-0.5 text-xs text-text-muted">Open Google Maps to find and check the residence, then screenshot and upload it below.</p>
                            @if(!$openInGoogleMapsUrl)
                                <p class="mt-0.5 text-xs font-semibold text-danger">No address available yet for this person — Open Google Maps is disabled until one is set.</p>
                            @endif
                        </div>
                        @if($openInGoogleMapsUrl)
                            <a href="{{ $openInGoogleMapsUrl }}" target="_blank" rel="noopener noreferrer" class="ui-button-primary-compact shrink-0" title="Open Google Maps"><x-ui.icon name="open" size="size-3.5" />Open Google Maps</a>
                        @else
                            <span class="ui-button-secondary-compact shrink-0 cursor-not-allowed opacity-50" aria-disabled="true" title="No address available yet"><x-ui.icon name="open" size="size-3.5" />Open Google Maps</span>
                        @endif
                    </div>

                    <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" name="map_screenshot" id="residence-map-screenshot-input" data-map-screenshot-input aria-label="Map screenshot">
                    <input type="hidden" name="remove_map_screenshot" value="0" data-map-screenshot-remove-flag>

                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
                        <div>
                            <label for="residence-map-screenshot-input" class="flex cursor-pointer items-center gap-3 rounded-control border-2 border-dashed border-brand-primary/40 bg-brand-soft/30 px-4 py-3 transition hover:border-brand-primary hover:bg-brand-soft/50" data-map-screenshot-dropzone @if($mapScreenshot) hidden @endif>
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="pin" size="size-4" /></span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-text-main">Add Map Screenshot</span>
                                    <span class="block text-xs text-text-muted">Click to browse or drag a screenshot here &middot; JPG, PNG up to {{ $maxPhotoMb }}MB</span>
                                </span>
                            </label>

                            <div @if(!$mapScreenshot) hidden @endif data-map-screenshot-preview-wrap>
                                <div class="group relative aspect-video overflow-hidden rounded-control border border-ui-border">
                                    <img src="{{ $mapScreenshot['url'] ?? '' }}" alt="Map screenshot" class="h-full w-full object-cover" data-map-screenshot-preview-img>
                                    <button type="button" class="ui-action-icon-button ui-action-icon-button-danger !size-7 absolute right-1.5 top-1.5 bg-surface/90" data-map-screenshot-remove aria-label="Remove screenshot" title="Remove"><x-ui.icon name="close" size="size-3" /></button>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" class="ui-button-secondary-compact" data-map-screenshot-replace><x-ui.icon name="upload" size="size-3.5" />Replace Screenshot</button>
                                    <button type="button" class="ui-button-danger-compact" data-map-screenshot-remove><x-ui.icon name="trash" size="size-3.5" />Remove Screenshot</button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-control border border-brand-primary/20 bg-brand-soft/40 p-3 text-xs leading-5 text-text-muted">
                            <p class="flex items-center gap-1.5 font-semibold text-brand-primary"><x-ui.icon name="info" size="size-3.5" />Tip</p>
                            <p class="mt-1">You can take a screenshot from Google Maps using your phone/PC and upload it here. Make sure the location pin and nearby landmarks are visible.</p>
                        </div>
                    </div>
                    <x-form.validation-message for="map_screenshot" />
                </div>
            </details>
        </div>
    </form>

    <div class="fixed inset-x-0 bottom-0 z-10 border-t border-ui-border bg-surface px-3 py-3 shadow-float sm:px-5">
        <div class="mx-auto flex max-w-6xl flex-col gap-2">
            <div class="hidden text-center" data-residence-check-save-status>
                <p class="text-xs font-semibold text-text-main" data-residence-check-save-status-text></p>
                <p class="mt-0.5 text-[11px] text-text-muted" data-residence-check-save-status-helper hidden>Upload time may vary depending on your internet connection.</p>
            </div>
            <div class="flex items-center justify-end gap-2">
                <button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>
                <button type="submit" form="residence-check-form" class="ui-button-primary" data-residence-check-submit data-residence-check-submit-label="{{ $residenceCheck ? 'Update Residence Check' : 'Save Residence Check' }}">
                    <span data-residence-check-submit-icon><x-ui.icon name="check" size="size-4" /></span>
                    <span class="hidden size-4 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white motion-reduce:animate-none" data-residence-check-submit-spinner aria-hidden="true"></span>
                    <span data-residence-check-submit-text>{{ $residenceCheck ? 'Update Residence Check' : 'Save Residence Check' }}</span>
                </button>
            </div>
        </div>
    </div>

    <x-ui.modal id="residence-check-companion-ci-dialog" title="Add Companion CI" description="Select one or more active Credit Investigators to add as companions on this Residence Check." size="max-w-md" data-companion-ci-dialog>
        <label for="residence-check-companion-ci-search" class="sr-only">Search Credit Investigator</label>
        <input type="search" id="residence-check-companion-ci-search" class="ui-control mb-3" placeholder="Search by name..." autocomplete="off" data-companion-search>
        <div class="flex max-h-64 flex-col gap-2 overflow-y-auto pr-1" data-companion-option-list>
            @forelse($activeCreditInvestigators as $candidate)
                <label class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-control border border-ui-border bg-surface px-3.5 py-2 text-sm font-medium hover:border-brand-primary hover:bg-brand-soft" data-companion-option data-user-id="{{ $candidate->id }}" data-full-name="{{ $candidate->full_name }}" data-search-name="{{ mb_strtolower($candidate->full_name) }}">
                    <input type="checkbox" class="size-4 border-ui-border-strong text-brand-primary focus:ring-brand-primary" value="{{ $candidate->id }}" data-companion-checkbox>
                    <span>{{ $candidate->full_name }}</span>
                </label>
            @empty
                <p class="text-sm text-text-muted">No other active Credit Investigators available.</p>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-text-muted" data-companion-search-empty hidden>No matching Credit Investigator found.</p>
        <x-slot:footer>
            <button type="button" class="ui-button-secondary" data-modal-close>Cancel</button>
            <button type="button" class="ui-button-primary" data-companion-confirm>Add Selected</button>
        </x-slot:footer>
    </x-ui.modal>
@endsection
