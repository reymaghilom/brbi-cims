@props(['id', 'clientFolder', 'report', 'candidates'])
@php($replacementOptions = $candidates->reject(fn ($user) => $user->id === $report->ci_in_charge_id)->pluck('full_name', 'id')->all())

{{-- Reopened on its own validation errors only, using the shared data-open-on-error hook, so a
     rejected reason lands the user back in this dialog with the message rather than dropping them
     on the folder page with no feedback. --}}
<x-ui.modal :id="$id" title="Reassign CI/BI Signatory" description="Change the official Prepared By / CI signatory for this report." size="max-w-md" :data-open-on-error="$errors->hasAny(['new_signatory_id', 'reason']) ? 'true' : 'false'">
    {{-- Which exact person this report belongs to (Applicant vs the exact Co-Maker) is resolved
         from the report's own co_maker_id server-side and baked directly into the form's action
         URL below — the reassignment always targets the correct report internally even though
         that context is no longer shown in this simplified UI. --}}
    <form id="{{ $id }}-form" method="POST" action="{{ route('client-folders.cibi-report.reassign-signatory', [$clientFolder, $report]) }}" data-cibi-reassign-form novalidate>
        @csrf
        {{-- Read-only context, deliberately styled as a value block rather than a field so it
             never reads as something editable. app.js copies this element's textContent into the
             confirmation dialog, so the name stays alone inside it — the icon sits on the label. --}}
        <div class="rounded-control border border-ui-border bg-surface-muted px-3 py-2">
            <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-text-muted"><x-ui.icon name="user" size="size-3.5" aria-hidden="true" />Current Signatory</p>
            <p class="mt-1 break-words text-sm font-semibold leading-5 text-text-main" data-cibi-reassign-current>{{ $report->investigator?->full_name ?? '—' }}</p>
        </div>
        <x-form.select name="new_signatory_id" label="New Signatory" class="mt-4" :options="$replacementOptions" placeholder="Select active Credit Investigator" required data-cibi-reassign-select />
        <x-form.textarea name="reason" label="Reason for reassignment" class="mt-3" rows="3" required placeholder="Enter reason for reassignment" data-cibi-reassign-reason />
        <p class="mt-4 flex items-start gap-2 rounded-control border border-ui-border bg-surface-muted px-3 py-2 text-xs leading-5 text-text-muted"><x-ui.icon name="info" size="size-4" class="mt-0.5 shrink-0 text-text-subtle" aria-hidden="true" /><span>The report creator and audit history will remain unchanged. The selected CI will be assigned as the official Prepared By / Signatory.</span></p>
    </form>
    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary"><x-ui.icon name="close" size="size-4" />Cancel</button>
        <button type="button" class="ui-button-primary" data-cibi-reassign-continue="{{ $id }}-form"><x-ui.icon name="users" size="size-4" />Reassign Signatory</button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal id="{{ $id }}-confirm" title="Confirm Signatory Reassignment" size="max-w-sm">
    <p class="text-sm text-text-main">Reassign signatory from <strong data-cibi-reassign-confirm-from></strong> to <strong data-cibi-reassign-confirm-to></strong>?</p>
    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary"><x-ui.icon name="close" size="size-4" />Cancel</button>
        <button type="button" class="ui-button-primary" data-cibi-reassign-confirm-submit><x-ui.icon name="users" size="size-4" />Yes, Reassign</button>
    </x-slot:footer>
</x-ui.modal>
