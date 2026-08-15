@php
    $isDistributorTemplate = $template->template_type === 'distributorship_wholesaler_b2b';
    $isPharmacyTemplate = $template->template_type === 'pharmacy_drugstore';
    $isGeneralMerchandiseTemplate = $template->template_type === 'general_merchandise_hardware_parts';
    $isBuySellDryGoodsTemplate = $template->template_type === 'buy_sell_dry_goods';
    $isMeatshopTemplate = $template->template_type === 'meatshop_store';
    $isContractorTemplate = $template->template_type === 'contractor_subcontractor';
    $isRestaurantTemplate = $template->template_type === 'restaurant_food_stall';
    $isRemittanceTemplate = $template->template_type === 'remittance_income';
    $isOtherBusinessSourceTemplate = $template->template_type === 'other_business_source_of_income';
    $schemaFields = collect($schema['fields'] ?? [])->reject(function (array $field) use ($template, $isDistributorTemplate, $isOtherBusinessSourceTemplate): bool {
        $truckEmployeeField = in_array($template->template_type, ['leasing_truck_equipment', 'trucking_services'], true)
            && in_array($field['key'], ['operators_count', 'drivers_count', 'helpers_count'], true);
        $distributorStockroomField = $isDistributorTemplate && in_array($field['key'], ['office_staff', 'agents', 'drivers', 'helpers', 'inventory_level', 'products_seen', 'top_brands', 'top_brand_prices'], true);

        return $truckEmployeeField || $distributorStockroomField || $isOtherBusinessSourceTemplate;
    });
@endphp
@if($isOtherBusinessSourceTemplate)
    @include('client-folders.income-sources._business-other-income-source')
@endif
@if($isDistributorTemplate)
    @include('client-folders.income-sources._business-distributor-stockroom')
@endif

@if(!empty($schema['fields']))
    <section class="business-template-field-grid" aria-label="Template details">
        @foreach($schemaFields as $field)
            @php
                $name = "template_data.fields.{$field['key']}";
                $inputName = "template_data[fields][{$field['key']}]";
                $value = old("template_data.fields.{$field['key']}", data_get($report?->template_data, "fields.{$field['key']}"));
                $type = $field['type'] ?? 'text';
                $isRequired = in_array($field['key'], $schema['required_fields'] ?? [], true);
            @endphp
            @if($type === 'checkbox')
                <div class="business-template-field business-report-choice-field">
                    <span class="ui-label">{{ $field['label'] }}</span>
                    <input type="hidden" name="{{ $inputName }}" value="0">
                    <label class="business-report-choice-option"><input type="checkbox" name="{{ $inputName }}" value="1" @checked((bool) $value) class="business-report-checkbox"> <span>Yes</span></label>
                    <x-form.validation-message :for="$name" />
                </div>
            @elseif($type === 'radio')
                <div @class([
                    'business-template-field business-report-choice-field',
                    'business-general-store-type' => (($isGeneralMerchandiseTemplate || $isBuySellDryGoodsTemplate) && $field['key'] === 'store_type') || ($isRestaurantTemplate && $field['key'] === 'scale_of_business'),
                    'business-restaurant-scale' => $isRestaurantTemplate && $field['key'] === 'scale_of_business',
                ])>
                    <span class="ui-label">{{ $field['label'] }}</span>
                    <div class="business-report-choice-group" role="radiogroup" aria-label="{{ $field['label'] }}">
                        @foreach($field['options'] ?? [] as $optionValue => $optionLabel)
                            @if($isRestaurantTemplate && $field['key'] === 'scale_of_business' && $loop->iteration === 5)
                                <span class="business-restaurant-mall-label">/ MALL OPERATIONS:</span>
                            @endif
                            <label class="business-report-choice-option"><input type="radio" name="{{ $inputName }}" value="{{ $optionValue }}" @checked((string) $value === (string) $optionValue) class="business-report-checkbox"> <span>{{ $optionLabel }}</span></label>
                        @endforeach
                    </div>
                    <x-form.validation-message :for="$name" />
                </div>
            @elseif($type === 'select')
                <x-form.select class="business-template-field" :name="$name" :input-name="$inputName" :label="$field['label']" :options="$field['options'] ?? []" :selected="$value" placeholder="Select" />
            @elseif($type === 'textarea')
                <x-form.textarea :name="$name" :input-name="$inputName" :label="$field['label']" :value="$value" rows="3" class="business-template-field business-template-field-wide" />
            @else
                <x-form.input class="business-template-field" :name="$name" :input-name="$inputName" :label="$field['label']" :label-guide="$field['guide'] ?? null" :type="$type" :value="$value" :step="$type === 'number' ? 'any' : null" :min="$type === 'number' ? 0 : null" :required="$isRequired" />
            @endif
        @endforeach
    </section>
@endif

@php
    $schemaTables = collect($schema['tables'] ?? []);
    $hasQuestions = !empty($schema['questions']);
    $primaryTables = $hasQuestions ? $schemaTables->reject(fn (array $table) => $table['key'] === 'suppliers') : $schemaTables;
    if ($isDistributorTemplate) {
        $primaryTables = $primaryTables->reject(fn (array $table) => $table['key'] === 'products');
    }
    if ($isPharmacyTemplate || $isGeneralMerchandiseTemplate || $isBuySellDryGoodsTemplate) {
        $primaryTables = $primaryTables->reject(fn (array $table) => $table['key'] === 'products');
    }
    if ($isMeatshopTemplate) {
        $primaryTables = $primaryTables->reject(fn (array $table) => $table['key'] === 'inventory');
    }
    $supplierTables = $hasQuestions && ! $isContractorTemplate ? $schemaTables->filter(fn (array $table) => $table['key'] === 'suppliers') : collect();
@endphp

@foreach($primaryTables as $table)
    @if(in_array($template->template_type, ['leasing_truck_equipment', 'trucking_services'], true) && $table['key'] === 'suppliers')
        @include('client-folders.income-sources._business-truck-employees-suppliers')
    @else
        @include('client-folders.income-sources._business-schema-table')
    @endif
@endforeach

@if($isDistributorTemplate)
    @include('client-folders.income-sources._business-distributor-interview-products', ['table' => $schemaTables->firstWhere('key', 'products')])
@elseif($isPharmacyTemplate || $isGeneralMerchandiseTemplate || $isBuySellDryGoodsTemplate)
    @include('client-folders.income-sources._business-pharmacy-products-observations', ['table' => $schemaTables->firstWhere('key', 'products')])
@elseif($isMeatshopTemplate)
    @include('client-folders.income-sources._business-meatshop-inventory-observations', ['table' => $schemaTables->firstWhere('key', 'inventory')])
@elseif($isContractorTemplate)
    @include('client-folders.income-sources._business-contractor-validations', ['supplierTable' => $schemaTables->firstWhere('key', 'suppliers')])
@elseif($isRestaurantTemplate)
    @include('client-folders.income-sources._business-restaurant-observations')
@elseif($isRemittanceTemplate)
    @include('client-folders.income-sources._business-remittance-questions')
@elseif(!empty($schema['questions']))
    <section class="business-report-section business-report-remarks">
        <h2>{{ $template->template_type === 'remittance_income' ? 'VALIDATION QUESTIONS / FINDINGS' : 'OBSERVATIONS DURING BUSINESS INSPECTION' }}</h2>
        <div class="business-validation-question-list">
            @foreach($schema['questions'] as $index => $question)
                <div class="business-validation-question-row">
                    <label class="ui-label" for="template-data-questions-{{ $index }}">{{ $index + 1 }}. {{ $question }}</label>
                    <div>
                        <textarea id="template-data-questions-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" rows="2">{{ old('template_data.questions.'.$index, data_get($report?->template_data, 'questions.'.$index)) }}</textarea>
                        <x-form.validation-message :for="'template_data.questions.'.$index" />
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif

@foreach($supplierTables as $table)
    @include('client-folders.income-sources._business-schema-table')
@endforeach
