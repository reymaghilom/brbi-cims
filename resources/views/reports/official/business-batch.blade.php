<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title>
@unless($pdfMode) @include('partials.favicon') @endunless
<style>
* { box-sizing: border-box; } body { margin: 0; color: #000; font-family: Arial, Helvetica, DejaVu Sans, sans-serif; }
{{-- Separation between combined reports is a plain margin, never a forced page-break — this is
     what lets two short reports share one printed page while a longer one still flows cleanly
     onto the next, instead of every report starting its own page regardless of length. Matches
     document.blade.php's own ".section { margin-top }" convention (a small gap before the very
     first item too, rather than relying on a sibling selector Dompdf support isn't verified for
     in this codebase). --}}
.business-batch-item:first-child { margin-top: .3in; }
.business-batch-item:not(:first-child) { margin-top: 0; }
@unless($pdfMode) @media screen { body { background: #e5e7eb; } .preview-toolbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; align-items:center; justify-content: space-between; gap: 12px; padding: 10px 18px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); font-family:Arial,Helvetica,system-ui,sans-serif; font-size: 10pt; } .preview-brand { display:flex; min-width:0; align-items:center; color:#1e3a8a; } .preview-brand img { display:block; width:180px; max-width:42vw; height:38px; object-fit:contain; object-position:left center; } .preview-toolbar a,.preview-toolbar button { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 14px; background: #fff; color: #1e3a8a; font-weight: 600; text-decoration: none; cursor: pointer; } .preview-toolbar button { background: #1e3a8a; color:#fff; } .preview-toolbar button:hover { background:#172f70; } .business-batch-page { width: 8.5in; margin: .3in auto; padding: .28in; background:#fff; box-shadow:0 10px 30px rgba(15,23,42,.18); } } @endunless
{{-- Print/PDF spacing comes entirely from business-styles.blade.php's own @page{margin:.28in}
     rule below (the same convention document.blade.php's .report-sheet already uses) — zeroing
     the container's own padding here avoids stacking a second inset on top of the page margin. --}}
@media print { .preview-toolbar { display:none; } .business-batch-page { margin:0; padding:0; } }
</style>
@include('reports.official.business-styles')
<style>
{{-- Must come AFTER the business-styles include above: business-form-table's own !important
     top margin is declared there, and with equal (single-class) specificity plus !important on
     both sides, CSS falls back to source order — a rule declared earlier in the document loses
     to one declared later regardless of !important, so this has to be the later one to actually
     win. Every business template's own first table (business-form-table) carries that margin,
     which a compound child-combinator selector from business-batch-item could try to reach
     instead, but support for that isn't verified in Dompdf's own, less complete CSS engine —
     so business.blade.php and every business-report-*.blade.php partial instead add the
     "business-batch-continuation-first" class directly onto their own first table whenever
     $showCommonHeader is false, letting this stay a plain class + :first-child pattern, already
     proven elsewhere in this codebase (business-styles.blade.php's :not(:first-child) and
     cibi-styles.blade.php's tr:first-child). Confirmed by screenshotting the actual rendered
     seam at gap = 0px only once this block was moved after the include. --}}
.business-batch-continuation-first { margin-top: 0 !important; }
.business-batch-continuation-first tr:first-child th,
.business-batch-continuation-first tr:first-child td { border-top: none; }
</style>
</head><body>
@unless($pdfMode)<nav class="preview-toolbar" aria-label="Report preview actions"><div class="preview-brand"><img src="{{ asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></div><div><a href="{{ route('client-folders.income-sources.manage', [$clientFolder] + $personParams) }}">Back to Business / Income Sources</a> <button type="button" onclick="window.print()">Print</button></div></nav>@endunless
<div class="business-batch-page">
{{-- One continuous outer report border for the whole selected batch — not one per business —
     so the second (and later) business continues directly inside the same bordered sheet
     instead of starting a new report box, matching the shared header now appearing only once
     above the first business. --}}
<article class="business-official-sheet" aria-label="Official Business Report">
@foreach($documents as $businessDocument)
    <div class="business-batch-item">
        @include('reports.official.business', ['document' => $businessDocument, 'pdfMode' => $pdfMode, 'showCommonHeader' => $loop->first])
    </div>
@endforeach
</article>
</div>
</body></html>
