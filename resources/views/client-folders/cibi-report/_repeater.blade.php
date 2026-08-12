@php
    $existingRows = old($section);
    if ($existingRows === null) {
        $existingRows = $records->map(fn ($record) => collect($fields)->mapWithKeys(fn ($field) => [$field['name'] => data_get($record, $field['name']) instanceof Illuminate\Support\Carbon ? data_get($record, $field['name'])->format('Y-m-d') : data_get($record, $field['name'])])->all() + ['id' => $record->id])->all();
    }
    $existingRows = count($existingRows) ? $existingRows : [[]];
@endphp

<section id="{{ $section }}-section" @class(['cibi-paper-section' => $paper ?? false, 'ui-panel p-5 sm:p-7' => ! ($paper ?? false), 'scroll-mt-24']) data-repeater="{{ $section }}">
    <header @class(['cibi-section-heading' => $paper ?? false, 'mb-5 flex flex-col gap-3 border-b border-ui-border pb-5 sm:flex-row sm:items-start sm:justify-between' => ! ($paper ?? false)])>
        <div><h2 @class(['ui-section-title' => ! ($paper ?? false)])>{{ $title }}</h2><p class="mt-1 text-xs leading-5 text-text-muted sm:text-sm">{{ $description }}</p></div>
        <button type="button" class="ui-button-secondary shrink-0" data-repeater-add>{{ $addLabel ?? 'Add Row' }}</button>
    </header>

    <div class="space-y-4" data-repeater-rows>
        @foreach($existingRows as $index => $row)
            @include('client-folders.cibi-report._repeater-row', ['row' => $row, 'index' => $index, 'template' => false, 'paper' => $paper ?? false])
        @endforeach
    </div>

    <template data-repeater-template>
        @include('client-folders.cibi-report._repeater-row', ['row' => [], 'index' => '__INDEX__', 'template' => true, 'paper' => $paper ?? false])
    </template>
    @error($section)<p class="ui-error mt-3" role="alert">{{ $message }}</p>@enderror
</section>
