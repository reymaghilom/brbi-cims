<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiagnoseTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnose_store_failure(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $template = IncomeSourceTemplate::where('template_type', 'leasing_non_agricultural')->firstOrFail();

        $response = $this->actingAs($ci)->post(route('client-folders.income-sources.store', $folder), [
            'income_source_template_id' => $template->id, 'source_name' => 'Income Source', 'business_name' => 'Sample Business',
        ]);

        fwrite(STDERR, "STATUS: ".$response->getStatusCode()."\n");
        fwrite(STDERR, "REDIRECT: ".($response->headers->get('Location') ?? 'none')."\n");
        fwrite(STDERR, "SESSION: ".print_r(session()->all(), true)."\n");
        fwrite(STDERR, "SCHEMA: ".json_encode($template->businessReportSchema())."\n");
        fwrite(STDERR, "COUNT: ".$folder->incomeSources()->count()."\n");
        $this->assertTrue(true);
    }
}
