@props(['name', 'size' => 'size-5'])

<svg {{ $attributes->class([$size, 'shrink-0'])->merge(['viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.8', 'aria-hidden' => 'true']) }}>
    @switch($name)
        @case('dashboard') <path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z"/> @break
        @case('folder') <path d="M3.5 6.5h6l2 2H20.5v9.75a1.75 1.75 0 0 1-1.75 1.75H5.25a1.75 1.75 0 0 1-1.75-1.75V6.5Z"/><path d="M3.5 9h17"/> @break
        @case('activity') <path d="M4 5h16M4 12h16M4 19h16"/><path d="m6 5 1 1 2-2m-3 8 1 1 2-2m-3 8 1 1 2-2"/> @break
        @case('report') <path d="M6 3.5h8l4 4v13H6v-17Z"/><path d="M14 3.5v4h4M9 12h6M9 16h6"/> @break
        @case('media') <rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m5.5 17 4.5-4 3 2.5 2.5-2 3 3"/> @break
        @case('video') <rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/> @break
        @case('telegram') <path d="m3.5 11 16.8-6.5-3.1 15-5.1-4-2.8 2.6.5-4.6L17 7.2 8.4 12"/> @break
        @case('drive') <path d="m9 3-5.5 9.5L7 19h10l3.5-6.5L15 3H9Z"/><path d="m9 3 5.5 9.5M3.5 12.5h11M7 19l3.5-6.5"/> @break
        @case('trash') <path d="M4.5 7h15M9 3.5h6L16 7H8l1-3.5ZM7 7l1 13h8l1-13M10 10v7M14 10v7"/> @break
        @case('settings') <circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l-2-.7-.6-1.4.9-1.9-2.1-2.1-1.9.9-1.4-.6-.7-2h-3l-.7 2-1.4.6-1.9-.9-2.1 2.1.9 1.9-.6 1.4-2 .7v3l2 .7.6 1.4-.9 1.9 2.1 2.1 1.9-.9 1.4.6.7 2h3l.7-2 1.4-.6 1.9.9 2.1-2.1-.9-1.9.6-1.4 2-.7Z"/> @break
        @case('users') <circle cx="9" cy="8" r="3"/><path d="M3.5 20v-2.2c0-3 2.4-5.3 5.5-5.3s5.5 2.3 5.5 5.3V20M15 5.5a3 3 0 0 1 0 5.8M16.5 13c2.3.6 4 2.4 4 4.8V20"/> @break
        @case('audit') <path d="M5 3.5h14v17H5v-17Z"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4"/> @break
        @case('menu') <path d="M4 7h16M4 12h16M4 17h16"/> @break
        @case('close') <path d="m6 6 12 12M18 6 6 18"/> @break
        @case('chevron-right') <path d="m9 5 7 7-7 7"/> @break
        @case('chevron-down') <path d="m5 9 7 7 7-7"/> @break
        @case('logout') <path d="M10 4H5v16h5M14 8l4 4-4 4m4-4H9"/> @break
        @case('check') <path d="m5 12 4 4L19 6"/> @break
        @case('warning') <path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5m0 3h.01"/> @break
        @case('more') <circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1" fill="currentColor" stroke="none"/> @break
        @case('search') <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/> @break
        @case('open') <path d="M4 7.5h6l2 2h8v9H4v-11Z"/><path d="m13 14 2-2 2 2m-2-2v5"/> @break
        @case('edit') <path d="m4 20 4.2-1 10.4-10.4a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/><path d="m13.8 7.4 3 3"/> @break
        @case('attachment') <path d="m8.5 12.5 6.7-6.7a3.2 3.2 0 0 1 4.5 4.5l-9.2 9.2a5 5 0 0 1-7.1-7.1l8.8-8.8"/><path d="m7 15 8.6-8.6"/> @break
        @case('info') <circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/> @break
        @case('user') <circle cx="12" cy="8" r="3.5"/><path d="M5 21a7 7 0 0 1 14 0"/> @break
        @case('calendar') <rect x="3.5" y="5.5" width="17" height="15" rx="2"/><path d="M7.5 3.5v4M16.5 3.5v4M3.5 9.5h17"/> @break
        @case('clock') <circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/> @break
        @case('chart') <path d="M5 20V10M10 20V4M15 20v-7M20 20V7"/> @break
        @default <circle cx="12" cy="12" r="8"/> @break
    @endswitch
</svg>
