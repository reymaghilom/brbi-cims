{{-- The duplicate-template failure is reported INLINE, right under the selector the CI is using,
     rather than as a toast: the choice and its rejection belong in the same place. The wording comes
     from the same authoritative constant the server rejection uses, so the fast client-side refusal
     and the server guard behind it can never say different things. --}}
@props([
    'businessTemplates',
    'id' => 'add-business-template-dialog',
    'selectId' => 'add-business-template-select',
    'usedTemplateIds' => [],
    'duplicateTemplateMessage' => \App\Http\Controllers\IncomeSourceController::DUPLICATE_TEMPLATE_MESSAGE,
])

<x-ui.modal :id="$id" title="Add Business" description="Select a business template to continue." size="max-w-lg" data-add-business-dialog>
    <div>
        <label for="{{ $selectId }}" class="ui-label">Business Template <span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span></label>
        {{-- The templates this exact person already holds a business on. "Next" refuses these
             immediately so the encoding form never opens for a duplicate; the server enforces the
             same rule in IncomeSourceController::launch(), so this is UX, not the protection. --}}
        <select id="{{ $selectId }}" class="ui-control" data-add-business-template-select
                data-used-template-ids="{{ json_encode(array_values($usedTemplateIds ?? [])) }}"
                data-duplicate-template-message="{{ $duplicateTemplateMessage ?? '' }}">
            <option value="">Select a business template</option>
            @foreach($businessTemplates as $template)
                <option value="{{ $template->id }}">{{ $template->name }}</option>
            @endforeach
        </select>
        <p class="mt-2 flex items-start gap-1.5 text-sm font-medium text-danger" role="alert" data-add-business-template-error hidden><x-ui.icon name="warning" size="mt-0.5 size-4" /><span data-add-business-template-error-text data-default-message="Please select a business template to continue.">Please select a business template to continue.</span></p>
    </div>
    <x-slot:footer>
        <button type="button" class="ui-button-secondary" data-modal-close><x-ui.icon name="close" size="size-4" />Cancel</button>
        <button type="button" class="ui-button-primary" data-add-business-next>Next<x-ui.icon name="chevron-right" size="size-4" /></button>
    </x-slot:footer>
</x-ui.modal>
