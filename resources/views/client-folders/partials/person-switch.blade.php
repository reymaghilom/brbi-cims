@php
    $personSwitchUrl = fn (?App\Models\CoMaker $coMaker = null) => $coMaker
        ? route('client-folders.show', $clientFolder).'?person=co-maker&co_maker_id='.$coMaker->id
        : route('client-folders.show', $clientFolder);
    $viewingLabel = $activeCoMaker ? 'Co-Maker — '.mb_strtoupper($activeCoMaker->full_name) : 'Applicant';
@endphp
@if($coMakers->isNotEmpty())
<section class="ui-panel p-3.5 sm:p-4" aria-labelledby="person-switch-title">
    <h2 id="person-switch-title" class="sr-only">Switch active person</h2>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 flex-nowrap items-center gap-2 overflow-x-auto">
                <a
                    href="{{ $personSwitchUrl() }}"
                    class="flex shrink-0 cursor-pointer items-center gap-2.5 rounded-control px-3 py-2 transition {{ $activeCoMaker ? 'text-text-muted hover:bg-surface-muted hover:text-brand-sidebar' : 'bg-brand-soft text-brand-primary' }}"
                >
                    <span class="grid size-8 shrink-0 place-items-center rounded-full {{ $activeCoMaker ? 'bg-surface-muted text-text-muted' : 'bg-white text-brand-primary' }}"><x-ui.icon name="user" size="size-4" /></span>
                    <span class="text-left">
                        <span class="block text-xs font-semibold uppercase tracking-wide {{ $activeCoMaker ? 'text-text-muted' : 'text-brand-primary' }}">Applicant @unless($activeCoMaker)(<span class="text-success">Active</span>)@endunless</span>
                        <span class="block text-sm font-bold text-text-main">{{ $clientFolder->display_name }}</span>
                    </span>
                </a>
                @foreach($coMakers as $coMaker)
                    @php($coMakerIsActive = $activeCoMaker?->id === $coMaker->id)
                    <div class="flex shrink-0 items-center rounded-control transition {{ $coMakerIsActive ? 'bg-brand-soft' : 'hover:bg-surface-muted' }}">
                        <a
                            href="{{ $personSwitchUrl($coMaker) }}"
                            data-co-maker-tab="{{ $coMaker->id }}"
                            class="flex cursor-pointer items-center gap-2.5 px-3 py-2 {{ $coMakerIsActive ? 'text-brand-primary' : 'text-text-muted hover:text-brand-sidebar' }}"
                        >
                            <span class="grid size-8 shrink-0 place-items-center rounded-full {{ $coMakerIsActive ? 'bg-white text-brand-primary' : 'bg-folder-soft text-progress' }}"><x-ui.icon name="user" size="size-4" /></span>
                            <span class="text-left">
                                <span class="block text-xs font-semibold uppercase tracking-wide {{ $coMakerIsActive ? 'text-brand-primary' : 'text-text-muted' }}">Co-Maker{{ $coMakers->count() > 1 ? ' '.$loop->iteration : '' }} @if($coMakerIsActive)(<span class="text-success">Active</span>)@endif</span>
                                <span class="block text-sm font-bold uppercase text-text-main" data-co-maker-tab-name>{{ $coMaker->full_name }}</span>
                            </span>
                        </a>
                        @if($canManageCoMakers)
                            <button
                                type="button"
                                class="ui-dots-trigger mr-1"
                                aria-haspopup="menu"
                                aria-expanded="false"
                                aria-label="Co-Maker{{ $coMakers->count() > 1 ? ' '.$loop->iteration : '' }} actions"
                                data-co-maker-menu-trigger
                                data-co-maker-id="{{ $coMaker->id }}"
                                data-co-maker-full-name="{{ $coMaker->full_name }}"
                                data-co-maker-first-name="{{ $coMaker->first_name }}"
                                data-co-maker-middle-name="{{ $coMaker->middle_name }}"
                                data-co-maker-last-name="{{ $coMaker->last_name }}"
                                data-co-maker-suffix="{{ $coMaker->suffix }}"
                                data-co-maker-address="{{ $coMaker->address }}"
                                data-co-maker-destroy-base-url="{{ route('client-folders.co-maker.store', $clientFolder) }}"
                            ><x-ui.icon name="more" size="size-4 rotate-90" /></button>
                        @endif
                    </div>
                @endforeach
            </div>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-3 lg:ml-auto">
                <span class="text-xs font-medium text-text-muted">Current View: <span class="rounded-full bg-brand-soft px-2 py-1 font-bold text-brand-primary">{{ $viewingLabel }}</span></span>
                <x-ui.context-menu label="Switch Person">
                    <x-slot:trigger>
                        <span class="ui-button-secondary-compact"><x-ui.icon name="users" size="size-3.5" />Switch Person<x-ui.icon name="chevron-down" size="size-3.5" /></span>
                    </x-slot:trigger>
                    <a href="{{ $personSwitchUrl() }}" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary">Applicant — {{ $clientFolder->display_name }}</a>
                    @foreach($coMakers as $coMaker)
                        <a href="{{ $personSwitchUrl($coMaker) }}" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary">Co-Maker{{ $coMakers->count() > 1 ? ' '.$loop->iteration : '' }} — {{ $coMaker->full_name }}</a>
                    @endforeach
                </x-ui.context-menu>
        </div>
    </div>
</section>
@endif
