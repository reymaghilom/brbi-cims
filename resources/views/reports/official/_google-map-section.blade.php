{{-- Google Map / Map Screenshot — shared by Residence and Business Check alike, but positioned
     differently by each caller: Residence includes this right after its own Residence Picture
     pages (unchanged); Business Check includes this AFTER Competitors, making it that report's own
     final section (never before Competitors, never mixed with Business Photos or Competitor
     Photos). Smart placement: no forced page-break-before here (unlike .photo-page elsewhere) —
     this section is left to flow naturally after whatever precedes it, using whatever page space
     remains. `page-break-inside: avoid` (see .google-map-page in document.blade.php) is what
     actually decides pagination: if the heading+screenshot fit in the remaining space, they render
     there; if not, the browser/Dompdf's own pagination pushes the whole block — heading and
     screenshot together, never split — onto a fresh page. Borderless (photo-frame-plain) for both
     categories — a bordered/shadowed card here would be the one remaining spot that didn't match
     the clean, borderless document style every other Business Check photo already uses. --}}
<section class="official-report-page photo-report-page google-map-page">
    <h1 class="report-title">Google Map</h1>
    @php($mapSrc = $pdfMode ? $photoSection['google_map']['image_path'] : $photoSection['google_map']['web_url'])
    <div class="photo"><div class="photo-frame map-photo-frame photo-frame-plain">
        @if($mapSrc)
            <img src="{{ $mapSrc }}">
        @else
            <div class="placeholder">Map image unavailable.</div>
        @endif
    </div></div>
</section>
