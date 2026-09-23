@extends('layouts.app')

@section('title', 'Create Client Folder')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => 'Create Client Folder']]" />
    <x-ui.page-header title="Create Client Folder" eyebrow="New client record">
        <x-slot:description>Enter the minimum client identity details. The stable folder number will be generated securely after submission.</x-slot:description>
    </x-ui.page-header>

    <form method="POST" action="{{ route('client-folders.store') }}" class="mx-auto w-full max-w-5xl pb-6" data-submit-guard>
        @csrf
        <x-ui.form-section title="Client identity" description="Enter the client's name as it should appear in the official filing cabinet.">
            <div>
                <label for="last_name" class="ui-label">Last name <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="last_name" name="last_name" value="{{ old('last_name') }}" class="ui-control" required maxlength="100" autocomplete="family-name" aria-describedby="last_name-error">
                @error('last_name')<p id="last_name-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="first_name" class="ui-label">First name <span class="text-danger" aria-hidden="true">*</span></label>
                <input id="first_name" name="first_name" value="{{ old('first_name') }}" class="ui-control" required maxlength="100" autocomplete="given-name" aria-describedby="first_name-error">
                @error('first_name')<p id="first_name-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="middle_name" class="ui-label">Middle name <span class="font-normal text-text-muted">(optional)</span></label>
                <input id="middle_name" name="middle_name" value="{{ old('middle_name') }}" class="ui-control" maxlength="100" autocomplete="additional-name" aria-describedby="middle_name-error">
                @error('middle_name')<p id="middle_name-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="suffix" class="ui-label">Suffix <span class="font-normal text-text-muted">(optional)</span></label>
                <input id="suffix" name="suffix" value="{{ old('suffix') }}" class="ui-control" maxlength="30" placeholder="JR., SR., III" aria-describedby="suffix-error">
                @error('suffix')<p id="suffix-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
        </x-ui.form-section>

        <x-ui.sticky-form-toolbar class="mt-6 sm:mt-7">
            <span class="leading-5">Folder numbers are generated after validation.</span>
            <x-slot:actions>
                <a href="{{ route('client-folders.index') }}" class="ui-button-secondary">
                    <x-ui.icon name="close" size="size-4" data-action-icon="close" />
                    <span>Cancel</span>
                </a>
                <button class="ui-button-primary">
                    <x-ui.icon name="plus" size="size-4" data-action-icon="plus" />
                    <span>Create Client Folder</span>
                </button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>
@endsection
