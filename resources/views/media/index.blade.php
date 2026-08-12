@extends('layouts.app')

@section('title', 'Photos & Videos')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Photos & Videos']]" />
    <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-primary">Authorized evidence library</p><h1 class="ui-page-title mt-1">Photos &amp; Videos</h1><p class="mt-2 text-sm text-text-muted">Protected media across client folders you are authorized to access.</p></div>
    <section class="mt-6 ui-panel overflow-hidden">
        <div class="border-b border-ui-border p-4 sm:p-5"><form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-end"><div class="flex-1"><label for="global-media-type" class="ui-label">Media</label><select id="global-media-type" name="type" class="ui-control"><option value="">All Media</option><option value="photo" @selected(request('type') === 'photo')>Photos</option><option value="video" @selected(request('type') === 'video')>Videos</option></select></div><div class="flex-1"><label for="global-media-category" class="ui-label">Category</label><select id="global-media-category" name="category" class="ui-control"><option value="">All Categories</option>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ str($category->value)->title() }}</option>@endforeach</select></div><button class="ui-button-secondary">Apply Filters</button></form></div>
        <div class="p-4 sm:p-5">@if($mediaItems->isEmpty())<x-ui.empty-state title="No authorized media" description="Uploaded client-folder evidence will appear here." icon="media" />@else<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">@foreach($mediaItems as $media)@include('media._card', ['clientFolder' => $media->clientFolder, 'showFolder' => true])@endforeach</div><div class="mt-5">{{ $mediaItems->links() }}</div>@endif</div>
    </section>
@endsection
