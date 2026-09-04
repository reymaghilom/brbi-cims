<?php

namespace Database\Factories;

use App\Models\IncomeSource;

class BusinessReportFactory extends Factory
{
    public function definition(): array
    {
        return ['income_source_id' => IncomeSource::factory(), 'business_name' => fake()->company(), 'report_category' => 'retail', 'main_business_address' => fake()->address()];
    }
}
