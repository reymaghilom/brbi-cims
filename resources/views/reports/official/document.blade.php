<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $document['title'] }}</title>
@unless($pdfMode) @include('partials.favicon') @endunless
<style>
@page { size: 8.5in 13in; margin: .45in; }
* { box-sizing: border-box; } body { margin: 0; color: #000; font-family: Arial, Helvetica, DejaVu Sans, sans-serif; font-size: 8.5pt; line-height: 1.22; }
.report-title { margin: 0; text-align: center; font-size: 14pt; } .report-subtitle { margin: .04in 0 .12in; text-align: center; font-weight: bold; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } th, td { border: .7pt solid #000; padding: .045in .055in; vertical-align: top; overflow-wrap: anywhere; }
th { background: #e7e7e7; font-size: 7.5pt; text-align: left; } .details td:nth-child(odd) { width: 15%; font-weight: bold; background: #f3f3f3; } .details td:nth-child(even) { width: 35%; }
.section { margin-top: .11in; } h2 { margin: 0 0 .04in; font-size: 9.5pt; text-transform: uppercase; } p { margin: 0; white-space: pre-wrap; }
.photo-page { page-break-before: always; } .photo { margin-top: .08in; page-break-inside: avoid; } .photo-frame { height: 4.55in; border: 1px solid #333; text-align: center; overflow: hidden; background: #fafafa; } .photo-frame img { max-width: 100%; max-height: 100%; } .placeholder { padding-top: 2in; color: #555; }
.photo-frame-plain { border: none; background: none; box-shadow: none; }
{{-- Business Check only (see the photo-frame-tall class in _photo-sections.blade.php) — its
     compact header (residence-header table, no title/subtitle) leaves noticeably more usable page
     height than Residence Check's own, so its photos get a taller frame to actually fill that space
     the way business1.docx/business1.pdf's large photos do, without changing Residence Check's own
     .photo-frame sizing above (still 4.55in). Kept comfortably under the ~11.2in actually available
     (12.1in content box minus the compact header and inter-photo gap) so a longer real Remarks line
     wrapping to a second line never pushes a page past 2 photos. --}}
.photo-frame-tall { height: 5.15in; }
.residence-header td { border: none; vertical-align: top; padding: 0; } .residence-header-left { width: 66%; padding-right: .15in; } .residence-header-right { width: 34%; } .residence-header p { margin: 0 0 .05in; }
{{-- No fixed box height here (unlike .photo-frame above) — see residence-business-check-batch.blade.php's identical comment: a forced height that doesn't match the actual scaled image left Dompdf's page-break-inside:avoid calculating against reserved-but-unused space, letting the heading and screenshot land on different pages even when the real content was short enough to fit together. --}}
.map-photo-frame { text-align: center; overflow: hidden; } .map-photo-frame img { max-width: 100%; max-height: 10.6in; }
.google-map-page { page-break-inside: avoid; }
.footer-note { margin-top: .1in; text-align: right; color: #555; font-size: 7pt; }
@unless($pdfMode) @media screen { body { background: #e5e7eb; } .preview-toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items:center; justify-content: space-between; gap: 12px; padding: 10px 18px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); font-family:Arial,Helvetica,system-ui,sans-serif; font-size: 10pt; } .preview-brand { display:flex; min-width:0; align-items:center; color:#1e3a8a; } .preview-brand img { display:block; width:180px; max-width:42vw; height:38px; object-fit:contain; object-position:left center; } .preview-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:flex-end; gap:8px; } .preview-toolbar a,.preview-toolbar button { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; background: #fff; color: #1e3a8a; font-weight: 600; text-decoration: none; cursor: pointer; } .preview-toolbar .preview-action { display:inline-flex; align-items:center; justify-content:center; gap:7px; white-space:nowrap; } .preview-toolbar .preview-action svg { width:16px; height:16px; } .preview-toolbar button,.preview-toolbar .preview-primary { border-color:#1e3a8a; background:#1e3a8a; color:#fff; } .preview-toolbar button:hover,.preview-toolbar .preview-primary:hover { background:#172f70; } .preview-toolbar a:hover { border-color:#1e3a8a; background:#eff6ff; } .preview-toolbar a:focus-visible,.preview-toolbar button:focus-visible { outline:3px solid rgba(37,99,235,.3); outline-offset:2px; } .report-sheet { width: 8.5in; min-height: 13in; margin: .3in auto; padding: .45in; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); } } @endunless
@media print { .preview-toolbar { display:none; } .report-sheet { margin:0; padding:0; box-shadow:none; } }
@if(!$pdfMode && ($document['type'] ?? null) === 'residence_business_photo')
{{-- Residence & Business Photo Report only, Web Preview only: .official-report-page (used
     exclusively by _photo-sections.blade.php, and the header "page" wrapper added below) becomes
     its own separate 8.5x13 white sheet with a small "Page N" badge, instead of one continuous
     scroll — a purely visual aid so a CI can immediately see where each printed page starts.
     Guarded to this one report type so CIBI/Business/General Income Source previews (which share
     this same document.blade.php but never render .official-report-page) are untouched, and to
     Web Preview only — Dompdf renders with defaultMediaType 'print', so this @media screen block
     (and the "Page N" badge inside it) never reaches the PDF, and PhpWord/DOCX never sees this
     Blade file at all. --}}
@media screen {
    .report-sheet { width: auto; min-height: 0; margin: 0; padding: 0; background: transparent; box-shadow: none; counter-reset: brbi-page; }
    .official-report-page { width: 8.5in; min-height: 13in; margin: 0 auto 28px; padding: .45in; background: #fff; box-shadow: 0 10px 30px rgba(15,23,42,.18); box-sizing: border-box; position: relative; counter-increment: brbi-page; }
    .official-report-page::after { content: "Page " counter(brbi-page); position: absolute; right: .3in; bottom: .22in; font-size: 7.5pt; font-weight: 700; color: #6b7280; background: #f3f4f6; padding: 2px 8px; border-radius: 10px; }
}
@endif
</style>@if(($document['type'] ?? null) === 'cibi') @include('reports.official.cibi-styles') @elseif(($document['type'] ?? null) === 'business_income_source') @include('reports.official.business-styles') @endif</head><body>
@unless($pdfMode)
    @if(($document['type'] ?? null) === 'business_income_source')
        <form id="business-preview-export-excel" method="POST" action="{{ route('client-folders.income-sources.export-excel', [$clientFolder, $source] + $personParams) }}" hidden>
            @csrf
            @if(array_key_exists('co_maker_id', $personParams))<input type="hidden" name="co_maker_id" value="{{ $personParams['co_maker_id'] }}">@endif
        </form>
        <nav class="preview-toolbar" aria-label="Business Report preview actions">
            <div class="preview-brand"><img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></div>
            <div class="preview-actions">
                <a href="{{ route('client-folders.generated-reports.index', [$clientFolder] + $personParams) }}" class="preview-action">Back to Reports</a>
                <a href="{{ route('client-folders.income-sources.export-pdf', [$clientFolder, $source] + $personParams) }}" class="preview-action preview-primary" aria-label="Download PDF"><x-ui.icon name="file-pdf" />Download PDF</a>
                <button type="submit" form="business-preview-export-excel" class="preview-action" aria-label="Download Excel"><x-ui.icon name="spreadsheet" />Download Excel</button>
                <button type="button" class="preview-action" onclick="window.print()" aria-label="Print current Business Report"><x-ui.icon name="printer" />Print</button>
            </div>
        </nav>
    @elseif(($document['type'] ?? null) === 'cibi')
        <nav class="preview-toolbar" aria-label="Report preview actions"><div class="preview-brand"><img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></div><div class="preview-actions"><a href="{{ route('client-folders.show', $clientFolder) }}">Back to Reports</a><button type="button" onclick="window.print()">Print</button></div></nav>
    @else
        <nav class="preview-toolbar" aria-label="Report preview actions"><div><strong>Read-only Report Preview</strong><br><span>8.5 × 13 inches · saved data only</span></div><div class="preview-actions"><a href="{{ route('client-folders.generated-reports.index', $clientFolder) }}">Back to Reports</a><button type="button" onclick="window.print()">Print</button></div></nav>
    @endif
@endunless
<main class="report-sheet">
@if(($document['type'] ?? null) === 'cibi')
@include('reports.official.cibi')
@elseif(($document['type'] ?? null) === 'business_income_source')
<article class="business-official-sheet" aria-label="Official Business Report">
@include('reports.official.business')
</article>
@else
@php($isResidenceBusinessPhoto = ($document['type'] ?? null) === 'residence_business_photo')
@if($isResidenceBusinessPhoto)<section class="official-report-page">@endif
<h1 class="report-title">{{ $document['title'] }}</h1><p class="report-subtitle">{{ $document['subtitle'] }}</p>
<table class="details"><tbody>@foreach(array_chunk($document['header'], 2) as $pair)<tr>@foreach([0,1] as $index) @php($item=$pair[$index] ?? ['', ''])<td>{{ $item[0] }}</td><td>{{ filled($item[1]) ? $item[1] : '—' }}</td>@endforeach</tr>@endforeach</tbody></table>
@foreach($document['sections'] as $section)<section class="section"><h2>{{ $section['title'] }}</h2>
@if($section['kind']==='narrative')<p>{{ $section['text'] }}</p>
@elseif($section['kind']==='details')<table class="details"><tbody>@foreach($section['rows'] as $row)<tr><td>{{ $row[0] }}</td><td colspan="3">{{ $row[1] }}</td></tr>@endforeach</tbody></table>
@else<table><thead><tr>@foreach($section['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>@forelse($section['rows'] as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($section['columns']) }}">No saved entries.</td></tr>@endforelse</tbody></table>@endif</section>@endforeach
@if($isResidenceBusinessPhoto)</section>@endif
@include('reports.official._photo-sections', ['photoSections' => $document['photo_sections']])
<p class="footer-note">Generated from saved BRBI data · {{ $document['generated_display_at'] }}</p>
@endif
</main>
</body></html>
