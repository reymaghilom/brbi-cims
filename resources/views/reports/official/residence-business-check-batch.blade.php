<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
@unless($pdfMode) @include('partials.favicon') @endunless
<style>
@page { size: 8.5in 13in; margin: .45in; }
* { box-sizing: border-box; } body { margin: 0; color: #000; font-family: Arial, Helvetica, DejaVu Sans, sans-serif; font-size: 8.5pt; line-height: 1.22; }
.report-title { margin: 0; text-align: center; font-size: 14pt; } .report-subtitle { margin: .04in 0 .12in; text-align: center; font-weight: bold; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; } th, td { border: .7pt solid #000; padding: .045in .055in; vertical-align: top; overflow-wrap: anywhere; }
.details td:nth-child(odd) { width: 15%; font-weight: bold; background: #f3f3f3; } .details td:nth-child(even) { width: 35%; }
.section { margin-top: .11in; } p { margin: 0; white-space: pre-wrap; }
.photo-page:first-child { page-break-before: avoid; } .photo-page { page-break-before: always; } .photo { margin-top: .08in; page-break-inside: avoid; } .photo-frame { height: 4.55in; border: 1px solid #333; text-align: center; overflow: hidden; background: #fafafa; } .photo-frame img { max-width: 100%; max-height: 100%; } .placeholder { padding-top: 2in; color: #555; }
.photo-frame-plain { border: none; background: none; box-shadow: none; }
.residence-header td { border: none; vertical-align: top; padding: 0; } .residence-header-left { width: 66%; padding-right: .15in; } .residence-header-right { width: 34%; } .residence-header p { margin: 0 0 .05in; }
.business-check-page { padding: .55in; font-family: Calibri, Arial, Helvetica, DejaVu Sans, sans-serif; font-size: 12pt; line-height: 1; }
.business-check-page .residence-header strong, .business-check-page .business-group-caption strong { font-weight: 400; }
.business-check-page .business-group-caption { margin-top: 0; }
.business-check-page .residence-header + .business-group-caption { margin-top: .18in; }
.business-check-page .photo { margin: .04in 0 0; }
{{-- No fixed box height here (unlike .photo-frame above) — a forced height that doesn't match the
     actual scaled image leaves Dompdf's page-break-inside:avoid calculating against reserved-but-
     unused space, which is what let the heading and screenshot land on different pages even though
     the real content was short enough to fit together. Constraining the <img> itself gives Dompdf
     the image's true rendered height to lay out against. --}}
.map-photo-frame { text-align: center; overflow: hidden; } .map-photo-frame img { max-width: 100%; max-height: 10.6in; }
.google-map-page { page-break-inside: avoid; }
@unless($pdfMode) @media screen { body { background: #e5e7eb; } .preview-toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items:center; justify-content: space-between; gap: 12px; padding: 10px 18px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); font-family:Arial,Helvetica,system-ui,sans-serif; font-size: 10pt; } .preview-brand { display:flex; min-width:0; align-items:center; color:#1e3a8a; } .preview-brand img { display:block; width:180px; max-width:42vw; height:38px; object-fit:contain; object-position:left center; } .preview-toolbar a,.preview-toolbar button { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; background: #fff; color: #1e3a8a; font-weight: 600; text-decoration: none; cursor: pointer; } .preview-toolbar button { background: #1e3a8a; color:#fff; } .preview-toolbar button:hover { background:#172f70; } .report-sheet { width: 8.5in; min-height: 13in; margin: .3in auto; padding: .45in; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); }
    {{-- The date/time, page title, and URL a CI sometimes sees on a printed page are Chrome's own
         "Headers and footers" print option, not anything this app renders — there is no server- or
         client-side way to suppress a browser's own print header/footer from the page being
         printed. This tip is web-preview-only chrome (hidden by the same @media print rule as the
         toolbar right below), never part of the printed report itself. --}}
    .print-help-tip { max-width: 8.5in; margin: 10px auto 0; padding: 0 18px; font-family: Arial, Helvetica, system-ui, sans-serif; font-size: 8.5pt; color: #6b7280; text-align: center; }
} @endunless
@media print { .preview-toolbar, .print-help-tip { display:none; } .report-sheet { margin:0; padding:0; box-shadow:none; } }
@unless($pdfMode)
{{-- Web Preview only (Dompdf renders with defaultMediaType 'print', so this never reaches the
     PDF): each .official-report-page (every residence/business check's own photo/map page) becomes
     its own separate 8.5x13 white sheet with a small "Page N" badge instead of one continuous
     scroll, so a CI can immediately see where each printed page starts. This whole template is
     already exclusively Residence/Business Check content, so no type guard is needed here. --}}
@media screen {
    .report-sheet { width: auto; min-height: 0; margin: 0; padding: 0; background: transparent; box-shadow: none; counter-reset: brbi-page; }
    .official-report-page { width: 8.5in; min-height: 13in; margin: 0 auto 28px; padding: .45in; background: #fff; box-shadow: 0 10px 30px rgba(15,23,42,.18); box-sizing: border-box; position: relative; counter-increment: brbi-page; }
    .official-report-page.business-check-page { padding: 1in; }
    .official-report-page::after { content: "Page " counter(brbi-page); position: absolute; right: .3in; bottom: .22in; font-size: 7.5pt; font-weight: 700; color: #6b7280; background: #f3f4f6; padding: 2px 8px; border-radius: 10px; }
}
@endunless
</style></head><body>
@unless($pdfMode)<nav class="preview-toolbar" aria-label="Report preview actions"><div class="preview-brand"><img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></div><div><a href="{{ route('client-folders.residence-business.edit', [$clientFolder] + $personParams) }}">Back to Residence &amp; Business Report</a> <button type="button" onclick="window.print()">Print</button></div></nav>
<p class="print-help-tip">For a clean printout, turn off "Headers and footers" in the browser print settings.</p>@endunless
<main class="report-sheet">
@include('reports.official._photo-sections', ['photoSections' => $photoSections])
</main>
</body></html>
