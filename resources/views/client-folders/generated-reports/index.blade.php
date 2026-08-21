@extends('layouts.app')

@section('title', 'Generated Reports')

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)], ['label' => 'Generated Reports']]" />
    <x-ui.page-header title="Generated Reports" eyebrow="Protected official artifacts">
        <x-slot:description>Create, preview, download, and regenerate historical PDF and editable Word versions. Every output is built from currently saved report data.</x-slot:description>
        <x-slot:actions><a href="{{ route('client-folders.show', [$clientFolder] + $personParams) }}" class="ui-button-secondary">Back to Folder</a></x-slot:actions>
    </x-ui.page-header>

    @if(session('error'))<div class="mb-6 rounded-card border border-danger/30 bg-danger-soft p-4 text-sm font-semibold text-danger" role="alert">{{ session('error') }}</div>@endif

    <section class="ui-card p-5 sm:p-6" aria-labelledby="generate-report-title">
        <div class="mb-5"><p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-primary">Official output</p><h2 id="generate-report-title" class="mt-1 text-xl font-bold">Generate a new version</h2><p class="mt-2 text-sm text-text-muted">Default paper size is 8.5 × 13 inches. Save encoding changes before generating.</p></div>
        @if($options === [])
            <x-ui.empty-state title="No saved report data" description="Start a CI / BI, Business / Income Source, or Residence & Business report before generating an artifact." icon="report" />
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($options as $option)
                    <article class="rounded-card border border-ui-border bg-surface-subtle p-4">
                        <h3 class="font-bold text-brand-sidebar">{{ $option['label'] }}</h3>
                        <p class="mt-1 text-xs text-text-muted">{{ $option['source'] ? 'Linked to this specific income source.' : 'Linked to the client folder.' }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a target="_blank" rel="noopener" href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => $option['type']->value, 'income_source_id' => $option['source']?->id] + $personParams) }}" class="ui-button-secondary">Preview / Print</a>
                            @foreach(App\Enums\ReportFormat::cases() as $format)
                                <form method="POST" action="{{ route('client-folders.generated-reports.store', $clientFolder) }}">@csrf
                                    <input type="hidden" name="report_type" value="{{ $option['type']->value }}"><input type="hidden" name="format" value="{{ $format->value }}"><input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">@if($option['source'])<input type="hidden" name="income_source_id" value="{{ $option['source']->id }}">@endif
                                    <button class="ui-button-primary" type="submit">Generate {{ strtoupper($format->value) }}</button>
                                </form>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-8" aria-labelledby="history-title">
        <div class="mb-4"><h2 id="history-title" class="text-xl font-bold">Artifact history</h2><p class="mt-1 text-sm text-text-muted">Earlier versions remain available and are never overwritten by regeneration.</p></div>
        @if($reports->isEmpty())
            <x-ui.empty-state title="No generated reports" description="Generated PDF and Word artifacts will appear here." icon="report" />
        @else
            <div class="ui-card overflow-x-auto"><table class="min-w-full divide-y divide-ui-border text-left text-sm"><thead class="bg-surface-subtle text-xs uppercase tracking-wide text-text-muted"><tr><th class="px-4 py-3">Report</th><th class="px-4 py-3">Format / Version</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Generated</th><th class="px-4 py-3">Actions</th></tr></thead><tbody class="divide-y divide-ui-border">
                @foreach($reports as $report)<tr><td class="px-4 py-4"><strong>{{ App\Enums\OfficialReportType::tryFrom($report->report_type)?->label() ?? str($report->report_type)->headline() }}</strong>@if($report->incomeSource)<span class="mt-1 block text-xs text-text-muted">{{ $report->incomeSource->source_name }}</span>@endif</td><td class="px-4 py-4 font-semibold">{{ strtoupper($report->format->value) }} · v{{ $report->version }}</td><td class="px-4 py-4"><x-ui.status-badge :status="$report->status" /></td><td class="px-4 py-4"><span class="block">{{ $report->generated_at?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') ?? 'Not completed' }}</span><span class="mt-1 block text-xs text-text-muted">{{ $report->generator->full_name }}</span></td><td class="px-4 py-4"><div class="flex flex-wrap gap-2">@if($report->status === App\Enums\GenerationStatus::Completed)<a href="{{ route('client-folders.generated-reports.download', [$clientFolder, $report]) }}" class="ui-button-secondary">Download</a>@endif<form method="POST" action="{{ route('client-folders.generated-reports.regenerate', [$clientFolder, $report]) }}">@csrf<button type="submit" class="ui-button-secondary">Regenerate</button></form></div>@if($report->status === App\Enums\GenerationStatus::Failed)<p class="mt-2 max-w-xs text-xs text-danger">{{ $report->failure_message }}</p>@endif</td></tr>@endforeach
            </tbody></table></div>{{ $reports->links() }}
        @endif
    </section>
@endsection
