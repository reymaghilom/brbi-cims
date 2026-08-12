<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class IncomeSourceTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'template_type' => 'business_source_validation', 'version' => 1, 'name' => 'Business Source of Income Validation',
            'form_handler' => 'dedicated-business', 'data_handler' => 'dedicated-business', 'preview_handler' => 'dedicated-business',
            'pdf_template_key' => 'business-source-validation', 'docx_template_key' => 'business-source-validation',
            'is_fallback' => false, 'is_active' => true,
        ];
    }

    public function fallback(): static
    {
        return $this->state(['template_type' => 'general_income_sources', 'name' => 'SOURCES OF INCOME DECLARED BY CLIENT', 'is_fallback' => true]);
    }
}
