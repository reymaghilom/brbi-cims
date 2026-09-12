@props(['url', 'title', 'label' => null, 'badge' => null, 'badgeClass' => 'bg-surface-muted text-text-muted', 'icon' => null, 'method' => 'GET', 'fields' => [], 'newTab' => false])
{{-- One actionable row in a Dashboard KPI detail modal: the whole row is the control, with the same
     hover / focus / chevron treatment as the Needs Attention rows. Supporting lines go in the slot.

     `method`/`fields`/`newTab` are additive and default to a plain same-tab GET link, so every
     existing use of this component renders exactly as before — the Client Folder rows in the other
     KPI modals are ordinary in-page navigation and stay that way.

     `newTab` is for report WEB OUTPUT rows: a preview opens beside the Dashboard rather than
     navigating it away, so the modal and the page behind it are still there when the report tab is
     closed. It stays a real anchor wherever the action is a GET, which keeps Ctrl/Cmd-click,
     middle-click, the browser context menu and keyboard activation all working as the user expects.

     A POST row exists for one reason: the two photo checks reach their web output through the
     shared batch-preview endpoint, which is a POST — the very same endpoint and fields the Global
     Reports Preview action already posts. That one is always new-tab, because a same-tab POST would
     navigate the Dashboard away and Back would re-post it.

     Either way this is navigation only: nothing is generated, downloaded or written. --}}
@php
    $isPost = strtoupper((string) $method) === 'POST';
    // A POST web output has to open beside the page; a GET one does so only when asked.
    $opensNewTab = $newTab || $isPost;
    // rel guards the opener in both directions: noopener keeps window.opener null so the report
    // tab can never reach back into the Dashboard, noreferrer withholds the Referer as well.
    $rowClass = 'group flex w-full cursor-pointer items-start gap-3 px-3.5 py-3 text-left transition duration-150 hover:bg-brand-soft/40 focus-visible:bg-brand-soft/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary/40';
    // Say so in the accessible name, since a new tab is a change of context.
    $rowLabel = $label === null ? null : $label.($opensNewTab ? ' (opens in a new tab)' : '');
    // Emitted as one static string rather than an inline @if, which would pad the tag with stray
    // whitespace between the attributes.
    $newTabAttributes = $opensNewTab ? 'target="_blank" rel="noopener noreferrer"' : '';
@endphp
<li {{ $attributes }}>
    @if($isPost)
        <form method="POST" action="{{ $url }}" target="_blank" rel="noopener noreferrer">
            @csrf
            @foreach($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
    @endif
    @if($isPost)
        <button type="submit" class="{{ $rowClass }}" @if($rowLabel) aria-label="{{ $rowLabel }}" @endif>
    @else
        <a href="{{ $url }}" {!! $newTabAttributes !!} class="{{ $rowClass }}" @if($rowLabel) aria-label="{{ $rowLabel }}" @endif>
    @endif
        @if($icon)<x-ui.icon :name="$icon" size="size-4" class="mt-0.5 shrink-0 text-text-muted" />@endif
        <div class="min-w-0 flex-1">
            <div class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                <p class="min-w-0 break-words text-sm font-semibold leading-5 text-text-main group-hover:text-brand-primary">{{ $title }}</p>
                @if($badge)<span class="w-fit shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $badgeClass }}" data-detail-badge>{{ $badge }}</span>@endif
            </div>
            {{ $slot }}
        </div>
        <x-ui.icon name="chevron-right" size="size-4" class="mt-0.5 shrink-0 text-text-subtle transition group-hover:translate-x-0.5 group-hover:text-brand-primary" />
    @if($isPost)</button>@else</a>@endif
    @if($isPost)
        </form>
    @endif
</li>
