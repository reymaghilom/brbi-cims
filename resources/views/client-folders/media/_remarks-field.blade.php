{{-- Remarks input for one documentation panel. $idPrefix keeps the id/name/for attributes unique
     across the Residence and Business panels, which both render this partial on the same page. --}}
<label for="{{ $idPrefix }}-remarks" class="media-field-label"><x-ui.icon name="edit" size="size-3.5" />Remarks <span class="font-normal normal-case tracking-normal text-text-subtle">(Optional)</span></label>
<textarea id="{{ $idPrefix }}-remarks" form="{{ $idPrefix }}-form" name="remarks" rows="3" maxlength="1000" class="ui-control !min-h-0 resize-y" @if($category === 'business') placeholder="e.g. Store is well maintained." @endif>{{ $formHasErrors ? old('remarks') : $activeDocument?->remarks }}</textarea>
<p class="media-help">Include only when additional field notes are needed.</p>
@if($formHasErrors)
    <x-form.validation-message for="remarks" />
@endif
