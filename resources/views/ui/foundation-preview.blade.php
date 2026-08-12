@extends('layouts.app')

@section('title', 'UI Foundation Preview')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'UI Foundation Preview']]" />
    <x-ui.page-header title="UI Foundation Preview" eyebrow="Phase 5 design system"><x-slot:description>Reusable BRBI interface components shown with sample presentation data only.</x-slot:description><x-slot:actions><button id="sample-modal-trigger" data-modal-open="sample-modal" class="ui-button-primary">Open sample modal</button></x-slot:actions></x-ui.page-header>

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
        <x-ui.summary-card label="Accessible folders" value="24" hint="Sample presentation value" icon="folder" tone="folder" />
        <x-ui.summary-card label="On Progress" value="8" icon="activity" tone="amber" />
        <x-ui.summary-card label="Completed" value="16" icon="check" />
    </div>

    <div class="mt-8 grid gap-5 lg:grid-cols-2">
        <x-ui.folder-card title="DELA CRUZ, JUAN" number="BRBI-CI-2026-00001" status="on_progress" progress="62" :href="route('home')" />
        <x-ui.client-header name="SANTOS, MARIA" folder-number="BRBI-CI-2026-00002" status="completed" progress="100" />
        <x-ui.module-card title="CI / BI Report" description="Official personal, residence and financial investigation form." icon="report" state="on_progress" />
        <x-ui.activity-checklist-item title="Residence Check" status="completed" description="Sample activity presentation." />
    </div>

    <div class="mt-8 grid gap-6 xl:grid-cols-2">
        <x-ui.form-section title="Form controls" description="Accessible reusable encoding controls.">
            <x-form.input name="preview_name" label="Applicant name" required />
            <x-form.select name="preview_status" label="Status" :options="['on_progress' => 'On Progress', 'completed' => 'Completed']" />
            <x-form.textarea name="preview_remarks" label="Remarks" class="sm:col-span-2" />
            <x-form.choice-group name="preview_choice" label="Validation result" type="radio" :options="['verified' => 'Verified', 'unverified' => 'Unverified']" />
        </x-ui.form-section>
        <section class="space-y-4">
            <x-ui.missing-items-summary :items="['Complete CI / BI report', 'Attach residence photographs']" />
            <x-ui.integration-status-badge provider="Google Drive" status="success" />
            <x-ui.retry-state title="Sample connection error" message="The retry pattern is ready for later integration phases." />
            <x-ui.loading-state label="Loading sample records" />
        </section>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-ui.tabs :tabs="['overview' => 'Overview', 'notes' => 'Notes']">
            <x-ui.tab-panel id="overview" active>Overview panel content.</x-ui.tab-panel>
            <x-ui.tab-panel id="notes">Notes panel content.</x-ui.tab-panel>
        </x-ui.tabs>
        <div class="space-y-3"><x-ui.accordion title="Applicant information" open>Accordion content uses native keyboard-accessible disclosure behavior.</x-ui.accordion><x-ui.context-menu><a href="{{ route('home') }}" role="menuitem" class="block rounded-control px-3 py-2 text-sm hover:bg-brand-soft">Sample action</a></x-ui.context-menu></div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <x-ui.media-card title="Residence Front View" type="photo" meta="Sample media card" />
        <div class="lg:col-span-2"><x-ui.note-timeline :notes="[['author' => 'Credit Investigator', 'date' => 'Aug 8, 2026', 'text' => 'Sample timeline entry for component verification.']]" /></div>
    </div>

    <div class="mt-8 space-y-4">
        <x-ui.report-preview-toolbar :preview-url="route('home')" />
        <x-ui.recycle-bin-item title="SAMPLE CLIENT" number="BRBI-CI-2026-00999" deleted-at="Aug 8, 2026" :restore-action="route('home')" />
        <x-ui.toast type="warning" message="Sample flash notification." />
        <x-ui.empty-state title="Empty state example" description="Use this pattern when a module has no records." />
    </div>

    <x-ui.sticky-form-toolbar>Sample sticky action area.<x-slot:actions><button class="ui-button-secondary">Cancel</button><button class="ui-button-primary">Save sample</button></x-slot:actions></x-ui.sticky-form-toolbar>

    <x-ui.modal id="sample-modal" title="Sample modal" description="Keyboard focus returns to the trigger when closed."><p class="text-sm text-text-muted">Reusable modal content.</p><x-slot:footer><button data-modal-close class="ui-button-secondary">Close</button></x-slot:footer></x-ui.modal>
    <x-ui.confirmation-dialog id="sample-confirmation" title="Confirm sample action?" :action="route('home')"><p class="text-sm text-text-muted">Reusable confirmation dialog content.</p></x-ui.confirmation-dialog>
@endsection
