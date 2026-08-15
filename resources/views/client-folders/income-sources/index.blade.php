@extends('layouts.app')

@section('title', 'Business Report')

@section('content')
    @error('income_source')<div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm font-semibold text-danger" role="alert">{{ $message }}</div>@enderror

    @if($sources->isEmpty())
        <x-ui.empty-state title="No income sources yet" description="Add the first income source and select the official template that matches it." icon="folder"><a href="{{ route('client-folders.income-sources.select-template', $clientFolder) }}" class="ui-button-primary">+ Add Income Source</a></x-ui.empty-state>
    @else
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach($sources as $source)
                <article class="ui-card border-t-4 border-t-folder p-5">
                    <div class="flex items-start justify-between gap-3"><span class="grid size-11 place-items-center rounded-card bg-folder-soft text-brand-sidebar"><x-ui.icon name="folder" /></span><x-ui.status-badge :status="$source->state" /></div>
                    <p class="mt-4 text-xs font-bold uppercase tracking-wide text-text-muted">{{ $source->template->is_fallback ? 'Official fallback' : 'Dedicated report' }}</p>
                    <h2 class="mt-1 text-lg font-bold">{{ $source->source_name }}</h2>
                    <p class="mt-1 text-sm text-text-muted">{{ $source->template->name }}</p>
                    @if($source->template->business_category)<p class="mt-1 text-sm font-semibold text-brand-primary">{{ $source->template->business_category }}</p>@endif
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-text-muted">Updated</dt><dd class="font-bold">{{ $source->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</dd></div><div><dt class="text-text-muted">Revision</dt><dd class="font-bold">{{ $source->revision }}</dd></div><div><dt class="text-text-muted">Linked records</dt><dd class="font-bold">{{ $source->media_references_count + $source->generated_reports_count }}</dd></div></dl>
                    <div class="mt-5 flex flex-wrap gap-2"><a href="{{ route('client-folders.income-sources.edit', [$clientFolder, $source]) }}" class="ui-button-primary">Open Report</a><button type="button" data-modal-open="delete-income-source-{{ $source->id }}" class="ui-button-danger">Remove</button></div>
                    <x-ui.confirmation-dialog id="delete-income-source-{{ $source->id }}" title="Remove this income source?" :action="route('client-folders.income-sources.destroy', [$clientFolder, $source])" method="DELETE" confirm-label="Remove income source" destructive><p class="text-sm text-text-muted">Removal is blocked if this source is already referenced by media, generated reports, CI / BI summaries, or photo-report sections.</p></x-ui.confirmation-dialog>
                </article>
            @endforeach
        </div>
    @endif
@endsection
