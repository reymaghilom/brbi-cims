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
.photo-page { page-break-before: always; height: 12.1in; } .photo { margin-top: .08in; page-break-inside: avoid; } .photo-frame { height: 4.55in; border: 1px solid #333; text-align: center; overflow: hidden; background: #fafafa; } .photo-frame img { max-width: 100%; max-height: 100%; } .placeholder { padding-top: 2in; color: #555; }
.footer-note { margin-top: .1in; text-align: right; color: #555; font-size: 7pt; }
@unless($pdfMode) @media screen { body { background: #e5e7eb; } .preview-toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items:center; justify-content: space-between; gap: 12px; padding: 10px 18px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); font-family:Arial,Helvetica,system-ui,sans-serif; font-size: 10pt; } .preview-brand { display:flex; min-width:0; align-items:center; color:#1e3a8a; } .preview-brand img { display:block; width:180px; max-width:42vw; height:38px; object-fit:contain; object-position:left center; } .preview-toolbar a,.preview-toolbar button { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; background: #fff; color: #1e3a8a; font-weight: 600; text-decoration: none; cursor: pointer; } .preview-toolbar button { background: #1e3a8a; color:#fff; } .preview-toolbar button:hover { background:#172f70; } .report-sheet { width: 8.5in; min-height: 13in; margin: .3in auto; padding: .45in; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); } } @endunless
@media print { .preview-toolbar { display:none; } .report-sheet { margin:0; padding:0; box-shadow:none; } }
</style>@if(($document['type'] ?? null) === 'cibi') @include('reports.official.cibi-styles') @elseif(($document['type'] ?? null) === 'business_income_source') @include('reports.official.business-styles') @endif</head><body>
@unless($pdfMode)<nav class="preview-toolbar" aria-label="Report preview actions">@if(in_array($document['type'] ?? null, ['cibi', 'business_income_source'], true))<div class="preview-brand"><img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></div><div><a href="{{ ($document['type'] ?? null) === 'cibi' ? route('client-folders.show', $clientFolder) : route('client-folders.generated-reports.index', $clientFolder) }}">Back to Reports</a> <button type="button" onclick="window.print()">Print</button></div>@else<div><strong>Read-only Report Preview</strong><br><span>8.5 × 13 inches · saved data only</span></div><div><a href="{{ route('client-folders.generated-reports.index', $clientFolder) }}">Back to Reports</a> <button type="button" onclick="window.print()">Print</button></div>@endif</nav>@endunless
<main class="report-sheet">
@if(($document['type'] ?? null) === 'cibi')
@include('reports.official.cibi')
@elseif(($document['type'] ?? null) === 'business_income_source')
<article class="business-official-sheet" aria-label="Official Business Report">
@include('reports.official.business')
</article>
@else
<h1 class="report-title">{{ $document['title'] }}</h1><p class="report-subtitle">{{ $document['subtitle'] }}</p>
<table class="details"><tbody>@foreach(array_chunk($document['header'], 2) as $pair)<tr>@foreach([0,1] as $index) @php($item=$pair[$index] ?? ['', ''])<td>{{ $item[0] }}</td><td>{{ filled($item[1]) ? $item[1] : '—' }}</td>@endforeach</tr>@endforeach</tbody></table>
@foreach($document['sections'] as $section)<section class="section"><h2>{{ $section['title'] }}</h2>
@if($section['kind']==='narrative')<p>{{ $section['text'] }}</p>
@elseif($section['kind']==='details')<table class="details"><tbody>@foreach($section['rows'] as $row)<tr><td>{{ $row[0] }}</td><td colspan="3">{{ $row[1] }}</td></tr>@endforeach</tbody></table>
@else<table><thead><tr>@foreach($section['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>@forelse($section['rows'] as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($section['columns']) }}">No saved entries.</td></tr>@endforelse</tbody></table>@endif</section>@endforeach
@include('reports.official._photo-sections', ['photoSections' => $document['photo_sections']])
<p class="footer-note">Generated from saved BRBI data · {{ $document['generated_display_at'] }}</p>
@endif
</main>
</body></html>
