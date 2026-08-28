@extends('layouts.app')

@section('title', 'Recycle Bin')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => 'Recycle Bin']]" />
    <x-ui.page-header title="Recycle Bin" eyebrow="Deleted records">
        <x-slot:description>Recycled folders and businesses retain their reports, checks, media, and history. Existing authorization rules apply to restore and permanent deletion.</x-slot:description>
    </x-ui.page-header>

    @if($errors->any())
        <div class="mb-6 rounded-card border border-danger/25 bg-danger-soft p-4 text-sm font-semibold text-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    @if($clientFolders->isEmpty() && $businesses->isEmpty())
        <x-ui.empty-state title="Recycle Bin is empty" description="Folders and businesses moved to the Recycle Bin within your authorized scope will appear here." icon="trash" />
    @else
        @if($businesses->isNotEmpty())
            <section class="mb-8 space-y-4" aria-labelledby="recycled-businesses-title">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="recycled-businesses-title" class="ui-section-title">Recycled businesses</h2>
                    <p class="text-sm text-text-muted">{{ $businesses->total() }} {{ Str::plural('business', $businesses->total()) }}</p>
                </div>

                @foreach($businesses as $business)
                    <article class="ui-panel flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-text-main">{{ $business->displayName() }}</h3>
                            <p class="mt-1 text-sm text-text-muted">
                                {{ $business->co_maker_id === null ? 'Applicant' : 'Co-Maker #'.$business->co_maker_id }}
                                <span aria-hidden="true">&middot;</span>
                                {{ $business->clientFolder->display_name }}
                            </p>
                            <p class="mt-1 text-xs text-text-muted">Moved {{ $business->deleted_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') }} <span aria-hidden="true">&middot;</span> Business ID {{ $business->id }}</p>
                        </div>

                        @can('restore', $business)
                            <form method="POST" action="{{ route('recycle-bin.businesses.restore', $business) }}" class="shrink-0">
                                @csrf
                                @method('PATCH')
                                <button class="ui-button-primary">Restore business</button>
                            </form>
                        @endcan
                    </article>
                @endforeach

                @if($businesses->hasPages())
                    <nav class="mt-6" aria-label="Recycled businesses pagination">{{ $businesses->links() }}</nav>
                @endif
            </section>
        @endif

        @if($clientFolders->isNotEmpty())
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
                        :assigned-ci="$clientFolder->assignedInvestigator?->full_name ?? '—'"
                        :deleted-by="$clientFolder->deletedBy?->full_name"
                        :restore-action="auth()->user()->can('restore', $clientFolder) ? route('recycle-bin.restore', $clientFolder) : null"
                        :purge-action="auth()->user()->can('forceDelete', $clientFolder) ? route('recycle-bin.destroy', $clientFolder) : null"
                    />
                @endforeach

                @if($clientFolders->hasPages())
                    <nav class="mt-8" aria-label="Recycle Bin pagination">{{ $clientFolders->links() }}</nav>
                @endif
            </section>
        @endif
    @endif
@endsection
