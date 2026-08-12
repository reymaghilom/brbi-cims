@php
    $rowId = data_get($row, 'id');
    $categoryValue = data_get($row, 'category', $category ?? 'residence');
    $categoryValue = $categoryValue instanceof BackedEnum ? $categoryValue->value : (string) $categoryValue;
    $categoryLabel = $categoryValue === 'business' ? 'Business' : ($categoryValue === 'other' ? 'Other' : 'Residence');
    $defaultOrder = is_numeric($index) ? (int) $index + 1 : 1;
    $rowMedia = data_get($row, 'media');
    if ($rowMedia === null && $row instanceof App\Models\PhotoReportSection) {
        $rowMedia = $row->mediaItems->map(fn ($item) => ['media_reference_id' => $item->media_reference_id, 'selected' => true, 'output_label' => $item->output_label, 'caption' => $item->caption, 'sort_order' => $item->sort_order])->all();
    }
    $rowMedia = collect((array) $rowMedia)->keyBy(fn ($item) => (string) data_get($item, 'media_reference_id'));
@endphp

<fieldset class="rounded-panel border border-ui-border bg-surface p-5 shadow-card sm:p-6" data-photo-section data-category="{{ $categoryValue }}" @if(data_get($row, '_delete')) hidden @endif>
    <legend class="px-2 text-base font-bold text-brand-sidebar"><span data-section-category-label>{{ $categoryLabel }}</span> Documentation Section</legend>
    <input type="hidden" name="sections[{{ $index }}][id]" value="{{ $rowId }}">
    <input type="hidden" name="sections[{{ $index }}][_delete]" value="{{ data_get($row, '_delete', 0) }}" data-delete-field>
    <input type="hidden" name="sections[{{ $index }}][category]" value="{{ $categoryValue }}" data-section-category>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div><label class="ui-label" for="sections-{{ $index }}-sort">Section Order</label><input class="ui-control" id="sections-{{ $index }}-sort" name="sections[{{ $index }}][sort_order]" type="number" min="1" max="1000" value="{{ data_get($row, 'sort_order', $defaultOrder) }}"><x-form.validation-message for="sections.{{ $index }}.sort_order" /></div>
        <div><label class="ui-label" for="sections-{{ $index }}-party">Report Subject</label><select class="ui-control" id="sections-{{ $index }}-party" name="sections[{{ $index }}][subject_party]"><option value="applicant" @selected(data_get($row, 'subject_party', 'applicant') === 'applicant')>Applicant</option><option value="co_maker" @selected(data_get($row, 'subject_party') === 'co_maker')>Co-maker</option><option value="other" @selected(data_get($row, 'subject_party') === 'other')>Other</option></select><x-form.validation-message for="sections.{{ $index }}.subject_party" /></div>
        <div><label class="ui-label" for="sections-{{ $index }}-subject-name">Subject Name</label><input class="ui-control" id="sections-{{ $index }}-subject-name" name="sections[{{ $index }}][subject_name]" value="{{ data_get($row, 'subject_name') }}" placeholder="Required for co-maker or other"><x-form.validation-message for="sections.{{ $index }}.subject_name" /></div>
        <div class="sm:col-span-2"><label class="ui-label" for="sections-{{ $index }}-heading">Subject / Heading</label><input class="ui-control" id="sections-{{ $index }}-heading" name="sections[{{ $index }}][heading_subject]" value="{{ data_get($row, 'heading_subject', $categoryValue === 'business' ? 'Business Check' : 'Residence Check') }}" data-section-heading><x-form.validation-message for="sections.{{ $index }}.heading_subject" /></div>
        <div data-section-business-only @if($categoryValue !== 'business') hidden @endif><label class="ui-label" for="sections-{{ $index }}-source">Linked Income Source</label><select class="ui-control" id="sections-{{ $index }}-source" name="sections[{{ $index }}][income_source_id]" data-section-income-source><option value="">Select income source</option>@foreach($incomeSources as $source)<option value="{{ $source->id }}" @selected((string) data_get($row, 'income_source_id') === (string) $source->id)>{{ $source->source_name }}</option>@endforeach</select><x-form.validation-message for="sections.{{ $index }}.income_source_id" /></div>
        <div data-section-business-only @if($categoryValue !== 'business') hidden @endif><label class="ui-label" for="sections-{{ $index }}-business">Business Name</label><input class="ui-control" id="sections-{{ $index }}-business" name="sections[{{ $index }}][business_name]" value="{{ data_get($row, 'business_name') }}"><x-form.validation-message for="sections.{{ $index }}.business_name" /></div>
        <div class="sm:col-span-2 lg:col-span-3"><label class="ui-label" for="sections-{{ $index }}-location">Location / Address</label><textarea class="ui-control" id="sections-{{ $index }}-location" name="sections[{{ $index }}][location]" rows="2">{{ data_get($row, 'location') }}</textarea><x-form.validation-message for="sections.{{ $index }}.location" /></div>
        <div class="sm:col-span-2"><label class="ui-label" for="sections-{{ $index }}-map">Google Maps Link</label><input class="ui-control" id="sections-{{ $index }}-map" name="sections[{{ $index }}][google_maps_link]" type="url" value="{{ data_get($row, 'google_maps_link') }}" placeholder="https://maps.google.com/..."><x-form.validation-message for="sections.{{ $index }}.google_maps_link" /></div>
        <div class="sm:col-span-2 lg:col-span-3"><label class="ui-label" for="sections-{{ $index }}-remarks">Findings / Remarks</label><textarea class="ui-control" id="sections-{{ $index }}-remarks" name="sections[{{ $index }}][remarks]" rows="4">{{ data_get($row, 'remarks') }}</textarea><x-form.validation-message for="sections.{{ $index }}.remarks" /></div>
    </div>

    <section class="mt-6 border-t border-ui-border pt-5" aria-labelledby="section-{{ $index }}-media-title">
        <div><h3 id="section-{{ $index }}-media-title" class="font-bold">Existing Media References</h3><p class="mt-1 text-sm text-text-muted">Select existing {{ strtolower($categoryLabel) }} evidence, then control its output label, caption, and order. Upload is not available in this phase.</p></div>
        @if($mediaReferences->isEmpty())
            <div class="mt-4 rounded-card border border-dashed border-ui-border-strong p-4 text-sm text-text-muted">No existing media references are available in this folder.</div>
        @else
            <div class="mt-4 grid gap-3 lg:grid-cols-2" data-section-media-list>
                @foreach($mediaReferences as $mediaIndex => $media)
                    @php
                        $selection = $rowMedia->get((string) $media->id);
                        $selected = (bool) data_get($selection, 'selected', false);
                    @endphp
                    <article class="rounded-card border border-ui-border bg-surface-subtle p-4" data-section-media data-media-category="{{ $media->category->value }}" data-media-income-source="{{ $media->income_source_id }}" @if($media->category->value !== $categoryValue) hidden @endif>
                        <label class="flex cursor-pointer items-start gap-3"><input type="hidden" name="sections[{{ $index }}][media][{{ $mediaIndex }}][media_reference_id]" value="{{ $media->id }}"><input type="hidden" name="sections[{{ $index }}][media][{{ $mediaIndex }}][selected]" value="0"><input type="checkbox" name="sections[{{ $index }}][media][{{ $mediaIndex }}][selected]" value="1" @checked($selected) class="mt-1 size-4 rounded border-ui-border-strong text-brand-primary focus:ring-brand-primary"><span><span class="block font-bold">{{ $media->label ?: $media->file_name }}</span><span class="block text-xs text-text-muted">{{ str($media->media_type->value)->title() }}@if($media->incomeSource) · {{ $media->incomeSource->source_name }}@endif</span></span></label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-3"><div><label class="ui-label" for="sections-{{ $index }}-media-{{ $mediaIndex }}-order">Order</label><input class="ui-control" id="sections-{{ $index }}-media-{{ $mediaIndex }}-order" name="sections[{{ $index }}][media][{{ $mediaIndex }}][sort_order]" type="number" min="1" value="{{ data_get($selection, 'sort_order', $mediaIndex + 1) }}"></div><div class="sm:col-span-2"><label class="ui-label" for="sections-{{ $index }}-media-{{ $mediaIndex }}-label">Output Label</label><input class="ui-control" id="sections-{{ $index }}-media-{{ $mediaIndex }}-label" name="sections[{{ $index }}][media][{{ $mediaIndex }}][output_label]" value="{{ data_get($selection, 'output_label', $media->label) }}"></div><div class="sm:col-span-3"><label class="ui-label" for="sections-{{ $index }}-media-{{ $mediaIndex }}-caption">Caption</label><textarea class="ui-control" id="sections-{{ $index }}-media-{{ $mediaIndex }}-caption" name="sections[{{ $index }}][media][{{ $mediaIndex }}][caption]" rows="2">{{ data_get($selection, 'caption') }}</textarea></div></div>
                        <x-form.validation-message for="sections.{{ $index }}.media.{{ $mediaIndex }}.media_reference_id" /><x-form.validation-message for="sections.{{ $index }}.media.{{ $mediaIndex }}.sort_order" />
                    </article>
                @endforeach
            </div>
        @endif
        <x-form.validation-message for="sections.{{ $index }}.media" />
    </section>
    <button type="button" class="ui-button-danger mt-5" data-photo-section-remove>{{ $rowId ? 'Remove Saved Section' : 'Remove Section' }}</button>
</fieldset>
