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
.photo-page:first-child { page-break-before: avoid; } .photo-page { page-break-before: always; height: 12.1in; } .photo { margin-top: .08in; page-break-inside: avoid; } .photo-frame { height: 4.55in; border: 1px solid #333; text-align: center; overflow: hidden; background: #fafafa; } .photo-frame img { max-width: 100%; max-height: 100%; } .placeholder { padding-top: 2in; color: #555; }
@unless($pdfMode) @media screen { body { background: #e5e7eb; } .preview-toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items:center; justify-content: space-between; gap: 12px; padding: 10px 18px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); font-family:Arial,Helvetica,system-ui,sans-serif; font-size: 10pt; } .preview-brand { display:flex; min-width:0; align-items:center; color:#1e3a8a; } .preview-brand img { display:block; width:180px; max-width:42vw; height:38px; object-fit:contain; object-position:left center; } .preview-toolbar a,.preview-toolbar button { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; background: #fff; color: #1e3a8a; font-weight: 600; text-decoration: none; cursor: pointer; } .preview-toolbar button { background: #1e3a8a; color:#fff; } .preview-toolbar button:hover { background:#172f70; } .report-sheet { width: 8.5in; min-height: 13in; margin: .3in auto; padding: .45in; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); } } @endunless
@media print { .preview-toolbar { display:none; } .report-sheet { margin:0; padding:0; box-shadow:none; } }
</style></head><body>
@unless($pdfMode)<nav class="preview-toolbar" aria-label="Report preview actions"><div class="preview-brand"><img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></div><div><a href="{{ route('client-folders.residence-business.edit', [$clientFolder] + $personParams) }}">Back to Residence &amp; Business Report</a> <button type="button" onclick="window.print()">Print</button></div></nav>@endunless
<main class="report-sheet">
@include('reports.official._photo-sections', ['photoSections' => $photoSections])
</main>
</body></html>
