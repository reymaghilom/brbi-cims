@extends('layouts.cibi-encoding')

@section('title', 'CI / BI Report · '.$clientFolder->display_name)

@section('content')
    <form id="cibi-report-form" method="POST" action="{{ route('client-folders.cibi-report.update', $clientFolder) }}" class="cibi-encoding-page w-full" data-cibi-form data-unsaved-form novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="intent" value="complete" data-cibi-intent>
        <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
        <input type="hidden" name="expected_revision" value="{{ $report?->revision }}" data-cibi-expected-revision>

        <div class="mb-3 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert" tabindex="-1" data-cibi-error-summary @if(!$errors->any()) hidden @endif>
            <p class="font-semibold">Please correct the highlighted report fields.</p>
            <p class="mt-1" data-cibi-error-message>No report changes were saved.</p>
        </div>

        @if($report)
            <div data-editing-presence data-editing-type="cibi_report" data-editing-id="{{ $report->id }}" data-editing-label="CI/BI Report">
                <div data-editing-presence-banner hidden role="status" class="mb-3 flex items-start gap-2 rounded-control border border-progress/30 bg-progress-soft p-3 text-sm text-progress">
                    <x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0" />
                    <span data-editing-presence-text></span>
                </div>
            </div>
        @endif

        <div class="cibi-encoding-paper" data-cibi-scroll-region>
            @include('client-folders.cibi-report._form-fields')
        </div>

        <x-ui.sticky-form-toolbar class="!bottom-3 !rounded-control !p-2.5">
            <span class="sr-only" data-cibi-revision>{{ $report?->revision ?? 1 }}</span>
            {{-- Same action-bar convention as Business Check / Business Report: a secondary Cancel
                 that closes the dialog this form is opened in, and a primary submit carrying the
                 save icon. The label follows the one existing create/edit signal this page already
                 uses (the report's own completed state), and the submit's name/value/mode hooks are
                 untouched, so CIBI's save/update lifecycle is exactly as before. The label lives in
                 its own span so the AJAX refresh can retitle the button without dropping the icon. --}}
            <x-slot:actions>
                <button type="button" class="ui-button-secondary" data-close-parent-dialog><x-ui.icon name="close" size="size-4" />Cancel</button>
                <button type="submit" name="intent" value="complete" class="ui-button-primary" data-cibi-submit data-cibi-submit-mode="{{ $report?->state?->value === 'complete' ? 'update' : 'save' }}"><x-ui.icon name="check" size="size-4" /><span data-cibi-submit-text>{{ $report?->state?->value === 'complete' ? 'Update CIBI Report' : 'Save CIBI Report' }}</span></button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>

    <x-ui.modal id="cibi-remove-entry-dialog" title="Remove this entry?" description="This row already contains information. Are you sure you want to remove it?" size="max-w-md" data-repeater-remove-dialog>
        <p class="text-sm text-text-muted">The entry will be removed when the CI / BI report is saved.</p>
        <x-slot:footer><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="button" class="ui-button-danger" data-repeater-remove-confirm>Remove</button></x-slot:footer>
    </x-ui.modal>

@endsection
