<?php

namespace Database\Factories;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomeSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_folder_id' => ClientFolder::factory(), 'income_source_template_id' => IncomeSourceTemplate::factory(),
            'template_type' => 'business_source_validation', 'template_version' => 1,
            'source_name' => fake()->company(), 'business_name' => fake()->company(), 'state' => RecordState::Draft,
        ];
    }
}
