@props(['name', 'label', 'options' => [], 'selected' => null, 'type' => 'checkbox', 'help' => null, 'hover' => true, 'columns' => null])
@php($selectedValues = collect(old($name, $selected ?? []))->map(fn ($value) => (string) $value)->all())
@php($optionColumns = $columns ? array_chunk($options, (int) ceil(count($options) / $columns), true) : [$options])

<fieldset {{ $attributes->class('sm:col-span-2') }}>
    <legend class="ui-label">{{ $label }}</legend>
    @if($help)<p class="mb-3 text-sm text-text-muted">{{ $help }}</p>@endif
    <div @class(['flex flex-wrap gap-3', 'choice-group-columns' => $columns])>
        @foreach($optionColumns as $column)
            <div @class(['choice-group-column' => $columns, 'contents' => ! $columns])>
                @foreach($column as $value => $optionLabel)
                    <label @class(['flex min-h-11 cursor-pointer items-center gap-2.5 rounded-control border border-ui-border bg-surface px-3.5 py-2 text-sm font-medium', 'hover:border-brand-primary hover:bg-brand-soft' => $hover])>
                        <input type="{{ $type }}" name="{{ $type === 'checkbox' ? $name.'[]' : $name }}" value="{{ $value }}" @checked(in_array((string) $value, $selectedValues, true)) class="size-4 border-ui-border-strong text-brand-primary focus:ring-brand-primary">
                        {{ $optionLabel }}
                    </label>
                @endforeach
            </div>
        @endforeach
    </div>
    <x-form.validation-message :for="$name" />
</fieldset>
