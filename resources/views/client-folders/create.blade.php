@extends('layouts.app')

@section('title', 'Create Client Folder')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => 'Create Client Folder']]" />
    <x-ui.page-header title="Create Client Folder" eyebrow="New client record">
        <x-slot:description>Enter the minimum client identity details. The stable folder number will be generated securely after submission.</x-slot:description>
    </x-ui.page-header>

    <form method="POST" action="{{ route('client-folders.store') }}" class="max-w-4xl">
        @csrf
        <x-ui.form-section title="Client identity" description="Names are normalized for the official filing-cabinet display.">
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
                <label for="middle_name" class="ui-label">Middle name <span aria-hidden="true" class="text-danger">*</span></label>
                <input id="middle_name" name="middle_name" value="{{ old('middle_name') }}" class="ui-control" maxlength="100" autocomplete="additional-name" required aria-describedby="middle_name-error">
                @error('middle_name')<p id="middle_name-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="suffix" class="ui-label">Suffix <span class="font-normal text-text-muted">(optional)</span></label>
                <input id="suffix" name="suffix" value="{{ old('suffix') }}" class="ui-control" maxlength="30" placeholder="JR., SR., III" aria-describedby="suffix-error">
                @error('suffix')<p id="suffix-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Primary Credit Investigator" description="One primary investigator owns the folder in the initial BRBI workflow." class="mt-6">
            @if(auth()->user()->role === App\Enums\UserRole::Administrator)
                <div class="sm:col-span-2">
                    <label for="assigned_ci_id" class="ui-label">Assigned Credit Investigator <span class="text-danger" aria-hidden="true">*</span></label>
                    <select id="assigned_ci_id" name="assigned_ci_id" class="ui-control" required aria-describedby="assigned_ci_id-help assigned_ci_id-error">
                        <option value="">Select an active Credit Investigator</option>
                        @foreach($creditInvestigators as $investigator)
                            <option value="{{ $investigator->id }}" @selected((string) old('assigned_ci_id') === (string) $investigator->id)>{{ $investigator->full_name }}{{ $investigator->employee_id ? ' — '.$investigator->employee_id : '' }}</option>
                        @endforeach
                    </select>
                    <p id="assigned_ci_id-help" class="ui-help">Only active Credit Investigator accounts are available.</p>
                    @error('assigned_ci_id')<p id="assigned_ci_id-error" class="mt-2 text-sm font-semibold text-danger" role="alert">{{ $message }}</p>@enderror
                </div>
            @else
                <div class="rounded-card border border-brand-primary/20 bg-brand-soft p-4 sm:col-span-2">
                    <p class="text-sm font-semibold text-brand-primary">This folder will be assigned to your account.</p>
                    <p class="mt-1 text-sm text-text-muted">{{ auth()->user()->full_name }}</p>
                </div>
            @endif
        </x-ui.form-section>

        <x-ui.sticky-form-toolbar class="mt-7">
            Folder numbers are generated after validation.
            <x-slot:actions>
                <a href="{{ route('client-folders.index') }}" class="ui-button-secondary">Cancel</a>
                <button class="ui-button-primary">Create Client Folder</button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>
@endsection
