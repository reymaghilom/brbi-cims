@extends('layouts.app')

@section('title', $title)

@php
    use App\Services\Settings\EvidenceStorageSetting;

    $options = [
        EvidenceStorageSetting::LOCAL => [
            'label' => 'Local Storage',
            'icon' => 'folder',
            'description' => 'Recommended for pilot testing and slower internet connections. New files are saved in this system\'s protected local storage.',
        ],
        EvidenceStorageSetting::CLOUDINARY => [
            'label' => 'Cloud Storage',
            'icon' => 'cloud',
            'description' => 'New files will be stored in Cloudinary. Requires a reliable internet connection at upload time.',
        ],
    ];
    $target = $evidenceProvider === EvidenceStorageSetting::LOCAL ? EvidenceStorageSetting::CLOUDINARY : EvidenceStorageSetting::LOCAL;
@endphp

@section('content')
    <x-ui.breadcrumb :items="[['label' => 'Dashboard', 'url' => route('home')], ['label' => $title]]" />
    <x-ui.page-header :title="$title" eyebrow="Administration">
        <x-slot:description>System-wide controls for BRBI CIMS. Only administrators can view or change these settings.</x-slot:description>
    </x-ui.page-header>

    <section class="ui-panel overflow-hidden" aria-labelledby="file-storage-title">
        <header class="flex flex-col gap-3 border-b border-ui-border px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6">
            <div class="min-w-0">
                <h2 id="file-storage-title" class="ui-section-title">File Storage</h2>
                <p class="ui-help !mt-1.5 max-w-2xl">Chooses where <span class="font-semibold text-text-main">new</span> Residence Check files, Business Check files, and CI Activity Supporting Proof files are saved. Existing files will not be moved.</p>
            </div>
            <span class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full bg-brand-soft px-3 py-1.5 text-xs font-bold text-brand-primary">
                <x-ui.icon name="check-circle" size="size-3.5" />Current: {{ EvidenceStorageSetting::label($evidenceProvider) }}
            </span>
        </header>

        {{-- The radios are a real form posting to the same admin endpoint. With JavaScript on, the
             global [data-modal-open] handler intercepts the submit button and shows the matching
             confirmation dialog below instead, which then performs the actual POST — so the
             confirm-before-switch workflow is unchanged, and nothing here auto-saves on click. --}}
        <form method="POST" action="{{ route('admin.settings.evidence-storage.update') }}" data-file-storage-form data-file-storage-active="{{ $evidenceProvider }}">
            @csrf
            <fieldset class="px-5 py-5 sm:px-6">
                <legend class="sr-only">Where new files are saved</legend>
                <div class="grid gap-3.5 sm:grid-cols-2">
                    @foreach($options as $value => $option)
                        @php $isActive = $value === $evidenceProvider; @endphp
                        {{-- The whole card is the <label>, so the radio, the icon, the title and the
                             description are all one click target; `has-[:checked]` gives the selected
                             state with no JavaScript at all. --}}
                        <label for="file-storage-{{ $value }}" class="group relative flex cursor-pointer gap-3.5 rounded-card border border-ui-border bg-surface p-4 transition hover:border-brand-primary/60 hover:bg-brand-soft/25 focus-within:ring-2 focus-within:ring-brand-primary/40 has-[:checked]:border-brand-primary has-[:checked]:bg-brand-soft/40 has-[:checked]:shadow-card sm:p-5">
                            <input
                                type="radio"
                                id="file-storage-{{ $value }}"
                                name="provider"
                                value="{{ $value }}"
                                @checked($isActive)
                                data-file-storage-option
                                data-file-storage-label="{{ $option['label'] }}"
                                class="mt-0.5 size-4 shrink-0 cursor-pointer accent-brand-primary"
                            >
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-control bg-surface-muted text-text-muted transition group-has-[:checked]:bg-brand-primary group-has-[:checked]:text-white" aria-hidden="true">
                                        <x-ui.icon :name="$option['icon']" size="size-4" />
                                    </span>
                                    <span class="font-bold text-text-main">{{ $option['label'] }}</span>
                                    @if($isActive)
                                        <span class="rounded-full bg-brand-primary px-2 py-0.5 text-[0.65rem] font-bold uppercase tracking-wide text-white">Active</span>
                                    @endif
                                </span>
                                <span class="mt-2 block text-sm leading-5 text-text-muted">{{ $option['description'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <footer class="flex flex-col gap-3 border-t border-ui-border bg-surface-muted px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-6">
                @if($evidenceSetting?->updated_at)
                    <p class="text-sm leading-5 text-text-muted">Last changed {{ $evidenceSetting->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y g:i A') }}@if($evidenceSetting->updater) by {{ $evidenceSetting->updater->full_name }}@endif.</p>
                @endif
                {{-- Starts on the active option, so it starts as a passive "already selected" state and
                     only becomes a real switch action once the other card is picked. --}}
                <button type="submit" class="ui-button-primary w-full shrink-0 sm:ml-auto sm:w-auto" data-file-storage-submit disabled>
                    <x-ui.icon name="settings" size="size-4" />
                    <span data-file-storage-submit-label>Current setting selected</span>
                </button>
            </footer>
        </form>
    </section>

    @foreach($options as $value => $option)
        <x-ui.confirmation-dialog
            :id="'switch-file-storage-'.$value"
            :title="'Switch File Storage to '.$option['label'].'?'"
            :action="route('admin.settings.evidence-storage.update')"
            :confirm-label="'Switch to '.$option['label']"
        >
            <x-slot:formFields><input type="hidden" name="provider" value="{{ $value }}"></x-slot:formFields>
            @if($value === EvidenceStorageSetting::CLOUDINARY)
                <p class="text-sm leading-6 text-text-muted">New Residence Check files, Business Check files, and CI Activity Supporting Proof files will be saved to Cloud Storage (Cloudinary).</p>
                <p class="mt-2 text-sm leading-6 text-text-muted">Existing local files will remain in Local Storage.</p>
            @else
                <p class="text-sm leading-6 text-text-muted">New Residence Check files, Business Check files, and CI Activity Supporting Proof files will be saved to Local Storage.</p>
                <p class="mt-2 text-sm leading-6 text-text-muted">Existing Cloud Storage files will remain in Cloud Storage.</p>
            @endif
        </x-ui.confirmation-dialog>
    @endforeach

    <script>
        // Keeps the single action button honest about what picking a card would actually do. It
        // never saves anything itself: it only points [data-modal-open] at the matching
        // confirmation dialog, which owns the real POST.
        (() => {
            const form = document.querySelector('[data-file-storage-form]');
            const submit = form?.querySelector('[data-file-storage-submit]');
            const label = submit?.querySelector('[data-file-storage-submit-label]');
            if (!form || !submit || !label) return;

            const active = form.dataset.fileStorageActive;
            const sync = () => {
                const checked = form.querySelector('[data-file-storage-option]:checked');
                if (!checked) return;
                const isActive = checked.value === active;
                submit.disabled = isActive;
                label.textContent = isActive ? 'Current setting selected' : `Switch to ${checked.dataset.fileStorageLabel}`;
                if (isActive) delete submit.dataset.modalOpen;
                else submit.dataset.modalOpen = `switch-file-storage-${checked.value}`;
            };

            form.querySelectorAll('[data-file-storage-option]').forEach((option) => option.addEventListener('change', sync));
            sync();
        })();
    </script>
@endsection
