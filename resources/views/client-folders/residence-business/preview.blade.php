<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @include('partials.favicon')
    <title>Residence & Business Report Preview · {{ $clientFolder->folder_number }}</title>
    @vite(['resources/css/app.css', 'resources/css/print/official-report.css'])
</head>
<body class="official-preview-body">
    <div class="encoding-ui-only sticky top-0 z-20 border-b border-ui-border bg-surface/95 p-3 shadow-card backdrop-blur">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3"><div><p class="font-bold text-brand-sidebar">Read-only Report Preview</p><p class="text-sm text-text-muted">Official output size: 8.5 × 13 inches. Final PDF and DOCX generation are not part of this phase.</p></div><x-ui.report-preview-toolbar><x-slot:actions><a href="{{ route('client-folders.residence-business.edit', $clientFolder) }}" class="ui-button-secondary">Back to Encoding</a></x-slot:actions></x-ui.report-preview-toolbar></div>
    </div>

    <main class="official-preview-shell" aria-label="Residence and Business report print preview">
        @if($report === null || $report->sections->isEmpty())
            <section class="official-report-page photo-report-page"><header class="photo-report-header"><h1>RESIDENCE & BUSINESS PHOTO REPORT</h1><p>{{ $clientFolder->display_name }}</p></header><div class="photo-empty-preview"><p>No report sections have been saved.</p><p>Return to the encoding form to add Residence and Business documentation sections.</p></div></section>
        @else
            @foreach($report->sections as $section)
                @php
                    $chunks = $section->mediaItems->chunk(2);
                    if ($chunks->isEmpty()) $chunks = collect([collect()]);
                    $partyLabel = match($section->subject_party) { 'co_maker' => 'Co-Maker Name', 'other' => 'Subject Name', default => 'Applicant Name' };
                    $partyName = $section->subject_party === 'applicant' ? $clientFolder->display_name : ($section->subject_name ?: 'Not recorded');
                @endphp
                @foreach($chunks as $pageIndex => $items)
                    <section class="official-report-page photo-report-page">
                        <header class="photo-report-header avoid-page-break">
                            <div class="photo-report-header-grid"><p><strong>{{ $partyLabel }}:</strong> {{ $partyName }}</p><p><strong>Date:</strong> {{ $report->report_date?->format('F j, Y') ?? 'Not recorded' }}</p><p><strong>Location:</strong> {{ $section->location ?: $report->default_location ?: 'Not recorded' }}</p><p><strong>CI:</strong> {{ $report->investigator?->full_name ?? $clientFolder->assignedInvestigator->full_name }}</p><p class="photo-report-subject"><strong>Subject:</strong> {{ $section->heading_subject ?: $report->default_subject ?: str($section->category->value)->title() . ' Check' }}@if($section->business_name) ({{ $section->business_name }})@endif @if($pageIndex > 0) - Continuation {{ $pageIndex + 1 }}@endif</p></div>
                            @if($section->incomeSource)<p class="photo-report-link"><strong>Income Source:</strong> {{ $section->incomeSource->source_name }}</p>@endif
                            @if($section->google_maps_link)<p class="photo-report-link"><strong>Map:</strong> <a href="{{ $section->google_maps_link }}">{{ $section->google_maps_link }}</a></p>@endif
                            @if($pageIndex === 0 && $section->remarks)<p class="photo-report-findings"><strong>Findings:</strong> {{ $section->remarks }}</p>@endif
                        </header>
                        <div class="photo-report-media-grid">
                            @forelse($items as $item)
                                @php
                                    $reference = $item->mediaReference;
                                    $thumbnail = $reference->thumbnail_path;
                                    $imageUrl = is_string($thumbnail) && (str_starts_with($thumbnail, '/storage/') || str_starts_with($thumbnail, 'https://')) ? $thumbnail : null;
                                @endphp
                                <figure class="photo-report-media avoid-page-break">
                                    <figcaption><strong>{{ $item->output_label ?: $reference->label ?: $reference->file_name }}</strong>@if($item->caption)<span>{{ $item->caption }}</span>@endif</figcaption>
                                    <div class="photo-report-image-frame">
                                        @if($imageUrl && $reference->media_type->value === 'photo')<img src="{{ $imageUrl }}" alt="{{ $item->output_label ?: $reference->label ?: 'Report photograph' }}">@else<div class="photo-report-media-placeholder"><strong>{{ str($reference->media_type->value)->upper() }}</strong><span>{{ $reference->file_name }}</span><small>Existing media reference; image content is unavailable in this browser preview.</small></div>@endif
                                    </div>
                                    @if($reference->google_maps_link)<p class="photo-report-media-map"><strong>Map:</strong> {{ $reference->google_maps_link }}</p>@endif
                                </figure>
                            @empty
                                <div class="photo-empty-preview"><p>No media is linked to this section.</p></div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            @endforeach
            @if($report->residence_remarks || $report->business_remarks)
                <section class="official-report-page photo-report-page"><header class="photo-report-header"><h1>OVERALL INVESTIGATION FINDINGS</h1><p><strong>Applicant Name:</strong> {{ $clientFolder->display_name }}</p><p><strong>Date:</strong> {{ $report->report_date?->format('F j, Y') ?? 'Not recorded' }}</p></header><div class="photo-report-narratives">@if($report->residence_remarks)<section><h2>Residence Findings / Remarks</h2><p>{{ $report->residence_remarks }}</p></section>@endif @if($report->business_remarks)<section><h2>Business Findings / Remarks</h2><p>{{ $report->business_remarks }}</p></section>@endif</div></section>
            @endif
        @endif
    </main>
</body>
</html>
