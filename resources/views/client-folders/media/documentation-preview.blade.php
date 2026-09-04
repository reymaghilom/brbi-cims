@extends('layouts.app')

@section('title', 'Documentation Preview')

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', $clientFolder)], ['label' => 'Photos & Videos', 'url' => route('client-folders.media.index', [$clientFolder] + \App\Services\ClientFolders\ActivePersonResolver::queryParams($documentation->co_maker_id ? $documentation->coMaker : null))], ['label' => 'Preview']]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-primary">{{ $documentation->isResidence() ? 'Residence' : 'Business' }} Documentation Preview</p>
            <h1 class="ui-page-title mt-1">Before You Send</h1>
        </div>
    </div>

    <div class="ui-panel mt-6 p-4 sm:p-5">
        <h2 class="ui-section-title">Telegram Caption</h2>
        <pre class="mt-3 whitespace-pre-wrap break-words rounded-control border border-ui-border bg-surface-muted p-3.5 font-sans text-sm text-text-main">{{ $caption }}</pre>
    </div>

    @if(! $documentation->isResidence())
        <div class="ui-panel mt-5 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-primary">Selected Business</p>
                    <h2 class="mt-1 break-words text-lg font-bold text-text-main">{{ $documentation->businessDisplayName() }}</h2>
                    <p class="mt-1 flex items-start gap-1.5 text-sm leading-6 text-text-muted"><x-ui.icon name="pin" size="mt-1 size-3.5 shrink-0" />{{ $documentation->location }}</p>
                </div>
                <span class="shrink-0 rounded-full bg-brand-soft px-2.5 py-1 text-xs font-bold text-brand-primary">BD-{{ str_pad((string) $documentation->id, 6, '0', STR_PAD_LEFT) }}</span>
            </div>
        </div>
    @endif

    <div class="ui-panel mt-5 p-4 sm:p-5">
        <h2 class="ui-section-title">Media Send Order</h2>
        <ol class="mt-4 flex flex-col gap-4">
            <li>
                <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-primary">1</span>Google Map Screenshot</p>
                @if($documentation->mapScreenshot)
                    <img src="{{ route('client-folders.media.content', [$clientFolder, $documentation->mapScreenshot]) }}" alt="Map screenshot" class="mt-2 max-w-md rounded-control border border-ui-border">
                @else
                    <p class="mt-2 text-sm text-text-muted">No map screenshot uploaded.</p>
                @endif
            </li>
            <li>
                <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-primary">2</span>{{ $documentation->isResidence() ? 'Residence' : 'Business' }} Pictures ({{ $documentation->pictures->count() }})</p>
                @if($documentation->pictures->isEmpty())
                    <p class="mt-2 text-sm text-text-muted">No pictures uploaded.</p>
                @else
                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach($documentation->pictures as $picture)
                            <img src="{{ route('client-folders.media.content', [$clientFolder, $picture]) }}" alt="Picture" class="aspect-[4/3] w-full rounded-control border border-ui-border object-cover {{ ! $documentation->isResidence() && $loop->first && $documentation->pictures->count() > 1 ? 'sm:col-span-2 sm:row-span-2 sm:h-full' : '' }}">
                        @endforeach
                    </div>
                @endif
            </li>
            {{-- Remarks and Video are optional: they appear only when actually present, so a set
                 without them never shows an empty placeholder in the send order. --}}
            @if(filled($documentation->remarks))
                <li>
                    <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-primary">3</span>Remarks</p>
                    <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-text-main">{{ $documentation->remarks }}</p>
                </li>
            @endif
            @if($documentation->videos->isNotEmpty())
                <li>
                    <p class="flex items-center gap-2 text-sm font-bold text-text-main"><span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-primary">{{ filled($documentation->remarks) ? 4 : 3 }}</span>{{ $documentation->isResidence() ? 'Residence' : 'Business' }} Video ({{ $documentation->videos->count() }})</p>
                    <div class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        @foreach($documentation->videos as $video)
                            <div class="grid aspect-square w-full place-items-center rounded-control border border-ui-border bg-brand-soft text-brand-primary"><x-ui.icon name="video" size="size-6" /></div>
                        @endforeach
                    </div>
                </li>
            @endif
        </ol>
    </div>
@endsection
