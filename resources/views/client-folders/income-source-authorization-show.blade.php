@extends('layouts.app')

@section('title', $incomeSource->source_name)

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => $incomeSource->source_name]]" />
    <x-ui.page-header :title="$incomeSource->source_name" eyebrow="Business / Income Source"><x-slot:description>{{ $clientFolder->folder_number }} · Nested folder and income-source authorization verified.</x-slot:description></x-ui.page-header>
    <x-ui.empty-state title="Income-source form remains in Phase 13" description="Template selection and dedicated form behavior have not been implemented in Phase 5." icon="report" />
@endsection
