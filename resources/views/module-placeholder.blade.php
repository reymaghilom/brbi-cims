@extends('layouts.app')

@section('title', $title)

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => $title]]" />
    <x-ui.page-header :title="$title" eyebrow="Workspace module">
        <x-slot:description>This navigation destination is established by the global shell. Its complete workflow remains in its approved implementation phase.</x-slot:description>
    </x-ui.page-header>
    <x-ui.empty-state :title="$title.' is not implemented yet'" description="Phase 5 provides the shared interface foundation only; no later-phase business logic has been introduced." />
@endsection
