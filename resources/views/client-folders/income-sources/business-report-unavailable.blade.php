@extends('layouts.app')

@section('title', $title)

@section('content')
    <section class="ui-panel mx-auto max-w-lg p-5 text-center sm:p-6" role="alert" data-business-report-unavailable>
        <span class="mx-auto grid size-11 place-items-center rounded-full bg-progress-soft text-progress">
            <x-ui.icon name="warning" size="size-5" aria-hidden="true" />
        </span>
        <h1 class="mt-4 text-lg font-bold text-brand-sidebar">{{ $title }}</h1>
        <p class="mt-2 text-sm leading-6 text-text-muted">{{ $message }}</p>
        <div class="mt-5 flex justify-center">
            <a href="{{ route('client-folders.index') }}" class="ui-button-secondary">
                <x-ui.icon name="folder" size="size-4" />Go to Client Folders
            </a>
        </div>
    </section>
@endsection
