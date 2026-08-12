<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => fake()->unique()->numerify('EMP-####'),
            'full_name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'password' => Hash::make(Str::random(40)),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'auth_session_version' => 1,
            'role' => UserRole::CreditInvestigator,
            'status' => UserStatus::Active,
        ];
    }

    public function administrator(): static
    {
        return $this->state(['role' => UserRole::Administrator]);
    }
}
