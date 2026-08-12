<?php

namespace Database\Seeders;

use App\Models\ActivityDefinition;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\GeneralIncomeSourceReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('cims.demo_data_enabled')) {
            return;
        }

        DB::transaction(function () {
            $investigator = User::factory()->create(['employee_id' => 'DEMO-CI', 'full_name' => 'Reasan Mark Q. Gura', 'username' => 'demo.ci']);
            $dedicated = IncomeSourceTemplate::where('template_type', 'business_source_validation')->firstOrFail();
            $fallback = IncomeSourceTemplate::where('template_type', 'general_income_sources')->firstOrFail();

            foreach ([
                ['DAROY', 'DANILO O.', 'BRBI-CI-2026-00001'],
                ['OBASA', 'REYNALDO JAYSON', 'BRBI-CI-2026-00002'],
                ['LIM', 'MARY GRACE', 'BRBI-CI-2026-00003'],
            ] as $index => [$last, $first, $number]) {
                $folder = ClientFolder::factory()->create(['folder_number' => $number, 'display_name' => "$last, $first", 'last_name' => $last, 'first_name' => $first, 'assigned_ci_id' => $investigator->id, 'created_by' => $investigator->id]);
                ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
                foreach (ActivityDefinition::orderBy('sort_order')->get() as $definition) {
                    CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => $definition->name]);
                }

                $template = $index === 2 ? $fallback : $dedicated;
                $source = IncomeSource::create(['client_folder_id' => $folder->id, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $index === 2 ? 'General Declared Income Sources' : 'Demo Business', 'business_name' => $index === 2 ? null : 'Demo Business', 'sort_order' => 1]);
                if ($template->is_fallback) {
                    GeneralIncomeSourceReport::create(['income_source_id' => $source->id, 'general_remarks' => 'Safe demonstration record only.']);
                } else {
                    BusinessReport::factory()->create(['income_source_id' => $source->id, 'business_name' => 'Demo Business']);
                }
            }
        });
    }
}
