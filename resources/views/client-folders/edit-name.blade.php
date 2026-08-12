@extends('layouts.app')

@section('title', 'Rename Client Folder')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Rename']]" />
    <x-ui.page-header title="Rename Client Folder" eyebrow="{{ $clientFolder->folder_number }}">
        <x-slot:description>Change only the human-readable folder name. Its stable ID, folder number, ownership and related records will remain unchanged.</x-slot:description>
    </x-ui.page-header>

    <form method="POST" action="{{ route('client-folders.update-name', $clientFolder) }}" class="max-w-2xl">
        @csrf
        @method('PATCH')
        <x-ui.form-section title="Folder display name">
            <div class="sm:col-span-2">
                <label for="display_name" class="ui-label">Display name <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="display_name" name="display_name" value="{{ old('display_name', $clientFolder->display_name) }}" class="ui-control" required maxlength="255" aria-describedby="display_name-help display_name-error">
                <p id="display_name-help" class="ui-help">Use the official filing-cabinet name shown on folder cards.</p>
                @error('display_name')<p id="display_name-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
        </x-ui.form-section>
        <x-ui.sticky-form-toolbar class="mt-7">
            Stable folder identity and ownership remain unchanged.
            <x-slot:actions>
                <a href="{{ route('client-folders.show', $clientFolder) }}" class="ui-button-secondary">Cancel</a>
                <button class="ui-button-primary">Save Folder Name</button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>
@endsection
