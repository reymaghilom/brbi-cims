@extends('layouts.app')

@section('title', 'Client Folders')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Client Folders']]" />

    @include('dashboard._folder-browser', ['folderBrowserAction' => route('client-folders.index')])
@endsection
