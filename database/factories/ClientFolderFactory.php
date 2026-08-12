<?php

namespace Database\Factories;

use App\Enums\ClientFolderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFolderFactory extends Factory
{
    public function definition(): array
    {
        $last = strtoupper(fake()->lastName());
        $first = strtoupper(fake()->firstName());

        return [
            'folder_number' => fake()->unique()->numerify('BRBI-CI-2026-#####'),
            'display_name' => "$last, $first",
            'last_name' => $last,
            'first_name' => $first,
            'assigned_ci_id' => User::factory(),
            'created_by' => User::factory(),
            'status' => ClientFolderStatus::OnProgress,
            'progress_percent' => 0,
        ];
    }
}
