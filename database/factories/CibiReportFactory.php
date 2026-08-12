<?php

namespace Database\Factories;

use App\Enums\PartyType;
use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CibiReportFactory extends Factory
{
    public function definition(): array
    {
        return ['client_folder_id' => ClientFolder::factory(), 'ci_in_charge_id' => User::factory(), 'party_type' => PartyType::Borrower, 'amount_applied' => fake()->randomFloat(2, 10000, 500000), 'state' => RecordState::Draft];
    }
}
