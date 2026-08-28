@foreach($photoSections as $photoSection)
    @php($isResidence = ($photoSection['category'] ?? null) === 'Residence')
    @if($isResidence)
        @php($chunks = array_chunk($photoSection['media'], 2) ?: [[]])
        @foreach($chunks as $pageIndex => $items)
            <section class="photo-page official-report-page photo-report-page">
                {{-- "Subject: Residence Check" inside the info block below already identifies this
                     page — no separate "Residence Check" / "Residence DOCUMENTATION" heading here. --}}
                @if($pageIndex > 0)<p class="report-subtitle">Continuation {{ $pageIndex+1 }}</p>@endif
                <table class="residence-header"><tr>
                    <td class="residence-header-left">
                        <p><strong>{{ $photoSection['party_label'] }}:</strong> {{ $photoSection['subject'] }}</p>
                        <p><strong>Location:</strong> {{ $photoSection['location'] ?: '—' }}</p>
                        <p><strong>Subject:</strong> {{ $photoSection['heading'] }}</p>
                        @if(filled($photoSection['remarks']))<p><strong>Remarks:</strong> {{ $photoSection['remarks'] }}</p>@endif
                    </td>
                    <td class="residence-header-right">
                        <p><strong>Date:</strong> {{ $photoSection['ci_date'] }}</p>
                        <p><strong>CI:</strong> {{ $photoSection['ci'] ?: '—' }}</p>
                    </td>
                </tr></table>
                @forelse($items as $item)
                    @php($src = $pdfMode ? $item['image_path'] : ($item['web_url'] ?? $item['image_path']))
                    <figure class="photo">
                        @if($item['caption'])<figcaption><strong>{{ $item['caption'] }}</strong></figcaption>@endif
                        <div class="photo-frame photo-frame-plain">@if($src && $item['media_type']==='photo')<img src="{{ $src }}">@else<div class="placeholder">Image unavailable.</div>@endif</div>
                    </figure>
                @empty
                    <div class="photo-frame section"><div class="placeholder">No media is linked to this section.</div></div>
                @endforelse
            </section>
        @endforeach
        @if(!empty($photoSection['google_map']))
            @include('reports.official._google-map-section')
        @endif
    @else
        {{-- Business Check: Business Photos (default/first group, then every additional Photo
             Group in saved order) pre-chunked to a strict maximum of 2 photos per page by
             OfficialReportDataBuilder::paginateBusinessPhotos() — the same page list DOCX renders,
             so Web/PDF/DOCX can never disagree on where a page boundary falls. Each page is its own
             .photo-page section (forces a fresh page); only the first repeats the compact header
             (see business1.docx/business1.pdf, the visual reference for this layout: no large title,
             no "DOCUMENTATION" subtitle — same compact 2-column .residence-header table Residence
             Check's own header already uses, just fed Business values). A group's own caption (if
             any) rides on that group's own first page only. Competitors comes next (below), then
             Google Map — this report's own final section — last of all; never mixed with Business
             Photos. --}}
        @php($businessPages = $photoSection['photo_pages'] ?? [])
        @php($hasAnyBusinessMedia = $businessPages !== [] || !empty($photoSection['competitor_photo_pages']))
        @forelse($businessPages as $pageIndex => $page)
            <section class="photo-page official-report-page photo-report-page business-check-page">
                @if($pageIndex === 0)
                    @include('reports.official._business-check-header')
                @endif
                @if(filled($page['caption']))<p class="section business-group-caption"><strong>{{ $page['caption'] }}</strong></p>@endif
                @foreach($page['photos'] as $item)
                    @php($src = $pdfMode ? $item['image_path'] : ($item['web_url'] ?? $item['image_path']))
                    <figure class="photo">
                        {{-- Business Check's own compact header leaves noticeably more page space
                             than Residence Check's does, so its photos get their own taller frame
                             (photo-frame-tall) to actually fill it — matching business1.docx/
                             business1.pdf's large, near-full-page photos — without touching
                             Residence Check's own unrelated .photo-frame sizing above. --}}
                        <div class="photo-frame photo-frame-plain photo-frame-tall">@if($src && $item['media_type']==='photo')<img src="{{ $src }}">@else<div class="placeholder">Image unavailable.</div>@endif</div>
                    </figure>
                @endforeach
            </section>
        @empty
            <section class="photo-page official-report-page photo-report-page business-check-page">
                @include('reports.official._business-check-header')
                @unless($hasAnyBusinessMedia)
                    <div class="photo-frame section"><div class="placeholder">No media is linked to this section.</div></div>
                @endunless
            </section>
        @endforelse
        {{-- Competitors: the Business Check report's own second-to-last section, always after
             Business Photos and before Google Map (which must be this report's final section) —
             never mixed with Business Photos — same pre-chunked (<=2 photos each) page list as
             above, each on its own fresh page. --}}
        @php($competitorPages = $photoSection['competitor_photo_pages'] ?? [])
        @foreach($competitorPages as $pageIndex => $page)
            <section class="photo-page official-report-page photo-report-page business-check-page">
                @if(filled($page['caption']))<p class="section business-group-caption"><strong>{{ $page['caption'] }}</strong></p>@endif
                @foreach($page['photos'] as $item)
                    @php($src = $pdfMode ? $item['image_path'] : ($item['web_url'] ?? $item['image_path']))
                    <figure class="photo">
                        <div class="photo-frame photo-frame-plain photo-frame-tall">@if($src && $item['media_type']==='photo')<img src="{{ $src }}">@else<div class="placeholder">Image unavailable.</div>@endif</div>
                    </figure>
                @endforeach
                @if($pageIndex === array_key_last($competitorPages) && !empty($photoSection['google_map']))
                    @include('reports.official._google-map-section', ['flowWithPrevious' => true, 'businessContext' => true])
                @endif
            </section>
        @endforeach
        @if($competitorPages === [] && !empty($photoSection['google_map']))
            @include('reports.official._google-map-section', ['businessContext' => true])
        @endif
    @endif
@endforeach
