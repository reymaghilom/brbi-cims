<?php

$field = fn (string $key, string $label, string $type = 'text', array $options = [], ?string $guide = null): array => compact('key', 'label', 'type', 'options') + ($guide === null ? [] : compact('guide'));
$column = fn (string $key, string $label, string $type = 'text', ?string $guide = null, bool $hidden = false): array => compact('key', 'label', 'type', 'guide') + ($hidden ? compact('hidden') : []);
$table = fn (string $key, string $title, array $columns): array => compact('key', 'title', 'columns');
$summary = fn (string $subject): array => [
    $field('total_declared', "Total $subject Declared", 'number'),
    $field('total_inspected', "Total $subject Inspected", 'number'),
    $field('total_not_inspected', "Total $subject Not Inspected", 'number'),
    $field('reason_not_inspected', 'Reason Not Inspected'),
];

$branchColumns = [
    $column('location', 'Location'), $column('frontage', 'Front (SQM)'), $column('total_area', 'Total SQM'),
    $column('air_conditioned', 'Aircon (Y/N)'), $column('operating_days_hours', 'Operating Days & Hours'),
    $column('shifts', '# of Shifts', 'number'), $column('employees_per_shift', '# of Employees per Shift', 'number'),
    $column('average_sales_per_shift', 'Average PHP Sales per Shift'), $column('inventory_level', 'Inventory Level'),
    $column('monthly_rent', 'Rent per Month'), $column('years_in_area', 'Years in the Area'),
    $column('nearby_brands', 'Big Brands / Relevant Location Information'),
];
$pharmacyBranchColumns = array_map(static function (array $branchColumn): array {
    return match ($branchColumn['key']) {
        'average_sales_per_shift' => array_replace($branchColumn, ['label' => 'AVE. PHP SALES PER SHIFT']),
        'inventory_level' => array_replace($branchColumn, ['label' => 'INVENTORY LEVEL', 'guide' => '(HIGH, MID, LOW)']),
        'nearby_brands' => array_replace($branchColumn, ['label' => 'BIG BRANDS NEAR THE AREA?']),
        default => $branchColumn,
    };
}, $branchColumns);
$supplierColumns = [
    $column('supplier_name', 'Supplier Name'), $column('office_location', 'Office Location'),
    $column('confirmed', 'Confirmed (Y/N)'), $column('contact_information', 'Contact Information'),
    $column('years_transacting', 'Years Transacting'), $column('payment_performance', 'Payment Performance / Important Remarks'),
];
$pharmacySupplierColumns = [
    $column('supplier_name', 'SUPPLIER NAME'),
    $column('office_location', 'OFFICE LOCATION'),
    $column('confirmed', 'CONFIMRED', 'text', '(Y/N)'),
    $column('payment_performance', 'IMPORTANT REMARKS'),
    $column('contact_information', 'Contact Information', 'text', null, true),
    $column('years_transacting', 'Years Transacting', 'text', null, true),
];
$generalMerchandiseSupplierColumns = [
    $column('supplier_name', 'SUPPLIER NAME'),
    $column('office_location', 'OFFICE LOCATION'),
    $column('confirmed', 'CONFIMRED'),
    $column('payment_performance', 'IMPORTANT REMARKS'),
    $column('contact_information', 'Contact Information', 'text', null, true),
    $column('years_transacting', 'Years Transacting', 'text', null, true),
];
$contractorSupplierColumns = [
    $column('supplier_name', 'SUPPLIER NAME'),
    $column('office_location', 'OFFICE LOCATION'),
    $column('confirmed', 'CONFIRMED', 'text', '(Y/N)'),
    $column('payment_performance', 'IMPORTANT REMARKS', 'text', '(CONTACT INFORMATION, YEARS TRANSACTING, BAD / GOOD PAYMENT PERFORMANCE, ETC.)'),
    $column('contact_information', 'Contact Information', 'text', null, true),
    $column('years_transacting', 'Years Transacting', 'text', null, true),
];
$restaurantBranchColumns = [
    $column('location', 'LOCATION'),
    $column('frontage', 'FRONT (SQM)'),
    $column('total_area', 'TOTAL SQM'),
    $column('air_conditioned', 'AIRCON (Y/N)'),
    $column('operating_days_hours', 'OPERATING DAYS & HOURS'),
    $column('shifts', '# OF SHIFTS', 'number'),
    $column('employees_per_shift', '# OF EMPLOYEES PER SHIFT', 'number'),
    $column('average_sales_per_shift', 'AVE. PHP SALES PER SHIFT'),
    $column('inventory_level', 'INVENTORY LEVEL', 'text', '(HIGH, MID, LOW)'),
    $column('monthly_rent', 'RENT PER MONTH'),
    $column('years_in_area', 'YEARS IN THE AREA'),
    $column('dining_capacity', 'IN-STORE DINING CAPACITY', 'text', '(# OF TABLES & CHAIRS)'),
];
$farmingSupplierColumns = [
    $column('supplier_category', 'SUPPLIERS:', 'static'),
    $column('supplier_name', 'BUSINESS NAME/CONTACT PERSON'),
    $column('office_location', 'ADDRESS'),
    $column('years_transacting', 'YEARS TRANSACTING'),
    $column('payment_performance', 'PAYMENT PERFORMANCE / OTHER REMARKS'),
    $column('confirmed', 'Confirmed', 'text', null, true),
    $column('contact_information', 'Contact Information', 'text', null, true),
];
$farmingSupplierDefaults = [
    ['supplier_category' => 'SEEDS SUPPLIER'],
    ['supplier_category' => 'FERTILIZER SUPPLIER'],
    ['supplier_category' => 'TRUCKING PROVIDER (IF ANY)'],
];
$commonQuestions = [
    'Who are the competitors near the area?',
    'Does the client have a good location and accessible market?',
    'During the visit, were customers observed visiting or buying? Indicate day and time.',
    'Which declared bank shows the business income? Indicate bank and branch, if any.',
];

$otherIncomeSourceGroups = [
    'business' => [
        ['key' => 'leasing_real_estate_agri', 'label' => 'Leasing Real Estate (Agri)'],
        ['key' => 'leasing_real_estate_non_agri', 'label' => 'Leasing Real Estate (Non-Agri)'],
        ['key' => 'leasing_truck_equipment', 'label' => 'Leasing (Truck/Equipment)'],
        ['key' => 'leasing_farm_operations', 'label' => 'Leasing: Farm Operations'],
        ['key' => 'taxi_puj_operator', 'label' => 'Taxi/PUJ Operator'],
        ['key' => 'trucking_services', 'label' => 'Trucking Services'],
        ['key' => 'distributorship_dealer_wholesaler', 'label' => 'Distributorship/Dealer/Wholesaler'],
        ['key' => 'pharmacy_store', 'label' => 'Pharmacy Store'],
        ['key' => 'quarry_operations', 'label' => 'Quarry Operations'],
        ['key' => 'crushing_plant_operations', 'label' => 'Crushing Plant Operations'],
        ['key' => 'gasoline_station', 'label' => 'Gasoline Station'],
        ['key' => 'franchise_national_brand', 'label' => 'Franchisee - National Brand'],
        ['key' => 'repair_shop', 'label' => 'Repair Shop'],
        ['key' => 'general_merchandise_hardware_auto_parts', 'label' => 'General Merchandise/Hardware/Auto Parts'],
        ['key' => 'buy_sell_dry_goods', 'label' => 'Buy & Sell: Dry Goods'],
        ['key' => 'still_lotto_outlet', 'label' => 'STL/Lotto Outlet'],
        ['key' => 'laundry_shop', 'label' => 'Laundry Shop'],
        ['key' => 'water_refilling_station', 'label' => 'Water Refilling Station'],
        ['key' => 'print_shop', 'label' => 'Print Shop'],
        ['key' => 'buy_sell_vehicles_heavy_equipment', 'label' => 'Buy & Sell: Vehicles/Heavy Equipment'],
        ['key' => 'integrator_aggregator', 'label' => 'Integrator/Aggregator'],
        ['key' => 'restaurant_cafeteria_carenderia_stall', 'label' => 'Restaurant/Cafeteria/Carenderia/Stall'],
        ['key' => 'catering_business', 'label' => 'Catering Business'],
        ['key' => 'bakery', 'label' => 'Bakery'],
        ['key' => 'grocery_convenience_supermarket', 'label' => 'Grocery/Convenience Store/Supermarket'],
        ['key' => 'meatshop', 'label' => 'Meatshop'],
        ['key' => 'salon_massage_parlor', 'label' => 'Salon/Massage Parlor'],
        ['key' => 'tugboat_services', 'label' => 'Tugboat Services'],
        ['key' => 'sawmill_bansuhan', 'label' => 'Sawmill/Bansuhan'],
        ['key' => 'furniture_making', 'label' => 'Furniture Making'],
        ['key' => 'manufacturing', 'label' => 'Manufacturing'],
        ['key' => 'contractor', 'label' => 'Contractor'],
        ['key' => 'subcontractor', 'label' => 'Subcontractor'],
        ['key' => 'hotel_inn_pension_dorm', 'label' => 'Hotel/Inn/Pension/Dorm'],
        ['key' => 'food_processing_packaging', 'label' => 'Food Processing/Packaging'],
        ['key' => 'school_tutorial_center', 'label' => 'School/Tutorial Center'],
        ['key' => 'private_vehicles_for_rent', 'label' => 'Private Vehicles for Rent'],
    ],
    'agriculture' => [
        ['key' => 'corn_production', 'label' => 'Corn Production'],
        ['key' => 'sugarcane_production', 'label' => 'Sugarcane Production'],
        ['key' => 'cassava_production', 'label' => 'Cassava Production'],
        ['key' => 'squash_production', 'label' => 'Squash Production'],
        ['key' => 'rice_production', 'label' => 'Rice Production'],
        ['key' => 'livestock_raising', 'label' => 'Livestock Raising'],
        ['key' => 'poultry_layer_egg', 'label' => 'Poultry: Layer Egg'],
        ['key' => 'poultry_broiler', 'label' => 'Poultry: Broiler'],
        ['key' => 'poultry_grower', 'label' => 'Poultry: Grower'],
        ['key' => 'fish_peddling', 'label' => 'Fish Peddling'],
    ],
    'professional' => [
        ['key' => 'lawyer_doctor', 'label' => 'Lawyer/Doctor'],
        ['key' => 'bookkeeping_accounting', 'label' => 'Bookkeeping/Accounting'],
        ['key' => 'real_estate_brokerage', 'label' => 'Real Estate Brokerage'],
        ['key' => 'customs_brokerage', 'label' => 'Customs Brokerage'],
        ['key' => 'architect_engineer_interior_design', 'label' => 'Architect/Engineer/Interior Design'],
    ],
    'remittance' => [
        ['key' => 'retirement_pension', 'label' => 'Retirement Pension'],
        ['key' => 'ofw_foreigner_allotment', 'label' => 'OFW/Foreigner Allotment/Alimony/Allowance'],
        ['key' => 'interest_income_investments', 'label' => 'Interest Income/Investments'],
    ],
    'employment' => [
        ['key' => 'government_agency_corporation', 'label' => 'Government Agency/Corporation'],
        ['key' => 'government_public_office', 'label' => 'Government: Public Office (Politician)'],
        ['key' => 'private_institution', 'label' => 'Private Institution'],
    ],
];
$otherIncomeSourceFields = [];
foreach ($otherIncomeSourceGroups as $choices) {
    foreach ($choices as $choice) {
        $otherIncomeSourceFields[] = $field($choice['key'].'_rank', $choice['label'].' Rank', 'number');
    }
}

return [
    'leasing_truck_equipment' => ['schema' => [
        'profile' => true, 'fields' => array_merge($summary('Units'), [$field('operators_count', '# of Operators', 'number'), $field('drivers_count', '# of Drivers', 'number'), $field('helpers_count', '# of Helpers', 'number')]),
        'tables' => [
            $table('units', 'Summary of Units Inspected', [$column('truck_equipment_type', 'Type of Truck/Equipment', 'text', '(brand/capacity/fully paid or mortgaged)'), $column('rented_by', 'Rented By', 'text', '(NAME/OFFICE ADDRESS/CONTACT NUMBER)'), $column('current_location', 'Current Location', 'text', '(AREA TRAVELLED/GOODS TRANSPORTED)'), $column('units_declared', 'TOTAL UNITS DECLARED', 'number'), $column('units_validated', 'TOTAL UNITS VALIDATED', 'number'), $column('rental_rate', 'Rental Rate', 'text', '(php per km/php per cubic/fixed monthly)'), $column('has_contract', 'W/ CONTRACT? (Y/N)')]),
            $table('suppliers', 'Employees / Suppliers', [$column('supplier_category', 'Supplier Type'), $column('supplier_name', 'Business Name'), $column('contact_information', 'Contact Person'), $column('office_location', 'Address'), $column('years_transacting', 'Years Transacting'), $column('payment_performance', 'Monthly Average Expense & Payment Track Record')]),
        ],
    ]],
    'leasing_agricultural' => ['schema' => [
        'profile' => false, 'fields' => $summary('Properties'),
        'required_fields' => ['total_declared', 'total_inspected', 'total_not_inspected', 'reason_not_inspected'],
        'tables' => [$table('properties', 'Summary of Properties Inspected', [$column('location_area', 'Location & Total HA'), $column('tenant', 'TENANT', 'text', '(NAME & YEARS RENTING)'), $column('annual_rent', 'Annual Rent'), $column('has_contract', 'W/ CONTRACT? (Y/N)'), $column('planted_crop', 'Planted Crop'), $column('tenant_contact', 'CONTACT NO. OF TENANT'), $column('relevant_information', 'RELEVANT INFORMATION', 'text', '(EX. LEASE INCOME SHARED AMONG RELATIVES?)')])],
    ]],
    'leasing_poultry_farm' => ['schema' => [
        'profile' => false, 'fields' => $summary('Properties'),
        'required_fields' => ['total_declared', 'total_inspected', 'total_not_inspected', 'reason_not_inspected'],
        'tables' => [$table('farms', 'Summary of Properties Inspected', [$column('location_area', 'Location & Total HA'), $column('lessor', 'LESSOR', 'text', '(NAME & CONTACT NO. & YEARS RENTING)'), $column('has_contract', 'W/ CONTRACT? (Y/N)'), $column('farm_buildings', '# of Farm Buildings', 'number'), $column('heads_per_building', '# of Heads per Building', 'number'), $column('production_type', 'Layer / Broiler / Grower'), $column('relevant_information', 'RELEVANT INFORMATION GATHERED', 'text', '(EX. LEASE INCOME SHARED AMONG RELATIVES?)')])],
    ]],
    'taxi_operator' => ['schema' => [
        'profile' => true, 'fields' => array_merge([$field('ltfrb_research_date', 'LTFRB RESEARCH (date)', 'date'), $field('franchise_fee', 'FRANCHISE/LICENSE FEE PER UNIT', 'text', [], '(INDICATE HOW FREQUENT RENEWAL)'), $field('minimum_year_model', 'MINIMUM YEAR MODEL REQUIREMENT'), $field('minimum_units', 'MINIMUM NUMBER OF UNITS PER OPERATOR', 'number')], $summary('Units')),
        'hidden_profile_fields' => ['previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'informant', 'registered_owner', 'relationship_to_borrower'],
        'tables' => [$table('units', 'Summary of Units Inspected', [$column('brand_model', 'BRAND/CAR MODEL', 'text', '(ONLY THOSE INSPECTED)'), $column('year_model', 'YEAR MODEL'), $column('plate_number', 'PLATE NO.'), $column('with_driver', 'W/ DRIVER?'), $column('operating_since', 'SINCE?', 'text', '(MONTH/YEAR)'), $column('daily_boundary', 'DAILY BOUNDARY'), $column('franchise_operator', 'FRANCHISE/OPERATOR NAME & CONTACT NO.', 'text', '(AS SEEN IN TAXI UNIT)'), $column('area_parked', 'AREA PARKED?')])],
    ]],
    'puj_van_jeepney_operator' => ['schema' => [
        'profile' => true, 'fields' => array_merge([$field('ltfrb_research_date', 'LTFRB RESEARCH (date)', 'date'), $field('franchise_fee', 'FRANCHISE/LICENSE FEE PER UNIT', 'text', [], '(INDICATE HOW FREQUENT RENEWAL)'), $field('minimum_year_model', 'MINIMUM YEAR MODEL REQUIREMENT'), $field('minimum_units', 'MINIMUM NUMBER OF UNITS PER OPERATOR', 'number')], $summary('Units')),
        'hidden_profile_fields' => ['previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'informant', 'registered_owner', 'relationship_to_borrower'],
        'tables' => [$table('units', 'Summary of Units Inspected', [$column('brand_model', 'BRAND/CAR MODEL', 'text', '(ONLY THOSE INSPECTED)'), $column('year_model', 'YEAR MODEL'), $column('plate_number', 'PLATE NO.'), $column('with_driver', 'W/ DRIVER?'), $column('operating_since', 'SINCE?', 'text', '(MONTH/YEAR)'), $column('daily_boundary', 'DAILY BOUNDARY'), $column('route', 'ROUTE'), $column('average_fare', 'AVERAGE FARE'), $column('franchise_operator', 'FRANCHISE/OPERATOR NAME & CONTACT NO.', 'text', '(AS SEEN IN PUJ/VAN/JEEPNEY UNIT)')])],
    ]],
    'trucking_services' => ['schema' => [
        'profile' => true, 'fields' => array_merge($summary('Units'), [$field('operators_count', '# of Operators', 'number'), $field('drivers_count', '# of Drivers', 'number'), $field('helpers_count', '# of Helpers', 'number')]),
        'tables' => [$table('units', 'Summary of Units Inspected', [$column('brand_model', 'BRAND/VEHICLE MODEL'), $column('year_model', 'YEAR MODEL'), $column('plate_number', 'PLATE NO.'), $column('years_employed', 'YEARS EMPLOYED'), $column('goods_transported', 'TYPES OF GOODS/BRANDS TRANSPORTED'), $column('pickup_delivery_areas', 'AREAS PICKUP / DELIVERY SITES?'), $column('registered_owner', 'REGISTERED OWNER'), $column('encumbrances', 'ENCUMBRANCES'), $column('orcr_validation', 'Actual OR/CR Validation', 'text', null, true)]), $table('suppliers', 'Suppliers and Operating Expenses', array_merge([$column('supplier_category', 'Supplier Type')], $supplierColumns))],
    ]],
    'distributorship_wholesaler_b2b' => ['schema' => [
        'profile' => true, 'fields' => array_merge([$field('office_staff', '# of Office Staff', 'number'), $field('agents', '# of Agents', 'number'), $field('drivers', '# of Drivers', 'number'), $field('helpers', '# of Helpers', 'number'), $field('inventory_level', 'Inventory Level'), $field('products_seen', 'Products / Goods Seen in Inventory'), $field('top_brands', 'Top Brands Stocked'), $field('top_brand_prices', 'Selling Price')], $summary('Units')),
        'tables' => [$table('units', 'Summary of Units Inspected', [$column('brand_model', 'BRAND/VEHICLE MODEL'), $column('year_model', 'YEAR MODEL'), $column('plate_number', 'PLATE NO.'), $column('years_employed', 'YEARS EMPLOYED'), $column('goods_transported', 'TYPES OF GOODS/BRANDS TRANSPORTED'), $column('areas_territories', 'AREAS/TERRITORIES?'), $column('registered_owner', 'REGISTERED OWNER'), $column('encumbrances', 'ENCUMBRANCES'), $column('driver_agent_interview', 'Driver / Agent Interview', 'text', null, true), $column('orcr_validation', 'Actual OR/CR Validation', 'text', null, true)]), $table('products', 'Top Sellable Products', [$column('product', 'Top Sellable Products'), $column('selling_price_per_unit', 'Selling Price per Unit', 'text', '(ex. case/box/etc.)'), $column('sales_per_month', 'Sales per Month')])],
        'questions' => ['Where are stocks purchased from?', 'How many stores are visited per day per truck?', 'How many deliveries are made per store in a month?', 'How many items or what PHP value does each store buy on average?', 'How are daily collections remitted?', 'Are stores given payment terms for deliveries?'],
    ]],
    'pharmacy_drugstore' => ['schema' => ['profile' => true, 'fields' => $summary('Branches'), 'tables' => [$table('branches', 'Summary of Branches Inspected', $pharmacyBranchColumns) + ['default_rows' => 3], $table('products', 'Top Sellable Products', [$column('product', 'Top Sellable Products', 'text', '(branded or generic)'), $column('selling_price', 'Selling Price per Item')]) + ['default_rows' => 4], $table('suppliers', 'Supplier Validation - Especially Supplier of Top Sellable Products (if applicable)', $pharmacySupplierColumns) + ['default_rows' => 3]], 'questions' => $commonQuestions]],
    'general_merchandise_hardware_parts' => ['schema' => ['profile' => true, 'fields' => array_merge([$field('store_type', 'Type of Store (Check Applicable)', 'radio', array_combine(['General Merchandise', 'Hardware', 'Auto or Motor Parts', 'Paint Only'], ['General Merchandise', 'Hardware', 'Auto or Motor Parts', 'Paint Only']))], $summary('Branches')), 'tables' => [$table('branches', 'Summary of Branches Inspected', $pharmacyBranchColumns) + ['default_rows' => 3], $table('products', 'Top Sellable Products', [$column('product', 'Top Sellable Products'), $column('selling_price', 'Selling Price per Item')]) + ['default_rows' => 6], $table('suppliers', 'Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):', $generalMerchandiseSupplierColumns) + ['default_rows' => 3]], 'questions' => ['Who are the competitors near the area?', 'Does the client have a good location and accessible market?', 'During the visit, were customers observed visiting or buying? Indicate day and time.', 'What are the most stocked products seen in the store or bodega?', 'Aside from walk-in clients, are there big recurring customers?', 'Do they have delivery trucks? How many?', 'Are these delivery trucks owned or mortgaged? Which institution?', 'Which declared bank shows the business income? Indicate bank and branch, if any.']]],
    'buy_sell_dry_goods' => ['schema' => ['profile' => true, 'fields' => array_merge([$field('store_type', 'Type of Store (Check Applicable)', 'radio', array_combine(['High End Display', 'Middle Tier Store', 'Ukay-Ukay', 'Stall Type Only'], ['High End Display', 'Middle Tier Store', 'Ukay-Ukay', 'Stall Type Only']))], [$field('total_declared', 'Total Branches Declared', 'number'), $field('total_inspected', 'Total Branches Inspected', 'number'), $field('total_not_inspected', '# Branches Not Inspected', 'number'), $field('reason_not_inspected', 'Reason Not Inspected')]), 'tables' => [$table('branches', 'Summary of Branches Inspected', $pharmacyBranchColumns) + ['default_rows' => 3], $table('products', 'Top Sellable Products', [$column('product', 'Top Sellable Products'), $column('selling_price', 'Selling Price per Item')]) + ['default_rows' => 6], $table('suppliers', 'Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):', $generalMerchandiseSupplierColumns) + ['default_rows' => 3]], 'questions' => ['Who are the competitors near the area?', 'Does the client have a good location? (Residential / commercial market)', 'During the visit, were there a lot of customers visiting or buying? Indicate day and time.', 'Which declared bank shows the business income? Indicate bank and branch, if any.', 'What are the most stocked products seen in the store or bodega?', 'Do they offer credit or installment plans to customers? Indicate payment terms.', 'Proofs of payment to suppliers?', 'Are items brand new or second hand?']]],
    'meatshop_store' => ['schema' => ['profile' => true, 'fields' => $summary('Branches'), 'tables' => [$table('branches', 'Summary of Branches Inspected', $pharmacyBranchColumns) + ['default_rows' => 3], $table('inventory', 'Products / Goods Seen in Inventory', [$column('product_type', 'Product Type'), $column('main_supplier', 'Main Supplier'), $column('location', 'Location'), $column('contact_number', 'Contact No.'), $column('daily_kilos_sold', 'Ave. Daily Kilos Sold'), $column('price_range', 'Range of Price per Kilo'), $column('remarks', 'Other Remarks')])], 'questions' => ['Does the client have a good location? (Residential / commercial market)', 'During the visit, were there a lot of customers visiting or buying? Indicate day and time.', 'What are the most stocked products seen in the store or bodega?', 'Do they have a Point of Sale machine or cash register?', 'How many refrigerators? What other specialized equipment is available?', 'Were you able to see the butcher area? How many live animals are present?', 'How many employees and butchers are employed? How long employed and how much compensation?', 'For pigs, pork, or beef, what is the cost per live-weight kilo and carcass yield?', 'Which declared bank shows the business income? Indicate bank and branch, if any.']]],
    'contractor_subcontractor' => ['schema' => ['profile' => true, 'fields' => [$field('projects_declared', 'Declared Ongoing Projects', 'number'), $field('projects_inspected', '# of Projects Inspected', 'number'), $field('projects_not_inspected', '# of Projects Not Inspected', 'number'), $field('reason_not_inspected', 'Reason Not Inspected')], 'tables' => [$table('projects', 'Summary of Projects Inspected (Validation with onsite personnel)', [$column('project_owner', 'PROJECT OWNER (CLIENT) AND/ OR MAIN CONTRACTOR'), $column('location', 'LOCATION OF PROJECT'), $column('government', "GOV'T?", 'text', '(Y/N)'), $column('scope_of_work', 'SCOPE OF WORK', 'text', '(VALIDATED AND OBSERVED)'), $column('start_date', 'START DATE', 'date'), $column('target_completion_date', 'TARGET COMPLETION DATE', 'date'), $column('percent_completed', '% COMPLETED?')]) + ['default_rows' => 3], $table('suppliers', 'Supplier Validation - Especially Suppliers for Main materials or services required (ex. Fuel gas station, CHB, cement, kabilya, aggregates & filling materials, tiles, glass, equipment rental service)', $contractorSupplierColumns) + ['default_rows' => 3]], 'questions' => ['EQUIPMENT (& # OF UNITS) SEEN ONSITE? (DT, MINI DT, BACKHOE, GRADER, ROLLER, ELF, ETC.)', 'INDICATE IF ABOVE EQUIPMENT ARE OWNED OR RENTED (PER VALIDATION)?', 'IF THERE ARE UNITS THAT ARE RENTED, ASK WHO THE SUPPLIER IS WITH CONTACT INFORMATION.', 'IS CONSTRUCTION ONGOING, STALLED, OR COMPLETED DURING YOUR VISIT? INDICATE DAY AND TIME OF VISIT.', 'HOW MANY WORKERS WERE ON SITE? (ENGRS, FOREMAN/LEADMAN, SKILLED, LABOR)', 'Does contractor/subcontractor have PhilGEPS for Government Contracts?', 'Does contractor/subcontractor have PCAB license?', 'If none above, who is the contractor they tie up with for the necessary licenses? Include contact information.', 'Which declared bank shows the business income? Indicate bank and branch, if any.']]],
    'restaurant_food_stall' => ['schema' => [
        'profile' => true,
        'fields' => array_merge([
            $field('scale_of_business', 'SCALE OF BUSINESS:', 'radio', [
                'Restaurant' => 'RESTAURANT',
                'Carenderia' => 'CARENDERIA',
                'Cafeteria' => 'CAFETERIA',
                'Stall' => 'STALL',
                'Mall - Restaurant' => 'RESTAURANT',
                'Mall - Stall Only' => 'STALL ONLY',
            ]),
        ], $summary('Branches')),
        'tables' => [$table('branches', 'Summary of Branches Inspected', $restaurantBranchColumns) + ['default_rows' => 3]],
        'questions' => [
            'EQUIPMENT SEEN ONSITE? (REFRIGERATOR, STOVES, OVENS, ETC.)',
            'HOW MANY WORKERS WERE ON SITE? (COOKS, WAIT STAFF, CASHIER, ETC.)',
            'INVENTORY LEVEL? (HIGH, MEDIUM, LOW, NONE)',
            'DO THEY HAVE DELIVERY (IN HOUSE, THIRD PARTY APP SERVICE?)',
            'HOW MUCH IS THEIR MENU? (TAKE PICTURE OF MENU) - INDICATE MOST SELLABLE ITEMS',
            'TARGET MARKET BASED ON LOCATION & PRICE POINT? (FAMILY, EMPLOYEES, STUDENTS?)',
            'WHERE DO THEY SOURCE INGREDIENTS/SUPPLY FOR FOOD AND DRINKS? (LOCATION/CONTACT INFORMATION)',
            'BANK DECLARED SHOWING BUSINESS INCOME? (BANK, BRANCH) - IF ANY',
        ],
    ]],
    'farming_corn' => ['schema' => [
        'profile' => false,
        'fields' => [
            $field('research_date', 'INDUSTRY RESEARCH/SURVEY', 'date', [], 'AS OF DATE'),
            $field('average_selling_price', 'AVE. SELLING PRICE PER KILO'),
            $field('seed_cost_per_ha', 'SEEDS COST PER HA'),
            $field('fertilizer_cost_per_ha', 'FERTILIZER COST PER HA'),
            $field('average_yield_per_ha', 'AVE. KILOS YIELD PER HA'),
            $field('crop_cycle_months', 'CROP CYCLE TERM (MONTHS)'),
            $field('peak_harvest_months', 'PEAK HARVEST MONTHS'),
            $field('total_ha_planted', 'TOTAL HA PLANTED'),
            $field('total_sites', 'TOTAL SITES/AREAS'),
            $field('sites_validated', 'TOTAL SITES VALIDATED'),
            $field('sites_not_inspected', 'TOTAL SITES NOT INSPECTED'),
            $field('reason_not_inspected', 'REASON NOT INSPECTED'),
        ],
        'required_fields' => ['average_selling_price', 'seed_cost_per_ha', 'fertilizer_cost_per_ha', 'average_yield_per_ha'],
        'tables' => [
            $table('farms', 'Summary of Properties Inspected', [
                $column('location_size', 'LOCATION & SIZE OF LAND (HA)'),
                $column('total_ha', 'TOTAL HA'),
                $column('tenure', 'OWNED/RENTED/PRENDA'),
                $column('annual_rent', 'IF RENTED, ANNUAL RENT PER HA'),
                $column('prenda_amount', 'IF PRENDA, AMOUNT PER HA'),
                $column('expiry_date', 'IF PRENDA/RENT, EXPIRY DATE', 'date'),
                $column('target_harvest_month', 'TARGET HARVEST MONTH'),
                $column('relevant_information', 'RELEVANT INFORMATION', 'text', '(EX. IF OWNED - NOT TRANSFERRED, INFO ON PROPERTY OWNER)'),
            ]) + ['default_rows' => 5],
            $table('suppliers', 'Suppliers', $farmingSupplierColumns) + ['default_row_values' => $farmingSupplierDefaults, 'default_row_key' => 'supplier_category'],
            $table('buyers', 'Corn Buyers', [
                $column('buyer', 'CORN BUYERS'),
                $column('address', 'ADDRESS'),
                $column('years_transacting', 'YEARS TRANSACTING'),
                $column('production_performance', 'PRODUCTION PERFORMANCE IN LAST HARVEST / OTHER REMARKS'),
            ]) + ['default_rows' => 2],
        ],
    ]],
    'farming_sugarcane' => ['schema' => [
        'profile' => false,
        'fields' => [
            $field('research_date', 'INDUSTRY RESEARCH/SURVEY', 'date', [], 'AS OF DATE'),
            $field('average_selling_price', 'AVE. SELLING PRICE PER LKG'),
            $field('seed_cost_per_ha', 'SEEDS COST PER HA'),
            $field('fertilizer_cost_per_ha', 'FERTILIZER COST PER HA'),
            $field('average_yield_per_ha', 'AVE. TONNES YIELD PER HA'),
            $field('crop_cycle_months', 'CROP CYCLE TERM (MONTHS)'),
            $field('peak_harvest_months', 'PEAK HARVEST MONTHS'),
            $field('total_ha_planted', 'TOTAL HA PLANTED'),
            $field('total_sites', 'TOTAL SITES/AREAS'),
            $field('sites_validated', 'TOTAL SITES VALIDATED'),
            $field('sites_not_inspected', 'TOTAL SITES NOT INSPECTED'),
            $field('reason_not_inspected', 'REASON NOT INSPECTED'),
        ],
        'required_fields' => ['seed_cost_per_ha', 'fertilizer_cost_per_ha', 'average_yield_per_ha', 'crop_cycle_months'],
        'tables' => [
            $table('farms', 'Summary of Properties Inspected', [
                $column('location_size', 'LOCATION & SIZE OF LAND (HA)'),
                $column('total_ha', 'TOTAL HA'),
                $column('ratoon_cycle', 'RATOON CYCLE'),
                $column('tenure', 'OWNED/RENTED/PRENDA'),
                $column('annual_rent', 'IF RENTED, ANNUAL RENT PER HA'),
                $column('prenda_amount', 'IF PRENDA, AMOUNT PER HA'),
                $column('expiry_date', 'IF PRENDA/RENT, EXPIRY DATE', 'date'),
                $column('target_harvest_month', 'TARGET HARVEST MONTH'),
                $column('relevant_information', 'RELEVANT INFORMATION', 'text', '(EX. IF OWNED - NOT TRANSFERRED, INFO ON PROPERTY OWNER)'),
            ]) + ['default_rows' => 5],
            $table('suppliers', 'Suppliers', $farmingSupplierColumns) + ['default_row_values' => $farmingSupplierDefaults, 'default_row_key' => 'supplier_category'],
            $table('sugarmills', 'Sugarmill Validation', [
                $column('sugarmill', 'SUGARMILL', 'radio', null) + ['options' => ['BUSCO' => 'BUSCO', 'CRYSTAL' => 'CRYSTAL']],
                $column('association', 'ASSOCIATION'),
                $column('address', 'ADDRESS'),
                $column('years_transacting', 'YEARS TRANSACTING'),
                $column('production_data', 'PRODUCTION DATA LAST MILLING / OTHER REMARKS (LOANS)'),
            ]) + ['default_rows' => 2],
        ],
    ]],
    'remittance_income' => ['schema' => ['profile' => false, 'fields' => [], 'tables' => [], 'required_questions' => [2], 'questions' => [
        'NAME OF THE PERSON REMITTING THE FUNDS (INDICATE CONTACT INFO, IF APPLICABLE)',
        'ADDRESS/ LOCATION OF THE PERSON REMITTING FUNDS',
        'RELATIONSHIP OF REMITTER TO THE APPLICANT?',
        'WHAT IS THE NATURE OF WORK/SOURCE OF INCOME OF REMITTER? (INDICATE BUSINESS/EMPLOYER)',
        'HOW OFTEN DOES REMITTER SEND FUNDS?',
        'WHICH BANK CAN THE REMITTANCE BE SEEN? (PROOF OF REMITTANCE)',
        'IF REMITTER IS EMPLOYED, IS THERE CONTRACT SUBMITTED WITH SALARY AND EMPLOYER INFO?',
        'HOW MUCH MONTHLY REMITTANCE IS RECEIVED THAT CAN BE SEEN IN PROOFS SUBMITTED?',
        'IS THERE ANY INFO GATHERED ON FIELD THAT SHOW REMITTANCE CASH FLOW IS NOT STABLE?',
        'WHEN DID APPLICANT START RECEIVING REMITTANCES:',
    ]]],
    'other_business_income_source' => ['schema' => [
        'profile' => true,
        'fields' => [],
        'tables' => [],
        'questions' => [],
    ]],
    'other_business_source_of_income' => ['schema' => [
        'profile' => false,
        'fields' => array_merge($otherIncomeSourceFields, [
            $field('business_selected', 'Business', 'checkbox'),
            $field('business_rank', 'Business Rank', 'number'),
            $field('business_description', 'Business Income Source'),
            $field('agriculture_selected', 'Agriculture Production', 'checkbox'),
            $field('agriculture_rank', 'Agriculture Production Rank', 'number'),
            $field('agriculture_description', 'Agriculture Production Income Source'),
            $field('professional_selected', 'Professional Services', 'checkbox'),
            $field('professional_rank', 'Professional Services Rank', 'number'),
            $field('professional_description', 'Professional Services Income Source'),
            $field('remittance_selected', 'Remittance', 'checkbox'),
            $field('remittance_rank', 'Remittance Rank', 'number'),
            $field('remittance_description', 'Remittance Income Source'),
            $field('employment_borrower_selected', 'Employment (Borrower)', 'checkbox'),
            $field('employment_borrower_rank', 'Employment (Borrower) Rank', 'number'),
            $field('employment_borrower_description', 'Employment (Borrower) Income Source'),
            $field('employment_spouse_selected', 'Employment (Spouse)', 'checkbox'),
            $field('employment_spouse_rank', 'Employment (Spouse) Rank', 'number'),
            $field('employment_spouse_description', 'Employment (Spouse) Income Source'),
        ]),
        'income_source_groups' => $otherIncomeSourceGroups,
        'tables' => [],
        'questions' => [],
    ]],
];
