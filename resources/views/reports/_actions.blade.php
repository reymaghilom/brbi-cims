@php
    /**
     * Only actions this exact row can really perform are rendered — no placeholder buttons and no
     * unsupported formats. A Pending row never offers a preview or a download of an unfinished
     * report; it offers the one action that moves it forward, plus a way into the whole folder.
     *
     * Every link and form carries the exact context back into the module's own workflow: the Client
     * Folder, the exact person (Applicant, or that one Co-Maker) and the exact income source for the
     * two business modules. Backend authorization still runs on arrival — these are shortcuts into
     * the existing routes, never a way around them.
     *
     * The three Completed controls are icon-only at every width: their labels reach the user through
     * title/aria-label, which keeps the Actions column the same compact size on a dense desktop
     * table and on a narrow card.
     */
    $preview = $item->previewAction();
    $downloads = $item->downloadActions();
    $iconButton = 'ui-action-icon-button text-brand-primary hover:border-brand-primary hover:bg-brand-soft';
    // Create and Continue are the same action at two points in a report's life, so they share one
    // compact outlined treatment — same height, padding, border, radius, text size and hover. Only
    // the icon and the label separate them: a plus for work that does not exist yet, a pencil for
    // work already underway. Both come from the work item itself so the pair always agrees (CI / BI
    // reads Create Report on every Pending row — see ReportWorkItem::continueLabel()).
    $startButton = 'ui-button-secondary-compact px-2.5 text-brand-primary';
    $startIcon = $item->continueIcon();
@endphp

<div class="{{ $actionClass ?? 'flex flex-nowrap items-center gap-1.5' }} whitespace-nowrap">
    @if($item->isCompleted)
        @if($preview)
            @if($preview['method'] === 'GET')
                {{-- A GET form rebuilds its query string from successful controls, which can drop
                     the report_type/income_source_id already embedded in this URL. Preview is
                     navigation, so keep the exact official-preview URL intact as a real link. --}}
                <a href="{{ $preview['url'] }}" target="_blank" rel="noopener"
                   class="{{ $iconButton }}" title="Preview Report" aria-label="Preview Report">
                    <x-ui.icon name="eye" size="size-4" />
                </a>
            @else
                <form method="{{ $preview['method'] }}" action="{{ $preview['url'] }}" target="_blank" rel="noopener">
                    @csrf
                    @foreach($preview['fields'] as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <button type="submit" class="{{ $iconButton }}" title="Preview Report" aria-label="Preview Report">
                        <x-ui.icon name="eye" size="size-4" />
                    </button>
                </form>
            @endif
        @endif

        @if($downloads !== [])
            {{-- One icon-only Download trigger instead of a row of format buttons. x-ui.context-menu
                 is the project's existing <details> menu: app.js already gives it outside-click and
                 Escape dismissal, aria-expanded and focus handling, so no new JS is introduced
                 here, and the panel keeps the shared border/shadow/hover treatment. --}}
            <x-ui.context-menu label="Download report">
                <x-slot:trigger>
                    <span class="{{ $iconButton }}">
                        <x-ui.icon name="download" size="size-4" />
                    </span>
                </x-slot:trigger>
                @foreach($downloads as $download)
                    @if($download['method'] === 'GET')
                        <a href="{{ $download['url'] }}" target="_blank" rel="noopener" role="menuitem" class="client-folder-menu-item" aria-label="{{ $download['label'] }}">
                            <x-ui.icon :name="$download['icon']" size="size-4" class="text-text-subtle" data-report-format-icon="{{ strtolower($download['format']) }}" />{{ $download['format'] }}
                        </a>
                    @else
                        <form method="{{ $download['method'] }}" action="{{ $download['url'] }}" target="_blank" rel="noopener" role="none">
                            @csrf
                            @foreach($download['fields'] as $name => $value)
                                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                            @endforeach
                            <button type="submit" role="menuitem" class="client-folder-menu-item" aria-label="{{ $download['label'] }}">
                                <x-ui.icon :name="$download['icon']" size="size-4" class="text-text-subtle" data-report-format-icon="{{ strtolower($download['format']) }}" />{{ $download['format'] }}
                            </button>
                        </form>
                    @endif
                @endforeach
            </x-ui.context-menu>
        @endif
    @else
        {{-- A pending CI / BI or Business Report opens its own existing encoding page inside the
             shared <dialog>+iframe modal for that module rather than navigating away, so the CI
             never loses the workspace they are working through. The URL is the identical existing
             edit URL the action already used — the exact person for CI / BI, and additionally the
             exact income source for a Business Report — so continuing edits the very same record it
             always did. Both stay real links: with JavaScript unavailable the same href opens the
             same page directly. Residence and Business Checks keep their own navigation. --}}
        <a href="{{ $item->continueUrl() }}" class="{{ $startButton }}" title="{{ $item->continueLabel() }}"
           @if($item->kind === 'cibi')
               data-modal-open="cibi-report-dialog" data-cibi-report-url="{{ $item->continueUrl() }}"
           @elseif($item->kind === 'business_report' && $item->isUnboundBusiness())
               data-modal-open="add-business-template-dialog" data-business-template-base-url="{{ $item->continueUrl() }}"
           @elseif($item->kind === 'business_report')
               data-modal-open="business-report-dialog" data-business-report-url="{{ $item->continueUrl() }}"
           @elseif(in_array($item->kind, ['residence_check', 'business_check'], true))
               data-modal-open="check-report-dialog" data-check-report-url="{{ $item->continueUrl() }}" data-check-report-title="{{ $item->typeLabel() }}"
           @endif>
            <x-ui.icon :name="$startIcon" size="size-3.5" />{{ $item->continueLabel() }}
        </a>
    @endif

    <a href="{{ $item->folderUrl() }}" class="ui-action-icon-button text-text-muted hover:border-brand-primary hover:bg-brand-soft hover:text-brand-primary" title="Open Client Folder" aria-label="Open Client Folder">
        <x-ui.icon name="folder-open" size="size-4" />
    </a>
</div>
