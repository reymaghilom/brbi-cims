@extends('layouts.app')

@section('title', 'All Business Documentation Preview')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Photos & Videos', 'url' => route('client-folders.media.index', [$clientFolder] + \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson) + ['tab' => 'business'])], ['label' => 'Preview All Businesses']]" />

    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-primary">Grouped Telegram Preview</p>
            <h1 class="ui-page-title mt-1">Business Pictures and Videos</h1>
            <p class="mt-2 text-sm text-text-muted">{{ $documentations->count() }} saved {{ Str::plural('business', $documentations->count()) }} · deterministic BD order</p>
        </div>
    </div>

    <div class="mt-6 flex flex-col gap-5">
        @foreach($documentations as $documentation)
            <section class="ui-panel overflow-hidden" aria-labelledby="business-preview-{{ $documentation->id }}">
                <div class="border-b border-ui-border bg-surface-muted px-4 py-4 sm:px-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-primary">Business {{ $loop->iteration }} of {{ $documentations->count() }}</p>
                            <h2 id="business-preview-{{ $documentation->id }}" class="mt-1 break-words text-lg font-bold text-text-main">{{ $documentation->businessDisplayName() }}</h2>
                        </div>
                        <span class="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-bold text-brand-primary">BD-{{ str_pad((string) $documentation->id, 6, '0', STR_PAD_LEFT) }}</span>
                    </div>
                </div>

                <div class="p-4 sm:p-5">
                    <h3 class="ui-section-title">Business Caption</h3>
                    <pre class="mt-3 whitespace-pre-wrap break-words rounded-control border border-ui-border bg-surface-muted p-3.5 font-sans text-sm text-text-main">{{ $captions[$documentation->id] }}</pre>

                    <ol class="mt-5 flex flex-col gap-4">
                        @if($documentation->mapScreenshot)
                            <li>
                                <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="media-step-badge">1</span>Google Map Screenshot</p>
                                <img src="{{ route('client-folders.media.content', [$clientFolder, $documentation->mapScreenshot]) }}" alt="Map screenshot for {{ $documentation->businessDisplayName() }}" class="mt-2 max-w-md rounded-control border border-ui-border">
                            </li>
                        @endif
                        <li>
                            <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="media-step-badge">{{ $documentation->mapScreenshot ? 2 : 1 }}</span>Business Pictures ({{ $documentation->pictures->count() }})</p>
                            @if($documentation->pictures->isEmpty())
                                <p class="mt-2 text-sm text-text-muted">No saved pictures.</p>
                            @else
                                <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                    @foreach($documentation->pictures as $picture)
                                        <img src="{{ route('client-folders.media.content', [$clientFolder, $picture]) }}" alt="Picture for {{ $documentation->businessDisplayName() }}" class="aspect-[4/3] w-full rounded-control border border-ui-border object-cover">
                                    @endforeach
                                </div>
                            @endif
                        </li>
                        @if($documentation->videos->isNotEmpty())
                            <li>
                                <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="media-step-badge">{{ $documentation->mapScreenshot ? 3 : 2 }}</span>Business Videos ({{ $documentation->videos->count() }})</p>
                                <div class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                                    @foreach($documentation->videos as $video)
                                        <div class="grid aspect-square place-items-center rounded-control border border-ui-border bg-brand-soft text-brand-primary" title="{{ $video->file_name }}"><x-ui.icon name="video" size="size-6" /></div>
                                    @endforeach
                                </div>
                            </li>
                        @endif
                    </ol>
                </div>
            </section>
        @endforeach
    </div>
@endsection
