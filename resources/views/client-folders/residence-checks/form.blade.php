@extends('layouts.check-encoding')

@section('title', ($residenceCheck ? 'Edit' : 'Add').' Residence Check · '.$clientFolder->display_name)

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folder', 'url' => route('client-folders.index')],
        ['label' => $personName, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'Residence Check', 'url' => route('client-folders.residence-business.edit', [$clientFolder] + $personParams)],
        ['label' => ($residenceCheck ? 'Edit' : 'Add').' Residence Check'],
    ]" />

    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-3">
            <span class="grid size-11 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="home" size="size-5" /></span>
            <div>
                <h1 class="ui-page-title">Residence Check</h1>
                <p class="mt-1 text-sm text-text-muted">Encode residence verification details and upload photos.</p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>
            <button type="submit" form="residence-check-form" class="ui-button-primary"><x-ui.icon name="check" size="size-4" />{{ $residenceCheck ? 'Update Residence Check' : 'Save Residence Check' }}</button>
        </div>
    </header>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert" tabindex="-1"><p class="font-bold">Please correct the highlighted fields. No changes were saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form id="residence-check-form" method="POST" action="{{ route('client-folders.residence-checks.store', $clientFolder) }}" enctype="multipart/form-data" class="grid gap-6 lg:grid-cols-2" data-unsaved-form>
        @csrf
        <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
        <input type="hidden" name="check_id" value="{{ $residenceCheck?->id }}">

        <section class="ui-panel h-fit p-4 sm:p-5" aria-labelledby="residence-basic-info-title">
            <h2 id="residence-basic-info-title" class="ui-section-title">Basic Information</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><span class="ui-label">Applicant / Co-Maker Name</span><p class="ui-control bg-surface-subtle">{{ $personName }}</p></div>
                <div><label for="residence-location" class="ui-label">Location</label><input id="residence-location" name="location" type="text" class="ui-control bg-surface-subtle" readonly aria-readonly="true" value="{{ old('location', $defaultLocation) }}"></div>
                <x-form.input name="ci_date" label="CI Date" type="date" required :value="old('ci_date', $residenceCheck?->ci_date?->format('Y-m-d'))" />
                <div><span class="ui-label">CI Name</span><p class="ui-control bg-surface-subtle">{{ auth()->user()->full_name }}</p></div>
                <div class="sm:col-span-2"><span class="ui-label">Subject</span><p class="ui-control bg-surface-subtle">Residence Check</p></div>
                <x-form.textarea name="remarks" label="Remarks" class="sm:col-span-2" rows="6" :value="old('remarks', $residenceCheck?->remarks)" />
            </div>
        </section>

        <div class="grid h-fit gap-6">
            <section aria-labelledby="residence-photos-title">
                <h2 id="residence-photos-title" class="ui-section-title mb-2">1. Residence Photos</h2>
                <x-ui.photo-upload-field input-name="photos" label="Residence Photos" :existing-photos="$existingPhotos" removed-input-name="removed_photo_ids" />
                <x-form.validation-message for="photos" />
            </section>

            <section class="ui-panel p-4 sm:p-5" aria-labelledby="residence-map-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="residence-map-title" class="ui-section-title">2. Google Map (Location)</h2>
                    @if($mapOpenLink)<a href="{{ $mapOpenLink }}" target="_blank" rel="noopener" class="ui-button-secondary-compact"><x-ui.icon name="open" size="size-3.5" />Open in Google Maps</a>@endif
                </div>
                <div class="mt-3">
                    <label for="residence-google-maps-link" class="ui-label">Google Maps Link <span class="font-normal text-text-muted">(optional — paste a link, or leave blank to use the Location above)</span></label>
                    <input id="residence-google-maps-link" name="google_maps_link" type="text" class="ui-control" placeholder="https://maps.google.com/..." value="{{ old('google_maps_link', $residenceCheck?->google_maps_link) }}">
                </div>
                @if($mapEmbedSrc)
                    <div class="mt-3 overflow-hidden rounded-control border border-ui-border"><iframe src="{{ $mapEmbedSrc }}" class="h-72 w-full" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Residence location map"></iframe></div>
                @else
                    <p class="mt-3 rounded-control border border-dashed border-ui-border-strong bg-surface-muted p-4 text-center text-sm text-text-muted">Add a Location or Google Maps Link to preview the map.</p>
                @endif
            </section>
        </div>
    </form>
@endsection
