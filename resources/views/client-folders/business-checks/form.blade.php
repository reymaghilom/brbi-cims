@extends('layouts.check-encoding')

@section('title', ($businessCheck ? 'Edit' : 'Add').' Business Check · '.$clientFolder->display_name)

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folder', 'url' => route('client-folders.index')],
        ['label' => $personName, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'Business Check', 'url' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams)],
        ['label' => ($businessCheck ? 'Edit' : 'Add').' Business Check'],
    ]" />

    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid size-11 shrink-0 place-items-center rounded-control bg-success-soft text-success"><x-ui.icon name="building" size="size-5" /></span>
            <div>
                <h1 class="ui-page-title">Business Check</h1>
                <p class="mt-1 text-sm text-text-muted">Encode business verification details and upload photos.</p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>
            <button type="submit" form="business-check-form" class="ui-button-primary"><x-ui.icon name="check" size="size-4" />{{ $businessCheck ? 'Update Business Check' : 'Save Business Check' }}</button>
        </div>
    </header>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert" tabindex="-1"><p class="font-bold">Please correct the highlighted fields. No changes were saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @if($businesses->isEmpty())
        <x-ui.empty-state title="No saved businesses yet" description="Add a Business / Income Source for this person before recording a Business Check." icon="folder" />
    @else
        <form id="business-check-form" method="POST" action="{{ route('client-folders.business-checks.store', $clientFolder) }}" enctype="multipart/form-data" class="grid gap-6 lg:grid-cols-2" data-unsaved-form data-business-check-form>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
            <input type="hidden" name="check_id" value="{{ $businessCheck?->id }}">

            <section class="ui-panel h-fit p-4 sm:p-5" aria-labelledby="business-basic-info-title">
                <h2 id="business-basic-info-title" class="ui-section-title">Basic Information</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div><span class="ui-label">Applicant / Co-Maker Name</span><p class="ui-control bg-surface-subtle">{{ $personName }}</p></div>
                    <div>
                        <label for="business-check-income-source" class="ui-label">Business Name <span class="text-danger" aria-hidden="true">*</span></label>
                        <select id="business-check-income-source" name="income_source_id" class="ui-control" required data-business-check-income-source-select>
                            <option value="">Select a business</option>
                            @foreach($businesses as $business)
                                <option value="{{ $business['id'] }}" data-location="{{ $business['location'] }}" @selected(old('income_source_id', $businessCheck?->income_source_id) == $business['id'])>{{ $business['name'] }}</option>
                            @endforeach
                        </select>
                        <x-form.validation-message for="income_source_id" />
                    </div>
                    <div><label for="business-check-location" class="ui-label">Location</label><input id="business-check-location" name="location" type="text" class="ui-control bg-surface-subtle" readonly aria-readonly="true" value="{{ old('location', $businessCheck?->location) }}" data-business-check-location></div>
                    <x-form.input name="ci_date" label="CI Date" type="date" required :value="old('ci_date', $businessCheck?->ci_date?->format('Y-m-d'))" />
                    <div><span class="ui-label">CI Name</span><p class="ui-control bg-surface-subtle">{{ auth()->user()->full_name }}</p></div>
                    <div class="sm:col-span-2"><span class="ui-label">Subject</span><p class="ui-control bg-surface-subtle">Business Check</p></div>
                    <x-form.textarea name="remarks" label="Remarks" class="sm:col-span-2" rows="6" :value="old('remarks', $businessCheck?->remarks)" />
                </div>
            </section>

            <div class="grid h-fit gap-6">
                <section aria-labelledby="business-photos-title">
                    <h2 id="business-photos-title" class="ui-section-title mb-2">1. Business Photos</h2>
                    <x-ui.photo-upload-field input-name="business_photos" label="Business Photos" :existing-photos="$existingBusinessPhotos" removed-input-name="removed_photo_ids" />
                    <x-form.validation-message for="business_photos" />
                </section>

                <section class="ui-panel p-4 sm:p-5" aria-labelledby="business-competitors-title">
                    <h2 id="business-competitors-title" class="ui-section-title">2. Competitors <span class="font-normal text-text-muted">(Optional)</span></h2>
                    <div class="mt-3">
                        <x-ui.photo-upload-field input-name="competitor_photos" label="Competitor Photos" :existing-photos="$existingCompetitorPhotos" removed-input-name="removed_photo_ids" :max-files="10" />
                    </div>
                    <x-form.textarea name="competitor_remarks" label="Competitor Remarks" class="mt-4" rows="4" :value="old('competitor_remarks', $businessCheck?->competitor_remarks)" />
                </section>

                <section class="ui-panel p-4 sm:p-5" aria-labelledby="business-map-title">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="business-map-title" class="ui-section-title">3. Google Map (Location)</h2>
                        @if($mapOpenLink)<a href="{{ $mapOpenLink }}" target="_blank" rel="noopener" class="ui-button-secondary-compact"><x-ui.icon name="open" size="size-3.5" />Open in Google Maps</a>@endif
                    </div>
                    <div class="mt-3">
                        <label for="business-google-maps-link" class="ui-label">Google Maps Link <span class="font-normal text-text-muted">(optional — paste a link, or leave blank to use the Location above)</span></label>
                        <input id="business-google-maps-link" name="google_maps_link" type="text" class="ui-control" placeholder="https://maps.google.com/..." value="{{ old('google_maps_link', $businessCheck?->google_maps_link) }}">
                    </div>
                    @if($mapEmbedSrc)
                        <div class="mt-3 overflow-hidden rounded-control border border-ui-border"><iframe src="{{ $mapEmbedSrc }}" class="h-72 w-full" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Business location map"></iframe></div>
                    @else
                        <p class="mt-3 rounded-control border border-dashed border-ui-border-strong bg-surface-muted p-4 text-center text-sm text-text-muted">Select a business or add a Google Maps Link to preview the map.</p>
                    @endif
                </section>
            </div>
        </form>
    @endif
@endsection
