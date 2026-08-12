@props(['type' => 'success', 'message'])

<div data-toast role="status" aria-live="polite" {{ $attributes->class(['flex items-start gap-3 rounded-card border p-4 shadow-card', 'border-success/25 bg-success-soft text-success' => $type === 'success', 'border-danger/25 bg-danger-soft text-danger' => $type === 'error', 'border-progress/25 bg-progress-soft text-progress' => $type === 'warning']) }}>
    <x-ui.icon :name="$type === 'success' ? 'check' : 'warning'" />
    <p class="min-w-0 flex-1 text-sm font-semibold">{{ $message }}</p>
    <button type="button" data-toast-close class="-m-2 rounded p-2" aria-label="Dismiss message"><x-ui.icon name="close" size="size-4" /></button>
</div>
