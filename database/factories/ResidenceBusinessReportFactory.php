<?php

namespace Database\Factories;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\User;

class ResidenceBusinessReportFactory extends Factory
{
    public function definition(): array
    {
        return ['client_folder_id' => ClientFolder::factory(), 'ci_user_id' => User::factory(), 'report_date' => now()->toDateString(), 'state' => RecordState::Draft];
    }
}
