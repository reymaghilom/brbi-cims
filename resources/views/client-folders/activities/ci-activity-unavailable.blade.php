@extends('layouts.app')

@section('title', 'CI Activity unavailable')

@section('content')
    {{-- Rendered with a 404 status for a CI Activity that cannot be resolved. Deliberately generic:
         the same not-found outcome covers a deleted activity, a stale page, a forged id, and an
         activity belonging to another folder or another person, so this must never claim the record
         was "deleted by another user" and must never name the record it could not resolve. Nothing
         is created, changed or recovered here — only the wording the CI reads. --}}
    <section class="ui-panel mx-auto max-w-lg p-5 text-center sm:p-6" role="alert" data-ci-activity-unavailable>
        <span class="mx-auto grid size-11 place-items-center rounded-full bg-progress-soft text-progress">
            <x-ui.icon name="warning" size="size-5" aria-hidden="true" />
        </span>
        <h1 class="mt-4 text-lg font-bold text-brand-sidebar">CI Activity unavailable</h1>
        <p class="mt-2 text-sm leading-6 text-text-muted">{{ $message }}</p>
        <div class="mt-5 flex justify-center">
            <a href="{{ route('client-folders.index') }}" class="ui-button-secondary">
                <x-ui.icon name="folder" size="size-4" />Go to Client Folders
            </a>
        </div>
    </section>
@endsection
