@extends('layouts.app')

@section('title', 'Client Information')

@section('content')
    <x-ui.breadcrumb :items="[
        ['label' => 'Dashboard', 'url' => route('home')],
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)],
        ['label' => 'Client Information'],
    ]" />

    <x-ui.page-header title="Client Information">
        <x-slot:description>Encode the client's core identity, household details, findings, and structured addresses.</x-slot:description>
        <x-slot:actions>
            <a href="{{ route('client-folders.show', $clientFolder) }}" class="ui-button-secondary">Back to Folder</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 rounded-card border border-folder-border bg-folder-soft px-4 py-3 sm:px-5" aria-label="Current client identity">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-folder-ink">{{ $clientFolder->folder_number }}</p>
        <p class="mt-1 font-bold text-brand-sidebar">{{ $clientFolder->display_name }}</p>
    </div>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert" tabindex="-1">
            <p class="font-bold">Please correct the highlighted fields before saving.</p>
            @error('addresses')<p class="mt-1">{{ $message }}</p>@enderror
        </div>
    @endif

    <form method="POST" action="{{ route('client-folders.client-information.update', $clientFolder) }}" class="space-y-6" data-unsaved-form>
        @csrf
        @method('PUT')

        <x-ui.form-section title="Basic Information" description="The name fields below are the canonical client identity and will refresh the folder display name.">
            <x-form.input name="first_name" label="First Name" :value="$clientFolder->first_name" required autocomplete="given-name" />
            <x-form.input name="middle_name" label="Middle Name" :value="$clientFolder->middle_name" autocomplete="additional-name" />
            <x-form.input name="last_name" label="Last Name" :value="$clientFolder->last_name" required autocomplete="family-name" />
            <x-form.input name="suffix" label="Suffix" :value="$clientFolder->suffix" help="For example: Jr., Sr., III" />
            <x-form.input name="birth_date" label="Date of Birth" type="date" :value="$information?->birth_date?->format('Y-m-d')" />
            <x-form.input name="civil_status" label="Civil Status" :value="$information?->civil_status" maxlength="40" />
            <x-form.input name="contact_number" label="Contact Number" :value="$information?->contact_number" autocomplete="tel" maxlength="60" />
            <x-form.input name="email" label="Email Address" type="email" :value="$information?->email" autocomplete="email" />
        </x-ui.form-section>

        <x-ui.form-section title="Household and Residence Profile" description="Record only verified or client-declared details supported by the approved data model.">
            <x-form.input name="spouse_name" label="Spouse Name" :value="$information?->spouse_name" class="sm:col-span-2" />
            <x-form.input name="dependents_count" label="Number of Dependents" type="number" min="0" max="100" :value="$information?->dependents_count" />
            <x-form.input name="length_of_stay_months" label="Overall Length of Stay (months)" type="number" min="0" max="1200" :value="$information?->length_of_stay_months" />
            <x-form.input name="home_ownership" label="Home Ownership" :value="$information?->home_ownership" maxlength="60" />
            <x-form.input name="home_condition" label="Home Condition" :value="$information?->home_condition" maxlength="60" />
            <x-form.input name="material_cost_level" label="Material / Cost Level" :value="$information?->material_cost_level" maxlength="60" />
            <x-form.input name="living_condition" label="Living Condition" :value="$information?->living_condition" maxlength="60" />
            <x-form.input name="reputation" label="Reputation" :value="$information?->reputation" maxlength="100" />
            <x-form.input name="lifestyle" label="Lifestyle" :value="$information?->lifestyle" maxlength="100" />
        </x-ui.form-section>

        <x-ui.form-section title="Findings and Remarks" description="Use concise, factual entries. These notes may support later investigation reports.">
            <x-form.textarea name="vehicles_owned" label="Vehicles Owned" :value="$information?->vehicles_owned" />
            <x-form.textarea name="other_residences" label="Other Residences" :value="$information?->other_residences" />
            <x-form.textarea name="barangay_findings" label="Barangay Findings" :value="$information?->barangay_findings" />
            <x-form.textarea name="court_background_summary" label="Court Background Summary" :value="$information?->court_background_summary" />
            <x-form.textarea name="other_remarks" label="Other Remarks" :value="$information?->other_remarks" class="sm:col-span-2" rows="5" />
        </x-ui.form-section>

        <section class="ui-panel p-5 sm:p-7" aria-labelledby="address-section-title">
            <header class="mb-6 border-b border-ui-border pb-5">
                <h2 id="address-section-title" class="ui-section-title">Structured Addresses</h2>
                <p class="mt-1.5 text-sm leading-6 text-text-muted">Enable only the address types that apply. This module stores one record per type; clearing an enabled type removes that address record.</p>
            </header>

            <div class="space-y-5">
                @foreach($addressTypes as $type)
                    @php
                        $address = $addresses->get($type->value);
                        $label = match($type) {
                            App\Enums\AddressType::Present => 'Present / Current Address',
                            App\Enums\AddressType::Previous => 'Previous Address',
                            App\Enums\AddressType::Parents => "Parents' Address",
                            App\Enums\AddressType::Residence => 'Permanent / Residence Address',
                            App\Enums\AddressType::Business => 'Business Address',
                            App\Enums\AddressType::Other => 'Other Address',
                        };
                        $prefix = "addresses.{$type->value}";
                        $htmlPrefix = "address-{$type->value}";
                        $enabled = (bool) old("$prefix.enabled", $address !== null);
                    @endphp
                    <fieldset class="rounded-card border border-ui-border bg-surface-subtle p-4 sm:p-5">
                        <legend class="px-1 text-base font-bold text-brand-sidebar">{{ $label }}</legend>
                        <label class="mt-1 flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold">
                            <input type="hidden" name="addresses[{{ $type->value }}][enabled]" value="0">
                            <input id="{{ $htmlPrefix }}-enabled" type="checkbox" name="addresses[{{ $type->value }}][enabled]" value="1" @checked($enabled) class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary">
                            Store this address
                        </label>

                        <div class="mt-4 grid gap-5 sm:grid-cols-2">
                            @foreach([
                                'address_line_1' => ['Address Line 1', true], 'address_line_2' => ['Address Line 2', false],
                                'barangay' => ['Barangay', false], 'city_municipality' => ['City / Municipality', false],
                                'province' => ['Province', false], 'postal_code' => ['Postal Code', false],
                                'country' => ['Country', false],
                            ] as $field => [$fieldLabel, $requiredWhenEnabled])
                                <div @class(['sm:col-span-2' => str_starts_with($field, 'address_line')])>
                                    <label for="{{ $htmlPrefix }}-{{ $field }}" class="ui-label">{{ $fieldLabel }} @if($requiredWhenEnabled)<span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required when address is enabled</span>@endif</label>
                                    <input id="{{ $htmlPrefix }}-{{ $field }}" name="addresses[{{ $type->value }}][{{ $field }}]" value="{{ old("$prefix.$field", $address?->{$field} ?? ($field === 'country' ? 'Philippines' : null)) }}" @if($errors->has("$prefix.$field")) aria-invalid="true" aria-describedby="{{ $htmlPrefix }}-{{ $field }}-error" @endif class="ui-control">
                                    @error("$prefix.$field")<p id="{{ $htmlPrefix }}-{{ $field }}-error" class="ui-error" role="alert">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                            <div>
                                <label for="{{ $htmlPrefix }}-stay" class="ui-label">Length of Stay (months)</label>
                                <input id="{{ $htmlPrefix }}-stay" type="number" min="0" max="1200" name="addresses[{{ $type->value }}][length_of_stay_months]" value="{{ old("$prefix.length_of_stay_months", $address?->length_of_stay_months) }}" class="ui-control">
                                @error("$prefix.length_of_stay_months")<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <div class="sm:col-span-2">
                                <label for="{{ $htmlPrefix }}-maps" class="ui-label">Google Maps Link</label>
                                <input id="{{ $htmlPrefix }}-maps" type="url" name="addresses[{{ $type->value }}][google_maps_link]" value="{{ old("$prefix.google_maps_link", $address?->google_maps_link) }}" placeholder="https://maps.google.com/..." class="ui-control">
                                @error("$prefix.google_maps_link")<p class="ui-error" role="alert">{{ $message }}</p>@enderror
                            </div>
                            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-semibold sm:col-span-2">
                                <input type="hidden" name="addresses[{{ $type->value }}][is_primary]" value="0">
                                <input type="checkbox" name="addresses[{{ $type->value }}][is_primary]" value="1" @checked((bool) old("$prefix.is_primary", $address?->is_primary ?? false)) class="size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary">
                                Mark as primary address
                            </label>
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </section>

        <x-ui.sticky-form-toolbar>
            <span>Required completion items: first and last name, birth date, civil status, contact number, and a sufficiently detailed present address.</span>
            <x-slot:actions>
                <button type="submit" name="intent" value="return" class="ui-button-secondary">Save and Return to Folder</button>
                <button type="submit" name="intent" value="stay" class="ui-button-primary">Save Client Information</button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>
@endsection
