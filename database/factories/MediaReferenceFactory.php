<?php

namespace Database\Factories;

use App\Enums\MediaCategory;
use App\Enums\MediaType;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaReferenceFactory extends Factory
{
    public function definition(): array
    {
        return ['client_folder_id' => ClientFolder::factory(), 'media_type' => MediaType::Photo, 'category' => MediaCategory::Residence, 'file_name' => fake()->uuid().'.jpg', 'uploaded_by' => User::factory()];
    }
}
