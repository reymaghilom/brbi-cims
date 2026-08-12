@extends('layouts.app')

@section('title', 'Recycle Bin')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Recycle Bin']]" />
    <x-ui.page-header title="Recycle Bin" eyebrow="Deleted client folders">
        <x-slot:description>Recycled folders retain their reports and history. Only an Administrator may restore or permanently delete them.</x-slot:description>
    </x-ui.page-header>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/25 bg-danger-soft p-4 text-sm font-semibold text-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    @if($clientFolders->isEmpty())
        <x-ui.empty-state title="Recycle Bin is empty" description="Folders moved to the Recycle Bin within your authorized scope will appear here." icon="trash" />
    @else
        <section class="space-y-4" aria-labelledby="recycled-folders-title">
            <div class="flex items-center justify-between gap-3">
                <h2 id="recycled-folders-title" class="ui-section-title">Recycled folders</h2>
                <p class="text-sm text-text-muted">{{ $clientFolders->total() }} {{ Str::plural('folder', $clientFolders->total()) }}</p>
            </div>

            @foreach($clientFolders as $clientFolder)
                <x-ui.recycle-bin-item
                    :title="$clientFolder->display_name"
                    :number="$clientFolder->folder_number"
                    :deleted-at="$clientFolder->deleted_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A')"
                    :assigned-ci="$clientFolder->assignedInvestigator->full_name"
                    :deleted-by="$clientFolder->deletedBy?->full_name"
                    :restore-action="auth()->user()->can('restore', $clientFolder) ? route('recycle-bin.restore', $clientFolder) : null"
                    :purge-action="auth()->user()->can('forceDelete', $clientFolder) ? route('recycle-bin.destroy', $clientFolder) : null"
                />
            @endforeach
        </section>

        @if($clientFolders->hasPages())
            <nav class="mt-8" aria-label="Recycle Bin pagination">{{ $clientFolders->links() }}</nav>
        @endif
    @endif
@endsection
