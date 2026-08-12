@props(['notes' => []])

<ol {{ $attributes->class('space-y-0') }}>
    @forelse($notes as $note)
        <li class="relative ml-3 border-l border-ui-border pb-6 pl-6 last:border-transparent last:pb-0">
            <span class="absolute -left-1.5 top-1.5 size-3 rounded-full border-2 border-surface bg-brand-primary" aria-hidden="true"></span>
            <div class="flex flex-wrap items-baseline justify-between gap-2"><p class="font-semibold">{{ $note['author'] }}</p><time class="text-xs text-text-subtle">{{ $note['date'] }}</time></div>
            <p class="mt-1.5 text-sm leading-6 text-text-muted">{{ $note['text'] }}</p>
        </li>
    @empty
        <li class="text-sm text-text-muted">No notes recorded.</li>
    @endforelse
</ol>
