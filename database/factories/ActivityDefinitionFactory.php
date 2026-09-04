<?php

namespace Database\Factories;

class ActivityDefinitionFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['code' => fake()->unique()->slug(2), 'name' => ucfirst($name), 'is_required' => true, 'is_active' => true];
    }
}
