@php
    $existingRows = old($section);
    if ($existingRows === null) {
        $existingRows = $records->map(fn ($record) => collect($fields)->mapWithKeys(fn ($field) => [$field['name'] => data_get($record, $field['name'])])->all() + ['id' => $record->id])->all();
    }
    $existingRows = count($existingRows) ? $existingRows : [[]];
@endphp

<section id="{{ $section }}-section" class="business-report-section scroll-mt-4" data-repeater="{{ $section }}">
    <header class="business-report-subheading">
        <div>
            <h2>{{ $title }}</h2>
            @if(filled($description ?? null))<p>{{ $description }}</p>@endif
        </div>
        <button type="button" class="business-add-entry" data-repeater-add>+ {{ $addLabel ?? 'Add Entry' }}</button>
    </header>

    <div class="business-report-table-wrap">
        <table class="business-report-table">
            <thead><tr>@foreach($fields as $field)<th scope="col">{{ $field['label'] }}</th>@endforeach<th scope="col" class="business-report-action-heading">Action</th></tr></thead>
            <tbody data-repeater-rows>
                @foreach($existingRows as $index => $row)
                    @include('client-folders.income-sources._business-repeater-row', ['row' => $row, 'index' => $index, 'template' => false])
                @endforeach
            </tbody>
        </table>
    </div>

    <template data-repeater-template>
        @include('client-folders.income-sources._business-repeater-row', ['row' => [], 'index' => '__INDEX__', 'template' => true])
    </template>
    @error($section)<p class="ui-error business-section-error" role="alert">{{ $message }}</p>@enderror
</section>
