@props(['tabs' => [], 'active' => null])
@php($activeId = $active ?? array_key_first($tabs))

<div data-tabs {{ $attributes }}>
    <div role="tablist" aria-label="Section tabs" class="flex gap-1 overflow-x-auto border-b border-ui-border">
        @foreach($tabs as $id => $label)
            <button type="button" role="tab" id="tab-{{ $id }}" aria-controls="panel-{{ $id }}" aria-selected="{{ $id === $activeId ? 'true' : 'false' }}" tabindex="{{ $id === $activeId ? '0' : '-1' }}" class="min-h-11 shrink-0 border-b-2 px-4 text-sm font-semibold transition aria-selected:border-brand-primary aria-selected:text-brand-primary aria-[selected=false]:border-transparent aria-[selected=false]:text-text-muted">{{ $label }}</button>
        @endforeach
    </div>
    <div class="pt-5">{{ $slot }}</div>
</div>
