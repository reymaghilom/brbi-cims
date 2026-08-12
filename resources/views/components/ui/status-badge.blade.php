@props(['status'])
@php
    $value = $status instanceof BackedEnum ? $status->value : (string) $status;
    $completed = in_array($value, ['completed', 'complete', 'active', 'success'], true);
    $danger = in_array($value, ['disabled', 'failed', 'error', 'deleted'], true);
    $label = str($value)->replace('_', ' ')->title();
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold', 'bg-success-soft text-success' => $completed, 'bg-danger-soft text-danger' => $danger, 'bg-progress-soft text-progress' => ! $completed && ! $danger]) }}>
    <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ $label }}
</span>
