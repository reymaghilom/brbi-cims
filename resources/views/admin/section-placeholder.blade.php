@extends('layouts.app')

@section('title', $title)

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => $title]]" />
    <x-ui.page-header :title="$title" eyebrow="Administration"><x-slot:description>Administrator authorization is active for this protected workspace.</x-slot:description></x-ui.page-header>
    <x-ui.empty-state :title="$title.' foundation ready'" description="The global UI shell is complete. The full module workflow remains in its approved implementation phase." icon="settings" />
@endsection
