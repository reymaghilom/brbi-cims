@extends('layouts.app')

@section('title', 'Photos & Videos')

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)], ['label' => 'Photos & Videos']]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-primary">Protected field evidence</p><h1 class="ui-page-title mt-1">Photos &amp; Videos</h1><p class="mt-2 text-sm text-text-muted">{{ $clientFolder->display_name }} · {{ $clientFolder->folder_number }}</p></div>
        @can('create', [App\Models\MediaReference::class, $clientFolder])
            <button type="button" class="ui-button-primary" data-modal-open="media-upload-dialog">
                <span aria-hidden="true">+</span>
                Add Media
            </button>
        @endcan
    </div>

    <section class="mt-6 ui-panel overflow-hidden" aria-label="Media evidence gallery">
        <div class="border-b border-ui-border p-4 sm:p-5">
            <form method="GET" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                @foreach($personParams as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <div class="flex-1"><label for="media-type" class="ui-label">Media</label><select id="media-type" name="type" class="ui-control"><option value="">All Media ({{ $counts['all'] }})</option><option value="photo" @selected(request('type') === 'photo')>Photos ({{ $counts['photo'] }})</option><option value="video" @selected(request('type') === 'video')>Videos ({{ $counts['video'] }})</option></select></div>
                <div class="flex-1"><label for="media-category" class="ui-label">Category</label><select id="media-category" name="category" class="ui-control"><option value="">All Categories</option>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ str($category->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
                <button class="ui-button-secondary">Apply Filters</button>
                @if(request()->hasAny(['type', 'category']))<a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams) }}" class="ui-button-secondary">Clear</a>@endif
            </form>
        </div>

        <div class="p-4 sm:p-5">
            @if($mediaItems->isEmpty())
                <x-ui.empty-state title="No photos or videos yet" description="Add field investigation media to this client folder." icon="media">
                    @can('create', [App\Models\MediaReference::class, $clientFolder])
                        <x-slot:action>
                            <button type="button" class="ui-button-primary" data-modal-open="media-upload-dialog">Add Media</button>
                        </x-slot:action>
                    @endcan
                </x-ui.empty-state>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    @foreach($mediaItems as $media)
                        @include('media._card', ['editable' => true])
                    @endforeach
                </div>
                <div class="mt-5">{{ $mediaItems->links() }}</div>
            @endif
        </div>
    </section>

    @can('create', [App\Models\MediaReference::class, $clientFolder])
        <x-ui.modal id="media-upload-dialog" title="Add Media" description="Upload protected field evidence. Each selected file creates its own evidence record." size="max-w-2xl" :data-open-on-error="old('media_form') === 'upload' ? 'true' : 'false'">
            <form id="media-upload-form" method="POST" action="{{ route('client-folders.media.store', $clientFolder) }}" enctype="multipart/form-data">
                @csrf<input type="hidden" name="media_form" value="upload"><input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
                <div>
                    <label for="media-files" class="ui-label">Photos or MP4 videos <span class="text-danger">*</span></label>
                    <input id="media-files" name="files[]" type="file" multiple required accept="image/jpeg,image/png,image/webp,video/mp4" class="ui-control file:mr-3 file:rounded-control file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:font-semibold file:text-brand-primary">
                    <p class="ui-help">Up to {{ config('cims.media.max_files_per_upload') }} files. Photos: 10 MB each. MP4 videos: 50 MB each.</p>
                    <x-form.validation-message for="files" />
                    @foreach($errors->get('files.*') as $messages)
                        @foreach($messages as $message)
                            <p class="mt-2 text-sm font-medium text-danger" role="alert">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div><label for="upload-category" class="ui-label">Category <span class="text-danger">*</span></label><select id="upload-category" name="category" class="ui-control" required>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ str($category->value)->title() }}</option>@endforeach</select><x-form.validation-message for="category" /></div>
                    <div><label for="upload-captured-at" class="ui-label">Date Taken</label><input id="upload-captured-at" name="captured_at" type="date" max="{{ now(config('cims.display_timezone'))->toDateString() }}" value="{{ old('captured_at') }}" class="ui-control"><x-form.validation-message for="captured_at" /></div>
                    <div class="sm:col-span-2"><label for="upload-label" class="ui-label">Title / Caption</label><input id="upload-label" name="label" value="{{ old('label') }}" maxlength="255" class="ui-control" placeholder="Optional shared title"><x-form.validation-message for="label" /></div>
                    <div><label for="upload-activity" class="ui-label">Related CI Activity</label><select id="upload-activity" name="ci_activity_id" class="ui-control"><option value="">Not linked</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) old('ci_activity_id') === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select><x-form.validation-message for="ci_activity_id" /></div>
                    <div><label for="upload-income-source" class="ui-label">Business / Income Source</label><select id="upload-income-source" name="income_source_id" class="ui-control"><option value="">Not linked</option>@foreach($incomeSources as $source)<option value="{{ $source->id }}" @selected((string) old('income_source_id') === (string) $source->id)>{{ $source->business_name ?: $source->source_name }}</option>@endforeach</select><x-form.validation-message for="income_source_id" /></div>
                    <div class="sm:col-span-2"><label for="upload-remarks" class="ui-label">Description / Notes</label><textarea id="upload-remarks" name="remarks" rows="3" maxlength="10000" class="ui-control">{{ old('remarks') }}</textarea><x-form.validation-message for="remarks" /></div>
                </div>
            </form>
            <x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Cancel</button><button type="submit" form="media-upload-form" class="ui-button-primary">Upload Media</button></x-slot:footer>
        </x-ui.modal>
    @endcan

    @foreach($mediaItems as $media)
        @can('update', $media)
            @php($editHasErrors = old('media_form') === 'edit-'.$media->id)
            <x-ui.modal id="media-edit-{{ $media->id }}" title="Edit Media Details" size="max-w-lg" :data-open-on-error="$editHasErrors ? 'true' : 'false'">
                <form id="media-edit-form-{{ $media->id }}" method="POST" action="{{ route('client-folders.media.update', [$clientFolder, $media]) }}">@csrf @method('PATCH')<input type="hidden" name="media_form" value="edit-{{ $media->id }}"><input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="ui-label" for="edit-category-{{ $media->id }}">Category</label><select class="ui-control" id="edit-category-{{ $media->id }}" name="category">@foreach($categories as $category)<option value="{{ $category->value }}" @selected(($editHasErrors ? old('category') : $media->category->value) === $category->value)>{{ str($category->value)->title() }}</option>@endforeach</select></div>
                        <div><label class="ui-label" for="edit-date-{{ $media->id }}">Date Taken</label><input class="ui-control" id="edit-date-{{ $media->id }}" name="captured_at" type="date" value="{{ $editHasErrors ? old('captured_at') : $media->captured_at?->toDateString() }}"></div>
                        <div class="sm:col-span-2"><label class="ui-label" for="edit-label-{{ $media->id }}">Title / Caption</label><input class="ui-control" id="edit-label-{{ $media->id }}" name="label" maxlength="255" value="{{ $editHasErrors ? old('label') : $media->label }}"></div>
                        <div><label class="ui-label" for="edit-activity-{{ $media->id }}">Related CI Activity</label><select class="ui-control" id="edit-activity-{{ $media->id }}" name="ci_activity_id"><option value="">Not linked</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) ($editHasErrors ? old('ci_activity_id') : $media->activities->first()?->id) === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select></div>
                        <div><label class="ui-label" for="edit-source-{{ $media->id }}">Business / Income Source</label><select class="ui-control" id="edit-source-{{ $media->id }}" name="income_source_id"><option value="">Not linked</option>@foreach($incomeSources as $source)<option value="{{ $source->id }}" @selected((string) ($editHasErrors ? old('income_source_id') : $media->income_source_id) === (string) $source->id)>{{ $source->business_name ?: $source->source_name }}</option>@endforeach</select></div>
                        <div class="sm:col-span-2"><label class="ui-label" for="edit-remarks-{{ $media->id }}">Description / Notes</label><textarea class="ui-control" id="edit-remarks-{{ $media->id }}" name="remarks" rows="3">{{ $editHasErrors ? old('remarks') : $media->remarks }}</textarea></div>
                    </div>
                    @if($editHasErrors && $errors->any())<div class="mt-4 rounded-control border border-danger/25 bg-danger-soft p-3 text-sm text-danger" role="alert">{{ $errors->first() }}</div>@endif
                </form>
                <x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Cancel</button><button type="submit" form="media-edit-form-{{ $media->id }}" class="ui-button-primary">Save Changes</button></x-slot:footer>
            </x-ui.modal>
        @endcan
        @can('delete', $media)
            <x-ui.modal id="media-remove-{{ $media->id }}" title="Remove Media" size="max-w-md"><p class="text-sm leading-6 text-text-muted">Are you sure you want to remove <strong class="text-text-main">{{ $media->label ?: 'this media item' }}</strong>? The evidence file will remain protected and will not be permanently deleted.</p><x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Cancel</button><form method="POST" action="{{ route('client-folders.media.destroy', [$clientFolder, $media]) }}">@csrf @method('DELETE')<button class="ui-button-danger">Remove</button></form></x-slot:footer></x-ui.modal>
        @endcan
    @endforeach
@endsection
