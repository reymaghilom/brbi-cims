@props(['value' => 0, 'label' => 'Completion progress'])
@php($progress = max(0, min(100, (float) $value)))

<div {{ $attributes }}>
    <div class="mb-2 flex items-center justify-between gap-3 text-sm"><span class="font-semibold text-text-main">{{ $label }}</span><span class="tabular-nums text-text-muted">{{ number_format($progress) }}%</span></div>
    <div class="h-2.5 overflow-hidden rounded-full bg-ui-border" role="progressbar" aria-label="{{ $label }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress }}">
        <div class="h-full rounded-full bg-progress transition-[width]" style="width: {{ $progress }}%"></div>
    </div>
</div>
