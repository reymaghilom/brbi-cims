@extends('layouts.app')

@section('title', 'Photos & Videos')

@section('content')
    @php($personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null))
    <x-ui.breadcrumb :items="[['label' => 'Client Folders', 'url' => route('client-folders.index')], ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)], ['label' => 'Photos & Videos']]" />

    <div class="mt-4">
        <h1 class="ui-page-title">Photos &amp; Videos</h1>
        <p class="mt-1 text-sm leading-6 text-text-muted">Manage Residence evidence or a separate documentation package for each business.</p>
    </div>

    {{-- Display name for the exact active person. Resolving it here does not weaken the
         Applicant (co_maker_id NULL) / Co-Maker (exact id) isolation the backend enforces —
         the person context itself is intentionally not shown as its own field. --}}
    @php($clientName = $activePerson?->full_name ?? $clientFolder->display_name)

    {{-- Which documentation workflow the tabs are showing. A failed save wins (so the encoder
         lands back on the tab holding their input), then an explicit ?tab, then whichever set
         the URL points at. Residence is the default. Each category keeps its own saved records
         and its own query key either way — the tabs only choose what is on screen. --}}
    @php($requestedTab = old('documentation_form') ?: request()->query('tab'))
    @php($activeTab = in_array($requestedTab, ['residence', 'business'], true)
        ? $requestedTab
        : (request()->filled('business_documentation') ? 'business' : 'residence'))

    {{-- Flash-prevention: read the support panel's persisted hide/show choice before first paint
         so the CSS below can suppress the wrong initial state — the same technique the Recent
         Activity panel uses on the Business / Income Sources page. --}}
    <script>
        (function () {
            try {
                if (localStorage.getItem('brbi-media-support-collapsed') === 'true') {
                    document.documentElement.setAttribute('data-media-support-collapsed', '');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        html[data-media-support-collapsed] [data-media-support-layout] { gap: 0; }
        html[data-media-support-collapsed] [data-media-support-shell] { display: none; }
        html[data-media-support-collapsed] [data-media-support-show][hidden] { display: inline-flex !important; }
        html[data-media-support-collapsed] [data-media-support-hide] { display: none; }

        [data-media-support-layout] {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            align-items: start;
            gap: 1.25rem;
            transition: gap 200ms ease-in-out;
        }

        [data-media-support-shell] {
            display: grid;
            min-width: 0;
            grid-template-rows: minmax(0, 1fr);
            transition: grid-template-rows 200ms ease-in-out;
        }

        [data-media-support-panel] {
            min-height: 0;
            overflow: hidden;
            opacity: 1;
            transform: translateX(0) scale(1);
            transform-origin: right center;
            transition: opacity 160ms ease-out, transform 200ms ease-out;
        }

        [data-media-support-layout][data-support-state="collapsed"] { gap: 0; }

        [data-media-support-layout][data-support-state="collapsed"] [data-media-support-shell] {
            grid-template-rows: minmax(0, 0fr);
        }

        [data-media-support-layout][data-support-state="collapsed"] [data-media-support-panel] {
            pointer-events: none;
            opacity: 0;
            transform: translateX(0.375rem) scale(0.99);
            transition-timing-function: ease-in;
        }

        @media (min-width: 1024px) {
            html[data-media-support-collapsed] [data-media-support-layout] { grid-template-columns: minmax(0, 1fr) minmax(0px, 0px); }

            /* Both column lists must have the same track count AND structurally matching track
               types, or the browser cannot interpolate between them and the collapse snaps
               instead of animating. Hence minmax(<length>, <length>) on the support column in
               both states rather than a bare `320px` on one side and a minmax(0, 0fr) on the
               other. Track 1 stays minmax(0, 1fr) throughout so the main content absorbs the
               freed width smoothly instead of jumping. */
            [data-media-support-layout] {
                grid-template-columns: minmax(0, 1fr) minmax(320px, 320px);
                gap: 1.5rem;
                transition: grid-template-columns 200ms ease-in-out, gap 200ms ease-in-out;
            }

            [data-media-support-layout][data-support-state="collapsed"] {
                grid-template-columns: minmax(0, 1fr) minmax(0px, 0px);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-media-support-layout],
            [data-media-support-shell],
            [data-media-support-panel] {
                transition-duration: 1ms !important;
            }

            [data-media-support-panel] { transform: none !important; }
        }

        @keyframes media-tab-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        [data-doc-tab-content][data-tab-entering] {
            animation: media-tab-fade-in 150ms ease-out;
        }

        @media (prefers-reduced-motion: reduce) {
            [data-doc-tab-content][data-tab-entering] { animation: none; }
        }
    </style>

    {{-- Documentation tabs: exactly Residence and Business, never a combined view. These are
         buttons, not links — both workflows are rendered below and switching only toggles which
         one is visible, so there is no navigation, no request and no lost keystrokes. The server
         still decides which tab opens first (see $activeTab above). --}}
    <div class="mt-5 flex flex-wrap items-end justify-between gap-x-4 gap-y-2 border-b border-ui-border">
        <div role="tablist" aria-label="Documentation tabs" class="flex min-w-0 gap-1 overflow-x-auto">
            @foreach(['residence' => ['label' => 'Residence Documentation', 'icon' => 'home'], 'business' => ['label' => 'Business Documentation', 'icon' => 'building']] as $value => $tab)
                <button type="button"
                        id="{{ $value }}-documentation-tab"
                        role="tab"
                        aria-selected="{{ $activeTab === $value ? 'true' : 'false' }}"
                        aria-controls="{{ $value }}-documentation-panel"
                        tabindex="{{ $activeTab === $value ? '0' : '-1' }}"
                        data-doc-tab="{{ $value }}"
                        class="flex min-h-11 shrink-0 cursor-pointer items-center gap-2 border-b-2 px-3 text-sm font-semibold transition aria-selected:border-brand-primary aria-selected:text-brand-primary aria-[selected=false]:border-transparent aria-[selected=false]:text-text-muted aria-[selected=false]:hover:text-text-main">
                    <x-ui.icon :name="$tab['icon']" size="size-4" />{{ $tab['label'] }}
                </button>
            @endforeach
        </div>

        {{-- Separate panel control aligned with the tabs; it is intentionally outside the
             tablist and never receives tab underline/selection styling. --}}
        <div class="mb-2 flex shrink-0 items-center">
            <button type="button" class="ui-button-secondary-compact cursor-pointer" title="Show panel" aria-label="Show panel" aria-controls="media-support-panel" aria-expanded="false" data-media-support-show hidden><x-ui.icon name="eye" size="size-3.5" />Show Panel</button>
            <button type="button" class="ui-button-secondary-compact cursor-pointer" title="Hide panel" aria-label="Hide panel" aria-controls="media-support-panel" aria-expanded="true" data-media-support-hide><x-ui.icon name="eye-off" size="size-3.5" />Hide Panel</button>
        </div>
    </div>

    <div class="mt-2" data-media-support-layout data-support-state="expanded">
        {{-- Both documentation workflows are rendered; the tabs above toggle which is visible, so
             typed input, upload state and scroll position survive a tab switch. Each keeps its own
             namespaced ids, its own form and its own backend category. --}}
        <div class="min-w-0">
            <div data-doc-tab-content="residence" @if($activeTab !== 'residence') hidden @endif>
                @include('client-folders.media._documentation-panel', [
                    'category' => 'residence',
                    'categoryLabel' => 'Residence',
                    'categoryIcon' => 'home',
                    'activeDocument' => $activeResidenceDocumentation,
                    'categoryDocuments' => $residenceDocumentations,
                    'cibiPrefill' => $residenceCibiPrefill,
                    'clientFolder' => $clientFolder,
                    'activePerson' => $activePerson,
                    'personParams' => $personParams,
                    'clientName' => $clientName,
                ])
            </div>
            <div data-doc-tab-content="business" @if($activeTab !== 'business') hidden @endif>
                <div class="media-card mb-3 p-3.5 sm:p-4" aria-labelledby="business-documentation-selector-label">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p id="business-documentation-selector-label" class="media-field-label"><x-ui.icon name="building" size="size-3.5" />Business Documentation</p>
                            <p class="mt-1 text-xs leading-5 text-text-muted">Select the exact business whose location, evidence, remarks, and Telegram delivery you want to manage.</p>
                        </div>
                        <span class="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-bold text-text-muted">{{ $businessDocumentations->count() }} {{ Str::plural('Business', $businessDocumentations->count()) }}</span>
                    </div>
                    <div class="mt-3 min-w-0" role="list" aria-label="Business Documentation records" data-documentation-business-selector>
                        <div class="flex min-w-0 gap-2 overflow-x-auto pb-1">
                            @foreach($businessDocumentations->reject->isLegacyBusiness() as $businessDocumentation)
                                <a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams + ['tab' => 'business', 'business_documentation' => $businessDocumentation->id]) }}"
                                   class="inline-flex min-h-14 min-w-40 shrink-0 flex-col items-start justify-center gap-0.5 rounded-control border px-3 py-2 text-sm font-semibold transition {{ $activeBusinessDocumentation?->is($businessDocumentation) ? 'border-brand-primary bg-brand-soft text-brand-primary shadow-xs ring-1 ring-brand-primary/15' : 'border-ui-border bg-surface text-text-main hover:border-ui-border-strong hover:bg-surface-muted' }}"
                                   @if($activeBusinessDocumentation?->is($businessDocumentation)) aria-current="true" @endif>
                                    <span class="flex max-w-52 items-center gap-1.5"><x-ui.icon name="building" size="size-3.5 shrink-0" /><span class="truncate">{{ $businessDocumentation->businessDisplayName() }}</span></span>
                                    <span class="pl-5 text-[0.65rem] font-bold tracking-wide text-text-subtle">BD-{{ str_pad((string) $businessDocumentation->id, 6, '0', STR_PAD_LEFT) }}</span>
                                </a>
                            @endforeach
                            @foreach($legacyBusinessDocumentations as $legacyDocumentation)
                                <a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams + ['tab' => 'business', 'business_documentation' => $legacyDocumentation->id]) }}"
                                   class="inline-flex min-h-14 min-w-40 shrink-0 flex-col items-start justify-center gap-0.5 rounded-control border px-3 py-2 text-sm font-semibold transition {{ $activeBusinessDocumentation?->is($legacyDocumentation) ? 'border-brand-primary bg-brand-soft text-brand-primary shadow-xs ring-1 ring-brand-primary/15' : 'border-ui-border bg-surface text-text-main hover:border-ui-border-strong hover:bg-surface-muted' }}"
                                   @if($activeBusinessDocumentation?->is($legacyDocumentation)) aria-current="true" @endif>
                                    <span class="flex items-center gap-1.5"><x-ui.icon name="folder" size="size-3.5" />Legacy / Unassigned</span>
                                    <span class="pl-5 text-[0.65rem] font-bold tracking-wide text-text-subtle">BD-{{ str_pad((string) $legacyDocumentation->id, 6, '0', STR_PAD_LEFT) }}</span>
                                </a>
                            @endforeach
                            @if($businessDocumentations->isEmpty())
                                <p class="flex min-h-14 items-center rounded-control border border-dashed border-ui-border px-3 text-xs text-text-muted">No saved businesses yet. Start with the draft below.</p>
                            @endif
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end border-t border-ui-border pt-3">
                        <a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams + ['tab' => 'business', 'business_draft' => 1] + ($activeBusinessDocumentation ? ['return_business_documentation' => $activeBusinessDocumentation->id] : [])) }}"
                           class="ui-button-primary w-full sm:w-auto"
                           data-add-business-action>
                            <x-ui.icon name="plus" size="size-4" />Add New Business
                        </a>
                    </div>
                </div>

                <div data-documentation-business-workspace>
                    @include('client-folders.media._documentation-panel', [
                        'category' => 'business',
                        'categoryLabel' => 'Business',
                        'categoryIcon' => 'building',
                        'activeDocument' => $activeBusinessDocumentation,
                        'categoryDocuments' => $businessDocumentations,
                        'cibiPrefill' => $businessLocationPrefill,
                        'clientFolder' => $clientFolder,
                        'activePerson' => $activePerson,
                        'personParams' => $personParams,
                        'clientName' => $clientName,
                    ])
                </div>
            </div>
        </div>

        {{-- Right-side preview / support panel — the only collapsible region on this page. Its
             per-tab cards are toggled by the same tab switch as the main workflows. --}}
        <div class="min-w-0" data-media-support-shell>
            <aside id="media-support-panel" class="flex flex-col gap-4 lg:sticky lg:top-20" aria-label="Preview and support panel" data-media-support-panel>
                <div class="media-card p-3.5">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="media-side-title"><x-ui.icon name="telegram" size="size-3.5" />Telegram Caption</h3>
                    </div>
                    <p class="mt-1 text-xs leading-5 text-text-muted">Review or edit the caption before sending to Telegram.</p>
                    <div class="mt-2.5">
                        @foreach(['residence' => ['caption' => $residenceCaption, 'doc' => $activeResidenceDocumentation], 'business' => ['caption' => $businessCaption, 'doc' => $activeBusinessDocumentation]] as $key => $preview)
                            <div data-doc-tab-content="{{ $key }}" @if($activeTab !== $key) hidden @endif>
                                @if($preview['doc'])
                                    @php($delivery = $preview['doc']->latestTelegramDelivery)
                                    @php($oldMatches = (int) old('telegram_documentation_id') === (int) $preview['doc']->id)
                                    @php($partialRetryBody = $delivery?->status === \App\Models\DocumentationTelegramDelivery::STATUS_FAILED && filled($delivery->message_ids) ? $delivery->message_body : null)
                                    @php($messageBody = $oldMatches ? old('telegram_message_body') : ($partialRetryBody ?: $preview['caption']))
                                    <textarea id="telegram-message-body-{{ $preview['doc']->id }}" form="telegram-send-form-{{ $preview['doc']->id }}" name="telegram_message_body" rows="8" maxlength="{{ $telegramMessageBodyMaxLength }}" class="ui-control min-h-44 w-full resize-y cursor-text whitespace-pre-wrap !py-3 text-[13px] leading-6 focus:!border-brand-primary focus:!ring-3 focus:!ring-brand-primary/15" data-telegram-message-body>{{ $messageBody }}</textarea>
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                        <p class="text-xs font-semibold text-text-main">Sent by: {{ request()->user()->full_name }}</p>
                                        <button type="button" class="text-xs font-semibold text-brand-primary hover:underline" data-telegram-reset data-target="telegram-message-body-{{ $preview['doc']->id }}" data-default-message="{{ $preview['caption'] }}">Reset to Default</button>
                                    </div>
                                    <p class="mt-1 text-right text-[0.68rem] text-text-muted"><span data-telegram-character-count>{{ mb_strlen((string) $messageBody) }}</span>/{{ $telegramMessageBodyMaxLength }}</p>
                                    @if($oldMatches)<x-form.validation-message for="telegram_message_body" />@endif
                                @else
                                    <p class="text-[13px] leading-6 text-text-main">No saved preview available.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2.5">
                        @foreach(['residence' => ['label' => 'Residence', 'icon' => 'home'], 'business' => ['label' => 'Business', 'icon' => 'building']] as $key => $meta)
                            <span data-doc-tab-content="{{ $key }}" @if($activeTab !== $key) hidden @endif class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide text-text-muted"><x-ui.icon :name="$meta['icon']" size="size-3" />{{ $meta['label'] }}</span>
                        @endforeach
                    </div>
                </div>

                @foreach(['residence' => ['label' => 'Residence', 'doc' => $activeResidenceDocumentation, 'canSave' => true], 'business' => ['label' => 'Business', 'doc' => $activeBusinessDocumentation, 'canSave' => true]] as $key => $meta)
                    @php($telegramReady = $meta['doc'] && filled($meta['doc']->location) && $meta['doc']->pictures->isNotEmpty() && ($key === 'residence' || $meta['doc']->mapScreenshot))
                    @php($telegramDelivery = $meta['doc']?->latestTelegramDelivery)
                    @php($alreadySent = $telegramDelivery?->status === \App\Models\DocumentationTelegramDelivery::STATUS_SENT)
                    @php($telegramSending = $telegramDelivery?->status === \App\Models\DocumentationTelegramDelivery::STATUS_SENDING)
                    <div data-doc-tab-content="{{ $key }}" @if($activeTab !== $key) hidden @endif class="flex flex-col gap-4">
                        @if($meta['doc'])
                            <div class="media-card p-3.5">
                                <h3 class="media-side-title"><x-ui.icon name="activity" size="size-3.5" />Send Order</h3>
                                <ol class="mt-2.5 flex flex-col">
                                    <li class="media-order-row">
                                        <span class="media-step-badge">1</span>
                                        <span class="min-w-0 flex-1 truncate">Google Map Screenshot</span>
                                        <span class="media-count-badge {{ $meta['doc']->mapScreenshot ? '' : 'is-empty' }}">{{ $meta['doc']->mapScreenshot ? 1 : 0 }}</span>
                                    </li>
                                    <li class="media-order-arrow" aria-hidden="true"><x-ui.icon name="chevron-down" size="size-3.5" /></li>
                                    <li class="media-order-row">
                                        <span class="media-step-badge">2</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $meta['label'] }} Pictures</span>
                                        <span class="media-count-badge {{ $meta['doc']->pictures->count() ? '' : 'is-empty' }}">{{ $meta['doc']->pictures->count() }}</span>
                                    </li>
                                    <li class="media-order-arrow" aria-hidden="true"><x-ui.icon name="chevron-down" size="size-3.5" /></li>
                                    <li class="media-order-row">
                                        <span class="media-step-badge">3</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $meta['label'] }} Videos</span>
                                        <span class="media-count-badge {{ $meta['doc']->videos->count() ? '' : 'is-empty' }}">{{ $meta['doc']->videos->count() }}</span>
                                    </li>
                                </ol>
                            </div>
                        @endif

                        <div class="media-card flex flex-col gap-2 p-3.5">
                            <h3 class="media-side-title mb-0.5"><x-ui.icon name="check" size="size-3.5" />Actions</h3>
                            {{-- Send to Telegram is the primary action of this workspace, so it keeps primary
                                 styling while the integration is unconfigured — disabled, never a faked success. --}}
                            @if(! $telegramConfigured)
                                <span class="ui-button-primary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true" title="Telegram is not connected"><x-ui.icon name="telegram" size="size-4" />{{ $key === 'business' ? 'Send This Business' : 'Send to Telegram' }}</span>
                            @elseif(! $meta['doc'])
                                <span class="ui-button-primary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true" title="Save this documentation first"><x-ui.icon name="telegram" size="size-4" />{{ $key === 'business' ? 'Send This Business' : 'Send to Telegram' }}</span>
                            @elseif($alreadySent)
                                <span class="ui-button-primary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true" title="This saved documentation has already been sent"><x-ui.icon name="check-circle" size="size-4" />Already Sent</span>
                                @if($telegramDelivery?->completed_at)
                                    <p class="text-center text-[0.68rem] leading-4 text-text-muted">Sent to Telegram · {{ $telegramDelivery->completed_at->timezone(config('cims.display_timezone'))->diffForHumans() }}</p>
                                @endif
                            @elseif($telegramSending)
                                <span class="ui-button-primary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true"><x-ui.icon name="telegram" size="size-4" />Sending...</span>
                            @elseif(! $telegramReady)
                                <span class="ui-button-primary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true" title="Complete the required saved documentation evidence first"><x-ui.icon name="telegram" size="size-4" />{{ $key === 'business' ? 'Send This Business' : 'Send to Telegram' }}</span>
                            @else
                                <button type="button" class="ui-button-primary w-full justify-center" data-modal-open="telegram-send-{{ $meta['doc']->id }}"><x-ui.icon name="telegram" size="size-4" />{{ $telegramDelivery?->status === \App\Models\DocumentationTelegramDelivery::STATUS_FAILED ? 'Retry Send to Telegram' : ($key === 'business' ? 'Send This Business' : 'Send to Telegram') }}</button>
                            @endif
                            @if($meta['doc'])
                                <a href="{{ route('client-folders.media.documentation.preview', [$clientFolder, $meta['doc']] + $personParams) }}" target="_blank" rel="noopener" class="ui-button-secondary w-full justify-center"><x-ui.icon name="eye" size="size-4" />{{ $key === 'business' ? 'Preview This Business' : 'Preview Before Send' }}</a>
                            @else
                                <span class="ui-button-secondary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true"><x-ui.icon name="eye" size="size-4" />Preview Before Send</span>
                            @endif
                            @if($key === 'business' && $businessDocumentations->count() > 1)
                                <div class="my-1 border-t border-ui-border"></div>
                                <a href="{{ route('client-folders.media.business-documentations.preview', [$clientFolder] + $personParams) }}" target="_blank" rel="noopener" class="ui-button-secondary w-full justify-center"><x-ui.icon name="eye" size="size-4" />Preview All Businesses</a>
                                @if($telegramConfigured && ! $businessDocumentations->contains(fn ($business) => $business->latestTelegramDelivery?->status === \App\Models\DocumentationTelegramDelivery::STATUS_SENDING))
                                    <button type="button" class="media-button-tertiary w-full justify-center" data-modal-open="telegram-send-all-businesses"><x-ui.icon name="telegram" size="size-4" />Send All Businesses ({{ $businessDocumentations->count() }})</button>
                                @else
                                    <span class="media-button-tertiary w-full cursor-not-allowed justify-center opacity-55" aria-disabled="true" title="{{ $telegramConfigured ? 'A business is currently being sent' : 'Telegram is not connected' }}"><x-ui.icon name="telegram" size="size-4" />Send All Businesses ({{ $businessDocumentations->count() }})</span>
                                @endif
                                <p class="text-[0.68rem] leading-4 text-text-muted">Sends every saved business for this exact {{ $activePerson ? 'Co-Maker' : 'Applicant' }} in business-by-business order. Unsaved drafts are excluded.</p>
                            @endif
                            @if($key !== 'business' || $meta['doc'])
                                <button type="submit" form="{{ $key }}-documentation-form" class="media-button-tertiary {{ $meta['canSave'] ? '' : 'cursor-not-allowed opacity-55' }}" data-documentation-save-submit @disabled(! $meta['canSave'])><x-ui.icon name="download" size="size-4" /><span data-documentation-save-label>{{ $key === 'business' ? 'Save Changes' : 'Save Locally' }}</span></button>
                            @endif
                            <span class="media-button-ghost" aria-disabled="true" title="Google Drive backup is not yet connected for this system"><x-ui.icon name="drive" size="size-3.5" />Back up to Google Drive</span>
                            <p class="mt-0.5 text-[0.68rem] leading-4 text-text-muted">@if($telegramConfigured)Telegram sends the last saved version only. Save local changes before sending. @else Telegram send and Drive backup are not yet connected for this system. @endif {{ $key === 'business' && $meta['doc'] ? 'Save Changes stores text edits and staged media on this selected business.' : 'Save Locally stores this set in BRBI-CIMS.' }}</p>
                        </div>
                    </div>
                @endforeach

                <div class="media-card p-3.5">
                    <h3 class="media-side-title"><x-ui.icon name="cloud" size="size-3.5" />Storage &amp; Integrations</h3>
                    <ul class="mt-2.5 flex flex-col gap-2.5">
                        <li class="flex items-start gap-2">
                            <span class="media-status-dot is-on" aria-hidden="true"></span>
                            <span class="min-w-0"><span class="block text-xs font-bold text-text-main">Local Storage</span><span class="block text-[0.7rem] leading-4 text-text-muted">Saved locally in BRBI-CIMS</span></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="media-status-dot {{ $telegramConfigured ? 'is-on' : '' }}" aria-hidden="true"></span>
                            <span class="min-w-0"><span class="block text-xs font-bold text-text-main">Telegram</span><span class="block text-[0.7rem] leading-4 text-text-muted">{{ $telegramConfigured ? 'Connected' : 'Not connected' }}</span></span>
                        </li>
                        <li class="flex items-start gap-2">
                            <span class="media-status-dot" aria-hidden="true"></span>
                            <span class="min-w-0"><span class="block text-xs font-bold text-text-main">Google Drive Backup</span><span class="block text-[0.7rem] leading-4 text-text-muted">Optional · Not connected</span></span>
                        </li>
                    </ul>
                </div>

                <div class="media-card p-3.5">
                    <h3 class="media-side-title"><x-ui.icon name="clock" size="size-3.5" />Recent Activity</h3>
                    @foreach(['residence' => $residenceDocumentationActivity, 'business' => $businessDocumentationActivity] as $key => $documentationActivityItems)
                        <div data-doc-tab-content="{{ $key }}" data-documentation-recent-activity="{{ $key }}" @if($activeTab !== $key) hidden @endif>
                            @include('client-folders.media._recent-activity', ['activities' => $documentationActivityItems, 'category' => $key])
                        </div>
                    @endforeach
                </div>
            </aside>
        </div>
    </div>

    {{-- View All modal, one per category — full Field Documentation activity history off the
         exact same authoritative collection the compact 5-item panel above draws from. Same
         plain <dialog> + generic [data-modal-open]/[data-modal-close] wiring as CI Activities'
         own "all-activity-history" modal (client-folders.activities.index), so opening, Escape,
         backdrop and focus behavior all come for free. --}}
    @foreach(['residence' => $residenceDocumentationActivity, 'business' => $businessDocumentationActivity] as $key => $documentationActivityItems)
        <dialog id="documentation-activity-history-{{ $key }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-2xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" aria-labelledby="documentation-activity-history-{{ $key }}-title">
            <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
                <div class="flex shrink-0 items-center justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6">
                    <div class="flex items-center gap-2 text-brand-primary">
                        <x-ui.icon name="clock" size="size-5" />
                        <div>
                            <h2 id="documentation-activity-history-{{ $key }}-title" class="text-lg font-bold text-brand-sidebar">Recent Activity</h2>
                            <p class="text-xs font-normal text-text-muted">{{ ucfirst($key) }} Documentation</p>
                        </div>
                    </div>
                    <button type="button" class="ui-icon-button -mr-2" data-modal-close aria-label="Close Recent Activity"><x-ui.icon name="close" size="size-5" /></button>
                </div>
                <div class="min-h-0 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6" data-documentation-activity-modal-body="{{ $key }}">
                    @include('client-folders.media._recent-activity-modal-body', ['activities' => $documentationActivityItems])
                </div>
            </div>
        </dialog>
    @endforeach

    @if($telegramConfigured)
        @foreach([$activeResidenceDocumentation, $activeBusinessDocumentation] as $documentation)
            @php($modalReady = $documentation && filled($documentation->location) && $documentation->pictures->isNotEmpty() && ($documentation->isResidence() || $documentation->mapScreenshot))
            @if($modalReady && ! in_array($documentation->latestTelegramDelivery?->status, [\App\Models\DocumentationTelegramDelivery::STATUS_SENT, \App\Models\DocumentationTelegramDelivery::STATUS_SENDING], true))
                <x-ui.modal id="telegram-send-{{ $documentation->id }}" title="Send this {{ $documentation->isResidence() ? 'Residence' : 'Business' }} Documentation to Telegram?" size="max-w-md">
                    <p class="text-sm leading-6 text-text-muted">The following saved items will be sent to the configured Telegram chat:</p>
                    <ul class="mt-3 space-y-2 text-sm text-text-main">
                        <li class="flex items-center gap-2"><x-ui.icon name="check" size="size-4 text-success" />Caption</li>
                        @if($documentation->mapScreenshot)<li class="flex items-center gap-2"><x-ui.icon name="check" size="size-4 text-success" />Google Map Screenshot</li>@endif
                        <li class="flex items-center gap-2"><x-ui.icon name="check" size="size-4 text-success" />{{ $documentation->pictures->count() }} {{ Str::plural('Picture', $documentation->pictures->count()) }}</li>
                        @if($documentation->videos->isNotEmpty())<li class="flex items-center gap-2"><x-ui.icon name="check" size="size-4 text-success" />{{ $documentation->videos->count() }} {{ Str::plural('Video', $documentation->videos->count()) }}</li>@endif
                    </ul>
                    <p class="mt-4 rounded-control border border-brand-primary/15 bg-brand-soft/45 p-3 text-sm leading-6 text-text-main">Sending multiple photos or videos may take a little longer depending on your internet connection. Please wait until the sending process is complete.</p>
                    <p class="mt-2 hidden text-xs leading-5 text-text-muted" data-telegram-send-helper>Please wait. Sending time depends on the number and size of files and your internet connection.</p>
                    <x-slot:footer>
                        <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
                        <form id="telegram-send-form-{{ $documentation->id }}" method="POST" action="{{ route('client-folders.media.documentation.telegram', [$clientFolder, $documentation] + $personParams) }}" data-telegram-send-form>
                            @csrf
                            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                            <input type="hidden" name="telegram_documentation_id" value="{{ $documentation->id }}">
                            <button type="submit" class="ui-button-primary" data-telegram-send-submit><x-ui.icon name="telegram" size="size-4" /><span data-telegram-send-label>Send to Telegram</span></button>
                        </form>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
        @endforeach

        @if($businessDocumentations->count() > 1 && ! $businessDocumentations->contains(fn ($business) => $business->latestTelegramDelivery?->status === \App\Models\DocumentationTelegramDelivery::STATUS_SENDING))
            <x-ui.modal id="telegram-send-all-businesses" title="Send all {{ $businessDocumentations->count() }} saved businesses to Telegram?" size="max-w-md">
                <p class="text-sm leading-6 text-text-muted">Included for this exact {{ $activePerson ? 'Co-Maker' : 'Applicant' }}:</p>
                <ul class="mt-3 space-y-2 text-sm text-text-main">
                    @foreach($businessDocumentations->sortBy('id') as $businessDocumentation)
                        <li class="flex items-start gap-2"><x-ui.icon name="check" size="mt-0.5 size-4 shrink-0 text-success" /><span class="min-w-0 break-words"><span class="font-semibold">{{ $businessDocumentation->businessDisplayName() }}</span> <span class="text-xs text-text-muted">BD-{{ str_pad((string) $businessDocumentation->id, 6, '0', STR_PAD_LEFT) }}</span></span></li>
                    @endforeach
                </ul>
                <p class="mt-4 rounded-control border border-brand-primary/15 bg-brand-soft/45 p-3 text-sm leading-6 text-text-main">Each business is sent as its own caption, optional map, pictures, and optional videos before the next business begins.</p>
                <p class="mt-2 hidden text-xs leading-5 text-text-muted" data-telegram-send-helper>Please wait while the saved businesses are sent in order.</p>
                <x-slot:footer>
                    <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
                    <form method="POST" action="{{ route('client-folders.media.business-documentations.telegram', [$clientFolder] + $personParams) }}" data-telegram-send-form>
                        @csrf
                        <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                        <button type="submit" class="ui-button-primary" data-telegram-send-submit><x-ui.icon name="telegram" size="size-4" /><span data-telegram-send-label>Send All Businesses</span></button>
                    </form>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    @endif

    <script>
        // Client-side tab switching. Both workflows are already in the DOM, so this only flips
        // which [data-doc-tab-content] blocks are visible — no navigation, no request, no scroll
        // reset, and anything half-typed in the other tab is still there when you come back.
        // The URL's ?tab is kept in sync with replaceState so a later reload or a save redirect
        // reopens the tab the encoder was actually on.
        window.initDocumentationTabs = function initDocumentationTabs() {
            const tabs = Array.from(document.querySelectorAll('[data-doc-tab]'));
            const panels = Array.from(document.querySelectorAll('[data-doc-tab-content]'));
            if (tabs.length === 0 || panels.length === 0) return;
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            const activate = (key, { focusTab = false } = {}) => {
                tabs.forEach((tab) => {
                    const selected = tab.dataset.docTab === key;
                    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                    tab.tabIndex = selected ? 0 : -1;
                    if (selected && focusTab) tab.focus();
                });

                panels.forEach((panel) => {
                    const shows = panel.dataset.docTabContent === key;
                    const wasHidden = panel.hidden;
                    panel.hidden = ! shows;
                    if (shows && wasHidden && ! reducedMotion) {
                        panel.dataset.tabEntering = '';
                        window.setTimeout(() => delete panel.dataset.tabEntering, 200);
                    }
                });

                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', key);
                    window.history.replaceState(window.history.state, '', url);
                } catch (e) {}
            };

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => activate(tab.dataset.docTab));
                // Standard tablist keyboard support: arrows move between tabs.
                tab.addEventListener('keydown', (event) => {
                    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
                    event.preventDefault();
                    const index = tabs.indexOf(tab);
                    const next = event.key === 'ArrowRight'
                        ? tabs[(index + 1) % tabs.length]
                        : tabs[(index - 1 + tabs.length) % tabs.length];
                    activate(next.dataset.docTab, { focusTab: true });
                });
            });
        };
        document.addEventListener('DOMContentLoaded', () => window.initDocumentationTabs());
    </script>

    <script>
        document.querySelectorAll('[data-telegram-message-body]').forEach((textarea) => {
            const count = textarea.closest('[data-doc-tab-content]')?.querySelector('[data-telegram-character-count]');
            const updateCount = () => { if (count) count.textContent = String(textarea.value.length); };
            textarea.addEventListener('input', updateCount);
            updateCount();
        });
        document.querySelectorAll('[data-telegram-reset]').forEach((button) => {
            button.addEventListener('click', () => {
                const textarea = document.getElementById(button.dataset.target);
                if (!(textarea instanceof HTMLTextAreaElement)) return;
                textarea.value = button.dataset.defaultMessage ?? '';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                textarea.focus();
            });
        });

        document.querySelectorAll('[data-telegram-send-form]').forEach((form) => {
            form.addEventListener('submit', () => {
                const button = form.querySelector('[data-telegram-send-submit]');
                const label = form.querySelector('[data-telegram-send-label]');
                if (form.dataset.submitting === 'true') return;
                form.dataset.submitting = 'true';
                if (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                }
                if (label) label.textContent = 'Sending to Telegram...';
                form.closest('[role="dialog"]')?.querySelector('[data-telegram-send-helper]')?.classList.remove('hidden');
            });
        });
    </script>

    <script>
        // Hide/show for the right-side preview panel only — the main documentation workflow is
        // never collapsed. Same interaction pattern, animation, persistence and accessibility as
        // the Recent Activity panel on the Business / Income Sources page; the column widths are
        // driven from [data-support-state] in the CSS above rather than by toggling utility
        // classes. The pre-paint read above restores the state before anything is painted after
        // a mutation redirect.
        window.initMediaSupportToggle = function initMediaSupportToggle() {
            const layout = document.querySelector('[data-media-support-layout]');
            const shell = document.querySelector('[data-media-support-shell]');
            const panel = document.querySelector('[data-media-support-panel]');
            const hideButton = document.querySelector('[data-media-support-hide]');
            const showButton = document.querySelector('[data-media-support-show]');
            const storageKey = 'brbi-media-support-collapsed';
            const initiallyCollapsed = document.documentElement.hasAttribute('data-media-support-collapsed');
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let collapseTimer = null;
            if (!(layout instanceof HTMLElement)
                || !(shell instanceof HTMLElement)
                || !(panel instanceof HTMLElement)
                || !(hideButton instanceof HTMLButtonElement)
                || !(showButton instanceof HTMLButtonElement)) return;

            const setExpandedState = (expanded) => {
                hideButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                showButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };

            const syncFinalVisibility = (hidden) => {
                shell.hidden = hidden;
                panel.hidden = hidden;
            };

            const persistCollapsedState = (collapsed) => {
                try {
                    localStorage.setItem(storageKey, String(collapsed));
                } catch (e) {}
            };

            const finishCollapse = () => {
                collapseTimer = null;
                if (layout.dataset.supportState === 'collapsed') syncFinalVisibility(true);
            };

            const hideSupportPanel = () => {
                if (collapseTimer !== null) window.clearTimeout(collapseTimer);
                shell.hidden = false;
                showButton.hidden = false;
                hideButton.hidden = true;
                layout.dataset.supportState = 'collapsed';
                setExpandedState(false);
                persistCollapsedState(true);

                if (reducedMotion) {
                    finishCollapse();
                    return;
                }

                collapseTimer = window.setTimeout(finishCollapse, 220);
            };

            const showSupportPanel = () => {
                if (collapseTimer !== null) window.clearTimeout(collapseTimer);
                syncFinalVisibility(false);
                showButton.hidden = true;
                hideButton.hidden = false;
                setExpandedState(true);
                persistCollapsedState(false);

                if (reducedMotion) {
                    layout.dataset.supportState = 'expanded';
                    return;
                }

                layout.dataset.supportState = 'collapsed';
                void shell.offsetHeight;
                window.requestAnimationFrame(() => {
                    layout.dataset.supportState = 'expanded';
                });
            };

            if (initiallyCollapsed) {
                layout.dataset.supportState = 'collapsed';
                syncFinalVisibility(true);
                showButton.hidden = false;
                hideButton.hidden = true;
                setExpandedState(false);
            } else {
                layout.dataset.supportState = 'expanded';
                syncFinalVisibility(false);
                showButton.hidden = true;
                hideButton.hidden = false;
                setExpandedState(true);
            }
            document.documentElement.removeAttribute('data-media-support-collapsed');

            hideButton.addEventListener('click', hideSupportPanel);
            showButton.addEventListener('click', showSupportPanel);
        };
        document.addEventListener('DOMContentLoaded', () => window.initMediaSupportToggle());
    </script>

    {{-- Legacy Media — historical uploads outside the documentation workflow. Never deleted, but
         the whole block is omitted when this person has none, rather than showing an empty shell. --}}
    @if($counts['all'] > 0)
    <div class="mt-8 flex items-center gap-3">
        <span class="h-px flex-1 bg-ui-border"></span>
        <span class="text-[0.65rem] font-bold uppercase tracking-[0.16em] text-text-subtle">Outside the documentation workflow</span>
        <span class="h-px flex-1 bg-ui-border"></span>
    </div>
    <details class="media-card media-disclosure group mt-3 overflow-hidden bg-surface-muted/60">
        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 sm:px-5 [&::-webkit-details-marker]:hidden">
            <span class="flex min-w-0 items-center gap-2 text-sm font-bold text-text-main"><x-ui.icon name="media" size="size-4 text-text-muted" />Legacy Media <span class="font-normal text-text-muted">({{ $counts['all'] }})</span></span>
            <x-ui.icon name="chevron-down" size="size-4 shrink-0 text-text-muted transition group-open:rotate-180" />
        </summary>
        <div class="border-t border-ui-border bg-surface p-4 sm:p-5">
            <p class="mb-4 text-xs leading-5 text-text-muted">Previously uploaded media not grouped into Residence or Business documentation — existing CI Activity Supporting Proof and any media uploaded before this workspace existed. These remain exactly as saved.</p>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                @can('create', [App\Models\MediaReference::class, $clientFolder])
                    <button type="button" class="ui-button-secondary" data-modal-open="media-upload-dialog"><span aria-hidden="true">+</span>Add Other Media</button>
                @endcan
            </div>
            <form method="GET" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                @foreach($personParams as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <div class="flex-1"><label for="media-type" class="ui-label">Media</label><select id="media-type" name="type" class="ui-control"><option value="">All Media ({{ $counts['all'] }})</option><option value="photo" @selected(request('type') === 'photo')>Photos ({{ $counts['photo'] }})</option><option value="video" @selected(request('type') === 'video')>Videos ({{ $counts['video'] }})</option></select></div>
                <div class="flex-1"><label for="media-category" class="ui-label">Category</label><select id="media-category" name="category" class="ui-control"><option value="">All Categories</option>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(request('category') === $category->value)>{{ str($category->value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
                <button class="ui-button-secondary">Apply Filters</button>
                @if(request()->hasAny(['type', 'category']))<a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams) }}" class="ui-button-secondary">Clear</a>@endif
            </form>

            <div class="mt-5">
                @if($mediaItems->isEmpty())
                    <x-ui.empty-state title="No legacy media" description="Existing CI Supporting Proof and any older uploads will appear here." icon="media" />
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        @foreach($mediaItems as $media)
                            @include('media._card', ['editable' => true])
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $mediaItems->links() }}</div>
                @endif
            </div>
        </div>
    </details>
    @endif

    @can('create', [App\Models\MediaReference::class, $clientFolder])
        <x-ui.modal id="media-upload-dialog" title="Add Other Media" description="Upload media that isn't part of a Residence/Business Documentation set. Each selected file creates its own evidence record." size="max-w-2xl" :data-open-on-error="old('media_form') === 'upload' ? 'true' : 'false'">
            <form id="media-upload-form" method="POST" action="{{ route('client-folders.media.store', $clientFolder) }}" enctype="multipart/form-data">
                @csrf<input type="hidden" name="media_form" value="upload"><input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
                <div>
                    <label for="media-files" class="ui-label">Photos or MP4 videos <span class="text-danger">*</span></label>
                    <input id="media-files" name="files[]" type="file" multiple required accept="image/jpeg,image/png,image/webp,video/mp4" class="ui-control file:mr-3 file:rounded-control file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:font-semibold file:text-brand-primary">
                    <p class="ui-help">Up to {{ config('cims.media.max_files_per_upload') }} files. Photos: 10 MB each. MP4 videos: 50 MB each.</p>
                    <x-form.validation-message for="files" />
                    @foreach($errors->get('files.*') as $messages)
                        @foreach($messages as $message)
                            <p class="mt-2 text-sm font-medium text-danger" role="alert">{{ $message }}</p>
                        @endforeach
                    @endforeach
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div><label for="upload-category" class="ui-label">Category <span class="text-danger">*</span></label><select id="upload-category" name="category" class="ui-control" required>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(old('category') === $category->value)>{{ str($category->value)->title() }}</option>@endforeach</select><x-form.validation-message for="category" /></div>
                    <div><label for="upload-captured-at" class="ui-label">Date Taken</label><input id="upload-captured-at" name="captured_at" type="date" max="{{ now(config('cims.display_timezone'))->toDateString() }}" value="{{ old('captured_at') }}" class="ui-control"><x-form.validation-message for="captured_at" /></div>
                    <div class="sm:col-span-2"><label for="upload-label" class="ui-label">Title / Caption</label><input id="upload-label" name="label" value="{{ old('label') }}" maxlength="255" class="ui-control" placeholder="Optional shared title"><x-form.validation-message for="label" /></div>
                    <div><label for="upload-activity" class="ui-label">Related CI Activity</label><select id="upload-activity" name="ci_activity_id" class="ui-control"><option value="">Not linked</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) old('ci_activity_id') === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select><x-form.validation-message for="ci_activity_id" /></div>
                    <div><label for="upload-income-source" class="ui-label">Business / Income Source</label><select id="upload-income-source" name="income_source_id" class="ui-control"><option value="">Not linked</option>@foreach($incomeSources as $source)<option value="{{ $source->id }}" @selected((string) old('income_source_id') === (string) $source->id)>{{ $source->business_name ?: $source->source_name }}</option>@endforeach</select><x-form.validation-message for="income_source_id" /></div>
                    <div class="sm:col-span-2"><label for="upload-remarks" class="ui-label">Description / Notes</label><textarea id="upload-remarks" name="remarks" rows="3" maxlength="10000" class="ui-control">{{ old('remarks') }}</textarea><x-form.validation-message for="remarks" /></div>
                </div>
            </form>
            <x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Cancel</button><button type="submit" form="media-upload-form" class="ui-button-primary">Upload Media</button></x-slot:footer>
        </x-ui.modal>
    @endcan

    @foreach($mediaItems as $media)
        @can('update', $media)
            @php($editHasErrors = old('media_form') === 'edit-'.$media->id)
            <x-ui.modal id="media-edit-{{ $media->id }}" title="Edit Media Details" size="max-w-lg" :data-open-on-error="$editHasErrors ? 'true' : 'false'">
                <form id="media-edit-form-{{ $media->id }}" method="POST" action="{{ route('client-folders.media.update', [$clientFolder, $media]) }}">@csrf @method('PATCH')<input type="hidden" name="media_form" value="edit-{{ $media->id }}"><input type="hidden" name="co_maker_id" value="{{ ($activePerson ?? null)?->id }}">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="ui-label" for="edit-category-{{ $media->id }}">Category</label><select class="ui-control" id="edit-category-{{ $media->id }}" name="category">@foreach($categories as $category)<option value="{{ $category->value }}" @selected(($editHasErrors ? old('category') : $media->category->value) === $category->value)>{{ str($category->value)->title() }}</option>@endforeach</select></div>
                        <div><label class="ui-label" for="edit-date-{{ $media->id }}">Date Taken</label><input class="ui-control" id="edit-date-{{ $media->id }}" name="captured_at" type="date" value="{{ $editHasErrors ? old('captured_at') : $media->captured_at?->toDateString() }}"></div>
                        <div class="sm:col-span-2"><label class="ui-label" for="edit-label-{{ $media->id }}">Title / Caption</label><input class="ui-control" id="edit-label-{{ $media->id }}" name="label" maxlength="255" value="{{ $editHasErrors ? old('label') : $media->label }}"></div>
                        <div><label class="ui-label" for="edit-activity-{{ $media->id }}">Related CI Activity</label><select class="ui-control" id="edit-activity-{{ $media->id }}" name="ci_activity_id"><option value="">Not linked</option>@foreach($activities as $activity)<option value="{{ $activity->id }}" @selected((string) ($editHasErrors ? old('ci_activity_id') : $media->activities->first()?->id) === (string) $activity->id)>{{ $activity->name }}</option>@endforeach</select></div>
                        <div><label class="ui-label" for="edit-source-{{ $media->id }}">Business / Income Source</label><select class="ui-control" id="edit-source-{{ $media->id }}" name="income_source_id"><option value="">Not linked</option>@foreach($incomeSources as $source)<option value="{{ $source->id }}" @selected((string) ($editHasErrors ? old('income_source_id') : $media->income_source_id) === (string) $source->id)>{{ $source->business_name ?: $source->source_name }}</option>@endforeach</select></div>
                        <div class="sm:col-span-2"><label class="ui-label" for="edit-remarks-{{ $media->id }}">Description / Notes</label><textarea class="ui-control" id="edit-remarks-{{ $media->id }}" name="remarks" rows="3">{{ $editHasErrors ? old('remarks') : $media->remarks }}</textarea></div>
                    </div>
                    @if($editHasErrors && $errors->any())<div class="mt-4 rounded-control border border-danger/25 bg-danger-soft p-3 text-sm text-danger" role="alert">{{ $errors->first() }}</div>@endif
                </form>
                <x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Cancel</button><button type="submit" form="media-edit-form-{{ $media->id }}" class="ui-button-primary">Save Changes</button></x-slot:footer>
            </x-ui.modal>
        @endcan
        @can('delete', $media)
            <x-ui.modal id="media-remove-{{ $media->id }}" title="Remove Media" size="max-w-md"><p class="text-sm leading-6 text-text-muted">Are you sure you want to remove <strong class="text-text-main">{{ $media->label ?: 'this media item' }}</strong>? The evidence file will remain protected and will not be permanently deleted.</p><x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Cancel</button><form method="POST" action="{{ route('client-folders.media.destroy', [$clientFolder, $media]) }}">@csrf @method('DELETE')<button class="ui-button-danger">Remove</button></form></x-slot:footer></x-ui.modal>
        @endcan
    @endforeach
@endsection
