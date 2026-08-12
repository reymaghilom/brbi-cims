<?php

namespace Database\Factories;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientInformationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_folder_id' => ClientFolder::factory(),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-21 years'),
            'civil_status' => fake()->randomElement(['single', 'married', 'widowed']),
            'contact_number' => fake()->numerify('09#########'),
            'dependents_count' => fake()->numberBetween(0, 5),
            'completion_state' => RecordState::Draft,
        ];
    }
}
