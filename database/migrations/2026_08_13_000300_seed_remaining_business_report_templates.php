<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = [
            ['leasing_truck_equipment', 'Leasing Operations (Truck or Equipment)', 'Leasing', ['branches', 'suppliers', 'observations'], 1],
            ['leasing_non_agricultural', 'Leasing Operations: Non-Agricultural Real Estate', 'Leasing', ['properties', 'tenants'], 2],
            ['leasing_agricultural', 'Leasing Operations: Agricultural Real Estate', 'Leasing', ['properties', 'tenants'], 3],
            ['leasing_poultry_farm', 'Leasing of Poultry Farm Operations', 'Leasing', ['properties', 'tenants', 'observations'], 4],
            ['taxi_operator', 'Taxi Operator', 'Transport', ['branches', 'observations'], 5],
            ['puj_van_jeepney_operator', 'PUJ / Van / Jeepney Replacement Operator', 'Transport', ['branches', 'observations'], 6],
            ['trucking_services', 'Trucking Services', 'Transport', ['branches', 'suppliers', 'observations'], 7],
            ['distributorship_wholesaler_b2b', 'Distributorship / Panel / Wholesaler / B2B Retailer', 'Distribution', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 8],
            ['pharmacy_drugstore', 'Pharmacy / Drugstore', 'Retail', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 9],
            ['general_merchandise_hardware_parts', 'General Merchandise Store / Hardware / Auto or Motor Parts', 'Retail', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 10],
            ['buy_sell_dry_goods', 'Buy & Sell Store: Dry Goods (Apparel, Gadgets, Electronics Non-Food)', 'Retail', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 11],
            ['retail_grocery_water_refilling', 'Retail: Grocery Store / Supermarket / Sari-Sari Store / Water Refilling', 'Retail', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 12],
            ['meatshop_store', 'Meatshop Store', 'Retail', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 13],
            ['contractor_subcontractor', 'Contractor / Subcontractor', 'Contracting', ['branches', 'suppliers', 'observations'], 14],
            ['restaurant_food_stall', 'Restaurant / Cafeteria / Carenderia / Food Stall', 'Food Service', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 15],
            ['farming_corn', 'Farming: Corn Production', 'Agriculture', ['properties', 'suppliers', 'observations'], 16],
            ['farming_sugarcane', 'Farming: Sugarcane Production', 'Agriculture', ['properties', 'suppliers', 'observations'], 17],
            ['remittance_income', 'Remittance Received from OFW / Foreigner (Allotment); Alimony; Allowance; Family Sharing of Profits', 'Remittance', ['observations'], 18],
        ];

        foreach ($templates as [$type, $name, $category, $tags, $order]) {
            DB::table('income_source_templates')->updateOrInsert(
                ['template_type' => $type, 'version' => 1],
                [
                    'name' => $name,
                    'description' => 'Official dedicated Business Report structure.',
                    'business_category' => $category,
                    'compatibility_tags' => json_encode($tags),
                    'form_handler' => 'dedicated-business',
                    'data_handler' => 'dedicated-business',
                    'preview_handler' => 'dedicated-business',
                    'pdf_template_key' => $type,
                    'docx_template_key' => $type,
                    'is_fallback' => false,
                    'is_active' => true,
                    'sort_order' => $order,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('income_source_templates')->whereIn('template_type', [
            'leasing_truck_equipment', 'leasing_agricultural', 'leasing_poultry_farm', 'taxi_operator',
            'puj_van_jeepney_operator', 'trucking_services', 'distributorship_wholesaler_b2b',
            'pharmacy_drugstore', 'general_merchandise_hardware_parts', 'buy_sell_dry_goods',
            'meatshop_store', 'contractor_subcontractor', 'restaurant_food_stall', 'farming_corn',
            'farming_sugarcane', 'remittance_income',
        ])->whereNotExists(function ($query) {
            $query->selectRaw('1')
                ->from('income_sources')
                ->whereColumn('income_sources.income_source_template_id', 'income_source_templates.id');
        })->delete();

        DB::table('income_source_templates')->where('template_type', 'leasing_non_agricultural')->update(['sort_order' => 1]);
        DB::table('income_source_templates')->where('template_type', 'retail_grocery_water_refilling')->update(['sort_order' => 2]);
    }
};
