@php
    $existingRows = old($section);
    if ($existingRows === null) {
        $existingRows = $records->map(fn ($record) => collect($fields)->mapWithKeys(fn ($field) => [$field['name'] => data_get($record, $field['name'])])->all() + ['id' => $record->id])->all();
    }
    $defaultRowCount = max(1, (int) ($defaultRows ?? 1));
    $existingRows = count($existingRows) ? $existingRows : array_fill(0, $defaultRowCount, []);
@endphp

<section id="{{ $section }}-section" class="business-report-section scroll-mt-4" data-repeater="{{ $section }}">
    <header class="business-report-subheading">
        <div>
            <h2>{{ $title }}</h2>
            @if(filled($description ?? null))<p>{{ $description }}</p>@endif
        </div>
        <button type="button" class="business-add-entry" data-repeater-add>+ {{ $addLabel ?? 'Add Row' }}</button>
    </header>

    <div class="business-report-table-wrap">
        <table @class(['business-report-table', $tableClass ?? null])>
            <thead><tr>@foreach($fields as $field)<th scope="col"><span>{{ $field['label'] }}</span>@if(filled($field['guide'] ?? null))<small class="business-report-column-guide">{{ $field['guide'] }}</small>@endif</th>@endforeach<th scope="col" class="business-report-action-heading">Action</th></tr></thead>
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
