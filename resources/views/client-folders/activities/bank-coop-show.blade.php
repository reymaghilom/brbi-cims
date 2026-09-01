@extends('layouts.app')

@section('title', 'Bank / Coop Check')

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $completedCount = $activity->bankTargets->where('status', App\Enums\ActivityStatus::Completed)->count();
        $targetCount = $activity->bankTargets->count();
    @endphp

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities', 'url' => route('client-folders.activities.index', [$clientFolder] + $personParams)],
        ['label' => 'Bank / Coop Check'],
    ]" />

    <x-ui.page-header title="Bank / Coop Check">
        <x-slot:description>
            {{ $activePerson ? 'Co-Maker: '.$activePerson->full_name : 'Applicant: '.$clientFolder->display_name }}
        </x-slot:description>
        <x-slot:actions>
            <button type="button" class="ui-button-primary" data-modal-open="add-bank-target"><x-ui.icon name="plus" size="size-4" />Add Bank / Coop</button>
            <a href="{{ route('client-folders.activities.index', [$clientFolder] + $personParams) }}" class="ui-button-secondary">All Activities</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div
        data-bank-coop-modal-source
        data-bank-coop-activity-id="{{ $activity->id }}"
        data-bank-coop-context="{{ $activePerson ? 'Co-Maker: '.$activePerson->full_name : 'Applicant: '.$clientFolder->display_name }}"
        data-bank-coop-target-count="{{ $targetCount }}"
        data-bank-coop-completed-count="{{ $completedCount }}"
        data-bank-coop-status="{{ $activity->status->value }}"
        data-bank-coop-status-label="{{ $activity->status->label() }}"
    >
    <section class="ui-panel p-4 sm:p-5 lg:p-6" aria-labelledby="bank-targets-title">
        <div class="flex flex-col gap-3 border-b border-ui-border pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 id="bank-targets-title" class="ui-section-title">Banks / Cooperatives</h2>
                <p class="mt-1 text-sm text-text-muted">Each institution keeps its own status and schedule.</p>
            </div>
            <div class="w-fit rounded-full bg-brand-soft px-3 py-1.5 text-sm font-bold text-brand-primary">{{ $completedCount }} of {{ $targetCount }} Completed</div>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-2" data-bank-target-list>
            @forelse($activity->bankTargets as $target)
                @php
                    $localSchedule = $target->scheduled_at?->timezone(config('cims.display_timezone'));
                    $targetCompleted = $target->status === App\Enums\ActivityStatus::Completed;
                    $targetLabel = $target->institution_name.($target->branch_location ? ' – '.$target->branch_location : '');
                @endphp
                <article class="rounded-card border border-ui-border bg-surface p-4 shadow-sm" data-bank-target-card="{{ $target->id }}">
                    <div class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            class="mt-1 size-4 shrink-0 rounded border-ui-border text-success focus:ring-success"
                            aria-label="{{ $targetCompleted ? $targetLabel.' is completed' : 'Mark '.$targetLabel.' as completed' }}"
                            data-bank-target-checkbox="{{ $target->id }}"
                            @checked($targetCompleted)
                            @disabled($targetCompleted)
                            @if(! $targetCompleted) data-bank-target-complete data-modal-open="complete-bank-target-{{ $target->id }}" aria-controls="complete-bank-target-{{ $target->id }}" @endif
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="break-words text-base font-bold text-brand-sidebar">{{ $target->institution_name }}@if($target->branch_location) <span class="font-normal text-text-muted">&mdash; {{ $target->branch_location }}</span>@endif</h3>
                                    <span class="mt-1.5 inline-flex rounded-full bg-brand-soft px-2.5 py-1 text-xs font-bold text-brand-primary">{{ $target->inquiryTypeLabel() }}</span>
                                </div>
                                <x-ui.status-badge :status="$target->status" />
                            </div>
                        </div>
                    </div>

                    @if($localSchedule)
                        <p class="mt-3 flex items-center gap-1.5 text-sm font-semibold text-text-main"><x-ui.icon name="calendar" size="size-4 text-text-muted" />{{ $localSchedule->format('M j, Y') }} <span class="font-normal text-text-muted">· {{ $target->scheduled_has_time ? $localSchedule->format('g:i A') : 'No specific time' }}</span></p>
                    @elseif($target->status === App\Enums\ActivityStatus::Scheduled)
                        <p class="mt-3 flex items-center gap-1.5 text-sm text-text-muted"><x-ui.icon name="calendar" size="size-4" />No schedule set</p>
                    @elseif($target->status === App\Enums\ActivityStatus::FollowUp)
                        <p class="mt-3 flex items-center gap-1.5 text-sm text-text-muted"><x-ui.icon name="calendar" size="size-4" />No follow-up date set</p>
                    @endif
                    @if($target->remarks)
                        <p class="mt-3 whitespace-pre-line text-sm leading-6 text-text-muted">{{ $target->remarks }}</p>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-ui-border pt-3">
                        @if($target->status === App\Enums\ActivityStatus::FollowUp)
                            <button type="button" class="ui-button-secondary-compact" data-modal-open="edit-bank-target-{{ $target->id }}" data-bank-target-follow-up="{{ $target->id }}"><x-ui.icon name="calendar" size="size-3.5" />Schedule Follow-up</button>
                        @endif
                        <button type="button" class="ui-button-secondary-compact" data-modal-open="edit-bank-target-{{ $target->id }}"><x-ui.icon name="edit" size="size-3.5" />Edit</button>
                        <button type="button" class="ui-button-danger-compact" data-modal-open="delete-bank-target-{{ $target->id }}"><x-ui.icon name="trash" size="size-3.5" />Delete</button>
                    </div>
                </article>
            @empty
                <div class="rounded-control border border-dashed border-ui-border-strong bg-surface-subtle p-6 text-center lg:col-span-2">
                    <p class="font-semibold text-text-main">No Bank / Coop targets yet.</p>
                    <p class="mt-1 text-sm text-text-muted">Add the first institution under this activity.</p>
                </div>
            @endforelse
        </div>
    </section>

    <dialog id="add-bank-target" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45">
        <form method="POST" action="{{ route('client-folders.activities.bank-targets.store', [$clientFolder, $activity]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-bank-target-form>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
            <div class="flex items-start justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6"><div><h2 class="text-lg font-bold text-brand-sidebar">Add Bank / Coop</h2><p class="mt-1 text-sm text-text-muted">Add another institution to this activity.</p></div><button type="button" class="ui-icon-button -mr-2" data-modal-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                @if($bankInstitutionPrefillCandidates !== [])
                    <div class="mb-4 rounded-control border border-brand-primary/20 bg-brand-soft/60 p-3" data-bank-target-prefill-list>
                        <p class="text-xs font-bold text-brand-sidebar">Available from this person&rsquo;s CIBI report</p>
                        <p class="mt-1 text-xs leading-5 text-text-muted">Choose a candidate to fill empty fields. Existing values are never replaced.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($bankInstitutionPrefillCandidates as $candidate)
                                <button type="button" class="ui-button-secondary-compact !text-left" data-bank-target-prefill data-inquiry-type="{{ $candidate['inquiry_type'] }}" data-institution="{{ $candidate['institution_name'] }}" data-branch="{{ $candidate['branch_location'] }}" title="{{ $candidate['source'] }}">
                                    {{ App\Models\CiActivityBankTarget::INQUIRY_TYPES[$candidate['inquiry_type']] }} &middot; {{ $candidate['institution_name'] }}@if($candidate['branch_location']) <span class="font-normal text-text-muted">&mdash; {{ $candidate['branch_location'] }}</span>@endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="add-inquiry-type" class="ui-label">Inquiry Type</label><select id="add-inquiry-type" name="inquiry_type" class="ui-control" required data-bank-target-detail-inquiry-type><option value="">Select inquiry type</option>@foreach(App\Models\CiActivityBankTarget::INQUIRY_TYPES as $value => $label)<option value="{{ $value }}" @selected(old('inquiry_type') === $value)>{{ $label }}</option>@endforeach</select><x-form.validation-message for="inquiry_type" /></div>
                    <div><label for="add-institution-name" class="ui-label">Bank / Coop Name</label><input id="add-institution-name" name="institution_name" value="{{ old('institution_name') }}" class="ui-control" maxlength="255" required><x-form.validation-message for="institution_name" /></div>
                    <div data-bank-target-detail-branch-field><label for="add-branch-location" class="ui-label">Branch / Location <span class="font-normal text-text-muted">(optional)</span></label><input id="add-branch-location" name="branch_location" value="{{ old('branch_location') }}" class="ui-control" maxlength="255" data-bank-target-detail-branch><x-form.validation-message for="branch_location" /></div>
                    <div><label for="add-target-status" class="ui-label">Status</label><select id="add-target-status" name="status" class="ui-control" required data-bank-target-detail-status>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(old('status', 'pending') === $status->value)>{{ $status->label() }}</option>@endforeach</select><x-form.validation-message for="status" /></div>
                    <div class="grid gap-3 sm:grid-cols-2" data-bank-target-detail-schedule><div><label for="add-target-date" class="ui-label">Schedule Date <span class="font-normal text-text-muted">(optional)</span></label><input id="add-target-date" name="scheduled_at" type="date" value="{{ old('scheduled_at') }}" class="ui-control" data-bank-target-detail-date><x-form.validation-message for="scheduled_at" /></div><div><label for="add-target-time" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="add-target-time" name="scheduled_time" type="time" value="{{ old('scheduled_time') }}" class="ui-control" data-bank-target-detail-time><x-form.validation-message for="scheduled_time" /></div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Date and time are optional. Select a date to enable a specific time.</p></div>
                    <div class="sm:col-span-2"><label for="add-target-remarks" class="ui-label">Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="add-target-remarks" name="remarks" rows="3" class="ui-control">{{ old('remarks') }}</textarea><x-form.validation-message for="remarks" /></div>
                </div>
            </div>
            <div class="flex flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="submit" class="ui-button-primary">Add Bank / Coop</button></div>
        </form>
    </dialog>

    @foreach($activity->bankTargets as $target)
        @php
            $editSchedule = $target->scheduled_at?->timezone(config('cims.display_timezone'));
            $completionLabel = $target->institution_name.($target->branch_location ? ' – '.$target->branch_location : '');
        @endphp
        @if($target->status !== App\Enums\ActivityStatus::Completed)
            <x-ui.confirmation-dialog id="complete-bank-target-{{ $target->id }}" title="Mark as completed?" :action="route('client-folders.activities.bank-targets.complete', [$clientFolder, $activity, $target])" method="PATCH" confirm-label="Mark Completed">
                <div class="space-y-2">
                    <p class="font-semibold text-text-main">Mark {{ $completionLabel }} as completed?</p>
                    <p>This confirms that the {{ $target->inquiryTypeLabel() }} for this institution has been completed.</p>
                </div>
                <x-slot:formFields><input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"></x-slot:formFields>
            </x-ui.confirmation-dialog>
        @endif
        <dialog id="edit-bank-target-{{ $target->id }}" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45">
            <form method="POST" action="{{ route('client-folders.activities.bank-targets.update', [$clientFolder, $activity, $target]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-bank-target-form data-bank-target-edit-form="{{ $target->id }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                <div class="flex items-start justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6"><div><h2 class="text-lg font-bold text-brand-sidebar">Edit Bank / Coop</h2><p class="mt-1 truncate text-sm text-text-muted">{{ $target->institution_name }}</p></div><button type="button" class="ui-icon-button -mr-2" data-modal-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div>
                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="inquiry-type-{{ $target->id }}" class="ui-label">Inquiry Type</label><select id="inquiry-type-{{ $target->id }}" name="inquiry_type" class="ui-control" required data-bank-target-detail-inquiry-type>@foreach(App\Models\CiActivityBankTarget::INQUIRY_TYPES as $value => $label)<option value="{{ $value }}" @selected($target->inquiry_type === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div><label for="institution-name-{{ $target->id }}" class="ui-label">Bank / Coop Name</label><input id="institution-name-{{ $target->id }}" name="institution_name" value="{{ $target->institution_name }}" class="ui-control" maxlength="255" required></div>
                        <div data-bank-target-detail-branch-field @if($target->inquiry_type === App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY) hidden @endif><label for="branch-location-{{ $target->id }}" class="ui-label">Branch / Location <span class="font-normal text-text-muted">(optional)</span></label><input id="branch-location-{{ $target->id }}" name="branch_location" value="{{ $target->branch_location }}" class="ui-control" maxlength="255" data-bank-target-detail-branch @disabled($target->inquiry_type === App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY)></div>
                        <div><label for="target-status-{{ $target->id }}" class="ui-label">Status</label><select id="target-status-{{ $target->id }}" name="status" class="ui-control" required data-bank-target-detail-status>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($target->status === $status)>{{ $status->label() }}</option>@endforeach</select></div>
                        <div class="grid gap-3 sm:grid-cols-2" data-bank-target-detail-schedule><div><label for="target-date-{{ $target->id }}" class="ui-label">Schedule Date <span class="font-normal text-text-muted">(optional)</span></label><input id="target-date-{{ $target->id }}" name="scheduled_at" type="date" value="{{ $editSchedule?->format('Y-m-d') }}" class="ui-control" data-bank-target-detail-date></div><div><label for="target-time-{{ $target->id }}" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="target-time-{{ $target->id }}" name="scheduled_time" type="time" value="{{ $target->scheduled_has_time ? $editSchedule?->format('H:i') : '' }}" class="ui-control" data-bank-target-detail-time></div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Date and time are optional. Select a date to enable a specific time.</p></div>
                        <div class="sm:col-span-2"><label for="target-remarks-{{ $target->id }}" class="ui-label">Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="target-remarks-{{ $target->id }}" name="remarks" rows="3" class="ui-control">{{ $target->remarks }}</textarea></div>
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-modal-close>Cancel</button><button type="submit" class="ui-button-primary">Save Target</button></div>
            </form>
        </dialog>

        <x-ui.confirmation-dialog id="delete-bank-target-{{ $target->id }}" title="Delete Bank / Coop Target?" :action="route('client-folders.activities.bank-targets.destroy', [$clientFolder, $activity, $target])" method="DELETE" confirm-label="Delete Target" destructive>
            <p><span class="font-semibold text-text-main">{{ $target->institution_name }}</span> will be deleted from this Bank / Coop Check. The parent activity will remain.</p>
            <x-slot:formFields><input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"></x-slot:formFields>
        </x-ui.confirmation-dialog>
    @endforeach
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const syncForm = (form) => {
                const inquiryType = form.querySelector('[data-bank-target-detail-inquiry-type]');
                const branchField = form.querySelector('[data-bank-target-detail-branch-field]');
                const branch = form.querySelector('[data-bank-target-detail-branch]');
                const status = form.querySelector('[data-bank-target-detail-status]');
                const date = form.querySelector('[data-bank-target-detail-date]');
                const time = form.querySelector('[data-bank-target-detail-time]');
                if (!(inquiryType instanceof HTMLSelectElement) || !(branchField instanceof HTMLElement) || !(branch instanceof HTMLInputElement)
                    || !(status instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;

                const sync = () => {
                    const isLoanInquiry = inquiryType.value === @js(App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);
                    branchField.hidden = isLoanInquiry;
                    if (isLoanInquiry) branch.value = '';
                    branch.disabled = isLoanInquiry;
                    const supportsSchedule = ['scheduled', 'follow_up'].includes(status.value);
                    if (! supportsSchedule) {
                        date.value = '';
                        time.value = '';
                    }
                    date.disabled = ! supportsSchedule;
                    date.required = false;
                    const timeEnabled = supportsSchedule && date.value !== '';
                    if (! timeEnabled) time.value = '';
                    time.disabled = ! timeEnabled;
                };

                status.addEventListener('change', sync);
                inquiryType.addEventListener('change', sync);
                date.addEventListener('input', sync);
                sync();
            };

            document.querySelectorAll('[data-bank-target-form]').forEach((form) => {
                if (form instanceof HTMLFormElement) syncForm(form);
            });
            document.querySelectorAll('[data-bank-target-prefill]').forEach((button) => {
                button.addEventListener('click', () => {
                    const dialog = button.closest('dialog');
                    const institution = dialog?.querySelector('[name="institution_name"]');
                    const inquiryType = dialog?.querySelector('[name="inquiry_type"]');
                    const branch = dialog?.querySelector('[name="branch_location"]');
                    if (!(inquiryType instanceof HTMLSelectElement) || !(institution instanceof HTMLInputElement) || !(branch instanceof HTMLInputElement)) return;

                    const candidateInstitution = button.dataset.institution ?? '';
                    const normalize = (value) => value.toLocaleLowerCase().replace(/\s+/g, ' ').trim();
                    if (institution.value.trim() !== '' && normalize(institution.value) !== normalize(candidateInstitution)) return;
                    if (institution.value.trim() === '') institution.value = candidateInstitution;
                    if (inquiryType.value === '') inquiryType.value = button.dataset.inquiryType ?? '';
                    if (branch.value.trim() === '') branch.value = button.dataset.branch ?? '';
                    inquiryType.dispatchEvent(new Event('change', { bubbles: true }));
                    institution.dispatchEvent(new Event('input', { bubbles: true }));
                    institution.focus();
                });
            });
            document.querySelectorAll('[data-bank-target-follow-up]').forEach((button) => {
                button.addEventListener('click', () => {
                    const form = document.querySelector(`[data-bank-target-edit-form="${button.dataset.bankTargetFollowUp}"]`);
                    const status = form?.querySelector('[data-bank-target-detail-status]');
                    if (status instanceof HTMLSelectElement) {
                        status.value = 'follow_up';
                        status.dispatchEvent(new Event('change'));
                    }
                });
            });
            document.querySelectorAll('[data-bank-target-complete]').forEach((checkbox) => {
                checkbox.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    checkbox.checked = false;
                    const dialog = document.getElementById(checkbox.dataset.modalOpen ?? '');
                    if (dialog instanceof HTMLDialogElement && ! dialog.open) dialog.showModal();
                });
            });
        });
    </script>
@endsection
