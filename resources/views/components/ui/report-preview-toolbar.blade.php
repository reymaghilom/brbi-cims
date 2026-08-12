@props(['previewUrl' => null, 'pdfUrl' => null, 'wordUrl' => null])

<div {{ $attributes->class('flex flex-wrap items-center gap-2 rounded-card border border-ui-border bg-surface p-3 shadow-card') }} aria-label="Report preview actions">
    @if($previewUrl)<a href="{{ $previewUrl }}" class="ui-button-secondary">Preview</a>@endif
    @if($pdfUrl)<a href="{{ $pdfUrl }}" class="ui-button-secondary">PDF</a>@endif
    @if($wordUrl)<a href="{{ $wordUrl }}" class="ui-button-secondary">Word</a>@endif
    <button type="button" class="ui-button-primary" onclick="window.print()">Print</button>
    @isset($actions){{ $actions }}@endisset
</div>
