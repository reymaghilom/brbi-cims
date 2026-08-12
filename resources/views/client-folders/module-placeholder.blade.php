@extends('layouts.app')

@section('title', $title)

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => $title]]" />
    <x-ui.page-header :title="$title" eyebrow="{{ $clientFolder->folder_number }}">
        <x-slot:description>This authorized module destination is ready for its dedicated implementation{{ $phase ? ' in Phase '.$phase : '' }}.</x-slot:description>
        <x-slot:actions><a href="{{ route('client-folders.show', $clientFolder) }}" class="ui-button-secondary">Back to Folder Contents</a></x-slot:actions>
    </x-ui.page-header>

    <x-ui.empty-state :title="$title.' is not encoded yet'" description="Phase 9 provides navigation and real summary data only. No later-phase business workflow has been implemented." icon="folder" />
@endsection
