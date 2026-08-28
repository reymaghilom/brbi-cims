@extends('layouts.check-encoding')

@section('title', ($businessCheck ? 'Edit' : 'Add').' Business Check · '.$clientFolder->display_name)

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    @php($isApplicant = ! ($activePerson ?? null))
    @php($hasExistingBusinesses = $businesses->isNotEmpty())
    <div class="mx-auto w-full max-w-5xl">
    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folder', 'url' => route('client-folders.index')],
        ['label' => $personName, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'Business Check', 'url' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams)],
        ['label' => ($businessCheck ? 'Edit' : 'Add').' Business Check'],
    ]" />

    <header class="mb-5 flex items-start gap-3">
        <span class="grid size-11 shrink-0 place-items-center rounded-control bg-success-soft text-success"><x-ui.icon name="building" size="size-5" /></span>
        <div>
            <h1 class="ui-page-title">Business Check</h1>
            <p class="mt-1 text-sm text-text-muted">Encode business verification details and upload photos.</p>
        </div>
    </header>

    {{-- The missing-required-photo error already gets its own temporary toast (below) plus the
         inline field-level message next to Business Photos — showing this same message a third
         time, as a bullet in a persistent banner, is redundant for that one case. Every other
         validation error (Location, CI Date, Map Screenshot, Competitor Photos, etc.) still has no
         toast of its own, so it keeps this banner exactly as before. --}}
    @if($errors->any() && ! $errors->has('photo_groups'))
        <div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert" tabindex="-1"><p class="font-bold">Please correct the highlighted fields. No changes were saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @if($errors->has('photo_groups'))
        {{-- Same temporary toast UX as Residence Check's own missing-photo validation error (see
             the [data-residence-check-form] XHR handler in app.js) — this form is still a plain
             POST + redirect (not XHR), so the toast is triggered client-side off this marker once
             the page reloads, rather than from a JSON error response. --}}
        <span hidden data-business-check-photo-error="{{ $errors->first('photo_groups') }}"></span>
    @endif

    @if($businessCheck)
        <div data-editing-presence data-editing-type="business_check" data-editing-id="{{ $businessCheck->id }}" data-editing-label="Business Check">
            <div data-editing-presence-banner hidden role="status" class="mb-3 flex items-start gap-2 rounded-control border border-progress/30 bg-progress-soft p-3 text-sm text-progress">
                <x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" />
                <span data-editing-presence-text></span>
            </div>
        </div>
    @endif

        <form id="business-check-form" method="POST" action="{{ route('client-folders.business-checks.store', $clientFolder) }}" enctype="multipart/form-data" class="flex flex-col gap-4 pb-20" data-unsaved-form data-business-check-form>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
            <input type="hidden" name="check_id" value="{{ $businessCheck?->id }}">
            <input type="hidden" name="expected_updated_at" value="{{ $businessCheck?->updated_at?->toISOString() }}">

            <div class="ui-panel divide-y divide-ui-border overflow-hidden">
                <details open class="group" aria-labelledby="business-basic-info-title">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                        <h2 id="business-basic-info-title" class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">1</span>Basic Information</h2>
                        <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
                    </summary>
                    <div class="border-t border-ui-border p-4 sm:p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <span class="ui-label">Applicant / Co-Maker Name</span>
                                <div class="relative"><x-ui.icon name="user" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" /><p class="ui-control bg-surface-subtle pl-9">{{ $personName }}</p></div>
                            </div>
                            <div>
                                <label for="business-check-income-source" class="ui-label">Business / Income Source <span class="text-danger" aria-hidden="true">*</span></label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <select id="business-check-income-source" name="income_source_id" class="ui-control min-w-0 flex-1" required data-business-check-income-source-select>
                                        <option value="">Select {{ $isApplicant ? 'an existing Applicant business' : 'a business' }}</option>
                                        @foreach($businesses as $business)
                                            @php($isCurrentBusiness = $businessCheck && (int) $businessCheck->income_source_id === (int) $business['id'])
                                            @php($alreadyChecked = filled($business['existing_check_id']) && ! $isCurrentBusiness)
                                            <option value="{{ $business['id'] }}" data-location="{{ $business['location'] }}" data-ci-date="{{ $business['ci_date'] }}" data-report-complete="{{ $business['report_complete'] ? '1' : '0' }}" @disabled($alreadyChecked) @selected(old('income_source_id', $businessCheck?->income_source_id) == $business['id'])>{{ $business['name'] }}{{ $alreadyChecked ? ' — Business Check already exists.' : '' }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="inline-flex h-11 shrink-0 items-center gap-1 rounded-control border border-brand-primary bg-brand-soft px-3 text-sm font-semibold text-brand-primary transition hover:bg-brand-primary hover:text-white disabled:cursor-not-allowed disabled:border-ui-border disabled:bg-surface-subtle disabled:text-text-muted" data-business-check-add-new data-modal-open="business-check-quick-add-dialog" @if($hasExistingBusinesses) disabled data-lock-when-existing="true" @endif><span aria-hidden="true">+</span> Add New Business</button>
                                </div>
                                @if($hasExistingBusinesses)
                                    <p class="mt-1.5 text-xs text-text-muted">An existing business is already available. Please select it first to avoid duplicate entries.</p>
                                    <button type="button" class="mt-1 text-xs font-semibold text-brand-primary underline-offset-2 hover:underline" data-business-check-add-another>Add another business</button>
                                @else
                                    <p class="mt-1.5 text-xs text-text-muted" data-business-source-helper>Select an existing business for this person or add one if the Business Report has not been created yet.</p>
                                @endif
                                <x-form.validation-message for="income_source_id" />
                            </div>
                            <div>
                                <label for="business-check-location" class="ui-label">Location <span class="text-danger" aria-hidden="true">*</span></label>
                                <div class="relative"><x-ui.icon name="pin" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" /><input id="business-check-location" name="location" type="text" class="ui-control pl-9" required placeholder="Select a business, or enter the location" value="{{ old('location', $currentLocation) }}" data-business-check-location @error('location') aria-invalid="true" aria-describedby="location-error" @enderror></div>
                                <p class="mt-1.5 text-xs text-text-muted">Shared with this business's Business Report — editing it here updates that same address.</p>
                                <x-form.validation-message for="location" />
                            </div>
                            <div>
                                <label for="ci_date" class="ui-label">CI Date <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="ci_date" name="ci_date" type="date" class="ui-control" required value="{{ old('ci_date', $currentCiDate) }}" data-business-check-ci-date @error('ci_date') aria-invalid="true" aria-describedby="ci_date-error" @enderror>
                                <p class="mt-1.5 text-xs text-text-muted">Shared with this business's Business Report — editing it here updates that same date.</p>
                                <x-form.validation-message for="ci_date" />
                            </div>
                            <div class="sm:col-span-2">
                                <span class="ui-label">CI In-Charge</span>
                                <div class="flex min-h-11 w-full flex-wrap items-center justify-between gap-x-3 gap-y-1.5 rounded-control border border-ui-border-strong bg-surface-subtle px-3.5 py-2" data-companion-ci-picker data-companion-dialog-id="business-check-companion-ci-dialog">
                                    <div class="flex flex-wrap items-center gap-x-1 gap-y-1 text-xs" data-companion-ci-container data-header-form-id="business-check-form">
                                        <span class="font-semibold" data-ci-primary-name>{{ $businessCheck ? ($businessCheck->investigator?->full_name ?? '—') : auth()->user()->full_name }}</span>
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
                                                <input type="hidden" name="contributor_ids[]" value="{{ $companion->id }}" form="business-check-form">
                                            @endforeach
                                        </div>
                                        <input type="hidden" name="contributor_ids_present" value="1" form="business-check-form">
                                    </div>
                                    <button type="button" class="ui-button-secondary shrink-0" data-modal-open="business-check-companion-ci-dialog" data-companion-dialog-trigger><span aria-hidden="true">+</span> Add Companion CI</button>
                                </div>
                            </div>
                            <div class="sm:col-span-2" data-char-counter-field>
                                <label for="remarks" class="ui-label">Remarks <span class="font-normal text-text-muted">(optional)</span></label>
                                <textarea id="remarks" name="remarks" rows="3" maxlength="10000" placeholder="Enter remarks (optional)..." class="ui-control" data-char-counter-input @error('remarks') aria-invalid="true" aria-describedby="remarks-error" @enderror>{{ old('remarks', $businessCheck?->remarks) }}</textarea>
                                <p class="mt-1 text-right text-xs text-text-muted"><span data-char-counter-value>{{ strlen(old('remarks', $businessCheck?->remarks ?? '')) }}</span> / 10000</p>
                                <x-form.validation-message for="remarks" />
                            </div>
                        </div>
                    </div>
                </details>

                <details @if($errors->has('photo_groups') || $errors->has('photo_groups.*')) open @endif class="group" aria-labelledby="business-photos-title" data-photo-group-repeater>
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                        <h2 id="business-photos-title" class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">2</span>Business Photos</h2>
                        <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
                    </summary>
                    <div class="border-t border-ui-border p-4 sm:p-5">
                        <div class="flex flex-col gap-4" data-photo-group-rows>
                            @foreach($photoGroups as $index => $group)
                                @include('client-folders.business-checks._photo-group-card', ['index' => $index, 'group' => $group, 'isFirst' => $index === 0])
                            @endforeach
                        </div>

                        <p class="mb-2 mt-4 text-xs text-text-muted">Use "+ Add Photo Group" when you need a separate set of photos with its own caption/remarks.</p>
                        <button type="button" class="ui-button-secondary-compact" data-photo-group-add><span aria-hidden="true">+</span> Add Photo Group</button>

                        <template data-photo-group-template>
                            @include('client-folders.business-checks._photo-group-card', ['index' => '__INDEX__', 'group' => null, 'isFirst' => false])
                        </template>

                        <x-form.validation-message for="photo_groups" />
                    </div>
                </details>

                <details @if($errors->has('map_screenshot')) open @endif class="group" aria-labelledby="business-map-screenshot-title" data-map-screenshot-field>
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
                        <h2 id="business-map-screenshot-title" class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand-primary text-xs font-bold text-white">3</span>Map Screenshot</h2>
                        <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
                    </summary>
                    <div class="border-t border-ui-border p-4 sm:p-5">
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" name="map_screenshot" id="business-map-screenshot-input" data-map-screenshot-input aria-label="Map screenshot">
                        <input type="hidden" name="remove_map_screenshot" value="0" data-map-screenshot-remove-flag>

                        @if($mapOpenLink)
                            <div class="mb-4 flex justify-end"><a href="{{ $mapOpenLink }}" target="_blank" rel="noopener" class="ui-button-secondary-compact"><x-ui.icon name="open" size="size-3.5" />Open in Google Maps</a></div>
                        @endif

                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_16rem]">
                            <div>
                                <label for="business-map-screenshot-input" class="flex cursor-pointer items-center gap-3 rounded-control border-2 border-dashed border-brand-primary/40 bg-brand-soft/30 px-4 py-3 transition hover:border-brand-primary hover:bg-brand-soft/50" data-map-screenshot-dropzone @if($mapScreenshot) hidden @endif>
                                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="pin" size="size-4" /></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-text-main">Add Map Screenshot</span>
                                        <span class="block text-xs text-text-muted">Click to browse or drag a screenshot here &middot; JPG, PNG up to {{ (int) (config('cims.media.image_max_kilobytes') / 1024) }}MB</span>
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
                                <p class="mt-1">You can take a screenshot from Google Maps using your phone and upload it here. Make sure the location pin and nearby landmarks are visible.</p>
                            </div>
                        </div>
                        <x-form.validation-message for="map_screenshot" />
                    </div>
                </details>

                <x-ui.photo-upload-field input-name="competitor_photos" label="Competitors" :existing-photos="$existingCompetitorPhotos" removed-input-name="removed_photo_ids" :max-files="10" :section-number="4" :optional="true" :open="$errors->has('competitor_photos') || $errors->has('competitor_photos.*') || $errors->has('competitor_remarks')">
                    <x-form.textarea name="competitor_remarks" label="Competitor Remarks" class="mt-4" rows="4" :value="old('competitor_remarks', $businessCheck?->competitor_remarks)" />
                </x-ui.photo-upload-field>
            </div>
        </form>

        <div class="fixed inset-x-0 bottom-0 z-10 border-t border-ui-border bg-surface px-3 py-3 shadow-float sm:px-5">
            <div class="mx-auto flex max-w-5xl flex-col gap-2">
                <div class="hidden text-center" data-business-check-save-status>
                    <p class="text-xs font-semibold text-text-main" data-business-check-save-status-text></p>
                    <p class="mt-0.5 text-[11px] text-text-muted" data-business-check-save-status-helper hidden>Upload time may vary depending on your internet connection.</p>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>
                    <button type="submit" form="business-check-form" class="ui-button-primary" data-business-check-submit>
                        <span data-business-check-submit-icon><x-ui.icon name="check" size="size-4" /></span>
                        <span class="hidden size-4 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white motion-reduce:animate-none" data-business-check-submit-spinner aria-hidden="true"></span>
                        <span data-business-check-submit-text>{{ $businessCheck ? 'Update Business Check' : 'Save Business Check' }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <x-ui.modal id="business-check-companion-ci-dialog" title="Add Companion CI" description="Select one or more active Credit Investigators to add as companions on this Business Check." size="max-w-md" data-companion-ci-dialog>
        <label for="business-check-companion-ci-search" class="sr-only">Search Credit Investigator</label>
        <input type="search" id="business-check-companion-ci-search" class="ui-control mb-3" placeholder="Search by name..." autocomplete="off" data-companion-search>
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

    <x-ui.modal id="business-check-quick-add-dialog" title="Add Business" size="max-w-sm" data-quick-add-business-dialog>
            <p class="mb-3 rounded-control border border-danger/30 bg-danger-soft p-2.5 text-xs text-danger" data-quick-add-business-error hidden></p>
            <div class="flex flex-col gap-4">
                <div>
                    <label for="quick-add-business-name" class="ui-label">Business Name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="quick-add-business-name" type="text" class="ui-control" placeholder="Enter business name" maxlength="255" data-quick-add-business-name>
                </div>
                <div>
                    <label for="quick-add-business-template" class="ui-label">Business Type / Income Source <span class="text-danger" aria-hidden="true">*</span></label>
                    <select id="quick-add-business-template" class="ui-control" data-quick-add-business-template>
                        <option value="">Select business type</option>
                        @foreach($businessTemplates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="quick-add-business-location" class="ui-label">Location <span class="text-danger" aria-hidden="true">*</span></label>
                    <input id="quick-add-business-location" type="text" class="ui-control" placeholder="Enter location" required data-quick-add-business-location>
                </div>
            </div>
            <x-slot:footer>
                <button type="button" class="ui-button-secondary" data-modal-close>Cancel</button>
                <button type="button" class="ui-button-primary" data-quick-add-business-confirm data-url="{{ route('client-folders.income-sources.quick-create', $clientFolder) }}">Add &amp; Continue</button>
            </x-slot:footer>
    </x-ui.modal>
@endsection
