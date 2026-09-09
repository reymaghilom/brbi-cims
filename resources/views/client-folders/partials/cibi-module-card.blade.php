@php
    $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
    $cibiHasReport = $cibiReport !== null;
    $cibiComplete = $cibiReport?->state === \App\Enums\RecordState::Complete;
    $cibiHref = route('client-folders.cibi-report.edit', [$clientFolder] + $personParams);
@endphp
<x-ui.module-card
    id="open-cibi-report"
    title="CI / BI Report"
    icon="report"
    :state="$cibiReport?->state?->value ?? 'not_started'"
    :description="$cibiHasReport ? 'Official CI / BI report record available.' : 'No CI / BI report has been started.'"
    :href="$cibiHref"
    modal-id="cibi-report-dialog"
    :modal-url="$cibiHref"
    :updated-at="$cibiReport?->updated_at?->timezone($displayTimezone)->format('M j, Y')"
    :open-label="$cibiHasReport ? 'Open' : 'Add'"
    :open-icon="$cibiHasReport ? 'edit' : 'plus'"
>
    @if($cibiComplete || ($cibiHasReport && auth()->user()->can('reassignSignatory', $cibiReport)))
        <x-slot:footer>
            @if($cibiComplete)
                <a href="{{ route('client-folders.generated-reports.preview', [$clientFolder, 'report_type' => 'cibi'] + $personParams) }}" target="_blank" rel="noopener" class="ui-action-icon-button ui-action-icon-button-neutral" title="Preview CI / BI Report" aria-label="Preview CI / BI Report"><x-ui.icon name="eye" size="size-4" /></a>
                <x-ui.context-menu label="Download CI / BI Report">
                    <x-slot:trigger>
                        <span class="ui-action-icon-button ui-action-icon-button-neutral gap-0.5 !w-auto px-1.5"><x-ui.icon name="download" size="size-4" /><x-ui.icon name="chevron-down" size="size-3" /></span>
                    </x-slot:trigger>
                    {{-- A plain download link carrying this exact person, matching the Preview link
                         above and the Business Report's own Download PDF. The former hidden-form
                         submission depended on a form living elsewhere in the page and broke as
                         soon as the target URL was opened as a link. --}}
                    <a href="{{ route('client-folders.cibi-report.export-pdf', [$clientFolder] + $personParams) }}" target="_blank" rel="noopener" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="report" size="size-4" />Download PDF</a>
                    <button type="submit" form="dashboard-cibi-export-excel-form" role="menuitem" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="spreadsheet" size="size-4" />Download Excel</button>
                </x-ui.context-menu>
            @endif
            @if($cibiHasReport && auth()->user()->can('reassignSignatory', $cibiReport))
                <x-ui.context-menu label="CI/BI signatory management">
                    <x-slot:trigger>
                        <span class="ui-action-icon-button ui-action-icon-button-neutral" title="CI/BI signatory management" aria-label="CI/BI signatory management"><x-ui.icon name="more" size="size-4" /></span>
                    </x-slot:trigger>
                    <button type="button" id="cibi-reassign-signatory-trigger" role="menuitem" data-modal-open="cibi-reassign-signatory-dialog" class="flex min-h-10 w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm font-semibold hover:bg-brand-soft hover:text-brand-primary"><x-ui.icon name="edit" size="size-4" />Reassign Signatory</button>
                </x-ui.context-menu>
            @endif
        </x-slot:footer>
    @endif
</x-ui.module-card>
