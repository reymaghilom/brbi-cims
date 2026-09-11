@extends('layouts.app')

@section('title', 'Edit Folder')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Edit Folder']]" />
    <x-ui.page-header title="Edit Folder" eyebrow="{{ $clientFolder->folder_number }}">
        <x-slot:description>Edit the client's structured name. Its stable ID, folder number, ownership and related records will remain unchanged.</x-slot:description>
    </x-ui.page-header>

    <form method="POST" action="{{ route('client-folders.update-name', $clientFolder) }}" class="max-w-2xl">
        @csrf
        @method('PATCH')
        <x-ui.form-section title="Client identity" description="Names use the same formatting as Client Folder creation.">
            @foreach([
                ['last_name', 'Last name', true, 'family-name', 100],
                ['first_name', 'First name', true, 'given-name', 100],
                ['middle_name', 'Middle name', false, 'additional-name', 100],
                ['suffix', 'Suffix', false, 'honorific-suffix', 30],
            ] as [$field, $label, $required, $autocomplete, $maxlength])
                <div>
                    <label for="{{ $field }}" class="ui-label">{{ $label }} @if($required)<span class="text-danger" aria-hidden="true">*</span>@else<span class="font-normal text-text-muted">(optional)</span>@endif</label>
                    <input id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $clientFolder->{$field}) }}" class="ui-control" @required($required) maxlength="{{ $maxlength }}" autocomplete="{{ $autocomplete }}" aria-describedby="{{ $field }}-error">
                    @error($field)<p id="{{ $field }}-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </x-ui.form-section>
        <x-ui.sticky-form-toolbar class="mt-7">
            Stable folder identity and ownership remain unchanged.
            <x-slot:actions>
                <a href="{{ route('client-folders.show', $clientFolder) }}" class="ui-button-secondary"><x-ui.icon name="close" size="size-4" />Cancel</a>
                <button class="ui-button-primary"><x-ui.icon name="edit" size="size-4" />Edit Folder</button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>
@endsection
