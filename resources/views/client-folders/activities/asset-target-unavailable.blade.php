@extends('layouts.app')

@section('title', 'Asset Check target unavailable')

@section('content')
    {{-- Rendered with a 404 status for a missing Asset Check target. Deliberately generic: the
         same not-found outcome covers a deleted target, a stale page, and a target belonging to
         another activity, person or folder, so this must never claim the record was "deleted by
         another user" and must never name the record it could not resolve. Nothing is created,
         changed or recovered here — only the wording the CI reads. --}}
    <section class="ui-panel mx-auto max-w-lg p-5 text-center sm:p-6" role="alert" data-asset-target-unavailable>
        <span class="mx-auto grid size-11 place-items-center rounded-full bg-progress-soft text-progress">
            <x-ui.icon name="warning" size="size-5" aria-hidden="true" />
        </span>
        <h1 class="mt-4 text-lg font-bold text-brand-sidebar">Asset Check target unavailable</h1>
        <p class="mt-2 text-sm leading-6 text-text-muted">{{ $message }}</p>
        <div class="mt-5 flex justify-center">
            <a href="{{ route('client-folders.index') }}" class="ui-button-secondary">
                <x-ui.icon name="folder" size="size-4" />Go to Client Folders
            </a>
        </div>
    </section>
@endsection
