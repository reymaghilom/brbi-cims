<?php

namespace Tests\Feature\Database;

use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\DeclaredIncomeSourceItem;
use App\Models\GeneralIncomeSourceReport;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_tables_exist_without_a_client_folder_collaboration_table(): void
    {
        foreach (['users', 'client_folders', 'income_source_templates', 'income_sources', 'general_income_source_reports', 'business_reports', 'cibi_reports', 'media_references', 'generated_reports'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [$table] was not created.");
        }
        $this->assertFalse(Schema::hasTable('client_folder_user'));
    }

    public function test_folder_numbers_and_one_to_one_reports_are_unique(): void
    {
        $folder = ClientFolder::factory()->create();

        $this->expectException(QueryException::class);
        ClientFolder::factory()->create(['folder_number' => $folder->folder_number]);
    }

    public function test_only_one_cibi_report_can_belong_to_a_folder(): void
    {
        $folder = ClientFolder::factory()->create();
        CibiReport::factory()->create(['client_folder_id' => $folder->id]);

        $this->expectException(QueryException::class);
        CibiReport::factory()->create(['client_folder_id' => $folder->id]);
    }

    public function test_fallback_contribution_ranks_are_unique_within_the_report(): void
    {
        $source = IncomeSource::factory()->create();
        $report = GeneralIncomeSourceReport::create(['income_source_id' => $source->id]);
        DeclaredIncomeSourceItem::create(['general_income_source_report_id' => $report->id, 'source_name' => 'Primary', 'contribution_rank' => 1]);

        $this->expectException(QueryException::class);
        DeclaredIncomeSourceItem::create(['general_income_source_report_id' => $report->id, 'source_name' => 'Duplicate', 'contribution_rank' => 1]);
    }

    public function test_an_in_use_income_source_template_cannot_be_deleted(): void
    {
        $template = IncomeSourceTemplate::factory()->create();
        IncomeSource::factory()->create(['income_source_template_id' => $template->id, 'template_type' => $template->template_type]);

        $this->expectException(QueryException::class);
        $template->delete();
    }

    public function test_generated_report_versions_are_unique_within_their_logical_scope(): void
    {
        $report = GeneratedReport::factory()->create();

        $this->expectException(QueryException::class);
        GeneratedReport::factory()->create([
            'client_folder_id' => $report->client_folder_id,
            'scope_key' => $report->scope_key,
            'report_type' => $report->report_type,
            'format' => $report->format,
            'version' => $report->version,
        ]);
    }

    public function test_client_folder_delete_is_soft_and_preserves_children(): void
    {
        $folder = ClientFolder::factory()->create();
        $source = IncomeSource::factory()->create(['client_folder_id' => $folder->id]);

        $folder->delete();

        $this->assertSoftDeleted($folder);
        $this->assertDatabaseHas('income_sources', ['id' => $source->id]);
    }
}
