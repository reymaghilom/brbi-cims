@php
    $employeeFields = [
        ['key' => 'office_staff', 'label' => '# OF OFFICE STAFF:'],
        ['key' => 'agents', 'label' => '# OF AGENTS:'],
        ['key' => 'drivers', 'label' => '# OF DRIVERS:'],
        ['key' => 'helpers', 'label' => '# OF HELPERS:'],
    ];
    $inventoryLevels = ['HIGH', 'MODERATE', 'LOW', 'NONE'];
    $productRows = [
        ['PHARMACEUTICALS', 'BEVERAGES'],
        ['CONSTRUCTION MATS', 'PACKAGED DRY FOOD'],
        ['FRESH / REFRIGERATED GOODS', 'EQUIPMENT / DEVICES / MACHINES'],
        ['HOME / CONSUMER GOODS', 'AGRI FEEDS / FERTILIZER / SEEDS'],
    ];
    $productsSeen = old('template_data.fields.products_seen', data_get($report?->template_data, 'fields.products_seen'));
    $normalizeProduct = static fn (string $value): string => preg_replace('/[^A-Z0-9]+/', '', strtoupper($value));
    $selectedProducts = collect(preg_split('/\s*,\s*/', (string) $productsSeen, -1, PREG_SPLIT_NO_EMPTY))
        ->map($normalizeProduct);
    $topBrands = old('template_data.fields.top_brands', data_get($report?->template_data, 'fields.top_brands'));
    $topBrandPrices = old('template_data.fields.top_brand_prices', data_get($report?->template_data, 'fields.top_brand_prices'));
    $splitStockroomValues = static function (?string $value): array {
        $rows = preg_split('/\R/', (string) $value);
        if (count($rows) <= 1 && str_contains((string) $value, ',')) {
            $rows = preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        }

        return array_pad(array_slice(array_map('trim', $rows ?: []), 0, 4), 4, '');
    };
    $brandRows = $splitStockroomValues($topBrands);
    $priceRows = $splitStockroomValues($topBrandPrices);
@endphp
<section class="business-report-section business-distributor-stockroom" data-distributor-stockroom>
    <header class="business-distributor-section-title"><h2>Summary of Office/Warehouse/Stockroom:</h2></header>
    <input type="hidden" name="template_data[fields][products_seen]" value="{{ $productsSeen }}" data-distributor-products-value>
    <input type="hidden" name="template_data[fields][top_brands]" value="{{ $topBrands }}" data-distributor-stockroom-value="brand">
    <input type="hidden" name="template_data[fields][top_brand_prices]" value="{{ $topBrandPrices }}" data-distributor-stockroom-value="price">
    <div class="business-report-table-wrap">
        <table class="business-report-table business-distributor-stockroom-table">
            <thead><tr>
                <th scope="col">EMPLOYEES:</th><th scope="col">INVENTORY LEVEL:</th><th scope="col">PRODUCTS/GOODS SEEN IN INVENTORY:</th><th scope="col">TOP BRANDS STOCKED:</th><th scope="col">SELLING PRICE</th>
            </tr></thead>
            <tbody>
                @foreach($employeeFields as $index => $employeeField)
                <tr>
                    <td class="business-distributor-employee-cell"><label class="ui-label" for="distributor-{{ $employeeField['key'] }}">{{ $employeeField['label'] }}</label><input id="distributor-{{ $employeeField['key'] }}" class="ui-control" name="template_data[fields][{{ $employeeField['key'] }}]" type="text" value="{{ old('template_data.fields.'.$employeeField['key'], data_get($report?->template_data, 'fields.'.$employeeField['key'])) }}"></td>
                    <td><label class="business-report-choice-option"><input class="business-report-checkbox" name="template_data[fields][inventory_level]" type="radio" value="{{ $inventoryLevels[$index] }}" @checked(strtoupper((string) old('template_data.fields.inventory_level', data_get($report?->template_data, 'fields.inventory_level'))) === $inventoryLevels[$index])><span>{{ $inventoryLevels[$index] }}</span></label></td>
                    <td class="business-distributor-product-category"><div class="business-distributor-product-options">
                        @foreach($productRows[$index] as $productOption)
                            <label class="business-report-choice-option"><input class="business-report-checkbox" type="checkbox" value="{{ $productOption }}" data-distributor-product-option @checked($selectedProducts->contains($normalizeProduct($productOption)))><span>{{ $productOption }}</span></label>
                        @endforeach
                    </div></td>
                    <td><label class="sr-only" for="distributor-top-brand-{{ $index }}">Top brand stocked for inventory row {{ $index + 1 }}</label><input id="distributor-top-brand-{{ $index }}" class="ui-control" type="text" value="{{ $brandRows[$index] }}" data-distributor-stockroom-input="brand"></td>
                    <td><label class="sr-only" for="distributor-top-brand-price-{{ $index }}">Selling price for inventory row {{ $index + 1 }}</label><input id="distributor-top-brand-price-{{ $index }}" class="ui-control" type="text" value="{{ $priceRows[$index] }}" data-distributor-stockroom-input="price"></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @foreach(['office_staff', 'agents', 'drivers', 'helpers', 'inventory_level', 'products_seen', 'top_brands', 'top_brand_prices'] as $fieldKey)<x-form.validation-message :for="'template_data.fields.'.$fieldKey" />@endforeach
</section>
