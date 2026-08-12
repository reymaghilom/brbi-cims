<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ReportFormat;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeneratedReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_folder_id' => ClientFolder::factory(), 'scope_key' => fn (array $attributes) => 'folder:'.$attributes['client_folder_id'],
            'source_type' => 'cibi_report', 'report_type' => 'cibi', 'format' => ReportFormat::Pdf,
            'version' => 1, 'status' => GenerationStatus::Processing, 'generated_by' => User::factory(),
        ];
    }
}
