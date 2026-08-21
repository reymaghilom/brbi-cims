@extends('layouts.cibi-encoding')

@section('title', 'CI / BI Report · '.$clientFolder->display_name)

@section('content')
    <form id="cibi-report-form" method="POST" action="{{ route('client-folders.cibi-report.update', $clientFolder) }}" class="cibi-encoding-page w-full" data-cibi-form data-unsaved-form novalidate>
        @csrf
        @method('PUT')
        <input type="hidden" name="intent" value="complete" data-cibi-intent>
        <input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">

        <div class="mb-3 rounded-control border border-danger/30 bg-danger-soft p-3 text-sm text-danger" role="alert" tabindex="-1" data-cibi-error-summary @if(!$errors->any()) hidden @endif>
            <p class="font-semibold">Please correct the highlighted report fields.</p>
            <p class="mt-1" data-cibi-error-message>No report changes were saved.</p>
        </div>

        <div class="cibi-encoding-paper" data-cibi-scroll-region>
            @include('client-folders.cibi-report._form-fields')
        </div>

        <x-ui.sticky-form-toolbar class="!bottom-3 !rounded-control !p-2.5">
            <span class="sr-only" data-cibi-revision>{{ $report?->revision ?? 1 }}</span>
            <x-slot:actions>
                <button type="submit" name="intent" value="complete" class="ui-button-primary" data-cibi-submit data-cibi-submit-mode="{{ $report?->state?->value === 'complete' ? 'update' : 'save' }}">{{ $report?->state?->value === 'complete' ? 'Update' : 'Save' }}</button>
            </x-slot:actions>
        </x-ui.sticky-form-toolbar>
    </form>

    <x-ui.modal id="cibi-remove-entry-dialog" title="Remove this entry?" description="This row already contains information. Are you sure you want to remove it?" size="max-w-md" data-repeater-remove-dialog>
        <p class="text-sm text-text-muted">The entry will be removed when the CI / BI report is saved.</p>
        <x-slot:footer><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="button" class="ui-button-danger" data-repeater-remove-confirm>Remove</button></x-slot:footer>
    </x-ui.modal>
@endsection
