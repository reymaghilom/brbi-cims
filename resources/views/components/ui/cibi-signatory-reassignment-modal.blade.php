@props(['id', 'clientFolder', 'report', 'candidates'])
@php($replacementOptions = $candidates->reject(fn ($user) => $user->id === $report->ci_in_charge_id)->pluck('full_name', 'id')->all())

<x-ui.modal :id="$id" title="Reassign CI/BI Signatory" description="Change the official Prepared By / CI signatory for this report." size="max-w-md">
    {{-- Which exact person this report belongs to (Applicant vs the exact Co-Maker) is resolved
         from the report's own co_maker_id server-side and baked directly into the form's action
         URL below — the reassignment always targets the correct report internally even though
         that context is no longer shown in this simplified UI. --}}
    <form id="{{ $id }}-form" method="POST" action="{{ route('client-folders.cibi-report.reassign-signatory', [$clientFolder, $report]) }}" data-cibi-reassign-form novalidate>
        @csrf
        <div class="text-sm">
            <p class="ui-label">Current Signatory</p>
            <p class="font-semibold text-text-main" data-cibi-reassign-current>{{ $report->investigator?->full_name ?? '—' }}</p>
        </div>
        <x-form.select name="new_signatory_id" label="New Signatory" class="mt-3" :options="$replacementOptions" placeholder="Select active Credit Investigator" required data-cibi-reassign-select />
        <x-form.textarea name="reason" label="Reason for reassignment" class="mt-3" rows="3" required data-cibi-reassign-reason />
        <p class="mt-3 rounded-control bg-surface-muted p-2.5 text-xs leading-5 text-text-muted">The report creator and previous audit history will remain unchanged. The selected CI will become the new official Prepared By / Signatory.</p>
    </form>
    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
        <button type="button" class="ui-button-primary" data-cibi-reassign-continue="{{ $id }}-form">Reassign Signatory</button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.modal id="{{ $id }}-confirm" title="Confirm Signatory Reassignment" size="max-w-sm">
    <p class="text-sm text-text-main">Reassign signatory from <strong data-cibi-reassign-confirm-from></strong> to <strong data-cibi-reassign-confirm-to></strong>?</p>
    <x-slot:footer>
        <button type="button" data-modal-close class="ui-button-secondary">Cancel</button>
        <button type="button" class="ui-button-primary" data-cibi-reassign-confirm-submit>Yes, Reassign</button>
    </x-slot:footer>
</x-ui.modal>
