@extends('layouts.app')

@section('title', 'Residence & Business Report')

@php
    $sectionRows = old('sections');
    if ($sectionRows === null) {
        $sectionRows = $report?->sections ?? collect();
    }
    if (count($sectionRows) === 0) {
        $sectionRows = [['category' => 'residence', 'subject_party' => 'applicant', 'heading_subject' => 'Residence Check', 'location' => $report?->default_location, 'sort_order' => 1]];
    }
@endphp

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Residence & Business Report']]" />
    <x-ui.page-header title="Residence & Business Report" eyebrow="Photo investigation record">
        <x-slot:description>Document applicant or co-maker residences and each distinct business or income source using existing folder media.</x-slot:description>
        <x-slot:actions><x-ui.status-badge :status="$report?->state ?? 'draft'" /><a href="{{ route('client-folders.residence-business.preview', $clientFolder) }}" target="_blank" rel="noopener" class="ui-button-secondary">Preview Report / Print</a><a href="{{ route('client-folders.generated-reports.index', $clientFolder) }}" class="ui-button-secondary">Report Outputs</a><a href="{{ route('client-folders.show', $clientFolder) }}" class="ui-button-secondary">Back to Folder</a></x-slot:actions>
    </x-ui.page-header>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm text-danger" role="alert" tabindex="-1"><p class="font-bold">Please correct the highlighted report fields. No changes were saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('client-folders.residence-business.update', $clientFolder) }}" class="space-y-6" data-unsaved-form data-photo-sections-form>
        @csrf @method('PUT')
        <x-ui.form-section title="Report Header" description="The CI is taken from the folder's single primary assignment. Section-specific values may override the default subject and location.">
            <x-form.input name="report_date" label="Report Date" type="date" :value="$report?->report_date?->format('Y-m-d')" />
            <div><span class="ui-label">Credit Investigator</span><p class="ui-control bg-surface-subtle">{{ $report?->investigator?->full_name ?? $clientFolder->assignedInvestigator->full_name }}</p></div>
            <x-form.input name="default_subject" label="Default Subject" :value="$report?->default_subject" placeholder="Residence and Business Check" />
            <x-form.textarea name="default_location" label="Default Location" :value="$report?->default_location" class="sm:col-span-2" rows="3" />
        </x-ui.form-section>

        <section class="ui-panel p-5 sm:p-7" aria-labelledby="documentation-sections-title">
            <header class="flex flex-col gap-4 border-b border-ui-border pb-5 sm:flex-row sm:items-start sm:justify-between"><div><h2 id="documentation-sections-title" class="ui-section-title">Documentation Sections</h2><p class="mt-1.5 text-sm leading-6 text-text-muted">Keep every residence and business as a distinct ordered section. Business sections link to the exact Phase 13 income source.</p></div><div class="flex flex-wrap gap-2"><button type="button" class="ui-button-secondary" data-photo-section-add="residence">+ Residence Section</button><button type="button" class="ui-button-secondary" data-photo-section-add="business">+ Business Section</button></div></header>
            <div class="mt-6 space-y-6" data-photo-section-rows>
                @foreach($sectionRows as $index => $row)
                    @include('client-folders.residence-business._section', ['row' => $row, 'index' => $index])
                @endforeach
            </div>
            <template data-photo-section-template>@include('client-folders.residence-business._section', ['row' => [], 'index' => '__INDEX__', 'category' => '__CATEGORY__'])</template>
            <x-form.validation-message for="sections" />
        </section>

        <x-ui.form-section title="Overall Findings" description="These report-level narratives supplement, but do not replace, the findings recorded for each section.">
            <x-form.textarea name="residence_remarks" label="Overall Residence Findings / Remarks" :value="$report?->residence_remarks" rows="6" />
            <x-form.textarea name="business_remarks" label="Overall Business Findings / Remarks" :value="$report?->business_remarks" rows="6" />
        </x-ui.form-section>

        <x-ui.sticky-form-toolbar><span>Revision {{ $report?->revision ?? 1 }} · Uses existing media only; official outputs use saved data.</span><x-slot:actions>@if($report)<a href="{{ route('client-folders.residence-business.preview', $clientFolder) }}" target="_blank" rel="noopener" class="ui-button-secondary">Preview / Print</a>@endif<button type="submit" name="intent" value="return" class="ui-button-secondary">Save and Return</button><button type="submit" name="intent" value="stay" class="ui-button-secondary">Save</button><button type="submit" name="intent" value="complete" class="ui-button-primary">Save and Mark Complete</button></x-slot:actions></x-ui.sticky-form-toolbar>
    </form>
@endsection
