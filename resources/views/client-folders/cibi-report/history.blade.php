@extends('layouts.app')

@section('title', 'CI / BI Report History · '.$clientFolder->display_name)

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'CI / BI History']]" />
    <x-ui.page-header title="CI / BI Report History" eyebrow="{{ $clientFolder->display_name }}">
        <x-slot:description>Signatory reassignments and save activity for this CI / BI report.</x-slot:description>
    </x-ui.page-header>

    <x-ui.note-timeline :notes="$entries->map(fn ($entry) => [
        'author' => $entry->user?->full_name ?? 'System',
        'date' => $entry->created_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A'),
        'text' => $entry->description.(data_get($entry->metadata, 'reason') ? ' Reason: '.data_get($entry->metadata, 'reason') : ''),
    ])->all()" />
@endsection
